<?php
/**
 * Tests that the image-conversion cron is gated on `convertImg`.
 *
 * `schedule_cron_jobs()` scheduled `wppo_img_conversion` unconditionally,
 * while its neighbour — the preload block — was gated on its own setting
 * (schedule when enabled, `wp_clear_scheduled_hook()` when not). The handler
 * `Cron::img_convert_cron()` checked only the lock transient, never
 * `image_optimisation.convertImg`, so a stale event kept converting after the
 * feature was disabled (issue #1771).
 *
 * Mirrors the shape of `CronCcssGatingTest` so the two read the same way.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cron;
use Brain\Monkey\Functions;

/**
 * Tests that the image-conversion cron only runs while convertImg is on.
 *
 * @package PerformanceOptimise\Tests
 */
class CronImgConversionGatingTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * The hook under test.
	 *
	 * @var string
	 */
	private const HOOK = 'wppo_img_conversion';

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
	 * The event is scheduled while image conversion is enabled.
	 *
	 * @return void
	 */
	public function test_schedules_img_conversion_cron_when_enabled(): void {
		$this->install_option_stubs();
		$this->options['wppo_settings'] = array(
			'image_optimisation' => array( 'convertImg' => true ),
		);

		$this->make_cron()->schedule_cron_jobs();

		$this->assertContains(
			self::HOOK,
			$this->scheduled,
			'Image conversion is enabled, so its hourly event must still be scheduled'
		);
	}

	/**
	 * No event is scheduled while image conversion is disabled.
	 *
	 * @return void
	 */
	public function test_does_not_schedule_img_conversion_cron_when_disabled(): void {
		$this->install_option_stubs();
		$this->options['wppo_settings'] = array(
			'image_optimisation' => array( 'convertImg' => false ),
		);

		$this->make_cron()->schedule_cron_jobs();

		$this->assertNotContains(
			self::HOOK,
			$this->scheduled,
			'a site with image conversion off must not carry an hourly event it will never act on'
		);
	}

	/**
	 * A pre-existing event is cleared when the feature is turned off.
	 *
	 * Without the clear branch, a site that enabled image conversion and then
	 * disabled it would keep firing an hourly event indefinitely.
	 *
	 * @return void
	 */
	public function test_clears_existing_img_conversion_cron_when_disabled(): void {
		$this->install_option_stubs();
		$this->options['wppo_settings'] = array(
			'image_optimisation' => array( 'convertImg' => false ),
		);
		$this->scheduled[]              = self::HOOK;

		$this->make_cron()->schedule_cron_jobs();

		$this->assertContains(
			self::HOOK,
			$this->cleared,
			'a stale image-conversion event must be removed when the feature is switched off'
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

	/**
	 * The callback returns early when the feature is off, even if invoked directly.
	 *
	 * A stale event left over from before the feature was disabled must stop
	 * doing work. The early return sits before the lock, so a disabled feature
	 * never touches the lock transient either.
	 *
	 * @return void
	 */
	public function test_callback_returns_early_when_disabled(): void {
		$this->install_option_stubs();
		$this->options['wppo_settings'] = array(
			'image_optimisation' => array( 'convertImg' => false ),
		);

		$transient_reads  = 0;
		$transient_writes = 0;
		Functions\when( 'get_transient' )->alias(
			function ( $key ) use ( &$transient_reads ) {
				unset( $key );
				++$transient_reads;
				return false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $expiration = 0 ) use ( &$transient_writes ) {
				unset( $key, $value, $expiration );
				++$transient_writes;
				return true;
			}
		);
		Functions\when( 'is_multisite' )->justReturn( false );

		$this->make_cron()->img_convert_cron();

		$this->assertSame( 0, $transient_reads, 'disabled conversion must return before reading the lock transient' );
		$this->assertSame( 0, $transient_writes, 'disabled conversion must return before taking the lock transient' );
	}
}
