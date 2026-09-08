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
		 * Whether the current request runs in a commerce or authenticated context.
		 *
		 * Conservative gate for AI speculation guardrails (issue #908): when true,
		 * AI-learned speculation eagerness is capped at `moderate` and commerce
		 * paths are suggested as speculation excludes. Mirrors the WooCommerce /
		 * auth detection precedents in Cache cart/checkout/account guards
		 * (class-cache.php) and LiteSpeed_ESI::should_punch_hole().
		 *
		 * Manual user settings are never touched — the AI only tightens, never
		 * loosens past moderate.
		 *
		 * Tradeoff: a mere active WooCommerce install caps eagerness site-wide
		 * (even on blog/product pages), because eagerness is a global setting
		 * and speculation rules apply globally. Page-level safety additionally
		 * comes from the always-on WooCommerce cart/checkout/account exclude
		 * paths in Main::add_speculation_rules().
		 *
		 * Limitation: the logged-in probe only fires in frontend contexts, so
		 * learn()/suggestions served from wp-admin on an auth-only (non-Woo)
		 * site cannot see the visitor's login state and may keep `eager`.
		 * Per-request protection still applies via filter_speculation_rules()
		 * for logged-in frontend visitors.
		 *
		 * @return bool True when a commerce/auth context is detected.
		 * @since NEXT
		 */
		public static function is_commerce_or_auth_context(): bool {
			$is_commerce = false;

			// WooCommerce active (plugin present, even outside shop pages — conservative cap).
			try {
				if ( class_exists( 'WooCommerce' ) || function_exists( 'WC' ) || function_exists( 'wc_get_checkout_url' ) ) {
					$is_commerce = true;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			if ( ! $is_commerce && function_exists( 'is_cart' ) ) {
				try {
					$is_commerce = (bool) is_cart();
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			if ( ! $is_commerce && function_exists( 'is_checkout' ) ) {
				try {
					$is_commerce = (bool) is_checkout();
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			if ( ! $is_commerce && function_exists( 'is_account_page' ) ) {
				try {
					$is_commerce = (bool) is_account_page();
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			if ( ! $is_commerce && function_exists( 'is_user_logged_in' ) ) {
				try {
					// Frontend visitors only: learn()/get_suggestions() execute in
					// wp-admin/REST/cron/CLI where a logged-in admin is always
					// present, which would otherwise cap every site site-wide.
					if ( self::is_frontend_context() ) {
						$is_commerce = (bool) is_user_logged_in();
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			// Active cart session on any page (read-only heuristic, no nonce needed).
			if ( ! $is_commerce && ( ! empty( $_COOKIE['woocommerce_items_in_cart'] ) || ! empty( $_COOKIE['woocommerce_cart_hash'] ) ) ) {
				$is_commerce = true;
			}

			/**
			 * Filters whether the current request is a commerce/auth context for AI speculation guardrails.
			 *
			 * Allows hosts/tests to force the context deterministically.
			 *
			 * @since NEXT
			 * @param bool $is_commerce Whether a commerce/auth context was detected.
			 */
			return (bool) apply_filters( 'wppo_ai_adaptive_commerce_context', $is_commerce );
		}

		/**
		 * Commerce/auth URL exclusion patterns for speculation suggestions.
		 *
		 * Derives cart/checkout/myaccount paths via wc_get_checkout_url() /
		 * wc_get_cart_url() / wc_get_page_permalink('myaccount') (exact precedent
		 * Main::add_speculation_rules()), falling back to /cart/*, /checkout/*,
		 * /my-account/* patterns when WooCommerce helpers are absent but a
		 * commerce/auth context (cookie/logged-in) was detected.
		 *
		 * @return string[]
		 * @since NEXT
		 */
		public static function get_commerce_exclude_paths(): array {
			$paths = array();

			if ( function_exists( 'wc_get_checkout_url' ) ) {
				try {
					$checkout_url = wc_get_checkout_url();
					if ( $checkout_url ) {
						$path = wp_parse_url( $checkout_url, PHP_URL_PATH );
						if ( $path && '/' !== $path ) {
							$paths[] = trailingslashit( $path ) . '*';
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			if ( function_exists( 'wc_get_cart_url' ) ) {
				try {
					$cart_url = wc_get_cart_url();
					if ( $cart_url ) {
						$path = wp_parse_url( $cart_url, PHP_URL_PATH );
						if ( $path && '/' !== $path ) {
							$paths[] = trailingslashit( $path ) . '*';
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			if ( function_exists( 'wc_get_page_permalink' ) ) {
				try {
					$myaccount_url = wc_get_page_permalink( 'myaccount' );
					if ( $myaccount_url ) {
						$path = wp_parse_url( $myaccount_url, PHP_URL_PATH );
						if ( $path && '/' !== $path ) {
							$paths[] = trailingslashit( $path ) . '*';
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			if ( empty( $paths ) ) {
				$paths = array( '/cart/*', '/checkout/*', '/my-account/*' );
			}

			return array_values( array_unique( $paths ) );
		}

		/**
		 * Whether the current request is a frontend visitor context.
		 *
		 * Used to scope the logged-in probe in is_commerce_or_auth_context():
		 * admin dashboard, REST, AJAX, cron, and CLI requests never represent a
		 * frontend visitor seeing speculation rules, and learn() runs there with
		 * an always-logged-in admin. All probes are function_exists-guarded so
		 * unit tests and minimal installs default to frontend (true).
		 *
		 * @return bool True when the request looks like a frontend visit.
		 * @since NEXT
		 */
		private static function is_frontend_context(): bool {
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				return false;
			}
			if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
				return false;
			}
			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				return false;
			}
			if ( function_exists( 'wp_doing_cron' ) ) {
				try {
					if ( wp_doing_cron() ) {
						return false;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( function_exists( 'is_admin' ) ) {
				try {
					if ( is_admin() ) {
						return false;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( function_exists( 'wp_doing_ajax' ) ) {
				try {
					if ( wp_doing_ajax() ) {
						return false;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			return true;
		}

		/**
		 * Normalize an eagerness value: allowlist then commerce/auth cap.
		 *
		 * Anything outside conservative|moderate|eager (e.g. LLM garbage like
		 * 'Eager' or 'aggressive', or stale pre-guardrail models) is coerced to
		 * conservative; `eager` is then capped at `moderate` in commerce/auth
		 * contexts via maybe_cap_eagerness().
		 *
		 * @param mixed $eagerness Raw eagerness value.
		 * @return string Normalized eagerness value.
		 * @since NEXT
		 */
		private static function normalize_eagerness( $eagerness ): string {
			// Non-stringable input (e.g. an array from a malformed AI payload)
			// must not reach the (string) cast (PHP warning); coerce to '' so
			// the allowlist below falls back to conservative.
			if ( ! is_string( $eagerness ) ) {
				$eagerness = is_scalar( $eagerness ) ? (string) $eagerness : '';
			}
			if ( ! in_array( $eagerness, array( 'conservative', 'moderate', 'eager' ), true ) ) {
				$eagerness = 'conservative';
			}
			return self::maybe_cap_eagerness( $eagerness );
		}

		/**
		 * Cap an AI-learned eagerness value at `moderate` in commerce/auth contexts.
		 *
		 * The AI only tightens, never loosens: `eager` becomes `moderate`, every
		 * other value passes through untouched. Manual user settings
		 * (wppo_settings[preload_settings][speculationEagerness]) are never touched.
		 *
		 * @param string $eagerness Learned eagerness value.
		 * @return string Capped eagerness value.
		 * @since NEXT
		 */
		public static function maybe_cap_eagerness( string $eagerness ): string {
			if ( 'eager' === $eagerness && self::is_commerce_or_auth_context() ) {
				return 'moderate';
			}
			return $eagerness;
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
					// Guardrail (#908): allowlist-validate (LLM output is untrusted)
					// and cap `eager` at `moderate` in commerce/auth contexts.
					if ( isset( $ai_model['eagerness'] ) ) {
						$ai_model['eagerness'] = self::normalize_eagerness( $ai_model['eagerness'] );
					}
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
				$client = wp_ai_client();
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

			// Most-frequently disabled handles = least-used (candidates to exclude).
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
			// Guardrail (#908): allowlist then cap at `moderate` in commerce/auth
			// contexts AFTER the filter, so a third-party filter returning
			// `eager` is still tightened.
			$eagerness = self::normalize_eagerness( $eagerness );

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
			return self::get_prefetch_urls_from_model( self::get_model() );
		}

		/**
		 * Extract top-2 sanitized prefetch URLs from a model array.
		 *
		 * Shared by get_prefetch_urls() and filter_speculation_rules() so the
		 * latter reads the stored model only once per filter run.
		 *
		 * @param array $model Model data.
		 * @return string[]
		 * @since NEXT
		 */
		private static function get_prefetch_urls_from_model( array $model ): array {
			$urls = $model['prefetch_urls'] ?? array();
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
			// Guardrail (#908): allowlist stale values and never propose `eager`
			// in commerce/auth contexts (covers models persisted before the cap).
			$eagerness = self::normalize_eagerness( $eagerness );
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

			// Guardrail (#908): in commerce/auth contexts suggest excluding
			// transactional/authenticated URLs from speculation. Suggestions only —
			// never auto-applied (see method docblock). Only paths missing from the
			// stored speculationExcludeUrls are suggested, so the suggestion
			// resolves once the user applies it.
			if ( self::is_commerce_or_auth_context() ) {
				$commerce_paths = self::get_commerce_exclude_paths();
				$settings       = Util::get_settings();
				$existing       = isset( $settings['preload_settings']['speculationExcludeUrls'] ) ? (string) $settings['preload_settings']['speculationExcludeUrls'] : '';
				// Normalized line-by-line compare (trim + trailing-slash and
				// wildcard insensitive) so formatting variants do not re-suggest.
				$normalize_path = static function ( $path ) {
					return rtrim( rtrim( rtrim( trim( (string) $path ), '/' ), '*' ), '/' );
				};
				$existing_list  = array_map( $normalize_path, Util::process_urls( $existing ) );
				$missing        = array();
				foreach ( $commerce_paths as $commerce_path ) {
					if ( ! in_array( $normalize_path( $commerce_path ), $existing_list, true ) ) {
						$missing[] = $commerce_path;
					}
				}
				if ( ! empty( $missing ) ) {
					// AiPanel.js merges ai_payload at settings-object level, so the
					// payload must carry existing + missing or applying would
					// discard the user's current custom excludes.
					$payload_excludes = implode( "\n", $missing );
					$existing_trimmed = trim( str_replace( "\r\n", "\n", $existing ) );
					if ( '' !== $existing_trimmed ) {
						$payload_excludes = $existing_trimmed . "\n" . $payload_excludes;
					}
					$suggestions[] = array(
						'metric'      => 'ai_speculation_excludes',
						'value'       => implode( ', ', $missing ),
						'unit'        => 'list',
						'status'      => 'needs_improvement',
						'description' => __( 'AI: Exclude commerce/auth URLs from speculation', 'performance-optimisation' ),
						'fix_action'  => 'open_preload_tab',
						'ai_payload'  => array(
							'tab'      => 'preload_settings',
							'settings' => array( 'speculationExcludeUrls' => $payload_excludes ),
						),
					);
				}
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
			// Single model read: reused for both prefetch URLs and eagerness.
			$model = self::get_model();
			$urls  = self::get_prefetch_urls_from_model( $model );
			if ( empty( $urls ) ) {
				return $rules;
			}
			// Append AI prefetch rule (prefetch top-2). Structure mirrors WP core:
			// rules = [ { source: 'list', urls: [...] , eagerness: 'conservative' } ].
			// Guardrail (#908): allowlist stale values and downgrade a persisted
			// `eager` to `moderate` in commerce/auth contexts before injecting.
			$eagerness = self::normalize_eagerness( $model['eagerness'] ?? 'conservative' );
			$rules[]   = array(
				'source'    => 'list',
				'urls'      => $urls,
				'eagerness' => $eagerness,
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
