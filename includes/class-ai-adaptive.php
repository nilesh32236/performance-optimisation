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

			// Load both aggregates once per learn run so the AI fallback to
			// the heuristic path does not deserialize the large options
			// twice in one run.
			$rum    = get_option( RUM::OPTION, array() );
			$trends = get_option( Pagespeed::TREND_OPTION, array() );
			if ( ! is_array( $rum ) ) {
				$rum = array();
			}
			if ( ! is_array( $trends ) ) {
				$trends = array();
			}

			if ( self::is_wp_ai_client_enabled() && function_exists( 'wp_ai_client' ) ) {
				$ai_model = self::learn_via_ai_client( $rum, $trends );
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

			$model               = self::heuristic_learn( $rum, $trends );
			$model['source']     = 'heuristic';
			$model['updated_at'] = time();
			self::update_model( $model );
			return $model;
		}

		/**
		 * Attempt learning via WP 7.0 AI Client when available.
		 *
		 * @param array|null $rum Optional pre-loaded RUM aggregate (loaded once per learn run).
		 * @param array|null $trends Optional pre-loaded trends aggregate.
		 * @return array|null Model or null on fallback.
		 * @since NEXT
		 */
		private static function learn_via_ai_client( ?array $rum = null, ?array $trends = null ): ?array {
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
				if ( null === $rum ) {
					$rum = get_option( RUM::OPTION, array() ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.option_option -- AI model needs cross-signal input.
					if ( ! is_array( $rum ) ) {
						$rum = array();
					}
				}
				if ( null === $trends ) {
					$trends = get_option( Pagespeed::TREND_OPTION, array() ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.option_option -- AI model needs cross-signal input.
					if ( ! is_array( $trends ) ) {
						$trends = array();
					}
				}
				// Send a summarized projection (top paths/segments) instead
				// of the raw aggregates so a ~480KB option is not duplicated
				// in memory via wp_json_encode on the learn path.
				$summary = self::summarize_aggregates_for_prompt( $rum, $trends );
				$prompt  = 'Given RUM aggregates and trends, suggest top 2 prefetch URLs, least-used scripts to exclude, and speculation eagerness (conservative|moderate|eager) as JSON.';
				$result  = $client->prompt( $prompt . ' RUM:' . wp_json_encode( $summary['rum'] ) . ' Trends:' . wp_json_encode( $summary['trends'] ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.option_option -- AI model needs cross-signal input.
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
		 * INP p75 threshold (ms) gating delay-JS suggestions.
		 *
		 * Matches the Core Web Vitals "needs improvement" boundary (>200ms):
		 * delay is suggested only when real-user INP p75 crosses it with
		 * sufficient samples. Poor INP (>500ms) maps to the `eager` level
		 * (capped at `moderate` in commerce/auth contexts).
		 *
		 * @since NEXT
		 * @var float
		 */
		public const INP_P75_DELAY_THRESHOLD_MS = 200.0;

		/**
		 * INP p75 threshold (ms) for the `eager` delay level.
		 *
		 * Matches the Core Web Vitals "poor" boundary (>500ms).
		 *
		 * @since NEXT
		 * @var float
		 */
		public const INP_P75_EAGER_THRESHOLD_MS = 500.0;

		/**
		 * LCP p75 threshold (ms) gating delay-JS suggestions.
		 *
		 * Mirrors the speculation eagerness ladder (>2500ms moderate,
		 * >3500ms eager) so heavy pages also surface a delay suggestion.
		 *
		 * @since NEXT
		 * @var float
		 */
		public const LCP_P75_DELAY_THRESHOLD_MS = 2500.0;

		/**
		 * LCP p75 threshold (ms) for the `eager` delay level.
		 *
		 * @since NEXT
		 * @var float
		 */
		public const LCP_P75_EAGER_THRESHOLD_MS = 3500.0;

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
		 * Read segmented field-INP p75 rows (device × template) fail-open.
		 *
		 * Thin wrapper over RUM::get_field_inp_p75_by_segment() so
		 * heuristic_learn() degrades gracefully when RUM is unavailable.
		 * No option or transient writes; never throws.
		 *
		 * @since NEXT
		 * @param int $min_samples Minimum samples per segment (1 = observe all).
		 * @return array[] Rows of array(path,device,template,n,p75).
		 */
		private static function segmented_field_inp( int $min_samples = 1 ): array {
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\RUM' ) || ! method_exists( 'PerformanceOptimise\Inc\RUM', 'get_field_inp_p75_by_segment' ) ) {
					return array();
				}
				$rows = RUM::get_field_inp_p75_by_segment( $min_samples );
				return is_array( $rows ) ? $rows : array();
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Dismissed AI suggestion metrics (persisted, per-site).
		 *
		 * Stored additively in `wppo_settings[ai_adaptive][dismissed_suggestions]`
		 * (array of metric strings). Dismissing is read-only w.r.t. frontend
		 * behavior: it only hides the suggestion card until cleared from settings.
		 * Local-only: no remote calls, no PII. Fail-open: any failure returns array().
		 *
		 * @since NEXT
		 * @return string[] Dismissed metric identifiers.
		 */
		public static function get_dismissed_suggestions(): array {
			try {
				$settings  = class_exists( 'PerformanceOptimise\Inc\Util' ) ? Util::get_settings() : array();
				$dismissed = $settings['ai_adaptive']['dismissed_suggestions'] ?? array();
				if ( ! is_array( $dismissed ) ) {
					return array();
				}
				$clean = array();
				foreach ( $dismissed as $metric ) {
					if ( ! is_string( $metric ) || '' === trim( $metric ) ) {
						continue;
					}
					$metric = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $metric ) : trim( $metric );
					if ( '' !== $metric ) {
						$clean[] = substr( $metric, 0, 64 );
					}
				}
				return array_values( array_unique( $clean ) );
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Whether an AI suggestion metric has been dismissed.
		 *
		 * @since NEXT
		 * @param string $metric Suggestion metric identifier.
		 * @return bool True when dismissed.
		 */
		public static function is_suggestion_dismissed( string $metric ): bool {
			try {
				if ( '' === $metric ) {
					return false;
				}
				return in_array( $metric, self::get_dismissed_suggestions(), true );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * RUM-gated, INP-aware delay-JS state (read-only, fail-open).
		 *
		 * Emits a delay suggestion only when real-user p75 crosses a
		 * threshold with sufficient samples: n >= min (shared
		 * `ai_adaptive.field_lcp_min_samples` gate, default 20) AND
		 * (INP p75 > 200ms OR LCP p75 > 2500ms). Below threshold, on error,
		 * or when the RUM class is unavailable the state is provisional with
		 * `qualified=false` so callers emit nothing. No external calls, no
		 * PII stored, no option/transient writes; multisite-safe via the
		 * per-site RUM aggregate. Commerce/auth contexts cap the level at
		 * `moderate` (never `eager`) via maybe_cap_eagerness().
		 *
		 * @since NEXT
		 * @return array{qualified:bool,level:string,inp_p75:float,lcp_p75:float,samples:int,min_samples:int,provisional:bool,segment:array|null} Gated state.
		 */
		public static function get_rum_gated_delay_state(): array {
			$fallback = array(
				'qualified'   => false,
				'level'       => 'conservative',
				'inp_p75'     => 0.0,
				'lcp_p75'     => 0.0,
				'samples'     => 0,
				'min_samples' => 20,
				'provisional' => true,
				'segment'     => null,
			);
			try {
				$min                     = self::field_lcp_min_samples();
				$fallback['min_samples'] = $min;

				$inp_rows = self::segmented_field_inp( 1 );
				$lcp_rows = self::segmented_field_lcp( 1 );

				$max_n = 0;
				foreach ( array_merge( $inp_rows, $lcp_rows ) as $row ) {
					if ( is_array( $row ) && isset( $row['n'] ) ) {
						$max_n = max( $max_n, (int) $row['n'] );
					}
				}
				$fallback['samples'] = $max_n;

				$qualified_inp = array();
				foreach ( $inp_rows as $row ) {
					if ( is_array( $row ) && isset( $row['n'] ) && (int) $row['n'] >= $min ) {
						$qualified_inp[] = $row;
					}
				}
				$qualified_lcp = array();
				foreach ( $lcp_rows as $row ) {
					if ( is_array( $row ) && isset( $row['n'] ) && (int) $row['n'] >= $min ) {
						$qualified_lcp[] = $row;
					}
				}
				if ( empty( $qualified_inp ) && empty( $qualified_lcp ) ) {
					return $fallback;
				}

				$top_inp = ! empty( $qualified_inp ) ? $qualified_inp[0] : null;
				$top_lcp = ! empty( $qualified_lcp ) ? $qualified_lcp[0] : null;
				$inp_p75 = ( is_array( $top_inp ) && isset( $top_inp['p75'] ) ) ? (float) $top_inp['p75'] : 0.0;
				$lcp_p75 = ( is_array( $top_lcp ) && isset( $top_lcp['p75'] ) ) ? (float) $top_lcp['p75'] : 0.0;

				$crosses_inp = $inp_p75 > self::INP_P75_DELAY_THRESHOLD_MS;
				$crosses_lcp = $lcp_p75 > self::LCP_P75_DELAY_THRESHOLD_MS;
				if ( ! $crosses_inp && ! $crosses_lcp ) {
					$fallback['inp_p75'] = $inp_p75;
					$fallback['lcp_p75'] = $lcp_p75;
					return $fallback;
				}

				$level = 'moderate';
				if ( $inp_p75 > self::INP_P75_EAGER_THRESHOLD_MS || $lcp_p75 > self::LCP_P75_EAGER_THRESHOLD_MS ) {
					$level = 'eager';
				}
				// Guardrail: commerce/auth contexts never suggest eager.
				$level = self::maybe_cap_eagerness( $level );
				if ( ! in_array( $level, array( 'conservative', 'moderate', 'eager' ), true ) ) {
					$level = 'moderate';
				}

				// Anchor the copy on the crossed signal (prefer INP, the
				// delay-JS lever); fall back to the LCP segment otherwise.
				$anchor  = ( $crosses_inp && is_array( $top_inp ) ) ? $top_inp : $top_lcp;
				$segment = null;
				if ( is_array( $anchor ) ) {
					$segment = array(
						'path'     => isset( $anchor['path'] ) ? (string) $anchor['path'] : '',
						'device'   => isset( $anchor['device'] ) ? (string) $anchor['device'] : 'unknown',
						'template' => isset( $anchor['template'] ) ? (string) $anchor['template'] : 'unknown',
					);
				}
				$samples = 0;
				if ( is_array( $anchor ) && isset( $anchor['n'] ) ) {
					$samples = (int) $anchor['n'];
				}

				return array(
					'qualified'   => true,
					'level'       => $level,
					'inp_p75'     => $inp_p75,
					'lcp_p75'     => $lcp_p75,
					'samples'     => $samples,
					'min_samples' => $min,
					'provisional' => false,
					'segment'     => $segment,
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return $fallback;
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
		 * Summarize large aggregates into a bounded prompt projection.
		 *
		 * Keeps only the slowest/most-sampled paths (top 10) with averaged
		 * LCP/TTFB and sample counts, plus the latest trends snapshot per
		 * key (capped at 10 keys), so the model prompt stays small.
		 *
		 * @since NEXT
		 * @param array $rum RUM aggregate.
		 * @param array $trends Trends aggregate.
		 * @return array{rum:array,trends:array} Bounded summary.
		 */
		private static function summarize_aggregates_for_prompt( array $rum, array $trends ): array {
			$scores = array();
			foreach ( $rum as $paths ) {
				if ( ! is_array( $paths ) ) {
					continue;
				}
				foreach ( $paths as $path => $metrics ) {
					if ( ! is_array( $metrics ) ) {
						continue;
					}
					$lcp_n    = isset( $metrics['lcp']['n'] ) ? (int) $metrics['lcp']['n'] : 0;
					$lcp_sum  = isset( $metrics['lcp']['sum'] ) ? (float) $metrics['lcp']['sum'] : 0;
					$ttfb_n   = isset( $metrics['ttfb']['n'] ) ? (int) $metrics['ttfb']['n'] : 0;
					$ttfb_sum = isset( $metrics['ttfb']['sum'] ) ? (float) $metrics['ttfb']['sum'] : 0;
					$avg_lcp  = $lcp_n > 0 ? $lcp_sum / $lcp_n : 0;
					$avg_ttfb = $ttfb_n > 0 ? $ttfb_sum / $ttfb_n : 0;
					$path_key = is_string( $path ) ? substr( $path, 0, 128 ) : '';
					if ( '' === $path_key ) {
						continue;
					}
					if ( ! isset( $scores[ $path_key ] ) ) {
						$scores[ $path_key ] = array(
							'path'     => $path_key,
							'avg_lcp'  => 0,
							'avg_ttfb' => 0,
							'samples'  => 0,
						);
					}
					$scores[ $path_key ]['avg_lcp']  = max( $scores[ $path_key ]['avg_lcp'], $avg_lcp );
					$scores[ $path_key ]['avg_ttfb'] = max( $scores[ $path_key ]['avg_ttfb'], $avg_ttfb );
					$scores[ $path_key ]['samples'] += max( $lcp_n, $ttfb_n );
				}
			}
			usort(
				$scores,
				static function ( $a, $b ) {
					$by_samples = $b['samples'] <=> $a['samples'];
					if ( 0 !== $by_samples ) {
						return $by_samples;
					}
					return $b['avg_lcp'] <=> $a['avg_lcp'];
				}
			);
			$rum_summary = array_slice( array_values( $scores ), 0, 10 );

			$trends_summary = array();
			$count          = 0;
			foreach ( $trends as $key => $snapshots ) {
				if ( $count >= 10 ) {
					break;
				}
				if ( ! is_array( $snapshots ) || empty( $snapshots ) ) {
					continue;
				}
				$last = end( $snapshots );
				if ( ! is_array( $last ) ) {
					continue;
				}
				$trends_summary[] = array(
					'performance' => isset( $last['performance'] ) ? (int) $last['performance'] : 0,
					'lcp'         => isset( $last['lcp'] ) ? (float) $last['lcp'] : null,
					'cls'         => isset( $last['cls'] ) ? (float) $last['cls'] : null,
				);
				++$count;
			}

			return array(
				'rum'    => $rum_summary,
				'trends' => $trends_summary,
			);
		}

		/**
		 * Heuristic fallback learning.
		 *
		 * @param array|null $rum Optional pre-loaded RUM aggregate.
		 * @param array|null $trends Optional pre-loaded trends aggregate.
		 * @return array
		 * @since NEXT
		 */
		private static function heuristic_learn( ?array $rum = null, ?array $trends = null ): array {
			if ( null === $rum ) {
				$rum = get_option( RUM::OPTION, array() );
			}
			if ( null === $trends ) {
				$trends = get_option( Pagespeed::TREND_OPTION, array() );
			}
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
			// Upgrade-only: when the global-average path already sits at the top
			// of the eagerness ladder (`eager`), the segmented lookup can never
			// raise it, so skip the full RUM option scan entirely.
			if ( 'eager' !== $eagerness ) {
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
			}

			// RUM-gated INP-aware delay state (issue #1036): read-only, opt-in
			// via ai_adaptive.enabled; qualified only at n>=min with INP/LCP
			// p75 crossed. Fail-open: provisional/empty on error, never fatal,
			// no external calls, no PII.
			$delay_state = array(
				'qualified'   => false,
				'level'       => 'conservative',
				'inp_p75'     => 0.0,
				'lcp_p75'     => 0.0,
				'samples'     => 0,
				'min_samples' => $field_lcp_min,
				'provisional' => true,
				'segment'     => null,
			);
			try {
				$live_delay = self::get_rum_gated_delay_state();
				if ( is_array( $live_delay ) && ! empty( $live_delay ) ) {
					$delay_state = array_merge( $delay_state, $live_delay );
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
				'delay_js_level'        => isset( $delay_state['level'] ) ? (string) $delay_state['level'] : 'conservative',
				'delay_inp_p75'         => isset( $delay_state['inp_p75'] ) ? (float) $delay_state['inp_p75'] : 0.0,
				'delay_lcp_p75'         => isset( $delay_state['lcp_p75'] ) ? (float) $delay_state['lcp_p75'] : 0.0,
				'delay_samples'         => isset( $delay_state['samples'] ) ? (int) $delay_state['samples'] : 0,
				'delay_min_samples'     => isset( $delay_state['min_samples'] ) ? (int) $delay_state['min_samples'] : $field_lcp_min,
				'delay_provisional'     => ! ( isset( $delay_state['qualified'] ) && $delay_state['qualified'] ),
				'delay_qualified'       => ! empty( $delay_state['qualified'] ),
				'delay_segment'         => isset( $delay_state['segment'] ) && is_array( $delay_state['segment'] ) ? $delay_state['segment'] : null,
			);
		}

		/**
		 * Option storing the last anomaly alarm timestamp (autoload=no).
		 *
		 * Per-site option, hence inherently multisite-safe.
		 *
		 * @since NEXT
		 * @var string
		 */
		private const ANOMALY_COOLDOWN_KEY = 'wppo_ai_anomaly_last_alarm';

		/**
		 * Default anomaly cooldown in days (single banner max).
		 *
		 * @since NEXT
		 * @var int
		 */
		private const ANOMALY_COOLDOWN_DAYS = 7;

		/**
		 * Default minimum numeric samples before an arm may fire.
		 *
		 * @since NEXT
		 * @var int
		 */
		private const ANOMALY_MIN_SAMPLES = 10;

		/**
		 * CLS regression arm threshold as an absolute delta (not percent).
		 *
		 * @since NEXT
		 * @var float
		 */
		private const CLS_ABSOLUTE_DELTA = 0.05;

		/**
		 * LCP regression arm threshold as a relative multiplier (+30%).
		 *
		 * @since NEXT
		 * @var float
		 */
		private const LCP_RELATIVE_MULTIPLIER = 1.3;

		/**
		 * Resolve the anomaly minimum-sample threshold.
		 *
		 * Reads the additive `ai_adaptive.anomaly_min_samples` setting,
		 * falling back to ANOMALY_MIN_SAMPLES. Filterable via
		 * `wppo_ai_anomaly_min_samples`. Fail-open to 10.
		 *
		 * @return int Minimum samples (>=1).
		 * @since NEXT
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
		 * @since NEXT
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
		 * Get the last anomaly alarm timestamp.
		 *
		 * Read-only option read; never throws.
		 *
		 * @return int Unix timestamp (0 when never alarmed).
		 * @since NEXT
		 */
		public static function get_last_anomaly_alarm(): int {
			try {
				if ( ! function_exists( 'get_option' ) ) {
					return 0;
				}
				$ts = get_option( self::ANOMALY_COOLDOWN_KEY, 0 ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.option_option -- Cooldown timestamp needs a dedicated per-site option.
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
		 * @since NEXT
		 */
		public static function set_last_anomaly_alarm( int $ts ): void {
			try {
				if ( ! function_exists( 'update_option' ) ) {
					return;
				}
				update_option( self::ANOMALY_COOLDOWN_KEY, $ts, false ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.option_option -- Cooldown timestamp needs a dedicated per-site option.
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Resolve the current timestamp deterministically.
		 *
		 * @param int|null $now Optional injected timestamp (tests).
		 * @return int
		 * @since NEXT
		 */
		private static function anomaly_now( ?int $now = null ): int {
			if ( null !== $now ) {
				return $now;
			}
			try {
				if ( function_exists( 'time' ) ) {
					return (int) time();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return 0;
		}

		/**
		 * Whether the anomaly cooldown has elapsed.
		 *
		 * Fail-open: any failure returns true (detection proceeds).
		 *
		 * @param int|null $now Optional injected timestamp (tests).
		 * @return bool True when a new banner may fire.
		 * @since NEXT
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
				if ( $day_seconds <= 0 ) {
					$day_seconds = 86400;
				}
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
		 * total `n >= anomaly_min_samples()` (undersampled returns false).
		 * Corroboration passes when the global RUM average is degraded vs
		 * the trend baseline (LCP: rum_avg >= baseline; CLS: rum_avg >=
		 * baseline). Fail-open: any failure returns false (no alarm).
		 *
		 * @param string     $metric Metric name ('lcp'|'cls').
		 * @param array|null $rum Optional RUM aggregate (null = live read via RUM::get_data()).
		 * @param float      $baseline Trend baseline for the firing arm.
		 * @return bool True when real-user data agrees with the trend arm.
		 * @since NEXT
		 */
		private static function is_rum_corroborated( string $metric, ?array $rum, float $baseline ): bool {
			try {
				if ( ! in_array( $metric, array( 'lcp', 'cls' ), true ) ) {
					return false;
				}
				if ( $baseline <= 0 && 'lcp' === $metric ) {
					return false;
				}
				if ( null === $rum ) {
					if ( ! class_exists( 'PerformanceOptimise\Inc\RUM' ) || ! method_exists( 'PerformanceOptimise\Inc\RUM', 'get_data' ) ) {
						return false;
					}
					$rum = RUM::get_data();
				}
				if ( ! is_array( $rum ) || empty( $rum ) ) {
					return false;
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
				if ( $total_n < self::anomaly_min_samples() ) {
					return false;
				}
				$rum_avg = $total_sum / $total_n;
				if ( 'lcp' === $metric ) {
					return $rum_avg >= $baseline;
				}
				// CLS arm: absolute-scale metric; corroborate when field
				// average is at/above the lab baseline.
				return $rum_avg >= $baseline;
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
		 * @since NEXT
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
		 * Detect LCP/CLS regressions from stored Web Vitals trend history.
		 *
		 * Multi-metric rolling-baseline comparison per URL+strategy key: the
		 * latest sample ("current", last value) is compared against the mean
		 * of all prior numeric samples. A key regresses when:
		 * - LCP: current >= baseline * 1.3 (+30%, relative), or
		 * - CLS: current - baseline >= 0.05 (absolute delta, NOT percent).
		 *
		 * Quiet-reliability gates: each firing arm must be corroborated by
		 * RUM field data (`is_rum_corroborated()`, total n >=
		 * `anomaly_min_samples()`, default 10) before alarming, and at most
		 * one anomaly overall is returned with a 7-day cooldown
		 * (`anomaly_cooldown_days`, default 7) persisted in the per-site
		 * `wppo_ai_anomaly_last_alarm` option (multisite-safe).
		 *
		 * Local computation only: no remote calls, no API keys, no email.
		 * Fail-open: undersampled history (<min_samples numeric samples),
		 * short history (<2 usable windows), non-positive LCP baseline,
		 * uncorroborated arms, active cooldown, or any failure returns an
		 * empty array — never fatal.
		 *
		 * Trend source is Pagespeed::get_trends() (capped 30/URL+strategy);
		 * RUM source is RUM::get_data() unless an aggregate is injected.
		 *
		 * @param array|null $trends Optional trends map for testability. When null, reads Pagespeed::get_trends().
		 * @param array|null $rum Optional RUM aggregate for testability. When null, reads RUM::get_data().
		 * @param int|null   $now Optional current timestamp for testability. When null, uses time().
		 * @return array[] At most one anomaly: array(array('key'=>string,'metric'=>string,'baseline'=>float,'current'=>float,'change_pct'=>float|'change_abs'=>float)).
		 * @since NEXT
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
				$resolved_now = self::anomaly_now( $now );
				// Single-banner cap: an active cooldown suppresses all arms.
				if ( ! self::is_anomaly_cooled_down( $resolved_now ) ) {
					return array();
				}
				$min_samples = self::anomaly_min_samples();
				if ( $min_samples < 1 ) {
					$min_samples = self::ANOMALY_MIN_SAMPLES;
				}
				foreach ( $trends as $trend_key => $snapshots ) {
					if ( ! is_array( $snapshots ) ) {
						continue;
					}
					$candidates = array();
					// LCP arm (relative +30%).
					$lcps = self::collect_trend_samples( $snapshots, 'lcp', true );
					if ( count( $lcps ) >= $min_samples && count( $lcps ) >= 2 ) {
						$current  = (float) end( $lcps );
						$prior    = array_slice( $lcps, 0, -1 );
						$baseline = array_sum( $prior ) / count( $prior );
						if ( $baseline > 0 && $current >= $baseline * self::LCP_RELATIVE_MULTIPLIER ) {
							$candidates[] = array(
								'key'        => (string) $trend_key,
								'metric'     => 'lcp',
								'baseline'   => (float) $baseline,
								'current'    => (float) $current,
								'change_pct' => (float) ( ( $current - $baseline ) / $baseline * 100.0 ),
							);
						}
					}
					// CLS arm (absolute delta, NOT percent).
					$clss = self::collect_trend_samples( $snapshots, 'cls', false );
					if ( count( $clss ) >= $min_samples && count( $clss ) >= 2 ) {
						$current  = (float) end( $clss );
						$prior    = array_slice( $clss, 0, -1 );
						$baseline = array_sum( $prior ) / count( $prior );
						$finite   = true;
						if ( function_exists( 'is_finite' ) ) {
							$finite = is_finite( $baseline ) && is_finite( $current );
						}
						if ( $finite && ( $current - $baseline ) >= self::CLS_ABSOLUTE_DELTA ) {
							$candidates[] = array(
								'key'        => (string) $trend_key,
								'metric'     => 'cls',
								'baseline'   => (float) $baseline,
								'current'    => (float) $current,
								'change_abs' => (float) ( $current - $baseline ),
							);
						}
					}
					foreach ( $candidates as $anomaly ) {
						// RUM corroboration gate: trends + real-user data must agree.
						if ( ! self::is_rum_corroborated( $anomaly['metric'], $rum, (float) $anomaly['baseline'] ) ) {
							continue;
						}
						// Record the alarm before filtering so a repeated
						// regression re-alarms only after the cooldown elapses.
						self::set_last_anomaly_alarm( $resolved_now );
						/**
						 * Filters the detected performance anomalies.
						 *
						 * @since NEXT
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
				// Persist the computed model so repeated renders do not
				// recompute the full RUM + trends deserialization, postmeta
				// UNION query and segmented scans on every invocation.
				// (No per-process static memo: statics leak across unit
				// tests sharing one PHP process.).
				$model = self::heuristic_learn();
				if ( ! empty( $model ) ) {
					try {
						self::update_model( $model );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
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
					// Single full-aggregate scan (min=1); filter the qualified
					// rows in memory instead of issuing a second scan.
					$observed = self::segmented_field_lcp( 1 );
					$max_n    = 0;
					foreach ( $observed as $row ) {
						if ( is_array( $row ) && isset( $row['n'] ) ) {
							$max_n = max( $max_n, (int) $row['n'] );
						}
					}
					$qualified = array();
					foreach ( $observed as $row ) {
						if ( is_array( $row ) && isset( $row['n'] ) && (int) $row['n'] >= $field_min ) {
							$qualified[] = $row;
						}
					}
					if ( ! empty( $qualified ) && is_array( $qualified[0] ) ) {
						$top               = $qualified[0];
						$field_segment     = array(
							'path'     => isset( $top['path'] ) ? (string) $top['path'] : '',
							'device'   => isset( $top['device'] ) ? (string) $top['device'] : 'unknown',
							'template' => isset( $top['template'] ) ? (string) $top['template'] : 'unknown',
						);
						$field_p75         = isset( $top['p75'] ) ? (float) $top['p75'] : 0.0;
						$field_samples     = isset( $top['n'] ) ? (int) $top['n'] : 0;
						$field_provisional = false;
					} else {
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

			// RUM-gated INP-aware delay-JS suggestion (issue #1036): read-only,
			// opt-in via ai_adaptive.enabled; emitted only when real-user p75
			// crosses thresholds with sufficient samples (n>=min AND
			// INP p75>200ms OR LCP p75>2500ms). Low samples, uncrossed
			// thresholds, or errors emit nothing (fail-open). Commerce/auth
			// contexts cap the level at `moderate` (never `eager`) via
			// get_rum_gated_delay_state(). Resolves once delayJS is enabled.
			// No external calls, no PII, no option/transient writes.
			try {
				$delay_qualified = array_key_exists( 'delay_qualified', $model ) ? ! empty( $model['delay_qualified'] ) : null;
				$delay_level     = isset( $model['delay_js_level'] ) && is_string( $model['delay_js_level'] ) ? $model['delay_js_level'] : null;
				$delay_inp       = isset( $model['delay_inp_p75'] ) ? (float) $model['delay_inp_p75'] : null;
				$delay_lcp       = isset( $model['delay_lcp_p75'] ) ? (float) $model['delay_lcp_p75'] : null;
				$delay_samples   = isset( $model['delay_samples'] ) ? (int) $model['delay_samples'] : null;
				$delay_min       = isset( $model['delay_min_samples'] ) ? (int) $model['delay_min_samples'] : null;
				$delay_segment   = isset( $model['delay_segment'] ) && is_array( $model['delay_segment'] ) ? $model['delay_segment'] : null;
				if ( null === $delay_qualified || null === $delay_level ) {
					// Models persisted before #1036 lack delay keys: fall back
					// to a live read-only lookup. Fail-open to unqualified.
					$live_delay = self::get_rum_gated_delay_state();
					if ( is_array( $live_delay ) ) {
						$delay_qualified = ! empty( $live_delay['qualified'] );
						$delay_level     = isset( $live_delay['level'] ) ? (string) $live_delay['level'] : 'conservative';
						$delay_inp       = isset( $live_delay['inp_p75'] ) ? (float) $live_delay['inp_p75'] : 0.0;
						$delay_lcp       = isset( $live_delay['lcp_p75'] ) ? (float) $live_delay['lcp_p75'] : 0.0;
						$delay_samples   = isset( $live_delay['samples'] ) ? (int) $live_delay['samples'] : 0;
						$delay_min       = isset( $live_delay['min_samples'] ) ? (int) $live_delay['min_samples'] : self::field_lcp_min_samples();
						$delay_segment   = isset( $live_delay['segment'] ) && is_array( $live_delay['segment'] ) ? $live_delay['segment'] : null;
					}
				}
				if ( $delay_qualified && is_string( $delay_level ) && 'conservative' !== $delay_level ) {
					// Commerce/auth guardrail: never propose `eager` (covers
					// models persisted before the cap).
					$delay_level = self::maybe_cap_eagerness( $delay_level );
					if ( ! in_array( $delay_level, array( 'moderate', 'eager' ), true ) ) {
						$delay_level = 'moderate';
					}
					$delay_settings = class_exists( 'PerformanceOptimise\Inc\Util' ) ? Util::get_settings() : array();
					$delay_enabled  = ! empty( $delay_settings['file_optimisation']['delayJS'] );
					if ( ! $delay_enabled && ! self::is_suggestion_dismissed( 'ai_delay_js' ) ) {
						$delay_device   = ( is_array( $delay_segment ) && isset( $delay_segment['device'] ) ) ? (string) $delay_segment['device'] : 'unknown';
						$delay_template = ( is_array( $delay_segment ) && isset( $delay_segment['template'] ) ) ? (string) $delay_segment['template'] : 'unknown';
						// Anchor the copy on the crossed signal (prefer INP).
						$crossed_inp      = (float) $delay_inp > self::INP_P75_DELAY_THRESHOLD_MS;
						$delay_anchor_p75 = $crossed_inp ? (float) $delay_inp : (float) $delay_lcp;
						if ( $crossed_inp ) {
							/* translators: %1$s level, %2$s device, %3$s template, %4$s p75 seconds. */
							$delay_value = sprintf( __( '%1$s · %2$s · %3$s · INP p75 %4$s', 'performance-optimisation' ), $delay_level, $delay_device, $delay_template, self::format_p75_seconds( (float) $delay_anchor_p75 ) );
							/* translators: %1$s device, %2$s template, %3$s p75 seconds, %4$d sample count. */
							$delay_description = sprintf( __( 'AI: Delay JavaScript suggestion for %1$s/%2$s (INP p75 %3$s, %4$d samples)', 'performance-optimisation' ), $delay_device, $delay_template, self::format_p75_seconds( (float) $delay_anchor_p75 ), (int) $delay_samples );
						} else {
							/* translators: %1$s level, %2$s device, %3$s template, %4$s p75 seconds. */
							$delay_value = sprintf( __( '%1$s · %2$s · %3$s · LCP p75 %4$s', 'performance-optimisation' ), $delay_level, $delay_device, $delay_template, self::format_p75_seconds( (float) $delay_anchor_p75 ) );
							/* translators: %1$s device, %2$s template, %3$s p75 seconds, %4$d sample count. */
							$delay_description = sprintf( __( 'AI: Delay JavaScript suggestion for %1$s/%2$s (LCP p75 %3$s, %4$d samples)', 'performance-optimisation' ), $delay_device, $delay_template, self::format_p75_seconds( (float) $delay_anchor_p75 ), (int) $delay_samples );
						}
						$suggestions[] = array(
							'metric'      => 'ai_delay_js',
							'value'       => $delay_value,
							'unit'        => 'string',
							'status'      => 'needs_improvement',
							'description' => $delay_description,
							'fix_action'  => 'open_file_optimization_tab',
							'ai_payload'  => array(
								'tab'      => 'file_optimisation',
								'settings' => array(
									'delayJS'          => true,
									'delayJSINPPreset' => true,
								),
							),
						);
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
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
			// LCP (+30% relative, RUM-corroborated) or CLS (+0.05 absolute
			// delta, RUM-corroborated) regression with a 7-day cooldown.
			// Fail-open: detector errors contribute zero suggestions (never
			// fatal, never white-screen). No auto-tune, no speculation
			// override here — that stays gated by is_enabled() in
			// filter_speculation_rules(). No email without opt-in.
			try {
				$anomalies = self::detect_anomalies();
			} catch ( \Throwable $e ) {
				unset( $e );
				$anomalies = array();
			}
			if ( is_array( $anomalies ) && ! empty( $anomalies ) ) {
				$anomaly = $anomalies[0];
				if ( 'cls' === ( $anomaly['metric'] ?? 'lcp' ) ) {
					$change_abs = isset( $anomaly['change_abs'] ) ? (float) $anomaly['change_abs'] : 0.0;
					/* translators: %s is the CLS absolute increase vs baseline. */
					$cls_value     = sprintf( __( 'CLS +%s vs baseline', 'performance-optimisation' ), number_format( $change_abs, 2 ) );
					$suggestions[] = array(
						'metric'      => 'ai_cls_regression',
						'value'       => $cls_value,
						'unit'        => 'string',
						'status'      => 'needs_improvement',
						'description' => __( 'AI: CLS regression detected', 'performance-optimisation' ),
						'fix_action'  => 'open_image_optimization_tab',
						'ai_payload'  => array(
							'tab'      => 'image_optimisation',
							'settings' => array(),
						),
					);
				} else {
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
			}

			// Dismiss persistence (issue #1036): filter out dismissed metrics
			// so a dismissed suggestion stays hidden across reloads until
			// the user clears it from settings. Fail-open: lookup errors
			// keep all suggestions.
			try {
				$dismissed = self::get_dismissed_suggestions();
				if ( ! empty( $dismissed ) ) {
					$suggestions = array_values(
						array_filter(
							$suggestions,
							static function ( $s ) use ( $dismissed ) {
								$metric = is_array( $s ) && isset( $s['metric'] ) ? (string) $s['metric'] : '';
								return '' === $metric || ! in_array( $metric, $dismissed, true );
							}
						)
					);
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			// Ensure fix_action is valid per Suggestion_Engine guard (already valid).
			$suggestions = array_values( array_filter( $suggestions, array( self::class, 'is_valid_suggestion' ) ) );
			return $suggestions;
		}

		/**
		 * Whether RUM-gated speculation eagerness is enabled.
		 *
		 * Additive toggle (issue #1061) living in
		 * `wppo_settings[preload_settings][speculationRumGating]` (default
		 * true, alongside `speculationEagerness`). A legacy
		 * `wppo_settings[ai_adaptive][speculation_rum_gating]` key is honored
		 * as fallback when the preload key is absent. Filterable via
		 * `wppo_ai_speculation_rum_gating`. Fail-open: any failure returns true.
		 *
		 * @return bool True when RUM gating applies.
		 * @since NEXT
		 */
		public static function is_speculation_rum_gating_enabled(): bool {
			try {
				$settings = class_exists( 'PerformanceOptimise\Inc\Util' ) ? Util::get_settings() : array();
				if ( isset( $settings['preload_settings']['speculationRumGating'] ) ) {
					$enabled = (bool) $settings['preload_settings']['speculationRumGating'];
				} elseif ( isset( $settings['ai_adaptive']['speculation_rum_gating'] ) ) {
					$enabled = (bool) $settings['ai_adaptive']['speculation_rum_gating'];
				} else {
					$enabled = true;
				}
				/**
				 * Filters whether RUM-gated speculation eagerness applies.
				 *
				 * @since NEXT
				 * @param bool $enabled Whether RUM gating is enabled.
				 */
				return (bool) apply_filters( 'wppo_ai_speculation_rum_gating', $enabled );
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * RUM-gated speculation eagerness state (read-only, fail-open).
		 *
		 * Good real-user p75 (LCP at/below `wppo_ai_speculation_lcp_threshold`,
		 * default 2500ms, AND INP at/below `wppo_ai_speculation_inp_threshold`,
		 * default 200ms, with n >= shared `field_lcp_min_samples` gate)
		 * qualifies for a moderate/eager list rule (eagerness filterable via
		 * `wppo_ai_speculation_eagerness`, default `moderate`). Poor or absent
		 * RUM stays conservative. Missing signals never block: a segment type
		 * with no qualified rows is treated as good. No option/transient
		 * writes; multisite-safe via the per-site RUM aggregate.
		 *
		 * @since NEXT
		 * @return array{qualified:bool,eagerness:string,lcp_p75:float,inp_p75:float,samples:int,min_samples:int,gated:bool} Gated state.
		 */
		public static function get_rum_gated_speculation_state(): array {
			$min      = self::field_lcp_min_samples();
			$fallback = array(
				'qualified'   => false,
				'eagerness'   => 'conservative',
				'lcp_p75'     => 0.0,
				'inp_p75'     => 0.0,
				'samples'     => 0,
				'min_samples' => $min,
				'gated'       => self::is_speculation_rum_gating_enabled(),
			);
			try {
				if ( ! self::is_speculation_rum_gating_enabled() ) {
					return $fallback;
				}
				$lcp_rows = self::segmented_field_lcp( 1 );
				$inp_rows = self::segmented_field_inp( 1 );

				$max_n = 0;
				foreach ( array_merge( $lcp_rows, $inp_rows ) as $row ) {
					if ( is_array( $row ) && isset( $row['n'] ) ) {
						$max_n = max( $max_n, (int) $row['n'] );
					}
				}
				$fallback['samples'] = $max_n;

				$qualified_lcp = array();
				foreach ( $lcp_rows as $row ) {
					if ( is_array( $row ) && isset( $row['n'] ) && (int) $row['n'] >= $min ) {
						$qualified_lcp[] = $row;
					}
				}
				$qualified_inp = array();
				foreach ( $inp_rows as $row ) {
					if ( is_array( $row ) && isset( $row['n'] ) && (int) $row['n'] >= $min ) {
						$qualified_inp[] = $row;
					}
				}
				if ( empty( $qualified_lcp ) && empty( $qualified_inp ) ) {
					return $fallback;
				}

				$max_lcp = 0.0;
				foreach ( $qualified_lcp as $row ) {
					if ( isset( $row['p75'] ) ) {
						$max_lcp = max( $max_lcp, (float) $row['p75'] );
					}
				}
				$max_inp = 0.0;
				foreach ( $qualified_inp as $row ) {
					if ( isset( $row['p75'] ) ) {
						$max_inp = max( $max_inp, (float) $row['p75'] );
					}
				}
				$fallback['lcp_p75'] = $max_lcp;
				$fallback['inp_p75'] = $max_inp;

				/**
				 * Filters the LCP p75 (ms) threshold for RUM-gated speculation eagerness.
				 *
				 * @since NEXT
				 * @param float $threshold LCP p75 threshold in milliseconds.
				 */
				$lcp_threshold = (float) apply_filters( 'wppo_ai_speculation_lcp_threshold', 2500.0 );
				/**
				 * Filters the INP p75 (ms) threshold for RUM-gated speculation eagerness.
				 *
				 * @since NEXT
				 * @param float $threshold INP p75 threshold in milliseconds.
				 */
				$inp_threshold = (float) apply_filters( 'wppo_ai_speculation_inp_threshold', 200.0 );

				$lcp_good = empty( $qualified_lcp ) || $max_lcp <= $lcp_threshold;
				$inp_good = empty( $qualified_inp ) || $max_inp <= $inp_threshold;
				if ( ! $lcp_good || ! $inp_good ) {
					return $fallback;
				}

				/**
				 * Filters the eagerness for a RUM-qualified (good p75) speculation list rule.
				 *
				 * @since NEXT
				 * @param string $eagerness Eagerness value (default `moderate`).
				 * @param array  $state Gated state (lcp_p75, inp_p75, samples).
				 */
				$eagerness = (string) apply_filters(
					'wppo_ai_speculation_eagerness',
					'moderate',
					array(
						'lcp_p75' => $max_lcp,
						'inp_p75' => $max_inp,
						'samples' => $max_n,
					)
				);
				$eagerness = self::normalize_eagerness( $eagerness );

				return array(
					'qualified'   => true,
					'eagerness'   => $eagerness,
					'lcp_p75'     => $max_lcp,
					'inp_p75'     => $max_inp,
					'samples'     => $max_n,
					'min_samples' => $min,
					'gated'       => true,
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return $fallback;
			}
		}

		/**
		 * Top RUM (real-visit) URLs for the RUM-gated speculation list rule.
		 *
		 * Volume-ranked from `RUM::get_data()` (one opportunistic read),
		 * resolved to absolute pretty-permalink URLs (trailing slash),
		 * deduped, same-site validated, commerce/query-string/admin rejected,
		 * and capped at $limit (keeps the ~1KB footprint). Invalid URLs are
		 * skipped individually (fail-open); empty means "no RUM winners".
		 *
		 * @param int $limit Maximum URLs to return.
		 * @return string[]
		 * @since NEXT
		 */
		public static function get_rum_top_speculation_urls( int $limit = 5 ): array {
			try {
				if ( $limit < 1 ) {
					return array();
				}
				if ( ! class_exists( 'PerformanceOptimise\Inc\RUM' ) || ! method_exists( 'PerformanceOptimise\Inc\RUM', 'get_data' ) ) {
					return array();
				}
				$rum = RUM::get_data();
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
			if ( ! is_array( $rum ) || empty( $rum ) ) {
				return array();
			}
			try {
				$counts = array();
				foreach ( $rum as $paths ) {
					if ( ! is_array( $paths ) ) {
						continue;
					}
					foreach ( $paths as $path => $metrics ) {
						if ( ! is_string( $path ) || '' === $path || ! is_array( $metrics ) ) {
							continue;
						}
						if ( false !== strpos( $path, '?' ) || false !== strpos( $path, '#' ) ) {
							continue;
						}
						$count = 0;
						foreach ( $metrics as $metric => $aggregate ) {
							if ( 'lcpUrls' === $metric || ! is_array( $aggregate ) ) {
								continue;
							}
							$n = isset( $aggregate['n'] ) ? (int) $aggregate['n'] : 0;
							if ( $n > $count ) {
								$count = $n;
							}
						}
						if ( $count <= 0 ) {
							continue;
						}
						$normalized = class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'normalize_rum_path' )
							? Util::normalize_rum_path( $path )
							: $path;
						if ( '/' === $normalized ) {
							continue;
						}
						if ( ! isset( $counts[ $normalized ] ) ) {
							$counts[ $normalized ] = 0;
						}
						$counts[ $normalized ] += $count;
					}
				}
				if ( empty( $counts ) ) {
					return array();
				}
				arsort( $counts );

				$excludes = self::get_commerce_exclude_paths();
				$urls     = array();
				foreach ( array_keys( $counts ) as $top_path ) {
					if ( '/' !== substr( $top_path, -1 ) ) {
						$top_path .= '/';
					}
					$absolute = Util::cached_home_url( $top_path );
					$clean    = function_exists( 'esc_url_raw' ) ? esc_url_raw( $absolute ) : $absolute;
					if ( ! is_string( $clean ) || '' === $clean ) {
						continue;
					}
					if ( in_array( $clean, $urls, true ) ) {
						continue;
					}
					// Commerce-prefix guard (explicit list rules bypass
					// href-exclude filtering, so filter here).
					$skip = false;
					if ( function_exists( 'wp_parse_url' ) ) {
						try {
							$path_part = wp_parse_url( $clean, PHP_URL_PATH );
							$path_part = is_string( $path_part ) && '' !== $path_part ? rtrim( strtolower( $path_part ), '/' ) : '';
							foreach ( array_merge( $excludes, array( '/account/*' ) ) as $exclude ) {
								$prefix = rtrim( rtrim( (string) $exclude, '*' ), '/' );
								$prefix = strtolower( $prefix );
								if ( '' !== $prefix && ( $path_part === $prefix || 0 === strpos( $path_part . '/', $prefix . '/' ) ) ) {
									$skip = true;
									break;
								}
							}
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
					if ( $skip ) {
						continue;
					}
					$urls[] = $clean;
					if ( count( $urls ) >= $limit ) {
						break;
					}
				}
				$urls = self::filter_same_site_urls( $urls );
				/**
				 * Filters the RUM-ranked top URLs for the gated speculation list rule.
				 *
				 * @since NEXT
				 * @param string[] $urls Ranked absolute URLs.
				 */
				$urls = apply_filters( 'wppo_ai_speculation_top_urls', $urls );
				if ( ! is_array( $urls ) ) {
					return array();
				}
				return array_values( array_filter( $urls, 'is_string' ) );
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Whether the AI speculation rule is hard-suppressed for this request.
		 *
		 * Hard suppression (emit nothing): wp-admin, commerce page contexts
		 * (cart/checkout/account via conditionals), or plain permalinks
		 * disabled. Generic commerce/auth signals (Woo active, cookies,
		 * logged-in) are NOT hard suppression — they keep the existing
		 * filter-plus-cap guardrail so model URLs still emit conservatively.
		 * Fail-open: any throwable means "not suppressed".
		 *
		 * @return bool True when no AI rule must be emitted.
		 * @since NEXT
		 */
		private static function is_speculation_hard_suppressed(): bool {
			try {
				if ( function_exists( 'is_admin' ) ) {
					try {
						if ( is_admin() ) {
							return true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				foreach ( array( 'is_cart', 'is_checkout', 'is_account_page' ) as $conditional ) {
					if ( function_exists( $conditional ) ) {
						try {
							if ( call_user_func( $conditional ) ) {
								return true;
							}
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
				}
				if ( function_exists( 'get_option' ) ) {
					try {
						$structure = get_option( 'permalink_structure' );
						// Plain permalinks store '' — suppress. A missing
						// option (false, e.g. minimal test installs) stays
						// fail-open so unit tests without the stub still run.
						if ( '' === $structure ) {
							return true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Inject AI-learned prefetch URLs into speculation rules.
		 *
		 * Hooks into wp_speculation_rules filter (WP 6.8+). Only injects when
		 * AI adaptive is enabled and prefetch URLs exist; never auto-enables
		 * speculation itself.
		 *
		 * RUM gating (issue #1061): model URLs are merged with the
		 * volume-ranked RUM top-URL list (pretty permalinks, deduped, capped
		 * at 5, ~1KB). When `speculationRumGating` is on (default), good RUM
		 * p75 emits a moderate/eager list rule; poor or absent RUM forces
		 * conservative. Commerce page contexts, wp-admin, and plain
		 * permalinks emit nothing; generic commerce/auth signals keep the
		 * filter-plus-cap guardrail (#908).
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
			// Hard suppression: commerce pages, wp-admin, no permalinks.
			if ( self::is_speculation_hard_suppressed() ) {
				return $rules;
			}
			// Single model read: reused for both prefetch URLs and eagerness.
			$model      = self::get_model();
			$model_urls = self::get_prefetch_urls_from_model( $model );
			// RUM-ranked top URLs fill the remaining budget (fail-open empty).
			$rum_urls = self::get_rum_top_speculation_urls( 5 );
			$urls     = array();
			foreach ( array_merge( $model_urls, $rum_urls ) as $candidate ) {
				if ( ! is_string( $candidate ) || '' === $candidate ) {
					continue;
				}
				if ( in_array( $candidate, $urls, true ) ) {
					continue;
				}
				$urls[] = $candidate;
				if ( count( $urls ) >= 5 ) {
					break;
				}
			}
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
			// RUM-gated eagerness (issue #1061): good p75 → moderate/eager,
			// poor/absent → conservative. Gating off → model eagerness.
			// Guardrail (#908): allowlist stale values and downgrade a persisted
			// `eager` to `moderate` in commerce/auth contexts before injecting.
			if ( self::is_speculation_rum_gating_enabled() ) {
				$state = self::get_rum_gated_speculation_state();
				if ( ! empty( $state['qualified'] ) ) {
					$eagerness = self::normalize_eagerness( $state['eagerness'] ?? 'moderate' );
				} else {
					$eagerness = 'conservative';
				}
			} else {
				$eagerness = self::normalize_eagerness( $model['eagerness'] ?? 'conservative' );
			}
			$rules[] = array(
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
