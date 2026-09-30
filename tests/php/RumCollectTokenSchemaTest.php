<?php
/**
 * Tests for the rum_collect beacon token contract (issue #1686).
 *
 * Two layers are covered deliberately, because they are two different
 * contracts and either can be removed independently:
 *
 * 1. The REST route schema declares `token` as a required, non-empty
 *    string. A beacon with no token is rejected at argument validation.
 * 2. RUM::collect() validates the token itself with hash_equals, scoped to
 *    the path and the day, and refuses everything downstream of that -
 *    including queueing a sample and scheduling the delayed flush.
 *
 * @package PerformanceOptimise\Tests
 */

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\RUM;
use PerformanceOptimise\Inc\Rest;
use PerformanceOptimise\Inc\Util;

/**
 * Class RumCollectTokenSchemaTest.
 *
 * @package PerformanceOptimise\Tests
 */
class RumCollectTokenSchemaTest extends \PHPUnit\Framework\TestCase {

	use WPPO_Test_Bootstrap;

	/**
	 * In-memory option map.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * In-memory transient map.
	 *
	 * @var array
	 */
	private $transients = array();

	/**
	 * Scheduled cron events recorded during a test.
	 *
	 * @var array
	 */
	private $scheduled = array();

	/**
	 * Install the WP stubs the beacon path needs.
	 */
	private function install_stubs(): void {
		RUM::clear_field_lcp_cache();
		Functions\stubs(
			array(
				'get_option',
				'update_option',
				'get_transient',
				'set_transient',
				'delete_transient',
				'wp_hash',
				'sanitize_text_field',
				'esc_url_raw',
				'wp_unslash',
				'is_multisite',
				'wp_next_scheduled',
				'wp_schedule_single_event',
				'wp_rand',
				'__',
				'trailingslashit',
				'untrailingslashit',
				'wp_json_encode',
				'absint',
				'wp_parse_url',
				'add_action',
				'add_filter',
				'do_action',
				'apply_filters',
			)
		);
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return $value;
			}
		);
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'trailingslashit' )->returnArg();
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/\\' );
			}
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return array_key_exists( $key, $this->transients ) ? $this->transients[ $key ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $expiration = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$this->transients[ $key ] = $value;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		// Deterministic token derivation so the valid token is predictable.
		Functions\when( 'wp_hash' )->alias(
			static function ( $data ) {
				return 'h_' . $data;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_rand' )->justReturn( 2 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook, $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$this->scheduled[] = array(
					'timestamp' => $timestamp,
					'hook'      => $hook,
				);
				return true;
			}
		);
		$GLOBALS['wp_version'] = '6.8';
	}

	/**
	 * Set up the stubs and enable RUM.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		// Settings live under the wppo_settings option; Util::get_settings()
		// proxies to Settings_Store, so the cache must be cleared too.
		$this->options = array(
			'wppo_settings' => array(
				'performance_audit' => array( 'rum_enabled' => true ),
			),
		);
		Util::clear_settings_cache();
		$this->transients = array();
		$this->scheduled  = array();
		$this->install_stubs();
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp_version'] );
		parent::tearDown();
	}

	/**
	 * The valid per-path, per-day token under the stubbed wp_hash.
	 *
	 * @param string $path Page path the token is minted for.
	 * @return string
	 */
	private function valid_token( string $path = '/' ): string {
		return 'h_wppo_rum_' . gmdate( 'Ymd' ) . '|' . $path;
	}

	/**
	 * The registered rum_collect route definition.
	 *
	 * @return array
	 */
	private function rum_route(): array {
		// get_routes() is private; reflect it the same way the plugin's own
		// internals do, rather than exposing a seam just for the test.
		$rest   = new Rest();
		$method = new \ReflectionMethod( Rest::class, 'get_routes' );
		$method->setAccessible( true );
		$routes = $method->invoke( $rest );

		$this->assertArrayHasKey(
			'rum_collect',
			$routes,
			'The public beacon route must still be registered.'
		);

		return $routes['rum_collect'];
	}

	/**
	 * (a) The route schema declares the token as a required, non-empty
	 *     string, so a tokenless beacon is rejected at argument validation.
	 *
	 * @return void
	 */
	public function test_route_schema_requires_a_non_empty_string_token(): void {
		$route = $this->rum_route();

		$this->assertArrayHasKey( 'args', $route, 'rum_collect must declare its args.' );
		$this->assertArrayHasKey( 'token', $route['args'], 'rum_collect must declare a token arg.' );

		$token = $route['args']['token'];

		$this->assertTrue( $token['required'], 'The beacon token is not optional.' );
		$this->assertSame( 'string', $token['type'] );
		$this->assertIsCallable( $token['validate_callback'] );

		$validate = $token['validate_callback'];

		$this->assertTrue( $validate( $this->valid_token() ), 'A real token must validate.' );
		$this->assertFalse( $validate( '' ), 'An empty token must be rejected.' );
		$this->assertFalse( $validate( null ), 'A null token must be rejected.' );
		$this->assertFalse( $validate( array( 'x' ) ), 'A non-string token must be rejected.' );
	}

	/**
	 * (b) A beacon with no token, or a forged one, is rejected - and nothing
	 *     downstream of the check runs.
	 *
	 * @return void
	 */
	public function test_collect_rejects_missing_and_forged_tokens(): void {
		foreach ( array( array(), array( 'token' => '' ), array( 'token' => 'forged' ) ) as $params ) {
			$result = RUM::collect( $params );

			$this->assertFalse( $result['ok'] );
			$this->assertSame( 401, $result['status'] );
		}

		// A rejected beacon must not queue a sample...
		$this->assertSame( array(), $this->transients );
		// ...nor schedule the delayed flush.
		$this->assertSame( array(), $this->scheduled );
	}

	/**
	 * (b-cont) The correct token is accepted.
	 *
	 * @return void
	 */
	public function test_collect_accepts_the_correct_token(): void {
		$result = RUM::collect(
			array(
				'path'  => '/',
				'token' => $this->valid_token( '/' ),
				'url'   => 'https://example.test/',
				'lcp'   => 1234,
			)
		);

		$this->assertTrue( $result['ok'], 'A correctly tokenised beacon must be accepted.' );
		$this->assertSame( 200, $result['status'] );
	}

	/**
	 * (c) The REST path works end to end, both ways: a forged token is
	 *     refused with the failure status, a valid one succeeds.
	 *
	 * @return void
	 */
	public function test_rest_collect_rum_end_to_end(): void {
		$rest = new Rest();

		$forged = $rest->collect_rum(
			new \WP_REST_Request(
				array(
					'path'  => '/',
					'token' => 'forged',
				)
			)
		);

		$this->assertSame( 401, $forged->get_status(), 'A forged token must not reach the collector.' );
		$this->assertFalse( $forged->get_data()['success'] );

		$valid = $rest->collect_rum(
			new \WP_REST_Request(
				array(
					'path'  => '/',
					'token' => $this->valid_token( '/' ),
					'url'   => 'https://example.test/',
					'lcp'   => 1234,
				)
			)
		);

		$this->assertSame( 200, $valid->get_status(), 'A valid token must still work end to end.' );
		$this->assertTrue( $valid->get_data()['success'] );
	}

	/**
	 * (d) The delayed flush is only ever scheduled by an accepted beacon, so
	 *     an unauthenticated caller cannot force a purge or a cron event.
	 *
	 * @return void
	 */
	public function test_rejected_beacon_cannot_schedule_the_delayed_flush(): void {
		RUM::collect(
			array(
				'path'  => '/',
				'token' => 'forged',
			)
		);

		$scheduled_hooks = array_column( $this->scheduled, 'hook' );

		$this->assertNotContains(
			'wppo_rum_flush',
			$scheduled_hooks,
			'A rejected beacon must not be able to schedule the delayed flush.'
		);
		$this->assertSame( array(), $scheduled_hooks );
	}
}
