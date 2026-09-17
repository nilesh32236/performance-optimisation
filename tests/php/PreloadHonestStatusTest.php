<?php
/**
 * Tests for honest preload status with resume and WooCommerce-safe defaults.
 *
 * Covers issue #1372 acceptance criteria: zero-file runs never report
 * completed, resume retries only the failed queue, Woo/tracking URLs are
 * excluded, and skipped bookkeeping carries reasons.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cron;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests honest preload counters, failed-only resume, and URL cleaning.
 *
 * @package PerformanceOptimise\Tests
 */
class PreloadHonestStatusTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options store.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * URLs passed to wp_schedule_single_event().
	 *
	 * @var array
	 */
	private $scheduled_args = array();

	/**
	 * Install in-memory option + URL stubs.
	 */
	private function install_stubs(): void {
		Functions\stubs(
			array(
				'get_option',
				'update_option',
				'wp_parse_url',
				'home_url',
				'esc_url_raw',
				'wp_schedule_single_event',
				'wp_rand',
			)
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				unset( $autoload );
				$this->options[ $name ] = $value;
				return true;
			}
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_rand' )->justReturn( 0 );
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook, $args = array() ) {
				unset( $timestamp, $hook );
				$this->scheduled_args[] = $args;
				return true;
			}
		);
	}

	/**
	 * Zero-file run with failures must not report completed.
	 */
	public function test_zero_file_run_never_reports_completed(): void {
		$this->install_stubs();
		$this->options[ Cron::PRELOAD_QUEUE_OPTION ] = array(
			'queued'          => array(),
			'done'            => 0,
			'failed'          => array( 'http://example.com/broken/' ),
			'skipped'         => 0,
			'skipped_reasons' => array(),
			'total'           => 1,
			'status'          => 'complete',
			'updated_at'      => 0,
		);

		$status = Cron::get_preload_status();

		$this->assertNotSame( 'complete', $status['status'] );
		$this->assertSame( 'running', $status['status'] );
		$this->assertSame( 1, $status['failed'] );
	}

	/**
	 * Zero-file run without failures falls back to idle, never complete.
	 */
	public function test_zero_file_run_without_failures_reports_idle(): void {
		$this->install_stubs();
		$this->options[ Cron::PRELOAD_QUEUE_OPTION ] = array(
			'queued'          => array(),
			'done'            => 0,
			'failed'          => array(),
			'skipped'         => 2,
			'skipped_reasons' => array( 'woo-excluded' => 2 ),
			'total'           => 2,
			'status'          => 'complete',
			'updated_at'      => 0,
		);

		$status = Cron::get_preload_status();

		$this->assertNotSame( 'complete', $status['status'] );
		$this->assertSame( 'idle', $status['status'] );
		$this->assertSame( 2, $status['skipped'] );
		$this->assertSame( array( 'woo-excluded' => 2 ), $status['skipped_reasons'] );
	}

	/**
	 * Resume retries only the failed queue; still-queued URLs are untouched.
	 */
	public function test_resume_retries_only_failed_queue(): void {
		$this->install_stubs();
		$this->options[ Cron::PRELOAD_QUEUE_OPTION ] = array(
			'queued'          => array( 'http://example.com/waiting/' ),
			'done'            => 1,
			'failed'          => array( 'http://example.com/broken-a/', 'http://example.com/broken-b/' ),
			'skipped'         => 0,
			'skipped_reasons' => array(),
			'total'           => 4,
			'status'          => 'running',
			'updated_at'      => 0,
		);

		$rescheduled = Cron::resume_preload_queue();

		$this->assertSame( 2, $rescheduled );
		// Only failed URLs are re-scheduled — the waiting URL is not duplicated.
		$flat = array();
		foreach ( $this->scheduled_args as $args ) {
			foreach ( (array) $args as $chunk ) {
				foreach ( (array) $chunk as $u ) {
					$flat[] = $u;
				}
			}
		}
		$this->assertContains( 'http://example.com/broken-a/', $flat );
		$this->assertContains( 'http://example.com/broken-b/', $flat );
		$this->assertNotContains( 'http://example.com/waiting/', $flat );

		$queue = Cron::get_preload_queue();
		$this->assertSame( array(), $queue['failed'] );
		$this->assertContains( 'http://example.com/waiting/', $queue['queued'] );
		$this->assertContains( 'http://example.com/broken-a/', $queue['queued'] );
		$this->assertSame( 'running', $queue['status'] );
	}

	/**
	 * WooCommerce dynamic URLs are refused by the canonical cleaner.
	 */
	public function test_woo_urls_excluded_by_clean_preload_url(): void {
		$this->install_stubs();

		$this->assertSame( '', Util::clean_preload_url( 'http://example.com/cart/', true ) );
		$this->assertSame( '', Util::clean_preload_url( 'http://example.com/checkout/', true ) );
		$this->assertSame( '', Util::clean_preload_url( 'http://example.com/my-account/', true ) );
		$this->assertNotSame( '', Util::clean_preload_url( 'http://example.com/about/', true ) );
	}

	/**
	 * Tracking-only params strip to the canonical URL; functional params refuse.
	 */
	public function test_tracking_params_stripped_and_deduped(): void {
		$this->install_stubs();

		$this->assertSame(
			'http://example.com/about/',
			Util::strip_tracking_params( 'http://example.com/about/?utm_source=x&gclid=y' )
		);
		$this->assertSame(
			'http://example.com/about/?paged=2',
			Util::strip_tracking_params( 'http://example.com/about/?utm_source=x&paged=2' )
		);
		$this->assertSame(
			'http://example.com/about/',
			Util::clean_preload_url( 'http://example.com/about/?utm_source=x', true )
		);
		$this->assertSame( '', Util::clean_preload_url( 'http://example.com/about/?paged=2', true ) );
	}

	/**
	 * Skipped URLs grow skipped counters with reasons, never done.
	 */
	public function test_skipped_bookkeeping_with_reasons(): void {
		$this->install_stubs();
		$this->options[ Cron::PRELOAD_QUEUE_OPTION ] = array(
			'queued'          => array( 'http://example.com/cart/' ),
			'done'            => 0,
			'failed'          => array(),
			'skipped'         => 0,
			'skipped_reasons' => array(),
			'total'           => 1,
			'status'          => 'running',
			'updated_at'      => 0,
		);

		Cron::mark_preload_skipped( 'http://example.com/cart/', 'woo-excluded' );

		$queue = Cron::get_preload_queue();
		$this->assertSame( 0, $queue['done'] );
		$this->assertSame( 1, $queue['skipped'] );
		$this->assertSame( array( 'woo-excluded' => 1 ), $queue['skipped_reasons'] );
		$this->assertSame( 'idle', $queue['status'] );
	}
}
