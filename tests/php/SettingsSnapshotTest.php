<?php
/**
 * Tests for safe-by-default fresh installs plus the one-click-undo settings snapshot (issue #1144).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Covers the canonical fresh-install defaults (page cache ON only,
 * aggressive pipelines OFF) and the Util snapshot helpers
 * (take/get/restore round-trip, fail-open paths).
 *
 * @package PerformanceOptimise\Tests
 */
class SettingsSnapshotTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory option store shared by get_option/update_option stubs.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Install get_option/update_option stubs backed by $this->options.
	 *
	 * @param bool $fail_writes When true, update_option() reports failure without writing.
	 */
	private function install_option_stubs( bool $fail_writes = false ): void {
		$this->options = array();
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) use ( $fail_writes ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature must match update_option().
				if ( $fail_writes ) {
					return false;
				}
				$this->options[ $name ] = $value;
				return true;
			}
		);
	}

	/**
	 * Fresh installs must enable the page cache only: every aggressive
	 * pipeline key stays OFF until explicitly enabled.
	 */
	public function test_fresh_install_defaults_enable_page_cache_only(): void {
		$defaults = Util::get_default_settings();

		$this->assertTrue( $defaults['cache_settings']['enableCache'], 'Fresh installs must ship with the page cache enabled' );

		$aggressive_off = array(
			'combineCSS',
			'delayJS',
			'deferJS',
			'minifyJS',
			'minifyCSS',
			'removeUnusedCSS',
			'criticalCSS',
		);
		foreach ( $aggressive_off as $key ) {
			$this->assertArrayHasKey( $key, $defaults['file_optimisation'], "Aggressive key {$key} must exist in defaults" );
			$this->assertFalse( $defaults['file_optimisation'][ $key ], "Aggressive key {$key} must default to OFF" );
		}
	}

	/**
	 * take_settings_snapshot() must persist the given settings with a timestamp.
	 */
	public function test_take_and_get_snapshot_round_trip(): void {
		$this->install_option_stubs();

		$settings = array( 'file_optimisation' => array( 'minifyHTML' => false ) );

		$this->assertTrue( Util::take_settings_snapshot( $settings ) );

		$snapshot = Util::get_settings_snapshot();
		$this->assertIsArray( $snapshot );
		$this->assertSame( $settings, $snapshot['settings'] );
		$this->assertArrayHasKey( 'taken_at', $snapshot );
		$this->assertIsInt( $snapshot['taken_at'] );
	}

	/**
	 * get_settings_snapshot() must return null when absent or malformed.
	 */
	public function test_get_snapshot_returns_null_when_absent_or_malformed(): void {
		$this->install_option_stubs();

		$this->assertNull( Util::get_settings_snapshot(), 'Absent snapshot must read as null' );

		$this->options[ Util::SETTINGS_SNAPSHOT_OPTION ] = array( 'taken_at' => 123 );
		$this->assertNull( Util::get_settings_snapshot(), 'Snapshot without a settings array must read as null' );

		$this->options[ Util::SETTINGS_SNAPSHOT_OPTION ] = 'not-an-array';
		$this->assertNull( Util::get_settings_snapshot(), 'Non-array snapshot must read as null' );
	}

	/**
	 * restore_settings_snapshot() must write the snapshot back to wppo_settings.
	 */
	public function test_restore_round_trip_returns_prior_settings(): void {
		$this->install_option_stubs();

		$prior = array( 'file_optimisation' => array( 'minifyHTML' => false ) );
		$this->options['wppo_settings'] = $prior;
		$this->assertTrue( Util::take_settings_snapshot( $prior ) );

		$new = array( 'file_optimisation' => array( 'minifyHTML' => true ) );
		$this->options['wppo_settings'] = $new;
		Util::clear_settings_cache();

		$restored = Util::restore_settings_snapshot();
		$this->assertSame( $prior, $restored );
		$this->assertSame( $prior, $this->options['wppo_settings'], 'Stored settings must be rolled back to the snapshot' );
		$this->assertSame( $prior, Util::get_settings(), 'Settings memo must reflect the restored settings' );
	}

	/**
	 * restore_settings_snapshot() must fail open (null, current settings
	 * intact) when no snapshot exists.
	 */
	public function test_restore_returns_null_without_snapshot(): void {
		$this->install_option_stubs();

		$current                          = array( 'file_optimisation' => array( 'minifyHTML' => true ) );
		$this->options['wppo_settings'] = $current;

		$this->assertNull( Util::restore_settings_snapshot() );
		$this->assertSame( $current, $this->options['wppo_settings'], 'Current settings must stay intact when no snapshot exists' );
	}

	/**
	 * restore_settings_snapshot() must fail open when the option write fails.
	 */
	public function test_restore_returns_null_when_write_fails(): void {
		$this->install_option_stubs( true );

		$prior = array( 'file_optimisation' => array( 'minifyHTML' => false ) );
		// Seed the snapshot directly: writes fail, so take_settings_snapshot()
		// cannot persist it through the stub.
		$this->options[ Util::SETTINGS_SNAPSHOT_OPTION ] = array(
			'settings' => $prior,
			'taken_at' => 1234567890,
		);
		$current                          = array( 'file_optimisation' => array( 'minifyHTML' => true ) );
		$this->options['wppo_settings'] = $current;

		$this->assertNull( Util::restore_settings_snapshot() );
		$this->assertSame( $current, $this->options['wppo_settings'], 'Current settings must stay intact when the restore write fails' );
	}

	/**
	 * The snapshot option key must be a registered uninstall row so snapshots
	 * never leak after uninstall.
	 */
	public function test_snapshot_option_key_registered_for_uninstall(): void {
		$this->assertSame( 'wppo_settings_snapshot', Util::SETTINGS_SNAPSHOT_OPTION );
		$this->assertContains( Util::SETTINGS_SNAPSHOT_OPTION, Util::UNINSTALL_OPTIONS );
	}
}
