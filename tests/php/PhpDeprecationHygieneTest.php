<?php
/**
 * PHP 8.4/8.5 deprecation-hygiene tests (issue #1056).
 *
 * Zero-notice gate for `composer test`: every test installs an error handler
 * that promotes E_DEPRECATED (and E_USER_DEPRECATED) to a test failure, so
 * the suite fails on any `Deprecated:` notice raised by the exercised plugin
 * code paths. Run with WP_DEBUG on (see tests/php/bootstrap.php).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Util;

// The suite has no WP core, so reuse the guarded WP_Error stand-in co-located
// in TelemetryTest.php (require_once keeps it a single declaration whichever
// file loads first).
if ( ! class_exists( 'WP_Error' ) ) {
	require_once __DIR__ . '/TelemetryTest.php';
}

/**
 * Deprecation-hygiene tests.
 *
 * @package PerformanceOptimise\Tests
 */
class PhpDeprecationHygieneTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Deprecations collected by the per-test error handler.
	 *
	 * @var string[]
	 */
	private array $caught_deprecations = array();

	/**
	 * Previous error handler, restored in tearDown().
	 *
	 * @var callable|null
	 */
	private $previous_handler = null;

	/**
	 * Install a deprecation-promoting error handler on top of the suite stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->caught_deprecations = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Test-only zero-notice gate (issue #1056).
		$this->previous_handler = set_error_handler(
			function ( $errno, $errstr, $errfile, $errline ) {
				if ( E_DEPRECATED === $errno || E_USER_DEPRECATED === $errno ) {
					$this->caught_deprecations[] = sprintf( '%s in %s:%d', $errstr, $errfile, $errline );
					return true;
				}
				return false;
			}
		);
	}

	/**
	 * Restore the previous error handler and fail on collected deprecations.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		restore_error_handler();
		$this->previous_handler = null;
		parent::tearDown();
		$this->assertSame(
			array(),
			$this->caught_deprecations,
			'Zero-notice gate: no E_DEPRECATED may be raised (issue #1056).'
		);
	}

	/**
	 * The 8.5 branch must release a cURL multi handle by dropping the
	 * reference (no curl_multi_close() call, so no deprecation).
	 *
	 * @return void
	 */
	public function test_close_curl_multi_handle_unsets_on_85(): void {
		if ( ! function_exists( 'curl_multi_init' ) ) {
			$this->markTestSkipped( 'cURL extension is required.' );
		}

		$mh = curl_multi_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_init -- test requires a real multi handle for the teardown helper.

		Util::close_curl_multi_handle( $mh, '8.5.0' );

		$this->assertNull( $mh );
	}

	/**
	 * Below 8.5 the legacy curl_multi_close() path must run without error.
	 *
	 * @return void
	 */
	public function test_close_curl_multi_handle_keeps_legacy_path_below_85(): void {
		if ( ! function_exists( 'curl_multi_init' ) ) {
			$this->markTestSkipped( 'cURL extension is required.' );
		}

		$mh = curl_multi_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_init -- test requires a real multi handle for the teardown helper.

		Util::close_curl_multi_handle( $mh, '8.4.0' );

		// Legacy curl_multi_close() closed the handle; null the local so no
		// second close is attempted during cleanup.
		$mh = null;
		$this->assertNull( $mh );
	}

	/**
	 * Node-list parsing must be null-safe (no trim(null) deprecation).
	 *
	 * @return void
	 */
	public function test_parse_nodes_is_null_safe(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/redis-connect-helper.php';

		$parsed = wppo_parse_nodes( array( ' 127.0.0.1:6379 ', null, '', 123 ) );

		$this->assertSame( array( '127.0.0.1:6379', '123' ), array_values( $parsed ) );
	}

	/**
	 * Single-node parsing must be null-safe (no strpos(null) deprecation).
	 *
	 * @return void
	 */
	public function test_parse_redis_node_is_null_safe(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/redis-connect-helper.php';

		$parsed = wppo_parse_redis_node( null );

		$this->assertSame( '', $parsed['host'] );
		$this->assertSame( 26379, $parsed['port'] );

		$bracketed = wppo_parse_redis_node( '[::1]:26379' );

		$this->assertSame( '::1', $bracketed['host'] );
		$this->assertSame( 26379, $bracketed['port'] );
	}

	/**
	 * Sentinel connect must not raise when phpredis is absent (the
	 * version_compare() probe casts phpversion()'s false return).
	 *
	 * @return void
	 */
	public function test_sentinel_connect_missing_class_returns_error_without_notice(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/redis-connect-helper.php';

		if ( class_exists( 'RedisSentinel' ) ) {
			$this->markTestSkipped( 'Requires phpredis Sentinel to be absent.' );
		}

		$result = wppo_redis_connect_sentinel(
			array(
				'nodes' => array( '127.0.0.1:26379' ),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * The bfcache session-token parameter must be redacted from stack traces.
	 *
	 * @return void
	 */
	public function test_session_token_param_is_sensitive(): void {
		$param = new \ReflectionParameter(
			array( 'PerformanceOptimise\Inc\Bfcache', 'get_user_token' ),
			'session_token'
		);

		$attrs = $param->getAttributes( \SensitiveParameter::class );

		$this->assertNotEmpty( $attrs, 'get_user_token( $session_token ) must carry #[SensitiveParameter] (issue #1056).' );
	}
}
