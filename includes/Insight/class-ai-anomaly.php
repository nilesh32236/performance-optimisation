<?php
/**
 * AI anomaly detection — thresholds, breach/alarm state, RUM digest, CSS-refresh reactions.
 *
 * Insight domain (ARCH-010): owns the anomaly-detection cluster extracted
 * verbatim from AI_Adaptive (threshold math, breach/alarm option state,
 * RUM digest + blog-aware memos, trend sampling, detect_anomalies(),
 * CSS-refresh-on-anomaly queueing/snapshots/cooldowns, deploy notes).
 * AI_Adaptive keeps thin same-signature static proxies (facade rule), so
 * all existing callers behave identically. No option renames.
 *
 * @package PerformanceOptimise\Inc
 * @since 2.4.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Ai_Anomaly' ) ) {
	/**
	 * Detects performance regressions and reacts to them.
	 *
	 * Detection inputs are RUM aggregates + PageSpeed trend history;
	 * outputs are breach/alarm state, the RUM anomaly digest, and guarded
	 * CSS-refresh queueing. All methods fail open (never fatal) and all
	 * state is per-site (multisite-safe).
	 *
	 * @since 2.4.0
	 */
	class Ai_Anomaly {
		/**
		 * Per-request memo of the RUM anomaly digest result.
		 *
		 * Live-path fallback in get_suggestions() calls
		 * get_rum_anomaly_digest() with null args; without a memo every
		 * admin render pays a full paths x dates scan. Null means not
		 * computed yet for the current blog; any array (including empty)
		 * is a valid memoized result. Only the live path (null $rum, null
		 * $now) is memoized — injected args (tests) always compute fresh.
		 * Reset via reset_rum_anomaly_digest_memo() (tests).
		 *
		 * @since 2.3.0
		 * @var array|null
		 */
		private static ?array $rum_digest_memo = null;

		/**
		 * Whether the digest memo holds a computed value.
		 *
		 * Distinguishes "not computed yet" (false) from a computed empty
		 * digest (memo is array(), still a valid cached result).
		 *
		 * @since 2.3.0
		 * @var bool
		 */
		private static bool $rum_digest_memo_computed = false;

		/**
		 * Blog ID the digest memo was computed for (multisite safety).
		 *
		 * @since 2.3.0
		 * @var int
		 */
		private static int $rum_digest_memo_blog = 0;

		/**
		 * Reset the per-request RUM anomaly digest memo (for testing).
		 *
		 * @return void
		 * @since 2.3.0
		 */
		public static function reset_rum_anomaly_digest_memo(): void {
			self::$rum_digest_memo          = null;
			self::$rum_digest_memo_computed = false;
			self::$rum_digest_memo_blog     = 0;
		}

		/**
		 * Resolve the blog ID for digest memo scoping (multisite safety).
		 *
		 * Fail-open to 0 when the multisite API is unavailable (unit tests).
		 *
		 * @return int Current blog ID or 0 when unavailable.
		 * @since 2.3.0
		 */
		private static function rum_digest_memo_blog_id(): int {
			try {
				if ( function_exists( 'get_current_blog_id' ) ) {
					return (int) get_current_blog_id();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return 0;
		}
		/**
		 * Option storing the last anomaly alarm timestamp (autoload=no).
		 *
		 * Per-site option, hence inherently multisite-safe.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		private const ANOMALY_COOLDOWN_KEY = 'wppo_ai_anomaly_last_alarm';

		/**
		 * Default anomaly cooldown in days (single banner max).
		 *
		 * @since 2.0.0
		 * @var int
		 */
		private const ANOMALY_COOLDOWN_DAYS = 7;

		/**
		 * Default minimum numeric samples before an arm may fire.
		 *
		 * @since 2.0.0
		 * @var int
		 */
		private const ANOMALY_MIN_SAMPLES = 10;

		/**
		 * CLS regression arm threshold as an absolute delta (not percent).
		 *
		 * @since 2.0.0
		 * @var float
		 */
		private const CLS_ABSOLUTE_DELTA = 0.05;

		/**
		 * LCP regression arm threshold as a relative multiplier (+30%).
		 *
		 * @since 2.0.0
		 * @var float
		 */
		private const LCP_RELATIVE_MULTIPLIER = 1.3;

		/**
		 * INP regression arm threshold as a relative multiplier (+30%).
		 *
		 * INP is a timing metric like LCP, so the RUM digest reuses the
		 * relative-multiplier arm (lab trends carry no INP snapshots, hence
		 * INP regressions surface only via the field-data digest).
		 *
		 * @since 2.3.0
		 * @var float
		 */
		private const INP_RELATIVE_MULTIPLIER = 1.3;

		/**
		 * Relative tolerance band (percent) above a relative arm threshold.
		 *
		 * A recent-window median must clear `baseline * multiplier *
		 * (1 + tolerance_pct/100)` before the LCP/INP digest arm fires, so
		 * borderline wobble inside the band stays silent.
		 *
		 * @since 2.3.0
		 * @var float
		 */
		private const ANOMALY_TOLERANCE_PCT = 5.0;

		/**
		 * Absolute tolerance band added to the CLS absolute-delta threshold.
		 *
		 * A recent-window median must clear `baseline + delta + tolerance`
		 * before the CLS digest arm fires, so borderline wobble inside the
		 * band stays silent.
		 *
		 * @since 2.3.0
		 * @var float
		 */
		private const ANOMALY_TOLERANCE_ABS = 0.01;

		/**
		 * Default number of trailing windows that must each breach the
		 * ratio/delta gate before an anomaly may page (issue #1384).
		 *
		 * A single noisy PageSpeed window can never page on its own; the
		 * last N windows must all persist above baseline.
		 *
		 * @since 2.3.0
		 * @var int
		 */
		private const ANOMALY_PERSISTENCE_WINDOWS = 3;

		/**
		 * Default minimum RUM samples before field data may corroborate
		 * a trend anomaly (issue #1384).
		 *
		 * @since 2.3.0
		 * @var int
		 */
		private const ANOMALY_P75_MIN_SAMPLES = 10;

		/**
		 * Upper clamp for the p75 sample-floor resolver (issue #1384).
		 *
		 * Guards the p75 sample-floor resolver so a misbehaving
		 * `wppo_ai_anomaly_p75_min_samples` filter (or stored setting)
		 * cannot silently disable paging forever with an enormous value.
		 * The persistence-window resolver uses ANOMALY_PERSISTENCE_MAX.
		 *
		 * @since 2.3.0
		 * @var int
		 */
		private const ANOMALY_GATE_MAX = 30;

		/**
		 * Upper clamp for the persistence-window resolver (issue #1384).
		 *
		 * Trend history is capped at 30 snapshots per URL+strategy
		 * (Pagespeed::TREND_LIMIT) while detection requires
		 * count >= persistence+1 for a non-empty baseline prior, so
		 * persistence=30 (admittable under ANOMALY_GATE_MAX) could never
		 * fire. Clamping to 29 keeps every admittable value reachable.
		 *
		 * @since 2.3.0
		 * @var int
		 */
		private const ANOMALY_PERSISTENCE_MAX = 29;

		/**
		 * Default trailing-window size for the moving-average band (issue #1313).
		 *
		 * The breach baseline is the mean of the trailing
		 * `anomaly_band_window` samples before the persistence tail, not
		 * all history, so stale history cannot skew the band.
		 *
		 * @since 2.3.0
		 * @var int
		 */
		private const ANOMALY_BAND_WINDOW = 10;

		/**
		 * Default in-band stabilization window before a recovery notice (issue #1313).
		 *
		 * A breach must stay inside the band for this many days before a
		 * recovery notice fires, preventing flapping notices.
		 *
		 * @since 2.3.0
		 * @var int
		 */
		private const ANOMALY_RECOVERY_DAYS = 3;

		/**
		 * Per-site option storing the active breach state (issue #1313).
		 *
		 * Shape: `trend_key => { metric, baseline, current, breached_at }`.
		 * Per-site option, hence inherently multisite-safe. Bounded to 20
		 * entries; autoload=false; never fatal.
		 *
		 * @since 2.3.0
		 * @var string
		 */
		private const BREACH_STATE_OPTION = 'wppo_ai_anomaly_breach_state';

		/**
		 * Per-site option storing manual deploy notes (issue #1313).
		 *
		 * Shape: list of `{ ts, note }` rows, additive, capped at 20 rows,
		 * autoload=false. Breaches near a noted deploy are annotated
		 * instead of treated as mysteries.
		 *
		 * @since 2.3.0
		 * @var string
		 */
		private const DEPLOY_NOTES_OPTION = 'wppo_ai_deploy_notes';

		/**
		 * Maximum stored deploy-note rows (issue #1313).
		 *
		 * Keeps the per-site option bounded (~1 extra row per deploy).
		 *
		 * @since 2.3.0
		 * @var int
		 */
		private const DEPLOY_NOTES_LIMIT = 20;

		/**
		 * Maximum stored breach-state rows (issue #1313).
		 *
		 * Separate from DEPLOY_NOTES_LIMIT so a future deploy-notes
		 * cap change cannot silently alter breach-state retention.
		 *
		 * @since 2.3.0
		 * @var int
		 */
		private const BREACH_STATE_LIMIT = 20;

		/**
		 * Deploy-note correlation window in days (issue #1313).
		 *
		 * A breach whose timestamp falls within this many days after a
		 * noted deploy carries the note as annotation.
		 *
		 * @since 2.3.0
		 * @var int
		 */
		private const DEPLOY_CORRELATION_DAYS = 7;

		/**
		 * Resolve the anomaly minimum-sample threshold.
		 *
		 * Reads the additive `ai_adaptive.anomaly_min_samples` setting,
		 * falling back to ANOMALY_MIN_SAMPLES. Filterable via
		 * `wppo_ai_anomaly_min_samples`. Fail-open to 10.
		 *
		 * @return int Minimum samples (>=1).
		 * @since 2.0.0
		 */
		private static function anomaly_min_samples(): int {
			try {
				$min = self::ANOMALY_MIN_SAMPLES;
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
					$settings = Util::get_settings();
					if ( isset( $settings['ai_adaptive']['anomaly_min_samples'] ) ) {
						$candidate = (int) $settings['ai_adaptive']['anomaly_min_samples'];
						if ( $candidate >= 1 ) {
							$min = $candidate;
						}
					}
				}
				if ( function_exists( 'apply_filters' ) ) {
					$filtered = apply_filters( 'wppo_ai_anomaly_min_samples', $min );
					if ( is_numeric( $filtered ) && (int) $filtered >= 1 ) {
						$min = (int) $filtered;
					}
				}
				return $min >= 1 ? $min : self::ANOMALY_MIN_SAMPLES;
			} catch ( \Throwable $e ) {
				unset( $e );
				return self::ANOMALY_MIN_SAMPLES;
			}
		}

		/**
		 * Resolve the anomaly cooldown window in days.
		 *
		 * Reads the additive `ai_adaptive.anomaly_cooldown_days` setting,
		 * falling back to ANOMALY_COOLDOWN_DAYS. Filterable via
		 * `wppo_ai_anomaly_cooldown_days`. Fail-open to 7.
		 *
		 * @return int Cooldown days (>=0; 0 disables the cooldown gate).
		 * @since 2.0.0
		 */
		private static function anomaly_cooldown_days(): int {
			try {
				$days = self::ANOMALY_COOLDOWN_DAYS;
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
					$settings = Util::get_settings();
					if ( isset( $settings['ai_adaptive']['anomaly_cooldown_days'] ) ) {
						$candidate = (int) $settings['ai_adaptive']['anomaly_cooldown_days'];
						if ( $candidate >= 0 ) {
							$days = $candidate;
						}
					}
				}
				if ( function_exists( 'apply_filters' ) ) {
					$filtered = apply_filters( 'wppo_ai_anomaly_cooldown_days', $days );
					if ( is_numeric( $filtered ) && (int) $filtered >= 0 ) {
						$days = (int) $filtered;
					}
				}
				return $days >= 0 ? $days : self::ANOMALY_COOLDOWN_DAYS;
			} catch ( \Throwable $e ) {
				unset( $e );
				return self::ANOMALY_COOLDOWN_DAYS;
			}
		}

		/**
		 * Resolve the relative tolerance band in percent.
		 *
		 * Reads the additive `ai_adaptive.anomaly_tolerance_pct` setting,
		 * falling back to ANOMALY_TOLERANCE_PCT. Filterable via
		 * `wppo_ai_anomaly_tolerance_pct`. Fail-open to 5.0. Clamped to
		 * 0–50 so a rogue value cannot silence every regression.
		 *
		 * @return float Tolerance percent (>=0).
		 * @since 2.3.0
		 */
		private static function anomaly_tolerance_pct(): float {
			try {
				$tol = self::ANOMALY_TOLERANCE_PCT;
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
					$settings = Util::get_settings();
					if ( isset( $settings['ai_adaptive']['anomaly_tolerance_pct'] ) && is_numeric( $settings['ai_adaptive']['anomaly_tolerance_pct'] ) ) {
						$tol = (float) $settings['ai_adaptive']['anomaly_tolerance_pct'];
					}
				}
				if ( function_exists( 'apply_filters' ) ) {
					$filtered = apply_filters( 'wppo_ai_anomaly_tolerance_pct', $tol );
					if ( is_numeric( $filtered ) ) {
						$tol = (float) $filtered;
					}
				}
				if ( ! is_finite( $tol ) || $tol < 0 ) {
					return self::ANOMALY_TOLERANCE_PCT;
				}
				return min( 50.0, $tol );
			} catch ( \Throwable $e ) {
				unset( $e );
				return self::ANOMALY_TOLERANCE_PCT;
			}
		}

		/**
		 * Resolve the anomaly persistence-window count.
		 *
		 * Reads the additive `ai_adaptive.anomaly_persistence_windows`
		 * setting, falling back to ANOMALY_PERSISTENCE_WINDOWS. Filterable
		 * via `wppo_ai_anomaly_persistence_windows`. Fail-open to 3.
		 * Clamped to ANOMALY_PERSISTENCE_MAX (29 = trend cap 30 minus 1
		 * for the baseline prior) so every admittable value remains
		 * reachable and a misbehaving filter cannot silently disable
		 * paging forever.
		 *
		 * @return int Trailing windows that must each breach (>=1, <=29).
		 * @since 2.3.0
		 */
		private static function anomaly_persistence_windows(): int {
			try {
				$windows = self::ANOMALY_PERSISTENCE_WINDOWS;
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
					$settings = Util::get_settings();
					if ( isset( $settings['ai_adaptive']['anomaly_persistence_windows'] ) ) {
						$candidate = (int) $settings['ai_adaptive']['anomaly_persistence_windows'];
						if ( $candidate >= 1 ) {
							$windows = min( $candidate, self::ANOMALY_PERSISTENCE_MAX );
						}
					}
				}
				if ( function_exists( 'apply_filters' ) ) {
					$filtered = apply_filters( 'wppo_ai_anomaly_persistence_windows', $windows );
					if ( is_numeric( $filtered ) && (int) $filtered >= 1 ) {
						$windows = min( (int) $filtered, self::ANOMALY_PERSISTENCE_MAX );
					}
				}
				if ( $windows < 1 ) {
					return self::ANOMALY_PERSISTENCE_WINDOWS;
				}
				return min( $windows, self::ANOMALY_PERSISTENCE_MAX );
			} catch ( \Throwable $e ) {
				unset( $e );
				return self::ANOMALY_PERSISTENCE_WINDOWS;
			}
		}

		/**
		 * Resolve the absolute tolerance band for the CLS arm.
		 *
		 * Reads the additive `ai_adaptive.anomaly_tolerance_abs` setting,
		 * falling back to ANOMALY_TOLERANCE_ABS. Filterable via
		 * `wppo_ai_anomaly_tolerance_abs`. Fail-open to 0.01. Clamped to
		 * 0–1 so a rogue value cannot silence every regression.
		 *
		 * @return float Absolute tolerance (>=0).
		 * @since 2.3.0
		 */
		private static function anomaly_tolerance_abs(): float {
			try {
				$tol = self::ANOMALY_TOLERANCE_ABS;
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
					$settings = Util::get_settings();
					if ( isset( $settings['ai_adaptive']['anomaly_tolerance_abs'] ) && is_numeric( $settings['ai_adaptive']['anomaly_tolerance_abs'] ) ) {
						$tol = (float) $settings['ai_adaptive']['anomaly_tolerance_abs'];
					}
				}
				if ( function_exists( 'apply_filters' ) ) {
					$filtered = apply_filters( 'wppo_ai_anomaly_tolerance_abs', $tol );
					if ( is_numeric( $filtered ) ) {
						$tol = (float) $filtered;
					}
				}
				if ( ! is_finite( $tol ) || $tol < 0 ) {
					return self::ANOMALY_TOLERANCE_ABS;
				}
				return min( 1.0, $tol );
			} catch ( \Throwable $e ) {
				unset( $e );
				return self::ANOMALY_TOLERANCE_ABS;
			}
		}

		/**
		 * Resolve the RUM corroboration sample floor.
		 *
		 * Reads the additive `ai_adaptive.anomaly_p75_min_samples`
		 * setting, falling back to ANOMALY_P75_MIN_SAMPLES. Filterable via
		 * `wppo_ai_anomaly_p75_min_samples`. Fail-open to 10. Clamped to
		 * ANOMALY_GATE_MAX so a misbehaving filter cannot silently
		 * disable paging forever.
		 *
		 * @return int Minimum RUM samples (>=1, <=30).
		 * @since 2.3.0
		 */
		private static function anomaly_p75_min_samples(): int {
			try {
				$min = self::ANOMALY_P75_MIN_SAMPLES;
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
					$settings = Util::get_settings();
					if ( isset( $settings['ai_adaptive']['anomaly_p75_min_samples'] ) ) {
						$candidate = (int) $settings['ai_adaptive']['anomaly_p75_min_samples'];
						if ( $candidate >= 1 ) {
							$min = min( $candidate, self::ANOMALY_GATE_MAX );
						}
					}
				}
				if ( function_exists( 'apply_filters' ) ) {
					$filtered = apply_filters( 'wppo_ai_anomaly_p75_min_samples', $min );
					if ( is_numeric( $filtered ) && (int) $filtered >= 1 ) {
						$min = min( (int) $filtered, self::ANOMALY_GATE_MAX );
					}
				}
				if ( $min < 1 ) {
					return self::ANOMALY_P75_MIN_SAMPLES;
				}
				return min( $min, self::ANOMALY_GATE_MAX );
			} catch ( \Throwable $e ) {
				unset( $e );
				return self::ANOMALY_P75_MIN_SAMPLES;
			}
		}

		/**
		 * Resolve the moving-average band window size (issue #1313).
		 *
		 * Reads the additive `ai_adaptive.anomaly_band_window` setting,
		 * falling back to ANOMALY_BAND_WINDOW. Filterable via
		 * `wppo_ai_anomaly_band_window`. Fail-open to 10. Clamped to
		 * 1–29 so every admittable value stays reachable within the
		 * 30-snapshot trend cap.
		 *
		 * @return int Trailing samples forming the band baseline (>=1, <=29).
		 * @since 2.3.0
		 */
		private static function anomaly_band_window(): int {
			try {
				$window = self::ANOMALY_BAND_WINDOW;
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
					$settings = Util::get_settings();
					if ( isset( $settings['ai_adaptive']['anomaly_band_window'] ) ) {
						$candidate = (int) $settings['ai_adaptive']['anomaly_band_window'];
						if ( $candidate >= 1 ) {
							$window = min( $candidate, self::ANOMALY_PERSISTENCE_MAX );
						}
					}
				}
				if ( function_exists( 'apply_filters' ) ) {
					$filtered = apply_filters( 'wppo_ai_anomaly_band_window', $window );
					if ( is_numeric( $filtered ) && (int) $filtered >= 1 ) {
						$window = min( (int) $filtered, self::ANOMALY_PERSISTENCE_MAX );
					}
				}
				if ( $window < 1 ) {
					return self::ANOMALY_BAND_WINDOW;
				}
				return min( $window, self::ANOMALY_PERSISTENCE_MAX );
			} catch ( \Throwable $e ) {
				unset( $e );
				return self::ANOMALY_BAND_WINDOW;
			}
		}

		/**
		 * Resolve the recovery hysteresis window in days (issue #1313).
		 *
		 * Reads the additive `ai_adaptive.anomaly_recovery_days` setting,
		 * falling back to ANOMALY_RECOVERY_DAYS. Filterable via
		 * `wppo_ai_anomaly_recovery_days`. Fail-open to 3.
		 *
		 * @return int In-band stabilization days (>=0; 0 recovers immediately).
		 * @since 2.3.0
		 */
		private static function anomaly_recovery_days(): int {
			try {
				$days = self::ANOMALY_RECOVERY_DAYS;
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
					$settings = Util::get_settings();
					if ( isset( $settings['ai_adaptive']['anomaly_recovery_days'] ) ) {
						$candidate = (int) $settings['ai_adaptive']['anomaly_recovery_days'];
						if ( $candidate >= 0 ) {
							$days = $candidate;
						}
					}
				}
				if ( function_exists( 'apply_filters' ) ) {
					$filtered = apply_filters( 'wppo_ai_anomaly_recovery_days', $days );
					if ( is_numeric( $filtered ) && (int) $filtered >= 0 ) {
						$days = (int) $filtered;
					}
				}
				return $days >= 0 ? $days : self::ANOMALY_RECOVERY_DAYS;
			} catch ( \Throwable $e ) {
				unset( $e );
				return self::ANOMALY_RECOVERY_DAYS;
			}
		}

		/**
		 * Compute the moving-average band for a trailing baseline window (issue #1313).
		 *
		 * Pure helper: mean plus a sigma floor over the trailing window.
		 * LCP (relative arm): `upper = max(mean * 1.3, mean + 2*σ)`.
		 * CLS (absolute arm): `upper = max(mean + 0.05, mean + 2*σ)`.
		 * The tolerance band is folded in by callers via the resolved
		 * `anomaly_tolerance_pct` / `anomaly_tolerance_abs` gates. Lower
		 * (`mean - 2*σ`) is intentionally retained for symmetric and
		 * future recovery-hysteresis reads; current callers
		 * (detect_anomalies(), detect_recoveries()) gate on upper/mean
		 * only. Fail-open: empty input yields a zero band (callers treat a
		 * non-positive mean as unusable).
		 *
		 * @param float[] $prior_window Trailing numeric samples (oldest first).
		 * @param string  $metric Metric name ('lcp'|'cls'; others use the LCP shape).
		 * @return array{mean:float,std:float,upper:float,lower:float} Band edges.
		 * @since 2.3.0
		 */
		private static function moving_band( array $prior_window, string $metric ): array {
			$zero = array(
				'mean'  => 0.0,
				'std'   => 0.0,
				'upper' => 0.0,
				'lower' => 0.0,
			);
			try {
				$values = array();
				foreach ( $prior_window as $value ) {
					if ( ! is_numeric( $value ) ) {
						continue;
					}
					$value = (float) $value;
					if ( function_exists( 'is_finite' ) && ! is_finite( $value ) ) {
						continue;
					}
					$values[] = $value;
				}
				$count = count( $values );
				if ( 0 === $count ) {
					return $zero;
				}
				$mean     = array_sum( $values ) / $count;
				$variance = 0.0;
				foreach ( $values as $value ) {
					$variance += ( $value - $mean ) * ( $value - $mean );
				}
				$variance = $variance / $count;
				$std      = $variance > 0 ? (float) sqrt( $variance ) : 0.0;
				if ( 'cls' === $metric ) {
					$upper = max( $mean + self::CLS_ABSOLUTE_DELTA, $mean + 2.0 * $std );
				} else {
					$upper = max( $mean * self::LCP_RELATIVE_MULTIPLIER, $mean + 2.0 * $std );
				}
				$lower = $mean - 2.0 * $std;
				return array(
					'mean'  => (float) $mean,
					'std'   => (float) $std,
					'upper' => (float) $upper,
					'lower' => (float) $lower,
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return $zero;
			}
		}

		/**
		 * Read the stored deploy notes (issue #1313).
		 *
		 * Manual-only v2 surface: a timestamped list managed via settings
		 * (`ai_adaptive.deploy_notes`) with a per-site option mirror for
		 * programmatic adds. Settings entries win on timestamp collision.
		 * Capped, sanitized, fail-open to an empty list.
		 *
		 * @return array[] List of {ts:int, note:string} rows (newest last, max 20).
		 * @since 2.3.0
		 */
		public static function get_deploy_notes(): array {
			try {
				$notes = array();
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
					$settings        = Util::get_settings();
					$stored_settings = $settings['ai_adaptive']['deploy_notes'] ?? array();
					if ( is_array( $stored_settings ) ) {
						foreach ( $stored_settings as $row ) {
							if ( ! is_array( $row ) ) {
								continue;
							}
							$ts   = isset( $row['ts'] ) ? (int) $row['ts'] : 0;
							$note = isset( $row['note'] ) && is_string( $row['note'] ) ? $row['note'] : '';
							if ( $ts <= 0 || '' === trim( $note ) ) {
								continue;
							}
							$clip    = function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 200 ) : substr( $note, 0, 200 );
							$notes[] = array(
								'ts'   => $ts,
								'note' => function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $clip ) : $clip,
							);
						}
					}
				}
				if ( function_exists( 'get_option' ) ) {
					$option_rows = get_option( self::DEPLOY_NOTES_OPTION, array() );
					if ( is_array( $option_rows ) ) {
						foreach ( $option_rows as $row ) {
							if ( ! is_array( $row ) ) {
								continue;
							}
							$ts   = isset( $row['ts'] ) ? (int) $row['ts'] : 0;
							$note = isset( $row['note'] ) && is_string( $row['note'] ) ? $row['note'] : '';
							if ( $ts <= 0 || '' === trim( $note ) ) {
								continue;
							}
							$clip    = function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 200 ) : substr( $note, 0, 200 );
							$notes[] = array(
								'ts'   => $ts,
								'note' => function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $clip ) : $clip,
							);
						}
					}
				}
				$seen    = array();
				$deduped = array();
				foreach ( $notes as $row ) {
					$k = (int) ( $row['ts'] ?? 0 );
					if ( ! isset( $seen[ $k ] ) ) {
						$seen[ $k ] = true;
						$deduped[]  = $row;
					}
				}
				$notes = $deduped;
				usort(
					$notes,
					static function ( $a, $b ) {
						return ( $a['ts'] ?? 0 ) <=> ( $b['ts'] ?? 0 );
					}
				);
				if ( count( $notes ) > self::DEPLOY_NOTES_LIMIT ) {
					$notes = array_slice( $notes, -self::DEPLOY_NOTES_LIMIT );
				}
				return array_values( $notes );
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Record a manual deploy note (issue #1313).
		 *
		 * Additive, per-site, capped at 20 rows (oldest evicted),
		 * autoload=false. Best-effort: never throws, never fatal when the
		 * options API is missing.
		 *
		 * @param string   $note Note text (sanitized, max 200 chars).
		 * @param int|null $ts Optional timestamp (defaults to now).
		 * @return bool True when stored.
		 * @since 2.3.0
		 */
		public static function add_deploy_note( string $note, ?int $ts = null ): bool {
			try {
				if ( '' === trim( $note ) || ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
					return false;
				}
				$clip  = function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 200 ) : substr( $note, 0, 200 );
				$clean = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $clip ) : $clip;
				if ( '' === trim( $clean ) ) {
					return false;
				}
				$at = null !== $ts && $ts > 0 ? (int) $ts : self::anomaly_now();
				if ( $at <= 0 ) {
					return false;
				}
				$stored = get_option( self::DEPLOY_NOTES_OPTION, array() );
				if ( ! is_array( $stored ) ) {
					$stored = array();
				}
				$stored[] = array(
					'ts'   => $at,
					'note' => $clean,
				);
				if ( count( $stored ) > self::DEPLOY_NOTES_LIMIT ) {
					$stored = array_slice( $stored, -self::DEPLOY_NOTES_LIMIT );
				}
				update_option( self::DEPLOY_NOTES_OPTION, array_values( $stored ), false );
				return true;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Find the deploy note nearest before a breach timestamp (issue #1313).
		 *
		 * A breach within DEPLOY_CORRELATION_DAYS after a noted deploy is
		 * annotated with that note instead of treated as a mystery.
		 * Fail-open: any failure returns an empty string.
		 *
		 * @param int $breach_ts Breach timestamp.
		 * @return string Matching note text, or '' when none nearby.
		 * @since 2.3.0
		 */
		public static function find_deploy_note_near( int $breach_ts ): string {
			try {
				if ( $breach_ts <= 0 ) {
					return '';
				}
				$notes = self::get_deploy_notes();
				if ( empty( $notes ) ) {
					return '';
				}
				$day_seconds = defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS : 86400;
				$window      = self::DEPLOY_CORRELATION_DAYS * $day_seconds;
				$best        = '';
				$best_ts     = 0;
				foreach ( $notes as $row ) {
					$ts = isset( $row['ts'] ) ? (int) $row['ts'] : 0;
					if ( $ts <= 0 || $ts > $breach_ts ) {
						continue;
					}
					if ( ( $breach_ts - $ts ) > $window ) {
						continue;
					}
					if ( $ts >= $best_ts ) {
						$best_ts = $ts;
						$best    = isset( $row['note'] ) ? (string) $row['note'] : '';
					}
				}
				return $best;
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Read the active breach state (issue #1313).
		 *
		 * Per-site option, hence inherently multisite-safe. Fail-open to
		 * an empty array.
		 *
		 * @return array<string, array{metric:string,baseline:float,current:float,breached_at:int}> Breach rows keyed by trend key.
		 * @since 2.3.0
		 */
		public static function get_breach_state(): array {
			try {
				if ( ! function_exists( 'get_option' ) ) {
					return array();
				}
				$stored = get_option( self::BREACH_STATE_OPTION, array() );
				if ( ! is_array( $stored ) ) {
					return array();
				}
				foreach ( $stored as $k => $row ) {
					if ( ! is_array( $row ) || ! isset( $row['metric'], $row['breached_at'] ) ) {
						unset( $stored[ $k ] );
					}
				}
				return $stored;
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Persist the active breach state (issue #1313).
		 *
		 * Bounded to 20 entries; per-site option with autoload=false;
		 * never throws.
		 *
		 * @param array $state Breach rows keyed by trend key.
		 * @return void
		 * @since 2.3.0
		 */
		public static function set_breach_state( array $state ): void {
			try {
				if ( ! function_exists( 'update_option' ) ) {
					return;
				}
				if ( count( $state ) > self::BREACH_STATE_LIMIT ) {
					$state = array_slice( $state, -self::BREACH_STATE_LIMIT, self::BREACH_STATE_LIMIT, true );
				}
				update_option( self::BREACH_STATE_OPTION, $state, false );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Record a breach for recovery hysteresis tracking (issue #1313).
		 *
		 * Never throws; fail-open (a write failure simply skips recovery).
		 *
		 * @param string $trend_key Trend key.
		 * @param string $metric Metric name.
		 * @param float  $baseline Band baseline.
		 * @param float  $current Breach sample.
		 * @param int    $breached_at Breach timestamp.
		 * @return void
		 * @since 2.3.0
		 */
		private static function record_breach_state( string $trend_key, string $metric, float $baseline, float $current, int $breached_at ): void {
			try {
				if ( '' === $trend_key ) {
					return;
				}
				$state               = self::get_breach_state();
				$state[ $trend_key ] = array(
					'metric'      => $metric,
					'baseline'    => $baseline,
					'current'     => $current,
					'breached_at' => $breached_at,
				);
				self::set_breach_state( $state );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Clear a tracked breach after recovery (issue #1313).
		 *
		 * Never throws.
		 *
		 * @param string $trend_key Trend key.
		 * @return void
		 * @since 2.3.0
		 */
		private static function clear_breach_state( string $trend_key ): void {
			try {
				if ( '' === $trend_key ) {
					return;
				}
				$state = self::get_breach_state();
				if ( ! array_key_exists( $trend_key, $state ) ) {
					return;
				}
				unset( $state[ $trend_key ] );
				self::set_breach_state( $state );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Detect recoveries closing the loop on tracked breaches (issue #1313).
		 *
		 * For each tracked breach, the latest trend sample is compared
		 * against the current moving-average band: a recovery fires only
		 * after `anomaly_recovery_days` have elapsed since the breach
		 * (`now - breached_at >= days`) AND the single latest sample reads
		 * back inside the band (`latest <= upper`). This is a time-gated
		 * single-sample check: intermediate samples between breach and now
		 * are not examined, so flap suppression is time-based rather than
		 * full-window stabilization-verified. At most
		 * one recovery is returned with the same single-banner cooldown
		 * as detect_anomalies() (active cooldown or low samples yields
		 * zero notices). Read-only suggestions shape; never auto-applies.
		 *
		 * Lazy boot: returns immediately when no breach state or no trend
		 * data exists. Fail-open: any failure returns an empty array.
		 *
		 * @param array|null $trends Optional trends map (null = live Pagespeed::get_trends()).
		 * @param int|null   $now Optional current timestamp (tests).
		 * @return array[] At most one recovery: array(array('key'=>string,'route'=>string,'metric'=>string,'baseline'=>float,'current'=>float,'samples'=>int,'recovered'=>true,'breached_at'=>int)).
		 * @since 2.3.0
		 */
		public static function detect_recoveries( ?array $trends = null, ?int $now = null ): array {
			try {
				$state = self::get_breach_state();
				if ( empty( $state ) ) {
					return array();
				}
				if ( null === $trends ) {
					if ( ! class_exists( 'PerformanceOptimise\Inc\Pagespeed' ) ) {
						return array();
					}
					if ( ! method_exists( 'PerformanceOptimise\Inc\Pagespeed', 'get_trends' ) ) {
						return array();
					}
					$trends = Pagespeed::get_trends();
				}
				if ( ! is_array( $trends ) || empty( $trends ) ) {
					return array();
				}
				$resolved_now = self::anomaly_now( $now );
				if ( ! self::is_anomaly_cooled_down( $resolved_now ) ) {
					return array();
				}
				$min_samples = self::anomaly_min_samples();
				if ( $min_samples < 1 ) {
					$min_samples = self::ANOMALY_MIN_SAMPLES;
				}
				$band_window = self::anomaly_band_window();
				if ( $band_window < 1 ) {
					$band_window = self::ANOMALY_BAND_WINDOW;
				}
				$recovery_days = self::anomaly_recovery_days();
				$day_seconds   = defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS : 86400;
				$tol_pct       = self::anomaly_tolerance_pct();
				$tol_abs       = self::anomaly_tolerance_abs();
				foreach ( $state as $trend_key => $breach ) {
					if ( ! is_array( $breach ) ) {
						continue;
					}
					$metric = isset( $breach['metric'] ) ? (string) $breach['metric'] : '';
					if ( ! in_array( $metric, array( 'lcp', 'cls' ), true ) ) {
						continue;
					}
					$breached_at = isset( $breach['breached_at'] ) ? (int) $breach['breached_at'] : 0;
					if ( $breached_at <= 0 ) {
						continue;
					}
					if ( ( $resolved_now - $breached_at ) < ( $recovery_days * $day_seconds ) ) {
						continue;
					}
					if ( ! isset( $trends[ $trend_key ] ) || ! is_array( $trends[ $trend_key ] ) ) {
						continue;
					}
					$samples = self::collect_trend_samples( $trends[ $trend_key ], $metric, 'lcp' === $metric );
					if ( count( $samples ) < $min_samples ) {
						continue;
					}
					$window = min( $band_window, count( $samples ) - 1 );
					if ( $window < 1 ) {
						continue;
					}
					$prior = array_slice( $samples, count( $samples ) - 1 - $window, $window );
					$band  = self::moving_band( $prior, $metric );
					if ( $band['mean'] <= 0 && 'lcp' === $metric ) {
						continue;
					}
					if ( 'cls' === $metric ) {
						$upper = max( $band['upper'], $band['mean'] + self::CLS_ABSOLUTE_DELTA + $tol_abs );
					} else {
						$upper = max( $band['upper'], $band['mean'] * self::LCP_RELATIVE_MULTIPLIER * ( 1.0 + $tol_pct / 100.0 ) );
					}
					$latest = (float) end( $samples );
					if ( $latest > $upper ) {
						continue;
					}
					$recovery = array(
						'key'         => (string) $trend_key,
						'route'       => (string) $trend_key,
						'metric'      => $metric,
						'baseline'    => (float) $band['mean'],
						'current'     => $latest,
						'p75'         => self::anomaly_p75( $samples ),
						'delta'       => (float) ( $latest - $band['mean'] ),
						'samples'     => count( $samples ),
						'recovered'   => true,
						'breached_at' => $breached_at,
					);
					if ( 'cls' === $metric ) {
						$recovery['change_abs'] = (float) ( $latest - $band['mean'] );
					} else {
						$recovery['change_pct'] = $band['mean'] > 0 ? (float) ( ( $latest - $band['mean'] ) / $band['mean'] * 100.0 ) : 0.0;
					}
					$filtered = $recovery;
					if ( function_exists( 'apply_filters' ) ) {
						$filtered_rows = apply_filters( 'wppo_ai_anomaly_recovered', array( $recovery ) );
						if ( is_array( $filtered_rows ) && ! empty( $filtered_rows ) ) {
							$first = $filtered_rows[0];
							if ( is_array( $first ) ) {
								$filtered = $first;
							}
						} elseif ( is_array( $filtered_rows ) && empty( $filtered_rows ) ) {
							return array();
						}
					}
					self::clear_breach_state( (string) $trend_key );
					self::set_last_anomaly_alarm( $resolved_now );
					return array( $filtered );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
			return array();
		}

		/**
		 * Whether RUM collection is enabled (read-only probe).
		 *
		 * Wraps RUM::is_enabled() with class/method guards so the anomaly
		 * detector degrades gracefully when the RUM class is unavailable.
		 * Fail-open to false (no corroboration without field collection).
		 *
		 * @return bool True when RUM collection is enabled.
		 * @since 2.3.0
		 */
		private static function is_rum_collection_enabled(): bool {
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\RUM' ) || ! method_exists( 'PerformanceOptimise\Inc\RUM', 'is_enabled' ) ) {
					return false;
				}
				return (bool) RUM::is_enabled();
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Compute the p75 of a numeric sample list (nearest-rank).
		 *
		 * Prefers RUM::compute_p75() when available (WP 6.2+/PHP 8.2+
		 * floor holds; legacy fallback is the local nearest-rank
		 * implementation below so behaviour never depends on callee
		 * availability). Fail-open: empty input returns 0.0.
		 *
		 * @param float[] $samples Numeric samples.
		 * @return float p75 value or 0.0 when empty.
		 * @since 2.3.0
		 */
		private static function anomaly_p75( array $samples ): float {
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\RUM' ) && method_exists( 'PerformanceOptimise\Inc\RUM', 'compute_p75' ) ) {
					return (float) RUM::compute_p75( $samples );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			$values = array_values( array_filter( $samples, 'is_numeric' ) );
			$count  = count( $values );
			if ( 0 === $count ) {
				return 0.0;
			}
			$values = array_map( 'floatval', $values );
			sort( $values, SORT_NUMERIC );
			$rank = (int) ceil( 0.75 * $count ) - 1;
			$rank = max( 0, min( $count - 1, $rank ) );
			return (float) $values[ $rank ];
		}

		/**
		 * Read the RUM aggregate for anomaly corroboration (read-only).
		 *
		 * Prefers the side-effect-free RUM::get_aggregate_readonly() path
		 * (no queue flush, no transient writes — one request, one query).
		 * Legacy fallback is RUM::get_data() when the read-only method is
		 * unavailable (older drop-in) or when the WP core version is below
		 * the 6.2 floor. Fail-open: any failure returns an empty array.
		 *
		 * @return array Aggregate data (empty array when missing/invalid).
		 * @since 2.3.0
		 */
		private static function read_rum_aggregate_for_anomaly(): array {
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\RUM' ) ) {
					return array();
				}
				$use_readonly = method_exists( 'PerformanceOptimise\Inc\RUM', 'get_aggregate_readonly' );
				if ( $use_readonly && function_exists( 'get_bloginfo' ) && function_exists( 'version_compare' ) ) {
					try {
						$wp_version = get_bloginfo( 'version' );
						if ( is_string( $wp_version ) && '' !== $wp_version && version_compare( $wp_version, '6.2', '<' ) ) {
							$use_readonly = false;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( $use_readonly ) {
					$rum = RUM::get_aggregate_readonly();
					return is_array( $rum ) ? $rum : array();
				}
				if ( method_exists( 'PerformanceOptimise\Inc\RUM', 'get_data' ) ) {
					$rum = RUM::get_data();
					return is_array( $rum ) ? $rum : array();
				}
				return array();
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Get the last anomaly alarm timestamp.
		 *
		 * Read-only option read; never throws.
		 *
		 * @return int Unix timestamp (0 when never alarmed).
		 * @since 2.0.0
		 */
		public static function get_last_anomaly_alarm(): int {
			try {
				if ( ! function_exists( 'get_option' ) ) {
					return 0;
				}
				$ts = get_option( self::ANOMALY_COOLDOWN_KEY, 0 );
				return is_numeric( $ts ) ? (int) $ts : 0;
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		/**
		 * Persist the last anomaly alarm timestamp.
		 *
		 * Per-site option with autoload=false; never throws.
		 *
		 * @param int $ts Unix timestamp.
		 * @return void
		 * @since 2.0.0
		 */
		public static function set_last_anomaly_alarm( int $ts ): void {
			try {
				if ( ! function_exists( 'update_option' ) ) {
					return;
				}
				update_option( self::ANOMALY_COOLDOWN_KEY, $ts, false );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Resolve the current timestamp deterministically.
		 *
		 * @param int|null $now Optional injected timestamp (tests).
		 * @return int
		 * @since 2.0.0
		 */
		private static function anomaly_now( ?int $now = null ): int {
			// Audit #1362: time() is PHP core — no function_exists guard needed.
			return $now ?? time();
		}

		/**
		 * Whether the anomaly cooldown has elapsed.
		 *
		 * Fail-open: any failure returns true (detection proceeds).
		 *
		 * @param int|null $now Optional injected timestamp (tests).
		 * @return bool True when a new banner may fire.
		 * @since 2.0.0
		 */
		public static function is_anomaly_cooled_down( ?int $now = null ): bool {
			try {
				$days = self::anomaly_cooldown_days();
				if ( $days <= 0 ) {
					return true;
				}
				$last = self::get_last_anomaly_alarm();
				if ( $last <= 0 ) {
					return true;
				}
				$current = self::anomaly_now( $now );
				if ( $current <= 0 ) {
					return true;
				}
				$day_seconds = defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS : 86400;
				return ( $current - $last ) >= ( $days * $day_seconds );
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Whether RUM field data corroborates a trend anomaly.
		 *
		 * Sums `n`/`sum` across dates/paths for the matching metric in the
		 * RUM aggregate (`date => path => metric => [n,sum]`). Requires
		 * total `n >= anomaly_p75_min_samples()` (undersampled returns
		 * false — thin data never pages). Corroboration passes when the
		 * global RUM average is degraded vs the trend baseline (LCP/INP:
		 * rum_avg >= baseline; CLS: rum_avg - baseline >=
		 * CLS_ABSOLUTE_DELTA, mirroring the trend arm — a bare
		 * rum_avg >= baseline check is vacuous for near-zero CLS
		 * baselines since any non-negative field average would pass).
		 * Fail-open: any failure returns false (no alarm).
		 *
		 * RUM-disabled short-circuit: when no aggregate is injected (live
		 * read path) and RUM collection is disabled, returns false
		 * immediately — zero notices with RUM disabled. An explicitly
		 * injected aggregate bypasses the enabled probe so tests and
		 * staging can exercise corroboration deterministically.
		 *
		 * Live reads use the side-effect-free
		 * RUM::get_aggregate_readonly() path with a legacy
		 * RUM::get_data() fallback (see read_rum_aggregate_for_anomaly()).
		 *
		 * @param string     $metric Metric name ('lcp'|'inp'|'cls').
		 * @param array|null $rum Optional RUM aggregate (null = live read-only read).
		 * @param float      $baseline Trend baseline for the firing arm.
		 * @param int|null   $p75_min_samples Optional pre-resolved sample floor (null = resolve once via anomaly_p75_min_samples()).
		 * @return bool True when real-user data agrees with the trend arm.
		 * @since 2.0.0
		 * @since 2.3.0 Supports the 'inp' metric (behaves like 'lcp').
		 * @since 2.3.0 RUM-disabled short-circuit; read-only aggregate path; p75 sample floor.
		 */
		private static function is_rum_corroborated( string $metric, ?array $rum, float $baseline, ?int $p75_min_samples = null ): bool {
			try {
				if ( ! in_array( $metric, array( 'lcp', 'inp', 'cls' ), true ) ) {
					return false;
				}
				if ( $baseline <= 0 && ( 'lcp' === $metric || 'inp' === $metric ) ) {
					return false;
				}
				if ( null === $rum ) {
					// Zero notices with RUM disabled (live path only).
					if ( ! self::is_rum_collection_enabled() ) {
						return false;
					}
					$rum = self::read_rum_aggregate_for_anomaly();
				}
				if ( ! is_array( $rum ) || empty( $rum ) ) {
					return false;
				}
				$floor = $p75_min_samples;
				if ( null === $floor ) {
					$floor = self::anomaly_p75_min_samples();
				}
				if ( $floor < 1 ) {
					$floor = self::ANOMALY_P75_MIN_SAMPLES;
				}
				$total_n   = 0;
				$total_sum = 0.0;
				foreach ( $rum as $paths ) {
					if ( ! is_array( $paths ) ) {
						continue;
					}
					foreach ( $paths as $metrics ) {
						if ( ! is_array( $metrics ) || ! isset( $metrics[ $metric ] ) || ! is_array( $metrics[ $metric ] ) ) {
							continue;
						}
						$n   = isset( $metrics[ $metric ]['n'] ) ? (int) $metrics[ $metric ]['n'] : 0;
						$sum = isset( $metrics[ $metric ]['sum'] ) ? (float) $metrics[ $metric ]['sum'] : 0.0;
						if ( $n <= 0 ) {
							continue;
						}
						$total_n   += $n;
						$total_sum += $sum;
					}
				}
				if ( $total_n < $floor ) {
					return false;
				}
				$rum_avg = $total_sum / $total_n;
				if ( 'lcp' === $metric || 'inp' === $metric ) {
					return $rum_avg >= $baseline;
				}
				// CLS arm: absolute-scale metric; corroborate only when the
				// field average confirms the same absolute shift the trend
				// gate requires. A bare rum_avg >= baseline check would be
				// vacuous for near-zero baselines (any non-negative field
				// average passes), leaving the trend delta gate to do all
				// the work.
				return ( $rum_avg - $baseline ) >= self::CLS_ABSOLUTE_DELTA;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Collect numeric samples for a metric from trend snapshots.
		 *
		 * @param array  $snapshots Trend snapshots for one URL+strategy key.
		 * @param string $metric Metric key ('lcp'|'cls').
		 * @param bool   $require_positive Whether to drop non-positive values (LCP only).
		 * @return float[]
		 * @since 2.0.0
		 */
		private static function collect_trend_samples( array $snapshots, string $metric, bool $require_positive ): array {
			$values = array();
			foreach ( $snapshots as $snapshot ) {
				if ( ! is_array( $snapshot ) || ! isset( $snapshot[ $metric ] ) ) {
					continue;
				}
				$value = $snapshot[ $metric ];
				if ( ! is_numeric( $value ) ) {
					continue;
				}
				$value = (float) $value;
				if ( $require_positive && $value <= 0 ) {
					continue;
				}
				if ( function_exists( 'is_finite' ) ) {
					if ( ! is_finite( $value ) ) {
						continue;
					}
				}
				$values[] = $value;
			}
			return $values;
		}

		/**
		 * Compute the median of a numeric sample list.
		 *
		 * Sorts ascending and picks the middle value (averaging the two
		 * middle values for even counts). Non-numeric and non-finite
		 * entries are ignored. Fail-open: empty input returns 0.0.
		 *
		 * @param array $samples Numeric samples.
		 * @return float Median value or 0.0 when empty.
		 * @since 2.3.0
		 */
		private static function rum_median( array $samples ): float {
			try {
				$values = array();
				foreach ( $samples as $value ) {
					if ( ! is_numeric( $value ) ) {
						continue;
					}
					$value = (float) $value;
					if ( function_exists( 'is_finite' ) && ! is_finite( $value ) ) {
						continue;
					}
					$values[] = $value;
				}
				$count = count( $values );
				if ( 0 === $count ) {
					return 0.0;
				}
				sort( $values, SORT_NUMERIC );
				$mid = (int) floor( $count / 2 );
				if ( 0 === $count % 2 ) {
					return (float) ( ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2.0 );
				}
				return (float) $values[ $mid ];
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0.0;
			}
		}

		/**
		 * Local RUM anomaly digest for LCP/INP/CLS regressions (read-only).
		 *
		 * Compares the recent-day mean against baseline medians per path
		 * from the stored RUM aggregate (`date => path => metric =>
		 * [n,sum]`): the latest date bucket is the recent window, all prior
		 * buckets are the baseline. Daily averages (`sum/n`) form the median
		 * inputs, so no raw-sample reservoir is needed and CLS (which has
		 * no segment reservoir) is covered alongside LCP and INP.
		 *
		 * A path+metric flags when both windows hold at least
		 * `anomaly_min_samples()` samples AND the recent-day mean clears the
		 * arm threshold plus the tolerance band:
		 * - LCP/INP: recent >= baseline * 1.3 * (1 + tolerance_pct/100).
		 * - CLS: recent >= baseline + 0.05 + tolerance_abs.
		 *
		 * Samples below the minimum threshold or movement inside the
		 * tolerance band suppress the alert (empty return); heuristic
		 * suggestions are untouched. At most one anomaly is returned
		 * (worst-first excess over its arm threshold) with the same
		 * 7-day single-banner cooldown as detect_anomalies(), persisted in
		 * the per-site `wppo_ai_anomaly_last_alarm` option
		 * (multisite-safe). The entry links the affected path and window
		 * (`path`, `window`) and never auto-changes any setting.
		 *
		 * Local computation only: reads the memoized
		 * RUM::get_aggregate_readonly() (one option read shared per
		 * request, never flushes the beacon queue, never touches
		 * transients), no remote calls, no API keys, no PII. Fail-open:
		 * fewer than 2 date buckets, undersampled windows, an active
		 * cooldown, or any failure returns an empty array — never fatal.
		 *
		 * @param array|null $rum Optional RUM aggregate for testability. When null, reads RUM::get_aggregate_readonly().
		 * @param int|null   $now Optional current timestamp for testability. When null, uses time().
		 * @return array[] At most one digest anomaly: array(array('key'=>string,'metric'=>string,'path'=>string,'baseline'=>float,'current'=>float,'recent'=>float,'window'=>string,'samples'=>int,'source'=>string,'change_pct'=>float|'change_abs'=>float)).
		 * @since 2.3.0
		 */
		public static function get_rum_anomaly_digest( ?array $rum = null, ?int $now = null ): array {
			// Per-request memo (live path only): repeated
			// get_suggestions() calls in one request share one scan.
			$is_live = ( null === $rum && null === $now );
			if ( $is_live ) {
				try {
					$blog_id = self::rum_digest_memo_blog_id();
					if ( self::$rum_digest_memo_computed && $blog_id === self::$rum_digest_memo_blog ) {
						return is_array( self::$rum_digest_memo ) ? self::$rum_digest_memo : array();
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			try {
				$result = self::compute_rum_anomaly_digest( $rum, $now );
			} catch ( \Throwable $e ) {
				unset( $e );
				$result = array();
			}
			if ( $is_live ) {
				try {
					self::$rum_digest_memo          = is_array( $result ) ? $result : array();
					self::$rum_digest_memo_computed = true;
					self::$rum_digest_memo_blog     = self::rum_digest_memo_blog_id();
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			return is_array( $result ) ? $result : array();
		}

		/**
		 * Compute the RUM anomaly digest (unmemoized worker).
		 *
		 * See get_rum_anomaly_digest() for the contract; this worker
		 * always scans fresh so injected args (tests) never hit the memo.
		 *
		 * @param array|null $rum Optional RUM aggregate for testability.
		 * @param int|null   $now Optional current timestamp for testability.
		 * @return array[] At most one digest anomaly.
		 * @since 2.3.0
		 */
		private static function compute_rum_anomaly_digest( ?array $rum = null, ?int $now = null ): array {
			try {
				if ( null === $rum ) {
					if ( ! class_exists( 'PerformanceOptimise\Inc\RUM' ) || ! method_exists( 'PerformanceOptimise\Inc\RUM', 'get_aggregate_readonly' ) ) {
						return array();
					}
					$rum = RUM::get_aggregate_readonly();
				}
				if ( ! is_array( $rum ) || empty( $rum ) ) {
					return array();
				}
				$dates = array();
				foreach ( $rum as $date => $paths ) {
					if ( ! is_string( $date ) || '' === $date || ! is_array( $paths ) || empty( $paths ) ) {
						continue;
					}
					$dates[] = $date;
				}
				sort( $dates, SORT_STRING );
				if ( count( $dates ) < 2 ) {
					return array();
				}
				$resolved_now = self::anomaly_now( $now );
				// Single-banner cap shared with detect_anomalies().
				if ( ! self::is_anomaly_cooled_down( $resolved_now ) ) {
					return array();
				}
				$min_samples = self::anomaly_min_samples();
				if ( $min_samples < 1 ) {
					$min_samples = self::ANOMALY_MIN_SAMPLES;
				}
				$tol_pct = self::anomaly_tolerance_pct();
				$tol_abs = self::anomaly_tolerance_abs();

				$recent_date    = (string) end( $dates );
				$baseline_dates = array_slice( $dates, 0, -1 );
				$first_baseline = (string) $baseline_dates[0];
				$last_baseline  = (string) end( $baseline_dates );
				$window         = 1 === count( $baseline_dates )
					/* translators: 1: recent date, 2: baseline date. */
					? sprintf( __( 'recent %1$s vs baseline %2$s', 'performance-optimisation' ), $recent_date, $first_baseline )
					/* translators: 1: recent date, 2: first baseline date, 3: last baseline date. */
					: sprintf( __( 'recent %1$s vs baseline %2$s to %3$s', 'performance-optimisation' ), $recent_date, $first_baseline, $last_baseline );

				$paths_union = array();
				foreach ( array_merge( $baseline_dates, array( $recent_date ) ) as $date ) {
					if ( ! isset( $rum[ $date ] ) || ! is_array( $rum[ $date ] ) ) {
						continue;
					}
					foreach ( $rum[ $date ] as $path => $metrics ) {
						if ( ! is_string( $path ) || '' === $path || ! is_array( $metrics ) ) {
							continue;
						}
						$paths_union[ $path ] = true;
					}
				}

				$candidates = array();
				foreach ( array_keys( $paths_union ) as $path ) {
					foreach ( array( 'lcp', 'inp', 'cls' ) as $metric ) {
						$baseline_avgs = array();
						$baseline_n    = 0;
						foreach ( $baseline_dates as $date ) {
							$bucket = isset( $rum[ $date ][ $path ][ $metric ] ) && is_array( $rum[ $date ][ $path ][ $metric ] ) ? $rum[ $date ][ $path ][ $metric ] : null;
							if ( ! is_array( $bucket ) ) {
								continue;
							}
							$n   = isset( $bucket['n'] ) ? (int) $bucket['n'] : 0;
							$sum = isset( $bucket['sum'] ) ? (float) $bucket['sum'] : 0.0;
							if ( $n <= 0 ) {
								continue;
							}
							$baseline_n     += $n;
							$baseline_avgs[] = $sum / $n;
						}
						$recent_bucket = isset( $rum[ $recent_date ][ $path ][ $metric ] ) && is_array( $rum[ $recent_date ][ $path ][ $metric ] ) ? $rum[ $recent_date ][ $path ][ $metric ] : null;
						if ( ! is_array( $recent_bucket ) ) {
							continue;
						}
						$recent_n = isset( $recent_bucket['n'] ) ? (int) $recent_bucket['n'] : 0;
						if ( $recent_n < $min_samples || $baseline_n < $min_samples || empty( $baseline_avgs ) ) {
							continue;
						}
						$recent_sum = isset( $recent_bucket['sum'] ) ? (float) $recent_bucket['sum'] : 0.0;
						$baseline   = self::rum_median( $baseline_avgs );
						$recent     = $recent_sum / $recent_n;
						if ( function_exists( 'is_finite' ) && ( ! is_finite( $baseline ) || ! is_finite( $recent ) ) ) {
							continue;
						}
						$clean_path = function_exists( 'mb_substr' ) ? mb_substr( $path, 0, 128, 'UTF-8' ) : substr( $path, 0, 128 );
						if ( 'cls' === $metric ) {
							$threshold = $baseline + self::CLS_ABSOLUTE_DELTA + $tol_abs;
							if ( $recent < $threshold ) {
								continue;
							}
							$excess       = self::CLS_ABSOLUTE_DELTA > 0 ? ( ( $recent - $baseline ) - self::CLS_ABSOLUTE_DELTA ) / self::CLS_ABSOLUTE_DELTA : 0.0;
							$candidates[] = array(
								'key'        => 'rum:' . $clean_path,
								'metric'     => 'cls',
								'path'       => $clean_path,
								'baseline'   => (float) $baseline,
								'current'    => (float) $recent,
								'recent'     => (float) $recent,
								'window'     => $window,
								'samples'    => $recent_n,
								'source'     => 'rum-digest',
								'change_abs' => (float) ( $recent - $baseline ),
								'severity'   => (float) $excess,
							);
							continue;
						}
						if ( $baseline <= 0 ) {
							continue;
						}
						$multiplier = 'inp' === $metric ? self::INP_RELATIVE_MULTIPLIER : self::LCP_RELATIVE_MULTIPLIER;
						$threshold  = $baseline * $multiplier * ( 1.0 + $tol_pct / 100.0 );
						if ( $recent < $threshold ) {
							continue;
						}
						$excess       = $multiplier > 0 ? ( ( $recent / $baseline ) - $multiplier ) / $multiplier : 0.0;
						$candidates[] = array(
							'key'        => 'rum:' . $clean_path,
							'metric'     => $metric,
							'path'       => $clean_path,
							'baseline'   => (float) $baseline,
							'current'    => (float) $recent,
							'recent'     => (float) $recent,
							'window'     => $window,
							'samples'    => $recent_n,
							'source'     => 'rum-digest',
							'change_pct' => (float) ( ( $recent - $baseline ) / $baseline * 100.0 ),
							'severity'   => (float) $excess,
						);
					}
				}
				if ( empty( $candidates ) ) {
					return array();
				}
				usort(
					$candidates,
					static function ( $a, $b ) {
						$sa = isset( $a['severity'] ) ? (float) $a['severity'] : 0.0;
						$sb = isset( $b['severity'] ) ? (float) $b['severity'] : 0.0;
						if ( $sa === $sb ) {
							return 0;
						}
						return $sa > $sb ? -1 : 1;
					}
				);
				$winner = $candidates[0];
				unset( $winner['severity'] );
				// Record the alarm before filtering so a repeated regression
				// re-alarms only after the cooldown elapses.
				self::set_last_anomaly_alarm( $resolved_now );
				/**
				 * Filters the detected performance anomalies.
				 *
				 * Digest entries carry the trend shape (`key`, `metric`,
				 * `baseline`, `current`, `change_pct`/`change_abs`) plus
				 * `path`, `recent`, `window`, `samples`, and
				 * `source: rum-digest` so the alert can link the affected
				 * path and window.
				 *
				 * @since 2.3.0 Digest entries flow through this filter.
				 * @param array[] $anomalies At most one anomaly array.
				 */
				if ( function_exists( 'apply_filters' ) ) {
					$filtered_anomalies = apply_filters( 'wppo_ai_anomaly_detected', array( $winner ) );
					if ( is_array( $filtered_anomalies ) && ! empty( $filtered_anomalies ) ) {
						$first = $filtered_anomalies[0];
						if ( is_array( $first ) ) {
							$winner = $first;
						}
					} elseif ( is_array( $filtered_anomalies ) && empty( $filtered_anomalies ) ) {
						return array();
					}
					// Backward compatibility: LCP consumers keep the
					// legacy filter name.
					if ( 'lcp' === ( $winner['metric'] ?? 'lcp' ) ) {
						$legacy = apply_filters( 'wppo_ai_lcp_regression', array( $winner ) );
						if ( ! is_array( $legacy ) ) {
							return array( $winner );
						}
						return array_slice( array_values( $legacy ), 0, 1 );
					}
				}
				return array( $winner );
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Detect LCP/CLS regressions from stored Web Vitals trend history.
		 *
		 * Detector v2 (issue #1313): moving-average bands with ratio
		 * persistence. The baseline is the mean of the trailing
		 * `anomaly_band_window` samples (default 10) before the last N
		 * trailing windows (N = `anomaly_persistence_windows()`, default
		 * 3), and a key regresses only when EVERY trailing window clears
		 * the band upper edge (mean plus sigma floor plus tolerance):
		 * - LCP: each trailing sample >= max(baseline * 1.3 * (1+tol%), baseline + 2*σ), or
		 * - CLS: each trailing sample >= max(baseline + 0.05 + tol_abs, baseline + 2*σ).
		 *
		 * A single noisy window can therefore never page on its own, and
		 * stale history cannot skew the band.
		 *
		 * Quiet-reliability gates: below the configured trend sample floor
		 * (`anomaly_min_samples()`, default 10) no notice fires; each firing
		 * arm must be corroborated by RUM field data
		 * (`is_rum_corroborated()`, total n >= `anomaly_p75_min_samples()`,
		 * default 10, read via the side-effect-free
		 * RUM::get_aggregate_readonly() path) before alarming; with RUM
		 * collection disabled zero notices fire; and at most one anomaly
		 * overall is returned with a 7-day cooldown
		 * (`anomaly_cooldown_days`, default 7) persisted in the per-site
		 * `wppo_ai_anomaly_last_alarm` option (multisite-safe).
		 *
		 * Breaches are tracked per key in the per-site
		 * `wppo_ai_anomaly_breach_state` option so detect_recoveries()
		 * can close the loop with hysteresis, and breaches near a manual
		 * deploy note carry a `deploy_note` annotation.
		 *
		 * Every returned notice carries route plus p75 plus baseline plus
		 * delta plus samples (`route`, `p75`, `baseline`, `delta`,
		 * `samples`) alongside the legacy `key`/`current`/`change_pct`/
		 * `change_abs` keys. Thin or absent data emits no page — use
		 * get_anomaly_provisional_state() to surface the provisional
		 * collecting-data state instead.
		 *
		 * Local computation only: no remote calls, no API keys, no email.
		 * Fail-open: undersampled history (<min_samples numeric samples),
		 * short history (<persistence+1 usable windows), non-positive LCP
		 * baseline, uncorroborated arms, active cooldown, RUM disabled, or
		 * any failure returns an empty array — never fatal.
		 *
		 * Trend source is Pagespeed::get_trends() (capped 30/URL+strategy);
		 * RUM source is the read-only aggregate unless an aggregate is injected.
		 *
		 * @param array|null $trends Optional trends map for testability. When null, reads Pagespeed::get_trends().
		 * @param array|null $rum Optional RUM aggregate for testability. When null, reads the read-only RUM aggregate (zero notices when RUM disabled).
		 * @param int|null   $now Optional current timestamp for testability. When null, uses time().
		 * @return array[] At most one anomaly: array(array('key'=>string,'route'=>string,'metric'=>string,'baseline'=>float,'current'=>float,'p75'=>float,'delta'=>float,'samples'=>int,'change_pct'=>float|'change_abs'=>float)).
		 * @since 2.0.0
		 * @since 2.3.0 Three-window ratio persistence; RUM-disabled short-circuit; enriched route/p75/baseline/delta/samples payload.
		 * @since 2.3.0 v2 moving-average bands, deploy-note annotation, breach-state tracking for recovery hysteresis.
		 */
		public static function detect_anomalies( ?array $trends = null, ?array $rum = null, ?int $now = null ): array {
			try {
				if ( null === $trends ) {
					if ( ! class_exists( 'PerformanceOptimise\Inc\Pagespeed' ) ) {
						return array();
					}
					if ( ! method_exists( 'PerformanceOptimise\Inc\Pagespeed', 'get_trends' ) ) {
						return array();
					}
					$trends = Pagespeed::get_trends();
				}
				if ( ! is_array( $trends ) || empty( $trends ) ) {
					return array();
				}
				// Zero notices with RUM disabled (live read path only; an
				// injected aggregate is an explicit test/staging override).
				if ( null === $rum && ! self::is_rum_collection_enabled() ) {
					return array();
				}
				$resolved_now = self::anomaly_now( $now );
				// Single-banner cap: an active cooldown suppresses all arms.
				if ( ! self::is_anomaly_cooled_down( $resolved_now ) ) {
					return array();
				}
				$min_samples = self::anomaly_min_samples();
				if ( $min_samples < 1 ) {
					$min_samples = self::ANOMALY_MIN_SAMPLES;
				}
				$persistence = self::anomaly_persistence_windows();
				if ( $persistence < 1 ) {
					$persistence = self::ANOMALY_PERSISTENCE_WINDOWS;
				}
				// Resolve the RUM sample floor once; is_rum_corroborated()
				// receives it per arm so multi-key maps do not repeat
				// option reads + filter applications.
				$rum_floor = self::anomaly_p75_min_samples();
				if ( $rum_floor < 1 ) {
					$rum_floor = self::ANOMALY_P75_MIN_SAMPLES;
				}
				$band_window = self::anomaly_band_window();
				if ( $band_window < 1 ) {
					$band_window = self::ANOMALY_BAND_WINDOW;
				}
				$tol_pct = self::anomaly_tolerance_pct();
				$tol_abs = self::anomaly_tolerance_abs();
				foreach ( $trends as $trend_key => $snapshots ) {
					if ( ! is_array( $snapshots ) ) {
						continue;
					}
					$candidates = array();
					// LCP arm (v2 moving-average band + persistence).
					$lcps = self::collect_trend_samples( $snapshots, 'lcp', true );
					if ( count( $lcps ) >= $min_samples && count( $lcps ) >= ( $persistence + 1 ) ) {
						$tail         = array_slice( $lcps, -$persistence );
						$window       = min( $band_window, count( $lcps ) - $persistence );
						$prior_window = array_slice( $lcps, count( $lcps ) - $persistence - $window, $window );
						$band         = self::moving_band( $prior_window, 'lcp' );
						$baseline     = (float) $band['mean'];
						$gate         = max( $band['upper'], $baseline * self::LCP_RELATIVE_MULTIPLIER * ( 1.0 + $tol_pct / 100.0 ) );
						$persisted    = true;
						foreach ( $tail as $window_sample ) {
							if ( (float) $window_sample < $gate ) {
								$persisted = false;
								break;
							}
						}
						if ( $baseline > 0 && $persisted ) {
							$current      = (float) end( $lcps );
							$p75          = self::anomaly_p75( $lcps );
							$candidates[] = array(
								'key'        => (string) $trend_key,
								'route'      => (string) $trend_key,
								'metric'     => 'lcp',
								'baseline'   => (float) $baseline,
								'current'    => (float) $current,
								'p75'        => (float) $p75,
								'delta'      => (float) ( $current - $baseline ),
								'samples'    => count( $lcps ),
								'change_pct' => (float) ( ( $current - $baseline ) / $baseline * 100.0 ),
							);
						}
					}
					// CLS arm (v2 moving-average band + persistence, absolute delta NOT percent).
					$clss = self::collect_trend_samples( $snapshots, 'cls', false );
					if ( count( $clss ) >= $min_samples && count( $clss ) >= ( $persistence + 1 ) ) {
						$tail         = array_slice( $clss, -$persistence );
						$window       = min( $band_window, count( $clss ) - $persistence );
						$prior_window = array_slice( $clss, count( $clss ) - $persistence - $window, $window );
						$band         = self::moving_band( $prior_window, 'cls' );
						$baseline     = (float) $band['mean'];
						$gate         = max( $band['upper'], $baseline + self::CLS_ABSOLUTE_DELTA + $tol_abs );
						$finite       = true;
						if ( function_exists( 'is_finite' ) ) {
							$finite = is_finite( $baseline );
							foreach ( $tail as $tail_sample ) {
								if ( ! is_finite( (float) $tail_sample ) ) {
									$finite = false;
									break;
								}
							}
						}
						$persisted = true;
						foreach ( $tail as $tail_sample ) {
							if ( (float) $tail_sample < $gate ) {
								$persisted = false;
								break;
							}
						}
						if ( $finite && $persisted ) {
							$current      = (float) end( $clss );
							$p75          = self::anomaly_p75( $clss );
							$candidates[] = array(
								'key'        => (string) $trend_key,
								'route'      => (string) $trend_key,
								'metric'     => 'cls',
								'baseline'   => (float) $baseline,
								'current'    => (float) $current,
								'p75'        => (float) $p75,
								'delta'      => (float) ( $current - $baseline ),
								'samples'    => count( $clss ),
								'change_abs' => (float) ( $current - $baseline ),
							);
						}
					}
					foreach ( $candidates as $anomaly ) {
						// RUM corroboration gate: trends + real-user data must agree.
						if ( ! self::is_rum_corroborated( $anomaly['metric'], $rum, (float) $anomaly['baseline'], $rum_floor ) ) {
							continue;
						}
						// Record the alarm before filtering so a repeated
						// regression re-alarms only after the cooldown elapses.
						self::set_last_anomaly_alarm( $resolved_now );
						// v2: track the breach for recovery hysteresis and
						// annotate breaches near a noted deploy.
						self::record_breach_state( (string) $anomaly['key'], (string) $anomaly['metric'], (float) $anomaly['baseline'], (float) $anomaly['current'], $resolved_now );
						$deploy_note = self::find_deploy_note_near( $resolved_now );
						if ( '' !== $deploy_note ) {
							$anomaly['deploy_note'] = $deploy_note;
						}
						/**
						 * Filters the detected performance anomalies.
						 *
						 * @since 2.0.0
						 * @param array[] $anomalies At most one anomaly array.
						 */
						$filtered = $anomaly;
						if ( function_exists( 'apply_filters' ) ) {
							$filtered_anomalies = apply_filters( 'wppo_ai_anomaly_detected', array( $anomaly ) );
							if ( is_array( $filtered_anomalies ) && ! empty( $filtered_anomalies ) ) {
								$first = $filtered_anomalies[0];
								if ( is_array( $first ) ) {
									$filtered = $first;
								}
							} elseif ( is_array( $filtered_anomalies ) && empty( $filtered_anomalies ) ) {
								return array();
							}
							// Backward compatibility: LCP consumers keep the
							// legacy filter name.
							if ( 'lcp' === ( $filtered['metric'] ?? 'lcp' ) ) {
								$legacy = apply_filters( 'wppo_ai_lcp_regression', array( $filtered ) );
								if ( ! is_array( $legacy ) ) {
									return array( $filtered );
								}
								// Cap to a single anomaly even if a filter appends more.
								return array_slice( array_values( $legacy ), 0, 1 );
							}
						}
						// Cap to a single anomaly even if a filter appends more.
						return array( $filtered );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
			return array();
		}

		/**
		 * Transient prefix for the per-URL CSS-refresh cooldown (issue #1407).
		 *
		 * The full key is the prefix plus md5() of the resolved URL,
		 * blog-qualified via Util::transient_key() so multisite sites cool
		 * down independently. Stored value is the queue timestamp.
		 *
		 * @since 2.3.0
		 * @var string
		 */
		private const CSS_REFRESH_COOLDOWN_PREFIX = 'wppo_ai_css_refresh_';

		/**
		 * Option storing per-URL before/after LCP snapshots (autoload=no).
		 *
		 * Per-site option, hence inherently multisite-safe. Bounded to 20
		 * entries; proves the refresh loop with before/after LCP numbers.
		 *
		 * @since 2.3.0
		 * @var string
		 */
		private const CSS_REFRESH_SNAPSHOT_OPTION = 'wppo_ai_css_refresh_snapshots';

		/**
		 * Default per-URL CSS-refresh cooldown in days.
		 *
		 * @since 2.3.0
		 * @var int
		 */
		private const CSS_REFRESH_COOLDOWN_DAYS = 7;

		/**
		 * Whether RUM-triggered CSS refresh on LCP regression is enabled.
		 *
		 * Additive opt-in living in
		 * `wppo_settings[ai_adaptive][css_refresh_on_lcp_regression]`
		 * (default false/suggest-only). Filterable via
		 * `wppo_ai_css_refresh_enabled`. Fail-open to false: any failure
		 * keeps the loop suggestion-only.
		 *
		 * @return bool True when a regression may queue a CSS regen job.
		 * @since 2.3.0
		 */
		public static function is_css_refresh_enabled(): bool {
			try {
				$enabled = false;
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
					$settings = Util::get_settings();
					$enabled  = ! empty( $settings['ai_adaptive']['css_refresh_on_lcp_regression'] );
				}
				if ( function_exists( 'apply_filters' ) ) {
					/**
					 * Filters whether RUM-triggered CSS refresh may queue jobs.
					 *
					 * @since 2.3.0
					 * @param bool $enabled Whether the CSS-refresh opt-in is on.
					 */
					$enabled = (bool) apply_filters( 'wppo_ai_css_refresh_enabled', $enabled );
				}
				return $enabled;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Resolve the per-URL CSS-refresh cooldown window in days.
		 *
		 * Reads the additive `ai_adaptive.css_refresh_cooldown_days`
		 * setting, falling back to CSS_REFRESH_COOLDOWN_DAYS. Filterable via
		 * `wppo_ai_css_refresh_cooldown_days`. Fail-open to 7.
		 *
		 * A 0-day window would re-queue on every suggestions render while
		 * the regression persists (dedup would rely solely on
		 * as_has_scheduled_action() for pending jobs), so the effective
		 * floor is 1 day: stored/filtered values below 1 are normalized up.
		 *
		 * @return int Cooldown days (>=1).
		 * @since 2.3.0
		 */
		public static function css_refresh_cooldown_days(): int {
			try {
				$days = self::CSS_REFRESH_COOLDOWN_DAYS;
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
					$settings = Util::get_settings();
					if ( isset( $settings['ai_adaptive']['css_refresh_cooldown_days'] ) ) {
						$raw = $settings['ai_adaptive']['css_refresh_cooldown_days'];
						if ( is_numeric( $raw ) && (int) $raw >= 0 ) {
							$days = (int) $raw;
						}
					}
				}
				if ( function_exists( 'apply_filters' ) ) {
					/**
					 * Filters the per-URL CSS-refresh cooldown window.
					 *
					 * Values below 1 are normalized up to 1 (see
					 * css_refresh_cooldown_days()).
					 *
					 * @since 2.3.0
					 * @param int $days Cooldown days.
					 */
					$filtered = apply_filters( 'wppo_ai_css_refresh_cooldown_days', $days );
					if ( is_numeric( $filtered ) && (int) $filtered >= 0 ) {
						$days = (int) $filtered;
					}
				}
				if ( $days < 1 ) {
					$days = 1;
				}
				return $days >= 1 ? $days : self::CSS_REFRESH_COOLDOWN_DAYS;
			} catch ( \Throwable $e ) {
				unset( $e );
				return self::CSS_REFRESH_COOLDOWN_DAYS;
			}
		}

		/**
		 * Resolve a trend anomaly key back to its scanned URL.
		 *
		 * Trend keys are opaque (`md5( esc_url_raw( $url ) ) . '_' . strategy`,
		 * see Pagespeed::record_trend()) and cannot be reversed, so
		 * forward-match over home + `performance_audit.high_value_urls`
		 * (mirroring Cron::web_vitals_rescan_cron() enumeration, capped at
		 * 20 raw entries). Fail-open: returns '' when nothing matches.
		 *
		 * Lazy by design: called only when an LCP regression fired, so the
		 * happy path (no regression) performs zero extra queries.
		 *
		 * @param string $trend_key Trend key (`md5(url)_strategy`).
		 * @return string Resolved absolute URL, or '' when unresolvable.
		 * @since 2.3.0
		 */
		public static function resolve_anomaly_url( string $trend_key ): string {
			try {
				if ( '' === $trend_key ) {
					return '';
				}
				$candidates = array();
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) ) {
					if ( method_exists( 'PerformanceOptimise\Inc\Util', 'cached_home_url' ) ) {
						$candidates[] = Util::cached_home_url( '/' );
					}
					if ( method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
						$settings   = Util::get_settings();
						$high_value = $settings['performance_audit']['high_value_urls'] ?? array();
						if ( is_string( $high_value ) ) {
							$high_value = preg_split( '/[\r\n,]+/', $high_value );
						}
						if ( is_array( $high_value ) ) {
							$high_value = array_slice( array_values( $high_value ), 0, 20 );
							foreach ( $high_value as $high_url ) {
								if ( is_string( $high_url ) && '' !== trim( $high_url ) ) {
									$candidates[] = trim( $high_url );
								}
							}
						}
					}
				}
				foreach ( $candidates as $candidate ) {
					if ( ! is_string( $candidate ) || '' === $candidate ) {
						continue;
					}
					$clean = function_exists( 'esc_url_raw' ) ? esc_url_raw( $candidate ) : $candidate;
					if ( ! is_string( $clean ) || '' === $clean ) {
						continue;
					}
					foreach ( array( 'mobile', 'desktop' ) as $strategy ) {
						$candidate_key = md5( $clean ) . '_' . $strategy;
						if ( function_exists( 'hash_equals' ) ) {
							if ( hash_equals( $trend_key, $candidate_key ) ) {
								return $clean;
							}
						} elseif ( $candidate_key === $trend_key ) {
							return $clean;
						}
					}
				}
				return '';
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Whether a URL is the site homepage.
		 *
		 * Core post-ID lookup returns 0 for the front page, so the queue path
		 * needs an explicit homepage check before degrading to
		 * suggestion-only. Comparison is trailing-slash insensitive.
		 *
		 * @param string $url Absolute URL.
		 * @return bool True when the URL is the homepage.
		 * @since 2.3.0
		 */
		public static function is_homepage_url( string $url ): bool {
			try {
				if ( '' === $url ) {
					return false;
				}
				$home = '';
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'cached_home_url' ) ) {
					$home = Util::cached_home_url( '/' );
				} elseif ( function_exists( 'home_url' ) ) {
					$home = home_url( '/' );
				}
				if ( ! is_string( $home ) || '' === $home ) {
					return false;
				}
				$normalize = static function ( $value ) {
					$value = strtolower( trim( (string) $value ) );
					return rtrim( $value, '/' );
				};
				return $normalize( $url ) === $normalize( $home );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Resolve the static front-page post ID, if one is configured.
		 *
		 * Fail-open: any failure returns 0 so callers degrade to
		 * suggestion-only with the distinct `homepage` reason.
		 *
		 * @return int Front-page post ID (>0), or 0 when none configured.
		 * @since 2.3.0
		 */
		public static function resolve_front_page_post_id(): int {
			try {
				if ( ! function_exists( 'get_option' ) ) {
					return 0;
				}
				// WordPress retains a stale page_on_front value after
				// switching back to latest-posts, so gate on show_on_front
				// to avoid queueing a regen against a page that is no
				// longer the front page (fail-open: return 0).
				if ( 'page' !== get_option( 'show_on_front' ) ) {
					return 0;
				}
				$front_id = (int) get_option( 'page_on_front' );
				return $front_id > 0 ? $front_id : 0;
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		/**
		 * Resolve a URL to its post ID for single-post used-CSS queueing.
		 *
		 * Guarded: returns 0 when url_to_postid() is unavailable or the URL
		 * maps to no post (e.g. the home page when no static front page is
		 * configured). Homepage URLs with a static front page resolve via
		 * resolve_front_page_post_id(); other unresolvable URLs degrade to
		 * suggestion-only with the distinct `homepage` reason.
		 *
		 * @param string $url Absolute URL.
		 * @return int Post ID (>0), or 0 when unresolvable.
		 * @since 2.3.0
		 */
		public static function resolve_anomaly_post_id( string $url ): int {
			try {
				if ( '' === $url ) {
					return 0;
				}
				if ( function_exists( 'url_to_postid' ) ) {
					$post_id = (int) url_to_postid( $url );
					if ( $post_id > 0 ) {
						return $post_id;
					}
				}
				// Front-page fallback: url_to_postid() returns 0 for the
				// homepage, so a configured static front page resolves here.
				if ( self::is_homepage_url( $url ) ) {
					return self::resolve_front_page_post_id();
				}
				return 0;
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		/**
		 * Record the before/after LCP snapshot for a refresh decision.
		 *
		 * Bounded to the 20 most recent entries in the per-site
		 * CSS_REFRESH_SNAPSHOT_OPTION (autoload=no). Best-effort: never
		 * throws, never fatal when the options API is missing.
		 *
		 * @param string $snapshot_key Snapshot key (md5 of URL or trend key).
		 * @param array  $entry Snapshot entry (url, before_lcp, current_lcp, queued, ...).
		 * @return void
		 * @since 2.3.0
		 */
		private static function record_css_refresh_snapshot( string $snapshot_key, array $entry ): void {
			try {
				if ( '' === $snapshot_key || ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
					return;
				}
				$stored = get_option( self::CSS_REFRESH_SNAPSHOT_OPTION, array() );
				if ( ! is_array( $stored ) ) {
					$stored = array();
				}
				$stored[ $snapshot_key ] = $entry;
				if ( count( $stored ) > 20 ) {
					$stored = array_slice( $stored, -20, 20, true );
				}
				update_option( self::CSS_REFRESH_SNAPSHOT_OPTION, $stored, false );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Get the stored before/after LCP snapshot for a URL.
		 *
		 * Powers the admin-notice proof around a refresh. Fail-open: any
		 * failure returns an empty array.
		 *
		 * @param string $url Absolute URL.
		 * @return array Snapshot entry, or empty array when none stored.
		 * @since 2.3.0
		 */
		public static function get_css_refresh_snapshot( string $url ): array {
			try {
				if ( '' === $url || ! function_exists( 'get_option' ) ) {
					return array();
				}
				$stored = get_option( self::CSS_REFRESH_SNAPSHOT_OPTION, array() );
				if ( ! is_array( $stored ) ) {
					return array();
				}
				$entry = $stored[ md5( $url ) ] ?? array();
				return is_array( $entry ) ? $entry : array();
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Bridge an LCP anomaly to a guarded single used-CSS regen job.
		 *
		 * Self-healing CSS (issue #1407): on a field-LCP regression for a
		 * URL, queue at most one `wppo_used_css_generate` job per URL per
		 * cooldown window — and only when the
		 * `ai_adaptive.css_refresh_on_lcp_regression` opt-in is on (default
		 * off/suggest-only). Non-LCP anomalies never queue.
		 *
		 * Fail-open by design: toggle-off, unresolvable URL, missing post,
		 * homepage without a static front page, excluded post type,
		 * scheduler absence, enqueue failure, or any throwable returns
		 * `queued => false` with a machine-readable `reason`; last-good CSS
		 * is kept (nothing is ever deleted here) so output degrades to the
		 * current un-refreshed CSS, never fatal. Only singular posts are
		 * queued: the homepage resolves via the static front page
		 * (`page_on_front`) and otherwise degrades with reason `homepage`.
		 *
		 * Lazy boot: no extra queries unless an LCP regression fired; the
		 * queue path adds one transient read plus (only on an actual queue)
		 * one snapshot option write. Non-queueing decisions perform no
		 * option writes, and unresolvable trend keys are never stored in
		 * the bounded proof option so they cannot evict genuine entries.
		 *
		 * @param array    $anomaly Anomaly array from detect_anomalies().
		 * @param int|null $now Optional current timestamp (tests).
		 * @return array{queued:bool,reason:string,url:string,post_id:int,before_lcp:float,current_lcp:float} Refresh decision.
		 * @since 2.3.0
		 */
		public static function maybe_queue_css_refresh( array $anomaly, ?int $now = null ): array {
			$fallback = array(
				'queued'      => false,
				'reason'      => 'error',
				'url'         => '',
				'post_id'     => 0,
				'before_lcp'  => 0.0,
				'current_lcp' => 0.0,
			);
			try {
				if ( 'lcp' !== ( $anomaly['metric'] ?? '' ) ) {
					$fallback['reason'] = 'non-lcp';
					return $fallback;
				}
				$baseline = isset( $anomaly['baseline'] ) ? (float) $anomaly['baseline'] : 0.0;
				$current  = isset( $anomaly['current'] ) ? (float) $anomaly['current'] : 0.0;
				if ( $baseline <= 0 || $current <= 0 ) {
					$fallback['reason'] = 'invalid-sample';
					return $fallback;
				}
				$fallback['before_lcp']  = $baseline;
				$fallback['current_lcp'] = $current;
				$trend_key               = isset( $anomaly['key'] ) ? (string) $anomaly['key'] : '';
				$url                     = self::resolve_anomaly_url( $trend_key );
				$fallback['url']         = $url;
				if ( '' === $url ) {
					$fallback['reason'] = 'unresolvable-url';
					return $fallback;
				}
				if ( ! self::is_css_refresh_enabled() ) {
					$fallback['reason'] = 'opt-out';
					return $fallback;
				}
				$cooldown_key = '';
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'transient_key' ) ) {
					$cooldown_key = Util::transient_key( self::CSS_REFRESH_COOLDOWN_PREFIX . md5( $url ) );
				} else {
					$cooldown_key = self::CSS_REFRESH_COOLDOWN_PREFIX . md5( $url );
				}
				if ( function_exists( 'get_transient' ) && get_transient( $cooldown_key ) ) {
					$fallback['reason'] = 'cooldown';
					return $fallback;
				}
				$resolved_now        = self::anomaly_now( $now );
				$post_id             = self::resolve_anomaly_post_id( $url );
				$fallback['post_id'] = $post_id;
				if ( $post_id <= 0 ) {
					$fallback['reason'] = self::is_homepage_url( $url ) ? 'homepage' : 'no-post';
					return $fallback;
				}
				if ( class_exists( 'PerformanceOptimise\Inc\Used_CSS' ) && method_exists( 'PerformanceOptimise\Inc\Used_CSS', 'is_excluded_post' ) ) {
					try {
						if ( Used_CSS::is_excluded_post( $post_id ) ) {
							$fallback['reason'] = 'excluded-post';
							return $fallback;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( ! function_exists( 'as_enqueue_async_action' ) ) {
					$fallback['reason'] = 'scheduler-unavailable';
					return $fallback;
				}
				$job_args = array( 'post_id' => $post_id );
				// Atomic-first on AS 4.x (issue #1407 review, same pattern as
				// the #1408 used_css_regenerate fix): the `$unique` insert
				// dedupes hook+args+group in the store, closing the
				// check-then-act race where two concurrent suggestion renders
				// both passed as_has_scheduled_action() and double-queued. A 0
				// return is ambiguous (deduped vs failure), so re-probe the
				// guard once to report "already queued" honestly.
				$job_id = 0;
				try {
					if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'enqueue_unique_async_action' ) ) {
						$job_id = (int) Util::enqueue_unique_async_action( 'wppo_used_css_generate', $job_args, 'performance_optimisation' );
					} elseif ( function_exists( 'as_enqueue_async_action' ) ) {
						$already_pending = false;
						if ( function_exists( 'as_has_scheduled_action' ) ) {
							try {
								$already_pending = (bool) as_has_scheduled_action( 'wppo_used_css_generate', $job_args, 'performance_optimisation' );
							} catch ( \Throwable $e ) {
								unset( $e );
								$already_pending = false;
							}
						}
						if ( $already_pending ) {
							$fallback['reason'] = 'already-queued';
							return $fallback;
						}
						$job_id = (int) as_enqueue_async_action( 'wppo_used_css_generate', $job_args, 'performance_optimisation' );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					$job_id = 0;
				}
				if ( $job_id <= 0 ) {
					$already_pending = false;
					if ( function_exists( 'as_has_scheduled_action' ) ) {
						try {
							$already_pending = (bool) as_has_scheduled_action( 'wppo_used_css_generate', $job_args, 'performance_optimisation' );
						} catch ( \Throwable $e ) {
							unset( $e );
							$already_pending = false;
						}
					}
					if ( $already_pending ) {
						$fallback['reason'] = 'already-queued';
						return $fallback;
					}
					$fallback['reason'] = 'enqueue-failed';
					return $fallback;
				}
				self::set_css_refresh_cooldown( $cooldown_key, $resolved_now );
				self::record_css_refresh_snapshot(
					md5( $url ),
					array(
						'url'         => $url,
						'trend_key'   => $trend_key,
						'before_lcp'  => $baseline,
						'current_lcp' => $current,
						'queued'      => true,
						'reason'      => 'queued',
						'post_id'     => $post_id,
						'job_id'      => $job_id,
						'queued_at'   => $resolved_now,
					)
				);
				if ( function_exists( 'do_action' ) ) {
					/**
					 * Fires after an LCP regression queues a used-CSS refresh.
					 *
					 * Lets the critical-CSS layer hook a template refresh in
					 * without coupling the bridge to template mapping. In-repo
					 * consumer: Main::on_ai_css_refresh_queued() regenerates the
					 * matching critical-CSS template (`home`/`page`/`single`).
					 *
					 * @since 2.3.0
					 * @param string $url regressed URL.
					 * @param int    $post_id Queued post ID.
					 * @param array  $anomaly The firing LCP anomaly.
					 */
					do_action( 'wppo_ai_css_refresh_queued', $url, $post_id, $anomaly );
				}
				$fallback['queued'] = true;
				$fallback['reason'] = 'queued';
				return $fallback;
			} catch ( \Throwable $e ) {
				unset( $e );
				return $fallback;
			}
		}

		/**
		 * Arm the per-URL CSS-refresh cooldown transient.
		 *
		 * Best-effort: a missing transient API is a no-op. The effective
		 * cooldown floor is 1 day (see css_refresh_cooldown_days()), so a
		 * queued regen always arms the transient. Never throws.
		 *
		 * @param string $cooldown_key Blog-aware transient key.
		 * @param int    $now Current timestamp.
		 * @return void
		 * @since 2.3.0
		 */
		private static function set_css_refresh_cooldown( string $cooldown_key, int $now ): void {
			try {
				if ( '' === $cooldown_key || ! function_exists( 'set_transient' ) ) {
					return;
				}
				$days = self::css_refresh_cooldown_days();
				if ( $days < 1 ) {
					$days = 1;
				}
				$day_seconds = defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS : 86400;
				set_transient( $cooldown_key, $now, $days * $day_seconds );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Describe the provisional (collecting-data) anomaly state.
		 *
		 * Thin or absent data never pages — this read-only helper reports
		 * WHY detect_anomalies() is quiet so staging and the SPA can render
		 * a provisional "collecting data" state instead of silence. Never
		 * pages, never writes, never fatal.
		 *
		 * Reasons: `rum_disabled` (RUM collection off), `rum_thin`
		 * (best-sampled RUM metric below `anomaly_p75_min_samples()` or
		 * aggregate empty), `trends_thin` (no key reaches both
		 * `anomaly_min_samples()` and the persistence+1 history floor),
		 * `cooling_down` (a page already fired inside the cooldown),
		 * `ready` (history + RUM floors satisfied — detection may page).
		 *
		 * With RUM disabled the state is always provisional (zero notices
		 * by design); otherwise the RUM floor is evaluated against the
		 * injected aggregate or the read-only live aggregate.
		 *
		 * @param array|null $trends Optional trends map (null = live Pagespeed::get_trends()).
		 * @param array|null $rum Optional RUM aggregate (null = live read-only aggregate).
		 * @param int|null   $now Optional current timestamp for testability.
		 * @return array{provisional:bool,reason:string,samples:int,min_samples:int,rum_samples:int,rum_min_samples:int} Provisional state.
		 * @since 2.3.0
		 */
		public static function get_anomaly_provisional_state( ?array $trends = null, ?array $rum = null, ?int $now = null ): array {
			$fallback = array(
				'provisional'     => true,
				'reason'          => 'trends_thin',
				'samples'         => 0,
				'min_samples'     => self::ANOMALY_MIN_SAMPLES,
				'rum_samples'     => 0,
				'rum_min_samples' => self::ANOMALY_P75_MIN_SAMPLES,
			);
			try {
				$min_samples                 = self::anomaly_min_samples();
				$rum_min_samples             = self::anomaly_p75_min_samples();
				$fallback['min_samples']     = $min_samples;
				$fallback['rum_min_samples'] = $rum_min_samples;
				if ( null === $trends ) {
					if ( ! class_exists( 'PerformanceOptimise\Inc\Pagespeed' ) || ! method_exists( 'PerformanceOptimise\Inc\Pagespeed', 'get_trends' ) ) {
						return $fallback;
					}
					$trends = Pagespeed::get_trends();
				}
				if ( ! is_array( $trends ) || empty( $trends ) ) {
					return $fallback;
				}
				// Count the best-sampled key across both arms. The ready gate
				// also requires the persistence+1 short-history floor from
				// detect_anomalies() so provisional never claims ready
				// while detection always returns empty.
				$best = 0;
				foreach ( $trends as $snapshots ) {
					if ( ! is_array( $snapshots ) ) {
						continue;
					}
					$best = max( $best, count( self::collect_trend_samples( $snapshots, 'lcp', true ) ), count( self::collect_trend_samples( $snapshots, 'cls', false ) ) );
				}
				$fallback['samples'] = $best;
				$persistence         = self::anomaly_persistence_windows();
				if ( $persistence < 1 ) {
					$persistence = self::ANOMALY_PERSISTENCE_WINDOWS;
				}
				$required_history = max( $min_samples, $persistence + 1 );
				if ( $best < $required_history ) {
					$fallback['reason'] = 'trends_thin';
					return $fallback;
				}
				// RUM-disabled short-circuit: provisional by design.
				$rum_injected = null !== $rum;
				if ( ! $rum_injected && ! self::is_rum_collection_enabled() ) {
					$fallback['reason'] = 'rum_disabled';
					return $fallback;
				}
				$aggregate = $rum_injected ? $rum : self::read_rum_aggregate_for_anomaly();
				// Match the corroboration gate semantics: the p75 floor is
				// enforced per arm (per metric), so report the best-sampled
				// metric total instead of summing both arms (which would
				// double-count the same visits).
				$total_lcp = 0;
				$total_cls = 0;
				if ( is_array( $aggregate ) ) {
					foreach ( $aggregate as $paths ) {
						if ( ! is_array( $paths ) ) {
							continue;
						}
						foreach ( $paths as $metrics ) {
							if ( ! is_array( $metrics ) ) {
								continue;
							}
							if ( isset( $metrics['lcp'] ) && is_array( $metrics['lcp'] ) && isset( $metrics['lcp']['n'] ) ) {
								$total_lcp += (int) $metrics['lcp']['n'];
							}
							if ( isset( $metrics['cls'] ) && is_array( $metrics['cls'] ) && isset( $metrics['cls']['n'] ) ) {
								$total_cls += (int) $metrics['cls']['n'];
							}
						}
					}
				}
				$total_n                 = max( $total_lcp, $total_cls );
				$fallback['rum_samples'] = $total_n;
				if ( $total_n < $rum_min_samples ) {
					$fallback['reason'] = 'rum_thin';
					return $fallback;
				}
				if ( ! self::is_anomaly_cooled_down( self::anomaly_now( $now ) ) ) {
					$fallback['provisional'] = true;
					$fallback['reason']      = 'cooling_down';
					return $fallback;
				}
				$fallback['provisional'] = false;
				$fallback['reason']      = 'ready';
				return $fallback;
			} catch ( \Throwable $e ) {
				unset( $e );
				return $fallback;
			}
		}
	}
}
