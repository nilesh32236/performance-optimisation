<?php
/**
 * Guards the invariant that separates the two fail-open helpers.
 *
 * `fail_open_to_memory()` counts a Redis outage toward the circuit breaker — it
 * calls `record_redis_failure()`, which writes wppo-redis-failures.json and can
 * eventually trip the breaker. `fail_open_null_client()` deliberately does NOT,
 * because a torn-down client handle is not a Redis outage and must not count
 * toward one.
 *
 * @package PerformanceOptimise\Tests
 */

/**
 * Static source-reading guard for the fail-open helper invariant.
 *
 * This is intentionally a source-reading test rather than an execution test. An
 * execution test that invokes the helpers causes real side effects —
 * `log_redis_failure_once()` writes a throttle flag file into WP_CONTENT_DIR —
 * which leaks into ObjectCacheCircuitBreakerTest and makes the suite order
 * sensitive. Reading the source pins the invariant with zero shared state.
 *
 * @package PerformanceOptimise\Tests
 */
class ObjectCacheFailOpenInvariantTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Extract one private method's body from the drop-in by method name.
	 *
	 * @param string $file   Absolute path to the source file.
	 * @param string $method Method name to extract.
	 * @return string The method body, comments stripped.
	 */
	private function body_of( string $file, string $method ): string {
		$source = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local repo source file, not a remote resource.
		self::assertNotFalse( $source, 'Drop-in source must be readable.' );

		$pattern = '/private function ' . preg_quote( $method, '/' ) . '\s*\([^)]*\)[^{]*\{(.*?)\n\t\t\}/s';
		self::assertSame( 1, preg_match( $pattern, $source, $matches ), "Method {$method} must be readable." );

		$code = (string) $matches[1];

		// Strip comments so a docblock mentioning record_redis_failure() is not
		// mistaken for a call to it.
		$code = (string) preg_replace( '/\/\*.*?\*\//s', '', $code );
		$code = (string) preg_replace( '/\/\/.*$/m', '', $code );

		return $code;
	}

	/**
	 * A null client handle must not be counted as a circuit-breaker failure.
	 *
	 * @return void
	 */
	public function test_fail_open_null_client_does_not_record_a_breaker_failure(): void {
		$code = $this->body_of( WPPO_PLUGIN_PATH . 'templates/object-cache.php', 'fail_open_null_client' );

		// The invariant itself.
		self::assertStringNotContainsString(
			'record_redis_failure',
			$code,
			'fail_open_null_client() must not count toward the circuit breaker — a torn-down handle is not an outage.',
		);

		// It must still reconcile the flag, or every subsequent call retries Redis.
		self::assertStringContainsString( 'redis_connected', $code );
	}

	/**
	 * The memory path DOES count, which is what keeps the two distinct.
	 *
	 * @return void
	 */
	public function test_fail_open_to_memory_does_record_a_breaker_failure(): void {
		$code = $this->body_of( WPPO_PLUGIN_PATH . 'templates/object-cache.php', 'fail_open_to_memory' );

		self::assertStringContainsString(
			'record_redis_failure',
			$code,
			'fail_open_to_memory() must record the outage — without it the circuit breaker never trips.',
		);
	}
}
