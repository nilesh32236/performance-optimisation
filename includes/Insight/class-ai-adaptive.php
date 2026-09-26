<?php
/**
 * AI Adaptive optimization — RUM → heuristic/auto-tune via suggestions.
 *
 * @package PerformanceOptimise\Inc
 * @since 2.0.0
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
	 * @since 2.0.0
	 */
	class AI_Adaptive {

		/**
		 * Per-request memo of the stored AI model (null = not loaded yet).
		 *
		 * Audit #1362: declared beside its consumer (was below get_model).
		 *
		 * @since 2.2.0
		 * @var array|null
		 */
		private static ?array $model_memo = null;

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
		 * @since 2.0.0
		 */
		public static function is_enabled(): bool {
			$settings = Util::get_settings();
			$enabled  = ! empty( $settings['ai_adaptive']['enabled'] );
			/**
			 * Filters whether AI Adaptive is enabled.
			 *
			 * @since 2.0.0
			 * @param bool $enabled Whether AI adaptive is enabled.
			 */
			return (bool) apply_filters( 'wppo_ai_adaptive_enabled', $enabled );
		}

		/**
		 * Get the stored model (per-request memoized).
		 *
		 * A single page view may deserialize the AI model plus the RUM
		 * aggregate; the memo collapses that to one get_option() per
		 * request. Invalidated by update_model() and reset_model_memo()
		 * (tests). Mirrors Util::get_settings()/RUM::get_memoized_aggregate().
		 *
		 * @return array
		 * @since 2.0.0
		 * @since 2.2.0 Memoize per request.
		 */
		public static function get_model(): array {
			if ( null !== self::$model_memo ) {
				return self::$model_memo;
			}
			$model = get_option( self::OPTION, array() );
			if ( ! is_array( $model ) ) {
				$model = array();
			}
			self::$model_memo = $model;
			return $model;
		}

		/**
		 * Reset the per-request model memo (for testing).
		 *
		 * @since 2.2.0
		 * @return void
		 */
		public static function reset_model_memo(): void {
			self::$model_memo = null;
		}

		/**
		 * Reset all AI-owned per-blog request memos.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function reset_runtime_state(): void {
			self::reset_model_memo();
			Ai_Anomaly::reset_rum_anomaly_digest_memo();
		}

		/**
		 * Facade proxy (ARCH-010): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ai_Anomaly::reset_rum_anomaly_digest_memo}.
		 *
		 * @since 2.4.0
		 * @return void
		 */
		public static function reset_rum_anomaly_digest_memo(): void {
			Ai_Anomaly::reset_rum_anomaly_digest_memo();
		}

		/**
		 * Persist the model with autoload=no.
		 *
		 * @param array $model Model data.
		 * @return void
		 * @since 2.0.0
		 */
		public static function update_model( array $model ): void {
			update_option( self::OPTION, $model, false );
			self::$model_memo = $model;
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
		 * @since 2.0.0
		 */
		public static function is_commerce_or_auth_context(): bool {
			$is_commerce = self::detect_commerce_or_auth_context();

			/**
			 * Filters whether the current request is a commerce/auth context for AI speculation guardrails.
			 *
			 * Allows hosts/tests to force the context deterministically.
			 *
			 * @since 2.0.0
			 * @param bool $is_commerce Whether a commerce/auth context was detected.
			 */
			return (bool) apply_filters( 'wppo_ai_adaptive_commerce_context', $is_commerce );
		}

		/**
		 * Internal helper to detect commerce or auth context using early returns.
		 *
		 * @return bool True when a commerce/auth context is detected.
		 * @since 2.0.0
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
			// Audit #1434: sanitized like sibling ESI/integration cookie reads.
			$items_in_cart = isset( $_COOKIE['woocommerce_items_in_cart'] ) && is_string( $_COOKIE['woocommerce_items_in_cart'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['woocommerce_items_in_cart'] ) ) : '';
			$cart_hash     = isset( $_COOKIE['woocommerce_cart_hash'] ) && is_string( $_COOKIE['woocommerce_cart_hash'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['woocommerce_cart_hash'] ) ) : '';
			if ( '' !== $items_in_cart || '' !== $cart_hash ) {
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
		 * @since 2.0.0
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
		 * @since 2.0.0
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
		 * @since 2.0.0
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
		 * @since 2.0.0
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
		 * @since 2.0.0
		 */
		public static function is_wp_ai_client_enabled(): bool {
			$settings = Util::get_settings();
			$enabled  = ! empty( $settings['ai_adaptive']['use_wp_ai_client'] );
			/**
			 * Filters whether the WordPress AI client path may be used.
			 *
			 * @since 2.0.0
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
		 * @since 2.0.0
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
		 * @since 2.0.0
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
		 * @since 2.0.0
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
					$rum = get_option( RUM::OPTION, array() );
					if ( ! is_array( $rum ) ) {
						$rum = array();
					}
				}
				if ( null === $trends ) {
					$trends = get_option( Pagespeed::TREND_OPTION, array() );
					if ( ! is_array( $trends ) ) {
						$trends = array();
					}
				}
				// Send a summarized projection (top paths/segments) instead
				// of the raw aggregates so a ~480KB option is not duplicated
				// in memory via wp_json_encode on the learn path.
				$summary = self::summarize_aggregates_for_prompt( $rum, $trends );
				$prompt  = 'Given RUM aggregates and trends, suggest top 2 prefetch URLs, least-used scripts to exclude, and speculation eagerness (conservative|moderate|eager) as JSON.';
				$result  = $client->prompt( $prompt . ' RUM:' . wp_json_encode( $summary['rum'] ) . ' Trends:' . wp_json_encode( $summary['trends'] ) );
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
		 * @since 2.0.0
		 * @var float
		 */
		public const INP_P75_DELAY_THRESHOLD_MS = 200.0;

		/**
		 * INP p75 threshold (ms) for the `eager` delay level.
		 *
		 * Matches the Core Web Vitals "poor" boundary (>500ms).
		 *
		 * @since 2.0.0
		 * @var float
		 */
		public const INP_P75_EAGER_THRESHOLD_MS = 500.0;

		/**
		 * LCP p75 threshold (ms) gating delay-JS suggestions.
		 *
		 * Mirrors the speculation eagerness ladder (>2500ms moderate,
		 * >3500ms eager) so heavy pages also surface a delay suggestion.
		 *
		 * @since 2.0.0
		 * @var float
		 */
		public const LCP_P75_DELAY_THRESHOLD_MS = 2500.0;

		/**
		 * LCP p75 threshold (ms) for the `eager` delay level.
		 *
		 * @since 2.0.0
		 * @var float
		 */
		public const LCP_P75_EAGER_THRESHOLD_MS = 3500.0;

		/**
		 * Read segmented field-LCP p75 rows (device × template × connection) fail-open.
		 *
		 * Thin wrapper over RUM::get_field_lcp_p75_by_segment() so
		 * heuristic_learn() degrades to the global-average path when RUM is
		 * unavailable. No option or transient writes; never throws.
		 *
		 * @since 2.0.0
		 * @since 2.2.0 Rows carry the `connection` segment dimension.
		 * @param int $min_samples Minimum samples per segment (1 = observe all).
		 * @return array[] Rows of array(path,device,template,connection,n,p75).
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
		 * Read segmented field-INP p75 rows (device × template × connection) fail-open.
		 *
		 * Thin wrapper over RUM::get_field_inp_p75_by_segment() so
		 * heuristic_learn() degrades gracefully when RUM is unavailable.
		 * No option or transient writes; never throws.
		 *
		 * @since 2.0.0
		 * @since 2.2.0 Rows carry the `connection` segment dimension.
		 * @param int $min_samples Minimum samples per segment (1 = observe all).
		 * @return array[] Rows of array(path,device,template,connection,n,p75).
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
		 * Extract the connection segment from a RUM segment row fail-open.
		 *
		 * Rows stored before the connection dimension shipped (issue #1143)
		 * carry no `connection` key and read back as `unknown`, keeping
		 * segment-routed copy backward compatible.
		 *
		 * @since 2.2.0
		 * @param mixed $row Segment row.
		 * @return string Allowlisted connection type or 'unknown'.
		 */
		private static function segment_connection( $row ): string {
			if ( ! is_array( $row ) || ! isset( $row['connection'] ) || ! is_string( $row['connection'] ) ) {
				return 'unknown';
			}
			$candidate = strtolower( trim( substr( $row['connection'], 0, 16 ) ) );
			if ( in_array( $candidate, array( 'slow-2g', '2g', '3g', '4g' ), true ) ) {
				return $candidate;
			}
			return 'unknown';
		}

		/**
		 * Build a segment descriptor from a RUM segment row fail-open.
		 *
		 * Always returns path/device/template/connection keys so suggestion
		 * copy stays segment-routed even for models persisted before the
		 * connection dimension shipped.
		 *
		 * @since 2.2.0
		 * @param mixed $row Segment row.
		 * @return array{path:string,device:string,template:string,connection:string} Segment descriptor.
		 */
		private static function segment_descriptor( $row ): array {
			$row = is_array( $row ) ? $row : array();
			return array(
				'path'       => isset( $row['path'] ) ? (string) $row['path'] : '',
				'device'     => isset( $row['device'] ) ? (string) $row['device'] : 'unknown',
				'template'   => isset( $row['template'] ) ? (string) $row['template'] : 'unknown',
				'connection' => self::segment_connection( $row ),
			);
		}

		/**
		 * Pick the slowest-p75 segment row from qualified rows (slowest-first).
		 *
		 * Centralizes the slowest-segment selection so callers never depend
		 * on the callee sort order of RUM::get_field_lcp_p75_by_segment():
		 * heuristic_learn(), get_suggestions(), and
		 * get_rum_gated_delay_state() all route through here with the same
		 * comparator. Fail-open: non-array entries are ignored, empty input
		 * returns null.
		 *
		 * @since 2.2.0
		 * @param array[] $rows Qualified segment rows.
		 * @return array|null Slowest row or null when empty.
		 */
		private static function slowest_segment_row( array $rows ): ?array {
			$rows = array_values( array_filter( $rows, 'is_array' ) );
			if ( empty( $rows ) ) {
				return null;
			}
			usort(
				$rows,
				static function ( $a, $b ) {
					$pa = isset( $a['p75'] ) ? (float) $a['p75'] : 0.0;
					$pb = isset( $b['p75'] ) ? (float) $b['p75'] : 0.0;
					if ( $pa === $pb ) {
						return 0;
					}
					return $pa > $pb ? -1 : 1;
				}
			);
			return $rows[0];
		}

		/**
		 * RUM-driven slow-top-path state for heuristic auto-tune (read-only, fail-open).
		 *
		 * Consumes RUM::get_field_lcp_p75_by_segment() via the fail-open
		 * segmented_field_lcp() wrapper: filters rows to n >=
		 * field_lcp_min_samples(), picks the slowest-p75 segment with
		 * slowest_segment_row(), and flags slow when p75 exceeds
		 * LCP_P75_DELAY_THRESHOLD_MS (2500ms). When slow, resolves exactly
		 * one preload hero URL via RUM::get_lcp_preload_candidate() for the
		 * slow path (field data wins, PageSpeed fallback inside RUM); when
		 * no candidate resolves the caller keeps static defaults with the
		 * manual metabox hero URL winning. Below the sample threshold (or
		 * on any error) slow is false so callers emit no override and keep
		 * static defaults with no speculation change. No option/transient
		 * writes, zero external HTTP; multisite-safe via the per-site RUM
		 * aggregate. Commerce/auth contexts are capped downstream via
		 * maybe_cap_eagerness() when the downgrade target is computed.
		 *
		 * @since 2.3.0
		 * @return array{slow:bool,path:string,segment:array|null,p75:float,samples:int,min_samples:int,preload_url:string} Slow-path state.
		 */
		public static function get_slow_top_path_state(): array {
			$fallback = array(
				'slow'        => false,
				'path'        => '',
				'segment'     => null,
				'p75'         => 0.0,
				'samples'     => 0,
				'min_samples' => 20,
				'preload_url' => '',
			);
			try {
				$min                     = self::field_lcp_min_samples();
				$fallback['min_samples'] = $min;
				$observed                = self::segmented_field_lcp( 1 );
				$max_n                   = 0;
				foreach ( $observed as $row ) {
					if ( is_array( $row ) && isset( $row['n'] ) ) {
						$max_n = max( $max_n, (int) $row['n'] );
					}
				}
				$fallback['samples'] = $max_n;
				$qualified           = array();
				foreach ( $observed as $row ) {
					if ( is_array( $row ) && isset( $row['n'] ) && (int) $row['n'] >= $min ) {
						$qualified[] = $row;
					}
				}
				if ( empty( $qualified ) ) {
					return $fallback;
				}
				$slowest = self::slowest_segment_row( $qualified );
				if ( ! is_array( $slowest ) ) {
					return $fallback;
				}
				$p75   = isset( $slowest['p75'] ) ? (float) $slowest['p75'] : 0.0;
				$n     = isset( $slowest['n'] ) ? (int) $slowest['n'] : 0;
				$path  = isset( $slowest['path'] ) && is_string( $slowest['path'] ) ? $slowest['path'] : '';
				$slow  = $p75 > self::LCP_P75_DELAY_THRESHOLD_MS;
				$state = array(
					'slow'        => $slow,
					'path'        => $path,
					'segment'     => self::segment_descriptor( $slowest ),
					'p75'         => $p75,
					'samples'     => $n,
					'min_samples' => $min,
					'preload_url' => '',
				);
				if ( $slow && '' !== $path && class_exists( 'PerformanceOptimise\Inc\RUM' ) && method_exists( 'PerformanceOptimise\Inc\RUM', 'get_lcp_preload_candidate' ) ) {
					try {
						$candidate = \PerformanceOptimise\Inc\RUM::get_lcp_preload_candidate( $path );
						if ( is_array( $candidate ) && isset( $candidate['url'] ) && is_string( $candidate['url'] ) && '' !== trim( $candidate['url'] ) ) {
							$state['preload_url'] = substr( trim( $candidate['url'] ), 0, 2048 );
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				return $state;
			} catch ( \Throwable $e ) {
				unset( $e );
				return $fallback;
			}
		}

		/**
		 * Downgrade target for a slow top path (one eagerness step down, floored).
		 *
		 * Explainable policy: eager becomes moderate, moderate becomes
		 * conservative, conservative stays conservative. The result is
		 * allowlisted and capped at moderate in commerce/auth contexts via
		 * normalize_eagerness() so transactional pages never loosen.
		 *
		 * @since 2.3.0
		 * @param string $eagerness Current learned eagerness.
		 * @return string Downgraded eagerness.
		 */
		public static function get_slow_path_downgrade_target( string $eagerness ): string {
			$current = self::normalize_eagerness( $eagerness );
			$target  = self::eagerness_from_level( self::eagerness_level( $current ) - 1 );
			return self::normalize_eagerness( $target );
		}

		/**
		 * Numeric level for an allowlisted eagerness value (ladder helper).
		 *
		 * Centralizes the conservative(0) < moderate(1) < eager(2) ladder so
		 * the downgrade target and the stale-model clamp in get_suggestions()
		 * cannot drift apart when the ladder changes. Unknown values floor
		 * to conservative (0).
		 *
		 * @since 2.3.0
		 * @param string $eagerness Allowlisted eagerness value.
		 * @return int Numeric ladder level (0-2).
		 */
		private static function eagerness_level( string $eagerness ): int {
			$ladder = array(
				'conservative' => 0,
				'moderate'     => 1,
				'eager'        => 2,
			);
			return $ladder[ $eagerness ] ?? 0;
		}

		/**
		 * Eagerness value for a numeric ladder level (ladder helper).
		 *
		 * Inverse of eagerness_level(): levels are clamped to 0-2 so a
		 * one-step downgrade from conservative floors at conservative.
		 *
		 * @since 2.3.0
		 * @param int $level Numeric ladder level.
		 * @return string Allowlisted eagerness value.
		 */
		private static function eagerness_from_level( int $level ): string {
			$reverse = array(
				0 => 'conservative',
				1 => 'moderate',
				2 => 'eager',
			);
			return $reverse[ max( 0, min( 2, $level ) ) ] ?? 'conservative';
		}

		/**
		 * Device/template/connection labels for a slow-path segment.
		 *
		 * Centralizes the segment triple extraction shared by the slow-path
		 * preload and downgrade cards so copy stays consistent.
		 *
		 * @since 2.3.0
		 * @param mixed $segment Segment descriptor (or null).
		 * @return array{0:string,1:string,2:string} Device, template, connection labels.
		 */
		private static function slow_segment_labels( $segment ): array {
			$segment = is_array( $segment ) ? $segment : array();
			return array(
				isset( $segment['device'] ) ? (string) $segment['device'] : 'unknown',
				isset( $segment['template'] ) ? (string) $segment['template'] : 'unknown',
				self::segment_connection( $segment ),
			);
		}

		/**
		 * Sanitize a persisted/live slow-path preload URL for suggestion copy.
		 *
		 * Trims + truncates to 2048 chars, drops empty esc_url_raw() output,
		 * and re-checks same-origin (strict variant when available) so a
		 * stale or tampered model value (off-origin, javascript:) can never
		 * be rendered into suggestion copy. Fail-open to '' on any error.
		 *
		 * @since 2.3.0
		 * @param mixed $url Raw URL value.
		 * @return string Sanitized URL or '' when invalid.
		 */
		private static function sanitize_slow_preload_url( $url ): string {
			try {
				if ( ! is_string( $url ) ) {
					return '';
				}
				$url = substr( trim( $url ), 0, 2048 );
				if ( '' === $url ) {
					return '';
				}
				if ( function_exists( 'esc_url_raw' ) ) {
					$clean = esc_url_raw( $url );
					if ( ! is_string( $clean ) || '' === trim( $clean ) ) {
						return '';
					}
					$url = trim( $clean );
				}
				if ( class_exists( 'PerformanceOptimise\Inc\RUM' ) ) {
					if ( method_exists( 'PerformanceOptimise\Inc\RUM', 'is_same_origin_url_strict' ) ) {
						if ( ! \PerformanceOptimise\Inc\RUM::is_same_origin_url_strict( $url ) ) {
							return '';
						}
					} elseif ( method_exists( 'PerformanceOptimise\Inc\RUM', 'is_same_origin_url' ) ) {
						if ( ! \PerformanceOptimise\Inc\RUM::is_same_origin_url( $url ) ) {
							return '';
						}
					}
				}
				return $url;
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
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
		 * @since 2.0.0
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
		 * @since 2.0.0
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
		 * @since 2.0.0
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

				$top_inp = self::slowest_segment_row( $qualified_inp );
				$top_lcp = self::slowest_segment_row( $qualified_lcp );
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
				// Segment-routed (issue #1143): device/template/connection
				// travel together so slow-connection advice never misfires
				// as a global recommendation.
				$anchor  = ( $crosses_inp && is_array( $top_inp ) ) ? $top_inp : $top_lcp;
				$segment = null;
				if ( is_array( $anchor ) ) {
					$segment = self::segment_descriptor( $anchor );
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
		 * @since 2.0.0
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
		 * @since 2.0.0
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
		 * @since 2.0.0
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
			$rum_summary = array_slice( $scores, 0, 10 );

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
		 * @since 2.0.0
		 * @since 2.2.0 Global-average eagerness upgrades are gated on total LCP
		 *             samples reaching field_lcp_min_samples (suggest-only below).
		 */
		private static function heuristic_learn( ?array $rum = null, ?array $trends = null ): array {
			if ( null === $rum ) {
				// RUM-segmented auto-tune (issue #1425): prefer the read-only
				// per-request memoized aggregate (no queue flush, no transient
				// writes, per-site option so multisite-safe). Every call is
				// guarded so minimal installs without the RUM class, without
				// the read-only accessor, or without get_option() fall back to
				// the legacy direct read, then to an empty aggregate.
				if ( class_exists( 'PerformanceOptimise\Inc\RUM' ) && method_exists( 'PerformanceOptimise\Inc\RUM', 'get_aggregate_readonly' ) ) {
					try {
						$rum = RUM::get_aggregate_readonly();
					} catch ( \Throwable $e ) {
						unset( $e );
						$rum = array();
					}
				} elseif ( class_exists( 'PerformanceOptimise\Inc\RUM' ) && function_exists( 'get_option' ) ) {
					try {
						$rum = get_option( RUM::OPTION, array() );
					} catch ( \Throwable $e ) {
						unset( $e );
						$rum = array();
					}
				} else {
					$rum = array();
				}
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

			// Resolve paths to absolute URLs for speculation, applying the
			// same guards as the RUM-gated list rule: reject query/fragment
			// keys (public-beacon-influenced), then same-site + commerce
			// filtering before persisting.
			$prefetch_urls = array();
			foreach ( $top_paths as $path ) {
				if ( ! is_string( $path ) || '' === $path ) {
					continue;
				}
				if ( false !== strpos( $path, '?' ) || false !== strpos( $path, '#' ) ) {
					continue;
				}
				$url             = Util::cached_home_url( $path );
				$prefetch_urls[] = esc_url_raw( $url );
			}
			$prefetch_urls = self::filter_same_site_urls( $prefetch_urls );
			$excludes      = self::get_commerce_exclude_paths();
			$prefetch_urls = array_values(
				array_filter(
					$prefetch_urls,
					static function ( $candidate ) use ( $excludes ) {
						return ! self::is_speculation_commerce_url( $candidate, $excludes );
					}
				)
			);

			// RUM-segmented auto-tune floor (issue #1425): when the opt-in
			// auto-tune is on and the aggregate is undersampled (peak
			// per-path sample count below speculation_min_samples), emit
			// zero list URLs so the filter renders core defaults only. The
			// legacy path (auto-tune off) keeps its top-2 behaviour
			// verbatim. Never touches stored manual settings.
			if ( ! empty( $prefetch_urls ) ) {
				try {
					if ( self::is_speculation_autotune_enabled() ) {
						$peak = 0;
						foreach ( $rum as $paths ) {
							if ( ! is_array( $paths ) ) {
								continue;
							}
							foreach ( $paths as $metrics ) {
								if ( ! is_array( $metrics ) ) {
									continue;
								}
								// Shared counter with get_rum_top_speculation_urls():
								// peak across ALL RUM metrics so the floor and the
								// volume ranking agree on the same sample counts.
								$peak = max( $peak, self::peak_rum_metric_samples( $metrics ) );
							}
						}
						if ( $peak < self::speculation_min_samples() ) {
							$prefetch_urls = array();
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			// Most-frequently disabled handles = least-used (candidates to exclude).
			$exclude_js  = self::get_disabled_assets( '_wppo_disabled_scripts' );
			$exclude_css = self::get_disabled_assets( '_wppo_disabled_styles' );

			// Eagerness heuristic: conservative by default, moderate if avg LCP > 2500 or high TTFB.
			// Suggest-only gating (issue #1200, @since 2.2.0): the global-average
			// ladder below only upgrades when total LCP samples reach the shared
			// field-LCP minimum (ai_adaptive.field_lcp_min_samples, default 20).
			// Undersampled RUM stays conservative on the global path so no
			// eagerness override is emitted without qualified field data. The
			// segmented ladder below has its own per-segment n >= min gate and
			// may still upgrade a conservative global baseline when a qualified
			// slow segment exists.
			$eagerness     = 'conservative';
			$avg_lcp_all   = 0;
			$path_count    = 0;
			$total_lcp_sum = 0.0;
			$total_lcp_n   = 0;
			$field_lcp_min = self::field_lcp_min_samples();
			foreach ( $rum as $date => $paths ) {
				if ( ! is_array( $paths ) ) {
					continue;
				}
				foreach ( $paths as $path => $metrics ) {
					if ( isset( $metrics['lcp']['sum'], $metrics['lcp']['n'] ) && $metrics['lcp']['n'] > 0 ) {
						// Sample-weighted accumulation so the estimator matches
						// the total-sample gate: a low-n outlier bucket cannot
						// outweigh high-n buckets the way a mean-of-means would.
						$total_lcp_sum += (float) $metrics['lcp']['sum'];
						$total_lcp_n   += (int) $metrics['lcp']['n'];
						++$path_count;
					}
				}
			}
			if ( 0 < $path_count && $total_lcp_n >= $field_lcp_min ) {
				$avg_lcp_all = $total_lcp_sum / $total_lcp_n;
				if ( $avg_lcp_all > 3500 ) {
					$eagerness = 'eager';
				} elseif ( $avg_lcp_all > 2500 ) {
					$eagerness = 'moderate';
				}
			}

			// Field-data-driven tuning (issues #986, #1143, #1200): route segmented
			// device × template × connection p75 into the eagerness decision.
			// Below the minimum threshold no eagerness upgrade is emitted
			// (the global-average path above is already gated on total samples);
			// at/above threshold the slowest-p75 segment (slowest
			// device/connection/template wins, so segment-skewed RUM cannot
			// misfire as global advice) may upgrade eagerness via the same
			// ladder (never downgrades the global result). Fail-open: RUM
			// failures keep the global path.
			$field_lcp_provisional = true;
			$field_lcp_segment     = null;
			$field_lcp_p75         = 0.0;
			$field_lcp_samples     = 0;
			// Upgrade-only: when the global-average path already sits at the top
			// of the eagerness ladder (`eager`), the segmented lookup can never
			// raise it, so skip the full RUM option scan entirely. Reporting
			// fields still carry the qualified global sample count so REST/UI
			// copy never pairs an eager suggestion with provisional (0/N)
			// copy (provisional stays true: no qualified segment was observed).
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
					$slowest = self::slowest_segment_row( $qualified );
					if ( is_array( $slowest ) ) {
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
						$field_lcp_segment     = self::segment_descriptor( $slowest );
						$field_lcp_p75         = $segment_p75;
						$field_lcp_samples     = isset( $slowest['n'] ) ? (int) $slowest['n'] : $max_n;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			} else {
				// Global-eager skip path (no segment scan): report the
				// qualified global sample count so the persisted model never
				// pairs `eager` with a zero-sample provisional copy.
				$field_lcp_samples = $total_lcp_n;
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
			 * @since 2.0.0
			 * @param string $eagerness Eagerness value.
			 * @param array  $rum RUM aggregates.
			 */
			$eagerness = apply_filters( 'wppo_ai_adaptive_eagerness', $eagerness, $rum );
			// Guardrail (#908): allowlist then cap at `moderate` in commerce/auth
			// contexts AFTER the filter, so a third-party filter returning
			// `eager` is still tightened.
			$eagerness = self::normalize_eagerness( $eagerness );

			// LCP-element attribution + slow-resource audit (issue #1311):
			// read-only candidates for preload/speculation suggestions.
			// Additive keys with defaults; lazily booted (only queried when
			// RUM data exists); fail-open to null/empty on any error; never
			// auto-applied — get_suggestions() renders them as suggestions.
			$attributed_selector = null;
			$slow_candidates     = array();
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\RUM' ) && method_exists( 'PerformanceOptimise\Inc\RUM', 'get_top_slow_resources' ) && method_exists( 'PerformanceOptimise\Inc\RUM', 'get_top_lcp_selector' ) ) {
					if ( ! empty( $top_paths ) && is_string( $top_paths[0] ) ) {
						$top_selector = \PerformanceOptimise\Inc\RUM::get_top_lcp_selector( $top_paths[0] );
						if ( is_array( $top_selector ) && isset( $top_selector['selector'] ) && is_string( $top_selector['selector'] ) && '' !== $top_selector['selector'] ) {
							$attributed_selector = array(
								'path'     => $top_paths[0],
								'selector' => $top_selector['selector'],
								'n'        => isset( $top_selector['n'] ) ? (int) $top_selector['n'] : 0,
								'lastSeen' => isset( $top_selector['lastSeen'] ) ? (int) $top_selector['lastSeen'] : 0,
							);
						}
					}
					$slow_rows = \PerformanceOptimise\Inc\RUM::get_top_slow_resources( 3 );
					if ( is_array( $slow_rows ) ) {
						foreach ( array_slice( $slow_rows, 0, 3 ) as $row ) {
							if ( ! is_array( $row ) || empty( $row['url'] ) || ! is_string( $row['url'] ) ) {
								continue;
							}
							$slow_candidates[] = array(
								'url'         => $row['url'],
								'type'        => isset( $row['type'] ) && is_string( $row['type'] ) ? $row['type'] : 'img',
								'n'           => isset( $row['n'] ) ? (int) $row['n'] : 0,
								'avgDuration' => isset( $row['avgDuration'] ) ? (float) $row['avgDuration'] : 0.0,
								'maxDuration' => isset( $row['maxDuration'] ) ? (float) $row['maxDuration'] : 0.0,
							);
						}
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				$attributed_selector = null;
				$slow_candidates     = array();
			}

			// RUM-driven slow-top-path auto-tune (issue #1463): consume the
			// segmented field-LCP read path; when the top-path p75 exceeds
			// 2500ms resolve exactly one preload hero plus a downgrade
			// target (suggest-only — get_suggestions() renders them, never
			// auto-applied). Below the sample threshold slow is false so
			// callers keep static defaults with the manual metabox hero
			// winning and no speculation change. Read-only, fail-open, zero
			// external HTTP, no option/transient writes here.
			$slow_path = array(
				'slow'        => false,
				'path'        => '',
				'segment'     => null,
				'p75'         => 0.0,
				'samples'     => 0,
				'min_samples' => $field_lcp_min,
				'preload_url' => '',
			);
			try {
				$live_slow = self::get_slow_top_path_state();
				if ( is_array( $live_slow ) && ! empty( $live_slow ) ) {
					$slow_path = array_merge( $slow_path, $live_slow );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			$slow_downgrade = 'conservative';
			try {
				$slow_downgrade = self::get_slow_path_downgrade_target( $eagerness );
			} catch ( \Throwable $e ) {
				unset( $e );
			}

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
				'attributed_lcp'        => $attributed_selector,
				'slow_resources'        => $slow_candidates,
				'slow_path_slow'        => ! empty( $slow_path['slow'] ),
				'slow_path_path'        => isset( $slow_path['path'] ) && is_string( $slow_path['path'] ) ? $slow_path['path'] : '',
				'slow_path_segment'     => isset( $slow_path['segment'] ) && is_array( $slow_path['segment'] ) ? $slow_path['segment'] : null,
				'slow_path_p75'         => isset( $slow_path['p75'] ) ? (float) $slow_path['p75'] : 0.0,
				'slow_path_samples'     => isset( $slow_path['samples'] ) ? (int) $slow_path['samples'] : 0,
				'slow_path_min_samples' => isset( $slow_path['min_samples'] ) ? (int) $slow_path['min_samples'] : $field_lcp_min,
				'slow_path_preload_url' => isset( $slow_path['preload_url'] ) && is_string( $slow_path['preload_url'] ) ? $slow_path['preload_url'] : '',
				'slow_path_downgrade'   => $slow_downgrade,
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
		 * Facade proxy (ARCH-010): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ai_Anomaly::get_deploy_notes}.
		 *
		 * @since 2.4.0
		 * @return array Result (see Ai_Anomaly).
		 */
		public static function get_deploy_notes(): array {
			return Ai_Anomaly::get_deploy_notes();
		}

		/**
		 * Facade proxy (ARCH-010): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ai_Anomaly::add_deploy_note}.
		 *
		 * @since 2.4.0
		 * @param string   $note Parameter (see Ai_Anomaly).
		 * @param int|null $ts Parameter (see Ai_Anomaly).
		 * @return bool Result (see Ai_Anomaly).
		 */
		public static function add_deploy_note( string $note, ?int $ts = null ): bool {
			return Ai_Anomaly::add_deploy_note( $note, $ts );
		}

		/**
		 * Facade proxy (ARCH-010): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ai_Anomaly::find_deploy_note_near}.
		 *
		 * @since 2.4.0
		 * @param int $breach_ts Parameter (see Ai_Anomaly).
		 * @return string Result (see Ai_Anomaly).
		 */
		public static function find_deploy_note_near( int $breach_ts ): string {
			return Ai_Anomaly::find_deploy_note_near( $breach_ts );
		}

		/**
		 * Facade proxy (ARCH-010): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ai_Anomaly::get_breach_state}.
		 *
		 * @since 2.4.0
		 * @return array Result (see Ai_Anomaly).
		 */
		public static function get_breach_state(): array {
			return Ai_Anomaly::get_breach_state();
		}

		/**
		 * Facade proxy (ARCH-010): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ai_Anomaly::set_breach_state}.
		 *
		 * @since 2.4.0
		 * @param array $state Parameter (see Ai_Anomaly).
		 * @return void
		 */
		public static function set_breach_state( array $state ): void {
			Ai_Anomaly::set_breach_state( $state );
		}

		/**
		 * Facade proxy (ARCH-010): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ai_Anomaly::detect_recoveries}.
		 *
		 * @since 2.4.0
		 * @param array|null $trends Parameter (see Ai_Anomaly).
		 * @param int|null   $now Parameter (see Ai_Anomaly).
		 * @return array Result (see Ai_Anomaly).
		 */
		public static function detect_recoveries( ?array $trends = null, ?int $now = null ): array {
			return Ai_Anomaly::detect_recoveries( $trends, $now );
		}

		/**
		 * Facade proxy (ARCH-010): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ai_Anomaly::get_last_anomaly_alarm}.
		 *
		 * @since 2.4.0
		 * @return int Result (see Ai_Anomaly).
		 */
		public static function get_last_anomaly_alarm(): int {
			return Ai_Anomaly::get_last_anomaly_alarm();
		}

		/**
		 * Facade proxy (ARCH-010): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ai_Anomaly::set_last_anomaly_alarm}.
		 *
		 * @since 2.4.0
		 * @param int $ts Parameter (see Ai_Anomaly).
		 * @return void
		 */
		public static function set_last_anomaly_alarm( int $ts ): void {
			Ai_Anomaly::set_last_anomaly_alarm( $ts );
		}

		/**
		 * Facade proxy (ARCH-010): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ai_Anomaly::is_anomaly_cooled_down}.
		 *
		 * @since 2.4.0
		 * @param int|null $now Parameter (see Ai_Anomaly).
		 * @return bool Result (see Ai_Anomaly).
		 */
		public static function is_anomaly_cooled_down( ?int $now = null ): bool {
			return Ai_Anomaly::is_anomaly_cooled_down( $now );
		}

		/**
		 * Facade proxy (ARCH-010): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ai_Anomaly::get_rum_anomaly_digest}.
		 *
		 * @since 2.4.0
		 * @param array|null $rum Parameter (see Ai_Anomaly).
		 * @param int|null   $now Parameter (see Ai_Anomaly).
		 * @return array Result (see Ai_Anomaly).
		 */
		public static function get_rum_anomaly_digest( ?array $rum = null, ?int $now = null ): array {
			return Ai_Anomaly::get_rum_anomaly_digest( $rum, $now );
		}

		/**
		 * Facade proxy (ARCH-010): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ai_Anomaly::detect_anomalies}.
		 *
		 * @since 2.4.0
		 * @param array|null $trends Parameter (see Ai_Anomaly).
		 * @param array|null $rum Parameter (see Ai_Anomaly).
		 * @param int|null   $now Parameter (see Ai_Anomaly).
		 * @return array Result (see Ai_Anomaly).
		 */
		public static function detect_anomalies( ?array $trends = null, ?array $rum = null, ?int $now = null ): array {
			return Ai_Anomaly::detect_anomalies( $trends, $rum, $now );
		}

		/**
		 * Facade proxy (ARCH-010): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ai_Anomaly::is_css_refresh_enabled}.
		 *
		 * @since 2.4.0
		 * @return bool Result (see Ai_Anomaly).
		 */
		public static function is_css_refresh_enabled(): bool {
			return Ai_Anomaly::is_css_refresh_enabled();
		}

		/**
		 * Facade proxy (ARCH-010): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ai_Anomaly::css_refresh_cooldown_days}.
		 *
		 * @since 2.4.0
		 * @return int Result (see Ai_Anomaly).
		 */
		public static function css_refresh_cooldown_days(): int {
			return Ai_Anomaly::css_refresh_cooldown_days();
		}

		/**
		 * Facade proxy (ARCH-010): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ai_Anomaly::resolve_anomaly_url}.
		 *
		 * @since 2.4.0
		 * @param string $trend_key Parameter (see Ai_Anomaly).
		 * @return string Result (see Ai_Anomaly).
		 */
		public static function resolve_anomaly_url( string $trend_key ): string {
			return Ai_Anomaly::resolve_anomaly_url( $trend_key );
		}

		/**
		 * Facade proxy (ARCH-010): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ai_Anomaly::is_homepage_url}.
		 *
		 * @since 2.4.0
		 * @param string $url Parameter (see Ai_Anomaly).
		 * @return bool Result (see Ai_Anomaly).
		 */
		public static function is_homepage_url( string $url ): bool {
			return Ai_Anomaly::is_homepage_url( $url );
		}

		/**
		 * Facade proxy (ARCH-010): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ai_Anomaly::resolve_front_page_post_id}.
		 *
		 * @since 2.4.0
		 * @return int Result (see Ai_Anomaly).
		 */
		public static function resolve_front_page_post_id(): int {
			return Ai_Anomaly::resolve_front_page_post_id();
		}

		/**
		 * Facade proxy (ARCH-010): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ai_Anomaly::resolve_anomaly_post_id}.
		 *
		 * @since 2.4.0
		 * @param string $url Parameter (see Ai_Anomaly).
		 * @return int Result (see Ai_Anomaly).
		 */
		public static function resolve_anomaly_post_id( string $url ): int {
			return Ai_Anomaly::resolve_anomaly_post_id( $url );
		}

		/**
		 * Facade proxy (ARCH-010): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ai_Anomaly::get_css_refresh_snapshot}.
		 *
		 * @since 2.4.0
		 * @param string $url Parameter (see Ai_Anomaly).
		 * @return array Result (see Ai_Anomaly).
		 */
		public static function get_css_refresh_snapshot( string $url ): array {
			return Ai_Anomaly::get_css_refresh_snapshot( $url );
		}

		/**
		 * Facade proxy (ARCH-010): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ai_Anomaly::maybe_queue_css_refresh}.
		 *
		 * @since 2.4.0
		 * @param array    $anomaly Parameter (see Ai_Anomaly).
		 * @param int|null $now Parameter (see Ai_Anomaly).
		 * @return array Result (see Ai_Anomaly).
		 */
		public static function maybe_queue_css_refresh( array $anomaly, ?int $now = null ): array {
			return Ai_Anomaly::maybe_queue_css_refresh( $anomaly, $now );
		}

		/**
		 * Facade proxy (ARCH-010): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ai_Anomaly::get_anomaly_provisional_state}.
		 *
		 * @since 2.4.0
		 * @param array|null $trends Parameter (see Ai_Anomaly).
		 * @param array|null $rum Parameter (see Ai_Anomaly).
		 * @param int|null   $now Parameter (see Ai_Anomaly).
		 * @return array Result (see Ai_Anomaly).
		 */
		public static function get_anomaly_provisional_state( ?array $trends = null, ?array $rum = null, ?int $now = null ): array {
			return Ai_Anomaly::get_anomaly_provisional_state( $trends, $rum, $now );
		}

		/**
		 * Per-request memo of disabled-asset aggregates keyed by meta key (audit #982).
		 *
		 * @since 2.0.0
		 * @var array<string, string[]>
		 */
		private static array $disabled_assets_cache = array();

		/**
		 * Reset the per-request disabled-asset memo (for testing).
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function reset_disabled_assets_cache(): void {
			self::$disabled_assets_cache = array();
		}

		/**
		 * Rank serialized handle lists into top-3 handles by frequency.
		 *
		 * @since 2.0.0
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
		 * @since 2.0.0
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
		 * @since 2.0.0
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
		 * @since 2.0.0
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
		 * GET-with-side-effect (issue #1407, intentional): this method is
		 * served by the `ai_suggestions` GET endpoint (via
		 * Suggestion_Engine::from_ai_adaptive()), so merely rendering the
		 * dashboard may mutate state. When a field-LCP regression fires,
		 * rendering the LCP suggestion bridges to
		 * maybe_queue_css_refresh(), which may enqueue at most one
		 * `wppo_used_css_generate` Action Scheduler job per URL per
		 * cooldown window (minimum 1 day, default 7) plus one cooldown
		 * transient and one bounded (20-entry) snapshot-option write, only
		 * on an actual queue, plus the `wppo_ai_css_refresh_queued`
		 * action. The bridge runs only when the
		 * `ai_adaptive.css_refresh_on_lcp_regression` opt-in is on
		 * (default off/suggest-only), only for admin-capability (`manage_options`)
		 * callers, and is fail-open (any failure degrades to a plain
		 * suggestion-only card). There is no per-URL confirmation: every
		 * new regression auto-queues once per cooldown window. Callers
		 * that need a pure suggest-only render must ensure the opt-in is
		 * off (or the `wppo_ai_css_refresh_enabled` filter returns false).
		 *
		 * @return array[]
		 * @since 2.0.0
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
			// Field LCP segment context (issues #986, #1143): prefer persisted
			// model keys, fall back to a live read-only lookup for models
			// stored before the segmentation shipped. Fail-open to provisional.
			// Pre-connection models are normalized to `connection: unknown`.
			$field_segment     = isset( $model['field_lcp_segment'] ) && is_array( $model['field_lcp_segment'] ) ? self::segment_descriptor( $model['field_lcp_segment'] ) : null;
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
					$top = self::slowest_segment_row( $qualified );
					if ( is_array( $top ) ) {
						$field_segment     = self::segment_descriptor( $top );
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
					$seg_device     = isset( $field_segment['device'] ) ? (string) $field_segment['device'] : 'unknown';
					$seg_template   = isset( $field_segment['template'] ) ? (string) $field_segment['template'] : 'unknown';
					$seg_connection = self::segment_connection( $field_segment );
					/* translators: %1$s eagerness, %2$s device, %3$s template, %4$s connection, %5$s p75. */
					$eagerness_value = sprintf( __( '%1$s · %2$s · %3$s · %4$s · p75 %5$s', 'performance-optimisation' ), $eagerness, $seg_device, $seg_template, $seg_connection, self::format_p75_seconds( $field_p75 ) );
					/* translators: %1$s device, %2$s template, %3$s connection, %4$s p75 seconds. */
					$eagerness_description = sprintf( __( 'AI: Speculation eagerness suggestion (field LCP %1$s/%2$s/%3$s p75 %4$s)', 'performance-optimisation' ), $seg_device, $seg_template, $seg_connection, self::format_p75_seconds( $field_p75 ) );
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

			// Field LCP auto-tune suggestion (issues #986, #1143): at/above
			// threshold the copy names device + template + connection + p75;
			// below threshold the copy reads provisional with no eagerness upgrade.
			if ( ! $field_provisional && is_array( $field_segment ) ) {
				$seg_device     = isset( $field_segment['device'] ) ? (string) $field_segment['device'] : 'unknown';
				$seg_template   = isset( $field_segment['template'] ) ? (string) $field_segment['template'] : 'unknown';
				$seg_connection = self::segment_connection( $field_segment );
				/* translators: %1$s device, %2$s template, %3$s connection, %4$s p75 seconds. */
				$field_value = sprintf( __( '%1$s · %2$s · %3$s · p75 %4$s', 'performance-optimisation' ), $seg_device, $seg_template, $seg_connection, self::format_p75_seconds( $field_p75 ) );
				/* translators: %1$s device, %2$s template, %3$s connection, %4$s p75 seconds, %5$d sample count. */
				$field_description = sprintf( __( 'AI: Field LCP tune for %1$s/%2$s/%3$s (p75 %4$s, %5$d samples)', 'performance-optimisation' ), $seg_device, $seg_template, $seg_connection, self::format_p75_seconds( $field_p75 ), $field_samples );
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
						$delay_device     = ( is_array( $delay_segment ) && isset( $delay_segment['device'] ) ) ? (string) $delay_segment['device'] : 'unknown';
						$delay_template   = ( is_array( $delay_segment ) && isset( $delay_segment['template'] ) ) ? (string) $delay_segment['template'] : 'unknown';
						$delay_connection = self::segment_connection( $delay_segment );
						// Anchor the copy on the crossed signal (prefer INP).
						$crossed_inp      = (float) $delay_inp > self::INP_P75_DELAY_THRESHOLD_MS;
						$delay_anchor_p75 = $crossed_inp ? (float) $delay_inp : (float) $delay_lcp;
						if ( $crossed_inp ) {
							/* translators: %1$s level, %2$s device, %3$s template, %4$s connection, %5$s p75 seconds. */
							$delay_value = sprintf( __( '%1$s · %2$s · %3$s · %4$s · INP p75 %5$s', 'performance-optimisation' ), $delay_level, $delay_device, $delay_template, $delay_connection, self::format_p75_seconds( (float) $delay_anchor_p75 ) );
							/* translators: %1$s device, %2$s template, %3$s connection, %4$s p75 seconds, %5$d sample count. */
							$delay_description = sprintf( __( 'AI: Delay JavaScript suggestion for %1$s/%2$s/%3$s (INP p75 %4$s, %5$d samples)', 'performance-optimisation' ), $delay_device, $delay_template, $delay_connection, self::format_p75_seconds( (float) $delay_anchor_p75 ), (int) $delay_samples );
						} else {
							/* translators: %1$s level, %2$s device, %3$s template, %4$s connection, %5$s p75 seconds. */
							$delay_value = sprintf( __( '%1$s · %2$s · %3$s · %4$s · LCP p75 %5$s', 'performance-optimisation' ), $delay_level, $delay_device, $delay_template, $delay_connection, self::format_p75_seconds( (float) $delay_anchor_p75 ) );
							/* translators: %1$s device, %2$s template, %3$s connection, %4$s p75 seconds, %5$d sample count. */
							$delay_description = sprintf( __( 'AI: Delay JavaScript suggestion for %1$s/%2$s/%3$s (LCP p75 %4$s, %5$d samples)', 'performance-optimisation' ), $delay_device, $delay_template, $delay_connection, self::format_p75_seconds( (float) $delay_anchor_p75 ), (int) $delay_samples );
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

			// RUM-driven slow-top-path tune (issue #1463): consume the
			// segmented field-LCP read path; when the top-path p75 exceeds
			// 2500ms collapse to exactly one preload candidate plus an
			// eagerness downgrade (both suggest-only, never auto-applied).
			// Below the sample threshold keep static defaults with the
			// manual metabox hero URL winning and no speculation change.
			// Fail-open: any failure emits nothing new. Zero external HTTP.
			$slow_is_slow     = false;
			$slow_path        = '';
			$slow_segment     = null;
			$slow_p75         = 0.0;
			$slow_samples     = 0;
			$slow_min         = self::field_lcp_min_samples();
			$slow_preload_url = '';
			$slow_downgrade   = 'conservative';
			try {
				if ( array_key_exists( 'slow_path_slow', $model ) ) {
					$slow_is_slow     = ! empty( $model['slow_path_slow'] );
					$slow_path        = isset( $model['slow_path_path'] ) && is_string( $model['slow_path_path'] ) ? $model['slow_path_path'] : '';
					$slow_segment     = isset( $model['slow_path_segment'] ) && is_array( $model['slow_path_segment'] ) ? self::segment_descriptor( $model['slow_path_segment'] ) : null;
					$slow_p75         = isset( $model['slow_path_p75'] ) ? (float) $model['slow_path_p75'] : 0.0;
					$slow_samples     = isset( $model['slow_path_samples'] ) ? (int) $model['slow_path_samples'] : 0;
					$slow_min         = isset( $model['slow_path_min_samples'] ) ? (int) $model['slow_path_min_samples'] : $slow_min;
					$slow_preload_url = isset( $model['slow_path_preload_url'] ) ? self::sanitize_slow_preload_url( $model['slow_path_preload_url'] ) : '';
				} else {
					$live_slow = self::get_slow_top_path_state();
					if ( is_array( $live_slow ) ) {
						$slow_is_slow     = ! empty( $live_slow['slow'] );
						$slow_path        = isset( $live_slow['path'] ) && is_string( $live_slow['path'] ) ? $live_slow['path'] : '';
						$slow_segment     = isset( $live_slow['segment'] ) && is_array( $live_slow['segment'] ) ? self::segment_descriptor( $live_slow['segment'] ) : null;
						$slow_p75         = isset( $live_slow['p75'] ) ? (float) $live_slow['p75'] : 0.0;
						$slow_samples     = isset( $live_slow['samples'] ) ? (int) $live_slow['samples'] : 0;
						$slow_min         = isset( $live_slow['min_samples'] ) ? (int) $live_slow['min_samples'] : $slow_min;
						$slow_preload_url = isset( $live_slow['preload_url'] ) ? self::sanitize_slow_preload_url( $live_slow['preload_url'] ) : '';
					}
				}
				// Re-derive the downgrade from the live eagerness when the
				// persisted value is missing/invalid so stale models cannot
				// propose an upgrade as a "downgrade".
				$slow_downgrade = self::get_slow_path_downgrade_target( $eagerness );
				if ( isset( $model['slow_path_downgrade'] ) && is_string( $model['slow_path_downgrade'] ) && in_array( $model['slow_path_downgrade'], array( 'conservative', 'moderate', 'eager' ), true ) ) {
					$candidate_downgrade = self::normalize_eagerness( $model['slow_path_downgrade'] );
					// Never let a stale persisted value loosen above the
					// freshly derived one-step-down target.
					if ( self::eagerness_level( $candidate_downgrade ) <= self::eagerness_level( $slow_downgrade ) ) {
						$slow_downgrade = $candidate_downgrade;
					}
				}
				if ( $slow_min < 1 ) {
					$slow_min = self::field_lcp_min_samples();
				}
				// Gate: below the sample threshold (or uncrossed p75) there
				// is no override — static defaults stay with the manual
				// metabox hero winning and no speculation change.
				if ( $slow_samples < $slow_min || $slow_p75 <= self::LCP_P75_DELAY_THRESHOLD_MS ) {
					$slow_is_slow = false;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				$slow_is_slow = false;
			}

			$prefetch = $model['prefetch_urls'] ?? array();
			if ( is_array( $prefetch ) && ! empty( $prefetch ) ) {
				// Slow top path: fewer speculative fetches — collapse the
				// prefetch list to the single top URL.
				$prefetch_limit = $slow_is_slow ? 1 : 2;
				$suggestions[]  = array(
					'metric'      => 'ai_prefetch_urls',
					'value'       => implode( ', ', array_slice( $prefetch, 0, $prefetch_limit ) ),
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

			// LCP-element attribution + slow-resource audit (issue #1311):
			// read-only preload/speculation candidates naming the exact hero
			// element and the slowest sub-resources. Fail-open: missing keys
			// (models persisted before attribution shipped) or lookup errors
			// emit nothing. Suggestions only — never auto-applied.
			// Slow-top-path tune (issue #1463): when the segmented top-path
			// p75 is slow these collapse to exactly one consolidated preload
			// suggestion (ai_slow_path_preload) emitted below, so the legacy
			// dual candidates are suppressed to avoid double preloads.
			try {
				if ( ! $slow_is_slow ) {
					$attributed = $model['attributed_lcp'] ?? null;
					// Models persisted before attribution shipped simply emit no
					// suggestions for missing keys (fail open).
					if ( is_array( $attributed ) && isset( $attributed['selector'] ) && is_string( $attributed['selector'] ) && '' !== $attributed['selector'] ) {
						$attr_path     = isset( $attributed['path'] ) && is_string( $attributed['path'] ) ? $attributed['path'] : '';
						$attr_selector = $attributed['selector'];
						/* translators: %1$s LCP selector, %2$s page path. */
						$attr_value = sprintf( __( '%1$s on %2$s', 'performance-optimisation' ), $attr_selector, '' !== $attr_path ? $attr_path : __( 'top page', 'performance-optimisation' ) );
						/* translators: %1$s LCP selector, %2$s page path. */
						$attr_description = sprintf( __( 'AI: Preload hero element %1$s on %2$s', 'performance-optimisation' ), $attr_selector, '' !== $attr_path ? $attr_path : __( 'top page', 'performance-optimisation' ) );
						$suggestions[]    = array(
							'metric'      => 'ai_lcp_preload',
							'value'       => $attr_value,
							'unit'        => 'string',
							'status'      => 'needs_improvement',
							'description' => $attr_description,
							'fix_action'  => 'open_preload_tab',
							'ai_payload'  => array(
								'tab'      => 'preload_settings',
								'settings' => array(),
							),
						);
					}
					$slow_list = $model['slow_resources'] ?? array();
					if ( is_array( $slow_list ) && ! empty( $slow_list ) ) {
						$slow_first = $slow_list[0];
						if ( is_array( $slow_first ) && isset( $slow_first['url'] ) && is_string( $slow_first['url'] ) && '' !== $slow_first['url'] ) {
							$slow_type = isset( $slow_first['type'] ) && is_string( $slow_first['type'] ) ? $slow_first['type'] : 'resource';
							/* translators: %1$s resource type, %2$s resource URL. */
							$slow_value = sprintf( __( '%1$s · %2$s', 'performance-optimisation' ), $slow_type, $slow_first['url'] );
							/* translators: %1$s resource type, %2$s resource URL. */
							$slow_description = sprintf( __( 'AI: Preload slow resource %1$s (%2$s)', 'performance-optimisation' ), $slow_first['url'], $slow_type );
							$suggestions[]    = array(
								'metric'      => 'ai_slow_resource_preload',
								'value'       => $slow_value,
								'unit'        => 'string',
								'status'      => 'needs_improvement',
								'description' => $slow_description,
								'fix_action'  => 'open_preload_tab',
								'ai_payload'  => array(
									'tab'      => 'preload_settings',
									'settings' => array(),
								),
							);
						}
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			// Slow-top-path tune suggestions (issue #1463): exactly one
			// preload candidate plus an eagerness downgrade, suggest-only
			// (never auto-applied — the user applies them from the preload
			// tab). Field truth picks the hero: the RUM-measured preload URL
			// wins when qualified; otherwise the copy names the manual
			// metabox hero as winning with static defaults kept. Commerce /
			// auth contexts stay capped at moderate via the downgrade
			// target. Zero external HTTP. Dismissible via the standard
			// dismissed-suggestions filter below.
			try {
				if ( $slow_is_slow && ! self::is_suggestion_dismissed( 'ai_slow_path_preload' ) ) {
					list( $slow_device, $slow_template, $slow_connection ) = self::slow_segment_labels( $slow_segment );
					if ( '' !== $slow_preload_url ) {
						/* translators: %1$s preload URL, %2$s page path. */
						$slow_value = sprintf( __( '%1$s on %2$s', 'performance-optimisation' ), $slow_preload_url, '' !== $slow_path ? $slow_path : __( 'top page', 'performance-optimisation' ) );
						/* translators: %1$s device, %2$s template, %3$s connection, %4$s p75 seconds, %5$d sample count. */
						$slow_description = sprintf( __( 'AI: Preload hero for slow path %1$s/%2$s/%3$s (field LCP p75 %4$s, %5$d samples) — suggest-only', 'performance-optimisation' ), $slow_device, $slow_template, $slow_connection, self::format_p75_seconds( $slow_p75 ), $slow_samples );
					} else {
						/* translators: %1$d observed samples, %2$d required samples. */
						$slow_value = sprintf( __( 'manual hero wins (%1$d/%2$d samples) — set via metabox', 'performance-optimisation' ), $slow_samples, $slow_min );
						/* translators: %1$s page path, %2$s p75 seconds. */
						$slow_description = sprintf( __( 'AI: Slow path %1$s (field LCP p75 %2$s) — no qualified hero yet, manual metabox image wins; static defaults kept', 'performance-optimisation' ), '' !== $slow_path ? $slow_path : __( 'top page', 'performance-optimisation' ), self::format_p75_seconds( $slow_p75 ) );
					}
					$suggestions[] = array(
						'metric'      => 'ai_slow_path_preload',
						'value'       => $slow_value,
						'unit'        => 'string',
						'status'      => 'needs_improvement',
						'description' => $slow_description,
						'fix_action'  => 'open_preload_tab',
						'ai_payload'  => array(
							'tab'      => 'preload_settings',
							'settings' => array(),
						),
					);
				}
				if ( $slow_is_slow && ! self::is_suggestion_dismissed( 'ai_speculation_downgrade' ) ) {
					list( $slow_device, $slow_template, $slow_connection ) = self::slow_segment_labels( $slow_segment );
					/* translators: %1$s eagerness, %2$s device, %3$s template, %4$s connection, %5$s p75 seconds. */
					$downgrade_value = sprintf( __( '%1$s · %2$s · %3$s · %4$s · p75 %5$s', 'performance-optimisation' ), $slow_downgrade, $slow_device, $slow_template, $slow_connection, self::format_p75_seconds( $slow_p75 ) );
					/* translators: %1$s eagerness, %2$s device, %3$s template, %4$s connection, %5$s p75 seconds, %6$d sample count. */
					$downgrade_description = sprintf( __( 'AI: Downgrade speculation to %1$s for slow path %2$s/%3$s/%4$s (field LCP p75 %5$s, %6$d samples) — suggest-only', 'performance-optimisation' ), $slow_downgrade, $slow_device, $slow_template, $slow_connection, self::format_p75_seconds( $slow_p75 ), $slow_samples );
					$suggestions[]         = array(
						'metric'      => 'ai_speculation_downgrade',
						'value'       => $downgrade_value,
						'unit'        => 'string',
						'status'      => 'needs_improvement',
						'description' => $downgrade_description,
						'fix_action'  => 'open_preload_tab',
						'ai_payload'  => array(
							'tab'      => 'preload_settings',
							'settings' => array( 'speculationEagerness' => $slow_downgrade ),
						),
					);
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			// Slow path wins the speculation-eagerness advice (issue #1463):
			// the base ai_speculation_eagerness card (emitted above with the
			// current eagerness) contradicts the ai_speculation_downgrade
			// card (one step down) for the same speculationEagerness setting.
			// Suppress the base card post-hoc when slow so only one
			// speculation-eagerness payload is offered.
			if ( $slow_is_slow ) {
				$suggestions = array_values(
					array_filter(
						$suggestions,
						static function ( $s ) {
							return ! ( is_array( $s ) && ( $s['metric'] ?? '' ) === 'ai_speculation_eagerness' );
						}
					)
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
			// delta, RUM-corroborated) trend regression, falling back to the
			// local RUM anomaly digest (LCP/INP/CLS recent-window medians vs
			// baseline with min-sample gate + tolerance band, linking the
			// affected path and window) — all with a 7-day cooldown.
			// Fail-open: detector errors contribute zero suggestions (never
			// fatal, never white-screen). Read-only: nothing here
			// auto-applies settings. No auto-tune, no speculation
			// override here — that stays gated by is_enabled() in
			// filter_speculation_rules(). No email without opt-in.
			try {
				$anomalies = self::detect_anomalies();
			} catch ( \Throwable $e ) {
				unset( $e );
				$anomalies = array();
			}
			if ( ! is_array( $anomalies ) || empty( $anomalies ) ) {
				try {
					$anomalies = self::get_rum_anomaly_digest();
				} catch ( \Throwable $e ) {
					unset( $e );
					$anomalies = array();
				}
			}
			// v2 recovery close-the-loop (issue #1313): when no breach
			// fires, a tracked breach that stayed in-band for the
			// recovery window renders a read-only recovery notice.
			$recovery_notice = null;
			if ( ! is_array( $anomalies ) || empty( $anomalies ) ) {
				try {
					$recoveries = self::detect_recoveries();
				} catch ( \Throwable $e ) {
					unset( $e );
					$recoveries = array();
				}
				if ( is_array( $recoveries ) && ! empty( $recoveries ) ) {
					$recovery_notice = $recoveries[0];
				}
			}
			if ( is_array( $recovery_notice ) && ! empty( $recovery_notice ) ) {
				$rec_metric = $recovery_notice['metric'] ?? '';
				if ( in_array( $rec_metric, array( 'lcp', 'cls' ), true ) ) {
					$rec_route = isset( $recovery_notice['route'] ) ? (string) $recovery_notice['route'] : ( isset( $recovery_notice['key'] ) ? (string) $recovery_notice['key'] : '' );
					if ( 'cls' === $rec_metric ) {
						/* translators: %s is the recovered route. */
						$rec_description = sprintf( __( 'AI: CLS recovered on %s — back inside the band', 'performance-optimisation' ), '' !== $rec_route ? $rec_route : __( 'site', 'performance-optimisation' ) );
						$suggestions[]   = array(
							'metric'      => 'ai_cls_recovered',
							'value'       => __( 'recovered', 'performance-optimisation' ),
							'unit'        => 'string',
							'status'      => 'good',
							'description' => $rec_description,
							'fix_action'  => 'no_action_required',
							'ai_payload'  => array(
								'tab'      => 'image_optimisation',
								'settings' => array(),
								'anomaly'  => array(
									'route'     => $rec_route,
									'metric'    => 'cls',
									'recovered' => true,
									'baseline'  => isset( $recovery_notice['baseline'] ) ? (float) $recovery_notice['baseline'] : 0.0,
									'samples'   => isset( $recovery_notice['samples'] ) ? (int) $recovery_notice['samples'] : 0,
								),
							),
						);
					} else {
						/* translators: %s is the recovered route. */
						$rec_description = sprintf( __( 'AI: LCP recovered on %s — back inside the band', 'performance-optimisation' ), '' !== $rec_route ? $rec_route : __( 'site', 'performance-optimisation' ) );
						$suggestions[]   = array(
							'metric'      => 'ai_lcp_recovered',
							'value'       => __( 'recovered', 'performance-optimisation' ),
							'unit'        => 'string',
							'status'      => 'good',
							'description' => $rec_description,
							'fix_action'  => 'no_action_required',
							'ai_payload'  => array(
								'tab'      => 'file_optimisation',
								'settings' => array(),
								'anomaly'  => array(
									'route'     => $rec_route,
									'metric'    => 'lcp',
									'recovered' => true,
									'baseline'  => isset( $recovery_notice['baseline'] ) ? (float) $recovery_notice['baseline'] : 0.0,
									'samples'   => isset( $recovery_notice['samples'] ) ? (int) $recovery_notice['samples'] : 0,
								),
							),
						);
					}
				}
			}
			if ( is_array( $anomalies ) && ! empty( $anomalies ) ) {
				$anomaly = $anomalies[0];
				// Fail-closed: only explicit 'cls'/'lcp' metrics render; unknown
				// or missing metrics contribute zero suggestions (mirrors the
				// maybe_queue_css_refresh() gate which treats non-'lcp' as non-lcp).
				$anomaly_metric = $anomaly['metric'] ?? '';
				$anomaly_path   = isset( $anomaly['path'] ) && is_string( $anomaly['path'] ) && '' !== $anomaly['path'] ? ( function_exists( 'mb_substr' ) ? mb_substr( $anomaly['path'], 0, 128, 'UTF-8' ) : substr( $anomaly['path'], 0, 128 ) ) : '';
				$anomaly_window = isset( $anomaly['window'] ) && is_string( $anomaly['window'] ) && '' !== $anomaly['window'] ? ( function_exists( 'mb_substr' ) ? mb_substr( $anomaly['window'], 0, 128, 'UTF-8' ) : substr( $anomaly['window'], 0, 128 ) ) : '';
				// Enriched anomaly context (issue #1384): every rendered
				// notice carries route plus p75 plus baseline plus delta
				// plus samples alongside the legacy change keys. v2
				// (issue #1313): breaches near a noted deploy carry the
				// `deploy_note` annotation.
				$anomaly_context = array(
					'route'    => isset( $anomaly['route'] ) ? (string) $anomaly['route'] : ( isset( $anomaly['key'] ) ? (string) $anomaly['key'] : '' ),
					'metric'   => isset( $anomaly['metric'] ) ? (string) $anomaly['metric'] : 'lcp',
					'p75'      => isset( $anomaly['p75'] ) ? (float) $anomaly['p75'] : 0.0,
					'baseline' => isset( $anomaly['baseline'] ) ? (float) $anomaly['baseline'] : 0.0,
					'delta'    => isset( $anomaly['delta'] ) ? (float) $anomaly['delta'] : 0.0,
					'samples'  => isset( $anomaly['samples'] ) ? (int) $anomaly['samples'] : 0,
				);
				if ( isset( $anomaly['deploy_note'] ) && is_string( $anomaly['deploy_note'] ) && '' !== $anomaly['deploy_note'] ) {
					$anomaly_context['deploy_note'] = function_exists( 'mb_substr' ) ? mb_substr( $anomaly['deploy_note'], 0, 200, 'UTF-8' ) : substr( $anomaly['deploy_note'], 0, 200 );
				}
				if ( 'cls' === $anomaly_metric ) {
					$change_abs = isset( $anomaly['change_abs'] ) ? (float) $anomaly['change_abs'] : 0.0;
					if ( '' !== $anomaly_path && '' !== $anomaly_window ) {
						/* translators: 1: CLS absolute increase vs baseline, 2: affected path, 3: compared window. */
						$cls_value = sprintf( __( 'CLS +%1$s on %2$s (%3$s)', 'performance-optimisation' ), number_format( $change_abs, 2 ), $anomaly_path, $anomaly_window );
						/* translators: %s is the affected path. */
						$cls_description = sprintf( __( 'AI: CLS regression detected on %s', 'performance-optimisation' ), $anomaly_path );
					} else {
						/* translators: %s is the CLS absolute increase vs baseline. */
						$cls_value       = sprintf( __( 'CLS +%s vs baseline', 'performance-optimisation' ), number_format( $change_abs, 2 ) );
						$cls_description = __( 'AI: CLS regression detected', 'performance-optimisation' );
					}
					if ( isset( $anomaly_context['deploy_note'] ) ) {
						/* translators: %s is the deploy note. */
						$cls_description .= sprintf( __( ' (near deploy: %s)', 'performance-optimisation' ), $anomaly_context['deploy_note'] );
					}
					$suggestions[] = array(
						'metric'      => 'ai_cls_regression',
						'value'       => $cls_value,
						'unit'        => 'string',
						'status'      => 'needs_improvement',
						'description' => $cls_description,
						'fix_action'  => 'open_image_optimization_tab',
						'ai_payload'  => array(
							'tab'      => 'image_optimisation',
							'settings' => array(),
							'anomaly'  => $anomaly_context,
						),
					);
				} elseif ( 'inp' === ( $anomaly['metric'] ?? '' ) ) {
					$change_pct = isset( $anomaly['change_pct'] ) ? (float) $anomaly['change_pct'] : 0.0;
					if ( '' !== $anomaly_path && '' !== $anomaly_window ) {
						/* translators: 1: INP percentage increase vs baseline, 2: affected path, 3: compared window. */
						$value = sprintf( __( 'INP +%1$d%% on %2$s (%3$s)', 'performance-optimisation' ), (int) round( $change_pct ), $anomaly_path, $anomaly_window );
						/* translators: %s is the affected path. */
						$description = sprintf( __( 'AI: INP regression detected on %s', 'performance-optimisation' ), $anomaly_path );
					} else {
						/* translators: %d is the INP percentage increase vs baseline. */
						$value       = sprintf( __( 'INP +%d%% vs baseline', 'performance-optimisation' ), (int) round( $change_pct ) );
						$description = __( 'AI: INP regression detected', 'performance-optimisation' );
					}
					$suggestions[] = array(
						'metric'      => 'ai_inp_regression',
						'value'       => $value,
						'unit'        => 'string',
						'status'      => 'needs_improvement',
						'description' => $description,
						'fix_action'  => 'open_file_optimization_tab',
						'ai_payload'  => array(
							'tab'      => 'file_optimisation',
							'settings' => array(),
							'anomaly'  => $anomaly_context,
						),
					);
				} elseif ( 'lcp' === $anomaly_metric ) {
					$change_pct = isset( $anomaly['change_pct'] ) ? (float) $anomaly['change_pct'] : 0.0;
					if ( '' !== $anomaly_path && '' !== $anomaly_window ) {
						/* translators: 1: LCP percentage increase vs baseline, 2: affected path, 3: compared window. */
						$value = sprintf( __( 'LCP +%1$d%% on %2$s (%3$s)', 'performance-optimisation' ), (int) round( $change_pct ), $anomaly_path, $anomaly_window );
						/* translators: %s is the affected path. */
						$description = sprintf( __( 'AI: LCP regression detected on %s', 'performance-optimisation' ), $anomaly_path );
					} else {
						/* translators: %d is the LCP percentage increase vs baseline. */
						$value       = sprintf( __( 'LCP +%d%% vs baseline', 'performance-optimisation' ), (int) round( $change_pct ) );
						$description = __( 'AI: LCP regression detected', 'performance-optimisation' );
					}
					// RUM-triggered CSS refresh loop (issue #1407): bridge the
					// firing LCP anomaly to a guarded single used-CSS regen
					// job. Lazy: runs only when a regression fired, so the
					// happy path performs no extra queries. Fail-open: any
					// throwable keeps the plain suggestion-only card.
					$css_refresh = array(
						'queued'      => false,
						'reason'      => 'opt-out',
						'url'         => '',
						'post_id'     => 0,
						'before_lcp'  => isset( $anomaly['baseline'] ) ? (float) $anomaly['baseline'] : 0.0,
						'current_lcp' => isset( $anomaly['current'] ) ? (float) $anomaly['current'] : 0.0,
					);
					try {
						$css_refresh = self::maybe_queue_css_refresh( $anomaly );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
					if ( ! empty( $css_refresh['queued'] ) ) {
						$description = __( 'AI: LCP regression detected — used-CSS refresh queued', 'performance-optimisation' );
					} elseif ( isset( $anomaly_context['deploy_note'] ) ) {
						/* translators: %s is the deploy note. */
						$description .= sprintf( __( ' (near deploy: %s)', 'performance-optimisation' ), $anomaly_context['deploy_note'] );
					}
					$suggestions[] = array(
						'metric'      => 'ai_lcp_regression',
						'value'       => $value,
						'unit'        => 'string',
						'status'      => 'needs_improvement',
						'description' => $description,
						'fix_action'  => 'open_file_optimization_tab',
						'ai_payload'  => array(
							'tab'         => 'file_optimisation',
							'settings'    => array(),
							'anomaly'     => $anomaly_context,
							'css_refresh' => $css_refresh,
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
		 * @since 2.0.0
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
				 * @since 2.0.0
				 * @param bool $enabled Whether RUM gating is enabled.
				 */
				return (bool) apply_filters( 'wppo_ai_speculation_rum_gating', $enabled );
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Whether RUM-segmented speculation auto-tune is enabled (issue #1425).
		 *
		 * Opt-in sub-gate under `ai_adaptive.enabled`: the additive
		 * `ai_adaptive.speculation_autotune_enabled` setting (default false,
		 * backfilled by Main::maybe_migrate_ai_speculation_autotune()) must be
		 * explicitly on. Filterable via `wppo_ai_speculation_autotune_enabled`.
		 * When off, filter_speculation_rules() and heuristic_learn() keep
		 * their legacy behaviour verbatim. Fail-safe: any failure returns
		 * false (legacy path).
		 *
		 * @return bool True when the RUM-segmented per-URL auto-tune applies.
		 * @since 2.3.0
		 */
		public static function is_speculation_autotune_enabled(): bool {
			try {
				$settings = class_exists( 'PerformanceOptimise\Inc\Util' ) ? Util::get_settings() : array();
				$enabled  = ! empty( $settings['ai_adaptive']['speculation_autotune_enabled'] );
				if ( function_exists( 'apply_filters' ) ) {
					/**
					 * Filters whether RUM-segmented speculation auto-tune applies.
					 *
					 * @since 2.3.0
					 * @param bool $enabled Whether the speculation auto-tune is on.
					 */
					return (bool) apply_filters( 'wppo_ai_speculation_autotune_enabled', $enabled );
				}
				return (bool) $enabled;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Minimum RUM samples for the speculation auto-tune (issue #1425).
		 *
		 * Prefers the additive `ai_adaptive.speculation_min_samples` setting
		 * (clamped 1-1000, default 20, mirroring `field_lcp_min_samples`);
		 * falls back to field_lcp_min_samples() so legacy installs without
		 * the key keep the shared gate. Fail-open to 20.
		 *
		 * @return int Minimum samples (>=1).
		 * @since 2.3.0
		 */
		public static function speculation_min_samples(): int {
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) ) {
					$settings = Util::get_settings();
					if ( isset( $settings['ai_adaptive']['speculation_min_samples'] ) && is_numeric( $settings['ai_adaptive']['speculation_min_samples'] ) ) {
						$min = (int) $settings['ai_adaptive']['speculation_min_samples'];
						return min( 1000, max( 1, $min ) );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			try {
				$fallback = self::field_lcp_min_samples();
				if ( $fallback >= 1 ) {
					return $fallback;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return 20;
		}

		/**
		 * Maximum per-URL list-rule URLs for the speculation auto-tune (issue #1425).
		 *
		 * Reads the additive `ai_adaptive.speculation_max_urls` setting,
		 * hard-clamped to 1-5 so the speculation JSON delta stays under ~1KB
		 * (5 URLs x ~150 bytes). Fail-open to 5.
		 *
		 * @return int Maximum URLs (1-5).
		 * @since 2.3.0
		 */
		public static function speculation_max_urls(): int {
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) ) {
					$settings = Util::get_settings();
					if ( isset( $settings['ai_adaptive']['speculation_max_urls'] ) && is_numeric( $settings['ai_adaptive']['speculation_max_urls'] ) ) {
						$max = (int) $settings['ai_adaptive']['speculation_max_urls'];
						if ( $max >= 1 && $max <= 5 ) {
							return $max;
						}
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return 5;
		}

		/**
		 * Trim a candidate list-rule URL set so its JSON delta stays under budget.
		 *
		 * The per-URL cap (speculation_max_urls, 1-5) bounds the common case,
		 * but very long pretty-permalink URLs could still push the delta past
		 * 1KB; drop trailing (lowest-ranked) URLs until the encoded rule fits
		 * or a single URL remains. Size is measured with a representative
		 * list-rule wrapper (source + eagerness) so the ~50-80 byte wrapper
		 * overhead counts toward the budget; only the URL list is returned.
		 * A single URL is always returned unchanged even when it alone exceeds
		 * the budget, so the ~1KB guarantee is soft for pathological single
		 * URLs by design. Fail-open: unencodable input returns the input
		 * unchanged (callers still slice to the max).
		 *
		 * @param string[] $urls Candidate absolute URLs (rank-ordered).
		 * @param int      $budget_bytes Maximum encoded size in bytes.
		 * @return string[]
		 * @since 2.3.0
		 */
		public static function trim_speculation_urls_to_budget( array $urls, int $budget_bytes = 1024 ): array {
			try {
				$urls = array_values( array_filter( $urls, 'is_string' ) );
				if ( count( $urls ) <= 1 || $budget_bytes < 1 ) {
					return $urls;
				}
				$encode = null;
				if ( function_exists( 'wp_json_encode' ) ) {
					$encode = 'wp_json_encode';
				} elseif ( function_exists( 'json_encode' ) ) {
					$encode = 'json_encode';
				}
				if ( null === $encode ) {
					return $urls;
				}
				$remaining = count( $urls );
				while ( $remaining > 1 ) {
					$probe   = array(
						'source'    => 'list',
						'urls'      => $urls,
						'eagerness' => 'moderate',
					);
					$encoded = call_user_func( $encode, $probe );
					if ( ! is_string( $encoded ) || strlen( $encoded ) <= $budget_bytes ) {
						break;
					}
					array_pop( $urls );
					$remaining = count( $urls );
				}
				return $urls;
			} catch ( \Throwable $e ) {
				unset( $e );
				return array_values( array_filter( $urls, 'is_string' ) );
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
		 * Two-gate interaction (issue #1425): the RUM-segmented auto-tune
		 * path enforces its own `speculation_min_samples()` gate on the
		 * returned `samples` count, which may be stricter (or looser) than
		 * the shared `field_lcp_min_samples` gate used here for row
		 * qualification. Pass that threshold via $min_samples_override so
		 * both gates agree; when null, the shared gate applies. A segment
		 * can therefore be qualified here yet rejected by the caller when
		 * the caller uses a higher threshold — that is intentional.
		 *
		 * The optional $gating_enabled parameter lets the frontend hot path
		 * (filter_speculation_rules()) resolve the
		 * `wppo_ai_speculation_rum_gating` filter once per request and pass
		 * the result down, instead of applying the filter once for the
		 * fallback, once for the guard, and once more in the caller.
		 *
		 * @since 2.0.0
		 * @param bool|null $gating_enabled Pre-resolved gating flag. Null resolves via is_speculation_rum_gating_enabled().
		 * @param int|null  $min_samples_override Optional minimum-samples override (e.g. speculation_min_samples()). Null uses the shared field_lcp_min_samples() gate.
		 * @return array{qualified:bool,eagerness:string,lcp_p75:float,inp_p75:float,samples:int,min_samples:int,gated:bool} Gated state.
		 */
		public static function get_rum_gated_speculation_state( ?bool $gating_enabled = null, ?int $min_samples_override = null ): array {
			$min = null !== $min_samples_override && $min_samples_override >= 1 ? (int) $min_samples_override : self::field_lcp_min_samples();
			try {
				$gating_enabled = null === $gating_enabled ? self::is_speculation_rum_gating_enabled() : $gating_enabled;
			} catch ( \Throwable $e ) {
				unset( $e );
				$gating_enabled = true;
			}
			$fallback = array(
				'qualified'   => false,
				'eagerness'   => 'conservative',
				'lcp_p75'     => 0.0,
				'inp_p75'     => 0.0,
				'samples'     => 0,
				'min_samples' => $min,
				'gated'       => $gating_enabled,
			);
			try {
				if ( ! $gating_enabled ) {
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
				 * @since 2.0.0
				 * @param float $threshold LCP p75 threshold in milliseconds.
				 */
				$lcp_threshold = self::validate_speculation_threshold( apply_filters( 'wppo_ai_speculation_lcp_threshold', 2500.0 ), 2500.0 );
				/**
				 * Filters the INP p75 (ms) threshold for RUM-gated speculation eagerness.
				 *
				 * @since 2.0.0
				 * @param float $threshold INP p75 threshold in milliseconds.
				 */
				$inp_threshold = self::validate_speculation_threshold( apply_filters( 'wppo_ai_speculation_inp_threshold', 200.0 ), 200.0 );

				$lcp_good = empty( $qualified_lcp ) || $max_lcp <= $lcp_threshold;
				$inp_good = empty( $qualified_inp ) || $max_inp <= $inp_threshold;
				if ( ! $lcp_good || ! $inp_good ) {
					return $fallback;
				}

				/**
				 * Filters the eagerness for a RUM-qualified (good p75) speculation list rule.
				 *
				 * @since 2.0.0
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
		 * Validate a RUM-gated speculation p75 threshold from a filter value.
		 *
		 * Filter callbacks may return numeric strings, negatives, INF, or
		 * NAN; an unvalidated cast would silently force always-conservative
		 * (negative) or always-qualified (INF) behavior. Non-finite or
		 * negative values fail open to the default.
		 *
		 * @param mixed $value Raw filter value.
		 * @param float $fallback Fallback threshold.
		 * @return float Validated threshold (>= 0 and finite).
		 * @since 2.0.0
		 */
		private static function validate_speculation_threshold( $value, float $fallback ): float {
			try {
				$threshold = is_numeric( $value ) ? (float) $value : $fallback;
				if ( ! is_finite( $threshold ) || $threshold < 0 ) {
					return $fallback;
				}
				return $threshold;
			} catch ( \Throwable $e ) {
				unset( $e );
				return $fallback;
			}
		}

		/**
		 * Whether an absolute URL falls under a commerce exclude prefix.
		 *
		 * Shared by the RUM-derived top-URL ranking and the
		 * `wppo_ai_speculation_top_urls` filter-output sanitization so a
		 * filter cannot reintroduce /cart/, /checkout/, /my-account/ (or
		 * store-specific Woo prefixes) into an explicit list rule, which
		 * bypasses href exclude-path filtering. Fail-open: unparseable URLs
		 * are not treated as commerce URLs (the same-site guard still applies).
		 *
		 * @param string   $url Absolute URL.
		 * @param string[] $excludes Commerce exclude prefixes (e.g. `/cart/*`).
		 * @return bool True when the URL path matches a commerce prefix.
		 * @since 2.0.0
		 */
		private static function is_speculation_commerce_url( string $url, array $excludes ): bool {
			try {
				if ( ! function_exists( 'wp_parse_url' ) ) {
					return false;
				}
				$path_part = wp_parse_url( $url, PHP_URL_PATH );
				$path_part = is_string( $path_part ) && '' !== $path_part ? rtrim( strtolower( $path_part ), '/' ) : '';
				foreach ( array_merge( $excludes, array( '/account/*' ) ) as $exclude ) {
					$prefix = rtrim( rtrim( (string) $exclude, '*' ), '/' );
					$prefix = strtolower( $prefix );
					if ( '' !== $prefix && ( $path_part === $prefix || 0 === strpos( $path_part . '/', $prefix . '/' ) ) ) {
						return true;
					}
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Peak per-bucket sample count across all RUM metrics (issue #1425).
		 *
		 * Shared counter between the heuristic_learn() undersample floor and
		 * get_rum_top_speculation_urls() volume ranking so both gates rank
		 * the same sample counts. Skips the `lcpUrls` URL list (not a sample
		 * aggregate) and non-array buckets. Fail-open: unparseable input
		 * yields 0.
		 *
		 * @param array $metrics Single path-bucket metric map.
		 * @return int Peak `n` (>= 0).
		 * @since 2.3.0
		 */
		public static function peak_rum_metric_samples( array $metrics ): int {
			try {
				$peak = 0;
				foreach ( $metrics as $metric => $aggregate ) {
					if ( 'lcpUrls' === $metric || ! is_array( $aggregate ) ) {
						continue;
					}
					if ( isset( $aggregate['n'] ) ) {
						$peak = max( $peak, (int) $aggregate['n'] );
					}
				}
				return $peak;
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		/**
		 * Top RUM (real-visit) URLs for the RUM-gated speculation list rule.
		 *
		 * Volume-ranked from the read-only RUM aggregate (no queue flush, no
		 * transient writes — shares the per-request memo with the segmented
		 * field-LCP/INP readers so the speculation-rules filter performs a
		 * single aggregate deserialization), resolved to absolute
		 * pretty-permalink URLs (trailing slash), deduped, same-site
		 * validated, commerce/query-string/admin rejected, and capped at
		 * $limit (keeps the ~1KB footprint). Invalid URLs are skipped
		 * individually (fail-open); empty means "no RUM winners".
		 *
		 * @param int $limit Maximum URLs to return.
		 * @return string[]
		 * @since 2.0.0
		 */
		public static function get_rum_top_speculation_urls( int $limit = 5 ): array {
			try {
				if ( $limit < 1 ) {
					return array();
				}
				if ( ! class_exists( 'PerformanceOptimise\Inc\RUM' ) ) {
					return array();
				}
				if ( method_exists( 'PerformanceOptimise\Inc\RUM', 'get_aggregate_readonly' ) ) {
					$rum = RUM::get_aggregate_readonly();
				} else {
					if ( ! method_exists( 'PerformanceOptimise\Inc\RUM', 'get_data' ) || ! function_exists( 'get_option' ) ) {
						return array();
					}
					// Back-compat fallback for runtimes without the read-only
					// accessor: plain get_option() still avoids the
					// queue-flushing get_data() write path.
					$rum = get_option( RUM::OPTION, array() );
				}
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
						$count = self::peak_rum_metric_samples( $metrics );
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
					if ( self::is_speculation_commerce_url( $clean, $excludes ) ) {
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
				 * Filter-supplied URLs are untrusted input: they are re-sanitized
				 * (esc_url_raw), re-checked against the commerce-prefix guard and
				 * the same-site guard, deduped, and re-capped at $limit, so a
				 * filter cannot reintroduce /checkout/, wp-admin, cross-site, or
				 * unbounded URL lists into the ~1KB list rule.
				 *
				 * @since 2.0.0
				 * @param string[] $urls Ranked absolute URLs.
				 */
				$urls = apply_filters( 'wppo_ai_speculation_top_urls', $urls );
				if ( ! is_array( $urls ) ) {
					return array();
				}
				$filtered = array();
				foreach ( $urls as $candidate ) {
					if ( ! is_string( $candidate ) || '' === $candidate ) {
						continue;
					}
					$clean = function_exists( 'esc_url_raw' ) ? esc_url_raw( $candidate ) : $candidate;
					if ( ! is_string( $clean ) || '' === $clean ) {
						continue;
					}
					if ( in_array( $clean, $filtered, true ) ) {
						continue;
					}
					if ( self::is_speculation_commerce_url( $clean, $excludes ) ) {
						continue;
					}
					$filtered[] = $clean;
					if ( count( $filtered ) >= $limit ) {
						break;
					}
				}
				$filtered = self::filter_same_site_urls( $filtered );
				return array_values( array_slice( $filtered, 0, $limit ) );
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
		 * @since 2.0.0
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
		 * Build the RUM-segmented auto-tuned list rule (issue #1425).
		 *
		 * Strict opt-in path used only when `is_speculation_autotune_enabled()`
		 * and RUM gating are both on (checked by the caller). Returns the
		 * rules with one appended bounded per-URL list rule for qualified
		 * segments, or the input rules unchanged when the segment is
		 * undersampled (samples below `speculation_min_samples()`) or
		 * unqualified (poor p75) — i.e. zero AI list rules, core conservative
		 * document prefetch stands. Returns null only on unexpected failure
		 * so the caller can fail open to the input rules.
		 *
		 * Guarantees: same-origin absolute URLs only (same-site guard),
		 * cart/checkout/account URLs never listed (commerce-prefix guard,
		 * applied unconditionally — not just in commerce contexts),
		 * eagerness coerced to conservative/moderate and additionally capped
		 * at moderate (a rogue `eager` from the `wppo_ai_speculation_eagerness`
		 * filter can never reach the markup on this path), URL count capped
		 * at `speculation_max_urls()` (1-5) with the encoded delta trimmed to
		 * ~1KB, deduped against existing list-source rules (Main's high-value
		 * list runs at priority 10, AI at 20), and manual `preload_settings`
		 * never written. Multisite-safe: per-site RUM aggregate, no transient
		 * writes, no cross-site URLs.
		 *
		 * @param array    $rules Incoming speculation rules (core default).
		 * @param int|null $min_override Optional pre-resolved minimum samples (avoids an extra settings read on the hot path).
		 * @param int|null $max_override Optional pre-resolved maximum URLs (avoids an extra settings read on the hot path).
		 * @return array|null Rules with the auto-tuned list rule appended, the input unchanged, or null on failure.
		 * @since 2.3.0
		 */
		private static function get_autotuned_speculation_rule( $rules, ?int $min_override = null, ?int $max_override = null ) {
			try {
				if ( ! is_array( $rules ) ) {
					return null;
				}
				$min   = null !== $min_override && $min_override >= 1 ? (int) $min_override : self::speculation_min_samples();
				$max   = null !== $max_override && $max_override >= 1 && $max_override <= 5 ? (int) $max_override : self::speculation_max_urls();
				$state = self::get_rum_gated_speculation_state( true, $min );
				if ( ! is_array( $state ) || empty( $state['qualified'] ) ) {
					return $rules;
				}
				if ( ! isset( $state['samples'] ) || (int) $state['samples'] < $min ) {
					return $rules;
				}
				$model      = self::get_model();
				$model_urls = self::get_prefetch_urls_from_model( is_array( $model ) ? $model : array() );
				$rum_urls   = self::get_rum_top_speculation_urls( $max );
				$urls       = array();
				foreach ( array_merge( $model_urls, $rum_urls ) as $candidate ) {
					if ( ! is_string( $candidate ) || '' === $candidate ) {
						continue;
					}
					if ( in_array( $candidate, $urls, true ) ) {
						continue;
					}
					$urls[] = $candidate;
					if ( count( $urls ) >= $max ) {
						break;
					}
				}
				if ( empty( $urls ) ) {
					return $rules;
				}
				// Unconditional commerce-prefix guard: explicit list rules
				// bypass href exclude-path filtering, so a RUM/LLM-nominated
				// /cart/, /checkout/, /my-account/ (or /account/) URL must
				// never be listed on this path, in any context.
				try {
					$excludes = self::get_commerce_exclude_paths();
					$urls     = array_values(
						array_filter(
							$urls,
							static function ( $url ) use ( $excludes ) {
								return ! self::is_speculation_commerce_url( $url, $excludes );
							}
						)
					);
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				if ( empty( $urls ) ) {
					return $rules;
				}
				$urls = self::filter_same_site_urls( $urls );
				$urls = self::dedupe_against_existing_lists( $urls, $rules );
				if ( empty( $urls ) ) {
					return $rules;
				}
				$urls = array_values( array_slice( $urls, 0, $max ) );
				$urls = self::trim_speculation_urls_to_budget( $urls, 1024 );
				if ( empty( $urls ) ) {
					return $rules;
				}
				// Moderate cap: normalize (allowlist rogue values to
				// conservative, commerce-cap) then downgrade any remaining
				// `eager` — on this path real-visit data picks exactly which
				// URLs to prefetch, and prefetch stays capped safe.
				$eagerness = self::normalize_eagerness( isset( $state['eagerness'] ) ? $state['eagerness'] : 'moderate' );
				if ( 'eager' === $eagerness ) {
					$eagerness = 'moderate';
				}
				$rules[] = array(
					'source'    => 'list',
					'urls'      => $urls,
					'eagerness' => $eagerness,
				);
				if ( function_exists( 'apply_filters' ) ) {
					/**
					 * Filters AI-injected speculation rules.
					 *
					 * @since 2.3.0
					 * @param array $rules Updated rules.
					 * @param array $urls AI prefetch URLs.
					 */
					$filtered = apply_filters( 'wppo_ai_adaptive_speculation_rules', $rules, $urls );
					return self::sanitize_speculation_rules_after_filter( $filtered, $rules, $max );
				}
				return $rules;
			} catch ( \Throwable $e ) {
				unset( $e );
				return null;
			}
		}

		/**
		 * Re-apply the auto-tune guard pipeline to post-filter speculation rules.
		 *
		 * The `wppo_ai_adaptive_speculation_rules` filter runs after the
		 * same-site, commerce, count, budget, and eagerness guards, so a
		 * third-party callback could re-inject cross-origin, commerce, or
		 * eager URLs. Every `list`-source rule in the filtered result is
		 * re-sanitized (esc_url_raw, commerce-prefix guard, same-site
		 * guard, dedupe, count cap, budget trim, moderate eagerness cap);
		 * non-list rules pass through untouched. Non-array filter output
		 * fails open to the pre-filter rules. Empty list rules are dropped
		 * (an empty list rule prefetches nothing).
		 *
		 * The legacy (non-autotune) path shares this sanitizer with
		 * `$strict` set to false so its long-standing contextual contract
		 * is preserved: commerce URLs and `eager` are only stripped/capped
		 * in a commerce/auth context (matching get_prefetch_urls_from_model()
		 * and maybe_cap_eagerness()), while same-site, count, budget, and
		 * eagerness-allowlist guards always apply.
		 *
		 * @param mixed $filtered Filtered rules (untrusted).
		 * @param array $fallback Pre-filter rules (guarded).
		 * @param int   $max Maximum URLs per list rule (1-5).
		 * @param bool  $strict When false, commerce/eager guards are contextual.
		 * @return array Sanitized rules.
		 * @since 2.3.0
		 */
		private static function sanitize_speculation_rules_after_filter( $filtered, array $fallback, int $max, bool $strict = true ): array {
			try {
				if ( ! is_array( $filtered ) ) {
					return $fallback;
				}
				$max = $max >= 1 && $max <= 5 ? (int) $max : 5;
				try {
					$excludes = self::get_commerce_exclude_paths();
				} catch ( \Throwable $e ) {
					unset( $e );
					$excludes = array( '/cart/*', '/checkout/*', '/my-account/*' );
				}
				$strict_commerce = $strict;
				if ( ! $strict ) {
					try {
						$strict_commerce = self::is_commerce_or_auth_context();
					} catch ( \Throwable $e ) {
						unset( $e );
						$strict_commerce = false;
					}
				}
				$sanitized = array();
				foreach ( $filtered as $rule ) {
					if ( ! is_array( $rule ) || ( $rule['source'] ?? '' ) !== 'list' ) {
						$sanitized[] = $rule;
						continue;
					}
					$rule_urls = isset( $rule['urls'] ) && is_array( $rule['urls'] ) ? $rule['urls'] : array();
					$cleaned   = array();
					foreach ( $rule_urls as $candidate ) {
						if ( ! is_string( $candidate ) || '' === $candidate ) {
							continue;
						}
						$clean = function_exists( 'esc_url_raw' ) ? esc_url_raw( $candidate ) : $candidate;
						if ( ! is_string( $clean ) || '' === $clean ) {
							continue;
						}
						if ( in_array( $clean, $cleaned, true ) ) {
							continue;
						}
						if ( $strict_commerce && self::is_speculation_commerce_url( $clean, $excludes ) ) {
							continue;
						}
						$cleaned[] = $clean;
					}
					$cleaned = self::filter_same_site_urls( $cleaned );
					$cleaned = array_values( array_slice( $cleaned, 0, $max ) );
					$cleaned = self::trim_speculation_urls_to_budget( $cleaned, 1024 );
					if ( empty( $cleaned ) ) {
						continue;
					}
					$rule_eagerness = self::normalize_eagerness( $rule['eagerness'] ?? 'conservative' );
					if ( $strict && 'eager' === $rule_eagerness ) {
						$rule_eagerness = 'moderate';
					}
					$rule['urls']      = $cleaned;
					$rule['eagerness'] = $rule_eagerness;
					$sanitized[]       = $rule;
				}
				return $sanitized;
			} catch ( \Throwable $e ) {
				unset( $e );
				return $fallback;
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
		 * @since 2.0.0
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
			// RUM-segmented auto-tune (issue #1425, opt-in via
			// ai_adaptive.speculation_autotune_enabled): qualified RUM
			// segments emit bounded per-URL `{"source":"list"}` rules picked
			// from real-visit data (never blanket document prefetch);
			// undersampled or unqualified segments emit zero list rules so
			// the core conservative document prefetch stands. Commerce/auth
			// URLs are never listed, eagerness never exceeds moderate, the
			// JSON delta is trimmed to ~1KB, and manual
			// `preload_settings` are only read, never written. Fail-open:
			// any failure emits nothing (core default rules stand).
			// Hot-path note: Util::get_settings() is per-request memoized,
			// but the autotune branch still resolves autotune/gating/min/max
			// from a single settings snapshot and passes min/max down, so
			// the front-end filter performs one settings read (not three)
			// plus the memoized model and RUM aggregate reads.
			try {
				$hot_settings = class_exists( 'PerformanceOptimise\Inc\Util' ) ? Util::get_settings() : array();
				if ( ! is_array( $hot_settings ) ) {
					$hot_settings = array();
				}
				$hot_autotune = ! empty( $hot_settings['ai_adaptive']['speculation_autotune_enabled'] );
				if ( function_exists( 'apply_filters' ) ) {
					$hot_autotune = (bool) apply_filters( 'wppo_ai_speculation_autotune_enabled', $hot_autotune );
				}
				if ( $hot_autotune ) {
					if ( isset( $hot_settings['preload_settings']['speculationRumGating'] ) ) {
						$hot_gating = (bool) $hot_settings['preload_settings']['speculationRumGating'];
					} elseif ( isset( $hot_settings['ai_adaptive']['speculation_rum_gating'] ) ) {
						$hot_gating = (bool) $hot_settings['ai_adaptive']['speculation_rum_gating'];
					} else {
						$hot_gating = true;
					}
					if ( function_exists( 'apply_filters' ) ) {
						$hot_gating = (bool) apply_filters( 'wppo_ai_speculation_rum_gating', $hot_gating );
					}
					if ( $hot_gating ) {
						$hot_min = null;
						if ( isset( $hot_settings['ai_adaptive']['speculation_min_samples'] ) && is_numeric( $hot_settings['ai_adaptive']['speculation_min_samples'] ) ) {
							$candidate_min = (int) $hot_settings['ai_adaptive']['speculation_min_samples'];
							if ( $candidate_min >= 1 && $candidate_min <= 1000 ) {
								$hot_min = $candidate_min;
							}
						}
						if ( null === $hot_min ) {
							try {
								$hot_fallback = self::field_lcp_min_samples();
								$hot_min      = $hot_fallback >= 1 ? (int) $hot_fallback : 20;
							} catch ( \Throwable $e ) {
								unset( $e );
								$hot_min = 20;
							}
						}
						$hot_max = 5;
						if ( isset( $hot_settings['ai_adaptive']['speculation_max_urls'] ) && is_numeric( $hot_settings['ai_adaptive']['speculation_max_urls'] ) ) {
							$candidate_max = (int) $hot_settings['ai_adaptive']['speculation_max_urls'];
							if ( $candidate_max >= 1 && $candidate_max <= 5 ) {
								$hot_max = $candidate_max;
							}
						}
						$autotune_rules = self::get_autotuned_speculation_rule( $rules, $hot_min, $hot_max );
						if ( null !== $autotune_rules ) {
							return $autotune_rules;
						}
						return $rules;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
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
			// The gating flag is resolved once and passed down so the
			// `wppo_ai_speculation_rum_gating` filter fires once per request
			// and the RUM aggregate is deserialized once (shared per-request
			// memo with the segmented readers and the top-URL ranking).
			$gating_enabled = self::is_speculation_rum_gating_enabled();
			if ( $gating_enabled ) {
				$state = self::get_rum_gated_speculation_state( $gating_enabled );
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
			 * @since 2.0.0
			 * @param array $rules Updated rules.
			 * @param array $urls AI prefetch URLs.
			 */
			if ( function_exists( 'apply_filters' ) ) {
				$filtered = apply_filters( 'wppo_ai_adaptive_speculation_rules', $rules, $urls );
				return self::sanitize_speculation_rules_after_filter( $filtered, $rules, 5, false );
			}
			return $rules;
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
		 * @since 2.0.0
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
			return $kept;
		}

		/**
		 * Remove URLs already covered by an existing list-source rule.
		 *
		 * @param string[] $urls  Candidate AI URLs.
		 * @param array    $rules Existing speculation rules.
		 * @return string[]
		 * @since 2.0.0
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
		 * @since 2.0.0
		 */
		public static function init(): void {
			if ( function_exists( 'wp_get_speculation_rules' ) ) {
				add_filter( 'wp_speculation_rules', array( self::class, 'filter_speculation_rules' ), 20 );
			}
		}
	}
}
