<?php

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Rest' ) ) {
	class Rest {

		const NAMESPACE = 'performance-optimisation/v1';

		public function register_routes() {
			$routes = $this->get_routes();

			foreach ( $routes as $route => $route_data ) {
				register_rest_route( self::NAMESPACE, $route, $route_data );
			}
		}

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

		public function permission_callback() {
			$nonce       = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ) : '';
			$nonce_valid = wp_verify_nonce( $nonce, 'wp_rest' );

			return current_user_can( 'manage_options' ) && $nonce_valid;
		}

		public function clear_cache( \WP_REST_Request $request ) {
			$params = $request->get_params();
			if ( 'clear_single_page_cahce' === $params['action'] ) {
				Cache::clear_cache( $params['path'] );
				new Log( 'Clear cache of <a href="' . home_url( $params['path'] ) . '">' . home_url( $params['path'] ) . '</a> on ' );
			} else {
				Cache::clear_cache();
				new Log( 'Clear all cache on ' );
			}
			return $this->send_response( true );
		}

		public function update_settings( \WP_REST_Request $request ) {
			$params  = $request->get_params();
			$options = get_option( 'qtpo_settings', array() );

			$options[ $params['tab'] ] = $params['settings'];

			if ( update_option( 'qtpo_settings', $options ) ) {
				Cache::clear_cache();
			}

			return $this->send_response( $options );
		}

		public function get_recent_activities( \WP_REST_Request $request ) {
			$params = $request->get_params();

			$data = Log::get_recent_activities( $params );

			return new \WP_REST_Response( $data, 200 );
		}

		public function optimise_image( \WP_REST_Request $request ) {
			$options       = get_option( 'qtpo_settings', array() );
			$img_converter = new Img_Converter( $options );
			$params        = $request->get_params();

			$webp_images = $params['webp'] ?? array();
			$avif_images = $params['avif'] ?? array();

			foreach ( $webp_images as $webp_image ) {
				$source_path = ABSPATH . $webp_image;

				if ( file_exists( $source_path ) ) {
					$img_converter->convert_image( $source_path, 'webp' );
				}
			}

			foreach ( $avif_images as $avif_image ) {
				$source_path = ABSPATH . $avif_image;

				if ( file_exists( $source_path ) ) {
					$img_converter->convert_image( $source_path, 'avif' );
				}
			}

			Cache::clear_cache();

			$response = get_option( 'qtpo_img_info', array() );

			return new \WP_REST_Response( $response, 200 );
		}

		public function delete_optimised_image() {
			global $wp_filesystem;
			if ( ! Util::init_filesystem() ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				new \WP_Filesystem_Direct( null );
			}

			$qtpo_dir = WP_CONTENT_DIR . '/qtpo';

			$img_info = get_option( 'qtpo_img_info', array() );

			$img_info['completed'] = array(
				'webp' => array(),
				'avif' => array(),
			);

			update_option( 'qtpo_img_info', $img_info );

			if ( $wp_filesystem && $wp_filesystem->is_dir( $qtpo_dir ) ) {
				if ( $wp_filesystem->delete( $qtpo_dir, true ) ) {
					Cache::clear_cache();
					return new \WP_REST_Response(
						array(
							'success' => true,
							'message' => 'Optimized images folder deleted successfully.',
						),
						200
					);
				}
			} else {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'message' => 'Optimized images folder does not exist.',
					),
					404
				);
			}
		}

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

		public function import_settings( \WP_REST_Request $request ) {
			$data = $request->get_json_params();

			if ( 'import_settings' !== $data['action'] || empty( $data['settings'] ) ) {
				return $this->send_response( null, false, 400, 'Invalid action or missing settings' );
			}

			// Retrieve the existing settings
			$existing_settings = get_option( 'qtpo_settings', array() );

			// Check if the settings are the same
			if ( $existing_settings === $data['settings'] ) {
				return $this->send_response( $existing_settings, true, 200, 'No changes detected, settings are already up-to-date' );
			}

			if ( ! update_option( 'qtpo_settings', $data['settings'] ) ) {
				return $this->send_response( null, false, 500, 'Failed to update settings' );
			}

			return $this->send_response( $data['settings'], true, 200, 'Settings updated successfully' );
		}
	}
}
