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

	/**
	 * Repeatable PHP 8.4/8.5 deprecation grep (issue #1260).
	 *
	 * Token-scans `includes/`, `templates/`, root `*.php`, and
	 * `uninstall.php` for the banned patterns from the acceptance
	 * criteria — `curl_close(null)`, `E_STRICT`, `mysqli_ping`, backtick
	 * shell execution, implicitly-nullable `Type $x = null` signatures,
	 * and raw 8.5 resource-teardown calls outside the version-gated
	 * legacy branches of `Util` — so PHP 8.5 stays clean without
	 * touching CI workflows. Token-based (not regex) so docblock
	 * backticks, explicit-nullable `?Type $x = null` signatures, and
	 * Redis `$manager->ping()` calls cannot false-positive. The WP
	 * object-cache drop-in keeps core's untyped signatures by design
	 * (untyped `$x = null` is legal and never flagged here).
	 *
	 * @since NEXT
	 * @return void
	 */
	public function test_plugin_sources_are_free_of_php84_85_banned_patterns(): void {
		$root  = dirname( __DIR__, 2 );
		$files = array();

		foreach ( array( 'includes', 'templates' ) as $dir ) {
			$path = $root . '/' . $dir;
			if ( ! is_dir( $path ) ) {
				continue;
			}
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				if ( 'php' === strtolower( (string) pathinfo( (string) $file, PATHINFO_EXTENSION ) ) ) {
					$files[] = (string) $file;
				}
			}
		}

		$root_files = glob( $root . '/*.php' );
		if ( is_array( $root_files ) ) {
			foreach ( $root_files as $file ) {
				$files[] = (string) $file;
			}
		}

		$this->assertNotEmpty( $files, 'Deprecation grep found no PHP files to scan (issue #1260).' );

		$violations = array();
		foreach ( array_unique( $files ) as $file ) {
			if ( ! is_readable( $file ) ) {
				continue;
			}
			$this->scan_file_for_deprecation_patterns( (string) $file, $root, $violations );
		}

		$this->assertSame(
			array(),
			$violations,
			'PHP 8.4/8.5 deprecation sweep must stay clean (issue #1260):' . "\n" . implode( "\n", $violations )
		);
	}

	/**
	 * The banned-pattern scanner must catch each violation class (issue #1260).
	 *
	 * Pins the green-path gate above with synthetic fixtures run through
	 * the private scan helper via reflection: each violation class
	 * (including fully-qualified and attributed variants) must report,
	 * while explicit-nullable `?array`, `mixed`, attributed
	 * `#[MyAttr] ?string`, method-call, and comment/string mentions
	 * must stay clean.
	 *
	 * @since NEXT
	 * @return void
	 */
	public function test_deprecation_scanner_catches_known_violations(): void {
		$method = new \ReflectionMethod( self::class, 'scan_file_for_deprecation_patterns' );
		$method->setAccessible( true );

		$dir = sys_get_temp_dir() . '/wppo-scanner-' . uniqid();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
		$this->assertTrue( mkdir( $dir ) || is_dir( $dir ), 'Scanner fixture dir must be creatable.' );

		$scan = function ( $code ) use ( $method, $dir ) {
			static $counter = 0;
			++$counter;
			$file = $dir . '/fixture-' . $counter . '.php';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
			file_put_contents( $file, $code );
			$violations = array();
			$method->invokeArgs( $this, array( $file, $dir, &$violations ) );
			return $violations;
		};

		try {
			$this->assertNotEmpty( $scan( "<?php\nfunction wppo_bad_nullable( string \$x = null ) {}\n" ), 'Implicitly-nullable Type $x = null must be flagged.' );
			$this->assertNotEmpty( $scan( "<?php\ncurl_close( null );\n" ), 'curl_close(null) must be flagged.' );
			$this->assertNotEmpty( $scan( "<?php\n\$level = E_STRICT;\n" ), 'E_STRICT must be flagged.' );
			$this->assertNotEmpty( $scan( "<?php\nmysqli_ping( \$conn );\n" ), 'mysqli_ping() must be flagged.' );
			$this->assertNotEmpty( $scan( "<?php\n\$out = `ls`;\n" ), 'Backtick execution must be flagged.' );
			$this->assertNotEmpty( $scan( "<?php\ncurl_close( \$ch );\n" ), 'Raw curl_close() outside Util must be flagged.' );
			$this->assertNotEmpty( $scan( "<?php\n\\curl_close( \$ch );\n" ), 'Fully-qualified \\curl_close() must be flagged.' );
			$this->assertNotEmpty( $scan( "<?php\nfunction wppo_bad_attr( #[A] string \$x = null ) {}\n" ), 'Attributed implicitly-nullable params must be flagged.' );

			$this->assertSame( array(), $scan( "<?php\nfunction wppo_good_nullable( ?array \$x = null, mixed \$y = null ) {}\n" ), 'Explicit ?array and mixed defaults must pass.' );
			$this->assertSame( array(), $scan( "<?php\nfunction wppo_good_attr( #[MyAttr] ?string \$x = null ) {}\n" ), 'Attributed explicitly-nullable params must pass.' );
			$this->assertSame( array(), $scan( "<?php\nfunction wppo_good_multi_attr( #[A(1, 2)] ?string \$x = null ) {}\n" ), 'Multi-arg attributed explicitly-nullable params must pass.' );
			$this->assertSame( array(), $scan( "<?php\n\$manager->ping();\n\$manager?->ping();\n" ), 'Method and nullsafe ping() calls must pass.' );
			$this->assertSame( array(), $scan( "<?php\n\$obj->curl_close( \$ch );\n" ), 'Method teardown calls must pass.' );
			$this->assertSame( array(), $scan( "<?php\n\$obj?->curl_close( \$ch );\n" ), 'Nullsafe teardown calls must pass.' );
			$this->assertSame( array(), $scan( "<?php\n\$obj->mysqli_ping();\n\$obj->curl_close( null );\n\$copy = \$E_STRICT;\n" ), 'Method-call and variable forms of raw patterns must pass.' );
			$this->assertSame( array(), $scan( "<?php\n// E_STRICT in a comment with `backticks`\n\$doc = 'curl_close(null) in a string';\n" ), 'Comment/string mentions must pass.' );

			$util_subdir = $dir . '/includes';
			if ( ! is_dir( $util_subdir ) ) {
				mkdir( $util_subdir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
			}
			$util_file = $util_subdir . '/class-util.php';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
			file_put_contents( $util_file, "<?php\nclass WPPO_Util_Fixture {\npublic static function close_curl_handle( &\$ch ) {\nif ( function_exists( 'curl_close' ) ) {\ncurl_close( \$ch );\n}\n}\n}\n" );
			$allowed_violations = array();
			$method->invokeArgs( $this, array( $util_file, $dir, &$allowed_violations ) );
			$this->assertSame( array(), $allowed_violations, 'Raw teardown inside the Util legacy helper must pass.' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
			file_put_contents( $util_file, "<?php\nclass WPPO_Util_Fixture {\npublic static function some_other_method() {\ncurl_close( \$ch );\n}\n}\n" );
			$stray_violations = array();
			$method->invokeArgs( $this, array( $util_file, $dir, &$stray_violations ) );
			$this->assertNotEmpty( $stray_violations, 'Raw teardown outside the Util legacy helpers must be flagged.' );
		} finally {
			$fixture_files = glob( $dir . '/*.php' );
			if ( is_array( $fixture_files ) ) {
				foreach ( $fixture_files as $file ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
					unlink( $file );
				}
			}
			if ( is_readable( $dir . '/includes/class-util.php' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
				unlink( $dir . '/includes/class-util.php' );
			}
			if ( is_dir( $dir . '/includes' ) ) {
				rmdir( $dir . '/includes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
			}
			if ( is_dir( $dir ) ) {
				rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
			}
		}
	}

	/**
	 * Scan one PHP file for the banned 8.4/8.5 patterns.
	 *
	 * Appends `file:line description` strings to `$violations` for every
	 * hit: raw `curl_close(null)` / `E_STRICT` / `mysqli_ping` in code
	 * tokens, standalone backtick operators, implicitly-nullable
	 * `Type $param = null` declarations (typed without `?`, `|null`, or
	 * `mixed`), and raw `curl_close()` / `curl_multi_close()` /
	 * `curl_share_close()` / `finfo_close()` / `xml_parser_free()` /
	 * `imagedestroy()` calls outside the version-gated legacy branches
	 * of `includes/class-util.php`.
	 *
	 * @since NEXT
	 * @param string   $file       Absolute file path.
	 * @param string   $root       Plugin root for relative reporting.
	 * @param string[] $violations Violation accumulator (by reference).
	 * @return void
	 */
	private function scan_file_for_deprecation_patterns( $file, $root, &$violations ): void {
		$source = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test-only source assertion.
		if ( false === $source ) {
			return;
		}

		$rel    = str_replace( $root . '/', '', $file );
		$tokens = token_get_all( $source );
		$count  = count( $tokens );

		// Code-only text (no comments/strings) for the raw-pattern checks
		// so docblock mentions can never false-positive.
		$code_only = '';
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) ) {
				$code_only .= ' ';
				continue;
			}
			$code_only .= is_array( $token ) ? $token[1] : $token;
		}

		$raw_patterns = array(
			'/(?<!->)(?<!::)\bcurl_close\s*\(\s*null/i' => 'curl_close(null) call',
			'/(?<!\$)(?<!->)(?<!::)\bE_STRICT\b/'       => 'E_STRICT usage',
			'/(?<!->)(?<!::)\bmysqli_ping\b/i'          => 'mysqli_ping() usage',
		);
		foreach ( $raw_patterns as $pattern => $label ) {
			if ( 1 === preg_match( $pattern, $code_only ) ) {
				$violations[] = sprintf( '%s: raw %s', $rel, $label );
			}
		}

		$close_functions = array( 'curl_close', 'curl_multi_close', 'curl_share_close', 'finfo_close', 'xml_parser_free', 'imagedestroy' );

		for ( $i = 0; $i < $count; ++$i ) {
			$token = $tokens[ $i ];

			// Standalone backtick tokens are shell execution; backticks
			// inside comments/strings never surface as lone tokens.
			if ( '`' === $token ) {
				$violations[] = sprintf( '%s: backtick shell-execution operator', $rel );
				continue;
			}

			if ( ! is_array( $token ) ) {
				continue;
			}

			$teardown_ids = array( T_STRING );
			if ( defined( 'T_NAME_FULLY_QUALIFIED' ) ) {
				$teardown_ids[] = constant( 'T_NAME_FULLY_QUALIFIED' );
			}
			if ( defined( 'T_NAME_QUALIFIED' ) ) {
				$teardown_ids[] = constant( 'T_NAME_QUALIFIED' );
			}
			if ( defined( 'T_NAME_RELATIVE' ) ) {
				$teardown_ids[] = constant( 'T_NAME_RELATIVE' );
			}

			if ( in_array( $token[0], $teardown_ids, true ) ) {
				$func_name = strtolower( ltrim( $token[1], '\\' ) );
				// Namespaced calls (Foo\curl_close) are not the global
				// teardown function; only bare or fully-qualified globals match.
				if ( false !== strpos( $func_name, '\\' ) ) {
					continue;
				}
				if ( ! in_array( $func_name, $close_functions, true ) ) {
					continue;
				}
				$next = $i + 1;
				while ( $next < $count && is_array( $tokens[ $next ] ) && in_array( $tokens[ $next ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					++$next;
				}
				if ( $next < $count && '(' === $tokens[ $next ] ) {
					$prev = $i - 1;
					while ( $prev >= 0 && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
						--$prev;
					}
					$prev_token    = $prev >= 0 ? $tokens[ $prev ] : null;
					$prev_id       = is_array( $prev_token ) ? $prev_token[0] : $prev_token;
					$method_guards = array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW );
					if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ) {
						$method_guards[] = constant( 'T_NULLSAFE_OBJECT_OPERATOR' );
					}
					if ( ! in_array( $prev_id, $method_guards, true ) ) {
						if ( ! $this->is_util_legacy_teardown_call( $tokens, $count, $i, $rel ) ) {
							$violations[] = sprintf( '%s:%d raw %s() outside the Util 8.5 helper', $rel, $token[2], $token[1] );
						}
					}
				}
			}

			if ( T_FUNCTION === $token[0] || T_FN === $token[0] ) {
				$this->scan_signature_for_implicit_nullable( $tokens, $count, $i, $rel, $violations );
			}
		}
	}

	/**
	 * Whether a teardown call sits inside a version-gated Util legacy helper.
	 *
	 * Narrows the `includes/class-util.php` exemption to the six legacy
	 * branches (`close_curl_handle`, `close_curl_multi_handle`,
	 * `destroy_gd_image`, `close_curl_share_handle`, `close_finfo_handle`,
	 * `free_xml_parser`) so a future raw `curl_close()` / `imagedestroy()`
	 * added elsewhere in that file still fails the gate.
	 *
	 * @since NEXT
	 * @param array  $tokens Full token stream of the file.
	 * @param int    $count  Token count.
	 * @param int    $index  Index of the teardown function-name token.
	 * @param string $rel    Relative file path for reporting.
	 * @return bool True when the call is an allowed legacy branch.
	 */
	private function is_util_legacy_teardown_call( $tokens, $count, $index, $rel ): bool {
		if ( false === strpos( $rel, 'includes/class-util.php' ) ) {
			return false;
		}
		$allowed   = array(
			'close_curl_handle',
			'close_curl_multi_handle',
			'destroy_gd_image',
			'close_curl_share_handle',
			'close_finfo_handle',
			'free_xml_parser',
		);
		$enclosing = $this->get_enclosing_function_name( $tokens, $count, $index );
		return in_array( $enclosing, $allowed, true );
	}

	/**
	 * Find the innermost named function containing a token index.
	 *
	 * Forward-scans for `T_FUNCTION` declarations, maps each named
	 * declaration to its `{...}` body range, and returns the innermost
	 * name containing `$index` (null when top-level). Anonymous
	 * functions/closures carry no name and never match the legacy list.
	 *
	 * @since NEXT
	 * @param array $tokens Full token stream of the file.
	 * @param int   $count  Token count.
	 * @param int   $index  Token index to locate.
	 * @return string|null Enclosing function name or null.
	 */
	private function get_enclosing_function_name( $tokens, $count, $index ): ?string {
		$match      = null;
		$match_size = null;
		for ( $k = 0; $k < $count; ++$k ) {
			$token = $tokens[ $k ];
			if ( ! is_array( $token ) || T_FUNCTION !== $token[0] ) {
				continue;
			}
			$name       = null;
			$name_index = -1;
			for ( $m = $k + 1; $m < $count; ++$m ) {
				$candidate = $tokens[ $m ];
				if ( is_array( $candidate ) && in_array( $candidate[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				if ( '&' === $candidate ) {
					continue;
				}
				if ( is_array( $candidate ) && T_STRING === $candidate[0] ) {
					$name       = $candidate[1];
					$name_index = $m;
				}
				break;
			}
			if ( null === $name ) {
				continue;
			}
			$brace_start = -1;
			for ( $m = $name_index + 1; $m < $count; ++$m ) {
				$candidate = $tokens[ $m ];
				if ( ';' === $candidate ) {
					break;
				}
				if ( '{' === $candidate ) {
					$brace_start = $m;
					break;
				}
			}
			if ( $brace_start < 0 ) {
				continue;
			}
			$depth     = 0;
			$brace_end = -1;
			for ( $m = $brace_start; $m < $count; ++$m ) {
				$candidate = $tokens[ $m ];
				if ( '{' === $candidate ) {
					++$depth;
				} elseif ( '}' === $candidate ) {
					--$depth;
					if ( 0 === $depth ) {
						$brace_end = $m;
						break;
					}
				}
			}
			if ( $brace_end < 0 ) {
				continue;
			}
			if ( $index > $brace_start && $index < $brace_end ) {
				$size = $brace_end - $brace_start;
				if ( null === $match_size || $size < $match_size ) {
					$match      = $name;
					$match_size = $size;
				}
			}
		}
		return $match;
	}

	/**
	 * Scan the parameter list of one function token for implicitly-nullable params.
	 *
	 * Flags `Type $param = null` where the declared type is non-empty and
	 * carries no explicit nullability (`?` prefix, `null` union member, or
	 * `mixed`). Untyped `$param = null` and `mixed $param = null` are
	 * legal and never flagged.
	 *
	 * @since NEXT
	 * @param array    $tokens     Full token stream of the file.
	 * @param int      $count      Token count.
	 * @param int      $index      Index of the T_FUNCTION/T_FN token.
	 * @param string   $rel        Relative file path for reporting.
	 * @param string[] $violations Violation accumulator (by reference).
	 * @return void
	 */
	private function scan_signature_for_implicit_nullable( $tokens, $count, $index, $rel, &$violations ): void {
		$j = $index + 1;
		while ( $j < $count && '(' !== $tokens[ $j ] ) {
			if ( '{' === $tokens[ $j ] || ';' === $tokens[ $j ] || '}' === $tokens[ $j ] ) {
				return;
			}
			++$j;
		}
		if ( $j >= $count || '(' !== $tokens[ $j ] ) {
			return;
		}

		$depth = 0;
		$end   = -1;
		for ( $k = $j; $k < $count; ++$k ) {
			if ( '(' === $tokens[ $k ] ) {
				++$depth;
			} elseif ( ')' === $tokens[ $k ] ) {
				--$depth;
				if ( 0 === $depth ) {
					$end = $k;
					break;
				}
			}
		}
		if ( $end < 0 ) {
			return;
		}

		$params  = array();
		$current = array();
		$depth   = 0;
		for ( $k = $j + 1; $k < $end; ++$k ) {
			$token = $tokens[ $k ];
			$text  = is_array( $token ) ? $token[1] : $token;
			if ( '(' === $text || '[' === $text || '{' === $text || '#[' === $text ) {
				++$depth;
			} elseif ( ')' === $text || ']' === $text || '}' === $text ) {
				--$depth;
			}
			if ( ',' === $text && 0 === $depth ) {
				$params[] = $current;
				$current  = array();
				continue;
			}
			$current[] = $token;
		}
		$params[] = $current;

		foreach ( $params as $param ) {
			$depth  = 0;
			$equals = -1;
			foreach ( $param as $idx => $token ) {
				$text = is_array( $token ) ? $token[1] : $token;
				if ( '(' === $text || '[' === $text || '{' === $text || '#[' === $text ) {
					++$depth;
				} elseif ( ')' === $text || ']' === $text || '}' === $text ) {
					--$depth;
				}
				if ( '=' === $token && 0 === $depth ) {
					$equals = $idx;
					break;
				}
			}
			if ( $equals < 0 ) {
				continue;
			}

			$default = '';
			foreach ( array_slice( $param, $equals + 1 ) as $token ) {
				$default .= is_array( $token ) ? $token[1] : $token;
			}
			if ( 'null' !== strtolower( trim( $default ) ) ) {
				continue;
			}

			$var_index = -1;
			foreach ( $param as $idx => $token ) {
				if ( $idx >= $equals ) {
					break;
				}
				if ( is_array( $token ) && T_VARIABLE === $token[0] ) {
					$var_index = $idx;
					break;
				}
			}
			if ( $var_index < 0 ) {
				continue;
			}

			$type      = '';
			$in_attr   = 0;
			$modifiers = array( T_PUBLIC, T_PRIVATE, T_PROTECTED, T_READONLY, T_VAR, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT );
			$line      = 0;
			foreach ( array_slice( $param, 0, $var_index ) as $token ) {
				$text = is_array( $token ) ? $token[1] : $token;
				$id   = is_array( $token ) ? $token[0] : $token;
				if ( 0 === $line && is_array( $token ) ) {
					$line = $token[2];
				}
				if ( '#[' === $text ) {
					++$in_attr;
					continue;
				}
				if ( $in_attr > 0 ) {
					if ( ']' === $token ) {
						--$in_attr;
					} elseif ( '[' === $token ) {
						++$in_attr;
					}
					continue;
				}
				if ( in_array( $id, $modifiers, true ) ) {
					continue;
				}
				if ( '&' === $text || '...' === $text ) {
					continue;
				}
				$type .= $text;
			}

			$normalized = strtolower( (string) preg_replace( '/\s+/', '', $type ) );
			if ( '' === $normalized || 'mixed' === $normalized ) {
				continue;
			}
			if ( 0 === strpos( $normalized, '?' ) ) {
				continue;
			}
			$parts = preg_split( '/[|&()]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY );
			if ( is_array( $parts ) && ( in_array( 'null', $parts, true ) || in_array( 'mixed', $parts, true ) ) ) {
				continue;
			}

			$violations[] = sprintf( '%s:%d implicitly-nullable `%s $.. = null`', $rel, $line, trim( $type ) );
		}
	}
}
