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
	 * @since NEXT
	 */
	class OD_Bridge {

		/**
		 * Settings key for the OD integration toggle.
		 *
		 * @since NEXT
		 * @var string
		 */
		const SETTINGS_KEY = 'od_integration';

		/**
		 * Filter to control whether OD optimization should be applied.
		 *
		 * @since NEXT
		 * @var string
		 */
		const FILTER_SHOULD_OPTIMIZE = 'wppo_od_should_optimize';

		/**
		 * Whether the Optimization Detective plugin is available.
		 *
		 * Checks for the Lab 6.9 class OD_URL_Metric or the helper
		 * function od_get_url_metrics(). No autoload is triggered beyond
		 * the class_exists check (autoload true by default).
		 *
		 * @since NEXT
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
		 * @since NEXT
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

			/**
			 * Filters whether OD-based optimization should be applied.
			 *
			 * @since NEXT
			 * @param bool   $should      Whether to optimize.
			 * @param string $current_url Current URL (if resolvable).
			 */
			$should = (bool) apply_filters( self::FILTER_SHOULD_OPTIMIZE, $enabled, $current_url );

			return $should && $enabled;
		}

		/**
		 * Get the LCP image URL from OD viewport groups.
		 *
		 * Tries viewport groups (mobile/desktop) first: collects LCP tags
		 * per group and returns the most representative URL. Falls back to
		 * a flat metric scan. Returns empty string when no OD data is
		 * available or optimization is disabled.
		 *
		 * @since NEXT
		 * @return string LCP image URL or empty string.
		 */
		public static function get_lcp_url(): string {
			if ( ! self::is_enabled() ) {
				return '';
			}

			$raw_urls = self::collect_raw_lcp_urls();
			if ( empty( $raw_urls ) ) {
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
			foreach ( $normalized as $idx => $norm ) {
				if ( ( $counts[ $norm ] ?? 0 ) === $max ) {
					return (string) $raw_urls[ $idx ];
				}
			}

			return (string) $raw_urls[0];
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
		 * @since NEXT
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

			// Heuristic fallback 1-3.
			$settings = Util::get_settings();
			$stored   = null;
			if ( isset( $settings['image_optimisation']['excludeFirstImages'] ) ) {
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
		 * @since NEXT
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
		 * @since NEXT
		 * @return string[] Raw LCP image URLs (may contain duplicates).
		 */
		private static function collect_raw_lcp_urls(): array {
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
							if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
								error_log( 'WPPO OD bridge get_lcp_element error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
							}
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
							if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
								error_log( 'WPPO OD bridge get_elements error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
							}
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
					} else {
						// Direct array with xpath/src.
						$url = self::extract_url_from_element( $metric );
						if ( '' !== $url ) {
							// Check isLCP flag in array.
							$urls[] = $url;
						}
					}
				}
			}

			return array_values( array_filter( $urls ) );
		}

		/**
		 * Count viewport groups from OD data.
		 *
		 * Used as a proxy for above-the-fold image count when LCP tag
		 * is not yet available but groups are.
		 *
		 * @since NEXT
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
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( 'WPPO OD bridge error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
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
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( 'WPPO OD bridge error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
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
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( 'WPPO OD bridge error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}
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
		 * Retrieve URL metrics via the OD API.
		 *
		 * Tries od_get_url_metrics() first (Lab 6.9). Falls back to
		 * OD_URL_Metric static helpers if the function is unavailable.
		 *
		 * @since NEXT
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
							if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
								error_log( 'WPPO OD bridge error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
							}
						}
					}

					$metrics = od_get_url_metrics();
					if ( is_array( $metrics ) ) {
						return $metrics;
					}
				} catch ( \Throwable $e ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( 'WPPO OD bridge error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
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
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( 'WPPO OD bridge error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
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
		 * @since NEXT
		 * @param mixed $element Element object or array.
		 * @return bool True when LCP.
		 */
		private static function element_is_lcp( $element ): bool {
			if ( is_object( $element ) ) {
				if ( method_exists( $element, 'is_lcp' ) ) {
					try {
						return (bool) $element->is_lcp();
					} catch ( \Throwable $e ) {
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( 'WPPO OD bridge error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}
					}
				}
				if ( method_exists( $element, 'isLCP' ) ) {
					try {
						return (bool) $element->isLCP();
					} catch ( \Throwable $e ) {
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( 'WPPO OD bridge error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}
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
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( 'WPPO OD bridge error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}
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
		 * @since NEXT
		 * @param mixed $element Element object or array.
		 * @return string URL or empty string.
		 */
		private static function extract_url_from_element( $element ): string {
			if ( null === $element ) {
				return '';
			}

			if ( is_string( $element ) ) {
				// Direct URL string.
				return $element;
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
							if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
								error_log( 'WPPO OD bridge error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
							}
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
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( 'WPPO OD bridge error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}
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
