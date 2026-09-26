<?php
/**
 * Tests that the Critical CSS regeneration cron is gated on `criticalCSS`.
 *
 * `schedule_cron_jobs()` scheduled `wppo_ccss_regeneration` unconditionally,
 * while the two events immediately above it — `wppo_llms_txt_daily` and
 * `wppo_used_css_cron` — were both gated on their own setting. The handler
 * `Cron::ccss_regeneration_cron()` early-returns when `criticalCSS` is off, so
 * the only cost was a daily cron event firing on **every** site, including the
 * large majority that never enables Critical CSS.
 *
 * Mirrors the shape of `CronPreloadGatingTest` so the two read the same way.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cron;
use Brain\Monkey\Functions;

/**
 * Tests that the Critical CSS cron is only scheduled while criticalCSS is on.
 *
 * @package PerformanceOptimise\Tests
 */
class CronCcssGatingTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * The hook under test.
	 *
	 * @var string
	 */
	private const HOOK = 'wppo_ccss_regeneration';

	/**
	 * In-memory options store.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Hooks recorded as scheduled.
	 *
	 * @var string[]
	 */
	private $scheduled = array();

	/**
	 * Hooks recorded as cleared.
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
	 * Install the option and cron-scheduling stubs.
	 *
	 * @return void
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
	 * The event is scheduled while Critical CSS is enabled.
	 *
	 * @return void
	 */
	public function test_schedules_ccss_cron_when_enabled(): void {
		$this->install_option_stubs();
		$this->options['wppo_settings'] = array(
			'file_optimisation' => array( 'criticalCSS' => true ),
		);

		$this->make_cron()->schedule_cron_jobs();

		$this->assertContains(
			self::HOOK,
			$this->scheduled,
			'Critical CSS is enabled, so its daily regeneration must still be scheduled'
		);
	}

	/**
	 * No event is scheduled while Critical CSS is disabled.
	 *
	 * @return void
	 */
	public function test_does_not_schedule_ccss_cron_when_disabled(): void {
		$this->install_option_stubs();
		$this->options['wppo_settings'] = array(
			'file_optimisation' => array( 'criticalCSS' => false ),
		);

		$this->make_cron()->schedule_cron_jobs();

		$this->assertNotContains(
			self::HOOK,
			$this->scheduled,
			'a site with Critical CSS off must not carry a daily regeneration event it will never act on'
		);
	}

	/**
	 * A pre-existing event is cleared when the feature is turned off.
	 *
	 * Without the clear branch, a site that enabled Critical CSS and then
	 * disabled it would keep firing a daily event indefinitely.
	 *
	 * @return void
	 */
	public function test_clears_existing_ccss_cron_when_disabled(): void {
		$this->install_option_stubs();
		$this->options['wppo_settings'] = array(
			'file_optimisation' => array( 'criticalCSS' => false ),
		);
		$this->scheduled[]              = self::HOOK;

		$this->make_cron()->schedule_cron_jobs();

		$this->assertContains(
			self::HOOK,
			$this->cleared,
			'a stale Critical CSS event must be removed when the feature is switched off'
		);
	}

	/**
	 * Absent settings behave like disabled, not like enabled.
	 *
	 * `schedule_cron_jobs()` runs on every `init`, so a site whose options have
	 * not been written yet must not accumulate events either.
	 *
	 * @return void
	 */
	public function test_absent_settings_do_not_schedule(): void {
		$this->install_option_stubs();
		$this->options['wppo_settings'] = array();

		$this->make_cron()->schedule_cron_jobs();

		$this->assertNotContains( self::HOOK, $this->scheduled );
	}
}
