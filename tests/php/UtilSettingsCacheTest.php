<?php
/**
 * Tests for Util settings memoization + deterministic invalidation
 * (audit #888 finding 4).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Settings-cache invalidation tests.
 *
 * @package PerformanceOptimise\Tests
 */
class UtilSettingsCacheTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options store.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Number of times get_option() was called (deserialization proxy).
	 *
	 * @var int
	 */
	private int $option_reads = 0;

	/**
	 * Install get_option/update_option stubs backed by an in-memory store.
	 */
	private function install_option_stubs(): void {
		$this->options      = array();
		$this->option_reads = 0;

		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				++$this->option_reads;
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'add_action' )->justReturn( true );
	}

	/**
	 * Eager registration must be idempotent (safe to call at boot and lazily).
	 */
	public function test_register_settings_cache_hooks_is_idempotent(): void {
		$this->install_option_stubs();

		Util::register_settings_cache_hooks();
		Util::register_settings_cache_hooks();
		Util::register_settings_cache_hooks();

		// No exception and no side effects on the memo.
		$this->assertSame( array(), Util::get_settings() );
	}

	/**
	 * The memo must collapse repeated calls to a single option read.
	 */
	public function test_get_settings_memoizes_option_reads(): void {
		$this->install_option_stubs();
		$this->options['wppo_settings'] = array( 'cache_settings' => array( 'enableCache' => true ) );

		$first  = Util::get_settings();
		$second = Util::get_settings();
		$third  = Util::get_settings();

		$this->assertSame( 1, $this->option_reads, 'get_settings() should deserialize the option once per request.' );
		$this->assertSame( $first, $second );
		$this->assertSame( $first, $third );
	}

	/**
	 * The update-option invalidation hook must make later get_settings() calls
	 * observe the new value (deterministic stale-cache fix).
	 */
	public function test_on_settings_update_invalidates_memo(): void {
		$this->install_option_stubs();
		$old                            = array( 'cache_settings' => array( 'enableCache' => false ) );
		$new                            = array( 'cache_settings' => array( 'enableCache' => true ) );
		$this->options['wppo_settings'] = $old;

		Util::register_settings_cache_hooks();
		$this->assertSame( $old, Util::get_settings() );

		// Simulate WP firing update_option_wppo_settings.
		Util::on_settings_update( $old, $new );

		$this->assertSame( $new, Util::get_settings() );
	}

	/**
	 * The add-option hook must populate the memo when the option is created.
	 */
	public function test_on_settings_add_populates_memo(): void {
		$this->install_option_stubs();

		Util::register_settings_cache_hooks();
		$value = array( 'database_cleanup' => array( 'revisions' => true ) );

		// Simulate WP firing add_option_wppo_settings.
		Util::on_settings_add( 'wppo_settings', $value );

		$this->assertSame( $value, Util::get_settings() );
		$this->assertSame( 0, $this->option_reads, 'Memo should serve the added value without a fresh option read.' );
	}

	/**
	 * Unrelated options must not populate the settings memo.
	 */
	public function test_on_settings_add_ignores_other_options(): void {
		$this->install_option_stubs();

		Util::on_settings_add( 'some_other_option', array( 'x' => true ) );

		$this->assertSame( array(), Util::get_settings() );
	}

	/**
	 * Clearing the memo forces the next read to re-fetch the option.
	 */
	public function test_clear_settings_cache_forces_refetch(): void {
		$this->install_option_stubs();
		$this->options['wppo_settings'] = array( 'a' => 1 );

		Util::get_settings();
		$this->options['wppo_settings'] = array( 'a' => 2 );

		// Memo still holds the old value.
		$this->assertSame( array( 'a' => 1 ), Util::get_settings() );

		Util::clear_settings_cache();
		$this->assertSame( array( 'a' => 2 ), Util::get_settings() );
		$this->assertSame( 2, $this->option_reads );
	}
}
