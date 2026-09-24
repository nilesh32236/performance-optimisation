<?php
/**
 * Critical-CSS inline budget + regeneration-cooldown policy (P3-018).
 *
 * CSS domain: owns the budget/cooldown-gate cluster extracted verbatim
 * from Critical_CSS (inline size cap, truncation, full/targeted regen
 * cooldowns + throttle stamps, committed-bytes ledger, effective budget,
 * budget coordination, gzipped sizing, over-budget guard, and the budget
 * label). Static-to-static move — `self::` stays valid inside this class.
 * Critical_CSS keeps thin same-signature static proxies (facade rule) so
 * all existing callers, hooks, filters, and method_exists guards behave
 * identically. No option/schema changes; per-site options stay multisite
 * safe via core get_option/update_option; no data migration.
 *
 * Load order: this class never references Critical_CSS at file scope —
 * only Util plus core functions inside method bodies — so either class
 * may load first via Loader_Map without a circular-include failure.
 *
 * @since convention: methods moved verbatim from Critical_CSS retain their
 * original @since tags to preserve history; only new policy infrastructure
 * (class, memos, seams) uses @since NEXT.
 *
 * @package PerformanceOptimise\Inc
 * @since NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Ccss_Budget_Policy' ) ) {
	/**
	 * Class Ccss_Budget_Policy
	 *
	 * Inline budget + regen-cooldown policy for critical CSS. Generation,
	 * parsing, storage, exclusions, retry scheduling, and hook
	 * registrations stay on Critical_CSS / Ccss_Store.
	 *
	 * @since NEXT
	 */
	class Ccss_Budget_Policy {
		/**
		 * Default cap for inlined critical CSS in bytes (20 KB).
		 *
		 * Moved verbatim from Critical_CSS (P3-018); Critical_CSS keeps a
		 * BC alias with the identical value (pinned by
		 * CcssBudgetPolicyParityTest) while the policy reads this constant
		 * directly.
		 *
		 * @since 2.0.0
		 * @var int
		 */
		private const DEFAULT_CCSS_MAX_SIZE = 20480;

		/**
		 * Default gzipped inline budget in bytes for critical CSS (14 KB).
		 *
		 * Moved verbatim from Critical_CSS (P3-018); see alias note above.
		 *
		 * @since 2.3.0
		 * @var int
		 */
		private const DEFAULT_CCSS_INLINE_BUDGET_BYTES = 14336;

		/**
		 * Minimum gzipped inline budget in bytes (1 KB, issue #1388).
		 *
		 * Moved verbatim from Critical_CSS (P3-018); see alias note above.
		 *
		 * @since 2.3.0
		 * @var int
		 */
		private const MIN_CCSS_INLINE_BUDGET_BYTES = 1024;

		/**
		 * Maximum gzipped inline budget in bytes (100 KB, issue #1388).
		 *
		 * Moved verbatim from Critical_CSS (P3-018); see alias note above.
		 *
		 * @since 2.3.0
		 * @var int
		 */
		private const MAX_CCSS_INLINE_BUDGET_BYTES = 102400;

		/**
		 * Option holding the last full-regeneration timestamp (issue #1462).
		 *
		 * Canonical single source (P3-018 review): Critical_CSS keeps a
		 * public BC alias with the identical value (pinned by
		 * CcssBudgetPolicyParityTest) while the policy reads this constant
		 * directly.
		 *
		 * @since 2.3.0
		 * @var string
		 */
		public const LAST_FULL_REGEN_OPTION = 'wppo_ccss_last_full_regen';

		/**
		 * Default full-regeneration cooldown in seconds (issue #1462).
		 *
		 * Moved verbatim from Critical_CSS (P3-018); see alias note above.
		 *
		 * @since 2.3.0
		 * @var int
		 */
		private const FULL_REGEN_COOLDOWN_SECONDS = 18000;

		/**
		 * Option holding the last targeted-regeneration timestamp (issue #1462).
		 *
		 * Canonical single source (P3-018 review): Critical_CSS keeps a
		 * public BC alias with the identical value (pinned by
		 * CcssBudgetPolicyParityTest) while the policy reads this constant
		 * directly.
		 *
		 * @since 2.3.0
		 * @var string
		 */
		public const TARGETED_REGEN_OPTION = 'wppo_ccss_last_targeted_regen';

		/**
		 * Targeted-regeneration burst-throttle window in seconds (issue #1462).
		 *
		 * Moved verbatim from Critical_CSS (P3-018); see alias note above.
		 *
		 * @since 2.3.0
		 * @var int
		 */
		private const TARGETED_REGEN_COOLDOWN_SECONDS = 3600;

		/**
		 * Per-request memo of gzipped transfer sizes keyed by md5(css).
		 *
		 * Moved with gzipped_size() from Critical_CSS. Reset via
		 * reset_ccss_memo().
		 *
		 * @since NEXT
		 * @var array<string, int>
		 */
		private static array $gzip_size_memo = array();

		/**
		 * Reset the per-request budget-policy memos.
		 *
		 * Called by Critical_CSS::reset_ccss_memo() so existing callers
		 * stay identical.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function reset_ccss_memo(): void {
			self::$gzip_size_memo = array();
		}

		/**
		 * Core `styles_inline_size_limit()` bridge.
		 *
		 * Local copy of the Critical_CSS private helper (P3-018): the
		 * cluster's get_effective_ccss_budget() needs it, and routing the
		 * policy through a private Critical_CSS method would re-add the
		 * edge this extraction removes. Single line, same contract.
		 *
		 * @since NEXT
		 * @return int The inline size limit in bytes.
		 */
		private static function get_styles_inline_limit(): int {
			return Util::get_styles_inline_limit();
		}

		/**
		 * Read the configured CCSS inline size cap in bytes.
		 *
		 * The single source of truth is `Util::get_default_settings()`
		 * (`file_optimisation.ccssMaxSize`). Missing or non-positive values
		 * fall back to DEFAULT_CCSS_MAX_SIZE so inline output is always
		 * bounded.
		 *
		 * @return int Cap in bytes.
		 * @since 2.0.0 Moved verbatim from Critical_CSS (P3-018); original tag retained.
		 */
		public static function get_ccss_max_size(): int {
			$options = Util::get_settings();
			$raw     = $options['file_optimisation']['ccssMaxSize'] ?? self::DEFAULT_CCSS_MAX_SIZE;
			$cap     = function_exists( 'absint' ) ? absint( $raw ) : abs( (int) $raw );
			return $cap > 0 ? (int) $cap : self::DEFAULT_CCSS_MAX_SIZE;
		}

		/**
		 * Truncate CSS to the cap without breaking a rule.
		 *
		 * Thin wrapper over Util::split_css_for_inline_budget(): the cut
		 * lands on the last top-level closing brace at or under the cap so
		 * output never ends mid-rule or leaves an `@media`/`@supports`/`@layer`
		 * wrapper unclosed (brace depth tracked, braces inside quoted strings
		 * and CSS comments skipped). Returns an empty string when no complete
		 * top-level rule fits — callers treat that as over-cap and serve the
		 * file variant instead. The single scanner lives in
		 * Util::find_top_level_css_cut() so the two call sites cannot drift.
		 *
		 * @param string $css CSS content.
		 * @param int    $cap Maximum bytes.
		 * @return string Truncated CSS, or '' when nothing fits.
		 * @since 2.0.0 Moved verbatim from Critical_CSS (P3-018); original tag retained.
		 */
		public static function truncate_to_cap( string $css, int $cap ): string {
			if ( '' === $css || $cap <= 0 ) {
				return strlen( $css ) <= $cap ? $css : '';
			}
			if ( strlen( $css ) <= $cap ) {
				return $css;
			}
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'split_css_for_inline_budget' ) ) {
					$split = Util::split_css_for_inline_budget( $css, $cap );
					return isset( $split['inline'] ) ? (string) $split['inline'] : '';
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			$cut = strrpos( substr( $css, 0, $cap ), '}' );
			if ( false === $cut ) {
				return '';
			}
			return substr( $css, 0, (int) $cut + 1 );
		}

		/**
		 * Effective full-regen cooldown in seconds (issue #1462).
		 *
		 * Filterable via `wppo_ccss_regen_cooldown` when a listener is
		 * registered (repo convention: has_filter() before apply_filters());
		 * invalid filter output is ignored and non-positive values fall back
		 * to FULL_REGEN_COOLDOWN_SECONDS so a rogue filter can never disable
		 * the cooldown. Core's `styles_inline_size_limit` filter is untouched
		 * here — it always runs through Util::get_styles_inline_limit().
		 *
		 * @return int Cooldown in seconds.
		 * @since 2.3.0 Moved verbatim from Critical_CSS (P3-018); original tag retained.
		 */
		public static function get_full_regen_cooldown(): int {
			try {
				$cooldown = self::FULL_REGEN_COOLDOWN_SECONDS;
				if ( function_exists( 'apply_filters' ) && function_exists( 'has_filter' ) && has_filter( 'wppo_ccss_regen_cooldown' ) ) {
					$filtered = apply_filters( 'wppo_ccss_regen_cooldown', $cooldown );
					if ( is_numeric( $filtered ) && (int) $filtered > 0 ) {
						$cooldown = (int) $filtered;
					}
				}
				return $cooldown > 0 ? (int) $cooldown : self::FULL_REGEN_COOLDOWN_SECONDS;
			} catch ( \Throwable $e ) {
				unset( $e );
				return self::FULL_REGEN_COOLDOWN_SECONDS;
			}
		}

		/**
		 * Read the configured gzipped inline budget in bytes (issue #1388).
		 *
		 * The single source of truth is `Util::get_default_settings()`
		 * (`file_optimisation.ccssInlineBudgetKb`, default 14 KB). Missing
		 * or out-of-range values fail open to
		 * DEFAULT_CCSS_INLINE_BUDGET_BYTES so inline output is always
		 * bounded. Filterable via `wppo_ccss_inline_budget` when a listener
		 * is registered; invalid filter output is ignored and oversized
		 * values clamp, so a rogue filter can never uncap inline weight.
		 *
		 * @return int Budget in bytes, clamped to MIN..MAX_CCSS_INLINE_BUDGET_BYTES.
		 * @since 2.3.0 Moved verbatim from Critical_CSS (P3-018); original tag retained.
		 * @see Ccss_Budget_Policy::get_ccss_max_size()
		 */
		public static function get_ccss_inline_budget_bytes(): int {
			try {
				$options = Util::get_settings();
				$raw     = $options['file_optimisation']['ccssInlineBudgetKb'] ?? 14;
				$kb      = function_exists( 'absint' ) ? absint( $raw ) : abs( (int) $raw );
				if ( $kb < 1 || $kb > 100 ) {
					$kb = 14;
				}
				$budget = $kb * 1024;
				if ( function_exists( 'apply_filters' ) && function_exists( 'has_filter' ) && has_filter( 'wppo_ccss_inline_budget' ) ) {
					$filtered = apply_filters( 'wppo_ccss_inline_budget', $budget );
					if ( is_numeric( $filtered ) && (int) $filtered >= 1 ) {
						$budget = (int) $filtered;
					}
				}
				return min( max( $budget, self::MIN_CCSS_INLINE_BUDGET_BYTES ), self::MAX_CCSS_INLINE_BUDGET_BYTES );
			} catch ( \Throwable $e ) {
				unset( $e );
				return self::DEFAULT_CCSS_INLINE_BUDGET_BYTES;
			}
		}

		/**
		 * Whether a non-forced full regeneration is inside the cooldown window (issue #1462).
		 *
		 * Mirrors Used_CSS::is_full_regen_cooled_down(). Fail-open: an
		 * unreadable timestamp never blocks work.
		 *
		 * @return bool True when the last full regen is newer than the cooldown.
		 * @since 2.3.0 Moved verbatim from Critical_CSS (P3-018); original tag retained.
		 */
		public static function is_full_regen_cooled_down(): bool {
			try {
				if ( ! function_exists( 'get_option' ) ) {
					return false;
				}
				$last = (int) get_option( self::LAST_FULL_REGEN_OPTION, 0 );
				if ( $last <= 0 ) {
					return false;
				}
				return ( time() - $last ) < self::get_full_regen_cooldown();
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Record a completed full-regeneration scan (issue #1462).
		 *
		 * Written whenever regenerate_all() performs a scan so repeat callers
		 * hit the cooldown instead of re-scanning. The stamp is written
		 * unconditionally — even when zero jobs were queued (empty template
		 * set, all skipped, scheduler unavailable) — matching the Used_CSS
		 * pipeline's documented choice: a scan happened, so the next caller
		 * waits out the window instead of re-scanning in a tight loop.
		 * Fail-open.
		 *
		 * @return void
		 * @since 2.3.0 Moved verbatim from Critical_CSS (P3-018); original tag retained.
		 */
		public static function mark_full_regen(): void {
			try {
				if ( function_exists( 'update_option' ) ) {
					update_option( self::LAST_FULL_REGEN_OPTION, time(), false );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Effective targeted-regeneration cooldown in seconds (issue #1462).
		 *
		 * Filterable via `wppo_ccss_targeted_cooldown` when a listener is
		 * registered (repo convention: has_filter() before apply_filters());
		 * invalid filter output is ignored and negative values heal to the
		 * default so a rogue filter can never corrupt the throttle. A zero
		 * value is honoured (throttle disabled) — unlike the full-regen
		 * cooldown, which must never be disabled.
		 *
		 * @return int Cooldown in seconds (>= 0).
		 * @since 2.3.0 Moved verbatim from Critical_CSS (P3-018); original tag retained.
		 */
		public static function get_targeted_regen_cooldown(): int {
			try {
				$cooldown = self::TARGETED_REGEN_COOLDOWN_SECONDS;
				if ( function_exists( 'apply_filters' ) && function_exists( 'has_filter' ) && has_filter( 'wppo_ccss_targeted_cooldown' ) ) {
					/**
					 * Filter the critical-CSS targeted-regeneration cooldown (issue #1462).
					 *
					 * Bounds how often builder/theme updates may queue bounded
					 * targeted requeues. Return 0 to disable the throttle.
					 *
					 * @since 2.3.0
					 *
					 * @param int $cooldown Cooldown in seconds. Default 3600.
					 */
					$filtered = apply_filters( 'wppo_ccss_targeted_cooldown', $cooldown );
					if ( is_numeric( $filtered ) && (int) $filtered >= 0 ) {
						$cooldown = (int) $filtered;
					}
				}
				return $cooldown >= 0 ? (int) $cooldown : self::TARGETED_REGEN_COOLDOWN_SECONDS;
			} catch ( \Throwable $e ) {
				unset( $e );
				return self::TARGETED_REGEN_COOLDOWN_SECONDS;
			}
		}

		/**
		 * Whether a targeted regeneration is inside the throttle window (issue #1462).
		 *
		 * Mirrors Used_CSS::is_targeted_regen_cooled_down(). Fail-open: an
		 * unreadable timestamp never blocks work.
		 *
		 * @return bool True when the last targeted regen is newer than the cooldown.
		 * @since 2.3.0 Moved verbatim from Critical_CSS (P3-018); original tag retained.
		 */
		public static function is_targeted_regen_cooled_down(): bool {
			try {
				if ( ! function_exists( 'get_option' ) ) {
					return false;
				}
				$last = (int) get_option( self::TARGETED_REGEN_OPTION, 0 );
				if ( $last <= 0 ) {
					return false;
				}
				return ( time() - $last ) < self::get_targeted_regen_cooldown();
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Record a targeted-regeneration pass (issue #1462).
		 *
		 * Written only when request_targeted_regen() queued at least one job,
		 * so an empty pass (all skipped, scheduler unavailable) never starts
		 * the throttle window. Fail-open.
		 *
		 * @return void
		 * @since 2.3.0 Moved verbatim from Critical_CSS (P3-018); original tag retained.
		 */
		public static function mark_targeted_regen(): void {
			try {
				if ( function_exists( 'update_option' ) ) {
					update_option( self::TARGETED_REGEN_OPTION, time(), false );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Bytes already committed to inline output on this request (issue #1462).
		 *
		 * Coordination point between the per-URL used-CSS pipeline and the
		 * per-template critical-CSS pipeline: critical CSS gets the remainder
		 * of the core inline budget after prior commits. Sources, in priority
		 * order: the request-global Util ledger (inline_ccss() records every
		 * block it inlines; future inline emitters record via
		 * Util::add_committed_inline_bytes()), then the
		 * `wppo_committed_inline_bytes` filter when a listener is registered
		 * (wins over the ledger so operators can account for bytes committed
		 * outside the plugin). Defaults to 0 — used CSS ships as an external
		 * file in every delivery mode (file/async/delay/remove, never inline),
		 * so nothing is committed in the default configuration.
		 *
		 * @return int Committed inline bytes (>= 0).
		 * @since 2.3.0 Moved verbatim from Critical_CSS (P3-018); original tag retained.
		 */
		public static function estimate_committed_inline_bytes(): int {
			try {
				$committed = 0;
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_committed_inline_bytes' ) ) {
					try {
						$committed = max( 0, (int) Util::get_committed_inline_bytes() );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( function_exists( 'apply_filters' ) && function_exists( 'has_filter' ) && has_filter( 'wppo_committed_inline_bytes' ) ) {
					$filtered = apply_filters( 'wppo_committed_inline_bytes', $committed );
					if ( is_numeric( $filtered ) && (int) $filtered > 0 ) {
						$committed = (int) $filtered;
					}
				}
				return $committed > 0 ? (int) $committed : 0;
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		/**
		 * Effective inline budget for critical CSS after prior commits (issue #1462).
		 *
		 * The combined inlined CSS (used CSS already committed + critical CSS)
		 * stays within core's `styles_inline_size_limit` (40KB on WP 6.9+,
		 * 20KB before) and the configured `ccssMaxSize` cap: the tighter of
		 * the two wins, minus already-committed bytes, clamped at zero.
		 * A non-positive core limit (rogue `styles_inline_size_limit` filter)
		 * heals to the unfiltered default via Util::get_styles_inline_default()
		 * so this path agrees with split_css_for_inline_budget() and
		 * get_remaining_inline_budget(). Fail-open: any uncertainty yields 0
		 * (caller serves the file variant), never fatal.
		 *
		 * @param int $already_inlined Bytes already committed to inline output.
		 * @return int Effective budget in bytes (>= 0).
		 * @since 2.3.0 Moved verbatim from Critical_CSS (P3-018); original tag retained.
		 */
		public static function get_effective_ccss_budget( int $already_inlined = 0 ): int {
			try {
				$cap   = self::get_ccss_max_size();
				$limit = self::get_styles_inline_limit();
				if ( $limit <= 0 && class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_styles_inline_default' ) ) {
					$limit = Util::get_styles_inline_default();
				}
				if ( $limit <= 0 ) {
					$limit = 40000;
				}
				$budget = min( $cap, $limit ) - max( 0, $already_inlined );
				return $budget > 0 ? (int) $budget : 0;
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		/**
		 * Coordinate used CSS and critical CSS within one inline budget (issue #1462).
		 *
		 * Used CSS (per-URL) is committed first; critical CSS (per-template)
		 * is split so the combined inline output fits core's
		 * `styles_inline_size_limit`. Called live by inline_ccss(): when the
		 * split reports a deferred remainder, inline_ccss() serves the whole
		 * per-template file variant (the cacheable repeat-visit asset) instead
		 * of inlining a truncated prefix — so the `deferred` substring is a
		 * fit signal for the caller, not a second asset that is enqueued.
		 * Fail-open: any uncertainty returns the inputs unmodified for the
		 * caller to handle with its file fallback.
		 *
		 * @param string     $ccss Critical CSS content for the current template.
		 * @param string|int $used_css Used-CSS content already committed inline ('' when file-delivered),
		 *                             or a byte count to avoid materialising a stub string.
		 * @return array{inline: string, deferred: string} Budget-aware CCSS split.
		 * @since 2.3.0 Moved verbatim from Critical_CSS (P3-018); original tag retained.
		 */
		public static function coordinate_inline_budgets( string $ccss, string|int $used_css = '' ): array {
			try {
				if ( '' === $ccss ) {
					return array(
						'inline'   => '',
						'deferred' => '',
					);
				}
				$used_len = is_int( $used_css ) ? max( 0, $used_css ) : strlen( $used_css );
				$budget   = self::get_effective_ccss_budget( $used_len );
				if ( strlen( $ccss ) <= $budget ) {
					return array(
						'inline'   => $ccss,
						'deferred' => '',
					);
				}
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'split_css_for_inline_budget' ) ) {
					return Util::split_css_for_inline_budget( $ccss, $budget );
				}
				$truncated = self::truncate_to_cap( $ccss, $budget );
				if ( '' === $truncated ) {
					return array(
						'inline'   => '',
						'deferred' => $ccss,
					);
				}
				return array(
					'inline'   => $truncated,
					'deferred' => substr( $ccss, strlen( $truncated ) ),
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return array(
					'inline'   => $ccss,
					'deferred' => '',
				);
			}
		}

		/**
		 * Measure the gzipped transfer size of CSS output (issue #1388).
		 *
		 * Uses gzencode() level 9 when available so the budget check tracks
		 * what the browser actually downloads; falls back to raw strlen()
		 * when zlib is unavailable (fail-open, never fatal). Results are
		 * memoized per content hash per request (bounded, 20 entries) so
		 * repeated budget probes on the frontend hot path pay compression once.
		 *
		 * @param string $css CSS content.
		 * @return int Gzipped size in bytes, or raw size without zlib.
		 * @since 2.3.0 Moved verbatim from Critical_CSS (P3-018); original tag retained.
		 */
		public static function gzipped_size( string $css ): int {
			if ( '' === $css ) {
				return 0;
			}
			try {
				$memo_key = md5( $css );
			} catch ( \Throwable $e ) {
				unset( $e );
				$memo_key = '';
			}
			if ( '' !== $memo_key && array_key_exists( $memo_key, self::$gzip_size_memo ) ) {
				return self::$gzip_size_memo[ $memo_key ];
			}
			$size = strlen( $css );
			try {
				if ( function_exists( 'gzencode' ) ) {
					$encoded = gzencode( $css, 9 );
					if ( is_string( $encoded ) && '' !== $encoded ) {
						$size = strlen( $encoded );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			if ( '' !== $memo_key ) {
				if ( count( self::$gzip_size_memo ) >= 20 ) {
					array_shift( self::$gzip_size_memo );
				}
				self::$gzip_size_memo[ $memo_key ] = $size;
			}
			return $size;
		}

		/**
		 * Whether CSS output exceeds the gzipped inline budget (issue #1388).
		 *
		 * Fail-open: any failure reports over-budget (never inline unbounded
		 * output), except empty input which is never over budget.
		 *
		 * @param string $css CSS content.
		 * @return bool True when the gzipped size exceeds the inline budget.
		 * @since 2.3.0 Moved verbatim from Critical_CSS (P3-018); original tag retained.
		 */
		public static function is_over_inline_budget( string $css ): bool {
			if ( '' === $css ) {
				return false;
			}
			try {
				return self::gzipped_size( $css ) > self::get_ccss_inline_budget_bytes();
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Human-readable label for the configured gzipped inline budget (issue #1388 review).
		 *
		 * Derived from get_ccss_inline_budget_bytes() so admin notices and
		 * activity-log warnings never hardcode "14 KB" while the budget is
		 * configurable (1–100 KB + wppo_ccss_inline_budget filter).
		 *
		 * @return string e.g. "14 KB" or "2.5 KB".
		 * @since 2.3.0 Moved verbatim from Critical_CSS (P3-018); original tag retained.
		 */
		public static function get_ccss_inline_budget_label(): string {
			try {
				$bytes = self::get_ccss_inline_budget_bytes();
				if ( 0 === ( $bytes % 1024 ) ) {
					return sprintf( '%d KB', (int) ( $bytes / 1024 ) );
				}
				$kb = $bytes / 1024;
				return sprintf( '%.1f KB', $kb );
			} catch ( \Throwable $e ) {
				unset( $e );
				return '14 KB';
			}
		}
	}
}
