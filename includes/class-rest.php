<?php

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Rest' ) ) {
	class Rest {

		const NAMESPACE = 'performance-monitor/v1';

		public function __construct() {
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		}

		public function register_routes() {
			$routes = $this->get_routes();

			foreach ( $routes as $route => $route_data ) {
				register_rest_route( self::NAMESPACE, $route, $route_data );
			}
		}

		private function get_routes() {
			return array(
				'clear_cache' => array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'clear_cache' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
			);
		}

		public function permission_callback() {
			return current_user_can( 'manage_options' );
		}

		public function clear_cache() {
			return $this->send_response( true );
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
