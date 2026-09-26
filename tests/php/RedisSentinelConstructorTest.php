<?php
/**
 * Guard: the Sentinel connection path must match the installed phpredis API.
 *
 * `wppo_redis_connect_sentinel()` refuses to run on phpredis < 6.0, so the only
 * constructor that can ever execute is the 6.x one. It was calling the phredis
 * 5.x positional signature, which on phpredis 6.3.0 raises
 * `ArgumentCountError: RedisSentinel::__construct() expects at most 1 argument,
 * 6 given`. The catch block turned that into "Sentinel node connection failed."
 * and then into a `sentinel_fail` error, which Object_Cache classifies as a
 * *transient* outage: the persistent outage flag was armed, the drop-in was
 * parked, and the failure counted toward the circuit breaker. Sentinel mode
 * therefore could not work on any supported phpredis, and configuring it drove
 * the site permanently uncached with no self-heal.
 *
 * The test asserts the constructor shape against the real extension rather than
 * against a stub, so a future phpredis API change fails here instead of in
 * production.
 *
 * @package PerformanceOptimise\Tests
 */

/**
 * Sentinel constructor compatibility guard.
 */
class RedisSentinelConstructorTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * The helper source must not use the phpredis 5.x positional form.
	 *
	 * @return void
	 */
	public function test_helper_does_not_use_the_legacy_positional_constructor(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local source scan.
		$source = (string) file_get_contents( WPPO_PLUGIN_PATH . 'includes/Support/redis-connect-helper.php' );
		$this->assertMatchesRegularExpression(
			'@new\s+\\\\RedisSentinel\s*\(\s*array\s*\(@',
			$source,
			'RedisSentinel must be constructed with the phpredis 6.x options array'
		);
		$this->assertDoesNotMatchRegularExpression(
			'@new\s+\\\\RedisSentinel\s*\(\s*[\x27\x22]@',
			$source,
			'the phpredis 5.x positional RedisSentinel constructor is not supported by the 6.0 floor this code requires'
		);
	}

	/**
	 * The version gate must still require phpredis 6.0.
	 *
	 * This is the invariant that makes the options-array form the only reachable
	 * one. If the floor is ever lowered, the constructor has to change again.
	 *
	 * @return void
	 */
	public function test_sentinel_still_requires_phpredis_6(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local source scan.
		$source = (string) file_get_contents( WPPO_PLUGIN_PATH . 'includes/Support/redis-connect-helper.php' );
		$this->assertStringContainsString(
			"'6.0.0'",
			$source,
			'the phpredis 6.0 version gate must remain in place for the options-array constructor to be the only reachable one'
		);
	}

	/**
	 * The options the code passes must be accepted by the installed phpredis.
	 *
	 * @return void
	 */
	public function test_options_array_is_accepted_by_the_installed_phpredis(): void {
		if ( ! class_exists( 'RedisSentinel' ) ) {
			$this->markTestSkipped( 'phpredis is not installed in this environment' );
		}
		$ctor = new ReflectionMethod( 'RedisSentinel', '__construct' );
		$this->assertSame(
			1,
			$ctor->getNumberOfParameters(),
			'phpredis 6.x takes a single options array; if this environment has a different API, revisit the constructor'
		);

		$options = array(
			'host'           => '127.0.0.1',
			'port'           => 26379,
			'connectTimeout' => 0.5,
			'readTimeout'    => 0.0,
			'retryInterval'  => 0,
			'persistent'     => false,
		);

		// Constructing must not raise. phpredis connects lazily, so this does
		// not require a reachable Sentinel.
		$sentinel = new RedisSentinel( $options );
		$this->assertInstanceOf( \RedisSentinel::class, $sentinel );

		// The method the helper calls next must exist with one parameter.
		$resolve = new ReflectionMethod( 'RedisSentinel', 'getMasterAddrByName' );
		$this->assertSame( 1, $resolve->getNumberOfParameters() );
	}

	/**
	 * Every option key the helper passes must be one phpredis recognises.
	 *
	 * An unrecognised key makes phpredis emit a PHP warning and drop the key,
	 * so a typo would otherwise fail silently and leave the connection using
	 * phpredis's own default. Capturing warnings turns that into a failure.
	 *
	 * @return void
	 */
	public function test_no_unknown_option_key_is_passed_to_phpredis(): void {
		if ( ! class_exists( 'RedisSentinel' ) ) {
			$this->markTestSkipped( 'phpredis is not installed in this environment' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local source scan.
		$source = (string) file_get_contents( WPPO_PLUGIN_PATH . 'includes/Support/redis-connect-helper.php' );
		$this->assertSame(
			1,
			preg_match( '@new\s+\\\\RedisSentinel\s*\(\s*array\s*\((.*?)\)\s*\)@s', $source, $match ),
			'the options array passed to RedisSentinel must be found'
		);
		preg_match_all( "@'([A-Za-z_][A-Za-z0-9_]*)'\s*=>@", $match[1], $keys );

		$warnings = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- capturing a phpredis diagnostic is the point of this test.
		set_error_handler(
			static function ( int $errno, string $errstr ) use ( &$warnings ): bool {
				$warnings[] = $errstr;
				return true;
			}
		);
		try {
			new RedisSentinel(
				array(
					'host'           => '127.0.0.1',
					'port'           => 26379,
					'connectTimeout' => 0.5,
					'readTimeout'    => 0.0,
					'retryInterval'  => 0,
					'persistent'     => false,
				)
			);
		} finally {
			restore_error_handler();
		}
		$this->assertSame(
			array(),
			$warnings,
			'phpredis rejected an option key, so it would fall back to its own default. Keys found in source: '
			. implode( ', ', $keys[1] ) . ' | diagnostics: ' . implode( ' | ', $warnings )
		);
	}

	/**
	 * `persistent` must stay explicit.
	 *
	 * Measured against the installed extension: passing `persistent => false`
	 * closes the connection when the object is destroyed, while
	 * `persistent => true` leaves it in the server's pool. phpredis defaults
	 * this option to true for RedisSentinel, so omitting the key would leak a
	 * persistent socket per Sentinel node instead of the short-lived
	 * connection this code expects.
	 *
	 * @return void
	 */
	public function test_persistent_is_explicitly_disabled(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local source scan.
		$source = (string) file_get_contents( WPPO_PLUGIN_PATH . 'includes/Support/redis-connect-helper.php' );
		$this->assertSame(
			1,
			preg_match( "@'persistent'\s*=>\s*false@", $source ),
			"RedisSentinel options must set 'persistent' => false explicitly"
		);
	}

	/**
	 * A sentinel failure must not be silently treated as a healthy config.
	 *
	 * `sentinel_fail` after the fix means the configured Sentinel nodes could
	 * not be reached, which is a real outage and is allowed to arm the outage
	 * flag. What must never happen again is the *cause* being a code error, so
	 * the classifier is pinned here to document the intended behaviour.
	 *
	 * @return void
	 */
	public function test_sentinel_fail_is_classified_as_transient_after_the_constructor_fix(): void {
		$method = new ReflectionMethod( \PerformanceOptimise\Inc\Object_Cache::class, 'is_transient_outage_error' );
		$method->setAccessible( true );
		// An unreachable Sentinel set is a genuine outage, so the persistent
		// outage flag is the correct response now that the constructor no
		// longer converts a code error into this code.
		$this->assertTrue( $method->invoke( null, 'sentinel_fail', 'Sentinel node connection failed.' ) );
		// Misconfiguration codes must stay non-transient.
		foreach ( array( 'auth_fail', 'missing_sentinel', 'low_nodes', 'redis_version' ) as $code ) {
			$this->assertFalse( $method->invoke( null, $code ), $code . ' must not arm a persistent outage flag' );
		}
	}
}
