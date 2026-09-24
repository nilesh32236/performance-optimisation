<?php
/**
 * REST settings service — settings save/import/snapshot/restore + sandbox staging handlers.
 *
 * ARCH-011: the site-administration slice (`update_settings()`,
 * `import_settings()`, `get_settings_snapshot()`, `restore_settings()`, and
 * the four sandbox handlers `get_sandbox_preview()`,
 * `save_sandbox_preview()`, `promote_sandbox_preview()`,
 * `discard_sandbox_preview()`) previously lived on the `Rest` 40-route
 * registrar. The cluster shares one responsibility (settings persistence +
 * staged-experiment lifecycle over the single `wppo_settings` option), one
 * trigger set (SPA settings saves, import/export, one-click undo, sandbox
 * stage/promote), and one correctness contract (throttle gates, snapshot
 * before overwrite, password/API-key redaction) — so it lives here.
 *
 * `Rest` keeps thin same-signature proxies delegating to an eagerly
 * constructed instance of this class, so every route callback, every direct
 * caller (`RestTest`, Hook_Registry wiring via `Rest::register_routes()`),
 * and every `get_routes()` reflection stays byte-identical with zero caller
 * migration.
 *
 * State strategy (Option A — ARCH-004/005/006/007 bridge precedent): ALL
 * shared infra stays on `Rest` as the single source of truth
 * (`permission_callback()`, `get_schema_for_route()`,
 * `is_endpoint_throttled()`, `send_response()`); this service holds the
 * owning `Rest` instance and reaches them through the `@internal`
 * `rest_*()` bridges — never a new write path. Handler bodies are verbatim
 * copies of the pre-extraction `Rest` methods (only `$this->` shared-infra
 * accesses re-pointed to `$this->owner->rest_*()`). The two private helpers
 * (`sanitize_settings_recursively()`,
 * `remove_sensitive_settings_from_response()`) are thin delegates to the
 * canonical logic on `Util` (same delegates `Rest` keeps for its remaining
 * handlers), so the sanitizer semantics and the redacted key list have a
 * single source of truth and cannot diverge.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Rest_Settings' ) ) {
	/**
	 * Class Rest_Settings
	 *
	 * Owns the REST settings-administration handlers. Constructed with the
	 * owning `Rest` registrar so throttle verdicts and response envelopes
	 * keep the exact semantics the bodies had on `Rest` (shared infra stays
	 * on `Rest`, accessed through the `@internal` `rest_*()` bridges —
	 * never a new write path).
	 *
	 * Always call via the `Rest` facade proxies, never directly on this
	 * service (route registration stays on `Rest` by design).
	 *
	 * @since 2.4.0
	 */
	final class Rest_Settings {

		/**
		 * Owning registrar (shared-infra owner: throttle, responses).
		 *
		 * @since 2.4.0
		 * @var Rest
		 */
		private Rest $owner;

		/**
		 * Constructor.
		 *
		 * @since 2.4.0
		 * @param Rest $owner Owning registrar (shared-infra owner).
		 */
		public function __construct( Rest $owner ) {
			$this->owner = $owner;
		}

		/**
		 * Updates the settings for the plugin.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @since 1.0.0
		 * @return \WP_REST_Response The response object.
		 */
		public function update_settings( \WP_REST_Request $request ) {
			if ( $this->owner->rest_throttle_hit( 'update_settings', 5, 60 ) ) {
				$response = $this->owner->rest_send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			$params   = $request->get_params();
			$tab      = isset( $params['tab'] ) ? sanitize_text_field( $params['tab'] ) : '';
			$settings = isset( $params['settings'] ) && is_array( $params['settings'] ) ? $params['settings'] : array();

			// Validate tab against known whitelist (single source: Util::ALLOWED_SETTINGS_TABS).
			$allowed_tabs = Util::ALLOWED_SETTINGS_TABS;
			if ( empty( $tab ) || ! in_array( $tab, $allowed_tabs, true ) ) {
				return $this->owner->rest_send_response( null, false, 400, __( 'Invalid settings tab.', 'performance-optimisation' ) );
			}

			// Sanitize settings array recursively.
			$sanitized_settings = $this->sanitize_settings_recursively( $settings );

			// Never store Redis password in the database. Store a boolean flag instead.
			// The password must be provided via the WPPO_REDIS_PASSWORD constant in wp-config.php.
			if ( 'object_cache' === $tab && isset( $sanitized_settings['password'] ) ) {
				$password_provided = ! empty( $sanitized_settings['password'] );
				unset( $sanitized_settings['password'] );
				if ( $password_provided ) {
					$sanitized_settings['password_set'] = true;
				}
			}

			// Server-only outage status flag (issue #1233): clients must not
			// pin spoofed degraded/healthy state via update_settings. Drop
			// any client value so the array_merge below preserves the stored
			// Removed (#925, pruned #1373): the legacy
			// file_optimisation.removeQueryStrings key is dropped on save so it
			// decays naturally. A legacy client that still posts the key is
			// accepted silently (fail-open, never fatal); stored legacy values
			// are ignored and `?ver` is always preserved.
			if ( 'file_optimisation' === $tab && isset( $sanitized_settings['removeQueryStrings'] ) ) {
				unset( $sanitized_settings['removeQueryStrings'] );
			}

			// server-written value, mirroring password handling.
			if ( 'object_cache' === $tab && isset( $sanitized_settings['outage_bypassed'] ) ) {
				unset( $sanitized_settings['outage_bypassed'] );
			}

			$options = Util::get_settings();

			// Preserve the pagespeed_api_key when the request omits it.
			if ( 'performance_audit' === $tab && ! isset( $params['settings']['pagespeed_api_key'] ) && isset( $options['performance_audit']['pagespeed_api_key'] ) ) {
				$sanitized_settings['pagespeed_api_key'] = sanitize_text_field( $options['performance_audit']['pagespeed_api_key'] );
			}

			// Preserve the server_timing_enabled flag when the request omits it (no UI toggle exists yet).
			if ( 'performance_audit' === $tab && ! isset( $params['settings']['server_timing_enabled'] ) && isset( $options['performance_audit']['server_timing_enabled'] ) ) {
				$sanitized_settings['server_timing_enabled'] = (bool) $options['performance_audit']['server_timing_enabled'];
			}

			// Preserve the auto_rescan frequency when the request omits it.
			if ( 'performance_audit' === $tab && ! isset( $params['settings']['auto_rescan'] ) && isset( $options['performance_audit']['auto_rescan'] ) ) {
				$stored_rescan                     = $options['performance_audit']['auto_rescan'];
				$stored_rescan                     = is_string( $stored_rescan ) ? sanitize_text_field( $stored_rescan ) : '';
				$sanitized_settings['auto_rescan'] = in_array( $stored_rescan, array( '', 'daily', 'weekly' ), true ) ? $stored_rescan : '';
			}

			// Preserve the RUM beacon sample rate when the request omits it
			// (issue #1214): a partial save must not wipe the rate. Clamped to
			// 1-100 like the sanitizer so a legacy extreme stored value
			// self-heals to unsampled instead of disabling beacons.
			if ( 'performance_audit' === $tab && ! isset( $params['settings']['rum_sample_rate'] ) && isset( $options['performance_audit']['rum_sample_rate'] ) ) {
				$rum_default                           = class_exists( 'PerformanceOptimise\Inc\RUM' ) ? \PerformanceOptimise\Inc\RUM::RUM_SAMPLE_RATE_DEFAULT : 100;
				$stored_rate                           = $options['performance_audit']['rum_sample_rate'];
				$stored_rate                           = is_numeric( $stored_rate ) ? (int) $stored_rate : $rum_default;
				$sanitized_settings['rum_sample_rate'] = ( $stored_rate >= 1 && $stored_rate <= 100 ) ? $stored_rate : $rum_default;
			}

			// Preserve dismissed AI suggestions when the request omits them
			// (issue #1036): AiPanel save posts only the toggles, while the
			// dismiss action posts the full list — a toggle save must not
			// wipe prior dismissals.
			if ( 'ai_adaptive' === $tab && ! isset( $params['settings']['dismissed_suggestions'] ) && isset( $options['ai_adaptive']['dismissed_suggestions'] ) ) {
				$dismissed = $options['ai_adaptive']['dismissed_suggestions'];
				if ( is_array( $dismissed ) ) {
					$sanitized_dismissed = array();
					foreach ( $dismissed as $metric ) {
						if ( ! is_string( $metric ) ) {
							continue;
						}
						$m = sanitize_text_field( $metric );
						if ( '' !== $m ) {
							$sanitized_dismissed[] = substr( $m, 0, 64 );
						}
					}
					$sanitized_settings['dismissed_suggestions'] = array_values( array_unique( $sanitized_dismissed ) );
				}
			}

			// Preserve the field-LCP minimum-sample threshold when the request
			// omits it (issue #1036): AiPanel save posts only the toggles, so
			// a toggle save must not wipe a custom threshold. Clamped to
			// 1-1000 like the sanitizer (issue #1200) so a legacy extreme
			// stored value self-heals instead of pinning auto-tune.
			if ( 'ai_adaptive' === $tab && ! isset( $params['settings']['field_lcp_min_samples'] ) && isset( $options['ai_adaptive']['field_lcp_min_samples'] ) ) {
				$sanitized_settings['field_lcp_min_samples'] = min( 1000, max( 1, absint( $options['ai_adaptive']['field_lcp_min_samples'] ) ) );
			}

			// Preserve the RUM-segmented speculation auto-tune keys when the
			// request omits them (issue #1425): same partial-save hazard as
			// the field-LCP threshold above — an older client/partial save
			// must not wipe the opt-in flag or the tuning thresholds.
			// Normalized like Util::sanitize_settings_recursively() so stored
			// extremes self-heal instead of persisting verbatim.
			if ( 'ai_adaptive' === $tab && ! isset( $params['settings']['speculation_autotune_enabled'] ) && isset( $options['ai_adaptive']['speculation_autotune_enabled'] ) ) {
				$stored = $options['ai_adaptive']['speculation_autotune_enabled'];
				if ( is_bool( $stored ) ) {
					$sanitized_settings['speculation_autotune_enabled'] = $stored;
				} else {
					$bool = filter_var( $stored, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
					$sanitized_settings['speculation_autotune_enabled'] = null === $bool ? false : $bool;
				}
			}
			if ( 'ai_adaptive' === $tab && ! isset( $params['settings']['speculation_min_samples'] ) && isset( $options['ai_adaptive']['speculation_min_samples'] ) ) {
				$sanitized_settings['speculation_min_samples'] = min( 1000, max( 1, absint( $options['ai_adaptive']['speculation_min_samples'] ) ) );
			}
			if ( 'ai_adaptive' === $tab && ! isset( $params['settings']['speculation_max_urls'] ) && isset( $options['ai_adaptive']['speculation_max_urls'] ) ) {
				$stored_limit                               = is_numeric( $options['ai_adaptive']['speculation_max_urls'] ) ? (int) $options['ai_adaptive']['speculation_max_urls'] : 5;
				$sanitized_settings['speculation_max_urls'] = min( 5, max( 1, $stored_limit ) );
			}

			// Preserve the RUM-priority ordering flags when the request omits
			// them (issue #1059): FileOptimization UI saves post the full tab,
			// but an older client/partial save must not wipe an opt-out set via
			// WP-CLI/DB. Mirrors the server_timing_enabled/auto_rescan preserves.
			if ( 'file_optimisation' === $tab && ! isset( $params['settings']['ccssRumPriority'] ) && isset( $options['file_optimisation']['ccssRumPriority'] ) ) {
				$sanitized_settings['ccssRumPriority'] = (bool) $options['file_optimisation']['ccssRumPriority'];
			}
			if ( 'file_optimisation' === $tab && ! isset( $params['settings']['usedCssRumPriority'] ) && isset( $options['file_optimisation']['usedCssRumPriority'] ) ) {
				$sanitized_settings['usedCssRumPriority'] = (bool) $options['file_optimisation']['usedCssRumPriority'];
			}

			// Preserve the RUM-weighted CSS queue keys when the request omits
			// them (issue #1164): same partial-save hazard as the RUM-priority
			// flags above — an older client/partial save must not wipe a
			// custom per-run cap or the viewport-variant toggle.
			if ( 'file_optimisation' === $tab && ! isset( $params['settings']['ccssQueueCap'] ) && isset( $options['file_optimisation']['ccssQueueCap'] ) ) {
				$sanitized_settings['ccssQueueCap'] = absint( $options['file_optimisation']['ccssQueueCap'] );
			}
			// Preserve the CCSS generation timeout when the request omits it
			// (issue #1235): same partial-save hazard as the queue caps above
			// — an older client/partial save must not wipe a custom budget.
			// $settings is the $params['settings'] copy (see above); isset()
			// matches the sibling preserves (an explicit null counts as
			// omitted and keeps the stored value); clamped to 1..120 at
			// write time so 'not-a-number'/0/500 self-heal instead of
			// persisting verbatim.
			if ( 'file_optimisation' === $tab && ! isset( $params['settings']['ccssGenTimeout'] ) && isset( $options['file_optimisation']['ccssGenTimeout'] ) ) {
				$stored                               = $options['file_optimisation']['ccssGenTimeout'];
				$stored                               = is_numeric( $stored ) ? (int) $stored : 25;
				$sanitized_settings['ccssGenTimeout'] = ( $stored >= 1 && $stored <= 120 ) ? $stored : 25;
			}
			if ( 'file_optimisation' === $tab && ! isset( $params['settings']['usedCssQueueCap'] ) && isset( $options['file_optimisation']['usedCssQueueCap'] ) ) {
				$sanitized_settings['usedCssQueueCap'] = absint( $options['file_optimisation']['usedCssQueueCap'] );
			}
			if ( 'file_optimisation' === $tab && ! isset( $params['settings']['ccssViewportVariants'] ) && isset( $options['file_optimisation']['ccssViewportVariants'] ) ) {
				$stored_variants                            = $options['file_optimisation']['ccssViewportVariants'];
				$sanitized_settings['ccssViewportVariants'] = is_array( $stored_variants ) ? array_values( array_filter( array_map( 'sanitize_text_field', $stored_variants ) ) ) : (bool) $stored_variants;
			}

			// Preserve the RUM-gated speculation toggle when the request
			// omits it (issue #1061): PreloadSettings save posts only the
			// toggles it renders, so a save must not wipe the gating flag.
			if ( 'preload_settings' === $tab && ! array_key_exists( 'speculationRumGating', $settings ) && isset( $options['preload_settings']['speculationRumGating'] ) ) {
				// Same filter_var() normalization as
				// Util::sanitize_settings_recursively() so a stored string
				// shape (e.g. 'false') does not diverge between the two paths.
				$stored = $options['preload_settings']['speculationRumGating'];
				if ( is_bool( $stored ) ) {
					$sanitized_settings['speculationRumGating'] = $stored;
				} else {
					$bool                                       = filter_var( $stored, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
					$sanitized_settings['speculationRumGating'] = null === $bool ? true : $bool;
				}
			}

			// Preserve the RUM-weighted top-URL cap when the request omits
			// it (issue #1183): same partial-save hazard as the gating flag
			// above — an older client/partial save must not wipe the cap.
			if ( 'preload_settings' === $tab && ! array_key_exists( 'speculationTopUrlsLimit', $settings ) && isset( $options['preload_settings']['speculationTopUrlsLimit'] ) ) {
				$stored = $options['preload_settings']['speculationTopUrlsLimit'];
				$limit  = is_numeric( $stored ) ? (int) $stored : 2;
				$sanitized_settings['speculationTopUrlsLimit'] = ( $limit >= 1 && $limit <= 5 ) ? $limit : 2;
			}

			// Preserve the high-value prerender list toggle when the
			// request omits it (issue #1237): same partial-save hazard —
			// an older client/partial save must not wipe the off-by-default
			// flag. Normalized like sanitize_settings_recursively().
			if ( 'preload_settings' === $tab && ! array_key_exists( 'speculationPrerenderList', $settings ) && isset( $options['preload_settings']['speculationPrerenderList'] ) ) {
				$stored = $options['preload_settings']['speculationPrerenderList'];
				if ( is_bool( $stored ) ) {
					$sanitized_settings['speculationPrerenderList'] = $stored;
				} else {
					$bool = filter_var( $stored, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
					$sanitized_settings['speculationPrerenderList'] = null === $bool ? false : $bool;
				}
			}

			// Preserve the automatic LCP + font-discovery toggles when the
			// request omits them (issue #1216): same partial-save hazard —
			// an older client/partial save must not wipe the off-by-default
			// flags. Normalized like sanitize_settings_recursively().
			if ( 'preload_settings' === $tab && ! array_key_exists( 'autoLcpPreload', $settings ) && isset( $options['preload_settings']['autoLcpPreload'] ) ) {
				$stored = $options['preload_settings']['autoLcpPreload'];
				if ( is_bool( $stored ) ) {
					$sanitized_settings['autoLcpPreload'] = $stored;
				} else {
					$bool                                 = filter_var( $stored, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
					$sanitized_settings['autoLcpPreload'] = null === $bool ? false : $bool;
				}
			}
			if ( 'preload_settings' === $tab && ! array_key_exists( 'autoDiscoverFonts', $settings ) && isset( $options['preload_settings']['autoDiscoverFonts'] ) ) {
				$stored = $options['preload_settings']['autoDiscoverFonts'];
				if ( is_bool( $stored ) ) {
					$sanitized_settings['autoDiscoverFonts'] = $stored;
				} else {
					$bool                                    = filter_var( $stored, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
					$sanitized_settings['autoDiscoverFonts'] = null === $bool ? false : $bool;
				}
			}

		// P3-008: single write seam — tab merge + snapshot-if-changed +
		// canonical Settings_Store write (autoload disabled, audit #1325).
		// The write result is intentionally ignored on this path (parity
		// with the pre-P3-008 inline update_option() whose return was
		// ignored here); the merged options are always returned.
		$save_result = Settings_Command::save_tab( $options, $tab, $sanitized_settings );
		$options     = $save_result[0];

			if ( class_exists( 'PerformanceOptimise\Inc\Telemetry' ) ) {
				Telemetry::invalidate_audit_cache();
			}

			$this->remove_sensitive_settings_from_response( $options );

			return $this->owner->rest_send_response( $options );
		}

		/**
		 * Imports settings via the REST API.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @since 1.0.0
		 * @return \WP_REST_Response The response object.
		 */
		public function import_settings( \WP_REST_Request $request ) {
			if ( $this->owner->rest_throttle_hit( 'import_settings', 10, 60 ) ) {
				$response = $this->owner->rest_send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			$data = $request->get_json_params();

			if ( ! is_array( $data ) ) {
				return $this->owner->rest_send_response( null, false, 400, __( 'Invalid payload.', 'performance-optimisation' ) );
			}

			if ( ! isset( $data['action'] ) || 'import_settings' !== $data['action'] ) {
				return $this->owner->rest_send_response( null, false, 400, __( 'Invalid action.', 'performance-optimisation' ) );
			}

			if ( empty( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
				return $this->owner->rest_send_response( null, false, 400, __( 'Settings are missing or invalid.', 'performance-optimisation' ) );
			}

			// Validate that only known top-level setting keys are present (single source: Util::ALLOWED_SETTINGS_KEYS).
			$allowed_keys = Util::ALLOWED_SETTINGS_KEYS;

			foreach ( array_keys( $data['settings'] ) as $key ) {
				if ( ! in_array( $key, $allowed_keys, true ) ) {
					return $this->owner->rest_send_response( null, false, 400, __( 'Invalid setting key detected.', 'performance-optimisation' ) );
				}
			}

			// Never store Redis password in the database. Store a boolean flag instead.
			if ( isset( $data['settings']['object_cache'] ) && isset( $data['settings']['object_cache']['password'] ) ) {
				$password_provided = ! empty( $data['settings']['object_cache']['password'] );
				unset( $data['settings']['object_cache']['password'] );
				if ( $password_provided ) {
					$data['settings']['object_cache']['password_set'] = true;
				}
			}

			// Server-only outage status flag (issue #1233): strip before
			// sanitize/merge so imports cannot pin spoofed bypassed state,
			// mirroring password handling. The stored server-written value
			// survives via array_replace_recursive of the remaining keys.
			if ( isset( $data['settings']['object_cache'] ) && is_array( $data['settings']['object_cache'] ) && array_key_exists( 'outage_bypassed', $data['settings']['object_cache'] ) ) {
				unset( $data['settings']['object_cache']['outage_bypassed'] );
			}

			// Sanitize settings before saving.
			$sanitized_settings = $this->sanitize_settings_recursively( $data['settings'] );

		// P3-008: single write seam — recursive merge + snapshot-if-changed
		// + canonical Settings_Store write. No-op imports skip the snapshot
		// and the write inside the command and report success, preserving
		// the 200 no-change branch below; changed imports report the write
		// result, preserving the 500-failure branch.
		$existing_settings = Util::get_settings();
		$save_result       = Settings_Command::save_merged( $existing_settings, $sanitized_settings );
		$merged_settings   = $save_result[0];
		$saved             = $save_result[1];

		// Check if the settings are the same.
		if ( $existing_settings === $merged_settings ) {
			$response_settings = $existing_settings;
			$this->remove_sensitive_settings_from_response( $response_settings );
			return $this->owner->rest_send_response( $response_settings, true, 200, __( 'No changes detected, settings are already up-to-date', 'performance-optimisation' ) );
		}

		if ( ! $saved ) {
			return $this->owner->rest_send_response( null, false, 500, __( 'Failed to update settings', 'performance-optimisation' ) );
		}

			if ( class_exists( 'PerformanceOptimise\Inc\Telemetry' ) ) {
				Telemetry::invalidate_audit_cache();
			}

			$response_settings = $merged_settings;
			$this->remove_sensitive_settings_from_response( $response_settings );

			return $this->owner->rest_send_response( $response_settings, true, 200, __( 'Settings updated successfully', 'performance-optimisation' ) );
		}

		/**
		 * Report whether a prior-settings snapshot exists for one-click undo.
		 *
		 * Read-only: exposes only the snapshot availability + timestamp, never
		 * the snapshot payload itself.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @return \WP_REST_Response The response object.
		 * @since 2.2.0
		 */
		public function get_settings_snapshot( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature must match the REST callback.
			$snapshot = Util::get_settings_snapshot();

			if ( ! is_array( $snapshot ) ) {
				return $this->owner->rest_send_response(
					array(
						'has_snapshot' => false,
						'taken_at'     => null,
					)
				);
			}

			return $this->owner->rest_send_response(
				array(
					'has_snapshot' => true,
					'taken_at'     => isset( $snapshot['taken_at'] ) ? absint( $snapshot['taken_at'] ) : null,
				)
			);
		}

		/**
		 * Restore `wppo_settings` from the prior-settings snapshot (one-click undo).
		 *
		 * Fail-open: a restore failure leaves the current settings intact and
		 * returns an error notice; never fatal. The restore itself takes no new
		 * snapshot, so a second undo cannot clobber the restored state.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @return \WP_REST_Response The response object.
		 * @since 2.2.0
		 */
		public function restore_settings( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature must match the REST callback.
			if ( $this->owner->rest_throttle_hit( 'restore_settings', 5, 60 ) ) {
				$response = $this->owner->rest_send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			// P3-008: restore routes through the command (delegates to the
		// Settings_Store snapshot policy owner).
		$restored = Settings_Command::restore();

			if ( ! is_array( $restored ) ) {
				return $this->owner->rest_send_response( null, false, 404, __( 'No settings snapshot available to restore.', 'performance-optimisation' ) );
			}

			if ( class_exists( 'PerformanceOptimise\Inc\Telemetry' ) ) {
				Telemetry::invalidate_audit_cache();
			}

			$response_settings = $restored;
			$this->remove_sensitive_settings_from_response( $response_settings );

			return $this->owner->rest_send_response( $response_settings, true, 200, __( 'Settings restored successfully.', 'performance-optimisation' ) );
		}

		/**
		 * Get sandbox preview status (issue #1163).
		 *
		 * Returns staged values, the admin-only preview URL, and whether a
		 * staged experiment exists. Read-only; perf tests cannot run inside
		 * the preview (server-side scans are unauthenticated) and always
		 * measure the production URL — staged output is verified visually
		 * via the admin preview link.
		 *
		 * @param \WP_REST_Request $_request The request object.
		 * @return \WP_REST_Response The response object.
		 * @since 2.2.0
		 */
		public function get_sandbox_preview( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			$staged      = class_exists( 'PerformanceOptimise\Inc\Sandbox_Preview' ) ? Sandbox_Preview::get_staged_settings() : array();
			$preview_url = '';
			if ( class_exists( 'PerformanceOptimise\Inc\Sandbox_Preview' ) && method_exists( 'PerformanceOptimise\Inc\Sandbox_Preview', 'get_preview_url' ) ) {
				$home        = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
				$preview_url = Sandbox_Preview::get_preview_url( $home );
			}
			return $this->owner->rest_send_response(
				array(
					'staged'      => $staged,
					'has_staged'  => ! empty( $staged ),
					'preview_url' => $preview_url,
				)
			);
		}

		/**
		 * Save staged sandbox settings (issue #1163).
		 *
		 * POST param `settings` holds the experimental asset slice; only
		 * allowlisted keys persist to `file_optimisation.sandboxStaged`.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @return \WP_REST_Response The response object.
		 * @since 2.2.0
		 */
		public function save_sandbox_preview( \WP_REST_Request $request ): \WP_REST_Response {
			if ( $this->owner->rest_throttle_hit( 'sandbox_save', 5, 60 ) ) {
				$response = $this->owner->rest_send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			$params   = $request->get_params();
			$settings = isset( $params['settings'] ) && is_array( $params['settings'] ) ? $params['settings'] : array();
			if ( ! class_exists( 'PerformanceOptimise\Inc\Sandbox_Preview' ) ) {
				return $this->owner->rest_send_response( null, false, 500, __( 'Sandbox preview is unavailable.', 'performance-optimisation' ) );
			}
			$ok = Sandbox_Preview::save_staged( $settings );
			if ( ! $ok ) {
				return $this->owner->rest_send_response( null, false, 500, __( 'Could not save sandbox settings.', 'performance-optimisation' ) );
			}
			return $this->owner->rest_send_response( array( 'staged' => Sandbox_Preview::get_staged_settings() ) );
		}

		/**
		 * Promote staged sandbox settings to production (issue #1163).
		 *
		 * @param \WP_REST_Request $_request The request object.
		 * @return \WP_REST_Response The response object.
		 * @since 2.2.0
		 */
		public function promote_sandbox_preview( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			if ( $this->owner->rest_throttle_hit( 'sandbox_promote', 5, 60 ) ) {
				$response = $this->owner->rest_send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			if ( ! class_exists( 'PerformanceOptimise\Inc\Sandbox_Preview' ) ) {
				return $this->owner->rest_send_response( null, false, 500, __( 'Sandbox preview is unavailable.', 'performance-optimisation' ) );
			}
			if ( ! Sandbox_Preview::is_staged_available() ) {
				return $this->owner->rest_send_response( null, false, 400, __( 'No staged sandbox settings to promote.', 'performance-optimisation' ) );
			}
			$ok = Sandbox_Preview::promote_staged();
			if ( ! $ok ) {
				return $this->owner->rest_send_response( null, false, 500, __( 'Could not promote sandbox settings.', 'performance-optimisation' ) );
			}
			$options = Util::get_settings();
			$this->remove_sensitive_settings_from_response( $options );
			return $this->owner->rest_send_response( $options );
		}

		/**
		 * Discard staged sandbox settings (issue #1163).
		 *
		 * @param \WP_REST_Request $_request The request object.
		 * @return \WP_REST_Response The response object.
		 * @since 2.2.0
		 */
		public function discard_sandbox_preview( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			if ( $this->owner->rest_throttle_hit( 'sandbox_discard', 5, 60 ) ) {
				$response = $this->owner->rest_send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			if ( ! class_exists( 'PerformanceOptimise\Inc\Sandbox_Preview' ) ) {
				return $this->owner->rest_send_response( null, false, 500, __( 'Sandbox preview is unavailable.', 'performance-optimisation' ) );
			}
			$ok = Sandbox_Preview::discard_staged();
			if ( ! $ok ) {
				return $this->owner->rest_send_response( null, false, 500, __( 'Could not discard sandbox settings.', 'performance-optimisation' ) );
			}
			return $this->owner->rest_send_response( array( 'staged' => array() ) );
		}

		/**
		 * Sanitizes the settings array recursively.
		 *
		 * Delegates to Util::sanitize_settings_recursively() so that all
		 * settings entry points (REST API, WP-CLI import/update) share
		 * identical sanitization semantics.
		 *
		 * @param array $settings The settings array.
		 * @return array The sanitized settings array.
		 * @since 1.1.1
		 */
		private function sanitize_settings_recursively( array $settings ): array {
			// Audit #1434: typed.
			return Util::sanitize_settings_recursively( $settings );
		}

		/**
		 * Removes sensitive settings from the response array.
		 *
		 * Delegates to Util::remove_sensitive_settings_from_response() so
		 * every REST read path redacts the same keys.
		 *
		 * @param array $settings The settings array passed by reference.
		 * @return void
		 * @since 1.1.1
		 */
		private function remove_sensitive_settings_from_response( array &$settings ): void {
			Util::remove_sensitive_settings_from_response( $settings );
		}
	}
}
