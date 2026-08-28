<?php
/**
 * Tests for the Cron Web Vitals auto-rescan cron.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cron;
use PerformanceOptimise\Inc\Pagespeed;
use Brain\Monkey\Functions;

/**
 * Tests Web Vitals auto-rescan scheduling on the Cron class.
 *
 * @package PerformanceOptimise\Tests
 */
class CronWebVitalsRescanTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options store.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Build a Cron instance without invoking the constructor.
	 *
	 * @return Cron
	 */
	private function make_cron(): Cron {
		$reflection = new \ReflectionClass( Cron::class );
		return $reflection->newInstanceWithoutConstructor();
	}

	/**
	 * Install get_option/update_option stubs backed by $this->options.
	 */
	private function install_option_stubs(): void {
		Functions\stubs(
			array(
				'get_option',
				'update_option',
				'get_transient',
				'set_transient',
				'delete_transient',
				'sanitize_text_field',
				'home_url',
				'esc_url_raw',
				'as_enqueue_async_action',
				'is_multisite',
				'get_current_blog_id',
			)
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
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
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'http://example.com/' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );
	}

	/**
	 * Test that the cron queues scans for home + high-value URLs on both strategies.
	 */
	public function test_rescan_queues_home_and_high_value_urls(): void {
		$this->install_option_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array(
				'auto_rescan'     => 'daily',
				'high_value_urls' => array( 'http://example.com/pricing/' ),
			),
		);

		$queued = array();
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( $hook, $args ) use ( &$queued ) {
				$queued[] = $args[0] ?? array();
				return 1;
			}
		);

		$cron = $this->make_cron();
		$cron->web_vitals_rescan_cron();

		$this->assertCount( 4, $queued );
		$strategy_pairs = array();
		foreach ( $queued as $job ) {
			$strategy_pairs[] = $job['strategy'];
		}
		// Two URLs x two strategies.
		$this->assertSame( array( 'mobile', 'desktop', 'mobile', 'desktop' ), $strategy_pairs );
		$this->assertSame( 'http://example.com/', $queued[0]['url'] );
		$this->assertSame( 'http://example.com/pricing/', $queued[2]['url'] );
	}

	/**
	 * Test that the cron does nothing when auto_rescan is disabled.
	 */
	public function test_rescan_skips_when_disabled(): void {
		$this->install_option_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'auto_rescan' => '' ),
		);

		Functions\expect( 'as_enqueue_async_action' )->never();

		$cron = $this->make_cron();
		$cron->web_vitals_rescan_cron();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test that weekly rescan throttles itself within the window.
	 */
	public function test_rescan_weekly_throttles(): void {
		$this->install_option_stubs();
		$this->options['wppo_settings']               = array(
			'performance_audit' => array( 'auto_rescan' => 'weekly' ),
		);
		$this->options['wppo_web_vitals_last_rescan'] = time();

		Functions\expect( 'as_enqueue_async_action' )->never();

		$cron = $this->make_cron();
		$cron->web_vitals_rescan_cron();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test that weekly rescan runs when the window has elapsed.
	 */
	public function test_rescan_weekly_runs_after_window(): void {
		$this->install_option_stubs();
		$this->options['wppo_settings']               = array(
			'performance_audit' => array( 'auto_rescan' => 'weekly' ),
		);
		$this->options['wppo_web_vitals_last_rescan'] = time() - WEEK_IN_SECONDS - 100;

		$queued = 0;
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function () use ( &$queued ) {
				++$queued;
				return 1;
			}
		);

		$cron = $this->make_cron();
		$cron->web_vitals_rescan_cron();

		$this->assertGreaterThanOrEqual( 2, $queued );
		$this->assertArrayHasKey( 'wppo_web_vitals_last_rescan', $this->options );
	}

	/**
	 * Test that a failed enqueue does not record a completed rescan.
	 *
	 * The queue_scan() method returns 0 when Action Scheduler cannot create a
	 * job. The weekly gate must not treat a failed run as completed, otherwise
	 * retries are blocked for the full window.
	 */
	public function test_rescan_does_not_record_run_when_enqueue_fails(): void {
		$this->install_option_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'auto_rescan' => 'daily' ),
		);

		// Every enqueue fails.
		Functions\when( 'as_enqueue_async_action' )->justReturn( 0 );

		$cron = $this->make_cron();
		$cron->web_vitals_rescan_cron();

		$this->assertArrayNotHasKey( 'wppo_web_vitals_last_rescan', $this->options );
	}
}
