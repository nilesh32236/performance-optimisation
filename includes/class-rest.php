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
				'clear_cache'            => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'clear_cache' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				'update_settings'        => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				'optimise_image'         => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'optimise_image' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				'delete_optimised_image' => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'delete_optimised_image' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				'recent_activities'      => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'get_recent_activities' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				'import_settings'        => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'import_settings' ),
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
			if ( 'clear_single_page_cahce' === $params['action'] ) {
				Cache::clear_cache( $params['path'] );
				new Log(
					sprintf(
					/* translators: %s: The URL of the page */
						__( 'Clear cache of <a href="%1$s">%2$s</a> on ', 'performance-optimisation' ),
						home_url( $params['path'] ),
						home_url( $params['path'] )
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
			$params  = $request->get_params();
			$options = get_option( 'wppo_settings', array() );

			$options[ $params['tab'] ] = $params['settings'];

			if ( update_option( 'wppo_settings', $options ) ) {
				Cache::clear_cache();
			}

			return $this->send_response( $options );
		}

		/**
		 * Retrieves the recent activities.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @since 1.0.0
		 * @return \WP_REST_Response The response object.
		 */
		public function get_recent_activities( \WP_REST_Request $request ) {
			$params = $request->get_params();

			$data = Log::get_recent_activities( $params );

			return new \WP_REST_Response( $data, 200 );
		}

		/**
		 * Optimizes the images and converts them to WebP or AVIF format.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @since 1.0.0
		 * @return \WP_REST_Response The response object.
		 */
		public function optimise_image( \WP_REST_Request $request ) {
			$options       = get_option( 'wppo_settings', array() );
			$img_converter = new Img_Converter( $options );
			$params        = $request->get_params();

			$webp_images = $params['webp'] ?? array();
			$avif_images = $params['avif'] ?? array();

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

			$response = get_option( 'wppo_img_info', array() );

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

			$img_info = get_option( 'wppo_img_info', array() );

			$img_info['completed'] = array(
				'webp' => array(),
				'avif' => array(),
			);

			update_option( 'wppo_img_info', $img_info );

			if ( $wp_filesystem && $wp_filesystem->is_dir( $wppo_dir ) ) {
				if ( $wp_filesystem->delete( $wppo_dir, true ) ) {
					Cache::clear_cache();
					return new \WP_REST_Response(
						array(
							'success' => true,
							'message' => __( 'Optimized images folder deleted successfully.', 'performance-optimisation' ),
						),
						200
					);
				} else {
					return new \WP_REST_Response(
						array(
							'success' => false,
							'message' => __( 'Failed to delete the optimized images folder.', 'performance-optimisation' ),
						),
						500
					);
				}
			} else {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'message' => __( 'Optimized images folder does not exist.', 'performance-optimisation' ),
					),
					404
				);
			}
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

			if ( 'import_settings' !== $data['action'] || empty( $data['settings'] ) ) {
				return $this->send_response( null, false, 400, __( 'Invalid action or missing settings', 'performance-optimisation' ) );
			}

			// Retrieve the existing settings.
			$existing_settings = get_option( 'wppo_settings', array() );

			// Check if the settings are the same.
			if ( $existing_settings === $data['settings'] ) {
				return $this->send_response( $existing_settings, true, 200, __( 'No changes detected, settings are already up-to-date', 'performance-optimisation' ) );
			}

			if ( ! update_option( 'wppo_settings', $data['settings'] ) ) {
				return $this->send_response( null, false, 500, __( 'Failed to update settings', 'performance-optimisation' ) );
			}

			return $this->send_response( $data['settings'], true, 200, __( 'Settings updated successfully', 'performance-optimisation' ) );
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
