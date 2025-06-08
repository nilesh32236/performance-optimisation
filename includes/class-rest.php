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

// Exit if accessed directly.
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
		 * REST API Namespace.
		 *
		 * @var string
		 */
		const NAMESPACE = 'performance-optimisation/v1';

		/**
		 * Registers the REST API routes.
		 *
		 * @since 1.0.0
		 */
		public function register_routes(): void {
			$routes = $this->get_routes_configuration();

			foreach ( $routes as $route => $route_config ) {
				register_rest_route( self::NAMESPACE, $route, $route_config );
			}
		}

		/**
		 * Returns the configuration for all REST API routes.
		 *
		 * @since 1.0.0
		 * @return array<string, array<string, mixed>> Registered routes configuration.
		 */
		private function get_routes_configuration(): array {
			return array(
				'/clear-cache'             => array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_clear_cache_request' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
					'args'                => array(
						'action' => array(
							'required'    => true,
							'type'        => 'string',
							'description' => 'Type of cache clear action (e.g., "all", "page").',
							'enum'        => array( 'all', 'page' ),
						),
						'path'   => array(
							'required'    => false,
							'type'        => 'string',
							'description' => 'URL path of the page to clear cache for, if action is "page".',
						),
					),
				),
				'/settings'                => array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'handle_update_settings_request' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
					'args'                => array(
						'tab'      => array(
							'required'    => true,
							'type'        => 'string',
							'description' => 'The settings tab being updated.',
						),
						'settings' => array(
							'required'    => true,
							'type'        => 'object', // Or 'array' if your settings are flat.
							'description' => 'The settings data for the specified tab.',
						),
					),
				),
				'/optimise-images'         => array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_optimise_images_request' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
				),
				'/delete-optimised-images' => array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_delete_optimised_images_request' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
				),
				'/recent-activities'       => array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_get_recent_activities_request' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
					'args'                => array(
						'page'     => array(
							'required'          => false,
							'type'              => 'integer',
							'default'           => 1,
							'description'       => 'Page number for pagination.',
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'required'          => false,
							'type'              => 'integer',
							'default'           => 10,
							'description'       => 'Number of items per page.',
							'sanitize_callback' => 'absint',
						),
					),
				),
				'/import-settings'         => array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_import_settings_request' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
					'args'                => array(
						'settings_json' => array(
							'required'    => true,
							'type'        => 'string',
							'description' => 'JSON string of settings to import.',
						),
					),
				),
			);
		}

		/**
		 * Permission callback: Checks if the current user has 'manage_options' capability.
		 * Also verifies the nonce sent with the request.
		 *
		 * @since 1.0.0
		 * @param \WP_REST_Request $request The REST API request.
		 * @return bool|\WP_Error True if permission is granted, WP_Error otherwise.
		 */
		public function check_admin_permissions( \WP_REST_Request $request ): bool|\WP_Error {
			if ( ! current_user_can( 'manage_options' ) ) {
				return new \WP_Error(
					'rest_forbidden_context',
					__( 'Sorry, you are not allowed to manage these options.', 'performance-optimisation' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}
			return true;
		}

		/**
		 * Handles request to clear cache.
		 *
		 * @since 1.0.0
		 * @param \WP_REST_Request $request The REST API request.
		 * @return \WP_REST_Response The response object.
		 */
		public function handle_clear_cache_request( \WP_REST_Request $request ): \WP_REST_Response {
			$action = $request->get_param( 'action' );
			$path   = $request->get_param( 'path' );

			if ( 'page' === $action && ! empty( $path ) ) {
				Cache::clear_cache( sanitize_text_field( $path ) );
				$page_url = home_url( sanitize_text_field( $path ) );
				// translators: %s is the URL of the page.
				new Log( sprintf( __( 'Cache cleared for page: %s on', 'performance-optimisation' ), esc_url( $page_url ) ) . ' ' . current_time( 'mysql' ) );
				$message = __( 'Cache for the specified page cleared successfully.', 'performance-optimisation' );
			} else {
				Cache::clear_cache(); // Clear all cache.
				new Log( __( 'All cache cleared on', 'performance-optimisation' ) . ' ' . current_time( 'mysql' ) );
				$message = __( 'All cache cleared successfully.', 'performance-optimisation' );
			}
			return $this->send_success_response( array( 'message' => $message ) );
		}

		/**
		 * Handles request to update plugin settings.
		 *
		 * @since 1.0.0
		 * @param \WP_REST_Request $request The REST API request.
		 * @return \WP_REST_Response The response object.
		 */
		public function handle_update_settings_request( \WP_REST_Request $request ): \WP_REST_Response {
			$tab_key       = $request->get_param( 'tab' );
			$settings_data = $request->get_param( 'settings' ); // This should be an array/object.

			if ( empty( $tab_key ) || ! is_array( $settings_data ) ) {
				return $this->send_error_response( 'invalid_parameters', __( 'Invalid parameters for updating settings.', 'performance-optimisation' ), 400 );
			}

			$current_options  = get_option( 'wppo_settings', array() );
			$old_tab_settings = $current_options[ $tab_key ] ?? array();

			$updated_options             = $current_options;
			$updated_options[ $tab_key ] = $settings_data; // Replace with sanitized data.

			$cron_setting_changed = false;
			if ( 'preload_settings' === $tab_key ) {
				$old_cron_enabled = $old_tab_settings['enableCronJobs'] ?? true;
				$new_cron_enabled = $settings_data['enableCronJobs'] ?? true;
				if ( $old_cron_enabled !== $new_cron_enabled ) {
					$cron_setting_changed = true;
				}
			}

			if ( update_option( 'wppo_settings', $updated_options ) ) {
				Cache::clear_cache();

				if ( $cron_setting_changed ) {
					if ( ! class_exists( 'PerformanceOptimise\Inc\Cron' ) ) {
						require_once WPPO_PLUGIN_PATH . 'includes/class-cron.php';
					}
					if ( ! $new_cron_enabled ) {
						Cron::clear_all_plugin_cron_jobs();
						new Log( __( 'Plugin cron jobs disabled and cleared on', 'performance-optimisation' ) . ' ' . current_time( 'mysql' ) );
					} else {
						$cron_manager = new Cron();
						$cron_manager->schedule_cron_jobs();
						new Log( __( 'Plugin cron jobs enabled and (re)scheduled on', 'performance-optimisation' ) . ' ' . current_time( 'mysql' ) );
					}
				}
				// translators: %s is the settings tab name.
				new Log( sprintf( __( 'Settings for tab "%s" updated on', 'performance-optimisation' ), esc_html( $tab_key ) ) . ' ' . current_time( 'mysql' ) );
				return $this->send_success_response(
					array(
						'message'      => __( 'Settings updated successfully.', 'performance-optimisation' ),
						'new_settings' => $updated_options,
					)
				);
			} elseif ( $current_options === $updated_options ) {
				return $this->send_success_response(
					array(
						'message'      => __( 'No changes detected in settings.', 'performance-optimisation' ),
						'new_settings' => $updated_options,
					)
				);
			} else {
				return $this->send_error_response( 'update_failed', __( 'Failed to update settings in the database.', 'performance-optimisation' ), 500 );
			}
		}

		/**
		 * Handles request to get recent activities.
		 *
		 * @since 1.0.0
		 * @param \WP_REST_Request $request The REST API request.
		 * @return \WP_REST_Response The response object.
		 */
		public function handle_get_recent_activities_request( \WP_REST_Request $request ): \WP_REST_Response {
			$params = array(
				'page'     => $request->get_param( 'page' ),
				'per_page' => $request->get_param( 'per_page' ),
			);
			$data   = Log::get_recent_activities( $params );
			return $this->send_success_response( $data );
		}

		/**
		 * Handles request to trigger image optimization for pending images.
		 *
		 * @since 1.0.0
		 * @param \WP_REST_Request $request The REST API request.
		 * @return \WP_REST_Response The response object.
		 */
		public function handle_optimise_images_request( \WP_REST_Request $request ): \WP_REST_Response {
			$options = get_option( 'wppo_settings', array() );
			if ( empty( $options['image_optimisation']['convertImg'] ) ) {
				return $this->send_error_response( 'feature_disabled', __( 'Image conversion feature is disabled.', 'performance-optimisation' ), 400 );
			}

			if ( ! class_exists( 'PerformanceOptimise\Inc\Img_Converter' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-img-converter.php';
			}
			if ( ! class_exists( 'PerformanceOptimise\Inc\Cron' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-cron.php';
			}

			$cron_manager = new Cron();
			$cron_manager->run_image_conversion_tasks();

			$updated_img_info = get_option( 'wppo_img_info', array() );
			$message          = __( 'Image optimization batch process initiated. Check activity log or refresh status.', 'performance-optimisation' );
			new Log( __( 'Manual image optimization batch triggered on', 'performance-optimisation' ) . ' ' . current_time( 'mysql' ) );

			return $this->send_success_response(
				array(
					'message'   => $message,
					'imageInfo' => $updated_img_info,
				)
			);
		}

		/**
		 * Handles request to delete all optimized (converted) images.
		 *
		 * @since 1.0.0
		 * @return \WP_REST_Response The response object.
		 */
		public function handle_delete_optimised_images_request(): \WP_REST_Response {
			$wp_filesystem = Util::init_filesystem();
			if ( ! $wp_filesystem ) {
				return $this->send_error_response( 'filesystem_error', __( 'Filesystem could not be initialized.', 'performance-optimisation' ), 500 );
			}

			$wppo_converted_images_dir = wp_normalize_path( WP_CONTENT_DIR . '/wppo' ); // Main directory for converted images.

			$deleted_successfully = false;
			if ( $wp_filesystem->is_dir( $wppo_converted_images_dir ) ) {
				$deleted_successfully = $wp_filesystem->delete( $wppo_converted_images_dir, true ); // Recursive delete.
			} else {
				$deleted_successfully = true;
			}

			if ( $deleted_successfully ) {
				$img_info              = get_option( 'wppo_img_info', array() );
				$img_info['completed'] = array(
					'webp' => array(),
					'avif' => array(),
				);
				$img_info['pending']   = array(
					'webp' => array(),
					'avif' => array(),
				);
				$img_info['failed']    = array(
					'webp' => array(),
					'avif' => array(),
				);
				$img_info['skipped']   = array(
					'webp' => array(),
					'avif' => array(),
				);

				update_option( 'wppo_img_info', $img_info );

				Cache::clear_cache();
				new Log( __( 'All converted images deleted and statuses reset on', 'performance-optimisation' ) . ' ' . current_time( 'mysql' ) );
				return $this->send_success_response(
					array(
						'message'   => __( 'All converted images deleted successfully.', 'performance-optimisation' ),
						'imageInfo' => $img_info,
					)
				);
			} else {
				new Log( __( 'Failed to delete converted images directory on', 'performance-optimisation' ) . ' ' . current_time( 'mysql' ) );
				return $this->send_error_response( 'delete_failed', __( 'Failed to delete the converted images directory.', 'performance-optimisation' ), 500 );
			}
		}

		/**
		 * Handles request to import plugin settings from a JSON string.
		 *
		 * @since 1.0.0
		 * @param \WP_REST_Request $request The REST API request.
		 * @return \WP_REST_Response The response object.
		 */
		public function handle_import_settings_request( \WP_REST_Request $request ): \WP_REST_Response {
			$settings_json_string = $request->get_param( 'settings_json' );
			if ( empty( $settings_json_string ) ) {
				return $this->send_error_response( 'missing_data', __( 'No settings data provided for import.', 'performance-optimisation' ), 400 );
			}

			$imported_settings = json_decode( $settings_json_string, true );
			if ( null === $imported_settings || json_last_error() !== JSON_ERROR_NONE ) {
				return $this->send_error_response( 'invalid_json', __( 'Invalid JSON format for settings import.', 'performance-optimisation' ), 400 );
			}

			if ( ! is_array( $imported_settings ) ) {
				return $this->send_error_response( 'invalid_settings_structure', __( 'Imported settings data has an invalid structure.', 'performance-optimisation' ), 400 );
			}

			$current_options      = get_option( 'wppo_settings', array() );
			$old_cron_enabled     = $current_options['preload_settings']['enableCronJobs'] ?? true;
			$new_cron_enabled     = $imported_settings['preload_settings']['enableCronJobs'] ?? true;
			$cron_setting_changed = ( $old_cron_enabled !== $new_cron_enabled );

			if ( update_option( 'wppo_settings', $imported_settings ) ) {
				Cache::clear_cache();

				if ( $cron_setting_changed ) {
					if ( ! class_exists( 'PerformanceOptimise\Inc\Cron' ) ) {
						require_once WPPO_PLUGIN_PATH . 'includes/class-cron.php';
					}
					if ( ! $new_cron_enabled ) {
						Cron::clear_all_plugin_cron_jobs();
						new Log( __( 'Plugin cron jobs disabled due to settings import on', 'performance-optimisation' ) . ' ' . current_time( 'mysql' ) );
					} else {
						$cron_manager = new Cron();
						$cron_manager->schedule_cron_jobs();
						new Log( __( 'Plugin cron jobs (re)scheduled due to settings import on', 'performance-optimisation' ) . ' ' . current_time( 'mysql' ) );
					}
				}
				new Log( __( 'Settings imported successfully on', 'performance-optimisation' ) . ' ' . current_time( 'mysql' ) );
				return $this->send_success_response(
					array(
						'message'      => __( 'Settings imported successfully.', 'performance-optimisation' ),
						'new_settings' => $imported_settings,
					)
				);
			} elseif ( $current_options === $imported_settings ) {
				return $this->send_success_response(
					array(
						'message'      => __( 'Imported settings are identical to current settings. No changes made.', 'performance-optimisation' ),
						'new_settings' => $imported_settings,
					)
				);
			} else {
				return $this->send_error_response( 'import_failed_db', __( 'Failed to save imported settings to the database.', 'performance-optimisation' ), 500 );
			}
		}


		/**
		 * Helper to send a standardized success REST API response.
		 *
		 * @since 1.0.0
		 * @param mixed $data Data to include in the response.
		 * @param int   $status_code HTTP status code. Default 200.
		 * @return \WP_REST_Response The response object.
		 */
		private function send_success_response( $data, int $status_code = 200 ): \WP_REST_Response {
			return new \WP_REST_Response(
				array(
					'success' => true,
					'data'    => $data,
				),
				$status_code
			);
		}

		/**
		 * Helper to send a standardized error REST API response.
		 *
		 * @since 1.0.0
		 * @param string $error_code  Machine-readable error code.
		 * @param string $message     Human-readable error message.
		 * @param int    $status_code HTTP status code.
		 * @return \WP_REST_Response The response object.
		 */
		private function send_error_response( string $error_code, string $message, int $status_code ): \WP_REST_Response {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'code'    => $error_code,
					'message' => $message,
					'data'    => array( 'status' => $status_code ),
				),
				$status_code
			);
		}
	}
}
