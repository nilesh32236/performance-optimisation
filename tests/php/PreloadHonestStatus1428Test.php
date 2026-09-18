<?php
/**
 * Tests for honest preload status + randomized-query guard (issue #1428).
 *
 * Pins the zero-byte complete→running/idle truth table, the 30-minute
 * stalled flag in Cron::get_preload_status(), and the Cache
 * ::is_randomized_query_asset() patterns (epoch matches, YYYYMMDD does not).
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */

use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\Cron;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression tests for issue #1428 follow-ups.
 *
 * @package PerformanceOptimise\Tests
 */
class PreloadHonestStatus1428Test extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options store backing the get_option stub.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Install a get_option stub backed by $this->options.
	 *
	 * @param array<string, mixed> $options Seed options.
	 * @return void
	 */
	private function install_option_stubs( array $options ): void {
		$this->options = $options;
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
	}

	/**
	 * Seed the preload queue option and return the honest status payload.
	 *
	 * Cache::get_cache_bytes_and_files() fail-opens to 0 bytes in the test
	 * env (no WP_Filesystem), which is exactly the zero-byte run the guard
	 * must handle honestly.
	 *
	 * @param array<string, mixed> $queue Queue payload.
	 * @return array<string, mixed> Status payload.
	 */
	private function status_for_queue( array $queue ): array {
		$this->install_option_stubs(
			array(
				Cron::PRELOAD_QUEUE_OPTION => $queue,
				'wppo_settings'            => array(),
			)
		);
		return Cron::get_preload_status();
	}

	/**
	 * Test that a fully drained zero-byte complete run reports idle.
	 *
	 * @return void
	 */
	public function test_drained_zero_byte_complete_becomes_idle(): void {
		$status = $this->status_for_queue(
			array(
				'queued'     => array(),
				'done'       => 5,
				'failed'     => array(),
				'total'      => 5,
				'status'     => 'complete',
				'updated_at' => time(),
			)
		);

		$this->assertSame( 'idle', $status['status'] );
		$this->assertFalse( $status['stalled'] );
		$this->assertSame( 0, $status['cache_bytes'] );
	}

	/**
	 * Test that a zero-byte complete run with resumable work stays running.
	 *
	 * @return void
	 */
	public function test_pending_failed_zero_byte_complete_becomes_running(): void {
		$status = $this->status_for_queue(
			array(
				'queued'     => array(),
				'done'       => 4,
				'failed'     => array( 'http://example.com/a/' ),
				'total'      => 5,
				'status'     => 'complete',
				'updated_at' => time(),
			)
		);

		$this->assertSame( 'running', $status['status'] );
	}

	/**
	 * Test that a running queue untouched for 30+ minutes is flagged stalled.
	 *
	 * @return void
	 */
	public function test_stalled_flag_set_when_running_untouched(): void {
		$status = $this->status_for_queue(
			array(
				'queued'     => array( 'http://example.com/a/' ),
				'done'       => 0,
				'failed'     => array(),
				'total'      => 1,
				'status'     => 'running',
				'updated_at' => time() - 3600,
			)
		);

		$this->assertSame( 'running', $status['status'] );
		$this->assertTrue( $status['stalled'] );
	}

	/**
	 * Test that a freshly touched running queue is not flagged stalled.
	 *
	 * @return void
	 */
	public function test_stalled_false_on_fresh_running(): void {
		$status = $this->status_for_queue(
			array(
				'queued'     => array( 'http://example.com/a/' ),
				'done'       => 0,
				'failed'     => array(),
				'total'      => 1,
				'status'     => 'running',
				'updated_at' => time() - 60,
			)
		);

		$this->assertSame( 'running', $status['status'] );
		$this->assertFalse( $status['stalled'] );
	}

	/**
	 * Test that the payload always carries the bytes-aware keys.
	 *
	 * @return void
	 */
	public function test_payload_carries_bytes_files_stalled_keys(): void {
		$status = $this->status_for_queue(
			array(
				'queued'     => array(),
				'done'       => 0,
				'failed'     => array(),
				'total'      => 0,
				'status'     => 'idle',
				'updated_at' => 0,
			)
		);

		$this->assertArrayHasKey( 'cache_bytes', $status );
		$this->assertArrayHasKey( 'cache_files', $status );
		$this->assertArrayHasKey( 'stalled', $status );
		$this->assertIsInt( $status['cache_bytes'] );
		$this->assertIsInt( $status['cache_files'] );
		$this->assertIsBool( $status['stalled'] );
	}

	/**
	 * Data provider for the randomized-query guard truth table.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function randomized_query_provider(): array {
		return array(
			'epoch timestamp matches'        => array( 'https://example.com/app.js?ver=1718720000', true ),
			'uniqid hex matches'             => array( 'https://example.com/app.js?ver=665e7a1b9c3d4', true ),
			'mixed alnum token matches'      => array( 'https://example.com/app.js?ver=abc123def456', true ),
			'YYYYMMDD date stays combinable' => array( 'https://example.com/app.js?ver=20240101', false ),
			'semver stays combinable'        => array( 'https://example.com/app.js?ver=1.2.3', false ),
			'short plain version'            => array( 'https://example.com/app.js?ver=123', false ),
			'non-version key ignored'        => array( 'https://example.com/app.js?foo=1718720000', false ),
			'semicolon separator detected'   => array( 'https://example.com/app.js?ver=1;_=1718720000', true ),
			'empty src'                      => array( '', false ),
			'no query'                       => array( 'https://example.com/app.js', false ),
		);
	}

	/**
	 * Test the randomized-query guard truth table.
	 *
	 * @param string $src      Asset src URL.
	 * @param bool   $expected Expected verdict.
	 * @return void
	 */
	#[DataProvider( 'randomized_query_provider' )]
	public function test_is_randomized_query_asset( string $src, bool $expected ): void {
		$this->assertSame( $expected, Cache::is_randomized_query_asset( $src ) );
	}
}
