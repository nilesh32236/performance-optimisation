<?php
/**
 * Tests for Rest class.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Rest;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

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
				'__',
				'esc_html__',
			)
		);
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'trailingslashit' )->returnArg();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );

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
			->with( 'performance-optimisation/v1', \Mockery::any(), \Mockery::any() );

		$this->rest->register_routes();

		// The call-count expectation above is verified by Mockery on teardown.
		$this->addToAssertionCount( 1 );
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
			'get_page_assets',
			'image_job_status',
			'object_cache',
			'system_info',
			'performance_scan',
			'pagespeed_scan',
			'pagespeed_results',
			'web_vitals_trends',
			'suggestions',
			'server_rules',
			'used_css_regenerate',
			'regenerate_ccss',
			'ccss_status',
			'dismiss_welcome',
			'rum_collect',
			'rum_data',
			'autoloaded_options',
		);

		// Keep in sync with the AGENTS.md endpoint count (25).
		$this->assertCount( 25, $routes, 'REST route count drifted from the documented endpoint count' );

		foreach ( $expected as $route ) {
			$this->assertArrayHasKey( $route, $routes, "Missing route: {$route}" );
		}
	}

	/**
	 * Test that a strategy-only web_vitals_trends request filters by strategy
	 * even when no url parameter is supplied.
	 */
	public function test_web_vitals_trends_filters_by_strategy_only(): void {
		$trends = array(
			md5( 'http://example.com/' ) . '_mobile'       => array(
				array(
					'fetched_at'  => '2026-08-01',
					'performance' => 70,
				),
			),
			md5( 'http://example.com/' ) . '_desktop'      => array(
				array(
					'fetched_at'  => '2026-08-01',
					'performance' => 85,
				),
			),
			md5( 'http://example.com/about/' ) . '_mobile' => array(
				array(
					'fetched_at'  => '2026-08-01',
					'performance' => 60,
				),
			),
		);

		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( $trends ) {
				return 'wppo_web_vitals_trends' === $name ? $trends : $fallback;
			}
		);

		$request = new WP_REST_Request( array( 'strategy' => 'desktop' ) );

		$response = $this->rest->get_web_vitals_trends( $request );

		$data = $response->get_data()['data'];
		$keys = array_keys( $data['trends'] );
		$this->assertCount( 1, $keys );
		$this->assertStringEndsWith( '_desktop', $keys[0] );
	}

	/**
	 * Test that the core_tweaks tab is rejected by update_settings.
	 *
	 * Core-tweak keys live under file_optimisation; accepting a separate
	 * core_tweaks tab is dead config that nothing reads.
	 */
	public function test_update_settings_rejects_core_tweaks_tab(): void {
		$request = new WP_REST_Request(
			array(
				'tab'      => 'core_tweaks',
				'settings' => array( 'disableEmojis' => true ),
			)
		);

		$response = $this->rest->update_settings( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Test that a core_tweaks key is rejected by import_settings.
	 */
	public function test_import_settings_rejects_core_tweaks_key(): void {
		$request = new WP_REST_Request(
			array(
				'action'   => 'import_settings',
				'settings' => array( 'core_tweaks' => array( 'disableEmojis' => true ) ),
			)
		);

		$response = $this->rest->import_settings( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Test that sanitize_settings_recursively skips keys that become empty
	 * after sanitization (e.g. keys made only of non a-zA-Z0-9_- characters
	 * or empty keys) so they are never stored under an empty-string key.
	 */
	public function test_sanitize_settings_recursively_skips_empty_keys(): void {
		$reflection = new ReflectionMethod( $this->rest, 'sanitize_settings_recursively' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke(
			$this->rest,
			array(
				'@@@'        => 'x',
				'normal_key' => 'value',
				'nested'     => array(
					'!!' => 'y',
				),
			)
		);

		$this->assertArrayNotHasKey( '', $result, 'Empty-string key must not be present at the top level' );
		$this->assertArrayNotHasKey( '', $result['nested'], 'Empty-string key must not be present in nested arrays' );
		$this->assertArrayHasKey( 'normal_key', $result );
		$this->assertSame( 'value', $result['normal_key'] );
		$this->assertSame( array(), $result['nested'] );
	}

	/**
	 * Test that update_settings sanitizes markup injected into exclude/delay
	 * fields and coerces numeric strings supplied for string settings.
	 *
	 * Exercises the shared Util::sanitize_settings_recursively() pipeline so
	 * REST and WP-CLI entry points cannot store unsanitized values.
	 */
	public function test_update_settings_sanitizes_xss_and_types_numeric_strings(): void {
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->alias(
			static fn( $value ): string => trim( preg_replace( '/<[^>]*>/', '', (string) $value ) )
		);
		Functions\when( 'get_option' )->justReturn( array() );

		$captured = array();
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$captured ) {
				$captured = $value;
				return true;
			}
		);

		$request = new WP_REST_Request(
			array(
				'tab'      => 'file_optimisation',
				'settings' => array(
					'excludeCSS' => '<script>alert(1)</script>/wp-admin',
					'delayJS'    => '<img src=x onerror=alert(1)>321',
					'minifyHTML' => '123',
				),
			)
		);

		$response = $this->rest->update_settings( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'alert(1)/wp-admin', $captured['file_optimisation']['excludeCSS'], 'Markup must be stripped from exclude fields' );
		$this->assertSame( '321', $captured['file_optimisation']['delayJS'], 'Markup must be stripped from delay fields' );
		$this->assertSame( 123, $captured['file_optimisation']['minifyHTML'], 'Numeric strings must be typed to int' );
	}

	/**
	 * Test that import_settings sanitizes markup injected into exclude/delay
	 * fields and coerces numeric strings supplied for string settings.
	 */
	public function test_import_settings_sanitizes_xss_and_types_numeric_strings(): void {
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->alias(
			static fn( $value ): string => trim( preg_replace( '/<[^>]*>/', '', (string) $value ) )
		);
		Functions\when( 'get_option' )->justReturn( array() );

		$captured = array();
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$captured ) {
				$captured = $value;
				return true;
			}
		);

		$request = new WP_REST_Request(
			array(
				'action'   => 'import_settings',
				'settings' => array(
					'file_optimisation' => array(
						'excludeCSS' => '<script>alert(1)</script>/wp-admin',
						'delayJS'    => '<img src=x onerror=alert(1)>321',
						'minifyHTML' => '123',
					),
				),
			)
		);

		$response = $this->rest->import_settings( $request );

		$this->assertSame( 200, $response->get_status() );
		$file_tab = $captured['file_optimisation'];
		$this->assertSame( 'alert(1)/wp-admin', $file_tab['excludeCSS'], 'Markup must be stripped from exclude fields' );
		$this->assertSame( '321', $file_tab['delayJS'], 'Markup must be stripped from delay fields' );
		$this->assertSame( 123, $file_tab['minifyHTML'], 'Numeric strings must be typed to int' );
	}

	/**
	 * Test that build_redis_config passes a request-supplied password through
	 * (sanitized) when the WPPO_REDIS_PASSWORD constant is not defined.
	 */
	public function test_build_redis_config_passes_password_through_without_constant(): void {
		if ( defined( 'WPPO_REDIS_PASSWORD' ) ) {
			$this->markTestSkipped( 'WPPO_REDIS_PASSWORD is already defined in this environment.' );
		}

		Functions\when( 'sanitize_text_field' )->alias(
			static fn( $value ): string => trim( preg_replace( '/<[^>]*>/', '', (string) $value ) )
		);

		$reflection = new ReflectionMethod( $this->rest, 'build_redis_config' );
		$reflection->setAccessible( true );

		$config = $reflection->invoke( $this->rest, array( 'password' => ' <b>s3cret</b> ' ) );

		$this->assertSame( 's3cret', $config['password'] );
	}

	/**
	 * Test that a request-supplied Redis password is dropped when the
	 * WPPO_REDIS_PASSWORD constant is defined: the constant takes precedence,
	 * with the wppo_redis_allow_request_password escape hatch consulted first.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_build_redis_config_drops_request_password_when_constant_defined(): void {
		define( 'WPPO_REDIS_PASSWORD', 'constant-secret' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals

		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wppo_redis_allow_request_password', false )
			->andReturn( false );

		$reflection = new ReflectionMethod( $this->rest, 'build_redis_config' );
		$reflection->setAccessible( true );

		$config = $reflection->invoke( $this->rest, array( 'password' => 'request-secret' ) );

		$this->assertSame( '', $config['password'], 'Request password must be dropped when WPPO_REDIS_PASSWORD is defined' );
	}

	/**
	 * Test that the wppo_redis_allow_request_password escape-hatch filter lets
	 * a request-supplied password win even when WPPO_REDIS_PASSWORD is defined.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_build_redis_config_allows_request_password_via_filter(): void {
		define( 'WPPO_REDIS_PASSWORD', 'constant-secret' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals

		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wppo_redis_allow_request_password', false )
			->andReturn( true );

		$reflection = new ReflectionMethod( $this->rest, 'build_redis_config' );
		$reflection->setAccessible( true );

		$config = $reflection->invoke( $this->rest, array( 'password' => 'request-secret' ) );

		$this->assertSame( 'request-secret', $config['password'], 'Escape-hatch filter must let the request password win' );
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
					if ( '__return_true' === $cfg['permission_callback'] ) {
						// Public routes (e.g. the RUM beacon) intentionally bypass auth.
						$this->addToAssertionCount( 1 );
						continue;
					}
					$this->assertSame( array( $this->rest, 'permission_callback' ), $cfg['permission_callback'] );
				}
			}
		}
	}

	/**
	 * Test that clearing all cache succeeds even when the cache directory does
	 * not exist yet (fresh install with no cached pages generated).
	 *
	 * An empty path has no traversal risk, so it must skip the realpath()-based
	 * validation that returns false for a non-existent cache directory.
	 *
	 * @return void
	 */
	public function test_clear_cache_all_when_cache_dir_missing_returns_success(): void {
		$_SERVER['HTTP_HOST']   = 'example.com';
		$_SERVER['REQUEST_URI'] = '/';

		// Simulate a fresh install where the cache directory has never been created.
		$cache_dir_reflection = new \ReflectionProperty( Rest::class, 'cache_dir' );
		$cache_dir_reflection->setValue( $this->rest, '/tmp/wppo-does-not-exist/cache/wppo/' );

		$GLOBALS['wp_filesystem'] = new WPPO_Filesystem_Mock();

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wpdb'] = new WPPO_WPDB_Mock();
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

		Functions\stubs(
			array(
				'wp_parse_url',
				'get_option',
				'do_action',
				'WP_Filesystem',
				'delete_transient',
				'update_option',
				'current_time',
				'is_multisite',
				'get_current_blog_id',
				'wp_kses_post',
				'__',
			)
		);
		Functions\when( 'wp_parse_url' )->justReturn( '/' );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'WP_Filesystem' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( null );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'content_url' )->justReturn( 'http://example.com/wp-content' );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( '__' )->returnArg( 1 );

		$request = new WP_REST_Request(
			array(
				'action' => 'clear_cache',
				'path'   => '',
			)
		);

		$response = $this->rest->clear_cache( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile
// WP core is not loaded in the unit test environment, so minimal stand-ins
// are required to invoke the REST handler methods.

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal WP_REST_Request stand-in for unit tests.
	 *
	 * @package PerformanceOptimise\Tests
	 */
	class WP_REST_Request {
		/**
		 * Request parameters.
		 *
		 * @var array
		 */
		private $params;

		/**
		 * Constructor.
		 *
		 * @param array $params Request parameters.
		 */
		public function __construct( $params = array() ) {
			$this->params = $params;
		}

		/**
		 * Get the request parameters.
		 *
		 * @return array Request parameters.
		 */
		public function get_params() {
			return $this->params;
		}

		/**
		 * Get the request JSON body parameters.
		 *
		 * @return array Request parameters.
		 */
		public function get_json_params() {
			return $this->params;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Minimal WP_REST_Response stand-in for unit tests.
	 *
	 * @package PerformanceOptimise\Tests
	 */
	class WP_REST_Response {
		/**
		 * Response data.
		 *
		 * @var array
		 */
		private $data;

		/**
		 * HTTP status code.
		 *
		 * @var int
		 */
		private $status;

		/**
		 * Constructor.
		 *
		 * @param array $data   Response data.
		 * @param int   $status HTTP status code.
		 */
		public function __construct( $data = array(), $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		/**
		 * Get the response data.
		 *
		 * @return array Response data.
		 */
		public function get_data() {
			return $this->data;
		}

		/**
		 * Get the HTTP status code.
		 *
		 * @return int HTTP status code.
		 */
		public function get_status() {
			return $this->status;
		}
	}
}

/**
 * Minimal filesystem mock that reports the cache directories as missing.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Filesystem_Mock {
	/**
	 * Whether a directory exists.
	 *
	 * @param string $dir Directory path.
	 * @return bool Always false to simulate a fresh install without a cache dir.
	 */
	public function is_dir( $dir ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return false;
	}
}

/**
 * Minimal WPDB mock for activity log inserts.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_WPDB_Mock {
	/**
	 * Database table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Mock insert that always succeeds.
	 *
	 * @param string $table   Table name.
	 * @param array  $data    Data to insert.
	 * @param array  $formats Format placeholders.
	 * @return int Mock inserted row id.
	 */
	public function insert( $table, $data, $formats = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return 1;
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
