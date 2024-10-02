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
				'clear_cache'       => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'clear_cache' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				'update_settings'   => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				'recent_activities' => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'get_recent_activities' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
			);
		}

		public function permission_callback() {
			$nonce       = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? $_SERVER['HTTP_X_WP_NONCE'] : '';
			$nonce_valid = wp_verify_nonce( $nonce, 'wp_rest' );

			return current_user_can( 'manage_options' ) && $nonce_valid;
		}

		public function clear_cache( \WP_REST_Request $request ) {
			$params = $request->get_params();
			if ( 'clear_single_page_cahce' === $params['action'] ) {
				Cache::clear_cache( $params['id'] );
				new Log( 'Clear cache of ' . get_permalink( $params['id'] ) . ' on ' );
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
			update_option( 'qtpo_settings', $options );
			return $this->send_response( $options );
		}

		public function get_recent_activities( \WP_REST_Request $request ) {
			$params = $request->get_params();

			$data = Log::get_recent_activities( $params );

			return new \WP_REST_Response( $data, 200 );
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
	}
}
