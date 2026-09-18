<?php
/**
 * PerformanceOptimise\Inc\Rest
 *
 * This class registers and manages the REST API routes related to performance optimization
 * functionalities, such as clearing the cache, optimizing images, updating settings, and more.
 * It provides endpoints for interacting with the plugin's features programmatically.
 *
 * @since 1.0.0
 * @package PerformanceOptimise\Inc
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Rest' ) ) {

	/**
	 * Registers REST API routes and handles requests for various performance optimization features.
	 *
	 * @since 1.0.0
	 */
	class Rest {

		/**
		 * REST API namespace.
		 *
		 * @var string
		 * @since 1.0.0
		 */
		const NAMESPACE = 'performance-optimisation/v1';

		/**
		 * Cache directory path.
		 *
		 * @var string
		 * @since 1.6.0
		 */
		private string $cache_dir; // Audit #1434: typed per docblock.

		/**
		 * Constructor.
		 *
		 * @since 1.6.0
		 */
		public function __construct() {
			$this->cache_dir = trailingslashit( WP_CONTENT_DIR ) . 'cache/wppo';
		}

		/**
		 * Registers the REST API routes.
		 *
		 * @since 1.0.0
		 */
		public function register_routes() {
			$routes = $this->get_routes();

			foreach ( $routes as $route => $route_data ) {
				register_rest_route( self::NAMESPACE, $route, $route_data );
			}
		}

		/**
		 * Provide the REST route definitions used when registering this class's endpoints.
		 *
		 * Each array entry maps a route slug to its registration configuration including
		 * HTTP methods, the callback handler, and the permission callback.
		 *
		 * @return array<string, array> Associative array of route slugs to route configuration arrays.
		 */
		private function get_routes() {
			$schemas = array( $this, 'get_schema_for_route' );

			return array(
				'clear_cache'               => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'clear_cache' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'update_settings'           => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'optimise_image'            => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'optimise_image' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'delete_optimised_image'    => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'delete_optimised_image' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'recent_activities'         => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_recent_activities' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'import_settings'           => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'import_settings' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'settings_snapshot'         => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings_snapshot' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'restore_settings'          => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'restore_settings' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'database_cleanup'          => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'database_cleanup' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'database_cleanup_counts'   => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_database_cleanup_counts' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'image_job_status'          => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_image_job_status' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'object_cache'              => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_object_cache' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),

				// Phase 1 — Local Diagnostics (v1.5.0).
				'system_info'               => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_system_info' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'performance_scan'          => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'run_performance_scan' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),

				// Phase 2 — PageSpeed Integration & Actionable Suggestions (v1.6.0).
				'pagespeed_scan'            => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'queue_pagespeed_scan' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'pagespeed_results'         => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_pagespeed_results' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'web_vitals_trends'         => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_web_vitals_trends' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'suggestions'               => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_suggestions' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'server_rules'              => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_server_rules' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'woo_cache_self_test'       => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_woo_cache_self_test' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'used_css_regenerate'       => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'used_css_regenerate' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'purge_used_css_cache'      => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'purge_used_css_cache' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'purge_derived_caches'      => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'purge_derived_caches' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'upgrade_purge_status'      => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_upgrade_purge_status' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'used_css_status'           => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_used_css_status' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'regenerate_ccss'           => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'regenerate_ccss' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'ccss_status'               => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_ccss_status' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'dismiss_welcome'           => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'dismiss_welcome' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),

				// Real-user Web Vitals (v2.18.0). The beacon is intentionally public
				// (anonymous visitors) so it is validated via token + rate limiting
				// instead of the manage_options permission used by the admin routes.
				// Compensating controls (see RUM::collect):
				// - Daily rolling per-path token: wp_hash('wppo_rum_' . Ymd . '|' . path) + hash_equals(), 24h rotation.
				// - Per-IP rate limit: 120/hour via Util::transient_key('wppo_rum_ratelimit_' . md5(IP)), multisite-safe.
				// - Bounded storage: 14 days × 200 paths/day with oldest-path eviction; metrics clamped.
				// __return_true is intentional and reviewed (A08 A-AUTH-01) — do not gate with manage_options.
				// @since 2.0.0 Added rate-limit documentation.
				'rum_collect'               => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'collect_rum' ),
					'permission_callback' => '__return_true',
					'schema'              => $schemas,
				),
				'rum_data'                  => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_rum_data' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'lcp_preload_candidate'     => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_lcp_preload_candidate' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'autoloaded_options'        => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_autoloaded_options' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'autoload_remediate'        => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_autoload_remediate' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'expired_transients_export' => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'export_expired_transients' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'preload_status'            => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_preload_status' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'preload_resume'            => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'resume_preload' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'ai_model'                  => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_ai_model' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'ai_learn'                  => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'ai_learn' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'ai_suggestions'            => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_ai_suggestions' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'sandbox_preview'           => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_sandbox_preview' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'sandbox_save'              => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_sandbox_preview' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'sandbox_promote'           => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'promote_sandbox_preview' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'sandbox_discard'           => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'discard_sandbox_preview' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'safe_mode_detect'          => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'detect_safe_mode_excludes' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
				'safe_mode'                 => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_safe_mode' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'schema'              => $schemas,
				),
			);
		}

		/**
		 * List the largest autoloaded options.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @return \WP_REST_Response The response object.
		 */
		public function get_autoloaded_options( \WP_REST_Request $request ): \WP_REST_Response {
			$limit  = isset( $request->get_params()['limit'] ) ? absint( $request->get_params()['limit'] ) : 20;
			$limit  = max( 1, min( 100, $limit ) );
			$result = Database_Cleanup::get_autoloaded_options( $limit );

			return $this->send_response( array( 'options' => $result ) );
		}

		/**
		 * Handle one-click autoload-bloat remediation (dry run, apply, revert, revert_all).
		 *
		 * POST param `mode` controls the operation:
		 * - `dry_run` (default): report candidates + bytes saved + backup export, changes nothing.
		 * - `apply`: flip non-core options above the threshold to autoload off; response includes the backup export.
		 * - `revert`: restore one option (`option` param) to its prior value.
		 * - `revert_all`: restore every remediated option to its exact prior
		 *   value (byte-identical); response includes restored details + total.
		 *
		 * Optional params: `threshold` (bytes, 100..10MB), `limit` (1..500).
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @return \WP_REST_Response The response object.
		 * @since 2.0.0
		 */
		public function handle_autoload_remediate( \WP_REST_Request $request ): \WP_REST_Response {
			$params = $request->get_params();
			$mode   = isset( $params['mode'] ) ? sanitize_text_field( $params['mode'] ) : 'dry_run';

			if ( ! in_array( $mode, array( 'dry_run', 'apply', 'revert', 'revert_all' ), true ) ) {
				return $this->send_response( null, false, 400, __( 'Invalid remediation mode.', 'performance-optimisation' ) );
			}

			// Throttle every mutating mode (apply/revert/revert_all all issue
			// bulk update_option writes); dry_run is read-only and unthrottled.
			if ( 'dry_run' !== $mode && $this->is_endpoint_throttled( 'autoload_remediate', 5, 60 ) ) {
				$throttled = $this->send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$throttled->header( 'Retry-After', '60' );
				return $throttled;
			}

			if ( 'revert' === $mode ) {
				$option = isset( $params['option'] ) ? sanitize_text_field( $params['option'] ) : '';
				if ( '' === $option ) {
					return $this->send_response( null, false, 400, __( 'Option name is required for revert.', 'performance-optimisation' ) );
				}
				$result = Database_Cleanup::revert_autoload_option( $option );
				if ( is_wp_error( $result ) ) {
					return $this->send_response( null, false, 400, $result->get_error_message() );
				}
				return $this->send_response( array( 'reverted' => $option ) );
			}

			if ( 'revert_all' === $mode ) {
				$result = Database_Cleanup::revert_autoload_all();
				return $this->send_response( $result );
			}

			$threshold = isset( $params['threshold'] ) ? absint( $params['threshold'] ) : null;
			if ( null !== $threshold ) {
				$threshold = max( Database_Cleanup::AUTOLOAD_THRESHOLD_MIN, min( Database_Cleanup::AUTOLOAD_THRESHOLD_MAX, $threshold ) );
			}
			$limit = isset( $params['limit'] ) ? absint( $params['limit'] ) : Database_Cleanup::AUTOLOAD_REMEDIATION_LIMIT;
			$limit = max( 1, min( Database_Cleanup::AUTOLOAD_LIMIT_MAX, $limit ) );

			if ( 'apply' === $mode ) {
				$result               = Database_Cleanup::remediate_autoload( $threshold, $limit );
				$result['remediated'] = Database_Cleanup::get_remediated_options();
				return $this->send_response( $result );
			}

			$report               = Database_Cleanup::plan_autoload_remediation( $threshold, $limit );
			$report['remediated'] = Database_Cleanup::get_remediated_options();
			return $this->send_response( $report );
		}

		/**
		 * Export expired transients for pre-run review (read-only, expired rows only).
		 *
		 * Optional GET param `limit` (1..2000, default 500).
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @return \WP_REST_Response The response object.
		 * @since 2.0.0
		 */
		public function export_expired_transients( \WP_REST_Request $request ): \WP_REST_Response {
			$params = $request->get_params();
			$limit  = isset( $params['limit'] ) ? absint( $params['limit'] ) : 500;
			$limit  = max( 1, min( Database_Cleanup::EXPORT_LIMIT_MAX, $limit ) );
			$rows   = Database_Cleanup::export_expired_transients( $limit );

			return $this->send_response(
				array(
					'count'      => count( $rows ),
					'limit'      => $limit,
					'truncated'  => count( $rows ) >= $limit,
					'transients' => $rows,
				)
			);
		}

		/**
		 * Handle a real-user Web Vitals beacon.
		 *
		 * Public endpoint (permission_callback __return_true) — intentionally
		 * unauthenticated so anonymous visitors can submit beacons. Protected by
		 * daily per-path token (RUM::is_valid_token) + per-IP rate limiting
		 * (RUM::is_rate_limited, 120/hour) instead of manage_options.
		 * See route definition docblock for compensating controls.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @return \WP_REST_Response The response object.
		 * @since 2.0.0 Added public-endpoint justification with rate-limit reference.
		 */
		public function collect_rum( \WP_REST_Request $request ): \WP_REST_Response {
			$params = $request->get_json_params();
			$result = RUM::collect( is_array( $params ) ? $params : array() );

			if ( ! $result['ok'] ) {
				return $this->send_response( null, false, $result['status'], $result['message'] );
			}

			return $this->send_response( array( 'success' => true ) );
		}

		/**
		 * Retrieve aggregated real-user Web Vitals for the dashboard.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @return \WP_REST_Response The response object.
		 */
		public function get_rum_data( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return $this->send_response( RUM::get_data() );
		}

		/**
		 * Resumable sitemap preload progress plus bounded-cache cap status.
		 *
		 * Fail-open: queue/cache failures return idle/ok payloads, never fatal.
		 *
		 * @since NEXT
		 * @param \WP_REST_Request $request The request object.
		 * @return \WP_REST_Response The response object.
		 */
		public function get_preload_status( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			$preload = array(
				'queued'      => 0,
				'done'        => 0,
				'failed'      => 0,
				'total'       => 0,
				'status'      => 'idle',
				'failed_urls' => array(),
			);
			$cache   = array(
				'bytes'     => 0,
				'cap_bytes' => 0,
				'state'     => 'ok',
				'enforce'   => true,
				'max_mb'    => 0,
			);
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Cron' ) && method_exists( 'PerformanceOptimise\Inc\Cron', 'get_preload_status' ) ) {
					$preload = Cron::get_preload_status();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Cache' ) && method_exists( 'PerformanceOptimise\Inc\Cache', 'get_cache_cap_status' ) ) {
					$cache = Cache::get_cache_cap_status();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $this->send_response(
				array(
					'preload' => $preload,
					'cache'   => $cache,
				)
			);
		}

		/**
		 * Retrieve the single LCP preload candidate for the settings UI.
		 *
		 * Surfaces the field-measured hero for one-click preload: the manual
		 * per-post picker (`_wppo_lcp_preload_url`) wins when `post_id` names
		 * a singular post, then sample-gated RUM field data
		 * (`RUM::get_field_lcp_url()`), then Optimization Detective
		 * (`OD_Bridge::get_lcp_url()`), then stored PageSpeed data
		 * (`RUM::get_stored_pagespeed_lcp_url()`).
		 * Optional GET params: `path` (page path, e.g.
		 * `/about/`) and `post_id`. Fail-open: detection failure returns a
		 * null candidate (never fatal), so the UI falls back to the manual
		 * picker path. Multisite-safe: candidate lookups use
		 * `Util::transient_key()` blog-aware keys.
		 *
		 * Note: the OD tier is current-request only and best-effort in admin
		 * context — `OD_Bridge::get_lcp_url()` takes no URL argument and
		 * resolves metrics for the current request (the admin REST URL when
		 * called from this route), so it can rarely resolve the requested
		 * page's hero. The `path` param is honoured by the RUM-field and
		 * stored-PageSpeed tiers only.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @return \WP_REST_Response The response object.
		 * @since NEXT
		 */
		public function get_lcp_preload_candidate( \WP_REST_Request $request ): \WP_REST_Response {
			$params  = $request->get_params();
			$path    = isset( $params['path'] ) && is_string( $params['path'] ) ? substr( trim( sanitize_text_field( wp_unslash( $params['path'] ) ) ), 0, 512 ) : null;
			$post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
			if ( is_string( $path ) && '' !== $path && '/' !== $path[0] ) {
				$path = '/' . $path;
			}

			$candidate = null;
			$source    = 'none';

			// Manual per-post picker first (fallback path that works before auto-detect).
			if ( $post_id > 0 && function_exists( 'get_post_meta' ) ) {
				try {
					$manual = get_post_meta( $post_id, '_wppo_lcp_preload_url', true );
					if ( is_string( $manual ) && '' !== trim( $manual ) ) {
						$sanitized = function_exists( 'esc_url_raw' ) ? esc_url_raw( trim( substr( $manual, 0, 2048 ) ) ) : trim( substr( $manual, 0, 2048 ) );
						// esc_url_raw() refuses javascript:/data: URIs to an
						// empty string, and non-image URLs must not surface as
						// preload candidates (parity with the frontend
						// pipeline's is_image_lcp_url() guard).
						if ( '' !== $sanitized && $this->is_image_candidate_url( $sanitized ) && $this->is_same_origin_candidate_url( $sanitized ) ) {
							$candidate = array(
								'url'      => $sanitized,
								'n'        => 0,
								'lastSeen' => time(),
							);
							$source    = 'manual';
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			// Optimization Detective real-visit hero, second tier (issue #1216):
			// the frontend resolve_auto_lcp_url() chain is manual > OD >
			// RUM-field/PageSpeed > heuristic, so the settings UI checks OD
			// before RUM-field to resolve the same hero as the frontend when
			// both OD and RUM-field data exist. OD_Bridge::is_enabled()
			// already applies the wppo_od_should_optimize filter with the
			// correct current URL, so no separate filter pre-check here.
			if ( null === $candidate && class_exists( 'PerformanceOptimise\Inc\OD_Bridge' ) && method_exists( 'PerformanceOptimise\Inc\OD_Bridge', 'get_lcp_url' ) ) {
				try {
					$od_available = class_exists( 'OD_URL_Metric' ) || function_exists( 'od_get_url_metrics' );
					if ( $od_available && method_exists( 'PerformanceOptimise\Inc\OD_Bridge', 'is_enabled' ) && \PerformanceOptimise\Inc\OD_Bridge::is_enabled() ) {
						$od_url = \PerformanceOptimise\Inc\OD_Bridge::get_lcp_url();
						if ( is_string( $od_url ) && '' !== $od_url && $this->is_image_candidate_url( $od_url ) && $this->is_same_origin_candidate_url( $od_url ) ) {
							$candidate = array(
								'url'      => $od_url,
								'n'        => 0,
								'lastSeen' => time(),
							);
							$source    = 'od';
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			// RUM field data wins over lab guesses when it passes the sample gate.
			// Field-only here: the stored-PageSpeed fallback lives in its own
			// tier below (manual > OD > RUM field > stored PageSpeed, matching
			// the frontend chain). Gated on the same fieldLcpOverride toggle
			// the frontend chain uses (issue #1216) so the settings UI resolves
			// the same hero as the frontend: with the toggle off both paths
			// skip the RUM-field tier.
			$field_override = false;
			try {
				$stored_opts    = class_exists( 'PerformanceOptimise\Inc\Util' ) ? Util::get_settings() : array();
				$field_override = is_array( $stored_opts ) && ! empty( $stored_opts['image_optimisation']['fieldLcpOverride'] );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			if ( $field_override && null === $candidate && class_exists( 'PerformanceOptimise\Inc\RUM' ) && method_exists( 'PerformanceOptimise\Inc\RUM', 'get_field_lcp_url' ) ) {
				try {
					$field = RUM::get_field_lcp_url( $path );
					if ( is_array( $field ) && ! empty( $field['url'] ) && is_string( $field['url'] ) ) {
						$candidate = $field;
						$source    = 'rum';
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			// Stored PageSpeed candidate, last tier (fail-open to null candidate).
			if ( null === $candidate && class_exists( 'PerformanceOptimise\Inc\RUM' ) && method_exists( 'PerformanceOptimise\Inc\RUM', 'get_stored_pagespeed_lcp_url' ) ) {
				try {
					$stored = RUM::get_stored_pagespeed_lcp_url( $path );
					if ( is_string( $stored ) && '' !== $stored ) {
						$candidate = array(
							'url'      => $stored,
							'n'        => 0,
							'lastSeen' => time(),
						);
						$source    = 'pagespeed';
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			return $this->send_response(
				array(
					'candidate' => $candidate,
					'source'    => $source,
				)
			);
		}

		/**
		 * Resume the sitemap preload queue (re-schedule queued + failed URLs).
		 *
		 * @since NEXT
		 * @param \WP_REST_Request $request The request object.
		 * @return \WP_REST_Response The response object.
		 */
		public function resume_preload( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			if ( $this->is_endpoint_throttled( 'resume_preload', 5, 60 ) ) {
				$response = $this->send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			$rescheduled = 0;
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Cron' ) && method_exists( 'PerformanceOptimise\Inc\Cron', 'resume_preload_queue' ) ) {
					$rescheduled = Cron::resume_preload_queue();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			$status = array();
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Cron' ) && method_exists( 'PerformanceOptimise\Inc\Cron', 'get_preload_status' ) ) {
					$status = Cron::get_preload_status();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $this->send_response(
				array(
					'rescheduled' => $rescheduled,
					'preload'     => $status,
				),
				true,
				200,
				$rescheduled > 0 ? __( 'Preload queue resumed.', 'performance-optimisation' ) : __( 'Nothing to resume.', 'performance-optimisation' )
			);
		}

		/**
		 * Whether a candidate URL is a plausible LCP image (text-LCP guard).
		 *
		 * Mirrors the frontend pipeline's `is_image_lcp_url()` guard so the
		 * settings UI never surfaces a `javascript:`/`data:`/non-image manual
		 * picker value as a preload candidate: such URIs are refused, and the
		 * URL must either map to a known image MIME type, carry an image file
		 * extension, or (for extensionless image-CDN URLs) carry image-ish
		 * query params. Fail-open: any failure returns false.
		 *
		 * @since NEXT
		 * @param string $url The candidate URL.
		 * @return bool True when the URL may be preloaded as an image.
		 */
		private function is_image_candidate_url( string $url ): bool {
			try {
				$url = trim( $url );
				if ( '' === $url ) {
					return false;
				}
				$lower = strtolower( ltrim( $url ) );
				if ( str_starts_with( $lower, 'data:' ) || str_starts_with( $lower, 'blob:' ) || str_starts_with( $lower, 'javascript:' ) || str_starts_with( $lower, 'vbscript:' ) ) {
					return false;
				}
				// Individually guarded: Util::get_image_mime_type() calls
				// wp_parse_url() unguarded, so a missing WP shim (unit
				// contexts) must fall through to the extension checks below
				// instead of rejecting the candidate.
				try {
					if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_image_mime_type' ) && '' !== Util::get_image_mime_type( $url ) ) {
						return true;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				$path = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_PATH ) : parse_url( $url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback only when wp_parse_url() is unavailable (unit contexts).
				if ( is_string( $path ) && '' !== $path && 1 === preg_match( '/\.(jpe?g|png|gif|webp|avif|svg|heic|heif|jxl)$/i', $path ) ) {
					return true;
				}
				$query = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_QUERY ) : parse_url( $url, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback only when wp_parse_url() is unavailable (unit contexts).
				if ( is_string( $query ) && '' !== $query ) {
					if ( 1 === preg_match( '/\.(jpe?g|png|gif|webp|avif|svg|heic|heif|jxl)/i', $query ) ) {
						return true;
					}
					// Image-service keys only: generic keys (ssl, url, src,
					// strip) also appear on non-image URLs and must not
					// classify a page URL as a preloadable image.
					if ( 1 === preg_match( '/(^|&)(w|h|width|height|format|fit|crop|resize|quality)(=|&|$)/i', $query ) ) {
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
		 * Whether a REST LCP candidate URL is same-origin with this site.
		 *
		 * Emission-path parity with the frontend pipeline (issue #1216):
		 * delegates to `RUM::is_same_origin_url_strict()` when available so
		 * an unverifiable verdict maps to false. Fail-closed: any failure
		 * returns false.
		 *
		 * @since NEXT
		 * @param string $url The candidate URL.
		 * @return bool True when the URL may surface as a candidate.
		 */
		private function is_same_origin_candidate_url( string $url ): bool {
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\RUM' ) && method_exists( 'PerformanceOptimise\Inc\RUM', 'is_same_origin_url_strict' ) ) {
					return RUM::is_same_origin_url_strict( $url );
				}
				if ( class_exists( 'PerformanceOptimise\Inc\RUM' ) && method_exists( 'PerformanceOptimise\Inc\RUM', 'is_same_origin_url' ) ) {
					return RUM::is_same_origin_url( $url );
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Returns the JSON schema for a given REST route.
		 *
		 * Provides a standard response schema including success status, data payload,
		 * and error message structure. Enables API discoverability and integration
		 * with WP REST API tooling.
		 *
		 * @since 2.0.0
		 *
		 * @return array The JSON schema definition.
		 */
		public function get_schema_for_route(): array {
			return array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'performance-optimisation',
				'type'       => 'object',
				'properties' => array(
					'success' => array(
						'description' => __( 'Whether the request was successful.', 'performance-optimisation' ),
						'type'        => 'boolean',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
					'data'    => array(
						'description' => __( 'Response data payload.', 'performance-optimisation' ),
						'type'        => array( 'object', 'array', 'string', 'boolean', 'null' ),
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
					'message' => array(
						'description' => __( 'Response message.', 'performance-optimisation' ),
						'type'        => array( 'string', 'null' ),
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
				),
				'required'   => array( 'success', 'data', 'message' ),
			);
		}

		/**
		 * Checks if the user has permission to access the route.
		 *
		 * @since 1.0.0
		 * @since 2.0.0 Added $request parameter for header canonicalization.
		 * @param \WP_REST_Request|null $request The REST request object.
		 * @return bool True if the user has permission, false otherwise.
		 */
		public function permission_callback( ?\WP_REST_Request $request = null ): bool {
			if ( ! current_user_can( 'manage_options' ) ) {
				return false;
			}

			$nonce = '';
			if ( $request instanceof \WP_REST_Request ) {
				// get_header() values are not slashed by WP, so no wp_unslash() is
				// needed here. A repeated header makes get_header() return an
				// array; use the first value instead of casting the whole array.
				$header = $request->get_header( 'X-WP-Nonce' );
				$nonce  = is_array( $header ) ? (string) reset( $header ) : (string) $header;
			}
			// BC-only fallback for legacy callers without a request object: when a
			// request was supplied, WP's header canonicalization is authoritative and
			// the raw $_SERVER value must not override it. wp_unslash() is needed
			// only on this raw superglobal path.
			if ( null === $request && isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$nonce = (string) wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] );
			}

			$nonce = sanitize_text_field( $nonce );

			return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
		}

		/**
		 * Per-request static throttle buckets (audit #1453).
		 *
		 * Fallback when the transient API is unavailable: bounds bursts
		 * within a single request lifecycle. Request-scoped by design —
		 * cross-request throttling still needs transients.
		 *
		 * @since NEXT
		 * @param string $endpoint Endpoint slug.
		 * @param int    $limit    Max hits per window.
		 * @param int    $window   Window in seconds.
		 * @return bool True when throttled.
		 */
		private static function static_throttle_hit( string $endpoint, int $limit, int $window ): bool {
			static $buckets = array();
			$now            = time();
			if ( ! isset( $buckets[ $endpoint ] ) || $now - $buckets[ $endpoint ]['at'] >= $window ) {
				$buckets[ $endpoint ] = array(
					'at'   => $now,
					'hits' => 1,
				);
				return false;
			}
			++$buckets[ $endpoint ]['hits'];
			return $buckets[ $endpoint ]['hits'] > $limit;
		}

		/**
		 * Per-endpoint transient throttle for heavy admin endpoints.
		 *
		 * @since NEXT
		 * @param string $endpoint Endpoint slug.
		 * @param int    $limit    Max hits per window.
		 * @param int    $window   Window in seconds.
		 * @return bool True when throttled.
		 */
		private function is_endpoint_throttled( string $endpoint, int $limit = 10, int $window = 60 ): bool {
			// Fail-open when the transient API is unavailable (unit stubs
			// without the transient helpers): throttling is best-effort.
			// Degrade loudly so inert throttling never goes unnoticed
			// (audit #1329): full denial here would lock admins out when
			// object cache backends flap, so log instead of blocking.
			if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
				if ( class_exists( 'PerformanceOptimise\Inc\Log' ) ) {
					try {
						Log::add( sprintf( 'Endpoint throttle inert for %s: transient API unavailable', $endpoint ) );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				// Audit #1453: static per-request fallback bucket so a transient
				// outage degrades throttling instead of disabling it.
				return self::static_throttle_hit( $endpoint, $limit, $window );
			}
			try {
				// Per-user/IP key so one actor cannot exhaust the budget for
				// everyone sharing the endpoint slug.
				$suffix = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
				if ( 0 === $suffix ) {
					$suffix = self::throttle_client_suffix();
				}
				$key    = Util::transient_key( 'wppo_throttle_' . ( function_exists( 'sanitize_key' ) ? sanitize_key( $endpoint ) : strtolower( (string) preg_replace( '/[^a-z0-9_-]/i', '', $endpoint ) ) ) . '_' . md5( (string) $suffix ) );
				$bucket = get_transient( $key );
				$now    = time();
				// Fixed window: the TTL is set only on the first increment; later
				// hits re-store with the remaining TTL instead of extending it.
				if ( ! is_array( $bucket ) || ! isset( $bucket['count'], $bucket['start'] ) || (int) $bucket['start'] > $now || ( $now - (int) $bucket['start'] ) >= $window ) {
					set_transient(
						$key,
						array(
							'count' => 1,
							'start' => $now,
						),
						$window
					);
					return false;
				}
				$count = (int) $bucket['count'];
				if ( $count >= $limit ) {
					return true;
				}
				// Clamp into [1, $window]: a future-dated (corrupted or tampered)
				// start would otherwise persist the bucket past one window. Future
				// starts reset above; the min() cap bounds this call's TTL regardless.
				$elapsed   = $now - (int) $bucket['start'];
				$remaining = max( 1, min( $window, $window - $elapsed ) );
				set_transient(
					$key,
					array(
						'count' => $count + 1,
						'start' => (int) $bucket['start'],
					),
					$remaining
				);
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Client suffix for the anonymous endpoint-throttle bucket.
		 *
		 * REMOTE_ADDR only — deliberately no X-Forwarded-For. The header is
		 * client-controlled, so mixing it into the key lets an attacker
		 * rotate XFF per request for a fresh bucket and never hit the
		 * limit. Behind a proxy/CDN this collapses edge-sharing clients
		 * into one bucket (fail-closed direction: legitimate bursts may
		 * 429 sooner rather than letting throttling be bypassed); sites
		 * with a trusted proxy should enforce limits at the edge instead.
		 *
		 * @since NEXT
		 * @return string Anon throttle suffix.
		 */
		private static function throttle_client_suffix(): string {
			$remote = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return '' !== $remote ? $remote : 'anon';
		}

		/**
		 * Same-site URL gate shared by readers and writers.
		 *
		 * @since NEXT
		 * @param string $url URL to check.
		 * @return bool
		 */
		private function is_same_site_url( string $url ): bool {
			// Audit #1357 review: canonical Util comparator (lowercases both
			// sides; the old raw === was the case-sensitivity outlier).
			if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'is_same_site_url' ) ) {
				try {
					return Util::is_same_site_url( $url );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			return false;
		}

		/**
		 * Clears the cache based on the given action.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @since 1.0.0
		 * @return \WP_REST_Response The response object.
		 */
		public function clear_cache( \WP_REST_Request $request ) {
			if ( $this->is_endpoint_throttled( 'clear_cache', 5, 60 ) ) {
				$response = $this->send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			$params = $request->get_params();
			$action = isset( $params['action'] ) ? sanitize_text_field( $params['action'] ) : '';
			$path   = isset( $params['path'] ) ? sanitize_text_field( $params['path'] ) : '';
			$group  = isset( $params['group'] ) ? sanitize_text_field( $params['group'] ) : '';

			// Handle cache group flushing.
			if ( ! empty( $group ) ) {
				$flushed = Cache::flush_group( $group );

				if ( ! $flushed ) {
					if ( function_exists( 'wp_cache_supports' ) && ! wp_cache_supports( 'flush_group' ) ) {
						Log::add(
							sprintf(
								/* translators: %s: The cache group name */
								__( 'Object cache does not support flush_group for %s — no action taken', 'performance-optimisation' ),
								$group
							)
						);
						return $this->send_response( array( 'flushed' => false ), false, 400, __( 'Object cache does not support flush_group — no action taken', 'performance-optimisation' ) );
					}

					Log::add(
						sprintf(
							/* translators: %s: The cache group name */
							__( 'Failed to flush cache group: %s', 'performance-optimisation' ),
							$group
						)
					);
					return $this->send_response( array( 'flushed' => $flushed ), false, 500, __( 'Failed to flush cache group.', 'performance-optimisation' ) );
				}

				Log::add(
					sprintf(
						/* translators: %s: The cache group name */
						__( 'Flushed cache group: %s', 'performance-optimisation' ),
						$group
					)
				);
				return $this->send_response( array( 'flushed' => $flushed ) );
			}

			$path = wp_normalize_path( $path );

			// Reject paths with directory traversal or outside the cache directory.
			// Empty path (clear all) has no traversal risk; realpath() returns false
			// when the cache directory does not exist yet, so it must not be validated.
			if ( '' !== $path ) {
				$normalized_cache_dir       = wp_normalize_path( $this->cache_dir );
				$normalized_cache_dir_trail = trailingslashit( $normalized_cache_dir );
				// Normalized candidate for fallback when realpath() fails (uncached page).
				$candidate_path = wp_normalize_path( trailingslashit( $this->cache_dir ) . ltrim( $path, '/\\' ) );

				$real_path = realpath( $this->cache_dir . $path );
				if ( false !== $real_path ) {
					$normalized_real_path = wp_normalize_path( $real_path );

					$is_exact_match = ( $normalized_real_path === $normalized_cache_dir );
					$is_under_dir   = ( 0 === strpos( $normalized_real_path, $normalized_cache_dir_trail ) );

					if ( ! $is_exact_match && ! $is_under_dir ) {
						return $this->send_response( null, false, 400, __( 'Invalid path provided.', 'performance-optimisation' ) );
					}
				} else {
					// Fallback when realpath() returns false (uncached page or missing dir).
					// Validates via normalized string prefix so "Clear This Page" works before caching.
					// @since 2.0.0 Added wp_normalize_path fallback for uncached pages.
					$is_exact_match = ( $candidate_path === $normalized_cache_dir );
					$is_under_dir   = ( 0 === strpos( $candidate_path, $normalized_cache_dir_trail ) );

					$decoded       = rawurldecode( $candidate_path );
					$has_traversal = false;
					foreach ( explode( '/', $decoded ) as $segment ) {
						if ( '..' === $segment ) {
							$has_traversal = true;
							break;
						}
					}
					if ( ( ! $is_exact_match && ! $is_under_dir ) || $has_traversal ) {
						return $this->send_response( null, false, 400, __( 'Invalid path provided.', 'performance-optimisation' ) );
					}
				}
			}

			if ( 'clear_single_page_cache' === $action ) {
				$cleared = Cache::clear_cache( $path );
				if ( ! $cleared ) {
					return $this->send_response( null, false, 400, __( 'Failed to clear cache: Invalid path.', 'performance-optimisation' ) );
				}
				$url = Util::cached_home_url( $path );
				Log::add(
					sprintf(
						/* translators: %s: The URL of the page */
						__( 'Clear cache of <a href="%1$s">%2$s</a>', 'performance-optimisation' ),
						esc_url( $url ),
						esc_html( $url )
					)
				);
			} else {
				Cache::clear_cache();
				Log::add( __( 'Clear all cache', 'performance-optimisation' ) );
			}
			return $this->send_response( true );
		}

		/**
		 * Updates the settings for the plugin.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @since 1.0.0
		 * @return \WP_REST_Response The response object.
		 */
		public function update_settings( \WP_REST_Request $request ) {
			if ( $this->is_endpoint_throttled( 'update_settings', 5, 60 ) ) {
				$response = $this->send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			$params   = $request->get_params();
			$tab      = isset( $params['tab'] ) ? sanitize_text_field( $params['tab'] ) : '';
			$settings = isset( $params['settings'] ) && is_array( $params['settings'] ) ? $params['settings'] : array();

			// Validate tab against known whitelist (single source: Util::ALLOWED_SETTINGS_TABS).
			$allowed_tabs = Util::ALLOWED_SETTINGS_TABS;
			if ( empty( $tab ) || ! in_array( $tab, $allowed_tabs, true ) ) {
				return $this->send_response( null, false, 400, __( 'Invalid settings tab.', 'performance-optimisation' ) );
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

			$merged_options = $options;
			// Merge into the existing tab (issue #1216): a partial POST (e.g.
			// only autoLcpPreload from an older client) must not delete sibling
			// keys like enablePreloadCache or preloadFontsUrls. The tab value is
			// replaced only when no prior tab array exists.
			$prior_tab              = ( isset( $options[ $tab ] ) && is_array( $options[ $tab ] ) ) ? $options[ $tab ] : array();
			$merged_options[ $tab ] = array_merge( $prior_tab, is_array( $sanitized_settings ) ? $sanitized_settings : array() );

			// One-click undo (issue #1144): snapshot the prior settings before
			// overwriting, but skip no-op saves so an identical write does not
			// churn the single-slot snapshot (mirrors import_settings). Fail-open:
			// a snapshot failure must never block the save.
			if ( $merged_options !== $options ) {
				try {
					Util::take_settings_snapshot( $options );
				} catch ( \Throwable $snapshot_error ) {
					unset( $snapshot_error );
				}
			}

			$options = $merged_options;

			// Audit #1325: never autoload the multi-tab settings array.
			update_option( 'wppo_settings', $options, false );

			if ( class_exists( 'PerformanceOptimise\Inc\Telemetry' ) ) {
				Telemetry::invalidate_audit_cache();
			}

			$this->remove_sensitive_settings_from_response( $options );

			return $this->send_response( $options );
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
		 * @param array $settings The settings array passed by reference.
		 * @return void
		 */
		private function remove_sensitive_settings_from_response( array &$settings ): void {
			if ( isset( $settings['performance_audit'] ) ) {
				unset( $settings['performance_audit']['pagespeed_api_key'] );
			}
			if ( isset( $settings['object_cache'] ) && isset( $settings['object_cache']['password'] ) ) {
				unset( $settings['object_cache']['password'] );
			}
		}

		/**
		 * Retrieves the recent activities.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @since 1.0.0
		 * @return \WP_REST_Response The response object.
		 */
		public function get_recent_activities( \WP_REST_Request $request ) {
			$params           = $request->get_params();
			$sanitized_params = array(
				'page' => isset( $params['page'] ) ? absint( $params['page'] ) : 1,
			);

			$data = Log::get_recent_activities( $sanitized_params );

			return $this->send_response( $data, true, 200, __( 'Activities fetched successfully.', 'performance-optimisation' ) );
		}

		/**
		 * Resolve a client- or DB-supplied image path to an absolute source path.
		 *
		 * DB-backed pending values are already absolute; relative client
		 * values are resolved under `ABSPATH`. Uses the same
		 * `ltrim()` + allowlist construction as the validator so the
		 * enqueue/sync loops can never double-prefix an absolute value.
		 *
		 * @since 2.0.0
		 *
		 * @param string $img_path           Raw path from the request or DB queue.
		 * @param string $normalized_abspath Trailingslashed `ABSPATH`.
		 * @return string Absolute candidate source path.
		 */
		private function resolve_optimise_source_path( string $img_path, string $normalized_abspath ): string {
			$candidate = wp_normalize_path( $img_path );
			if ( method_exists( 'PerformanceOptimise\Inc\Img_Converter', 'is_path_in_allowlist' ) && Img_Converter::is_path_in_allowlist( $candidate ) ) {
				return $candidate;
			}
			return $normalized_abspath . ltrim( $candidate, '/' );
		}

		/**
		 * Optimizes the images and converts them to WebP or AVIF format.
		 *
		 * Uses Action Scheduler for background processing when available,
		 * falls back to synchronous processing otherwise.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @since 1.0.0
		 * @return \WP_REST_Response The response object.
		 */
		public function optimise_image( \WP_REST_Request $request ) {
			if ( $this->is_endpoint_throttled( 'optimise_image', 10, 60 ) ) {
				$response = $this->send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			// Defense-in-depth capability re-check on the conversion/delete
			// trigger path (the route permission_callback already requires
			// manage_options). Guarded so unit stubs without the pluggable
			// helper cannot fatal.
			if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
				return $this->send_response( null, false, 403, __( 'You are not allowed to optimize images.', 'performance-optimisation' ) );
			}

			$params = $request->get_params();

			// Nested-array input (e.g. webp[][x]=1) would throw a TypeError
			// inside sanitize_text_field() on PHP 8 — keep scalars only and
			// reject anything else with the 400 invalid-path response below.
			$raw_webp = isset( $params['webp'] ) ? (array) $params['webp'] : array();
			$raw_avif = isset( $params['avif'] ) ? (array) $params['avif'] : array();
			foreach ( array_merge( $raw_webp, $raw_avif ) as $entry ) {
				if ( ! is_string( $entry ) && ! is_int( $entry ) ) {
					return $this->send_response( null, false, 400, __( 'Invalid image path provided.', 'performance-optimisation' ) );
				}
			}
			$webp_images = array_map(
				static function ( $v ) {
					return sanitize_text_field( (string) $v );
				},
				$raw_webp
			);
			$avif_images = array_map(
				static function ( $v ) {
					return sanitize_text_field( (string) $v );
				},
				$raw_avif
			);

			// If no paths sent from client, fall back to reading pending paths from DB.
			if ( empty( $webp_images ) && empty( $avif_images ) ) {
				$img_info    = Img_Converter::get_img_info();
				$webp_images = isset( $img_info['pending'] ) ? ( $img_info['pending']['webp'] ?? array() ) : array();
				$avif_images = isset( $img_info['pending'] ) ? ( $img_info['pending']['avif'] ?? array() ) : array();

				if ( empty( $webp_images ) && empty( $avif_images ) ) {
					return $this->send_response(
						array(
							'background'  => false,
							'jobs_queued' => 0,
							'message'     => __( 'No pending images to optimize.', 'performance-optimisation' ),
						),
						true,
						200,
						__( 'No pending images to optimize.', 'performance-optimisation' )
					);
				}
			}

			// Validate image paths against the uploads/wppo allowlist to
			// prevent directory traversal. Relative client paths are resolved
			// under ABSPATH, then containment is asserted via
			// Img_Converter::is_path_in_allowlist() (guarded) plus a realpath
			// check when the file exists. Fail-open: invalid paths are
			// rejected with the file intact, never converted.
			$normalized_abspath = trailingslashit( wp_normalize_path( ABSPATH ) );
			foreach ( array_merge( $webp_images, $avif_images ) as $img_path ) {
				if ( false !== strpos( $img_path, "\0" ) ) {
					return $this->send_response( null, false, 400, __( 'Invalid image path provided.', 'performance-optimisation' ) );
				}
				// Segment-only traversal check: `..` as a full path segment
				// (after URL-decoding), so `my..photo.jpg` stays valid.
				$decoded_segments = str_replace( '\\', '/', rawurldecode( $img_path ) );
				if ( 1 === preg_match( '#(^|/)\.\.(/|$)#', $decoded_segments ) ) {
					return $this->send_response( null, false, 400, __( 'Invalid image path provided.', 'performance-optimisation' ) );
				}
				$source_path = $this->resolve_optimise_source_path( $img_path, $normalized_abspath );
				if ( method_exists( 'PerformanceOptimise\Inc\Img_Converter', 'is_path_in_allowlist' ) && ! Img_Converter::is_path_in_allowlist( $source_path ) ) {
					return $this->send_response( null, false, 400, __( 'Invalid image path provided.', 'performance-optimisation' ) );
				}
				if ( ! file_exists( $source_path ) ) {
					continue;
				}
				$resolved = realpath( $source_path );
				if ( false === $resolved ) {
					return $this->send_response( null, false, 400, __( 'Invalid image path provided.', 'performance-optimisation' ) );
				}
				$resolved_norm = wp_normalize_path( $resolved );
				$content_base  = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
				$root_ok       = 0 === strpos( $resolved_norm, $normalized_abspath ) || 0 === strpos( $resolved_norm, $content_base );
				if ( ! $root_ok ) {
					$abspath_real = realpath( ABSPATH );
					if ( false !== $abspath_real ) {
						$root_ok = 0 === strpos( $resolved_norm, trailingslashit( wp_normalize_path( $abspath_real ) ) );
					}
				}
				if ( ! $root_ok ) {
					$content_real = realpath( WP_CONTENT_DIR );
					if ( false !== $content_real ) {
						$root_ok = 0 === strpos( $resolved_norm, trailingslashit( wp_normalize_path( $content_real ) ) );
					}
				}
				if ( ! $root_ok ) {
					return $this->send_response( null, false, 400, __( 'Invalid image path provided.', 'performance-optimisation' ) );
				}
			}

			// Cap the merged client-supplied list so one request cannot
			// enqueue unbounded jobs (100 per request; remainder left pending).
			$jobs_cap    = (int) apply_filters( 'wppo_optimise_image_cap', 100 );
			$jobs_cap    = $jobs_cap > 0 ? $jobs_cap : 100;
			$jobs_capped = ( count( $webp_images ) + count( $avif_images ) ) > $jobs_cap;
			if ( count( $webp_images ) > $jobs_cap ) {
				$webp_images = array_slice( $webp_images, 0, $jobs_cap );
			}
			$remaining = $jobs_cap - count( $webp_images );
			if ( count( $avif_images ) > $remaining ) {
				$avif_images = array_slice( $avif_images, 0, max( 0, $remaining ) );
			}

			$use_action_scheduler = function_exists( 'as_enqueue_async_action' );
			$jobs_queued          = 0;

			if ( $use_action_scheduler ) {
				// Paginated scheduler snapshot for dedup instead of one
				// as_has_scheduled_action() query per image (N+1). Falls back
				// to per-item checks only when the snapshot is incomplete
				// (unavailable/failed/page-cap hit); a complete snapshot is
				// trusted (audit #1338). The snapshot is best-effort: a
				// concurrent process enqueueing between snapshot and enqueue
				// can still duplicate — the per-item fallback narrows that
				// window only when the snapshot is known-incomplete.
				$scheduled         = array();
				$snapshot_complete = false;
				// Needed dedup keys (bounded by $jobs_cap): stop paginating as
				// soon as every needed key is covered instead of fetching up
				// to 10k rows.
				$needed = array();
				foreach ( $webp_images as $webp_image ) {
					$needed[ $this->resolve_optimise_source_path( $webp_image, $normalized_abspath ) . '|webp' ] = true;
				}
				foreach ( $avif_images as $avif_image ) {
					$needed[ $this->resolve_optimise_source_path( $avif_image, $normalized_abspath ) . '|avif' ] = true;
				}
				if ( function_exists( 'as_get_scheduled_actions' ) ) {
					try {
						$statuses = array( 'pending', 'in-progress' );
						if ( class_exists( 'ActionScheduler_Store' ) ) {
							$statuses = array(
								\ActionScheduler_Store::STATUS_PENDING,
								\ActionScheduler_Store::STATUS_RUNNING,
							);
						}
						// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- bounded pagination loop.
						for ( $as_page = 0; $as_page < 10; $as_page++ ) {
							$query            = array(
								'hook'     => 'wppo_convert_image_background',
								'group'    => 'performance_optimisation',
								'status'   => $statuses,
								'per_page' => 1000,
								'offset'   => $as_page * 1000,
							);
							$existing_actions = as_get_scheduled_actions( $query, 'ARRAY_A' );
							// Store failure is NOT complete: fall back to per-item checks
							// rather than trusting an empty map (avoids double-queueing).
							if ( ! is_array( $existing_actions ) ) {
								break;
							}
							if ( empty( $existing_actions ) ) {
								$snapshot_complete = true;
								break;
							}
							foreach ( $existing_actions as $action ) {
								if ( ! is_array( $action ) ) {
									continue;
								}
								$action_args = $action['args'] ?? null;
								if ( is_string( $action_args ) ) {
									$decoded     = json_decode( $action_args, true );
									$action_args = is_array( $decoded ) ? $decoded : null;
								}
								if ( is_array( $action_args ) && isset( $action_args[0]['source_path'], $action_args[0]['format'] ) ) {
									$scheduled[ $action_args[0]['source_path'] . '|' . $action_args[0]['format'] ] = true;
									// All needed keys covered: stop paginating early.
									if ( ! empty( $needed ) && ! array_diff_key( $needed, $scheduled ) ) {
										$snapshot_complete = true;
										break 2;
									}
								}
							}
							// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- bounded pagination loop.
							if ( count( $existing_actions ) < 1000 ) {
								$snapshot_complete = true;
								break;
							}
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						$scheduled = array();
					}
				}
				// Schedule background jobs via Action Scheduler with deduplication.
				foreach ( $webp_images as $webp_image ) {
					$source_path = $this->resolve_optimise_source_path( $webp_image, $normalized_abspath );

					if ( file_exists( $source_path ) ) {
						$args      = array(
							array(
								'source_path' => $source_path,
								'format'      => 'webp',
							),
						);
						$dedup_key = $source_path . '|webp';
						if ( isset( $scheduled[ $dedup_key ] ) ) {
							continue;
						}
						if ( ! $snapshot_complete && function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'wppo_convert_image_background', $args, 'performance_optimisation' ) ) {
							$scheduled[ $dedup_key ] = true;
							continue;
						}
						as_enqueue_async_action(
							'wppo_convert_image_background',
							$args,
							'performance_optimisation'
						);
						$scheduled[ $dedup_key ] = true;
						++$jobs_queued;
					}
				}

				foreach ( $avif_images as $avif_image ) {
					$source_path = $this->resolve_optimise_source_path( $avif_image, $normalized_abspath );

					if ( file_exists( $source_path ) ) {
						$args      = array(
							array(
								'source_path' => $source_path,
								'format'      => 'avif',
							),
						);
						$dedup_key = $source_path . '|avif';
						if ( isset( $scheduled[ $dedup_key ] ) ) {
							continue;
						}
						if ( ! $snapshot_complete && function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'wppo_convert_image_background', $args, 'performance_optimisation' ) ) {
							$scheduled[ $dedup_key ] = true;
							continue;
						}
						as_enqueue_async_action(
							'wppo_convert_image_background',
							$args,
							'performance_optimisation'
						);
						$scheduled[ $dedup_key ] = true;
						++$jobs_queued;
					}
				}

				Log::add(
					sprintf(
						/* translators: %d: Number of image jobs queued */
						__( 'Scheduled %d image optimization jobs for background processing.', 'performance-optimisation' ),
						$jobs_queued
					)
				);

				return $this->send_response(
					array(
						'background'  => true,
						'jobs_queued' => $jobs_queued,
						'jobs_capped' => $jobs_capped,
						'message'     => sprintf(
							/* translators: %d: Number of jobs */
							__( '%d images queued for background optimization.', 'performance-optimisation' ),
							$jobs_queued
						),
					)
				);
			}

			// Fallback: synchronous processing (Action Scheduler not available).
			$options       = Util::get_settings();
			$img_converter = new Img_Converter( $options );

			foreach ( $webp_images as $webp_image ) {
				$source_path = $this->resolve_optimise_source_path( $webp_image, $normalized_abspath );

				if ( file_exists( $source_path ) ) {
					$img_converter->convert_image( $source_path, 'webp' );
				}
			}

			foreach ( $avif_images as $avif_image ) {
				$source_path = $this->resolve_optimise_source_path( $avif_image, $normalized_abspath );

				if ( file_exists( $source_path ) ) {
					$img_converter->convert_image( $source_path, 'avif' );
				}
			}

			Cache::clear_cache();

			$response  = Img_Converter::get_img_info();
			$sanitized = array();
			foreach ( array( 'pending', 'completed', 'failed' ) as $bucket ) {
				$bucket_data          = $response[ $bucket ] ?? array();
				$sanitized[ $bucket ] = array(
					'webp' => is_array( $bucket_data['webp'] ?? null ) ? count( $bucket_data['webp'] ) : ( $bucket_data['webp'] ?? 0 ),
					'avif' => is_array( $bucket_data['avif'] ?? null ) ? count( $bucket_data['avif'] ) : ( $bucket_data['avif'] ?? 0 ),
				);
			}

			return $this->send_response( $sanitized, true, 200, __( 'Images optimized successfully.', 'performance-optimisation' ) );
		}

		/**
		 * Deletes the optimized images from the filesystem.
		 *
		 * @since 1.0.0
		 * @return \WP_REST_Response The response object.
		 */
		public function delete_optimised_image(): \WP_REST_Response {
			if ( $this->is_endpoint_throttled( 'delete_optimised_image', 5, 60 ) ) {
				$response = $this->send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			// Defense-in-depth capability re-check on the delete trigger
			// (the route permission_callback already requires
			// manage_options). Guarded so unit stubs cannot fatal.
			if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
				return $this->send_response( null, false, 403, __( 'You are not allowed to delete optimized images.', 'performance-optimisation' ) );
			}

			global $wp_filesystem;
			if ( ! Util::init_filesystem() ) {
				return $this->send_response( null, false, 500, __( 'Unable to initialize filesystem.', 'performance-optimisation' ) );
			}

			$wppo_dir = wp_normalize_path( WP_CONTENT_DIR . '/wppo' );

			// Delete containment: prove the target is exactly the plugin's
			// `wppo` directory inside `WP_CONTENT_DIR` before unlinking, and
			// re-assert via realpath when it exists. Never unlink outside.
			$content_dir = rtrim( wp_normalize_path( WP_CONTENT_DIR ), '/' ) . '/';
			if ( rtrim( $wppo_dir, '/' ) . '/' !== $content_dir . 'wppo/' ) {
				return $this->send_response( null, false, 400, __( 'Invalid optimized images folder.', 'performance-optimisation' ) );
			}
			if ( file_exists( $wppo_dir ) ) {
				$resolved_wppo = realpath( $wppo_dir );
				if ( false === $resolved_wppo || rtrim( wp_normalize_path( $resolved_wppo ), '/' ) . '/' !== $content_dir . 'wppo/' ) {
					// Allow symlink edge-cases only when the resolved path
					// is still inside WP_CONTENT_DIR (fail-open otherwise).
					if ( false === $resolved_wppo || 0 !== strpos( rtrim( wp_normalize_path( $resolved_wppo ), '/' ) . '/', $content_dir ) ) {
						return $this->send_response( null, false, 400, __( 'Invalid optimized images folder.', 'performance-optimisation' ) );
					}
				}
			}

			if ( ! $wp_filesystem || ! $wp_filesystem->is_dir( $wppo_dir ) ) {
				return $this->send_response( null, false, 404, __( 'Optimized images folder does not exist.', 'performance-optimisation' ) );
			}

			if ( ! $wp_filesystem->delete( $wppo_dir, true ) ) {
				return $this->send_response( null, false, 500, __( 'Failed to delete the optimized images folder.', 'performance-optimisation' ) );
			}

			Img_Converter::clear_completed_formats();
			Cache::clear_cache();

			return $this->send_response( null, true, 200, __( 'Optimized images folder deleted successfully.', 'performance-optimisation' ) );
		}

		/**
		 * Imports settings via the REST API.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @since 1.0.0
		 * @return \WP_REST_Response The response object.
		 */
		public function import_settings( \WP_REST_Request $request ) {
			if ( $this->is_endpoint_throttled( 'import_settings', 10, 60 ) ) {
				$response = $this->send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			$data = $request->get_json_params();

			if ( ! is_array( $data ) ) {
				return $this->send_response( null, false, 400, __( 'Invalid payload.', 'performance-optimisation' ) );
			}

			if ( ! isset( $data['action'] ) || 'import_settings' !== $data['action'] ) {
				return $this->send_response( null, false, 400, __( 'Invalid action.', 'performance-optimisation' ) );
			}

			if ( empty( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
				return $this->send_response( null, false, 400, __( 'Settings are missing or invalid.', 'performance-optimisation' ) );
			}

			// Validate that only known top-level setting keys are present (single source: Util::ALLOWED_SETTINGS_KEYS).
			$allowed_keys = Util::ALLOWED_SETTINGS_KEYS;

			foreach ( array_keys( $data['settings'] ) as $key ) {
				if ( ! in_array( $key, $allowed_keys, true ) ) {
					return $this->send_response( null, false, 400, __( 'Invalid setting key detected.', 'performance-optimisation' ) );
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

			// Retrieve the existing settings and merge the imported settings on top,
			// so newer setting keys from future plugin versions are preserved.
			$existing_settings = Util::get_settings();
			$merged_settings   = array_replace_recursive( $existing_settings, $sanitized_settings );

			// Check if the settings are the same.
			if ( $existing_settings === $merged_settings ) {
				$response_settings = $existing_settings;
				$this->remove_sensitive_settings_from_response( $response_settings );
				return $this->send_response( $response_settings, true, 200, __( 'No changes detected, settings are already up-to-date', 'performance-optimisation' ) );
			}

			// One-click undo (issue #1144): snapshot the prior settings before
			// overwriting. Fail-open: a snapshot failure must never block the save.
			try {
				Util::take_settings_snapshot( $existing_settings );
			} catch ( \Throwable $snapshot_error ) {
				unset( $snapshot_error );
			}

			if ( ! update_option( 'wppo_settings', $merged_settings, false ) ) {
				return $this->send_response( null, false, 500, __( 'Failed to update settings', 'performance-optimisation' ) );
			}

			if ( class_exists( 'PerformanceOptimise\Inc\Telemetry' ) ) {
				Telemetry::invalidate_audit_cache();
			}

			$response_settings = $merged_settings;
			$this->remove_sensitive_settings_from_response( $response_settings );

			return $this->send_response( $response_settings, true, 200, __( 'Settings updated successfully', 'performance-optimisation' ) );
		}

		/**
		 * Report whether a prior-settings snapshot exists for one-click undo.
		 *
		 * Read-only: exposes only the snapshot availability + timestamp, never
		 * the snapshot payload itself.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @return \WP_REST_Response The response object.
		 * @since NEXT
		 */
		public function get_settings_snapshot( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature must match the REST callback.
			$snapshot = Util::get_settings_snapshot();

			if ( ! is_array( $snapshot ) ) {
				return $this->send_response(
					array(
						'has_snapshot' => false,
						'taken_at'     => null,
					)
				);
			}

			return $this->send_response(
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
		 * @since NEXT
		 */
		public function restore_settings( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature must match the REST callback.
			if ( $this->is_endpoint_throttled( 'restore_settings', 5, 60 ) ) {
				$response = $this->send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			$restored = Util::restore_settings_snapshot();

			if ( ! is_array( $restored ) ) {
				return $this->send_response( null, false, 404, __( 'No settings snapshot available to restore.', 'performance-optimisation' ) );
			}

			if ( class_exists( 'PerformanceOptimise\Inc\Telemetry' ) ) {
				Telemetry::invalidate_audit_cache();
			}

			$response_settings = $restored;
			$this->remove_sensitive_settings_from_response( $response_settings );

			return $this->send_response( $response_settings, true, 200, __( 'Settings restored successfully.', 'performance-optimisation' ) );
		}

		/**
		 * Perform database cleanup for the requested cleanup type.
		 *
		 * Accepts a request param `type` (one of: `revisions`, `auto_drafts`, `trashed_posts`,
		 * `spam_comments`, `trashed_comments`, `expired_transients`, `orphan_postmeta`, `all`)
		 * and executes the corresponding cleanup operation.
		 *
		 * @param \WP_REST_Request $request REST request containing the `type` parameter.
		 * @return \WP_REST_Response On success:
		 *                           - For `all`: response with `results` (per-cleanup results) and `deleted` (total deleted).
		 *                           - For specific types: response with `type` and `deleted` (number deleted).
		 *                           On invalid `type`: 400 response with an error message.
		 *                           On partial or total failure when `type` is `all`: 500 response with `failures` and `deleted`.
		 *                           On failure of a specific cleanup method: 500 response with the error message.
		 *
		 * @since 1.4.0
		 */
		public function database_cleanup( \WP_REST_Request $request ) {
			if ( $this->is_endpoint_throttled( 'database_cleanup', 5, 60 ) ) {
				$response = $this->send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			$params = $request->get_params();
			$type   = isset( $params['type'] ) ? sanitize_text_field( $params['type'] ) : '';

			$valid_types = Database_Cleanup::get_valid_cleanup_types();

			if ( ! in_array( $type, $valid_types, true ) ) {
				return $this->send_response( null, false, 400, __( 'Invalid cleanup type.', 'performance-optimisation' ) );
			}

			if ( 'all' === $type ) {
				$results  = Database_Cleanup::clean_all();
				$total    = 0;
				$failures = array();

				foreach ( $results as $key => $value ) {
					if ( is_wp_error( $value ) ) {
						$failures[ $key ] = sprintf(
							/* translators: %s: Cleanup type */
							__( 'Failed to clean %s.', 'performance-optimisation' ),
							$key
						);
					} else {
						$total += (int) $value;
					}
				}

				Log::add(
					sprintf(
						/* translators: %d: Number of items cleaned */
						__( 'Database cleanup (all): %d items removed', 'performance-optimisation' ),
						$total
					)
				);

				if ( ! empty( $failures ) ) {
					return $this->send_response(
						array(
							'failures' => $failures,
							'deleted'  => $total,
						),
						false,
						500,
						__( 'Partial or total failure during database cleanup.', 'performance-optimisation' )
					);
				}

				return $this->send_response(
					array(
						'results' => $results,
						'deleted' => $total,
					)
				);
			}

			// Standalone Action Scheduler branch: delegates to the AS cleaner,
			// never a raw DELETE (issue #1106). Kept out of CLEANUP_METHOD_MAP.
			// clean_action_scheduler() returns int only (fail-open 0), so there
			// is no false/WP_Error branch here; availability is surfaced
			// explicitly so a 0 does not read as a silent success.
			$as_available = null;
			if ( Database_Cleanup::ACTION_SCHEDULER_TYPE === $type ) {
				$result       = Database_Cleanup::clean_action_scheduler();
				$as_available = Database_Cleanup::is_action_scheduler_available();
			} else {
				$method_map = Database_Cleanup::CLEANUP_METHOD_MAP;

				$method = $method_map[ $type ] ?? null;

				if ( ! $method ) {
					return $this->send_response( array( 'deleted' => false ), false, 400, __( 'Invalid cleanup type.', 'performance-optimisation' ) );
				}

				if ( 'revisions' === $type ) {
					list( $max_age, $keep_latest ) = Database_Cleanup::get_revision_defaults();
					$result                        = Database_Cleanup::invoke_cleanup_method( $method, $max_age, $keep_latest );
				} else {
					$result = Database_Cleanup::invoke_cleanup_method( $method );
				}
			}

			if ( is_wp_error( $result ) ) {
				return $this->send_response( null, false, 500, __( 'Database cleanup failed.', 'performance-optimisation' ) );
			}

			// Skip the success log line when the AS cleaner ran with nothing
			// available to purge (AS absent or opted out via
			// `wppo_action_scheduler_cleanup_enabled`) — logging
			// "0 items removed" there reads as a completed cleanup.
			$is_as_unavailable = Database_Cleanup::ACTION_SCHEDULER_TYPE === $type && false === $as_available;
			if ( ! $is_as_unavailable ) {
				Log::add(
					sprintf(
					/* translators: %1$s: Cleanup type, %2$d: Number of items */
						__( 'Database cleanup (%1$s): %2$d items removed', 'performance-optimisation' ),
						$type,
						(int) $result
					)
				);
			}

			// Optimize affected tables after successful individual cleanup.
			if ( (int) $result > 0 && isset( Database_Cleanup::TABLE_MAP[ $type ] ) ) {
				Database_Cleanup::maybe_optimize_tables(
					Database_Cleanup::TABLE_MAP[ $type ],
					true
				);
			}

			$response = array(
				'type'    => $type,
				'deleted' => (int) $result,
			);
			if ( Database_Cleanup::ACTION_SCHEDULER_TYPE === $type ) {
				$response['action_scheduler_available'] = (bool) $as_available;
				if ( ! $as_available ) {
					$response['note'] = __( 'Action Scheduler is not available or cleanup is disabled; nothing was purged.', 'performance-optimisation' );
				}
			}

			return $this->send_response( $response );
		}

		/**
		 * Returns counts for all database cleanup types.
		 *
		 * @param \WP_REST_Request $_request The request object.
		 * @since 1.1.0
		 * @return \WP_REST_Response The response object.
		 */
		public function get_database_cleanup_counts( \WP_REST_Request $_request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			$counts = Database_Cleanup::get_counts();
			// get_action_scheduler_health() is fail-open internally (returns the
			// unavailable shape instead of throwing), so its result is reused
			// directly — no duplicated empty-health literal here.
			$counts['action_scheduler_health'] = Database_Cleanup::get_action_scheduler_health();
			return $this->send_response( $counts );
		}

		/**
		 * Returns the status of background image optimization jobs.
		 *
		 * @param \WP_REST_Request $_request The request object.
		 * @since 1.1.0
		 * @return \WP_REST_Response The response object.
		 */
		public function get_image_job_status( \WP_REST_Request $_request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			// Cache the payload briefly so SPA polling does not hammer the
			// Action Scheduler store tables on every request.
			$status_key = Util::transient_key( 'wppo_image_job_status' );
			$cached     = get_transient( $status_key );
			if ( is_array( $cached ) ) {
				return $this->send_response( $cached );
			}
			$img_info = Img_Converter::get_img_info();

			$status = array(
				'pending'   => array(
					'webp' => count( $img_info['pending']['webp'] ?? array() ),
					'avif' => count( $img_info['pending']['avif'] ?? array() ),
				),
				'completed' => array(
					'webp' => count( $img_info['completed']['webp'] ?? array() ),
					'avif' => count( $img_info['completed']['avif'] ?? array() ),
				),
				'failed'    => array(
					'webp' => count( $img_info['failed']['webp'] ?? array() ),
					'avif' => count( $img_info['failed']['avif'] ?? array() ),
				),
			);

			// Check if Action Scheduler is active and get job counts.
			// Bound the query so SPA polling does not recount the full set.
			// The count is a capped sample: queues deeper than the cap are
			// reported as '100+' via queued_jobs_capped.
			if ( function_exists( 'as_get_scheduled_actions' ) ) {
				$pending_status = class_exists( 'ActionScheduler_Store' ) ? \ActionScheduler_Store::STATUS_PENDING : 'pending';
				$pending_jobs   = as_get_scheduled_actions(
					array(
						'hook'     => 'wppo_convert_image_background',
						'status'   => $pending_status,
						'group'    => 'performance_optimisation',
						'per_page' => 100,
					),
					'ARRAY_A'
				);

				$queued_count                  = is_array( $pending_jobs ) ? count( $pending_jobs ) : 0;
				$status['queued_jobs']         = $queued_count;
				$status['queued_jobs_capped']  = $queued_count >= 100;
				$status['queued_jobs_display'] = $queued_count >= 100 ? '100+' : (string) $queued_count;
			} else {
				$status['queued_jobs']         = 0;
				$status['queued_jobs_capped']  = false;
				$status['queued_jobs_display'] = '0';
			}

			// Aggregate original-vs-optimised sizes for the dashboard report.
			// Derived from the single $img_info read above (no second unserialize).
			$status['savings'] = Img_Converter::get_savings_summary( $img_info );

			set_transient( $status_key, $status, 30 );

			return $this->send_response( $status );
		}

		/**
		 * Handles object cache requests (status, ping, enable, disable, flush).
		 *
		 * Admin REST calls that include a Redis password should always run over
		 * HTTPS, as the password transits the request body. Any request-supplied
		 * password is used at connection time only and is never persisted: it is
		 * stripped from the generated drop-in config file (see
		 * Object_Cache::enable()), and update_settings stores only a boolean
		 * `password_set` flag. When the WPPO_REDIS_PASSWORD constant is defined
		 * it takes precedence over request-supplied passwords unless the
		 * `wppo_redis_allow_request_password` filter returns true.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @since 1.4.0
		 * @since NEXT Flush action uses Object_Cache::flush_scoped() for multisite scoping.
		 * @return \WP_REST_Response The response object.
		 */
		public function handle_object_cache( \WP_REST_Request $request ) {
			if ( $this->is_endpoint_throttled( 'object_cache', 5, 60 ) ) {
				$response = $this->send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			$params = $request->get_params();
			$action = isset( $params['action'] ) ? sanitize_text_field( $params['action'] ) : '';

			$manager = new Object_Cache();

			if ( 'status' === $action ) {
				$status                          = $manager->get_status();
				$status['supported_compressors'] = array(
					'lzf'  => defined( '\Redis::COMPRESSION_LZF' ),
					'lz4'  => defined( '\Redis::COMPRESSION_LZ4' ),
					'zstd' => defined( '\Redis::COMPRESSION_ZSTD' ),
				);
				if ( ! isset( $status['serializers'] ) ) {
					$status['serializers'] = $manager->get_serializer_support();
				}
				return $this->send_response( $status );
			}

			if ( 'ping' === $action ) {
				$config = $this->build_redis_config( $params );
				$ping   = $manager->ping( $config );
				if ( is_wp_error( $ping ) ) {
					// No Log::add here: Object_Cache::ping() already records
					// the failure in-app via log_redis_failure().
					return $this->send_response( $this->redis_error_payload( $ping, 'error' ), false, 400, __( 'Redis connection failed.', 'performance-optimisation' ) );
				}

				return $this->send_response( array( 'success' => true ) );
			}

			if ( 'enable' === $action ) {
				$config = $this->build_redis_config( $params );
				$result = $manager->enable( $config );

				if ( is_wp_error( $result ) ) {
					// No Log::add here: enable() → ping() already logged it.
					return $this->send_response( $this->redis_error_payload( $result, 'error' ), false, 400, __( 'Redis connection failed.', 'performance-optimisation' ) );
				}

				Log::add( __( 'Object Cache enabled.', 'performance-optimisation' ) );
				return $this->send_response( true, true, 200, __( 'Object Cache enabled successfully.', 'performance-optimisation' ) );
			}

			if ( 'disable' === $action ) {
				$result = $manager->disable();

				if ( is_wp_error( $result ) ) {
					Log::add( __( 'Redis connection disable failed.', 'performance-optimisation' ) );
					return $this->send_response( null, false, 400, __( 'Failed to disable object cache.', 'performance-optimisation' ) );
				}

				Log::add( __( 'Object Cache disabled.', 'performance-optimisation' ) );
				return $this->send_response( true, true, 200, __( 'Object Cache disabled.', 'performance-optimisation' ) );
			}

			if ( 'recover' === $action || 're-enable' === $action ) {
				// Manual circuit-breaker recovery: reuse the stored Redis
				// settings as defaults (request params only override keys
				// they explicitly carry) and restore via the enable() path,
				// which pings first and clears all circuit state on success.
				$stored = $manager->get_redis_config();
				$config = $this->build_redis_config( $params, $stored );
				$result = $manager->enable( $config );

				if ( is_wp_error( $result ) ) {
					// No Log::add here: enable() → ping() already logged it.
					return $this->send_response( $this->redis_error_payload( $result, 'warning' ), false, 400, __( 'Redis is still unreachable. The circuit breaker stays open.', 'performance-optimisation' ) );
				}

				$manager->clear_circuit_state();

				if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
					wp_clear_scheduled_hook( 'wppo_object_cache_probe' );
				}

				Log::add( __( 'Object Cache circuit breaker recovered — drop-in re-enabled manually.', 'performance-optimisation' ) );
				return $this->send_response( true, true, 200, __( 'Object Cache recovered successfully.', 'performance-optimisation' ) );
			}

			if ( 'flush' === $action ) {
				// Scoped flush on multisite (issue #1186): never flush
				// sibling sites.
				$result = $manager->flush_scoped();
				if ( $result ) {
					Log::add( __( 'Object Cache flushed.', 'performance-optimisation' ) );
					return $this->send_response( true, true, 200, __( 'Object Cache flushed.', 'performance-optimisation' ) );
				}
				// No Log::add here: Object_Cache::flush()/flush_scoped()
				// already recorded the failure in-app. Forward the
				// manager's real error so the SPA notice keeps its specificity.
				$flush_error = $manager->get_last_flush_error();
				if ( ! ( $flush_error instanceof \WP_Error ) ) {
					$flush_error = new \WP_Error( 'flush_fail', __( 'Flush reported failure.', 'performance-optimisation' ) );
				} else {
					$flush_error = $this->sanitize_flush_error( $flush_error );
				}
				return $this->send_response( $this->redis_error_payload( $flush_error, 'error' ), false, 400, __( 'Failed to flush object cache.', 'performance-optimisation' ) );
			}

			return $this->send_response( null, false, 400, __( 'Invalid action.', 'performance-optimisation' ) );
		}

		/**
		 * Builds a sanitized Redis configuration array from request parameters.
		 *
		 * When the WPPO_REDIS_PASSWORD constant is defined it takes precedence:
		 * request-supplied passwords are dropped unless the
		 * `wppo_redis_allow_request_password` filter returns true. Admin REST
		 * calls carrying a password should run over HTTPS; the password is used
		 * at connection time only and is never persisted (it is stripped from
		 * the generated drop-in config file and from stored settings).
		 *
		 * @param array $params Request parameters.
		 * @param array $defaults Optional stored config (e.g. from Object_Cache::get_redis_config())
		 *                        filling keys the request does not explicitly carry. Defaults to array()
		 *                        (historic behaviour: hardcoded host/port/mode fallbacks).
		 * @since 1.4.0
		 * @since 2.0.0 Request-supplied passwords are ignored when WPPO_REDIS_PASSWORD is defined, unless the `wppo_redis_allow_request_password` filter returns true.
		 * @since 2.0.0 Added the $defaults parameter for circuit-breaker recovery.
		 * @return array Sanitized Redis config.
		 */
		private function build_redis_config( $params, $defaults = array() ) {
			// Single source of truth: Object_Cache::ALLOWED_KEYS (local
			// fallback only when the class is unavailable, e.g. unit stubs).
			$allowed_keys = class_exists( 'PerformanceOptimise\Inc\Object_Cache' ) ? Object_Cache::ALLOWED_KEYS : array( 'mode', 'host', 'port', 'password', 'database', 'timeout', 'prefix', 'nodes', 'master_name', 'use_tls', 'persistent', 'compression' );
			$config       = array();

			foreach ( $allowed_keys as $key ) {
				if ( ! isset( $params[ $key ] ) ) {
					continue;
				}

				$config[ $key ] = $this->sanitize_redis_config_value( $key, $params[ $key ] );
			}

			// Stored-config fallback for keys the request omits (used by the
			// circuit-breaker recover action, which typically sends only the
			// mode). Merged values run through the same sanitizers (including
			// the WPPO_REDIS_PASSWORD precedence guard), so a recover can
			// never smuggle a raw stored password past the constant.
			// Explicit request keys always win.
			if ( is_array( $defaults ) && ! empty( $defaults ) ) {
				foreach ( $allowed_keys as $key ) {
					if ( ! array_key_exists( $key, $config ) && array_key_exists( $key, $defaults ) ) {
						$config[ $key ] = $this->sanitize_redis_config_value( $key, $defaults[ $key ] );
					}
				}
			}

			// Defaults for missing keys.
			$config['mode'] = $config['mode'] ?? 'standalone';
			$config['host'] = $config['host'] ?? '127.0.0.1';
			$config['port'] = $config['port'] ?? 6379;

			return $config;
		}

		/**
		 * Sanitize a single Redis configuration value by key.
		 *
		 * Single sanitization contract shared by request-supplied values and
		 * stored-config fallbacks in build_redis_config(), so both paths
		 * enforce the same types and the WPPO_REDIS_PASSWORD precedence guard.
		 *
		 * @param string $key Config key (one of the build_redis_config() allowlist).
		 * @param mixed  $value Raw value.
		 * @since 2.0.0
		 * @return mixed Sanitized value.
		 */
		private function sanitize_redis_config_value( $key, $value ) {
			switch ( $key ) {
				case 'host':
				case 'master_name':
					$host = sanitize_text_field( (string) $value );
					$host = strtolower( trim( $host ) );
					// Host allowlist: hostname/IP/socket path — no URL schemes or userinfo.
					if ( '' !== $host && 1 !== preg_match( '/^(?:[a-z0-9](?:[a-z0-9\-\.]{0,251}[a-z0-9])?|\/[\w\/\.\-]+)$/', $host ) ) {
						return '';
					}
					return substr( $host, 0, 255 );
				case 'compression':
					$compression = sanitize_text_field( (string) $value );
					return in_array( $compression, array( '', 'none', 'lz4', 'zstd' ), true ) ? $compression : '';
				case 'mode':
					$mode = sanitize_text_field( (string) $value );
					return in_array( $mode, array( 'standalone', 'sentinel', 'cluster' ), true ) ? $mode : 'standalone';
				case 'port':
					return max( 1, min( 65535, (int) $value ) );
				case 'database':
					return max( 0, min( 15, (int) $value ) );
				case 'timeout':
					// Clamp to a sane float range: an array/object here
					// would otherwise flow raw into connect calls and
					// var_export'ed config (type confusion downstream).
					if ( is_array( $value ) || is_object( $value ) ) {
						return 1.0;
					}
					return max( 0.1, min( 30.0, (float) $value ) );
				case 'prefix':
					// Redis key prefix: charset + length sanitized so it
					// cannot smuggle whitespace/control sequences into keys
					// or the var_export'ed drop-in config.
					if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
						return '';
					}
					$prefix = sanitize_text_field( (string) $value );
					$prefix = (string) preg_replace( '/[^A-Za-z0-9_\-:]/', '', $prefix );
					return substr( $prefix, 0, 64 );
				case 'password':
					// When WPPO_REDIS_PASSWORD is defined the constant takes
					// precedence: supplied passwords are dropped unless
					// the wppo_redis_allow_request_password escape hatch returns true.
					if ( defined( 'WPPO_REDIS_PASSWORD' ) && ! apply_filters( 'wppo_redis_allow_request_password', false ) ) {
						return '';
					}
					// Audit #1453: no tag-stripping on passwords (mangles strong
					// secrets) — string cast + length clamp only. Never
					// persisted (unset/flag-only downstream); HTTPS-only for
					// admin REST calls carrying a password.
					if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
						return '';
					}
					return substr( (string) $value, 0, 512 );
				case 'use_tls':
				case 'persistent':
					return (bool) $value;
				case 'nodes':
					return $this->sanitize_nodes( $value );
				default:
					return $value;
			}
		}

		/**
		 * Normalize and sanitize Redis node entries into an indexed array of non-empty strings.
		 *
		 * When given an array, each element is sanitized, empty values are removed, and the result is reindexed.
		 * When given a scalar, it is cast to string, sanitized, and returned as a single-element array if non-empty.
		 *
		 * @param string|array $nodes Node or list of nodes to sanitize and normalize.
		 * @since 1.4.0
		 * @return string[] An indexed array of sanitized, non-empty node strings.
		 */
		private function sanitize_nodes( $nodes ) {
			$sanitize_node = static function ( $node ) {
				if ( ! is_string( $node ) && ! is_numeric( $node ) ) {
					return '';
				}
				$candidate = strtolower( trim( sanitize_text_field( (string) $node ) ) );
				if ( '' === $candidate ) {
					return '';
				}
				// Strip URL schemes and userinfo so entries like
				// http://169.254.169.254:6379 or user:pass@host cannot pass
				// to ping()/enable() connection attempts (SSRF/port-scan
				// inconsistency vs the host/master_name allowlist).
				if ( false !== strpos( $candidate, '://' ) ) {
					$parts     = explode( '://', $candidate, 2 );
					$candidate = $parts[1] ?? '';
				}
				if ( false !== strpos( $candidate, '@' ) ) {
					$parts     = explode( '@', $candidate );
					$candidate = end( $parts );
				}
				$candidate = trim( $candidate, '/' );
				// Same host allowlist as host/master_name: hostname/IP/socket
				// path with optional :port — no schemes or userinfo.
				if ( 1 === preg_match( '/^(?:[a-z0-9](?:[a-z0-9\-\.]{0,251}[a-z0-9])?|\/[\w\/\.\-]+)(?::([0-9]{1,5}))?$/', $candidate, $m ) ) {
					if ( isset( $m[1] ) && '' !== $m[1] ) {
						$port = (int) $m[1];
						if ( $port < 1 || $port > 65535 ) {
							return '';
						}
					}
					return substr( $candidate, 0, 255 );
				}
				return '';
			};
			if ( is_array( $nodes ) ) {
				return array_values( array_filter( array_map( $sanitize_node, $nodes ) ) );
			}
			$single = $sanitize_node( $nodes );
			return '' !== $single ? array( $single ) : array();
		}

		/**
		 * Strip local paths from a flush WP_Error before REST surfacing.
		 *
		 * Flush messages are safe today (generic strings, blog_id cast to
		 * int) but a future verbose Redis error could leak topology
		 * (absolute paths) via the message or the error data payload.
		 * Mirrors the ABSPATH/WP_CONTENT_DIR stripping in
		 * run_performance_scan(); verbose detail stays available under
		 * WP_DEBUG via get_status().
		 *
		 * @since NEXT
		 * @param \WP_Error $error Failing result.
		 * @return \WP_Error Sanitized clone (same code, scrubbed message and data).
		 */
		private function sanitize_flush_error( $error ): \WP_Error {
			$message = $error->get_error_message();
			if ( defined( 'ABSPATH' ) && is_string( ABSPATH ) && '' !== ABSPATH ) {
				$message = str_replace( array( ABSPATH, rtrim( ABSPATH, '/\\' ) ), '', $message );
			}
			if ( defined( 'WP_CONTENT_DIR' ) && is_string( WP_CONTENT_DIR ) && '' !== WP_CONTENT_DIR ) {
				$message = str_replace( array( WP_CONTENT_DIR, rtrim( WP_CONTENT_DIR, '/\\' ) ), '', $message );
			}
			$message = sanitize_text_field( $message );
			if ( '' === $message ) {
				$message = __( 'Flush reported failure.', 'performance-optimisation' );
			}
			return new \WP_Error( $error->get_error_code(), $message, $this->scrub_flush_error_data( $error->get_error_data() ) );
		}

		/**
		 * Recursively strip local paths from flush error data.
		 *
		 * Strings are scrubbed of ABSPATH/WP_CONTENT_DIR; arrays are
		 * scrubbed element-wise; scalars and null pass through untouched.
		 * Objects and resources are dropped (replaced with null) since a
		 * verbose backend could hide paths or topology inside them.
		 *
		 * @since NEXT
		 * @param mixed $data Raw error data.
		 * @return mixed Scrubbed error data.
		 */
		private function scrub_flush_error_data( $data ) {
			if ( is_string( $data ) ) {
				if ( defined( 'ABSPATH' ) && is_string( ABSPATH ) && '' !== ABSPATH ) {
					$data = str_replace( array( ABSPATH, rtrim( ABSPATH, '/\\' ) ), '', $data );
				}
				if ( defined( 'WP_CONTENT_DIR' ) && is_string( WP_CONTENT_DIR ) && '' !== WP_CONTENT_DIR ) {
					$data = str_replace( array( WP_CONTENT_DIR, rtrim( WP_CONTENT_DIR, '/\\' ) ), '', $data );
				}
				return $data;
			}
			if ( is_array( $data ) ) {
				$scrubbed = array();
				foreach ( $data as $key => $value ) {
					$scrubbed[ $key ] = $this->scrub_flush_error_data( $value );
				}
				return $scrubbed;
			}
			if ( is_scalar( $data ) || null === $data ) {
				return $data;
			}
			return null;
		}

		/**
		 * Build a useNotice-compatible error payload for Redis failures.
		 *
		 * The SPA reads `res.success` + `res.message`; this adds a structured
		 * `code` + `notice` ({ type, message }) body so useNotice() can render
		 * the failure with the right severity without changing the envelope.
		 *
		 * @since 2.0.0
		 * @param \WP_Error $error  Failing result.
		 * @param string    $notice Notice severity: 'error', 'warning', 'info'.
		 * @return array Shape { code: string, notice: array{ type: string, message: string } }.
		 */
		private function redis_error_payload( $error, string $notice = 'error' ): array {
			$allowed = array( 'error', 'warning', 'info' );
			if ( ! in_array( $notice, $allowed, true ) ) {
				$notice = 'error';
			}
			// Scrub backend topology/absolute paths like the flush path —
			// raw phpredis messages commonly embed host:port and file paths.
			$sanitized = $this->sanitize_flush_error( $error );
			$message   = $sanitized->get_error_message();
			if ( '' === $message ) {
				$message = __( 'Redis connection failed.', 'performance-optimisation' );
			}
			return array(
				'code'   => $error->get_error_code(),
				'notice' => array(
					'type'    => $notice,
					'message' => $message,
				),
			);
		}

		/**
		 * Refreshes the REST API nonce via AJAX to bypass stale X-WP-Nonce issues.
		 *
		 * Two-path nonce-refresh contract (audit #888 finding 2 — intentionally
		 * two consumers, one endpoint):
		 *
		 * 1. SPA bundle  (src/index.js → src/lib/apiRequest.js) — reads
		 *    `wppoSettings.nonce_refresh` and `{success, data.nonce}` from this
		 *    endpoint when a REST request returns a `rest_forbidden` /
		 *    `rest_cookie_invalid_nonce` body, then retries once with the fresh
		 *    X-WP-Nonce.
		 * 2. Admin-bar bundle (src/main.js — intentionally standalone, separate
		 *    `wppoObject` contract, see the sync comment at the top of that
		 *    file) — reads `wppoObject.nonce_refresh` and the same
		 *    `{success, data.nonce}` shape when an admin-bar fetch gets a 403.
		 *
		 * Both paths MUST keep the same auth checks (capability + this dedicated
		 * `wppo_nonce_refresh` nonce — not the `wp_rest` nonce, which is the very
		 * thing being refreshed) and the same `{success, data.nonce}` response
		 * shape produced by wp_send_json_success() below.
		 *
		 * @since 1.4.0
		 * @return void
		 */
		public function ajax_get_nonce() {
			if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'performance-optimisation' ) ), 403 );
			}

			if ( ! check_ajax_referer( 'wppo_nonce_refresh', 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Nonce verification failed.', 'performance-optimisation' ) ), 403 );
			}

			wp_send_json_success(
				array(
					'nonce' => wp_create_nonce( 'wp_rest' ),
				)
			);
		}

		/**
		 * Returns all system information groups (PHP, DB, WordPress, server, cache).
		 *
		 * @param \WP_REST_Request $_request The request object (unused).
		 * @since 1.5.0
		 * @return \WP_REST_Response The response object.
		 */
		public function get_system_info( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return $this->send_response( System_Info::get_all() );
		}

		/**
		 * Runs a local telemetry scan on the provided URL.
		 *
		 * Accepts a POST body with a 'url' parameter. Returns all 16 performance
		 * metric keys or a WP_Error on failure.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @since 1.5.0
		 * @return \WP_REST_Response The response object.
		 */
		public function run_performance_scan( \WP_REST_Request $request ): \WP_REST_Response {
			if ( $this->is_endpoint_throttled( 'performance_scan', 10, 60 ) ) {
				$response = $this->send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			$params = $request->get_params();
			// Audit #1362 review: present-but-non-string input is a 400
			// (not a silent home fallback) so malformed callers get an error.
			if ( isset( $params['url'] ) && ! is_string( $params['url'] ) ) {
				return $this->send_response( null, false, 400, __( 'Invalid URL parameter.', 'performance-optimisation' ) );
			}
			$url = isset( $params['url'] ) ? esc_url_raw( $params['url'] ) : Util::cached_home_url( '/' );

			if ( empty( $url ) ) {
				return $this->send_response( null, false, 400, __( 'A valid URL is required.', 'performance-optimisation' ) );
			}

			// SSRF protection: reject URLs that do not pass WordPress HTTP validation.
			// wp_http_validate_url() rejects loopback, private, and reserved addresses.
			if ( ! wp_http_validate_url( $url ) ) {
				return $this->send_response( null, false, 400, __( 'A valid, allowed URL is required.', 'performance-optimisation' ) );
			}

			// Only allow http and https schemes.
			$parsed_url = wp_parse_url( $url );
			$scheme     = $parsed_url['scheme'] ?? '';
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				return $this->send_response( null, false, 400, __( 'A valid, allowed URL is required.', 'performance-optimisation' ) );
			}

			// SSRF protection: validate that the URL belongs to this website.
			$home_host = wp_parse_url( Util::cached_home_url(), PHP_URL_HOST );
			if ( ( $parsed_url['host'] ?? '' ) !== $home_host ) {
				return $this->send_response( null, false, 400, __( 'You can only scan URLs belonging to this website.', 'performance-optimisation' ) );
			}

			$force  = isset( $params['force'] ) ? (bool) $params['force'] : false;
			$result = Telemetry::scan( $url, 'manual', $force );

			if ( is_wp_error( $result ) ) {
				// Strip local paths plus any embedded URLs (which may leak hostnames,
				// IPs, or credentials from Telemetry/wp_remote errors) before the
				// message is returned to the client and persisted via the activity log.
				$detail = str_replace( array( ABSPATH, WP_CONTENT_DIR ), '', $result->get_error_message() );
				$detail = preg_replace( '#https?://[^\s\'"]+#', '[url]', $detail );
				if ( ! is_string( $detail ) ) {
					$detail = '';
				}
				$detail = sanitize_text_field( $detail );
				if ( '' === $detail ) {
					$detail = __( 'Performance scan failed.', 'performance-optimisation' );
				}
				return $this->send_response( null, false, 500, $detail );
			}

			return $this->send_response( $result );
		}

		/**
		 * Queues a Google PageSpeed Insights scan as a background Action Scheduler job.
		 *
		 * Accepts POST body params: url (string), strategy ('mobile'|'desktop').
		 * Returns HTTP 202 with the queued job ID so the React UI can poll
		 * GET /pagespeed_results until the result is ready.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @since 1.6.0
		 * @return \WP_REST_Response The response object.
		 */
		public function queue_pagespeed_scan( \WP_REST_Request $request ): \WP_REST_Response {
			if ( $this->is_endpoint_throttled( 'pagespeed_scan', 10, 60 ) ) {
				$response = $this->send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			$params = $request->get_params();
			// Audit #1362 review: present-but-non-string input is a 400
			// (not a silent home fallback) so malformed callers get an error.
			if ( isset( $params['url'] ) && ! is_string( $params['url'] ) ) {
				return $this->send_response( null, false, 400, __( 'Invalid URL parameter.', 'performance-optimisation' ) );
			}
			$url      = isset( $params['url'] ) ? esc_url_raw( $params['url'] ) : Util::cached_home_url( '/' );
			$strategy = isset( $params['strategy'] ) ? sanitize_text_field( $params['strategy'] ) : 'mobile';

			if ( empty( $url ) ) {
				return $this->send_response( null, false, 400, __( 'A valid URL is required.', 'performance-optimisation' ) );
			}

			// Only allow http and https schemes.
			$parsed_url = wp_parse_url( $url );
			$scheme     = $parsed_url['scheme'] ?? '';
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				return $this->send_response( null, false, 400, __( 'A valid, allowed URL is required.', 'performance-optimisation' ) );
			}

			// Validate that the URL belongs to this website.
			$home_host = wp_parse_url( Util::cached_home_url(), PHP_URL_HOST );
			if ( ( $parsed_url['host'] ?? '' ) !== $home_host ) {
				return $this->send_response( null, false, 400, __( 'You can only scan URLs belonging to this website.', 'performance-optimisation' ) );
			}

			// Reject loopback/private addresses.
			if ( ! wp_http_validate_url( $url ) ) {
				return $this->send_response( null, false, 400, __( 'PageSpeed cannot scan local or non-public URLs.', 'performance-optimisation' ) );
			}

			// Validate strategy.
			if ( ! in_array( $strategy, array( 'mobile', 'desktop' ), true ) ) {
				$strategy = 'mobile';
			}

			if ( ! function_exists( 'as_enqueue_async_action' ) ) {
				return $this->send_response( null, false, 500, __( 'Action Scheduler is not available.', 'performance-optimisation' ) );
			}

			// Audit #1338: no REST-layer pre-check — queue_scan() resolves the
			// winner's ID internally (unique insert + re-query), so a separate
			// as_has_scheduled_action() here paid an extra scheduler read.
			// Contract note: already_queued stays in the shape but is always
			// false now — dedup happens inside queue_scan() and the REST layer
			// can no longer distinguish a fresh enqueue from a deduped winner
			// without re-introducing the query. Pollers must key off job_id
			// (always the actionable job) rather than already_queued.
			$job_id = Pagespeed::queue_scan( $url, $strategy );

			return $this->send_response(
				array(
					'job_id'         => $job_id,
					'url'            => $url,
					'strategy'       => $strategy,
					'already_queued' => false,
				),
				true,
				202
			);
		}

		/**
		 * Returns cached PageSpeed Insights results for a URL and strategy.
		 *
		 * Returns the prepared result array if the transient exists, or a
		 * { status: 'not_ready' } response with HTTP 202 if the background
		 * job has not yet completed.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @since 1.6.0
		 * @return \WP_REST_Response The response object.
		 */
		public function get_pagespeed_results( \WP_REST_Request $request ): \WP_REST_Response {
			$params = $request->get_params();
			// Audit #1362 review: present-but-non-string input is a 400
			// (not a silent home fallback) so malformed callers get an error.
			if ( isset( $params['url'] ) && ! is_string( $params['url'] ) ) {
				return $this->send_response( null, false, 400, __( 'Invalid URL parameter.', 'performance-optimisation' ) );
			}
			$url = isset( $params['url'] ) ? esc_url_raw( $params['url'] ) : Util::cached_home_url( '/' );
			if ( '' !== $url && ! $this->is_same_site_url( $url ) ) {
				return $this->send_response( null, false, 400, __( 'You can only query URLs belonging to this website.', 'performance-optimisation' ) );
			}
			$strategy = isset( $params['strategy'] ) ? sanitize_text_field( $params['strategy'] ) : 'mobile';

			if ( ! in_array( $strategy, array( 'mobile', 'desktop' ), true ) ) {
				$strategy = 'mobile';
			}

			$results = Pagespeed::get_results( $url, $strategy );

			if ( false === $results ) {
				$response = $this->send_response( array( 'status' => 'not_ready' ), true, 202 );

				// Backoff hint for the SPA poller and a no-store instruction
				// so intermediaries never cache the pending state (audit #888
				// finding 27).
				$response->header( 'Retry-After', '5' );
				$response->header( 'Cache-Control', 'no-store' );

				return $response;
			}

			// Detect failure sentinel stored by Pagespeed::store_failure().
			if ( ! empty( $results['error'] ) ) {
				return $this->send_response(
					null,
					false,
					500,
					__( 'PageSpeed scan failed. Please check your API key and try again.', 'performance-optimisation' )
				);
			}

			// Retroactively store LCP image URL from cached results (plugin upgrade path).
			// store_lcp_image_url() handles deduplication internally.
			if ( ! empty( $results['lcp_image_url'] ) ) {
				\PerformanceOptimise\Inc\Pagespeed::store_lcp_image_url( $url, $results, $strategy );
			}

			// Append Suggestion_Engine output so the React UI gets everything in one call.
			$results['suggestions'] = Suggestion_Engine::from_pagespeed( $results );

			return $this->send_response( $results );
		}

		/**
		 * Returns the stored Web Vitals trend history.
		 *
		 * Optionally filters by url and strategy via GET params. Returns the raw
		 * trend option data so the React Dashboard can render trend charts.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @since 2.0.0
		 * @return \WP_REST_Response The response object.
		 */
		public function get_web_vitals_trends( \WP_REST_Request $request ): \WP_REST_Response {
			$params = $request->get_params();
			$url    = ( isset( $params['url'] ) && is_string( $params['url'] ) ) ? esc_url_raw( $params['url'] ) : ''; // Audit #1362 review: non-string falls to '' (immaterial filter), never a fatal.
			if ( '' !== $url && ! $this->is_same_site_url( $url ) ) {
				return $this->send_response( null, false, 400, __( 'You can only query URLs belonging to this website.', 'performance-optimisation' ) );
			}
			$strategy = isset( $params['strategy'] ) ? sanitize_text_field( $params['strategy'] ) : '';

			if ( ! in_array( $strategy, array( 'mobile', 'desktop', '' ), true ) ) {
				$strategy = '';
			}

			$trends = Pagespeed::get_trends();

			// Filter when either url or strategy is provided.
			if ( ! empty( $url ) || ! empty( $strategy ) ) {
				$url_key = '' !== $url ? md5( esc_url_raw( $url ) ) : null;
				$trends  = array_filter(
					$trends,
					static function ( $k ) use ( $url_key, $strategy ) {
						if ( null !== $url_key && 0 !== strpos( $k, $url_key . '_' ) ) {
							return false;
						}
						if ( ! empty( $strategy ) && ! str_ends_with( $k, '_' . $strategy ) ) {
							return false;
						}
						return true;
					},
					ARRAY_FILTER_USE_KEY
				);
			}

			return $this->send_response(
				array(
					'trends' => $trends,
				)
			);
		}

		/**
		 * Returns Suggestion_Engine output for a given telemetry scan result.
		 *
		 * Accepts GET param: url (string). Retrieves the cached telemetry transient
		 * and runs it through Suggestion_Engine::from_telemetry().
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @since 1.6.0
		 * @return \WP_REST_Response The response object.
		 */
		public function get_suggestions( \WP_REST_Request $request ): \WP_REST_Response {
			$params = $request->get_params();
			// Audit #1362 review: present-but-non-string input is a 400
			// (not a silent home fallback) so malformed callers get an error.
			if ( isset( $params['url'] ) && ! is_string( $params['url'] ) ) {
				return $this->send_response( null, false, 400, __( 'Invalid URL parameter.', 'performance-optimisation' ) );
			}
			$url = isset( $params['url'] ) ? esc_url_raw( $params['url'] ) : Util::cached_home_url( '/' );
			if ( '' !== $url && ! $this->is_same_site_url( $url ) ) {
				return $this->send_response( null, false, 400, __( 'You can only query URLs belonging to this website.', 'performance-optimisation' ) );
			}

			$transient_key = Util::transient_key( 'wppo_audit_' . md5( $url ) );
			// Mirror Telemetry::scan() read path: the salted object-cache
			// layer is authoritative when a persistent cache exists.
			if ( function_exists( 'wp_cache_get_salted' ) && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
				$telemetry = wp_cache_get_salted( $transient_key, 'wppo', Util::cache_salt( 'wppo_audit_salt' ) );
				if ( false === $telemetry ) {
					$telemetry = get_transient( $transient_key );
				}
			} else {
				$telemetry = get_transient( $transient_key );
			}

			if ( false === $telemetry ) {
				return $this->send_response(
					array( 'suggestions' => array() ),
					true,
					200,
					__( 'No cached scan found for this URL. Run a scan first.', 'performance-optimisation' )
				);
			}

			$suggestions = Suggestion_Engine::from_telemetry( $telemetry );
			// Merge AI suggestions when the feature is enabled (never auto-applies).
			if ( class_exists( 'PerformanceOptimise\Inc\AI_Adaptive' ) && AI_Adaptive::is_enabled() ) {
				$ai = Suggestion_Engine::from_ai_adaptive();
				if ( ! empty( $ai ) ) {
					$suggestions = array_merge( $suggestions, $ai );
				}
			}

			return $this->send_response( array( 'suggestions' => $suggestions ) );
		}

		/**
		 * Verifiable WooCommerce cart/checkout cache-exclusion self-test (read-only).
		 *
		 * Returns Util::woo_cache_self_test(): detected Woo paths against the
		 * exclusion list, safe-mode toggle state, and per-URL pass/fail
		 * proving cart/checkout/account bypass the static HTML cache with
		 * DONOTCACHEPAGE honored, plus additive preload-skip probes (faceted
		 * filter URLs never enter the preload queue), guest-cart survival
		 * probes (cart/session cookies, wc-ajax, add-to-cart, Store API bypass
		 * with page and object cache on) and a fail-closed `force_exclude`
		 * recommendation (true when the verdict fails: force-exclude dynamic
		 * routes plus cookie bypass and serve dynamic). Never writes options,
		 * transients, or files.
		 *
		 * @param \WP_REST_Request $_request The request object (unused).
		 * @since 2.0.0
		 * @since NEXT Added preload_checks, cart_checks and force_exclude to the result (issue #1256).
		 * @return \WP_REST_Response The response object.
		 */
		public function get_woo_cache_self_test( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return $this->send_response( Util::woo_cache_self_test() );
		}

		/**
		 * Regenerate used-CSS for all pages or a single post.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @since 1.9.0
		 * @return \WP_REST_Response The response object.
		 */
		public function used_css_regenerate( \WP_REST_Request $request ): \WP_REST_Response {
			if ( $this->is_endpoint_throttled( 'used_css_regenerate', 5, 60 ) ) {
				$response = $this->send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			$params = $request->get_params();
			// Validate the raw post_id before absint(): absint('foo') is 0,
			// which would fall through to bulk regenerate_all and mass-queue
			// on a typo (issue #1274 review).
			$raw_id = $params['post_id'] ?? null;
			if ( null !== $raw_id && '' !== $raw_id && ! is_numeric( $raw_id ) ) {
				return $this->send_response( null, false, 400, __( 'Invalid post ID.', 'performance-optimisation' ) );
			}
			$post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;

			if ( ! function_exists( 'as_enqueue_async_action' ) ) {
				return $this->send_response( null, false, 500, __( 'Action Scheduler is not available.', 'performance-optimisation' ) );
			}

			if ( $post_id ) {
				// Reject unknown post IDs before enqueueing so junk jobs
				// never reach the queue (issue #1274 review). get_post()
				// returns false (not just null) for missing posts.
				if ( function_exists( 'get_post' ) && ! get_post( $post_id ) ) {
					return $this->send_response( null, false, 404, __( 'Invalid post ID.', 'performance-optimisation' ) );
				}
				// Builder-template skip-and-continue (issue #1274): excluded
				// post types report skipped instead of queueing (no error loop).
				if ( class_exists( 'PerformanceOptimise\Inc\Used_CSS' ) && method_exists( 'PerformanceOptimise\Inc\Used_CSS', 'is_excluded_post' ) ) {
					try {
						if ( Used_CSS::is_excluded_post( $post_id ) ) {
							return $this->send_response(
								array(
									'mode'    => 'single',
									'post_id' => $post_id,
									'skipped' => true,
								),
								true,
								200,
								__( 'Skipped: post type is excluded from used-CSS generation.', 'performance-optimisation' )
							);
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				$job_args = array( 'post_id' => $post_id );
				if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'wppo_used_css_generate', $job_args, 'performance_optimisation' ) ) {
					return $this->send_response(
						array(
							'mode'    => 'single',
							'post_id' => $post_id,
						),
						true,
						202,
						__( 'Used CSS regeneration already queued.', 'performance-optimisation' )
					);
				}
				as_enqueue_async_action(
					'wppo_used_css_generate',
					$job_args,
					'performance_optimisation'
				);
				return $this->send_response(
					array(
						'mode'    => 'single',
						'post_id' => $post_id,
					),
					true,
					202,
					__( 'Used CSS regeneration queued.', 'performance-optimisation' )
				);
			}

			$used_css = new Used_CSS();
			// Forced: an explicit operator request bypasses the cooldown,
			// while per-post freshness still skips up-to-date posts (issue #1107).
			$queued = $used_css->regenerate_all( true );

			return $this->send_response(
				array(
					'mode'   => 'background',
					'queued' => $queued,
				),
				true,
				202,
				sprintf(
					/* translators: %d: Number of queued jobs */
					__( 'Queued %d used-CSS regeneration jobs.', 'performance-optimisation' ),
					$queued
				)
			);
		}

		/**
		 * Purge page cache and used CSS together via a single action (issue #1023).
		 *
		 * Shared purge path: delegates to Used_CSS::purge_coupled() which makes
		 * a guarded call into the Cache layer. Accepts an optional `path` for a
		 * single-page coupled purge; empty purges all.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @return \WP_REST_Response The response object.
		 * @since 2.0.0
		 */
		public function purge_used_css_cache( \WP_REST_Request $request ): \WP_REST_Response {
			if ( $this->is_endpoint_throttled( 'purge_used_css_cache', 5, 60 ) ) {
				$response = $this->send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			$params = $request->get_params();
			$path   = isset( $params['path'] ) ? sanitize_text_field( $params['path'] ) : null;

			$url_path = null;
			if ( null !== $path && '' !== $path ) {
				$canonical_host  = class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_canonical_host' ) ? Util::get_canonical_host() : '';
				$normalized_path = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $path ) : (string) $path;
				if ( '' === $canonical_host ) {
					// Fail closed when the canonical host is unresolvable
					// (early boot/CLI/misconfigured home_url): refuse
					// absolute-form inputs instead of degrading to legacy
					// path-only extraction that would map a foreign host
					// onto the local tree.
					$target = ltrim( substr( ltrim( $normalized_path ), 0, strcspn( ltrim( $normalized_path ), '?#' ) ) );
					if ( (bool) preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $target ) || 0 === strpos( $target, '//' ) || (bool) preg_match( '#^[a-zA-Z]:#', $target ) || 0 === strpos( $target, '\\\\' ) ) {
						return $this->send_response( null, false, 400, __( 'Invalid path provided.', 'performance-optimisation' ) );
					}
					$sanitized = Util::sanitize_cache_url_path( $normalized_path, null );
				} else {
					$sanitized = Util::sanitize_cache_url_path( $normalized_path, $canonical_host );
				}
				if ( '' === $sanitized ) {
					// '' is both the benign homepage ('/') and hostile
					// input: only 400 when the raw path component is
					// non-blank. A benign '/' purges just the homepage
					// ('/' stays non-empty so clear_cache() takes the
					// single-page branch instead of purge-all).
					$component = function_exists( 'wp_parse_url' ) ? wp_parse_url( (string) $path, PHP_URL_PATH ) : parse_url( (string) $path, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback for very old WP.
					if ( null === $component || false === $component ) {
						$component = $path;
					}
					if ( '' !== trim( (string) $component, " \t\n\r\0\x0B/" ) ) {
						return $this->send_response( null, false, 400, __( 'Invalid path provided.', 'performance-optimisation' ) );
					}
					$url_path = '/';
				} else {
					$url_path = $sanitized;
				}
			}

			$result  = Used_CSS::purge_coupled( $url_path );
			$page_ok = ! empty( $result['page_cache'] );
			$css_ok  = ! empty( $result['used_css'] );
			$data    = array(
				'page_cache' => $page_ok,
				'used_css'   => $css_ok,
			);

			if ( ! $page_ok && ! $css_ok ) {
				Log::add( __( 'Coupled purge failed: page cache and used CSS not purged.', 'performance-optimisation' ) );
				return $this->send_response( $data, false, 500, __( 'Failed to purge page cache and used CSS.', 'performance-optimisation' ) );
			}

			if ( $page_ok && $css_ok ) {
				Log::add( __( 'Coupled purge: page cache and used CSS purged.', 'performance-optimisation' ) );
				return $this->send_response(
					$data,
					true,
					200,
					__( 'Page cache and used CSS purged.', 'performance-optimisation' )
				);
			}

			$message = $page_ok
				? __( 'Page cache purged, but used CSS purge failed.', 'performance-optimisation' )
				: __( 'Used CSS purged, but page cache purge failed.', 'performance-optimisation' );
			Log::add( $message );

			return $this->send_response(
				$data,
				true,
				200,
				$message
			);
		}

		/**
		 * Manually purge all derived caches (page cache + used-CSS + critical-CSS).
		 *
		 * SPA manual counterpart to the automatic `upgrader_process_complete`
		 * purge (issue #1276): clears the page cache, purges coupled CSS, and
		 * bumps combined-asset versions so the first post-update hit never
		 * serves a FOUC. Intentionally synchronous (unlike the deferred
		 * automatic upgrade path): the admin click needs immediate feedback
		 * plus an up-to-date last-purge record; the 5/60 throttle bounds the
		 * cost. Throttled like regenerate_ccss; fail-open messaging.
		 *
		 * @param \WP_REST_Request $_request The request object (unused).
		 * @since NEXT
		 * @return \WP_REST_Response The response object.
		 */
		public function purge_derived_caches( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			if ( $this->is_endpoint_throttled( 'purge_derived_caches', 5, 60 ) ) {
				$response = $this->send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			$degraded = ! class_exists( 'PerformanceOptimise\Inc\Builder_Purge_Watcher' ) || ! method_exists( 'PerformanceOptimise\Inc\Builder_Purge_Watcher', 'purge_derived_caches' );
			try {
				if ( ! $degraded ) {
					( new Builder_Purge_Watcher() )->purge_derived_caches( 'manual purge' );
				} else {
					if ( class_exists( 'PerformanceOptimise\Inc\Cache' ) ) {
						Cache::clear_cache();
						if ( method_exists( 'PerformanceOptimise\Inc\Cache', 'bump_stats_cache' ) ) {
							Cache::bump_stats_cache();
						}
					}
					if ( class_exists( 'PerformanceOptimise\Inc\Used_CSS' ) ) {
						Used_CSS::purge_coupled( null );
					}
					if ( class_exists( 'PerformanceOptimise\Inc\Critical_CSS' ) ) {
						Critical_CSS::clear_all();
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			if ( class_exists( 'PerformanceOptimise\Inc\Log' ) ) {
				try {
					Log::add( __( 'Manual purge: page cache, used-CSS and critical-CSS purged; combined assets version-bumped.', 'performance-optimisation' ) );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			$message    = $degraded
				? __( 'Page cache and used CSS purged (degraded mode: version tracking unavailable).', 'performance-optimisation' )
				: __( 'Page cache, used CSS and critical CSS purged.', 'performance-optimisation' );
			$last_purge = array(
				'reason' => '',
				'time'   => 0,
			);
			if ( class_exists( 'PerformanceOptimise\Inc\Builder_Purge_Watcher' ) && method_exists( 'PerformanceOptimise\Inc\Builder_Purge_Watcher', 'get_last_purge' ) ) {
				$last_purge = Builder_Purge_Watcher::get_last_purge();
			}
			return $this->send_response(
				$last_purge,
				true,
				200,
				$message
			);
		}

		/**
		 * SPA-visible upgrade-purge status: last-purge reason + safe-mode preview link.
		 *
		 * Read-only GET (issue #1276). The safe preview URL carries
		 * `?wppo_nocache=1` (honoured by Main::is_aggressive_bypass_active())
		 * so it bypasses minify/delay/defer/used-CSS for a one-click styled
		 * proof after an upgrade.
		 *
		 * @param \WP_REST_Request $_request The request object (unused).
		 * @since NEXT
		 * @return \WP_REST_Response The response object.
		 */
		public function get_upgrade_purge_status( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			$last_purge = array(
				'reason' => '',
				'time'   => 0,
			);
			$safe_url   = '';
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Builder_Purge_Watcher' ) ) {
					if ( method_exists( 'PerformanceOptimise\Inc\Builder_Purge_Watcher', 'get_last_purge' ) ) {
						$last_purge = Builder_Purge_Watcher::get_last_purge();
					}
					if ( method_exists( 'PerformanceOptimise\Inc\Builder_Purge_Watcher', 'get_safe_preview_url' ) ) {
						$safe_url = Builder_Purge_Watcher::get_safe_preview_url();
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $this->send_response(
				array(
					'last_purge'       => $last_purge,
					'safe_preview_url' => $safe_url,
				)
			);
		}

		/**
		 * Returns server-level performance rules (Apache/Nginx).
		 *
		 * @param \WP_REST_Request $_request The request object (unused).
		 * @since 1.6.0
		 * @return \WP_REST_Response The response object.
		 */
		public function get_server_rules( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			$server_type = Server_Rules::get_server_type();
			$data        = array(
				'server_type' => $server_type,
				'nginx'       => Server_Rules::get_nginx_rules(),
				'apache'      => Server_Rules::get_apache_rules(),
			);
			// LiteSpeed is Apache-compatible — expose litespeed flag + Apache rules already populated.
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) ) {
				$data['litespeed'] = LiteSpeed_Integration::get_info();
			}
			return $this->send_response( $data );
		}

		/**
		 * Regenerate critical CSS for all templates.
		 *
		 * @param \WP_REST_Request $_request The request object.
		 * @since 2.0.0
		 * @return \WP_REST_Response The response object.
		 */
		public function regenerate_ccss( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			if ( $this->is_endpoint_throttled( 'regenerate_ccss', 5, 60 ) ) {
				$response = $this->send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			// Per-template/per-URL regenerate (issue #1274): an optional
			// `template` slug (or hash) queues one job; otherwise all.
			// The raw input is length-capped (defense-in-depth) before the
			// allowlist lookup in regenerate_single().
			try {
				$params = $_request->get_params();
				if ( array_key_exists( 'template', $params ) ) {
					// An explicit but empty/non-string template key must
					// not fall through to bulk regen (issue #1274 review):
					// it signals a caller bug, so return 0 queued instead
					// of queueing every template.
					$raw_param = $params['template'];
					if ( ! is_string( $raw_param ) || '' === trim( $raw_param ) ) {
						return $this->send_response(
							array(
								'mode'     => 'single',
								'template' => is_string( $raw_param ) ? sanitize_text_field( substr( trim( $raw_param ), 0, 256 ) ) : '',
								'queued'   => 0,
							),
							false,
							400,
							__( 'Invalid template: nothing queued.', 'performance-optimisation' )
						);
					}
					$raw      = substr( trim( $raw_param ), 0, 256 );
					$template = sanitize_text_field( $raw );
					// Known-first ordering (issue #1274 review): an unknown
					// slug returns 404 even while suspended, so typos are
					// never hidden behind the suspended message. The
					// single enumeration below is reused by both the known
					// check and the queue call (no double theme scan).
					$templates_map = null;
					if ( method_exists( 'PerformanceOptimise\Inc\Critical_CSS', 'get_templates' ) ) {
						try {
							$templates_map = Critical_CSS::get_templates();
						} catch ( \Throwable $e ) {
							unset( $e );
							$templates_map = null;
						}
					}
					if ( method_exists( 'PerformanceOptimise\Inc\Critical_CSS', 'is_known_template' ) ) {
						$known = true;
						try {
							$known = Critical_CSS::is_known_template( $template, $templates_map );
						} catch ( \Throwable $e ) {
							unset( $e );
						}
						if ( ! $known ) {
							return $this->send_response(
								array(
									'mode'     => 'single',
									'template' => $template,
									'queued'   => 0,
								),
								false,
								404,
								__( 'Unknown template: nothing queued.', 'performance-optimisation' )
							);
						}
					}
					if ( method_exists( 'PerformanceOptimise\Inc\Critical_CSS', 'is_deferral_suspended_by_js' ) && Critical_CSS::is_deferral_suspended_by_js() ) {
						return $this->send_response(
							array(
								'mode'     => 'single',
								'template' => $template,
								'queued'   => 0,
							),
							true,
							200,
							__( 'Critical CSS generation is suspended while deferred or delayed JavaScript is enabled.', 'performance-optimisation' )
						);
					}
					$queued = Critical_CSS::regenerate_single( $template, $templates_map );
					if ( -1 === $queued ) {
						return $this->send_response(
							array(
								'mode'     => 'single',
								'template' => $template,
								'queued'   => 0,
							),
							false,
							500,
							__( 'Scheduler unavailable: nothing queued.', 'performance-optimisation' )
						);
					}
					if ( 1 === $queued ) {
						return $this->send_response(
							array(
								'mode'     => 'single',
								'template' => $template,
								'queued'   => 1,
							),
							true,
							202,
							__( 'Critical CSS regeneration queued for template.', 'performance-optimisation' )
						);
					}
					return $this->send_response(
						array(
							'mode'     => 'single',
							'template' => $template,
							'queued'   => 0,
						),
						true,
						200,
						__( 'Template skipped: nothing queued.', 'performance-optimisation' )
					);
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			$queued = Critical_CSS::regenerate_all();

			// Suspended while deferJS/delayJS is active: regenerate_all()
			// preserves existing variants and queues nothing (issue #1090).
			// Stay backward compatible (success + queued key) with an
			// explanatory message instead of an HTTP error.
			if ( 0 === $queued && method_exists( 'PerformanceOptimise\Inc\Critical_CSS', 'is_deferral_suspended_by_js' ) && Critical_CSS::is_deferral_suspended_by_js() ) {
				return $this->send_response(
					array( 'queued' => 0 ),
					true,
					200,
					__( 'Critical CSS generation is suspended while deferred or delayed JavaScript is enabled.', 'performance-optimisation' )
				);
			}

			return $this->send_response(
				array( 'queued' => $queued ),
				true,
				200,
				sprintf(
					/* translators: %d: Number of regeneration jobs queued */
					__( 'Critical CSS regeneration: %d jobs queued.', 'performance-optimisation' ),
					$queued
				)
			);
		}

		/**
		 * Get critical CSS status per template.
		 *
		 * @param \WP_REST_Request $_request The request object.
		 * @since 2.0.0
		 * @return \WP_REST_Response The response object.
		 */
		public function get_ccss_status( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			$status = Critical_CSS::get_status_all();

			return $this->send_response( $status );
		}

		/**
		 * Get used-CSS staleness status (issue #1220).
		 *
		 * Read-only: last-regen time, stale flag, targeted-regen cooldown
		 * remaining, and the active delivery mode for the admin staleness
		 * warning. Fail-open to safe defaults on any failure.
		 *
		 * @param \WP_REST_Request $_request The request object (unused).
		 * @return \WP_REST_Response The response object.
		 * @since NEXT
		 */
		public function get_used_css_status( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			$status = class_exists( 'PerformanceOptimise\Inc\Used_CSS' ) && method_exists( 'PerformanceOptimise\Inc\Used_CSS', 'get_staleness_info' )
				? Used_CSS::get_staleness_info()
				: array(
					'last_regen'         => 0,
					'last_regen_human'   => '',
					'is_stale'           => false,
					'cooldown_remaining' => 0,
					'delivery_mode'      => 'file',
				);

			return $this->send_response( $status );
		}

		/**
		 * Get AI adaptive model.
		 *
		 * @param \WP_REST_Request $_request The request object.
		 * @return \WP_REST_Response The response object.
		 * @since 2.0.0
		 */
		public function get_ai_model( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			$model = class_exists( 'PerformanceOptimise\Inc\AI_Adaptive' ) ? AI_Adaptive::get_model() : array();
			return $this->send_response( $model );
		}

		/**
		 * Trigger AI learning.
		 *
		 * @param \WP_REST_Request $_request The request object.
		 * @return \WP_REST_Response The response object.
		 * @since 2.0.0
		 */
		public function ai_learn( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			if ( $this->is_endpoint_throttled( 'ai_learn', 5, 60 ) ) {
				$response = $this->send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			$model = class_exists( 'PerformanceOptimise\Inc\AI_Adaptive' ) ? AI_Adaptive::learn() : array();
			return $this->send_response( $model );
		}

		/**
		 * Get AI suggestions.
		 *
		 * @param \WP_REST_Request $_request The request object.
		 * @return \WP_REST_Response The response object.
		 * @since 2.0.0
		 */
		public function get_ai_suggestions( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			$suggestions = class_exists( 'PerformanceOptimise\Inc\Suggestion_Engine' ) ? Suggestion_Engine::from_ai_adaptive() : array();
			return $this->send_response( array( 'suggestions' => $suggestions ) );
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
		 * @since NEXT
		 */
		public function get_sandbox_preview( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			$staged      = class_exists( 'PerformanceOptimise\Inc\Sandbox_Preview' ) ? Sandbox_Preview::get_staged_settings() : array();
			$preview_url = '';
			if ( class_exists( 'PerformanceOptimise\Inc\Sandbox_Preview' ) && method_exists( 'PerformanceOptimise\Inc\Sandbox_Preview', 'get_preview_url' ) ) {
				$home        = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
				$preview_url = Sandbox_Preview::get_preview_url( $home );
			}
			return $this->send_response(
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
		 * @since NEXT
		 */
		public function save_sandbox_preview( \WP_REST_Request $request ): \WP_REST_Response {
			if ( $this->is_endpoint_throttled( 'sandbox_save', 5, 60 ) ) {
				$response = $this->send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			$params   = $request->get_params();
			$settings = isset( $params['settings'] ) && is_array( $params['settings'] ) ? $params['settings'] : array();
			if ( ! class_exists( 'PerformanceOptimise\Inc\Sandbox_Preview' ) ) {
				return $this->send_response( null, false, 500, __( 'Sandbox preview is unavailable.', 'performance-optimisation' ) );
			}
			$ok = Sandbox_Preview::save_staged( $settings );
			if ( ! $ok ) {
				return $this->send_response( null, false, 500, __( 'Could not save sandbox settings.', 'performance-optimisation' ) );
			}
			return $this->send_response( array( 'staged' => Sandbox_Preview::get_staged_settings() ) );
		}

		/**
		 * Promote staged sandbox settings to production (issue #1163).
		 *
		 * @param \WP_REST_Request $_request The request object.
		 * @return \WP_REST_Response The response object.
		 * @since NEXT
		 */
		public function promote_sandbox_preview( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			if ( $this->is_endpoint_throttled( 'sandbox_promote', 5, 60 ) ) {
				$response = $this->send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			if ( ! class_exists( 'PerformanceOptimise\Inc\Sandbox_Preview' ) ) {
				return $this->send_response( null, false, 500, __( 'Sandbox preview is unavailable.', 'performance-optimisation' ) );
			}
			if ( ! Sandbox_Preview::is_staged_available() ) {
				return $this->send_response( null, false, 400, __( 'No staged sandbox settings to promote.', 'performance-optimisation' ) );
			}
			$ok = Sandbox_Preview::promote_staged();
			if ( ! $ok ) {
				return $this->send_response( null, false, 500, __( 'Could not promote sandbox settings.', 'performance-optimisation' ) );
			}
			$options = Util::get_settings();
			$this->remove_sensitive_settings_from_response( $options );
			return $this->send_response( $options );
		}

		/**
		 * Discard staged sandbox settings (issue #1163).
		 *
		 * @param \WP_REST_Request $_request The request object.
		 * @return \WP_REST_Response The response object.
		 * @since NEXT
		 */
		public function discard_sandbox_preview( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			if ( $this->is_endpoint_throttled( 'sandbox_discard', 5, 60 ) ) {
				$response = $this->send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			if ( ! class_exists( 'PerformanceOptimise\Inc\Sandbox_Preview' ) ) {
				return $this->send_response( null, false, 500, __( 'Sandbox preview is unavailable.', 'performance-optimisation' ) );
			}
			$ok = Sandbox_Preview::discard_staged();
			if ( ! $ok ) {
				return $this->send_response( null, false, 500, __( 'Could not discard sandbox settings.', 'performance-optimisation' ) );
			}
			return $this->send_response( array( 'staged' => array() ) );
		}

		/**
		 * Auto-exclude detector for the minify/combine/defer stack (issue #1465).
		 *
		 * Inspects the currently enqueued script/style handles (when
		 * available) plus active-plugin signals (WooCommerce, Elementor,
		 * Kadence — all function_exists/class_exists-guarded for WP 6.2+
		 * compat) and maps them through
		 * Main::detect_fragile_handles() so the UI can suggest the exact
		 * handle to exclude. Read-only, per-site settings only
		 * (multisite-safe). Fail-open: any failure returns an empty
		 * suggestion list, never fatal.
		 *
		 * Limitation: inside a REST/admin request the frontend
		 * `$GLOBALS['wp_scripts']->queue` / `$GLOBALS['wp_styles']->queue`
		 * is never populated, so the endpoint falls back to plugin-signal
		 * guesses unless the caller passes the page's real handles via the
		 * optional `handles` param (array or comma-separated string).
		 *
		 * @param \WP_REST_Request $_request The request object. Optional `handles` param for accuracy.
		 * @return \WP_REST_Response The response object.
		 * @since NEXT
		 */
		public function detect_safe_mode_excludes( \WP_REST_Request $_request ): \WP_REST_Response {
			try {
				$file_opt = array();
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
					try {
						$settings = (array) Util::get_settings();
						$file_opt = isset( $settings['file_optimisation'] ) && is_array( $settings['file_optimisation'] ) ? $settings['file_optimisation'] : array();
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				$handles = array();
				// Explicit handles param wins for accuracy: the REST/admin
				// queue is empty, so the UI may POST the frontend queue it
				// collected (array or comma-separated string, sanitized).
				try {
					$req_params = $_request->get_params();
					if ( isset( $req_params['handles'] ) ) {
						$raw_handles = $req_params['handles'];
						if ( is_string( $raw_handles ) ) {
							$raw_handles = explode( ',', $raw_handles );
						}
						if ( is_array( $raw_handles ) ) {
							foreach ( $raw_handles as $handle ) {
								if ( is_string( $handle ) || is_numeric( $handle ) ) {
									$clean = trim( sanitize_text_field( (string) $handle ) );
									if ( '' !== $clean ) {
										$handles[] = $clean;
									}
								}
							}
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				try {
					$has_explicit = ! empty( $handles );
					if ( ! $has_explicit && isset( $GLOBALS['wp_scripts'] ) && is_object( $GLOBALS['wp_scripts'] ) && ! empty( $GLOBALS['wp_scripts']->queue ) && is_array( $GLOBALS['wp_scripts']->queue ) ) {
						foreach ( $GLOBALS['wp_scripts']->queue as $handle ) {
							if ( is_string( $handle ) || is_numeric( $handle ) ) {
								$handles[] = (string) $handle;
							}
						}
					}
					if ( ! $has_explicit && isset( $GLOBALS['wp_styles'] ) && is_object( $GLOBALS['wp_styles'] ) && ! empty( $GLOBALS['wp_styles']->queue ) && is_array( $GLOBALS['wp_styles']->queue ) ) {
						foreach ( $GLOBALS['wp_styles']->queue as $handle ) {
							if ( is_string( $handle ) || is_numeric( $handle ) ) {
								$handles[] = (string) $handle;
							}
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				// Active-plugin signals when the queue is unavailable (e.g.
				// admin-ajax context): suggest the canonical fragile handles
				// so the UI still names the exact handle first.
				try {
					if ( empty( $handles ) ) {
						$signals = array();
						if ( function_exists( 'is_cart' ) || function_exists( 'is_checkout' ) || class_exists( 'WooCommerce' ) ) {
							$signals[] = 'wc-cart-fragments';
							$signals[] = 'wc-checkout';
						}
						if ( function_exists( 'elementor_pro_load_plugin' ) || ( function_exists( 'did_action' ) && did_action( 'elementor/loaded' ) ) || class_exists( 'Elementor\Plugin' ) ) {
							$signals[] = 'elementor-frontend';
						}
						if ( class_exists( 'Kadence_Blocks' ) || ( function_exists( 'wp_get_theme' ) && false !== stripos( (string) ( function_exists( 'get_template' ) ? get_template() : '' ), 'kadence' ) ) ) {
							$signals[] = 'kadence-blocks';
						}
						if ( function_exists( 'et_setup_theme' ) || class_exists( 'ET_Builder_Element' ) ) {
							$signals[] = 'divi-custom-script';
						}
						if ( empty( $signals ) ) {
							$signals[] = 'jquery-core';
						}
						$handles = $signals;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					if ( empty( $handles ) ) {
						$handles = array( 'jquery-core' );
					}
				}
				$suggestions = array();
				if ( class_exists( 'PerformanceOptimise\Inc\Main' ) && method_exists( 'PerformanceOptimise\Inc\Main', 'detect_fragile_handles' ) ) {
					try {
						$suggestions = Main::detect_fragile_handles( $handles );
					} catch ( \Throwable $e ) {
						unset( $e );
						$suggestions = array();
					}
				}
				$stack = array(
					'safe_mode'         => false,
					'delay_js'          => ! empty( $file_opt['delayJS'] ),
					'defer_js'          => ! empty( $file_opt['deferJS'] ),
					'combine_css'       => ! empty( $file_opt['combineCSS'] ),
					'remove_unused_css' => ! empty( $file_opt['removeUnusedCSS'] ),
					'stack_enabled'     => false,
				);
				if ( class_exists( 'PerformanceOptimise\Inc\Main' ) && method_exists( 'PerformanceOptimise\Inc\Main', 'get_safe_mode_stack_state' ) ) {
					try {
						$stack = Main::get_safe_mode_stack_state( $file_opt );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				return $this->send_response(
					array(
						'stack'       => $stack,
						'suggestions' => $suggestions,
						'handles'     => array_values( array_unique( $handles ) ),
					)
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return $this->send_response(
					array(
						'stack'       => array(
							'safe_mode'         => false,
							'delay_js'          => false,
							'defer_js'          => false,
							'combine_css'       => false,
							'remove_unused_css' => false,
							'stack_enabled'     => false,
						),
						'suggestions' => array(),
						'handles'     => array(),
					)
				);
			}
		}

		/**
		 * One-click safe mode for the minify/combine/defer stack (issue #1465).
		 *
		 * POST param `action` is `enable` (single action restores an unbroken
		 * render: delay + defer + combine + used-CSS paused, settings
		 * preserved) or `disable` (restores the previous configuration).
		 * Persists per-site `file_optimisation.safeMode` with a settings
		 * snapshot for one-click undo. Throttled, manage_options-gated via
		 * the route permission callback. Fail-open: never fatal.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @return \WP_REST_Response The response object.
		 * @since NEXT
		 */
		public function handle_safe_mode( \WP_REST_Request $request ): \WP_REST_Response {
			if ( $this->is_endpoint_throttled( 'safe_mode', 5, 60 ) ) {
				$response = $this->send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			try {
				$params = $request->get_params();
				$action = isset( $params['action'] ) ? sanitize_text_field( (string) $params['action'] ) : 'enable';
				if ( ! in_array( $action, array( 'enable', 'disable' ), true ) ) {
					return $this->send_response( null, false, 400, __( 'Invalid safe-mode action.', 'performance-optimisation' ) );
				}
				$options = class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ? (array) Util::get_settings() : array();
				if ( ! isset( $options['file_optimisation'] ) || ! is_array( $options['file_optimisation'] ) ) {
					$options['file_optimisation'] = array();
				}
				// Snapshot only on enable while safe mode is off: snapshotting
				// on disable would overwrite the pre-enable undo state with
				// the safeMode=true state, so a later restore would wrongly
				// re-enable safe mode instead of the original config.
				$was_safe = ! empty( $options['file_optimisation']['safeMode'] );
				if ( 'enable' === $action && ! $was_safe ) {
					try {
						if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'take_settings_snapshot' ) ) {
							Util::take_settings_snapshot( $options );
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( 'enable' === $action ) {
					if ( class_exists( 'PerformanceOptimise\Inc\Main' ) && method_exists( 'PerformanceOptimise\Inc\Main', 'build_safe_mode_enable_payload' ) ) {
						try {
							$options['file_optimisation'] = Main::build_safe_mode_enable_payload( $options['file_optimisation'] );
						} catch ( \Throwable $e ) {
							unset( $e );
							$options['file_optimisation']['safeMode'] = true;
						}
					} else {
						$options['file_optimisation']['safeMode'] = true;
					}
				} else {
					$options['file_optimisation']['safeMode'] = false;
				}
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'save_settings' ) ) {
					Util::save_settings( $options );
					if ( method_exists( 'PerformanceOptimise\Inc\Util', 'set_settings_cache' ) ) {
						try {
							Util::set_settings_cache( $options );
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
				} elseif ( function_exists( 'update_option' ) ) {
						update_option( 'wppo_settings', $options, false );
				}
				if ( class_exists( 'PerformanceOptimise\Inc\Telemetry' ) && method_exists( 'PerformanceOptimise\Inc\Telemetry', 'invalidate_audit_cache' ) ) {
					try {
						Telemetry::invalidate_audit_cache();
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				// Purge the static HTML page cache (plus combined-CSS sidecars)
				// so safe mode takes effect immediately: cached pages are
				// served via the advanced-cache.php drop-in bypassing
				// WordPress, and would otherwise keep serving the broken
				// markup until expiry. Fail-open: purge failure never blocks
				// the toggle response.
				try {
					if ( class_exists( 'PerformanceOptimise\Inc\Cache' ) && method_exists( 'PerformanceOptimise\Inc\Cache', 'clear_cache' ) ) {
						Cache::clear_cache();
					} elseif ( class_exists( 'PerformanceOptimise\Inc\Main' ) && method_exists( 'PerformanceOptimise\Inc\Main', 'clear_all_cache' ) ) {
						Main::clear_all_cache();
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				$this->remove_sensitive_settings_from_response( $options );
				return $this->send_response( $options );
			} catch ( \Throwable $e ) {
				unset( $e );
				return $this->send_response( null, false, 500, __( 'Could not toggle safe mode.', 'performance-optimisation' ) );
			}
		}

		/**
		 * Dismisses the welcome panel for the current user.
		 *
		 * @param \WP_REST_Request|null $request The request object (unused; present for route-callback signature parity).
		 * @since 2.0.0
		 * @return \WP_REST_Response The response object.
		 */
		public function dismiss_welcome( ?\WP_REST_Request $request = null ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST route callbacks receive the request object.
			if ( get_current_user_id() ) {
				update_user_meta( get_current_user_id(), 'wppo_welcome_dismissed', 1 );
			}
			return $this->send_response( null, true );
		}

		/**
		 * Sends a REST API response.
		 *
		 * @param mixed       $data The data to return in the response.
		 * @param bool        $success Indicates whether the request was successful.
		 * @param int         $status_code The HTTP status code.
		 * @param string|null $message The response message.
		 * @since 1.0.0
		 * @return \WP_REST_Response The response object.
		 */
		private function send_response( $data, $success = true, $status_code = 200, $message = null ) {
			return new \WP_REST_Response(
				array(
					'data'    => $data,
					'success' => $success,
					'message' => $message,
				),
				$status_code
			);
		}
	}
}
