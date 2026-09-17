<?php
/**
 * Optimization Detective (OD) bridge.
 *
 * Consumes viewport groups (mobile/desktop LCP tag) from the Optimization
 * Detective plugin (Performance Lab) to set fetchpriority=high for the LCP
 * image and to derive the lazy-load threshold excludeFirstImages from
 * measured data. Degrades gracefully to a heuristic 1-3 when OD is absent.
 *
 * Gated by class_exists('OD_URL_Metric') or function_exists('od_get_url_metrics')
 * and by the wppo_od_enabled setting (auto true when OD active, false else).
 * No hard dependency on OD — pure class_exists / function_exists guards.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\OD_Bridge' ) ) {
	/**
	 * Optimization Detective bridge.
	 *
	 * @since 2.0.0
	 */
	class OD_Bridge {

		/**
		 * Settings key for the OD integration toggle.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		const SETTINGS_KEY = 'od_integration';

		/**
		 * Filter to control whether OD optimization should be applied.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		const FILTER_SHOULD_OPTIMIZE = 'wppo_od_should_optimize';

		/**
		 * Per-request memo for OD lookups keyed by current URL.
		 *
		 * One page can resolve OD metrics up to 4-6x (preload, lazy
		 * exclusion, hero stamp, fetchpriority filter); each scan fires
		 * the `wppo_od_should_optimize` filter + `od_get_url_metrics()` +
		 * normalize. This memo keeps repeated lookups on the same URL to
		 * a single scan per request. Keys are `raw:`, `lcp:`, and
		 * `stable:` prefixed current URLs. Bounded (reset past 30
		 * entries); reset with {@see clear_request_memo()} (also wired
		 * into `Image_Optimisation::clear_runtime_caches()` so the shared
		 * PHPUnit bootstrap reset covers it). In-memory only,
		 * multisite-safe by construction.
		 *
		 * @since NEXT
		 * @var array<string, mixed>
		 */
		private static array $request_memo = array();

		/**
		 * Reset the per-request OD memo.
		 *
		 * Called between pages in long-lived processes and in tests (via
		 * `Image_Optimisation::clear_runtime_caches()`).
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function clear_request_memo(): void {
			self::$request_memo = array();
		}

		/**
		 * Memo key for the current URL with the given prefix.
		 *
		 * Fail-open to the bare prefix when the URL is unresolvable.
		 *
		 * @since NEXT
		 * @param string $prefix Memo namespace prefix.
		 * @return string Memo key.
		 */
		private static function request_memo_key( string $prefix ): string {
			$url = '';
			try {
				if ( method_exists( Util::class, 'get_current_url' ) ) {
					$url = Util::get_current_url();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				$url = '';
			}
			return $prefix . (string) $url;
		}

		/**
		 * Store a memo value, bounding the map.
		 *
		 * @since NEXT
		 * @param string $key   Memo key.
		 * @param mixed  $value Memo value.
		 * @return void
		 */
		private static function request_memo_set( string $key, $value ): void {
			self::$request_memo[ $key ] = $value;
			if ( count( self::$request_memo ) > 30 ) {
				self::$request_memo = array();
			}
		}

		/**
		 * Log a Throwable diagnostic to the server error log when WP_DEBUG is on.
		 *
		 * Centralizes the bridge's debug diagnostics so the WP_DEBUG gate and the
		 * error_log ignore live in one place. These messages never reach the
		 * activity log (Log::add()) because they are low-level exception output.
		 *
		 * @since 2.0.0
		 * @param string $message Message to log.
		 * @return void
		 */
		private static function debug_log( string $message ): void {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only diagnostics, gated above.
				error_log( $message );
			}
		}

		/**
		 * Whether the Optimization Detective plugin is available.
		 *
		 * Checks for the Lab 6.9 class OD_URL_Metric or the helper
		 * function od_get_url_metrics(). No autoload is triggered beyond
		 * the class_exists check (autoload true by default).
		 *
		 * @since 2.0.0
		 * @return bool True when OD is active.
		 */
		public static function is_od_available(): bool {
			return class_exists( 'OD_URL_Metric' ) || function_exists( 'od_get_url_metrics' );
		}

		/**
		 * Whether OD optimization is enabled for the current request.
		 *
		 * Reads wppo_settings[od_integration][enabled] with an auto fallback
		 * (true when OD is active, false otherwise) and applies the
		 * wppo_od_should_optimize filter.
		 *
		 * @since 2.0.0
		 * @return bool True when OD data should be consumed.
		 */
		public static function is_enabled(): bool {
			if ( ! self::is_od_available() ) {
				return false;
			}

			$settings = Util::get_settings();
			$enabled  = null;
			if ( isset( $settings[ self::SETTINGS_KEY ] ) && is_array( $settings[ self::SETTINGS_KEY ] ) && isset( $settings[ self::SETTINGS_KEY ]['enabled'] ) ) {
				$enabled = (bool) $settings[ self::SETTINGS_KEY ]['enabled'];
			} elseif ( isset( $settings['wppo_od_enabled'] ) ) {
				// Legacy flat key.
				$enabled = (bool) $settings['wppo_od_enabled'];
			} else {
				// Auto: true when OD is active, false else. This mirrors the
				// in-memory default applied in Main::__construct() without
				// requiring a DB write on first load.
				$enabled = true;
			}

			$current_url = method_exists( Util::class, 'get_current_url' ) ? Util::get_current_url() : Util::cached_home_url();

			// Per-request memo so the filter fires once per URL per
			// settings state instead of 4-6x per page. Keyed by URL +
			// settings fingerprint so mid-request settings changes still
			// re-evaluate.
			$settings_fingerprint = '';
			try {
				$od_slice = array( $settings[ self::SETTINGS_KEY ] ?? null, $settings['wppo_od_enabled'] ?? null );
				if ( function_exists( 'wp_json_encode' ) ) {
					$encoded = wp_json_encode( $od_slice );
				} else {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fallback when wp_json_encode() is unavailable (unit contexts).
					$encoded = json_encode( $od_slice );
				}
				if ( is_string( $encoded ) ) {
					$settings_fingerprint = md5( $encoded );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			$enabled_key = 'enabled:' . (string) $current_url . ':' . $settings_fingerprint;
			if ( array_key_exists( $enabled_key, self::$request_memo ) && is_bool( self::$request_memo[ $enabled_key ] ) ) {
				return self::$request_memo[ $enabled_key ];
			}

			/**
			 * Filters whether OD-based optimization should be applied.
			 *
			 * @since 2.0.0
			 * @param bool   $should      Whether to optimize.
			 * @param string $current_url Current URL (if resolvable).
			 */
			$should = (bool) apply_filters( self::FILTER_SHOULD_OPTIMIZE, $enabled, $current_url );

			$result = $should && $enabled;
			self::request_memo_set( $enabled_key, $result );
			return $result;
		}

		/**
		 * Get the LCP image URL from OD viewport groups.
		 *
		 * Tries viewport groups (mobile/desktop) first: collects LCP tags
		 * per group and returns the most representative URL. Falls back to
		 * a flat metric scan. Returns empty string when no OD data is
		 * available or optimization is disabled.
		 *
		 * @since 2.0.0
		 * @return string LCP image URL or empty string.
		 */
		public static function get_lcp_url(): string {
			if ( ! self::is_enabled() ) {
				return '';
			}

			$memo_key = self::request_memo_key( 'lcp:' );
			if ( array_key_exists( $memo_key, self::$request_memo ) && is_string( self::$request_memo[ $memo_key ] ) ) {
				return self::$request_memo[ $memo_key ];
			}

			$raw_urls = self::collect_raw_lcp_urls();
			if ( empty( $raw_urls ) ) {
				self::request_memo_set( $memo_key, '' );
				return '';
			}

			// Normalize for counting so http/https and size-suffix variants
			// collapse to the same LCP. Keep original URL for return value
			// but count via normalized form.
			$normalized = array();
			foreach ( $raw_urls as $u ) {
				$norm         = Util::normalize_url( $u );
				$normalized[] = '' !== $norm ? $norm : trim( (string) $u );
			}

			$counts = array_count_values( $normalized );
			if ( empty( $counts ) ) {
				return (string) $raw_urls[0];
			}

			$max = max( $counts );
			// Tie-break: earliest in original order among those with max count.
			$winner_url = (string) $raw_urls[0];
			foreach ( $normalized as $idx => $norm ) {
				if ( ( $counts[ $norm ] ?? 0 ) === $max ) {
					$winner_url = (string) $raw_urls[ $idx ];
					break;
				}
			}
			self::request_memo_set( $memo_key, $winner_url );

			return $winner_url;
		}

		/**
		 * Get the stable LCP image URL from OD viewport groups.
		 *
		 * Stability-gated twin of {@see get_lcp_url()}: the most-common
		 * normalized URL wins only when at least two real-visit observations
		 * agree on it (mobile + desktop viewport groups), mirroring the
		 * RUM sample-count gate. A single observation (one viewport group
		 * measured so far) is accepted as stable — it is still real-visit
		 * field data, and rejecting it would stall optimisation on
		 * single-viewport pages. OD metrics describe the current URL, so
		 * they are inherently fresh (no TTL needed). Returns an empty
		 * string when disabled, when nothing is measured, or when two
		 * viewport groups disagree (no stable winner). Fail-open: any
		 * failure returns ''.
		 *
		 * @since NEXT
		 * @return string Stable LCP image URL or empty string.
		 */
		public static function get_stable_lcp_url(): string {
			if ( ! self::is_enabled() ) {
				return '';
			}

			$memo_key = self::request_memo_key( 'stable:' );
			if ( array_key_exists( $memo_key, self::$request_memo ) && is_string( self::$request_memo[ $memo_key ] ) ) {
				return self::$request_memo[ $memo_key ];
			}

			try {
				$raw_urls = self::collect_raw_lcp_urls();
				if ( empty( $raw_urls ) ) {
					self::request_memo_set( $memo_key, '' );
					return '';
				}

				if ( 1 === count( $raw_urls ) ) {
					$single = (string) $raw_urls[0];
					self::request_memo_set( $memo_key, $single );
					return $single;
				}

				$normalized = array();
				foreach ( $raw_urls as $u ) {
					$norm         = Util::normalize_url( $u );
					$normalized[] = '' !== $norm ? $norm : trim( (string) $u );
				}

				$counts = array_count_values( $normalized );
				if ( empty( $counts ) ) {
					self::request_memo_set( $memo_key, '' );
					return '';
				}

				$max = max( $counts );
				if ( $max < 2 ) {
					self::request_memo_set( $memo_key, '' );
					return '';
				}

				// Unique-winner gate: a tied vote (e.g. mobile vs desktop
				// heroes at 2-2) has no stable winner, so fail open to ''.
				$winners = array_keys(
					array_filter(
						$counts,
						static function ( $c ) use ( $max ) {
							return $c === $max;
						}
					)
				);
				if ( 1 !== count( $winners ) ) {
					self::request_memo_set( $memo_key, '' );
					return '';
				}
				$winner = (string) $winners[0];
				foreach ( $normalized as $idx => $norm ) {
					if ( $norm === $winner ) {
						$winner_url = (string) $raw_urls[ $idx ];
						self::request_memo_set( $memo_key, $winner_url );
						return $winner_url;
					}
				}
			} catch ( \Throwable $e ) {
				self::debug_log( 'WPPO OD bridge stable LCP error: ' . $e->getMessage() );
			}

			self::request_memo_set( $memo_key, '' );
			return '';
		}

		/**
		 * Get the lazy-load threshold (excludeFirstImages) from OD data.
		 *
		 * When OD measured data is available, derives the count from the
		 * number of distinct LCP viewport groups (1 for single-viewport
		 * LCP, 2 when mobile/desktop differ, capped 1-3). Otherwise
		 * degrades to a heuristic 1-3 based on the stored setting or a
		 * static 2 default.
		 *
		 * @since 2.0.0
		 * @return int Number of first images to exclude, 1-3.
		 */
		public static function get_exclude_first_images_count(): int {
			if ( self::is_enabled() ) {
				$lcp_urls = self::collect_lcp_urls();
				if ( ! empty( $lcp_urls ) ) {
					// Distinct LCP URLs correspond to viewport groups with
					// different LCP tags (e.g. mobile vs desktop hero).
					$count = count( array_unique( $lcp_urls ) );
					// Clamp to 1-3 per spec.
					$count = max( 1, min( 3, $count ) );
					return $count;
				}

				// OD enabled but no LCP tag yet (e.g. first visit) — try
				// viewport group count as a proxy for above-the-fold images.
				$group_count = self::count_viewport_groups();
				if ( $group_count > 0 ) {
					return max( 1, min( 3, $group_count ) );
				}
			}

			// Heuristic fallback 1-3. Same precedence as
			// Image_Optimisation::get_effective_exclude_first_images_count():
			// additive `lcp_first_n` wins over legacy `excludeFirstImages`.
			$settings = Util::get_settings();
			$stored   = null;
			if ( isset( $settings['image_optimisation']['lcp_first_n'] ) ) {
				$stored = (int) $settings['image_optimisation']['lcp_first_n'];
			} elseif ( isset( $settings['image_optimisation']['excludeFirstImages'] ) ) {
				$stored = (int) $settings['image_optimisation']['excludeFirstImages'];
			}

			if ( null === $stored ) {
				// No stored value — heuristic middle.
				return 2;
			}

			// Clamp stored heuristic to 1-3; a stored 0 (disable) becomes 1
			// so the heuristic always excludes at least the hero.
			if ( $stored <= 0 ) {
				return 1;
			}
			if ( $stored > 3 ) {
				return 3;
			}
			return $stored;
		}

		/**
		 * Collect distinct LCP URLs from OD metrics.
		 *
		 * Handles multiple OD API shapes:
		 * - function od_get_url_metrics() returning OD_URL_Metric objects
		 * - OD_URL_Metric_Group_Collection::get_groups() viewport groups
		 * - OD_URL_Metric::get_lcp_element() / get_elements() with isLCP flag
		 * - Array-shaped metrics with ['elements'] and ['isLCP'] / ['xpath']
		 *
		 * @since 2.0.0
		 * @return string[] Distinct LCP image URLs.
		 */
		private static function collect_lcp_urls(): array {
			$urls = self::collect_raw_lcp_urls();
			// Deduplicate while preserving order (mobile-first).
			$urls = array_values( array_unique( array_filter( $urls ) ) );
			return $urls;
		}

		/**
		 * Collect raw (non-deduplicated) LCP URLs from OD metrics.
		 *
		 * Preserves duplicates so callers can compute most-common frequency
		 * across viewport groups. Uses the same extraction logic as
		 * collect_lcp_urls() but returns filtered list without uniquing.
		 *
		 * @since 2.0.0
		 * @return string[] Raw LCP image URLs (may contain duplicates).
		 */
		private static function collect_raw_lcp_urls(): array {
			// Share one scan per URL per request: resolve_auto_lcp_url()
			// tiers (P0 OD-only, P1 stored, P1b stable signal) each collect
			// the same snapshot on a cold page, so memoize the raw list.
			$memo_key = self::request_memo_key( 'raw:' );
			if ( array_key_exists( $memo_key, self::$request_memo ) && is_array( self::$request_memo[ $memo_key ] ) ) {
				return self::$request_memo[ $memo_key ];
			}
			$metrics = self::get_url_metrics();
			if ( empty( $metrics ) ) {
				return array();
			}

			$urls = array();

			foreach ( $metrics as $metric ) {
				// Try object APIs first.
				if ( is_object( $metric ) ) {
					// Modern OD: get_lcp_element() returns element array or object.
					if ( method_exists( $metric, 'get_lcp_element' ) ) {
						try {
							$el  = $metric->get_lcp_element();
							$url = self::extract_url_from_element( $el );
							if ( '' !== $url ) {
								$urls[] = $url;
								continue;
							}
						} catch ( \Throwable $e ) {
							self::debug_log( 'WPPO OD bridge get_lcp_element error: ' . $e->getMessage() );
						}
					}

					// Fallback: get_elements() + isLCP flag.
					if ( method_exists( $metric, 'get_elements' ) ) {
						try {
							$elements = $metric->get_elements();
							if ( is_array( $elements ) ) {
								foreach ( $elements as $el ) {
									if ( self::element_is_lcp( $el ) ) {
										$url = self::extract_url_from_element( $el );
										if ( '' !== $url ) {
											$urls[] = $url;
										}
									}
								}
								continue;
							}
						} catch ( \Throwable $e ) {
							self::debug_log( 'WPPO OD bridge get_elements error: ' . $e->getMessage() );
						}
					}

					// ArrayAccess or property fallback: $metric->get_url() may be LCP itself if single element.
					if ( method_exists( $metric, 'get_url' ) && method_exists( $metric, 'get_xpath' ) ) {
						// OD element itself.
						if ( self::element_is_lcp( $metric ) ) {
							$url = self::extract_url_from_element( $metric );
							if ( '' !== $url ) {
								$urls[] = $url;
							}
						}
						continue;
					}

					// Generic object with properties.
					$url = self::extract_url_from_element( $metric );
					if ( '' !== $url && self::element_is_lcp( $metric ) ) {
						$urls[] = $url;
					}
					continue;
				}

				if ( is_array( $metric ) ) {
					// Array shape: ['elements' => [...]] or direct LCP element.
					if ( isset( $metric['elements'] ) && is_array( $metric['elements'] ) ) {
						foreach ( $metric['elements'] as $el ) {
							if ( self::element_is_lcp( $el ) ) {
								$url = self::extract_url_from_element( $el );
								if ( '' !== $url ) {
									$urls[] = $url;
								}
							}
						}
						continue;
					}

					if ( self::element_is_lcp( $metric ) ) {
						$url = self::extract_url_from_element( $metric );
						if ( '' !== $url ) {
							$urls[] = $url;
						}
					}
				}
			}

			$filtered = array();
			foreach ( $urls as $url ) {
				if ( ! is_string( $url ) || '' === $url ) {
					continue;
				}
				// Reject absolute URLs with non-http(s) schemes; relative
				// URLs (no scheme) stay eligible for same-site resolution.
				$scheme = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_SCHEME ) : parse_url( $url, PHP_URL_SCHEME ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback when wp_parse_url() is unavailable.
				if ( is_string( $scheme ) && '' !== $scheme && ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ) {
					continue;
				}
				$filtered[] = $url;
			}

			$result = array_values( array_filter( $filtered ) );
			self::request_memo_set( $memo_key, $result );
			return $result;
		}

		/**
		 * Count viewport groups from OD data.
		 *
		 * Used as a proxy for above-the-fold image count when LCP tag
		 * is not yet available but groups are.
		 *
		 * @since 2.0.0
		 * @return int Number of viewport groups (0 when unavailable).
		 */
		private static function count_viewport_groups(): int {
			// Try OD_URL_Metric_Group_Collection first.
			if ( class_exists( 'OD_URL_Metric_Group_Collection' ) ) {
				try {
					if ( method_exists( 'OD_URL_Metric_Group_Collection', 'get_group_count' ) ) {
						$count = \OD_URL_Metric_Group_Collection::get_group_count();
						if ( is_int( $count ) ) {
							return $count;
						}
					}
					if ( method_exists( 'OD_URL_Metric_Group_Collection', 'get_groups' ) ) {
						$groups = \OD_URL_Metric_Group_Collection::get_groups();
						if ( is_array( $groups ) ) {
							return count( $groups );
						}
					}
					if ( method_exists( 'OD_URL_Metric_Group_Collection', 'get_groups_by_lcp_element' ) ) {
						$groups = \OD_URL_Metric_Group_Collection::get_groups_by_lcp_element();
						if ( is_array( $groups ) ) {
							return count( $groups );
						}
					}
				} catch ( \Throwable $e ) {
					self::debug_log( 'WPPO OD bridge error: ' . $e->getMessage() );
				}
			}

			if ( class_exists( 'OD_URL_Metric_Group' ) ) {
				try {
					if ( method_exists( 'OD_URL_Metric_Group', 'get_groups' ) ) {
						$groups = \OD_URL_Metric_Group::get_groups();
						if ( is_array( $groups ) ) {
							return count( $groups );
						}
					}
				} catch ( \Throwable $e ) {
					self::debug_log( 'WPPO OD bridge error: ' . $e->getMessage() );
				}
			}

			// Fallback: count distinct viewport widths in metrics.
			$metrics = self::get_url_metrics();
			if ( empty( $metrics ) ) {
				return 0;
			}

			$widths = array();
			foreach ( $metrics as $metric ) {
				if ( is_object( $metric ) && method_exists( $metric, 'get_viewport_width' ) ) {
					try {
						$w = $metric->get_viewport_width();
						if ( is_int( $w ) && $w > 0 ) {
							$widths[] = $w;
						}
					} catch ( \Throwable $e ) {
						self::debug_log( 'WPPO OD bridge error: ' . $e->getMessage() );
					}
				} elseif ( is_array( $metric ) && isset( $metric['viewportWidth'] ) ) {
					$widths[] = (int) $metric['viewportWidth'];
				} elseif ( is_array( $metric ) && isset( $metric['viewport_width'] ) ) {
					$widths[] = (int) $metric['viewport_width'];
				}
			}

			if ( empty( $widths ) ) {
				// If metrics exist but no width info, assume at least 1 group.
				return 1;
			}

			// Bucket widths into mobile/desktop groups (<=768 vs >768).
			$groups = array();
			foreach ( $widths as $w ) {
				$bucket            = $w <= 768 ? 'mobile' : 'desktop';
				$groups[ $bucket ] = true;
			}

			return count( $groups );
		}

		/**
		 * Get normalized occluded image URLs for the current URL.
		 *
		 * Reads per-element occlusion/visibility signals from the real-visit
		 * OD URL metrics and returns the normalized image URLs of elements
		 * reported as occluded (CSS-hidden but in-viewport, e.g. hidden
		 * carousel slides). Per-URL only (multisite-safe by construction),
		 * memoized per request alongside `$request_memo` and bounded.
		 * The stable-LCP winner is never reported as occluded. Fail-open:
		 * disabled bridge, missing OD API, no metrics, or any failure
		 * returns an empty array (callers leave markup unchanged).
		 *
		 * @since NEXT
		 * @return string[] Normalized occluded image URLs (may be empty).
		 */
		public static function get_occluded_image_urls(): array {
			try {
				if ( ! self::is_enabled() ) {
					return array();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}

			$memo_key = self::request_memo_key( 'occluded:' );
			if ( array_key_exists( $memo_key, self::$request_memo ) && is_array( self::$request_memo[ $memo_key ] ) ) {
				return self::$request_memo[ $memo_key ];
			}

			try {
				$metrics = self::get_url_metrics();
				if ( empty( $metrics ) ) {
					self::request_memo_set( $memo_key, array() );
					return array();
				}

				$elements = array();
				foreach ( $metrics as $metric ) {
					if ( is_object( $metric ) && method_exists( $metric, 'get_elements' ) ) {
						try {
							$els = $metric->get_elements();
							if ( is_array( $els ) ) {
								foreach ( $els as $el ) {
									$elements[] = $el;
								}
								continue;
							}
						} catch ( \Throwable $e ) {
							self::debug_log( 'WPPO OD bridge get_elements error: ' . $e->getMessage() );
						}
					}
					if ( is_array( $metric ) && isset( $metric['elements'] ) && is_array( $metric['elements'] ) ) {
						foreach ( $metric['elements'] as $el ) {
							$elements[] = $el;
						}
						continue;
					}
					$elements[] = $metric;
				}

				if ( empty( $elements ) ) {
					self::request_memo_set( $memo_key, array() );
					return array();
				}

				$lcp_norms = array();
				try {
					$raw_lcp = self::collect_raw_lcp_urls();
					foreach ( $raw_lcp as $u ) {
						$norm = Util::normalize_url( $u );
						if ( '' !== $norm ) {
							$lcp_norms[ $norm ] = true;
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}

				$urls = array();
				foreach ( $elements as $el ) {
					try {
						if ( self::element_is_lcp( $el ) ) {
							continue;
						}
						if ( ! self::element_is_occluded( $el ) ) {
							continue;
						}
						$url = self::extract_url_from_element( $el );
						if ( ! is_string( $url ) || '' === $url ) {
							continue;
						}
						$scheme = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_SCHEME ) : parse_url( $url, PHP_URL_SCHEME ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback when wp_parse_url() is unavailable.
						if ( is_string( $scheme ) && '' !== $scheme && ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ) {
							continue;
						}
						$norm = Util::normalize_url( $url );
						if ( '' === $norm ) {
							continue;
						}
						if ( isset( $lcp_norms[ $norm ] ) ) {
							continue;
						}
						$urls[ $norm ] = $url;
					} catch ( \Throwable $e ) {
						unset( $e );
						continue;
					}
				}

				$result = array_values( $urls );
				self::request_memo_set( $memo_key, $result );
				return $result;
			} catch ( \Throwable $e ) {
				self::debug_log( 'WPPO OD bridge occluded error: ' . $e->getMessage() );
				return array();
			}
		}

		/**
		 * Whether an OD element is occluded (hidden but in-viewport).
		 *
		 * Covers object methods (`is_occluded()`, `isOccluded()`,
		 * `is_visible()`/`isVisible()` false, `is_hidden()`/`isHidden()`
		 * true), matching properties/array keys (including
		 * `isHiddenElement`), plus a zero-area-rect geometry fallback.
		 * A zero `intersectionRatio` alone is not occlusion (it also
		 * matches ordinary below-the-fold nodes). The LCP element itself is
		 * never occluded (callers skip LCP first). Fail-open to false.
		 *
		 * @since NEXT
		 * @param mixed $element Element object or array.
		 * @return bool True when occluded.
		 */
		private static function element_is_occluded( $element ): bool {
			try {
				if ( is_object( $element ) ) {
					foreach ( array( 'is_occluded', 'isOccluded' ) as $method ) {
						if ( method_exists( $element, $method ) ) {
							try {
								if ( (bool) $element->$method() ) {
									return true;
								}
							} catch ( \Throwable $e ) {
								unset( $e );
							}
						}
					}
					foreach ( array( 'is_visible', 'isVisible' ) as $method ) {
						if ( method_exists( $element, $method ) ) {
							try {
								if ( false === (bool) $element->$method() ) {
									return true;
								}
							} catch ( \Throwable $e ) {
								unset( $e );
							}
						}
					}
					foreach ( array( 'is_hidden', 'isHidden', 'isHiddenElement' ) as $method ) {
						if ( method_exists( $element, $method ) ) {
							try {
								if ( (bool) $element->$method() ) {
									return true;
								}
							} catch ( \Throwable $e ) {
								unset( $e );
							}
						}
					}
					foreach ( array( 'is_occluded', 'isOccluded', 'occluded' ) as $prop ) {
						if ( isset( $element->$prop ) && (bool) $element->$prop ) {
							return true;
						}
					}
					foreach ( array( 'is_visible', 'isVisible' ) as $prop ) {
						if ( isset( $element->$prop ) && false === (bool) $element->$prop ) {
							return true;
						}
					}
					foreach ( array( 'is_hidden', 'isHidden', 'isHiddenElement' ) as $prop ) {
						if ( isset( $element->$prop ) && (bool) $element->$prop ) {
							return true;
						}
					}
					return self::element_geometry_is_occluded( (array) get_object_vars( $element ) );
				}

				if ( is_array( $element ) ) {
					foreach ( array( 'is_occluded', 'isOccluded', 'occluded' ) as $key ) {
						if ( isset( $element[ $key ] ) && (bool) $element[ $key ] ) {
							return true;
						}
					}
					foreach ( array( 'is_visible', 'isVisible' ) as $key ) {
						if ( array_key_exists( $key, $element ) && false === (bool) $element[ $key ] ) {
							return true;
						}
					}
					foreach ( array( 'is_hidden', 'isHidden', 'isHiddenElement' ) as $key ) {
						if ( isset( $element[ $key ] ) && (bool) $element[ $key ] ) {
							return true;
						}
					}
					return self::element_geometry_is_occluded( $element );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return false;
		}

		/**
		 * Geometry fallback for occlusion detection.
		 *
		 * An element with a zero-area rect (either dimension is zero or
		 * negative) counts as occluded. Missing geometry returns false
		 * (fail-open).
		 *
		 * Limitation: a zero `intersectionRatio` with a non-empty bounding
		 * rect is intentionally NOT treated as occlusion here. That shape
		 * is indistinguishable from an ordinary below-the-fold offscreen
		 * node, so classifying it as occluded would demote below-fold
		 * eager images that belong to the lazy pipeline instead. Occlusion
		 * therefore requires an explicit occlusion/visibility signal
		 * (checked in `element_is_occluded()`) or a zero-area rect.
		 *
		 * @since NEXT
		 * @param array $data Element data as an array.
		 * @return bool True when geometry implies occlusion.
		 */
		private static function element_geometry_is_occluded( array $data ): bool {
			try {
				$rect = null;
				foreach ( array( 'boundingClientRect', 'bounding_client_rect', 'boundingRect', 'bounding_rect', 'rect' ) as $key ) {
					if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
						$rect = $data[ $key ];
						break;
					}
				}
				if ( null === $rect ) {
					$w = $data['width'] ?? $data['boundingWidth'] ?? $data['bounding_width'] ?? null;
					$h = $data['height'] ?? $data['boundingHeight'] ?? $data['bounding_height'] ?? null;
					if ( null !== $w || null !== $h ) {
						$rect = array(
							'width'  => $w,
							'height' => $h,
						);
					}
				}
				if ( null === $rect ) {
					return false;
				}
				$w = isset( $rect['width'] ) && is_numeric( $rect['width'] ) ? (float) $rect['width'] : null;
				$h = isset( $rect['height'] ) && is_numeric( $rect['height'] ) ? (float) $rect['height'] : null;
				if ( null !== $w && null !== $h && ( $w <= 0 || $h <= 0 ) ) {
					return true;
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return false;
		}

		/**
		 * Retrieve URL metrics via the OD API.
		 *
		 * Tries od_get_url_metrics() first (Lab 6.9). Falls back to
		 * OD_URL_Metric static helpers if the function is unavailable.
		 *
		 * @since 2.0.0
		 * @return array List of metric objects/arrays.
		 */
		private static function get_url_metrics(): array {
			// Primary: od_get_url_metrics().
			if ( function_exists( 'od_get_url_metrics' ) ) {
				try {
					$current_url = Util::get_current_url();
					// Try with current URL first, then without args.
					if ( '' !== $current_url ) {
						try {
							$metrics = od_get_url_metrics( $current_url );
							if ( is_array( $metrics ) && ! empty( $metrics ) ) {
								return $metrics;
							}
						} catch ( \Throwable $e ) {
							self::debug_log( 'WPPO OD bridge error: ' . $e->getMessage() );
						}
					}

					$metrics = od_get_url_metrics();
					if ( is_array( $metrics ) ) {
						return $metrics;
					}
				} catch ( \Throwable $e ) {
					self::debug_log( 'WPPO OD bridge error: ' . $e->getMessage() );
				}
			}

			// Secondary: OD_URL_Metric_Group_Collection::get_groups().
			if ( class_exists( 'OD_URL_Metric_Group_Collection' ) ) {
				try {
					if ( method_exists( 'OD_URL_Metric_Group_Collection', 'get_groups_for_current_url' ) ) {
						$groups = \OD_URL_Metric_Group_Collection::get_groups_for_current_url();
						if ( is_array( $groups ) ) {
							$all = array();
							foreach ( $groups as $group ) {
								if ( is_object( $group ) && method_exists( $group, 'get_lcp_element' ) ) {
									$el = $group->get_lcp_element();
									if ( null !== $el ) {
										$all[] = $el;
									}
								} elseif ( is_object( $group ) && method_exists( $group, 'get_url_metrics' ) ) {
									$gm = $group->get_url_metrics();
									if ( is_array( $gm ) ) {
										$all = array_merge( $all, $gm );
									}
								} elseif ( is_array( $group ) ) {
									$all = array_merge( $all, $group );
								}
							}
							if ( ! empty( $all ) ) {
								return $all;
							}
						}
					}
				} catch ( \Throwable $e ) {
					self::debug_log( 'WPPO OD bridge error: ' . $e->getMessage() );
				}
			}

			// Tertiary: global collection (some OD versions store metrics in a global).
			if ( isset( $GLOBALS['od_url_metrics'] ) && is_array( $GLOBALS['od_url_metrics'] ) ) {
				return $GLOBALS['od_url_metrics'];
			}

			return array();
		}

		/**
		 * Whether an OD element is the LCP element.
		 *
		 * @since 2.0.0
		 * @param mixed $element Element object or array.
		 * @return bool True when LCP.
		 */
		private static function element_is_lcp( $element ): bool {
			if ( is_object( $element ) ) {
				if ( method_exists( $element, 'is_lcp' ) ) {
					try {
						return (bool) $element->is_lcp();
					} catch ( \Throwable $e ) {
						self::debug_log( 'WPPO OD bridge error: ' . $e->getMessage() );
					}
				}
				if ( method_exists( $element, 'isLCP' ) ) {
					try {
						return (bool) $element->isLCP();
					} catch ( \Throwable $e ) {
						self::debug_log( 'WPPO OD bridge error: ' . $e->getMessage() );
					}
				}
				if ( isset( $element->isLCP ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					return (bool) $element->isLCP; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				}
				if ( isset( $element->is_lcp ) ) {
					return (bool) $element->is_lcp;
				}
				// Heuristic: if object has xpath containing LCP tag.
				if ( method_exists( $element, 'get_xpath' ) ) {
					try {
						$xpath = $element->get_xpath();
						if ( is_string( $xpath ) && '' !== $xpath ) {
							// Presence of xpath alone does not indicate LCP; need flag.
							return false;
						}
					} catch ( \Throwable $e ) {
						self::debug_log( 'WPPO OD bridge error: ' . $e->getMessage() );
					}
				}
				return false;
			}

			if ( is_array( $element ) ) {
				if ( isset( $element['isLCP'] ) ) {
					return (bool) $element['isLCP'];
				}
				if ( isset( $element['is_lcp'] ) ) {
					return (bool) $element['is_lcp'];
				}
				if ( isset( $element['isLCPElement'] ) ) {
					return (bool) $element['isLCPElement'];
				}
				// Array element without flag — assume not LCP unless caller already filtered.
				return false;
			}

			return false;
		}

		/**
		 * Extract an image URL from an OD element.
		 *
		 * Handles object methods get_url(), get_src(), get_xpath() with
		 * attribute extraction, and array keys src/url/xpath.
		 *
		 * @since 2.0.0
		 * @param mixed $element Element object or array.
		 * @return string URL or empty string.
		 */
		private static function extract_url_from_element( $element ): string {
			if ( null === $element ) {
				return '';
			}

			if ( is_string( $element ) ) {
				// Direct URL string: sanitize and refuse non-http(s) schemes
				// (javascript:/data:) at the source; relative URLs pass.
				$clean = function_exists( 'esc_url_raw' ) ? esc_url_raw( $element ) : $element;
				if ( ! is_string( $clean ) || '' === $clean ) {
					return '';
				}
				$scheme = function_exists( 'wp_parse_url' ) ? wp_parse_url( $clean, PHP_URL_SCHEME ) : parse_url( $clean, PHP_URL_SCHEME ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback when wp_parse_url() is unavailable.
				if ( is_string( $scheme ) && '' !== $scheme && ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ) {
					return '';
				}
				return $clean;
			}

			if ( is_object( $element ) ) {
				// Try direct URL getters.
				foreach ( array( 'get_url', 'get_src', 'getAttribute', 'get_attribute' ) as $method ) {
					if ( method_exists( $element, $method ) ) {
						try {
							if ( 'getAttribute' === $method || 'get_attribute' === $method ) {
								$val = $element->$method( 'src' );
								if ( is_string( $val ) && '' !== $val ) {
									return $val;
								}
								continue;
							}
							$val = $element->$method();
							if ( is_string( $val ) && '' !== $val && false !== strpos( $val, '/' ) ) {
								return $val;
							}
						} catch ( \Throwable $e ) {
							self::debug_log( 'WPPO OD bridge error: ' . $e->getMessage() );
						}
					}
				}

				// Try properties.
				foreach ( array( 'url', 'src', 'xpath', 'nodePath' ) as $prop ) {
					if ( isset( $element->$prop ) && is_string( $element->$prop ) && '' !== $element->$prop ) {
						$val = $element->$prop;
						// If xpath, try to extract URL from xpath-like string (may contain src).
						if ( 'xpath' === $prop || 'nodePath' === $prop ) {
							continue;
						}
						return $val;
					}
				}

				// Try array access.
				if ( $element instanceof \ArrayAccess ) {
					foreach ( array( 'src', 'url', 'image_url', 'lcp_url' ) as $key ) {
						if ( isset( $element[ $key ] ) && is_string( $element[ $key ] ) && '' !== $element[ $key ] ) {
							return $element[ $key ];
						}
					}
				}

				// Try to serialize element to array for fallback.
				if ( method_exists( $element, 'to_array' ) ) {
					try {
						$arr = $element->to_array();
						if ( is_array( $arr ) ) {
							$url = self::extract_url_from_element( $arr );
							if ( '' !== $url ) {
								return $url;
							}
						}
					} catch ( \Throwable $e ) {
						self::debug_log( 'WPPO OD bridge error: ' . $e->getMessage() );
					}
				}
			}

			if ( is_array( $element ) ) {
				foreach ( array( 'src', 'url', 'image_url', 'lcp_url', 'imageUrl', 'lcpUrl' ) as $key ) {
					if ( isset( $element[ $key ] ) && is_string( $element[ $key ] ) && '' !== $element[ $key ] ) {
						return $element[ $key ];
					}
				}
				// Nested attributes array.
				if ( isset( $element['attributes'] ) && is_array( $element['attributes'] ) ) {
					foreach ( array( 'src', 'url' ) as $key ) {
						if ( isset( $element['attributes'][ $key ] ) && is_string( $element['attributes'][ $key ] ) && '' !== $element['attributes'][ $key ] ) {
							return $element['attributes'][ $key ];
						}
					}
				}
				// Sometimes xpath contains the image path.
				if ( isset( $element['xpath'] ) && is_string( $element['xpath'] ) && false !== strpos( $element['xpath'], 'img' ) ) {
					return '';
				}
			}

			return '';
		}
	}
}
