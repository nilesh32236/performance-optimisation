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
		if ( Util::is_php85_or_greater() ) {
			$this->markTestSkipped( 'Legacy curl_multi_close() path cannot run notice-free on PHP >= 8.5.' );
		}
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

		\Brain\Monkey\Functions\stubs(
			array(
				'__' => static function ( $text ) {
					return $text;
				},
			)
		);

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

	/**
	 * The 8.5 branch must release a cURL share handle by dropping the
	 * reference (no curl_share_close() call, so no deprecation).
	 *
	 * @since NEXT
	 * @return void
	 */
	public function test_close_curl_share_handle_unsets_on_85(): void {
		if ( ! function_exists( 'curl_share_init' ) ) {
			$this->markTestSkipped( 'cURL extension is required.' );
		}

		$sh = curl_share_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_share_init -- test requires a real share handle for the teardown helper.

		Util::close_curl_share_handle( $sh, '8.5.0' );

		$this->assertNull( $sh );
	}

	/**
	 * Below 8.5 the legacy curl_share_close() path must run without error.
	 *
	 * @since NEXT
	 * @return void
	 */
	public function test_close_curl_share_handle_keeps_legacy_path_below_85(): void {
		if ( Util::is_php85_or_greater() ) {
			$this->markTestSkipped( 'Legacy curl_share_close() path cannot run notice-free on PHP >= 8.5.' );
		}
		if ( ! function_exists( 'curl_share_init' ) ) {
			$this->markTestSkipped( 'cURL extension is required.' );
		}

		$sh = curl_share_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_share_init -- test requires a real share handle for the teardown helper.

		Util::close_curl_share_handle( $sh, '8.4.0' );

		// Legacy curl_share_close() closed the handle; null the local so no
		// second close is attempted during cleanup.
		$sh = null;
		$this->assertNull( $sh );
	}

	/**
	 * The 8.5 branch must release a finfo handle by dropping the reference
	 * (no finfo_close() call, so no deprecation).
	 *
	 * @since NEXT
	 * @return void
	 */
	public function test_close_finfo_handle_unsets_on_85(): void {
		if ( ! function_exists( 'finfo_open' ) ) {
			$this->markTestSkipped( 'fileinfo extension is required.' );
		}

		$finfo = finfo_open( FILEINFO_MIME_TYPE );

		Util::close_finfo_handle( $finfo, '8.5.0' );

		$this->assertNull( $finfo );
	}

	/**
	 * Below 8.5 the legacy finfo_close() path must run without error.
	 *
	 * @since NEXT
	 * @return void
	 */
	public function test_close_finfo_handle_keeps_legacy_path_below_85(): void {
		if ( Util::is_php85_or_greater() ) {
			$this->markTestSkipped( 'Legacy finfo_close() path cannot run notice-free on PHP >= 8.5.' );
		}
		if ( ! function_exists( 'finfo_open' ) ) {
			$this->markTestSkipped( 'fileinfo extension is required.' );
		}

		$finfo = finfo_open( FILEINFO_MIME_TYPE );

		Util::close_finfo_handle( $finfo, '8.4.0' );

		// Legacy finfo_close() closed the handle; null the local so no
		// second close is attempted during cleanup.
		$finfo = null;
		$this->assertNull( $finfo );
	}

	/**
	 * The 8.5 branch must release an XML parser by dropping the reference
	 * (no xml_parser_free() call, so no deprecation).
	 *
	 * @since NEXT
	 * @return void
	 */
	public function test_free_xml_parser_unsets_on_85(): void {
		if ( ! function_exists( 'xml_parser_create' ) ) {
			$this->markTestSkipped( 'xml extension is required.' );
		}

		$parser = xml_parser_create(); // phpcs:ignore WordPress.WP.AlternativeFunctions.xml_xml_parser_create -- test requires a real parser for the teardown helper.

		Util::free_xml_parser( $parser, '8.5.0' );

		$this->assertNull( $parser );
	}

	/**
	 * Below 8.5 the legacy xml_parser_free() path must run without error.
	 *
	 * @since NEXT
	 * @return void
	 */
	public function test_free_xml_parser_keeps_legacy_path_below_85(): void {
		if ( Util::is_php85_or_greater() ) {
			$this->markTestSkipped( 'Legacy xml_parser_free() path cannot run notice-free on PHP >= 8.5.' );
		}
		if ( ! function_exists( 'xml_parser_create' ) ) {
			$this->markTestSkipped( 'xml extension is required.' );
		}

		$parser = xml_parser_create(); // phpcs:ignore WordPress.WP.AlternativeFunctions.xml_xml_parser_create -- test requires a real parser for the teardown helper.

		Util::free_xml_parser( $parser, '8.4.0' );

		// Legacy xml_parser_free() freed the parser; null the local so no
		// second free is attempted during cleanup.
		$parser = null;
		$this->assertNull( $parser );
	}

	/**
	 * The bundled Action Scheduler save_action surface must stay
	 * explicit-nullable (no PHP 8.4 implicitly-nullable deprecation).
	 *
	 * Guards the acceptance criterion "no nullable notice on save" without
	 * touching vendor/: the pinned Action Scheduler files are read as text
	 * (the classes are not Composer-autoloadable in the unit suite) and
	 * every save_action() signature must use `?DateTime ... = null`.
	 *
	 * @since NEXT
	 * @return void
	 */
	public function test_action_scheduler_save_action_is_explicit_nullable(): void {
		$root  = dirname( __DIR__, 2 );
		$files = array(
			'vendor/woocommerce/action-scheduler/classes/abstracts/ActionScheduler_Store.php',
			'vendor/woocommerce/action-scheduler/classes/data-stores/ActionScheduler_DBStore.php',
			'vendor/woocommerce/action-scheduler/classes/data-stores/ActionScheduler_HybridStore.php',
			'vendor/woocommerce/action-scheduler/classes/data-stores/ActionScheduler_wpPostStore.php',
		);

		// Collect unreadable files instead of skipping inside the loop so one
		// missing file cannot mask the assertions on the remaining files.
		$missing = array();
		$checked = 0;
		foreach ( $files as $relative ) {
			$path = $root . '/' . $relative;
			if ( ! is_readable( $path ) ) {
				$missing[] = $relative;
				continue;
			}

			$source = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test-only vendor source assertion.
			$this->assertNotFalse( $source, sprintf( '%s must be readable.', $relative ) );

			$this->assertMatchesRegularExpression(
				'/function\s+save_action\s*\([^)]*\?DateTime[^)]*=\s*null/s',
				$source,
				sprintf( '%s::save_action() must be explicit-nullable `?DateTime ... = null` (issue #1219).', $relative )
			);
			$this->assertDoesNotMatchRegularExpression(
				'/function\s+save_action\s*\([^)]*(?<!\?)(?:^|[\s(,])DateTime\s+\$\w+\s*=\s*null/',
				$source,
				sprintf( '%s::save_action() must not use implicitly-nullable `DateTime $x = null` (issue #1219).', $relative )
			);
			++$checked;
		}

		if ( 0 === $checked ) {
			$this->markTestSkipped(
				sprintf( 'Action Scheduler sources unavailable: %s.', implode( ', ', $missing ) )
			);
		}
		if ( ! empty( $missing ) ) {
			$this->markTestSkipped(
				sprintf( 'Partial Action Scheduler sources unavailable (checked %d of %d): %s.', $checked, count( $files ), implode( ', ', $missing ) )
			);
		}
	}

	/**
	 * The cron scheduler hotspot must run null-safely (no trim(null) or
	 * implicitly-nullable deprecation on the Woo-exclusion path).
	 *
	 * Exercises Cron::is_woo_excluded_url() and get_rest_route_param()
	 * through reflection with empty/edge inputs under the zero-notice gate.
	 *
	 * @since NEXT
	 * @return void
	 */
	public function test_cron_woo_exclusion_paths_are_null_safe(): void {
		\Brain\Monkey\Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		\Brain\Monkey\Functions\when( 'get_option' )->justReturn( array() );
		Util::clear_settings_cache();

		$cron = ( new \ReflectionClass( 'PerformanceOptimise\Inc\Cron' ) )->newInstanceWithoutConstructor();

		$is_excluded = new \ReflectionMethod( 'PerformanceOptimise\Inc\Cron', 'is_woo_excluded_url' );
		$is_excluded->setAccessible( true );

		// Empty URL and plain home URL must not raise; failures fail-open
		// (excluded) or fail-closed deterministically, never a notice.
		$home_excluded  = $is_excluded->invoke( $cron, 'http://example.com/' );
		$store_excluded = $is_excluded->invoke( $cron, 'http://example.com/?rest_route=/wc/store/v1/cart' );

		// Plain home page is cacheable (not excluded); the plain-permalink
		// Store API URL is unconditionally excluded.
		$this->assertIsBool( $home_excluded );
		$this->assertIsBool( $store_excluded );
		$this->assertFalse( $home_excluded );
		$this->assertTrue( $store_excluded );

		$rest_route = new \ReflectionMethod( 'PerformanceOptimise\Inc\Cron', 'get_rest_route_param' );
		$rest_route->setAccessible( true );

		$this->assertSame( '', $rest_route->invoke( $cron, 'http://example.com/', '' ) );
		$this->assertSame( '/wc/store/v1/cart', $rest_route->invoke( $cron, 'http://example.com/?rest_route=/wc/store/v1/cart', 'rest_route=/wc/store/v1/cart' ) );
	}
}
