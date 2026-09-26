<?php
/**
 * Tests for the cache preload cron gating on enablePreloadCache.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cron;
use Brain\Monkey\Functions;

/**
 * Tests that the preload cron is only scheduled while enablePreloadCache is on.
 *
 * @package PerformanceOptimise\Tests
 */
class CronPreloadGatingTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options store.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Hooks recorded as scheduled by wp_schedule_event().
	 *
	 * @var string[]
	 */
	private $scheduled = array();

	/**
	 * Hooks recorded as cleared by wp_clear_scheduled_hook().
	 *
	 * @var string[]
	 */
	private $cleared = array();

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
	 * Install option + cron-scheduling stubs backed by $this->options/$this->scheduled.
	 */
	private function install_option_stubs(): void {
		Functions\stubs(
			array(
				'get_option',
				'wp_next_scheduled',
				'wp_schedule_event',
				'wp_clear_scheduled_hook',
			)
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'wp_next_scheduled' )->alias(
			function ( $hook ) {
				return in_array( $hook, $this->scheduled, true );
			}
		);
		Functions\when( 'wp_schedule_event' )->alias(
			function ( $timestamp, $recurrence, $hook ) {
				$this->scheduled[] = $hook;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) {
				$this->cleared[] = $hook;
			}
		);
	}

	/**
	 * Test that the preload cron is scheduled when enablePreloadCache is on.
	 */
	public function test_schedules_preload_cron_when_enabled(): void {
		$this->install_option_stubs();
		$this->options['wppo_settings'] = array(
			'preload_settings' => array( 'enablePreloadCache' => true ),
		);

		$this->make_cron()->schedule_cron_jobs();

		$this->assertContains( 'wppo_page_cron_hook', $this->scheduled );
	}

	/**
	 * Test that the preload cron is NOT scheduled when enablePreloadCache is off.
	 */
	public function test_does_not_schedule_preload_cron_when_disabled(): void {
		$this->install_option_stubs();
		$this->options['wppo_settings'] = array(
			'preload_settings' => array( 'enablePreloadCache' => false ),
		);

		$this->make_cron()->schedule_cron_jobs();

		$this->assertNotContains( 'wppo_page_cron_hook', $this->scheduled );
	}

	/**
	 * Test that a pre-existing preload cron is cleared when the toggle is off.
	 */
	public function test_clears_existing_preload_cron_when_disabled(): void {
		$this->install_option_stubs();
		$this->options['wppo_settings'] = array(
			'preload_settings' => array( 'enablePreloadCache' => false ),
		);
		$this->scheduled[]              = 'wppo_page_cron_hook';

		$this->make_cron()->schedule_cron_jobs();

		$this->assertContains( 'wppo_page_cron_hook', $this->cleared );
	}
}
