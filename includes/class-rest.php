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
	die();
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
			return array(
				'clear_cache'             => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'clear_cache' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				'update_settings'         => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				'optimise_image'          => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'optimise_image' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				'delete_optimised_image'  => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'delete_optimised_image' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				'recent_activities'       => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_recent_activities' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				'import_settings'         => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'import_settings' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				'database_cleanup'        => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'database_cleanup' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				'database_cleanup_counts' => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_database_cleanup_counts' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				'get_page_assets'         => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_page_assets' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				'image_job_status'        => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_image_job_status' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				'object_cache'            => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_object_cache' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),

				// Phase 1 — Local Diagnostics (v1.5.0).
				'system_info'             => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_system_info' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				'performance_scan'        => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'run_performance_scan' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),

				// Phase 2 — PageSpeed Integration & Actionable Suggestions (v1.6.0).
				'pagespeed_scan'          => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'queue_pagespeed_scan' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				'pagespeed_results'       => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_pagespeed_results' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				'suggestions'             => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_suggestions' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				'server_rules'            => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_server_rules' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
			);
		}

		/**
		 * Checks if the user has permission to access the route.
		 *
		 * @since 1.0.0
		 * @return bool True if the user has permission, false otherwise.
		 */
		public function permission_callback() {
			$nonce       = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ) : '';
			$nonce_valid = wp_verify_nonce( $nonce, 'wp_rest' );

			return current_user_can( 'manage_options' ) && $nonce_valid;
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

			$path = wp_normalize_path( $path );

			// Reject path if it contains directory traversal sequences.
			if ( strpos( $path, '..' ) !== false ) {
				return $this->send_response( null, false, 400, __( 'Invalid path provided.', 'performance-optimisation' ) );
			}

			if ( 'clear_single_page_cache' === $action ) {
				$cleared = Cache::clear_cache( $path );
				if ( ! $cleared ) {
					return $this->send_response( null, false, 400, __( 'Failed to clear cache: Invalid path.', 'performance-optimisation' ) );
				}
				new Log(
					sprintf(
						/* translators: %s: The URL of the page */
						__( 'Clear cache of <a href="%1$s">%2$s</a> on ', 'performance-optimisation' ),
						esc_url( home_url( $path ) ),
						esc_html( home_url( $path ) )
					)
				);
			} else {
				Cache::clear_cache();
				new Log( __( 'Clear all cache on ', 'performance-optimisation' ) );
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
			$settings = isset( $params['settings'] ) ? (array) $params['settings'] : array();

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

			$options         = get_option( 'wppo_settings', array() );
			$options[ $tab ] = $sanitized_settings;

			if ( update_option( 'wppo_settings', $options ) ) {
				Cache::clear_cache();
			}

			return $this->send_response( $options );
		}

		/**
		 * Sanitizes the settings array recursively.
		 *
		 * @param array $settings The settings array.
		 * @return array The sanitized settings array.
		 * @since 1.1.1
		 */
		private function sanitize_settings_recursively( $settings ) {
			$sanitized = array();
			foreach ( $settings as $key => $value ) {
				$safe_key = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key );

				if ( is_array( $value ) ) {
					$sanitized[ $safe_key ] = $this->sanitize_settings_recursively( $value );
				} elseif ( is_bool( $value ) ) {
					$sanitized[ $safe_key ] = (bool) $value;
				} elseif ( is_numeric( $value ) ) {
					$sanitized[ $safe_key ] = (int) $value;
				} elseif ( stripos( $safe_key, 'api_key' ) !== false || stripos( $safe_key, 'password' ) !== false ) {
					// Use wp_kses() to strip HTML tags and null bytes while preserving
					// special characters (e.g. +, /, = in base64 keys, or special chars in passwords).
					$sanitized[ $safe_key ] = wp_kses( $value, array() );
				} elseif ( stripos( $safe_key, 'url' ) !== false || stripos( $safe_key, 'cdn' ) !== false || stripos( $safe_key, 'origin' ) !== false ) {
					$sanitized[ $safe_key ] = esc_url_raw( $value );
				} elseif ( stripos( $safe_key, 'exclude' ) !== false || stripos( $safe_key, 'preload' ) !== false || stripos( $safe_key, 'delay' ) !== false || stripos( $safe_key, 'list' ) !== false ) {
					$sanitized[ $safe_key ] = sanitize_textarea_field( $value );
				} else {
					$sanitized[ $safe_key ] = sanitize_text_field( $value );
				}
			}
			return $sanitized;
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

			return new \WP_REST_Response( $data, 200 );
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

			// Validate image paths using realpath to prevent directory traversal.
			$normalized_abspath = wp_normalize_path( ABSPATH );
			foreach ( array_merge( $webp_images, $avif_images ) as $img_path ) {
				$resolved = realpath( $normalized_abspath . $img_path );
				if ( false === $resolved || 0 !== strpos( wp_normalize_path( $resolved ), $normalized_abspath ) ) {
					return $this->send_response( null, false, 400, __( 'Invalid image path provided.', 'performance-optimisation' ) );
				}
			}

			$use_action_scheduler = function_exists( 'as_enqueue_async_action' );
			$jobs_queued          = 0;

			if ( $use_action_scheduler ) {
				// Schedule background jobs via Action Scheduler.
				foreach ( $webp_images as $webp_image ) {
					$source_path = wp_normalize_path( ABSPATH . $webp_image );

					if ( file_exists( $source_path ) ) {
						as_enqueue_async_action(
							'wppo_convert_image_background',
							array(
								array(
									'source_path' => $source_path,
									'format'      => 'webp',
								),
							),
							'performance_optimisation'
						);
						++$jobs_queued;
					}
				}

				foreach ( $avif_images as $avif_image ) {
					$source_path = wp_normalize_path( ABSPATH . $avif_image );

					if ( file_exists( $source_path ) ) {
						as_enqueue_async_action(
							'wppo_convert_image_background',
							array(
								array(
									'source_path' => $source_path,
									'format'      => 'avif',
								),
							),
							'performance_optimisation'
						);
						++$jobs_queued;
					}
				}

				new Log(
					sprintf(
						/* translators: %d: Number of image jobs queued */
						__( 'Scheduled %d image optimization jobs for background processing on ', 'performance-optimisation' ),
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
			$options       = get_option( 'wppo_settings', array() );
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

			$response = Img_Converter::get_img_info();

			return $this->send_response( $response, true, 200, __( 'Images optimized successfully.', 'performance-optimisation' ) );
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
				return new \WP_REST_Response(
					array(
						'success' => false,
						'message' => __( 'Unable to initialize filesystem.', 'performance-optimisation' ),
					),
					500
				);
			}

			$wppo_dir = wp_normalize_path( WP_CONTENT_DIR . '/wppo' );

			if ( ! $wp_filesystem || ! $wp_filesystem->is_dir( $wppo_dir ) ) {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'message' => __( 'Optimized images folder does not exist.', 'performance-optimisation' ),
					),
					404
				);
			}

			if ( ! $wp_filesystem->delete( $wppo_dir, true ) ) {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'message' => __( 'Failed to delete the optimized images folder.', 'performance-optimisation' ),
					),
					500
				);
			}

			Img_Converter::clear_completed_formats();
			Cache::clear_cache();

			return new \WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Optimized images folder deleted successfully.', 'performance-optimisation' ),
				),
				200
			);
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

			// Validate that only known top-level setting keys are present.
			$allowed_keys = array(
				'file_optimisation',
				'preload_settings',
				'image_optimisation',
				'database_cleanup',
				'object_cache',
				'performance_audit',
				'core_tweaks',
				'cache_settings',
			);

			foreach ( array_keys( $data['settings'] ) as $key ) {
				if ( ! in_array( $key, $allowed_keys, true ) ) {
					return $this->send_response( null, false, 400, __( 'Invalid setting key detected.', 'performance-optimisation' ) );
				}
			}

			// Sanitize settings before saving.
			$sanitized_settings = $this->sanitize_settings_recursively( $data['settings'] );

			// Retrieve the existing settings and merge the imported settings on top,
			// so newer setting keys from future plugin versions are preserved.
			$existing_settings = get_option( 'wppo_settings', array() );
			$merged_settings   = array_replace_recursive( $existing_settings, $sanitized_settings );

			// Check if the settings are the same.
			if ( $existing_settings === $merged_settings ) {
				return $this->send_response( $existing_settings, true, 200, __( 'No changes detected, settings are already up-to-date', 'performance-optimisation' ) );
			}

			if ( ! update_option( 'wppo_settings', $merged_settings ) ) {
				return $this->send_response( null, false, 500, __( 'Failed to update settings', 'performance-optimisation' ) );
			}

			return $this->send_response( $merged_settings, true, 200, __( 'Settings updated successfully', 'performance-optimisation' ) );
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

			$valid_types = array( 'revisions', 'auto_drafts', 'trashed_posts', 'spam_comments', 'trashed_comments', 'expired_transients', 'orphan_postmeta', 'all' );

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

				new Log(
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

			$method_map = array(
				'revisions'          => 'clean_revisions',
				'auto_drafts'        => 'clean_auto_drafts',
				'trashed_posts'      => 'clean_trashed_posts',
				'spam_comments'      => 'clean_spam_comments',
				'trashed_comments'   => 'clean_trashed_comments',
				'expired_transients' => 'clean_expired_transients',
				'orphan_postmeta'    => 'clean_orphan_postmeta',
			);

			$method = $method_map[ $type ] ?? null;

			if ( ! $method ) {
				return $this->send_response( array( 'deleted' => false ), false, 400, __( 'Invalid cleanup type.', 'performance-optimisation' ) );
			}

			$result = Database_Cleanup::invoke_cleanup_method( $method );

			if ( is_wp_error( $result ) ) {
				return $this->send_response( null, false, 500, __( 'Database cleanup failed.', 'performance-optimisation' ) );
			}

			new Log(
				sprintf(
					/* translators: %1$s: Cleanup type, %2$d: Number of items */
					__( 'Database cleanup (%1$s): %2$d items removed on ', 'performance-optimisation' ),
					$type,
					(int) $result
				)
			);

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
		 * @param \WP_REST_Request $request The request object.
		 * @since 1.1.0
		 * @return \WP_REST_Response The response object.
		 */
		public function get_page_assets( \WP_REST_Request $request ) {
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
			if ( function_exists( 'as_get_scheduled_actions' ) ) {
				$pending_jobs = as_get_scheduled_actions(
					array(
						'hook'   => 'wppo_convert_image_background',
						'status' => \ActionScheduler_Store::STATUS_PENDING,
						'group'  => 'performance_optimisation',
					),
					'ARRAY_A'
				);

				$status['queued_jobs'] = count( $pending_jobs );
			} else {
				$status['queued_jobs'] = 0;
			}

			return $this->send_response( $status );
		}

		/**
		 * Handles object cache requests (status, ping, enable, disable, flush).
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
				return $this->send_response( $status );
			}

			if ( 'ping' === $action ) {
				$config = $this->build_redis_config( $params );
				$ping   = $manager->ping( $config );
				if ( is_wp_error( $ping ) ) {
					return $this->send_response( null, false, 400, __( 'Redis connection test failed.', 'performance-optimisation' ) );
				}

				return $this->send_response( array( 'success' => true ) );
			}

			if ( 'enable' === $action ) {
				$config = $this->build_redis_config( $params );
				$result = $manager->enable( $config );

				if ( is_wp_error( $result ) ) {
					return $this->send_response( null, false, 400, __( 'Failed to enable object cache.', 'performance-optimisation' ) );
				}

				new Log( __( 'Object Cache enabled.', 'performance-optimisation' ) );
				return $this->send_response( true, true, 200, __( 'Object Cache enabled successfully.', 'performance-optimisation' ) );
			}

			if ( 'disable' === $action ) {
				$result = $manager->disable();

				if ( is_wp_error( $result ) ) {
					return $this->send_response( null, false, 400, __( 'Failed to disable object cache.', 'performance-optimisation' ) );
				}

				new Log( __( 'Object Cache disabled.', 'performance-optimisation' ) );
				return $this->send_response( true, true, 200, __( 'Object Cache disabled.', 'performance-optimisation' ) );
			}

			if ( 'flush' === $action ) {
				$result = $manager->flush();
				if ( $result ) {
					new Log( __( 'Object Cache flushed.', 'performance-optimisation' ) );
					return $this->send_response( true, true, 200, __( 'Object Cache flushed.', 'performance-optimisation' ) );
				}
				return $this->send_response( null, false, 400, __( 'Failed to flush object cache.', 'performance-optimisation' ) );
			}

			return $this->send_response( null, false, 400, __( 'Invalid action.', 'performance-optimisation' ) );
		}

		/**
		 * Builds a sanitized Redis configuration array from request parameters.
		 *
		 * @param array $params Request parameters.
		 * @since 1.4.0
		 * @return array Sanitized Redis config.
		 */
		private function build_redis_config( $params ) {
			$allowed_keys = array( 'mode', 'host', 'port', 'password', 'database', 'nodes', 'master_name', 'use_tls', 'persistent', 'compression' );
			$config       = array();

			foreach ( $allowed_keys as $key ) {
				if ( ! isset( $params[ $key ] ) ) {
					continue;
				}

				$value = $params[ $key ];

				switch ( $key ) {
					case 'host':
					case 'master_name':
					case 'compression':
					case 'mode':
						$config[ $key ] = sanitize_text_field( (string) $value );
						break;
					case 'port':
					case 'database':
						$config[ $key ] = (int) $value;
						break;
					case 'password':
						$config[ $key ] = (string) $value;
						break;
					case 'use_tls':
					case 'persistent':
						$config[ $key ] = (bool) $value;
						break;
					case 'nodes':
						$config[ $key ] = $this->sanitize_nodes( $value );
						break;
				}
			}

			// Defaults for missing keys.
			$config['mode'] = $config['mode'] ?? 'standalone';
			$config['host'] = $config['host'] ?? '127.0.0.1';
			$config['port'] = $config['port'] ?? 6379;

			return $config;
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
		 * Refreshes the REST API nonce via AJAX to bypass stale X-WP-Nonce issues.
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
			require_once WPPO_PLUGIN_PATH . 'includes/class-system-info.php';
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
			require_once WPPO_PLUGIN_PATH . 'includes/class-telemetry.php';
			$params = $request->get_params();
			$url    = isset( $params['url'] ) ? esc_url_raw( $params['url'] ) : home_url( '/' );

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
			$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
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
			require_once WPPO_PLUGIN_PATH . 'includes/class-pagespeed.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-suggestion-engine.php';
			$params   = $request->get_params();
			$url      = isset( $params['url'] ) ? esc_url_raw( $params['url'] ) : home_url( '/' );
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
			$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
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

			$job_id = Pagespeed::queue_scan( $url, $strategy );

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
			require_once WPPO_PLUGIN_PATH . 'includes/class-pagespeed.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-suggestion-engine.php';
			$params   = $request->get_params();
			$url      = isset( $params['url'] ) ? esc_url_raw( $params['url'] ) : home_url( '/' );
			$strategy = isset( $params['strategy'] ) ? sanitize_text_field( $params['strategy'] ) : 'mobile';

			if ( ! in_array( $strategy, array( 'mobile', 'desktop' ), true ) ) {
				$strategy = 'mobile';
			}

			$results = Pagespeed::get_results( $url, $strategy );

			if ( false === $results ) {
				return $this->send_response( array( 'status' => 'not_ready' ), true, 202 );
			}

			// Detect failure sentinel stored by Pagespeed::store_failure().
			if ( ! empty( $results['error'] ) ) {
				return $this->send_response(
					null,
					false,
					500,
					$results['message'] ?? __( 'PageSpeed scan failed.', 'performance-optimisation' )
				);
			}

			// Append Suggestion_Engine output so the React UI gets everything in one call.
			$results['suggestions'] = Suggestion_Engine::from_pagespeed( $results );

			return $this->send_response( $results );
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
			require_once WPPO_PLUGIN_PATH . 'includes/class-telemetry.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-suggestion-engine.php';
			$params = $request->get_params();
			$url    = isset( $params['url'] ) ? esc_url_raw( $params['url'] ) : home_url( '/' );

			$transient_key = 'wppo_audit_' . md5( $url );
			$telemetry     = get_transient( $transient_key );

			if ( false === $telemetry ) {
				return $this->send_response(
					array( 'suggestions' => array() ),
					true,
					200,
					__( 'No cached scan found for this URL. Run a scan first.', 'performance-optimisation' )
				);
			}

			$suggestions = Suggestion_Engine::from_telemetry( $telemetry );

			return $this->send_response( array( 'suggestions' => $suggestions ) );
		}

		/**
		 * Returns server-level performance rules (Apache/Nginx).
		 *
		 * @param \WP_REST_Request $_request The request object (unused).
		 * @since 1.6.0
		 * @return \WP_REST_Response The response object.
		 */
		public function get_server_rules( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			require_once WPPO_PLUGIN_PATH . 'includes/class-server-rules.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-htaccess-handler.php';

			return $this->send_response(
				array(
					'server_type' => Server_Rules::get_server_type(),
					'nginx'       => Server_Rules::get_nginx_rules(),
					'apache'      => Server_Rules::get_apache_rules(),
				)
			);
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
