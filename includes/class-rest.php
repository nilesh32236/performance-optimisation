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
		private $cache_dir;

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
				// @deprecated NEXT get_page_assets is deprecated; kept one release for
				// external consumers. Migrate to the Abilities API
				// performance-optimisation/get-page-assets.
				'get_page_assets'           => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_page_assets' ),
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
				// @since NEXT Added rate-limit documentation.
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
		 * Handle one-click autoload-bloat remediation (dry run, apply, revert).
		 *
		 * POST param `mode` controls the operation:
		 * - `dry_run` (default): report candidates + bytes saved, changes nothing.
		 * - `apply`: flip non-core options above the threshold to autoload off.
		 * - `revert`: restore one option (`option` param) to its prior value.
		 * - `revert_all`: restore every remediated option.
		 *
		 * Optional params: `threshold` (bytes, 100..10MB), `limit` (1..500).
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @return \WP_REST_Response The response object.
		 * @since NEXT
		 */
		public function handle_autoload_remediate( \WP_REST_Request $request ): \WP_REST_Response {
			$params = $request->get_params();
			$mode   = isset( $params['mode'] ) ? sanitize_text_field( $params['mode'] ) : 'dry_run';

			if ( ! in_array( $mode, array( 'dry_run', 'apply', 'revert', 'revert_all' ), true ) ) {
				return $this->send_response( null, false, 400, __( 'Invalid remediation mode.', 'performance-optimisation' ) );
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
		 * @since NEXT
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
		 * @since NEXT Added public-endpoint justification with rate-limit reference.
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
		 * Returns the JSON schema for a given REST route.
		 *
		 * Provides a standard response schema including success status, data payload,
		 * and error message structure. Enables API discoverability and integration
		 * with WP REST API tooling.
		 *
		 * @since NEXT
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
						'description' => esc_html__( 'Whether the request was successful.', 'performance-optimisation' ),
						'type'        => 'boolean',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
					'data'    => array(
						'description' => esc_html__( 'Response data payload.', 'performance-optimisation' ),
						'type'        => array( 'object', 'array', 'string', 'boolean', 'null' ),
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
					'message' => array(
						'description' => esc_html__( 'Response message.', 'performance-optimisation' ),
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
		 * @since NEXT Added $request parameter for header canonicalization.
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
		 * Clears the cache based on the given action.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @since 1.0.0
		 * @return \WP_REST_Response The response object.
		 */
		public function clear_cache( \WP_REST_Request $request ) {
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
					// @since NEXT Added wp_normalize_path fallback for uncached pages.
					$is_exact_match = ( $candidate_path === $normalized_cache_dir );
					$is_under_dir   = ( 0 === strpos( $candidate_path, $normalized_cache_dir_trail ) );

					if ( ( ! $is_exact_match && ! $is_under_dir ) || false !== strpos( $candidate_path, '..' ) ) {
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

			// Removed (#925): the legacy file_optimisation.removeQueryStrings key
			// is dropped on save so it decays naturally. A legacy client that
			// still posts the key is accepted silently (fail-open, never fatal);
			// stored legacy values are ignored and `?ver` is always preserved.
			if ( 'file_optimisation' === $tab && isset( $sanitized_settings['removeQueryStrings'] ) ) {
				unset( $sanitized_settings['removeQueryStrings'] );
			}

			$options = Util::get_settings();

			// Preserve the pagespeed_api_key when the request omits it.
			if ( 'performance_audit' === $tab && ! isset( $params['settings']['pagespeed_api_key'] ) && isset( $options['performance_audit']['pagespeed_api_key'] ) ) {
				$sanitized_settings['pagespeed_api_key'] = sanitize_text_field( $options['performance_audit']['pagespeed_api_key'] );
			}

			// Preserve the server_timing_enabled flag when the request omits it (no UI toggle exists yet).
			if ( 'performance_audit' === $tab && ! isset( $params['settings']['server_timing_enabled'] ) && isset( $options['performance_audit']['server_timing_enabled'] ) ) {
				$sanitized_settings['server_timing_enabled'] = $options['performance_audit']['server_timing_enabled'];
			}

			// Preserve the auto_rescan frequency when the request omits it.
			if ( 'performance_audit' === $tab && ! isset( $params['settings']['auto_rescan'] ) && isset( $options['performance_audit']['auto_rescan'] ) ) {
				$sanitized_settings['auto_rescan'] = $options['performance_audit']['auto_rescan'];
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
			// a toggle save must not wipe a custom threshold.
			if ( 'ai_adaptive' === $tab && ! isset( $params['settings']['field_lcp_min_samples'] ) && isset( $options['ai_adaptive']['field_lcp_min_samples'] ) ) {
				$sanitized_settings['field_lcp_min_samples'] = absint( $options['ai_adaptive']['field_lcp_min_samples'] );
			}

			$options[ $tab ] = $sanitized_settings;

			update_option( 'wppo_settings', $options );

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
		private function sanitize_settings_recursively( $settings ) {
			return Util::sanitize_settings_recursively( $settings );
		}

		/**
		 * Removes sensitive settings from the response array.
		 *
		 * @param array $settings The settings array passed by reference.
		 * @return void
		 */
		private function remove_sensitive_settings_from_response( array &$settings ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingReturn -- no void type for PHP 7.0 compat
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
			$params = $request->get_params();

			$webp_images = isset( $params['webp'] ) ? array_map( 'sanitize_text_field', (array) $params['webp'] ) : array();
			$avif_images = isset( $params['avif'] ) ? array_map( 'sanitize_text_field', (array) $params['avif'] ) : array();

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

			// Validate image paths using realpath to prevent directory traversal.
			$normalized_abspath = trailingslashit( wp_normalize_path( ABSPATH ) );
			foreach ( array_merge( $webp_images, $avif_images ) as $img_path ) {
				$source_path = $normalized_abspath . $img_path;
				if ( ! file_exists( $source_path ) ) {
					continue;
				}
				$resolved = realpath( $source_path );
				if ( false === $resolved || 0 !== strpos( wp_normalize_path( $resolved ), $normalized_abspath ) ) {
					return $this->send_response( null, false, 400, __( 'Invalid image path provided.', 'performance-optimisation' ) );
				}
			}

			$use_action_scheduler = function_exists( 'as_enqueue_async_action' );
			$jobs_queued          = 0;

			if ( $use_action_scheduler ) {
				// Schedule background jobs via Action Scheduler with deduplication.
				foreach ( $webp_images as $webp_image ) {
					$source_path = wp_normalize_path( ABSPATH . $webp_image );

					if ( file_exists( $source_path ) ) {
						$args = array(
							array(
								'source_path' => $source_path,
								'format'      => 'webp',
							),
						);
						if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'wppo_convert_image_background', $args, 'performance_optimisation' ) ) {
							continue;
						}
						as_enqueue_async_action(
							'wppo_convert_image_background',
							$args,
							'performance_optimisation'
						);
						++$jobs_queued;
					}
				}

				foreach ( $avif_images as $avif_image ) {
					$source_path = wp_normalize_path( ABSPATH . $avif_image );

					if ( file_exists( $source_path ) ) {
						$args = array(
							array(
								'source_path' => $source_path,
								'format'      => 'avif',
							),
						);
						if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'wppo_convert_image_background', $args, 'performance_optimisation' ) ) {
							continue;
						}
						as_enqueue_async_action(
							'wppo_convert_image_background',
							$args,
							'performance_optimisation'
						);
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
				$source_path = wp_normalize_path( ABSPATH . $webp_image );

				if ( file_exists( $source_path ) ) {
					$img_converter->convert_image( $source_path, 'webp' );
				}
			}

			foreach ( $avif_images as $avif_image ) {
				$source_path = wp_normalize_path( ABSPATH . $avif_image );

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
			global $wp_filesystem;
			if ( ! Util::init_filesystem() ) {
				return $this->send_response( null, false, 500, __( 'Unable to initialize filesystem.', 'performance-optimisation' ) );
			}

			$wppo_dir = wp_normalize_path( WP_CONTENT_DIR . '/wppo' );

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

			if ( ! update_option( 'wppo_settings', $merged_settings ) ) {
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
						__( 'Database cleanup (all): %d items removed on ', 'performance-optimisation' ),
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

			if ( is_wp_error( $result ) ) {
				return $this->send_response( null, false, 500, __( 'Database cleanup failed.', 'performance-optimisation' ) );
			}

			Log::add(
				sprintf(
					/* translators: %1$s: Cleanup type, %2$d: Number of items */
					__( 'Database cleanup (%1$s): %2$d items removed on ', 'performance-optimisation' ),
					$type,
					(int) $result
				)
			);

			// Optimize affected tables after successful individual cleanup.
			if ( (int) $result > 0 && isset( Database_Cleanup::TABLE_MAP[ $type ] ) ) {
				Database_Cleanup::maybe_optimize_tables(
					Database_Cleanup::TABLE_MAP[ $type ],
					true
				);
			}

			return $this->send_response(
				array(
					'type'    => $type,
					'deleted' => (int) $result,
				)
			);
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
			return $this->send_response( $counts );
		}

		/**
		 * Returns the cached assets for a specific post/page.
		 *
		 * @deprecated NEXT Use the Abilities API `performance-optimisation/get-page-assets`
		 *             or Asset_Manager::get_page_assets() directly. This REST route will
		 *             be removed in a future release.
		 * @param \WP_REST_Request $request The request object.
		 * @since 1.1.0
		 * @return \WP_REST_Response The response object.
		 */
		public function get_page_assets( \WP_REST_Request $request ) {
			_deprecated_function( __METHOD__, 'NEXT', 'Abilities::execute_get_page_assets' );
			$params  = $request->get_params();
			$post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;

			if ( ! $post_id ) {
				return $this->send_response( null, false, 400, __( 'Post ID is required.', 'performance-optimisation' ) );
			}

			$assets = Asset_Manager::get_page_assets( $post_id );

			if ( false === $assets ) {
				return $this->send_response(
					array(
						'scripts' => array(),
						'styles'  => array(),
					),
					true,
					200,
					__( 'No assets captured yet. Visit the page on the frontend first.', 'performance-optimisation' )
				);
			}

			return $this->send_response( $assets );
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
			if ( function_exists( 'as_get_scheduled_actions' ) ) {
				$pending_jobs = as_get_scheduled_actions(
					array(
						'hook'     => 'wppo_convert_image_background',
						'status'   => \ActionScheduler_Store::STATUS_PENDING,
						'group'    => 'performance_optimisation',
						'per_page' => 100,
					),
					'ARRAY_A'
				);

				$status['queued_jobs'] = count( $pending_jobs );
			} else {
				$status['queued_jobs'] = 0;
			}

			// Aggregate original-vs-optimised sizes for the dashboard report.
			$status['savings'] = Img_Converter::get_savings_summary();

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
		 * @return \WP_REST_Response The response object.
		 */
		public function handle_object_cache( \WP_REST_Request $request ) {
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
				$result = $manager->flush();
				if ( $result ) {
					Log::add( __( 'Object Cache flushed.', 'performance-optimisation' ) );
					return $this->send_response( true, true, 200, __( 'Object Cache flushed.', 'performance-optimisation' ) );
				}
				// No Log::add here: Object_Cache::flush() already recorded
				// the failure in-app. Forward the manager's real error so
				// the SPA notice keeps its specificity.
				$flush_error = $manager->get_last_flush_error();
				if ( ! ( $flush_error instanceof \WP_Error ) ) {
					$flush_error = new \WP_Error( 'flush_fail', __( 'Flush reported failure.', 'performance-optimisation' ) );
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
		 * @since NEXT Request-supplied passwords are ignored when WPPO_REDIS_PASSWORD is defined, unless the `wppo_redis_allow_request_password` filter returns true.
		 * @since NEXT Added the $defaults parameter for circuit-breaker recovery.
		 * @return array Sanitized Redis config.
		 */
		private function build_redis_config( $params, $defaults = array() ) {
			$allowed_keys = array( 'mode', 'host', 'port', 'password', 'database', 'nodes', 'master_name', 'use_tls', 'persistent', 'compression' );
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
		 * @since NEXT
		 * @return mixed Sanitized value.
		 */
		private function sanitize_redis_config_value( $key, $value ) {
			switch ( $key ) {
				case 'host':
				case 'master_name':
				case 'compression':
				case 'mode':
					return sanitize_text_field( (string) $value );
				case 'port':
				case 'database':
					return (int) $value;
				case 'password':
					// When WPPO_REDIS_PASSWORD is defined the constant takes
					// precedence: supplied passwords are dropped unless
					// the wppo_redis_allow_request_password escape hatch returns true.
					if ( defined( 'WPPO_REDIS_PASSWORD' ) && ! apply_filters( 'wppo_redis_allow_request_password', false ) ) {
						return '';
					}
					return sanitize_text_field( (string) $value );
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
			if ( is_array( $nodes ) ) {
				return array_values( array_filter( array_map( 'sanitize_text_field', $nodes ) ) );
			}
			$nodes = sanitize_text_field( (string) $nodes );
			return $nodes ? array( $nodes ) : array();
		}

		/**
		 * Build a useNotice-compatible error payload for Redis failures.
		 *
		 * The SPA reads `res.success` + `res.message`; this adds a structured
		 * `code` + `notice` ({ type, message }) body so useNotice() can render
		 * the failure with the right severity without changing the envelope.
		 *
		 * @since NEXT
		 * @param \WP_Error $error  Failing result.
		 * @param string    $notice Notice severity: 'error', 'warning', 'info'.
		 * @return array Shape { code: string, notice: array{ type: string, message: string } }.
		 */
		private function redis_error_payload( $error, string $notice = 'error' ): array {
			$allowed = array( 'error', 'warning', 'info' );
			if ( ! in_array( $notice, $allowed, true ) ) {
				$notice = 'error';
			}
			$message = $error->get_error_message();
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
			$params = $request->get_params();
			$url    = isset( $params['url'] ) ? esc_url_raw( $params['url'] ) : Util::cached_home_url( '/' );

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
				return $this->send_response( null, false, 500, __( 'Performance scan failed.', 'performance-optimisation' ) );
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
			$params   = $request->get_params();
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

			// Check dedup before enqueuing so we can return 200 with the existing job ID instead of 202+0.
			$dedup_args = array(
				array(
					'url'      => $url,
					'strategy' => $strategy,
				),
			);
			$is_deduped = function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( Pagespeed::AS_HOOK, $dedup_args, Pagespeed::AS_GROUP );

			$job_id = Pagespeed::queue_scan( $url, $strategy );

			if ( $is_deduped && $job_id > 0 ) {
				return $this->send_response(
					array(
						'job_id'         => $job_id,
						'url'            => $url,
						'strategy'       => $strategy,
						'already_queued' => true,
					),
					true,
					200
				);
			}

			if ( 0 === $job_id && $is_deduped ) {
				// Deduped but existing ID could not be resolved — return 200 without ambiguous 202+0.
				return $this->send_response(
					array(
						'job_id'         => 0,
						'url'            => $url,
						'strategy'       => $strategy,
						'already_queued' => true,
					),
					true,
					200
				);
			}

			return $this->send_response(
				array(
					'job_id'   => $job_id,
					'url'      => $url,
					'strategy' => $strategy,
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
			$params   = $request->get_params();
			$url      = isset( $params['url'] ) ? esc_url_raw( $params['url'] ) : Util::cached_home_url( '/' );
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
		 * @since NEXT
		 * @return \WP_REST_Response The response object.
		 */
		public function get_web_vitals_trends( \WP_REST_Request $request ): \WP_REST_Response {
			$params   = $request->get_params();
			$url      = isset( $params['url'] ) ? esc_url_raw( $params['url'] ) : '';
			$strategy = isset( $params['strategy'] ) ? sanitize_text_field( $params['strategy'] ) : '';

			if ( ! in_array( $strategy, array( 'mobile', 'desktop', '' ), true ) ) {
				$strategy = '';
			}

			$trends = Pagespeed::get_trends();

			// Filter when either url or strategy is provided.
			if ( ! empty( $url ) || ! empty( $strategy ) ) {
				$url_key = '' !== $url ? md5( $url ) : null;
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
			$url    = isset( $params['url'] ) ? esc_url_raw( $params['url'] ) : Util::cached_home_url( '/' );

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
		 * DONOTCACHEPAGE honored. Never writes options, transients, or files.
		 *
		 * @param \WP_REST_Request $_request The request object (unused).
		 * @since NEXT
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
			$params  = $request->get_params();
			$post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;

			if ( ! function_exists( 'as_enqueue_async_action' ) ) {
				return $this->send_response( null, false, 500, __( 'Action Scheduler is not available.', 'performance-optimisation' ) );
			}

			if ( $post_id ) {
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
			$queued   = $used_css->regenerate_all();

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
		 * @since NEXT
		 */
		public function purge_used_css_cache( \WP_REST_Request $request ): \WP_REST_Response {
			$params = $request->get_params();
			$path   = isset( $params['path'] ) ? sanitize_text_field( $params['path'] ) : null;

			$url_path = null;
			if ( null !== $path && '' !== $path ) {
				$sanitized = Util::sanitize_cache_url_path( wp_normalize_path( $path ) );
				if ( '' === $sanitized ) {
					return $this->send_response( null, false, 400, __( 'Invalid path provided.', 'performance-optimisation' ) );
				}
				$url_path = $sanitized;
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
		 * @since NEXT
		 * @return \WP_REST_Response The response object.
		 */
		public function regenerate_ccss( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			$queued = Critical_CSS::regenerate_all();

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
		 * @since NEXT
		 * @return \WP_REST_Response The response object.
		 */
		public function get_ccss_status( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			$status = Critical_CSS::get_status_all();

			return $this->send_response( $status );
		}

		/**
		 * Get AI adaptive model.
		 *
		 * @param \WP_REST_Request $_request The request object.
		 * @return \WP_REST_Response The response object.
		 * @since NEXT
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
		 * @since NEXT
		 */
		public function ai_learn( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			$model = class_exists( 'PerformanceOptimise\Inc\AI_Adaptive' ) ? AI_Adaptive::learn() : array();
			return $this->send_response( $model );
		}

		/**
		 * Get AI suggestions.
		 *
		 * @param \WP_REST_Request $_request The request object.
		 * @return \WP_REST_Response The response object.
		 * @since NEXT
		 */
		public function get_ai_suggestions( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			$suggestions = class_exists( 'PerformanceOptimise\Inc\Suggestion_Engine' ) ? Suggestion_Engine::from_ai_adaptive() : array();
			return $this->send_response( array( 'suggestions' => $suggestions ) );
		}

		/**
		 * Dismisses the welcome panel for the current user.
		 *
		 * @since NEXT
		 * @return \WP_REST_Response The response object.
		 */
		public function dismiss_welcome(): \WP_REST_Response {
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
