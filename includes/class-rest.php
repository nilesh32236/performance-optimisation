<?php
/**
 * PerformanceOptimise\Inc\Rest
 *
 * This class registers and manages the REST API routes related to performance optimization
 * functionalities, such as clearing the cache, optimizing images, updating settings, and more.
 * It provides endpoints for interacting with the plugin's features programmatically.
 *
 * @since 1.0.0
 * @package PerformanceOptimise
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
		 * Returns the routes for the REST API.
		 *
		 * @since 1.0.0
		 * @return array Registered routes.
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
				'get_nonce'               => array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_nonce' ),
					'permission_callback' => function () {
						return is_user_logged_in();
					},
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
				} else {
					$sanitized[ $safe_key ] = sanitize_textarea_field( $value );
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

			// Reject image paths if they contain directory traversal sequences.
			foreach ( array_merge( $webp_images, $avif_images ) as $img_path ) {
				if ( strpos( $img_path, '..' ) !== false ) {
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

			return new \WP_REST_Response( $response, 200 );
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
				require_once wp_normalize_path( ABSPATH . 'wp-admin/includes/file.php' );
				new \WP_Filesystem_Direct( null );
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

			if ( ! is_array( $data ) || ! isset( $data['action'] ) || 'import_settings' !== $data['action'] || empty( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
				return $this->send_response( null, false, 400, __( 'Invalid action or missing settings', 'performance-optimisation' ) );
			}

			// Sanitize settings before saving.
			$sanitized_settings = $this->sanitize_settings_recursively( $data['settings'] );

			// Retrieve the existing settings.
			$existing_settings = get_option( 'wppo_settings', array() );

			// Check if the settings are the same.
			if ( $existing_settings === $sanitized_settings ) {
				return $this->send_response( $existing_settings, true, 200, __( 'No changes detected, settings are already up-to-date', 'performance-optimisation' ) );
			}

			if ( ! update_option( 'wppo_settings', $sanitized_settings ) ) {
				return $this->send_response( null, false, 500, __( 'Failed to update settings', 'performance-optimisation' ) );
			}

			return $this->send_response( $sanitized_settings, true, 200, __( 'Settings updated successfully', 'performance-optimisation' ) );
		}

		/**
		 * Handles database cleanup requests.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @since 1.1.0
		 * @return \WP_REST_Response The response object.
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
						$failures[ $key ] = $value->get_error_message();
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

			$method  = $method_map[ $type ];
			$deleted = Database_Cleanup::invoke_cleanup_method( $method );

			if ( is_wp_error( $deleted ) ) {
				return $this->send_response( null, false, 500, $deleted->get_error_message() );
			}

			new Log(
				sprintf(
					/* translators: %1$s: Cleanup type, %2$d: Number of items */
					__( 'Database cleanup (%1$s): %2$d items removed on ', 'performance-optimisation' ),
					$type,
					(int) $deleted
				)
			);

			return $this->send_response(
				array(
					'type'    => $type,
					'deleted' => (int) $deleted,
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
		 * @since 1.3.0
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
					return $this->send_response( null, false, 400, $ping->get_error_message() );
				}

				return $this->send_response( array( 'success' => true ) );
			}

			if ( 'enable' === $action ) {
				$config = $this->build_redis_config( $params );
				$result = $manager->enable( $config );

				if ( is_wp_error( $result ) ) {
					return $this->send_response( null, false, 400, $result->get_error_message() );
				}

				new Log( __( 'Object Cache enabled.', 'performance-optimisation' ) );
				return $this->send_response( true, true, 200, __( 'Object Cache enabled successfully.', 'performance-optimisation' ) );
			}

			if ( 'disable' === $action ) {
				$result = $manager->disable();

				if ( is_wp_error( $result ) ) {
					return $this->send_response( null, false, 400, $result->get_error_message() );
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
		 * Sanitizes the nodes parameter.
		 *
		 * @param mixed $nodes The nodes to sanitize.
		 * @return array|string Sanitized nodes.
		 */
		private function sanitize_nodes( $nodes ) {
			if ( is_array( $nodes ) ) {
				return array_values( array_filter( array_map( 'sanitize_text_field', $nodes ) ) );
			}
			$nodes = sanitize_text_field( (string) $nodes );
			return $nodes ? array( $nodes ) : array();
		}

		/**
		 * Returns a fresh REST API nonce for the frontend.
		 *
		 * @since 1.4.0
		 * @return \WP_REST_Response The response object.
		 */
		public function get_nonce() {
			return $this->send_response(
				array(
					'nonce' => wp_create_nonce( 'wp_rest' ),
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
