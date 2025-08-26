<?php
/**
 * Rest Controller
 *
 * @package PerformanceOptimisation\Core\API
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Core\API;

use PerformanceOptimisation\Services\SettingsService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RestController
 *
 * @package PerformanceOptimisation\Core\API
 */
class RestController {

	private SettingsService $settingsService;

	public function __construct( SettingsService $settingsService ) {
		$this->settingsService = $settingsService;
	}

	public function register_routes(): void {
		register_rest_route(
			'wppo/v1',
			'/settings',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_settings' ],
				'permission_callback' => [ $this, 'permissions_check' ],
			]
		);

		register_rest_route(
			'wppo/v1',
			'/settings',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'save_settings' ],
				'permission_callback' => [ $this, 'permissions_check' ],
			]
		);
	}

	public function permissions_check(): bool {
		return current_user_can( 'manage_options' );
	}

	public function get_settings(): \WP_REST_Response {
		return new \WP_REST_Response( $this->settingsService->get_settings(), 200 );
	}

	public function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$settings = $request->get_json_params();
		$this->settingsService->update_settings( $settings );
		return new \WP_REST_Response( [ 'success' => true ], 200 );
	}
}
