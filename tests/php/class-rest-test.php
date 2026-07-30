<?php
/**
 * Tests for Rest class.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Rest;
use Brain\Monkey\Functions;

/**
 * Tests for the REST API endpoints registration and permission callbacks.
 *
 * @package PerformanceOptimise\Tests
 */
class RestTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * REST API handler instance.
	 *
	 * @var Rest
	 */
	private Rest $rest;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();

		Functions\stubs(
			array(
				'wp_normalize_path',
				'sanitize_text_field',
				'wp_unslash',
				'trailingslashit',
				'register_rest_route',
			)
		);
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'trailingslashit' )->returnArg();

		$this->rest = new Rest();
	}

	/**
	 * Test that the REST API namespace is correct.
	 */
	public function test_namespace_is_correct(): void {
		$this->assertSame( 'performance-optimisation/v1', Rest::NAMESPACE );
	}

	/**
	 * Test that register_routes calls register_rest_route.
	 */
	public function test_register_routes_calls_register_rest_route(): void {
		Functions\expect( 'register_rest_route' )
			->atLeast()
			->once()
			->with( 'performance-optimisation/v1', \Brain\Monkey\Anything(), \Brain\Monkey\Anything() );

		$this->rest->register_routes();
	}

	/**
	 * Test that permission_callback allows authorized users.
	 */
	public function test_permission_callback_checks_capability(): void {
		$_SERVER['HTTP_X_WP_NONCE'] = 'test_nonce';

		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );

		$result = $this->rest->permission_callback();
		$this->assertTrue( $result );
	}

	/**
	 * Test that permission_callback rejects unauthorized users.
	 */
	public function test_permission_callback_rejects_unauthorized(): void {
		$_SERVER['HTTP_X_WP_NONCE'] = 'test_nonce';

		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( false );

		$result = $this->rest->permission_callback();
		$this->assertFalse( $result );
	}

	/**
	 * Test that get_routes contains all expected endpoints.
	 */
	public function test_get_routes_contains_expected_endpoints(): void {
		$reflection = new ReflectionMethod( $this->rest, 'get_routes' );
		$reflection->setAccessible( true );
		$routes = $reflection->invoke( $this->rest );

		$expected = array(
			'clear_cache',
			'update_settings',
			'optimise_image',
			'delete_optimised_image',
			'recent_activities',
			'import_settings',
			'database_cleanup',
			'database_cleanup_counts',
			'object_cache',
			'system_info',
			'performance_scan',
			'pagespeed_scan',
			'pagespeed_results',
			'suggestions',
			'server_rules',
			'used_css_regenerate',
			'regenerate_ccss',
			'ccss_status',
			'dismiss_welcome',
		);

		foreach ( $expected as $route ) {
			$this->assertArrayHasKey( $route, $routes, "Missing route: {$route}" );
		}
	}

	/**
	 * Test that each route has a permission callback.
	 */
	public function test_each_route_has_permission_callback(): void {
		$reflection = new ReflectionMethod( $this->rest, 'get_routes' );
		$reflection->setAccessible( true );
		$routes = $reflection->invoke( $this->rest );

		foreach ( $routes as $route => $config ) {
			if ( is_array( $config ) ) {
				$configs = isset( $config[0] ) ? $config : array( $config );
				foreach ( $configs as $cfg ) {
					$this->assertArrayHasKey( 'permission_callback', $cfg, "Route {$route} missing permission_callback" );
					$this->assertSame( array( $this->rest, 'permission_callback' ), $cfg['permission_callback'] );
				}
			}
		}
	}
}
