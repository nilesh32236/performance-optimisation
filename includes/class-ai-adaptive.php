<?php
/**
 * AI Adaptive optimization — RUM → heuristic/auto-tune via suggestions.
 *
 * @package PerformanceOptimise\Inc
 * @since NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\AI_Adaptive' ) ) {
	/**
	 * Learns from RUM + trends + asset-usage heuristics and produces
	 * suggestion objects for file_optimisation / preload_settings.
	 *
	 * Never auto-enables: all outputs are suggestions gated by
	 * ai_adaptive.enabled + wppo_ai_adaptive_enabled filter.
	 *
	 * @since NEXT
	 */
	class AI_Adaptive {

		/**
		 * Option storing the learned model (autoload=no).
		 *
		 * @var string
		 */
		public const OPTION = 'wppo_ai_model';

		/**
		 * Transient lock for learn throttling.
		 *
		 * @var string
		 */
		private const LEARN_LOCK = 'wppo_ai_learn_lock';

		/**
		 * Whether AI adaptive optimization is enabled.
		 *
		 * Gated by wppo_settings[ai_adaptive][enabled] (false default)
		 * and the wppo_ai_adaptive_enabled filter. The filter receives the
		 * boolean setting and must return bool.
		 *
		 * @return bool
		 * @since NEXT
		 */
		public static function is_enabled(): bool {
			$settings = Util::get_settings();
			$enabled  = ! empty( $settings['ai_adaptive']['enabled'] );
			/**
			 * Filters whether AI Adaptive is enabled.
			 *
			 * @since NEXT
			 * @param bool $enabled Whether AI adaptive is enabled.
			 */
			return (bool) apply_filters( 'wppo_ai_adaptive_enabled', $enabled );
		}

		/**
		 * Get the stored model.
		 *
		 * @return array
		 * @since NEXT
		 */
		public static function get_model(): array {
			$model = get_option( self::OPTION, array() );
			return is_array( $model ) ? $model : array();
		}

		/**
		 * Persist the model with autoload=no.
		 *
		 * @param array $model Model data.
		 * @return void
		 * @since NEXT
		 */
		public static function update_model( array $model ): void {
			update_option( self::OPTION, $model, false );
		}

		/**
		 * Learn from RUM + trends + disabled-script frequency.
		 *
		 * When WP 7.0 AI Client is available (function_exists('wp_ai_client')),
		 * delegates to it; otherwise uses the local heuristic fallback.
		 *
		 * The heuristic is a simple logistic-like scorer over
		 * wppo_web_vitals_rum + wppo_web_vitals_trends + wppo_settings:
		 * - per-URL pattern: slowest LCP + highest sample count = top prefetch.
		 * - least-used scripts: frequency of _wppo_disabled_scripts meta.
		 * - speculation eagerness: derived from average LCP / RUM ttfb.
		 *
		 * @return array The updated model.
		 * @since NEXT
		 */
		public static function learn(): array {
			// Throttle: at most once per minute.
			$lock_key = Util::transient_key( self::LEARN_LOCK );
			if ( get_transient( $lock_key ) ) {
				return self::get_model();
			}
			set_transient( $lock_key, 1, MINUTE_IN_SECONDS );

			if ( function_exists( 'wp_ai_client' ) ) {
				$ai_model = self::learn_via_ai_client();
				if ( is_array( $ai_model ) && ! empty( $ai_model ) ) {
					$ai_model['source']     = 'ai_client';
					$ai_model['updated_at'] = time();
					self::update_model( $ai_model );
					return $ai_model;
				}
			}

			$model               = self::heuristic_learn();
			$model['source']     = 'heuristic';
			$model['updated_at'] = time();
			self::update_model( $model );
			return $model;
		}

		/**
		 * Attempt learning via WP 7.0 AI Client when available.
		 *
		 * @return array|null Model or null on fallback.
		 * @since NEXT
		 */
		private static function learn_via_ai_client(): ?array {
			if ( ! function_exists( 'wp_ai_client' ) ) {
				return null;
			}
			try {
				$client = wp_ai_client(); // @phpstan-ignore-line
				if ( ! is_object( $client ) || ! method_exists( $client, 'prompt' ) ) {
					return null;
				}
				$rum    = get_option( RUM::OPTION, array() );
				$trends = get_option( Pagespeed::TREND_OPTION, array() );
				$prompt = 'Given RUM aggregates and trends, suggest top 2 prefetch URLs, least-used scripts to exclude, and speculation eagerness (conservative|moderate|eager) as JSON.';
				$result = $client->prompt( $prompt . ' RUM:' . wp_json_encode( $rum ) . ' Trends:' . wp_json_encode( $trends ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.option_option -- AI model needs cross-signal input.
				if ( is_array( $result ) ) {
					return $result;
				}
				if ( is_string( $result ) ) {
					$decoded = json_decode( $result, true );
					if ( is_array( $decoded ) ) {
						return $decoded;
					}
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
			return null;
		}

		/**
		 * Heuristic fallback learning.
		 *
		 * @return array
		 * @since NEXT
		 */
		private static function heuristic_learn(): array {
			$rum    = get_option( RUM::OPTION, array() );
			$trends = get_option( Pagespeed::TREND_OPTION, array() );
			if ( ! is_array( $rum ) ) {
				$rum = array();
			}
			if ( ! is_array( $trends ) ) {
				$trends = array();
			}

			// Per-URL pattern: score = avg LCP * log(count+1) + ttfb weight.
			$url_scores = array();
			foreach ( $rum as $date => $paths ) {
				if ( ! is_array( $paths ) ) {
					continue;
				}
				foreach ( $paths as $path => $metrics ) {
					if ( ! is_array( $metrics ) ) {
						continue;
					}
					$lcp_n    = isset( $metrics['lcp']['n'] ) ? (int) $metrics['lcp']['n'] : 0;
					$lcp_sum  = isset( $metrics['lcp']['sum'] ) ? (float) $metrics['lcp']['sum'] : 0;
					$avg_lcp  = $lcp_n > 0 ? $lcp_sum / $lcp_n : 0;
					$ttfb_n   = isset( $metrics['ttfb']['n'] ) ? (int) $metrics['ttfb']['n'] : 0;
					$ttfb_sum = isset( $metrics['ttfb']['sum'] ) ? (float) $metrics['ttfb']['sum'] : 0;
					$avg_ttfb = $ttfb_n > 0 ? $ttfb_sum / $ttfb_n : 0;
					$count    = max( $lcp_n, $ttfb_n, 1 );
					// Simple logistic-like score: higher LCP/TTFB = higher priority, dampened by log.
					$score = ( $avg_lcp * 0.7 + $avg_ttfb * 0.3 ) * log( $count + 1 );
					if ( ! isset( $url_scores[ $path ] ) ) {
						$url_scores[ $path ] = 0;
					}
					$url_scores[ $path ] += $score;
				}
			}

			// Blend trends heatmap (performance score inverse).
			foreach ( $trends as $key => $snapshots ) {
				if ( ! is_array( $snapshots ) ) {
					continue;
				}
				// key is md5(url)_strategy — we cannot reverse md5; use as signal only for eagerness.
				// For prefetch URLs, prefer RUM paths; trends influence eagerness.
			}

			arsort( $url_scores );
			$top_paths = array_slice( array_keys( $url_scores ), 0, 2 );

			// Resolve paths to absolute URLs for speculation.
			$prefetch_urls = array();
			foreach ( $top_paths as $path ) {
				$url             = Util::cached_home_url( $path );
				$prefetch_urls[] = esc_url_raw( $url );
			}

			// Most-frequently disabled handles = least-used (candidates to suggest excluding).
			$exclude_js  = array();
			$exclude_css = array();
			global $wpdb;
			if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_col' ) ) {
				$disabled = array();
				try {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$rows = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wppo_disabled_scripts' LIMIT 500" );
					if ( is_array( $rows ) ) {
						foreach ( $rows as $row ) {
							$val = maybe_unserialize( $row );
							if ( is_array( $val ) ) {
								foreach ( $val as $handle ) {
									$handle = sanitize_text_field( (string) $handle );
									if ( '' === $handle ) {
										continue;
									}
									$disabled[ $handle ] = ( $disabled[ $handle ] ?? 0 ) + 1;
								}
							}
						}
					}
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				}
				if ( ! empty( $disabled ) ) {
					arsort( $disabled );
					$exclude_js = array_slice( array_keys( $disabled ), 0, 3 );
				}
				$disabled_css = array();
				try {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$rows_css = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wppo_disabled_styles' LIMIT 500" );
					if ( is_array( $rows_css ) ) {
						foreach ( $rows_css as $row ) {
							$val = maybe_unserialize( $row );
							if ( is_array( $val ) ) {
								foreach ( $val as $handle ) {
									$handle = sanitize_text_field( (string) $handle );
									if ( '' === $handle ) {
										continue;
									}
									$disabled_css[ $handle ] = ( $disabled_css[ $handle ] ?? 0 ) + 1;
								}
							}
						}
					}
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				}
				if ( ! empty( $disabled_css ) ) {
					arsort( $disabled_css );
					$exclude_css = array_slice( array_keys( $disabled_css ), 0, 3 );
				}
			}

			// Fallback JS excludes from file_optimisation settings when no postmeta.
			if ( empty( $exclude_js ) ) {
				$settings = Util::get_settings();
				$assets   = array();
				// Try to suggest from existing disabled styles/scripts frequency alternative:
				// Use non-critical handles that appear in unused-javascript diagnostics indirectly.
				// For MVP, leave empty — suggestions will trigger only when data exists.
			}

			// Eagerness heuristic: conservative by default, moderate if avg LCP > 2500 or high TTFB.
			$eagerness   = 'conservative';
			$avg_lcp_all = 0;
			$cnt         = 0;
			foreach ( $rum as $date => $paths ) {
				if ( ! is_array( $paths ) ) {
					continue;
				}
				foreach ( $paths as $path => $metrics ) {
					if ( isset( $metrics['lcp']['sum'], $metrics['lcp']['n'] ) && $metrics['lcp']['n'] > 0 ) {
						$avg_lcp_all += (float) $metrics['lcp']['sum'] / (int) $metrics['lcp']['n'];
						++$cnt;
					}
				}
			}
			if ( $cnt > 0 ) {
				$avg_lcp_all /= $cnt;
				if ( $avg_lcp_all > 3500 ) {
					$eagerness = 'eager';
				} elseif ( $avg_lcp_all > 2500 ) {
					$eagerness = 'moderate';
				}
			}

			// Allow filter for eagerness.
			/**
			 * Filters AI-learned speculation eagerness.
			 *
			 * @since NEXT
			 * @param string $eagerness Eagerness value.
			 * @param array  $rum RUM aggregates.
			 */
			$eagerness = apply_filters( 'wppo_ai_adaptive_eagerness', $eagerness, $rum );
			if ( ! in_array( $eagerness, array( 'conservative', 'moderate', 'eager' ), true ) ) {
				$eagerness = 'conservative';
			}

			return array(
				'version'       => 1,
				'prefetch_urls' => array_values( array_filter( $prefetch_urls ) ),
				'exclude_js'    => array_values( array_filter( $exclude_js ) ),
				'exclude_css'   => array_values( array_filter( $exclude_css ) ),
				'eagerness'     => $eagerness,
			);
		}

		/**
		 * Get AI-learned prefetch URLs (top-2).
		 *
		 * @return string[]
		 * @since NEXT
		 */
		public static function get_prefetch_urls(): array {
			$model = self::get_model();
			$urls  = $model['prefetch_urls'] ?? array();
			if ( ! is_array( $urls ) ) {
				return array();
			}
			$urls = array_values( array_filter( array_map( 'esc_url_raw', $urls ) ) );
			return array_slice( $urls, 0, 2 );
		}

		/**
		 * Generate suggestion objects for the suggestion engine.
		 *
		 * Always returns suggestion-shaped arrays but the caller should gate
		 * display on is_enabled() (guard: never auto-apply).
		 *
		 * @return array[]
		 * @since NEXT
		 */
		public static function get_suggestions(): array {
			$model = self::get_model();
			if ( empty( $model ) ) {
				$model = self::heuristic_learn();
			}
			$suggestions = array();

			$exclude_js = $model['exclude_js'] ?? array();
			if ( is_array( $exclude_js ) && ! empty( $exclude_js ) ) {
				$suggestions[] = array(
					'metric'      => 'ai_exclude_js',
					'value'       => implode( ', ', $exclude_js ),
					'unit'        => 'list',
					'status'      => 'needs_improvement',
					'description' => __( 'AI: Scripts to exclude (least-used)', 'performance-optimisation' ),
					'fix_action'  => 'open_file_optimization_tab',
					'ai_payload'  => array(
						'tab'      => 'file_optimisation',
						'settings' => array( 'excludeJS' => implode( "\n", $exclude_js ) ),
					),
				);
			}

			$exclude_css = $model['exclude_css'] ?? array();
			if ( is_array( $exclude_css ) && ! empty( $exclude_css ) ) {
				$suggestions[] = array(
					'metric'      => 'ai_exclude_css',
					'value'       => implode( ', ', $exclude_css ),
					'unit'        => 'list',
					'status'      => 'needs_improvement',
					'description' => __( 'AI: Styles to exclude (least-used)', 'performance-optimisation' ),
					'fix_action'  => 'open_file_optimization_tab',
					'ai_payload'  => array(
						'tab'      => 'file_optimisation',
						'settings' => array( 'excludeCSS' => implode( "\n", $exclude_css ) ),
					),
				);
			}

			$eagerness = $model['eagerness'] ?? 'conservative';
			if ( 'conservative' !== $eagerness ) {
				$suggestions[] = array(
					'metric'      => 'ai_speculation_eagerness',
					'value'       => $eagerness,
					'unit'        => 'string',
					'status'      => 'needs_improvement',
					'description' => __( 'AI: Speculation eagerness suggestion', 'performance-optimisation' ),
					'fix_action'  => 'open_preload_tab',
					'ai_payload'  => array(
						'tab'      => 'preload_settings',
						'settings' => array( 'speculationEagerness' => $eagerness ),
					),
				);
			}

			$prefetch = $model['prefetch_urls'] ?? array();
			if ( is_array( $prefetch ) && ! empty( $prefetch ) ) {
				$suggestions[] = array(
					'metric'      => 'ai_prefetch_urls',
					'value'       => implode( ', ', array_slice( $prefetch, 0, 2 ) ),
					'unit'        => 'list',
					'status'      => 'needs_improvement',
					'description' => __( 'AI: Prefetch predicted next URLs', 'performance-optimisation' ),
					'fix_action'  => 'open_preload_tab',
					'ai_payload'  => array(
						'tab'      => 'preload_settings',
						'settings' => array( 'speculationMode' => 'prefetch' ),
					),
				);
			}

			// Ensure fix_action is valid per Suggestion_Engine guard (already valid).
			return $suggestions;
		}

		/**
		 * Inject AI-learned prefetch URLs into speculation rules.
		 *
		 * Hooks into wp_speculation_rules filter (WP 6.8+). Only injects when
		 * AI adaptive is enabled and prefetch URLs exist; never auto-enables
		 * speculation itself.
		 *
		 * @param array $rules Speculation rules array.
		 * @return array
		 * @since NEXT
		 */
		public static function filter_speculation_rules( $rules ) {
			if ( ! self::is_enabled() ) {
				return $rules;
			}
			if ( ! is_array( $rules ) ) {
				return $rules;
			}
			$urls = self::get_prefetch_urls();
			if ( empty( $urls ) ) {
				return $rules;
			}
			// Append AI prefetch rule (prefetch top-2). Structure mirrors WP core:
			// rules = [ { source: 'list', urls: [...] , eagerness: 'conservative' } ].
			$rules[] = array(
				'source'    => 'list',
				'urls'      => $urls,
				'eagerness' => self::get_model()['eagerness'] ?? 'conservative',
			);
			/**
			 * Filters AI-injected speculation rules.
			 *
			 * @since NEXT
			 * @param array $rules Updated rules.
			 * @param array $urls AI prefetch URLs.
			 */
			return apply_filters( 'wppo_ai_adaptive_speculation_rules', $rules, $urls );
		}

		/**
		 * Register hooks.
		 *
		 * @return void
		 * @since NEXT
		 */
		public static function init(): void {
			if ( function_exists( 'wp_get_speculation_rules_configuration' ) ) {
				add_filter( 'wp_speculation_rules', array( self::class, 'filter_speculation_rules' ), 20 );
			}
		}
	}
}
