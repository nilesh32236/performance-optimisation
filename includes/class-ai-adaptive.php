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
			$is_commerce = self::detect_commerce_or_auth_context();

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
		 * Internal helper to detect commerce or auth context using early returns.
		 *
		 * @return bool True when a commerce/auth context is detected.
		 * @since NEXT
		 */
		private static function detect_commerce_or_auth_context(): bool {
			// WooCommerce active (plugin present, even outside shop pages — conservative cap).
			try {
				if ( class_exists( 'WooCommerce' ) || function_exists( 'WC' ) || function_exists( 'wc_get_checkout_url' ) ) {
					return true;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			if ( function_exists( 'is_cart' ) ) {
				try {
					if ( is_cart() ) {
						return true;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			if ( function_exists( 'is_checkout' ) ) {
				try {
					if ( is_checkout() ) {
						return true;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			if ( function_exists( 'is_account_page' ) ) {
				try {
					if ( is_account_page() ) {
						return true;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			if ( function_exists( 'is_user_logged_in' ) ) {
				try {
					// Frontend visitors only: learn()/get_suggestions() execute in
					// wp-admin/REST/cron/CLI where a logged-in admin is always
					// present, which would otherwise cap every site site-wide.
					if ( self::is_frontend_context() && is_user_logged_in() ) {
						return true;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			// Active cart session on any page (read-only heuristic, no nonce needed).
			if ( ! empty( $_COOKIE['woocommerce_items_in_cart'] ) || ! empty( $_COOKIE['woocommerce_cart_hash'] ) ) {
				return true;
			}

			return false;
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
		 * Keep in sync with Main::add_speculation_rules() — both derive the same
		 * WooCommerce cart/checkout/account paths.
		 *
		 * @see Main::add_speculation_rules()
		 *
		 * @return string[]
		 * @since NEXT
		 */
		public static function get_commerce_exclude_paths(): array {
			$paths = array();

			// URL helpers are WP core (always present in normal runtime);
			// guarded for minimal installs, mirroring the other probes.
			$has_url_helpers = function_exists( 'wp_parse_url' ) && function_exists( 'trailingslashit' );

			// Canonical order (cart, checkout, my-account) matches the fallback
			// below so output order never depends on probe order.
			if ( $has_url_helpers && function_exists( 'wc_get_cart_url' ) ) {
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

			if ( $has_url_helpers && function_exists( 'wc_get_checkout_url' ) ) {
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

			if ( $has_url_helpers && function_exists( 'wc_get_page_permalink' ) ) {
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
		 * Whether the optional WordPress AI client path is enabled.
		 *
		 * Local-only scoring stays the default: the AI client is used only
		 * when the explicit `ai_adaptive.use_wp_ai_client` opt-in setting is
		 * true (and host-controlled via filter). Fail-open: absent client
		 * classes/functions fall back to the local heuristic.
		 *
		 * @return bool
		 * @since NEXT
		 */
		public static function is_wp_ai_client_enabled(): bool {
			$settings = Util::get_settings();
			$enabled  = ! empty( $settings['ai_adaptive']['use_wp_ai_client'] );
			/**
			 * Filters whether the WordPress AI client path may be used.
			 *
			 * @since NEXT
			 * @param bool $enabled Whether the WP AI client opt-in is on.
			 */
			return (bool) apply_filters( 'wppo_ai_adaptive_use_wp_client', $enabled );
		}

		/**
		 * Validate a suggestion object shape before display.
		 *
		 * Every emitted item must carry the 6 required keys with a known
		 * status and an allowlisted fix_action. Commerce-impacting advice is
		 * capped at `moderate` via normalize_eagerness() before this check;
		 * the `ai_speculation_excludes` / `ai_lcp_regression` items are
		 * informational `needs_improvement` list/string items, never
		 * auto-applied.
		 *
		 * @param mixed $s Candidate suggestion.
		 * @return bool True when the shape is valid.
		 * @since NEXT
		 */
		private static function is_valid_suggestion( $s ): bool {
			if ( ! is_array( $s ) ) {
				return false;
			}
			foreach ( array( 'metric', 'value', 'unit', 'status', 'description', 'fix_action' ) as $key ) {
				if ( ! array_key_exists( $key, $s ) ) {
					return false;
				}
			}
			if ( ! in_array( $s['status'], array( 'good', 'needs_improvement', 'poor' ), true ) ) {
				return false;
			}
			if ( class_exists( 'PerformanceOptimise\Inc\Suggestion_Engine' ) ) {
				if ( ! in_array( $s['fix_action'], Suggestion_Engine::VALID_FIX_ACTIONS, true ) ) {
					return false;
				}
			} elseif ( ! in_array( $s['fix_action'], array( 'open_object_cache_tab', 'open_image_optimization_tab', 'open_file_optimization_tab', 'open_ccss_settings', 'enable_server_rules', 'open_preload_tab', 'no_action_required' ), true ) ) {
				return false;
			}
			return true;
		}

		/**
		 * Learn from RUM + trends + disabled-script frequency.
		 *
		 * Local-only scorer by default with no remote calls; delegates to
		 * the WordPress AI client only when the explicit
		 * `ai_adaptive.use_wp_ai_client` opt-in is enabled and the client
		 * function exists; otherwise the local heuristic is used.
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

			if ( self::is_wp_ai_client_enabled() && function_exists( 'wp_ai_client' ) ) {
				$ai_model = self::learn_via_ai_client();
				if ( is_array( $ai_model ) && ! empty( $ai_model ) ) {
					$ai_model['source']     = 'ai_client';
					$ai_model['updated_at'] = time();
					// Guardrail (#908): the LLM payload is untrusted. Sanitize
					// fields at persist time mirroring the heuristic path, and
					// allowlist/cap eagerness (missing key defaults to
					// conservative so every stored model has a valid value).
					if ( isset( $ai_model['prefetch_urls'] ) ) {
						$urls                      = is_array( $ai_model['prefetch_urls'] ) ? array_filter( $ai_model['prefetch_urls'], 'is_string' ) : array();
						$ai_model['prefetch_urls'] = array_slice( array_values( array_filter( array_map( 'esc_url_raw', $urls ) ) ), 0, 2 );
					}
					foreach ( array( 'exclude_js', 'exclude_css' ) as $exclude_key ) {
						if ( isset( $ai_model[ $exclude_key ] ) ) {
							$handles                  = is_array( $ai_model[ $exclude_key ] ) ? array_filter( $ai_model[ $exclude_key ], 'is_string' ) : array();
							$ai_model[ $exclude_key ] = array_slice( array_values( array_filter( array_map( 'sanitize_text_field', $handles ) ) ), 0, 3 );
						}
					}
					$ai_model['eagerness'] = self::normalize_eagerness( $ai_model['eagerness'] ?? 'conservative' );
					$ai_model['version']   = isset( $ai_model['version'] ) ? (int) $ai_model['version'] : 1;
					// Persist only the known schema: drop unknown LLM keys so
					// the stored model shape stays predictable for readers.
					$ai_model = array_intersect_key(
						$ai_model,
						array(
							'version'       => 1,
							'prefetch_urls' => 1,
							'exclude_js'    => 1,
							'exclude_css'   => 1,
							'eagerness'     => 1,
							'source'        => 1,
							'updated_at'    => 1,
						)
					);
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
			if ( ! self::is_wp_ai_client_enabled() ) {
				return null;
			}
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
		 * Read segmented field-LCP p75 rows (device × template) fail-open.
		 *
		 * Thin wrapper over RUM::get_field_lcp_p75_by_segment() so
		 * heuristic_learn() degrades to the global-average path when RUM is
		 * unavailable. No option or transient writes; never throws.
		 *
		 * @since NEXT
		 * @param int $min_samples Minimum samples per segment (1 = observe all).
		 * @return array[] Rows of array(path,device,template,n,p75).
		 */
		private static function segmented_field_lcp( int $min_samples = 1 ): array {
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\RUM' ) || ! method_exists( 'PerformanceOptimise\Inc\RUM', 'get_field_lcp_p75_by_segment' ) ) {
					return array();
				}
				$rows = RUM::get_field_lcp_p75_by_segment( $min_samples );
				return is_array( $rows ) ? $rows : array();
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Resolve the field-LCP minimum-sample threshold for auto-tune.
		 *
		 * Delegates to RUM::get_field_lcp_min_samples() when available so the
		 * `ai_adaptive.field_lcp_min_samples` setting (with legacy
		 * `image_optimisation.fieldLcpMinSamples` fallback) is honoured in
		 * one place. Fail-open to 20.
		 *
		 * @since NEXT
		 * @return int Minimum samples (>=1).
		 */
		private static function field_lcp_min_samples(): int {
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\RUM' ) && method_exists( 'PerformanceOptimise\Inc\RUM', 'get_field_lcp_min_samples' ) ) {
					$min = (int) RUM::get_field_lcp_min_samples();
					if ( $min >= 1 ) {
						return $min;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			if ( class_exists( 'PerformanceOptimise\Inc\RUM' ) && defined( 'PerformanceOptimise\Inc\RUM::FIELD_LCP_DEFAULT_MIN_SAMPLES' ) ) {
				return (int) RUM::FIELD_LCP_DEFAULT_MIN_SAMPLES;
			}
			return 20;
		}

		/**
		 * Format a p75 millisecond value as seconds for suggestion copy.
		 *
		 * @since NEXT
		 * @param float $p75_ms p75 in milliseconds.
		 * @return string e.g. "3.8s".
		 */
		private static function format_p75_seconds( float $p75_ms ): string {
			// `%.1f` already rounds to one decimal, so the previous
			// `$seconds >= 10` branch returned the identical string.
			return sprintf( '%.1fs', $p75_ms / 1000.0 );
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
			$exclude_js  = self::get_disabled_assets( '_wppo_disabled_scripts' );
			$exclude_css = self::get_disabled_assets( '_wppo_disabled_styles' );

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

			// Field-data-driven tuning (issue #986): route segmented device ×
			// template p75 into the eagerness decision. Below the minimum
			// threshold the global-average path above is used unchanged with
			// no eagerness upgrade; at/above threshold the slowest-p75
			// segment may upgrade eagerness via the same ladder (never
			// downgrades the global result). Fail-open: RUM failures keep
			// the global path.
			$field_lcp_provisional = true;
			$field_lcp_segment     = null;
			$field_lcp_p75         = 0.0;
			$field_lcp_samples     = 0;
			$field_lcp_min         = self::field_lcp_min_samples();
			try {
				$observed = self::segmented_field_lcp( 1 );
				$max_n    = 0;
				foreach ( $observed as $row ) {
					if ( is_array( $row ) && isset( $row['n'] ) ) {
						$max_n = max( $max_n, (int) $row['n'] );
					}
				}
				$field_lcp_samples = $max_n;
				$qualified         = array();
				foreach ( $observed as $row ) {
					if ( is_array( $row ) && isset( $row['n'] ) && (int) $row['n'] >= $field_lcp_min ) {
						$qualified[] = $row;
					}
				}
				if ( ! empty( $qualified ) ) {
					usort(
						$qualified,
						static function ( $a, $b ) {
							$pa = isset( $a['p75'] ) ? (float) $a['p75'] : 0.0;
							$pb = isset( $b['p75'] ) ? (float) $b['p75'] : 0.0;
							if ( $pa === $pb ) {
								return 0;
							}
							return $pa > $pb ? -1 : 1;
						}
					);
					$slowest           = $qualified[0];
					$segment_p75       = isset( $slowest['p75'] ) ? (float) $slowest['p75'] : 0.0;
					$segment_eagerness = 'conservative';
					if ( $segment_p75 > 3500 ) {
						$segment_eagerness = 'eager';
					} elseif ( $segment_p75 > 2500 ) {
						$segment_eagerness = 'moderate';
					}
					// Upgrade-only: the segment may raise eagerness above the
					// global-average result, never lower it.
					$ladder = array(
						'conservative' => 0,
						'moderate'     => 1,
						'eager'        => 2,
					);
					if ( ( $ladder[ $segment_eagerness ] ?? 0 ) > ( $ladder[ $eagerness ] ?? 0 ) ) {
						$eagerness = $segment_eagerness;
					}
					$field_lcp_provisional = false;
					$field_lcp_segment     = array(
						'path'     => isset( $slowest['path'] ) ? (string) $slowest['path'] : '',
						'device'   => isset( $slowest['device'] ) ? (string) $slowest['device'] : 'unknown',
						'template' => isset( $slowest['template'] ) ? (string) $slowest['template'] : 'unknown',
					);
					$field_lcp_p75         = $segment_p75;
					$field_lcp_samples     = isset( $slowest['n'] ) ? (int) $slowest['n'] : $max_n;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
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
				'version'               => 1,
				'prefetch_urls'         => array_values( array_filter( $prefetch_urls ) ),
				'exclude_js'            => array_values( array_filter( $exclude_js ) ),
				'exclude_css'           => array_values( array_filter( $exclude_css ) ),
				'eagerness'             => $eagerness,
				'field_lcp_segment'     => $field_lcp_segment,
				'field_lcp_p75'         => $field_lcp_p75,
				'field_lcp_provisional' => $field_lcp_provisional,
				'field_lcp_samples'     => $field_lcp_samples,
				'field_lcp_min_samples' => $field_lcp_min,
			);
		}

		/**
		 * Detect LCP regressions from stored Web Vitals trend history.
		 *
		 * Rolling-baseline comparison per URL+strategy key: the latest sample
		 * ("current", last-1 for determinism) is compared against the mean of
		 * all prior numeric samples. A key regresses when current >= baseline
		 * * 1.3 (+30%). At most one anomaly overall is returned (first
		 * regressed key in iteration order) so dashboards surface a single
		 * read-only suggestion instead of a flood.
		 *
		 * Local computation only: no remote calls, no API keys, no option or
		 * transient writes. Fail-open: under-sampled history (<10 numeric
		 * samples), short history (<2 usable windows), non-positive baseline,
		 * or any failure returns an empty array — never fatal.
		 *
		 * Trend source is Pagespeed::get_trends() (capped 30/URL+strategy);
		 * RUM::get_data() aggregates are intentionally not used here (daily
		 * per-day/per-path aggregates with a transient-locked write path).
		 *
		 * @param array|null $trends Optional trends map for testability. When null, reads Pagespeed::get_trends().
		 * @return array[] At most one anomaly: array(array('key'=>string,'baseline'=>float,'current'=>float,'change_pct'=>float)).
		 * @since NEXT
		 */
		public static function detect_anomalies( ?array $trends = null ): array {
			try {
				if ( null === $trends ) {
					if ( ! class_exists( 'PerformanceOptimise\Inc\Pagespeed' ) ) {
						return array();
					}
					$trends = Pagespeed::get_trends();
				}
				if ( ! is_array( $trends ) || empty( $trends ) ) {
					return array();
				}
				foreach ( $trends as $trend_key => $snapshots ) {
					if ( ! is_array( $snapshots ) ) {
						continue;
					}
					$lcps = array();
					foreach ( $snapshots as $snapshot ) {
						if ( ! is_array( $snapshot ) || ! isset( $snapshot['lcp'] ) ) {
							continue;
						}
						$lcp = $snapshot['lcp'];
						if ( ! is_numeric( $lcp ) ) {
							continue;
						}
						$lcp = (float) $lcp;
						if ( $lcp <= 0 ) {
							continue;
						}
						$lcps[] = $lcp;
					}
					// Fail-open: under-sampled history never alarms.
					if ( count( $lcps ) < 10 ) {
						continue;
					}
					// Need baseline + current windows.
					if ( count( $lcps ) < 2 ) {
						continue;
					}
					$current  = (float) end( $lcps );
					$prior    = array_slice( $lcps, 0, -1 );
					$baseline = array_sum( $prior ) / count( $prior );
					if ( $baseline <= 0 ) {
						continue;
					}
					if ( $current >= $baseline * 1.3 ) {
						$change_pct = ( $current - $baseline ) / $baseline * 100.0;
						$anomaly    = array(
							'key'        => (string) $trend_key,
							'baseline'   => (float) $baseline,
							'current'    => (float) $current,
							'change_pct' => (float) $change_pct,
						);
						/**
						 * Filters the detected LCP regression anomalies.
						 *
						 * @since NEXT
						 * @param array[] $anomalies At most one anomaly array.
						 */
						$filtered = apply_filters( 'wppo_ai_lcp_regression', array( $anomaly ) );
						if ( ! is_array( $filtered ) ) {
							return array( $anomaly );
						}
						// Cap to a single anomaly even if a filter appends more.
						return array_slice( array_values( $filtered ), 0, 1 );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
			return array();
		}

		/**
		 * Per-request memo of disabled-asset aggregates keyed by meta key (audit #982).
		 *
		 * @since NEXT
		 * @var array<string, string[]>
		 */
		private static array $disabled_assets_cache = array();

		/**
		 * Reset the per-request disabled-asset memo (for testing).
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function reset_disabled_assets_cache(): void {
			self::$disabled_assets_cache = array();
		}

		/**
		 * Rank serialized handle lists into top-3 handles by frequency.
		 *
		 * @since NEXT
		 * @param array $rows Raw meta_value strings.
		 * @return string[]
		 */
		private static function top_disabled_handles( array $rows ): array {
			$disabled = array();
			foreach ( $rows as $row ) {
				$val = maybe_unserialize( $row );
				if ( ! is_array( $val ) ) {
					continue;
				}
				foreach ( $val as $handle ) {
					$handle = sanitize_text_field( (string) $handle );
					if ( '' === $handle ) {
						continue;
					}
					$disabled[ $handle ] = ( $disabled[ $handle ] ?? 0 ) + 1;
				}
			}
			if ( empty( $disabled ) ) {
				return array();
			}
			arsort( $disabled );
			return array_slice( array_keys( $disabled ), 0, 3 );
		}

		/**
		 * Get most-frequently disabled assets from postmeta.
		 *
		 * Both known keys are fetched in a single UNION ALL round-trip and
		 * memoized per request (audit #982).
		 *
		 * @param string $meta_key The meta key to query.
		 * @return string[]
		 * @since NEXT
		 */
		private static function get_disabled_assets( string $meta_key ): array {
			if ( array_key_exists( $meta_key, self::$disabled_assets_cache ) ) {
				return self::$disabled_assets_cache[ $meta_key ];
			}
			global $wpdb;
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) ) {
				self::$disabled_assets_cache[ $meta_key ] = array();
				return array();
			}
			$keys      = array( '_wppo_disabled_scripts', '_wppo_disabled_styles' );
			$extra_key = ! in_array( $meta_key, $keys, true ) ? $meta_key : '';
			$grouped   = array();
			foreach ( $keys as $k ) {
				$grouped[ $k ] = array();
			}
			// Single round-trip for both known keys (UNION ALL of two LIMIT
			// 500 selects preserves the original per-key LIMIT semantics).
			if ( method_exists( $wpdb, 'get_results' ) ) {
				try {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$rows = $wpdb->get_results(
						$wpdb->prepare(
							"(SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 500) UNION ALL (SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 500)",
							$keys[0],
							$keys[1]
						),
						ARRAY_A
					);
					if ( is_array( $rows ) ) {
						foreach ( $rows as $row ) {
							$k = is_array( $row ) ? ( $row['meta_key'] ?? '' ) : '';
							if ( ! array_key_exists( $k, $grouped ) ) {
								continue;
							}
							if ( count( $grouped[ $k ] ) >= 500 ) {
								continue;
							}
							$grouped[ $k ][] = $row['meta_value'];
						}
					}
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				}
			}
			// Fallback when get_results() is unavailable (e.g. partial $wpdb
			// doubles in tests): per-key get_col() with identical semantics.
			if ( ! method_exists( $wpdb, 'get_results' ) && method_exists( $wpdb, 'get_col' ) ) {
				foreach ( $keys as $k ) {
					try {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$single = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 500", $k ) );
						if ( is_array( $single ) ) {
							$grouped[ $k ] = $single;
						}
					} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					}
				}
			}
			foreach ( $grouped as $k => $values ) {
				self::$disabled_assets_cache[ $k ] = self::top_disabled_handles( $values );
			}
			// Defensive fallback for unknown keys: single-key query, same
			// LIMIT 500 + ranking semantics as before.
			if ( '' !== $extra_key && ! array_key_exists( $extra_key, self::$disabled_assets_cache ) ) {
				$extra_rows = array();
				if ( method_exists( $wpdb, 'get_col' ) ) {
					try {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$extra = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 500", $extra_key ) );
						if ( is_array( $extra ) ) {
							$extra_rows = $extra;
						}
					} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					}
				}
				self::$disabled_assets_cache[ $extra_key ] = self::top_disabled_handles( $extra_rows );
			}
			if ( ! array_key_exists( $meta_key, self::$disabled_assets_cache ) ) {
				self::$disabled_assets_cache[ $meta_key ] = array();
			}
			return self::$disabled_assets_cache[ $meta_key ];
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
			$urls = array_values( array_filter( array_map( 'esc_url_raw', array_filter( $urls, 'is_string' ) ) ) );
			// Guardrail (#908): never AI-prefetch commerce/auth URLs in commerce
			// contexts. Explicit list-source rules bypass href exclude-path
			// filtering, so a RUM/LLM-nominated /checkout/ would otherwise be
			// injected verbatim (the eagerness cap alone cannot prevent it).
			if ( ! empty( $urls ) && self::is_commerce_or_auth_context() ) {
				$excludes = self::get_commerce_exclude_paths();
				$urls     = array_values(
					array_filter(
						$urls,
						static function ( $url ) use ( $excludes ) {
							$path = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_PATH ) : null;
							$path = is_string( $path ) && '' !== $path ? rtrim( $path, '/' ) : rtrim( $url, '/' );
							foreach ( $excludes as $exclude ) {
								$prefix = rtrim( rtrim( $exclude, '*' ), '/' );
								if ( '' !== $prefix && ( $path === $prefix || 0 === strpos( $path . '/', $prefix . '/' ) ) ) {
									return false;
								}
							}
							return true;
						}
					)
				);
			}
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
			// Field LCP segment context (issue #986): prefer persisted model
			// keys, fall back to a live read-only lookup for models stored
			// before the segmentation shipped. Fail-open to provisional.
			$field_segment     = isset( $model['field_lcp_segment'] ) && is_array( $model['field_lcp_segment'] ) ? $model['field_lcp_segment'] : null;
			$field_p75         = isset( $model['field_lcp_p75'] ) ? (float) $model['field_lcp_p75'] : 0.0;
			$field_provisional = array_key_exists( 'field_lcp_provisional', $model ) ? (bool) $model['field_lcp_provisional'] : true;
			$field_samples     = isset( $model['field_lcp_samples'] ) ? (int) $model['field_lcp_samples'] : 0;
			$field_min         = isset( $model['field_lcp_min_samples'] ) ? (int) $model['field_lcp_min_samples'] : self::field_lcp_min_samples();
			if ( $field_min < 1 ) {
				$field_min = self::field_lcp_min_samples();
			}
			if ( null === $field_segment || $field_provisional ) {
				try {
					$live = self::segmented_field_lcp( $field_min );
					if ( ! empty( $live ) && is_array( $live[0] ) ) {
						$top               = $live[0];
						$field_segment     = array(
							'path'     => isset( $top['path'] ) ? (string) $top['path'] : '',
							'device'   => isset( $top['device'] ) ? (string) $top['device'] : 'unknown',
							'template' => isset( $top['template'] ) ? (string) $top['template'] : 'unknown',
						);
						$field_p75         = isset( $top['p75'] ) ? (float) $top['p75'] : 0.0;
						$field_samples     = isset( $top['n'] ) ? (int) $top['n'] : 0;
						$field_provisional = false;
					} else {
						$observed = self::segmented_field_lcp( 1 );
						$max_n    = 0;
						foreach ( $observed as $row ) {
							if ( is_array( $row ) && isset( $row['n'] ) ) {
								$max_n = max( $max_n, (int) $row['n'] );
							}
						}
						$field_samples     = max( $field_samples, $max_n );
						$field_provisional = true;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( 'conservative' !== $eagerness ) {
				$eagerness_value       = $eagerness;
				$eagerness_description = __( 'AI: Speculation eagerness suggestion', 'performance-optimisation' );
				if ( ! $field_provisional && is_array( $field_segment ) ) {
					$seg_device   = isset( $field_segment['device'] ) ? (string) $field_segment['device'] : 'unknown';
					$seg_template = isset( $field_segment['template'] ) ? (string) $field_segment['template'] : 'unknown';
					/* translators: %1$s eagerness, %2$s device, %3$s template, %4$s p75. */
					$eagerness_value = sprintf( __( '%1$s · %2$s · %3$s · p75 %4$s', 'performance-optimisation' ), $eagerness, $seg_device, $seg_template, self::format_p75_seconds( $field_p75 ) );
					/* translators: %1$s device, %2$s template, %3$s p75 seconds. */
					$eagerness_description = sprintf( __( 'AI: Speculation eagerness suggestion (field LCP %1$s/%2$s p75 %3$s)', 'performance-optimisation' ), $seg_device, $seg_template, self::format_p75_seconds( $field_p75 ) );
				}
				$suggestions[] = array(
					'metric'      => 'ai_speculation_eagerness',
					'value'       => $eagerness_value,
					'unit'        => 'string',
					'status'      => 'needs_improvement',
					'description' => $eagerness_description,
					'fix_action'  => 'open_preload_tab',
					'ai_payload'  => array(
						'tab'      => 'preload_settings',
						'settings' => array( 'speculationEagerness' => $eagerness ),
					),
				);
			}

			// Field LCP auto-tune suggestion (issue #986): at/above threshold
			// the copy names device + template + p75; below threshold the
			// copy reads provisional with no eagerness upgrade.
			if ( ! $field_provisional && is_array( $field_segment ) ) {
				$seg_device   = isset( $field_segment['device'] ) ? (string) $field_segment['device'] : 'unknown';
				$seg_template = isset( $field_segment['template'] ) ? (string) $field_segment['template'] : 'unknown';
				/* translators: %1$s device, %2$s template, %3$s p75 seconds. */
				$field_value = sprintf( __( '%1$s · %2$s · p75 %3$s', 'performance-optimisation' ), $seg_device, $seg_template, self::format_p75_seconds( $field_p75 ) );
				/* translators: %1$s device, %2$s template, %3$s p75 seconds, %4$d sample count. */
				$field_description = sprintf( __( 'AI: Field LCP tune for %1$s/%2$s (p75 %3$s, %4$d samples)', 'performance-optimisation' ), $seg_device, $seg_template, self::format_p75_seconds( $field_p75 ), $field_samples );
				$suggestions[]     = array(
					'metric'      => 'ai_field_lcp_tune',
					'value'       => $field_value,
					'unit'        => 'string',
					'status'      => 'needs_improvement',
					'description' => $field_description,
					'fix_action'  => 'open_preload_tab',
					'ai_payload'  => array(
						'tab'      => 'preload_settings',
						'settings' => array(),
					),
				);
			} else {
				/* translators: %1$d observed samples, %2$d required samples. */
				$provisional_value = sprintf( __( 'provisional (%1$d/%2$d samples)', 'performance-optimisation' ), $field_samples, $field_min );
				/* translators: %1$d observed samples, %2$d required samples. */
				$provisional_description = sprintf( __( 'AI: Field LCP provisional (%1$d/%2$d samples) — collecting data', 'performance-optimisation' ), $field_samples, $field_min );
				$suggestions[]           = array(
					'metric'      => 'ai_field_lcp_tune',
					'value'       => $provisional_value,
					'unit'        => 'string',
					'status'      => 'needs_improvement',
					'description' => $provisional_description,
					'fix_action'  => 'open_preload_tab',
					'ai_payload'  => array(
						'tab'      => 'preload_settings',
						'settings' => array(),
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
				// Normalized line-by-line compare (full URLs reduced to path-only,
				// trim + trailing-slash and wildcard insensitive) so formatting
				// variants do not re-suggest.
				$normalize_path = static function ( $path ) {
					$path   = trim( (string) $path );
					$parsed = function_exists( 'wp_parse_url' ) ? wp_parse_url( $path, PHP_URL_PATH ) : null;
					if ( is_string( $parsed ) && '' !== $parsed ) {
						$path = $parsed;
					}
					return rtrim( rtrim( rtrim( $path, '/' ), '*' ), '/' );
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

			// Self-watching performance: surface a single read-only suggestion on
			// +30% LCP regression. Fail-open: detector errors contribute zero
			// suggestions (never fatal, never white-screen). No auto-tune, no
			// speculation override here — that stays gated by is_enabled() in
			// filter_speculation_rules().
			try {
				$anomalies = self::detect_anomalies();
			} catch ( \Throwable $e ) {
				unset( $e );
				$anomalies = array();
			}
			if ( is_array( $anomalies ) && ! empty( $anomalies ) ) {
				$anomaly    = $anomalies[0];
				$change_pct = isset( $anomaly['change_pct'] ) ? (float) $anomaly['change_pct'] : 0.0;
				/* translators: %d is the LCP percentage increase vs baseline. */
				$value         = sprintf( __( 'LCP +%d%% vs baseline', 'performance-optimisation' ), (int) round( $change_pct ) );
				$suggestions[] = array(
					'metric'      => 'ai_lcp_regression',
					'value'       => $value,
					'unit'        => 'string',
					'status'      => 'needs_improvement',
					'description' => __( 'AI: LCP regression detected', 'performance-optimisation' ),
					'fix_action'  => 'open_image_optimization_tab',
					'ai_payload'  => array(
						'tab'      => 'image_optimisation',
						'settings' => array(),
					),
				);
			}

			// Ensure fix_action is valid per Suggestion_Engine guard (already valid).
			$suggestions = array_values( array_filter( $suggestions, array( self::class, 'is_valid_suggestion' ) ) );
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
			// Same-site guard: never inject cross-site/admin/commerce URLs, and
			// dedupe against list-source URLs already present (Main's
			// high-value list runs at priority 10, AI at 20).
			$urls = self::filter_same_site_urls( $urls );
			$urls = self::dedupe_against_existing_lists( $urls, $rules );
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
		 * Keep only same-site, non-admin, non-commerce list URLs.
		 *
		 * Prevents multisite cross-site leakage and transactional/admin
		 * prefetch when a RUM/LLM-nominated URL is off-site or sensitive.
		 * Invalid entries are skipped individually (fail-open).
		 *
		 * @param string[] $urls Candidate URLs.
		 * @return string[]
		 * @since NEXT
		 */
		private static function filter_same_site_urls( array $urls ): array {
			$home = Util::cached_home_url();
			if ( '' === $home ) {
				return array();
			}
			$home_host = wp_parse_url( $home, PHP_URL_HOST );
			if ( ! is_string( $home_host ) || '' === $home_host ) {
				return array();
			}
			$kept = array();
			foreach ( $urls as $url ) {
				if ( ! is_string( $url ) || '' === $url ) {
					continue;
				}
				$parts = wp_parse_url( $url );
				if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
					continue;
				}
				if ( strtolower( (string) $parts['host'] ) !== strtolower( $home_host ) ) {
					continue;
				}
				$path  = strtolower( (string) ( $parts['path'] ?? '/' ) );
				$lower = strtolower( $url );
				if ( false !== strpos( $path, '/wp-admin' ) || false !== strpos( $lower, 'wp-login.php' ) || false !== strpos( $path, '/wp-json' ) ) {
					continue;
				}
				$kept[] = $url;
			}
			return array_values( $kept );
		}

		/**
		 * Remove URLs already covered by an existing list-source rule.
		 *
		 * @param string[] $urls  Candidate AI URLs.
		 * @param array    $rules Existing speculation rules.
		 * @return string[]
		 * @since NEXT
		 */
		private static function dedupe_against_existing_lists( array $urls, array $rules ): array {
			$existing = array();
			foreach ( $rules as $rule ) {
				if ( ! is_array( $rule ) || ( $rule['source'] ?? '' ) !== 'list' ) {
					continue;
				}
				$rule_urls = $rule['urls'] ?? array();
				if ( ! is_array( $rule_urls ) ) {
					continue;
				}
				foreach ( $rule_urls as $existing_url ) {
					if ( is_string( $existing_url ) && '' !== $existing_url ) {
						$existing[] = $existing_url;
					}
				}
			}
			if ( empty( $existing ) ) {
				return array_values( $urls );
			}
			return array_values( array_diff( $urls, $existing ) );
		}

		/**
		 * Register hooks.
		 *
		 * @return void
		 * @since NEXT
		 */
		public static function init(): void {
			if ( function_exists( 'wp_get_speculation_rules' ) ) {
				add_filter( 'wp_speculation_rules', array( self::class, 'filter_speculation_rules' ), 20 );
			}
		}
	}
}
