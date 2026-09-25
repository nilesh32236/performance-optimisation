<?php
/**
 * Behavior tests for the P3-008 Settings_Command write seam (issue #1580).
 *
 * @package PerformanceOptimise\Tests
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Settings_Command;
use PerformanceOptimise\Inc\Settings_Store;

/**
 * Settings_Command behavior tests.
 */
class SettingsCommandTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory WordPress option store.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Number of wppo_settings writes attempted.
	 *
	 * @var int
	 */
	private int $settings_writes = 0;

	/**
	 * Load the command class directly for a dirty development classmap.
	 *
	 * @return void
	 */
	private function load_command(): void {
		if ( ! class_exists( Settings_Command::class, false ) ) {
			require_once WPPO_PLUGIN_PATH . 'includes/Settings/class-settings-command.php';
		}
	}

	/**
	 * Install an in-memory option store shared by Settings_Store.
	 *
	 * @param bool $fail_settings_writes Whether wppo_settings writes should fail.
	 * @return void
	 */
	private function install_option_stubs( bool $fail_settings_writes = false ): void {
		$this->options         = array();
		$this->settings_writes = 0;
		Settings_Store::clear_settings_cache();
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( $fail_settings_writes ) {
				if ( 'wppo_settings' === $name ) {
					++$this->settings_writes;
					if ( $fail_settings_writes ) {
						return false;
					}
				}
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'add_action' )->justReturn( true );
	}

	/**
	 * A changed save snapshots the prior settings, writes once, and refreshes the memo.
	 *
	 * @return void
	 */
	public function test_save_snapshots_changed_settings_and_refreshes_memo(): void {
		$this->load_command();
		$this->install_option_stubs();
		$prior = array( 'file_optimisation' => array( 'minifyHTML' => false ) );
		$next  = array( 'file_optimisation' => array( 'minifyHTML' => true ) );

		$this->assertTrue( Settings_Command::save( $next, $prior ) );
		$this->assertSame( 1, $this->settings_writes );
		$this->assertSame( $prior, $this->options[ Settings_Store::SETTINGS_SNAPSHOT_OPTION ]['settings'] );
		$this->assertSame( $next, $this->options['wppo_settings'] );
		$this->assertSame( $next, Settings_Store::get_settings(), 'The Store memo must observe the command write without another option read.' );
	}

	/**
	 * An equal save preserves the existing snapshot while retaining the write result.
	 *
	 * @return void
	 */
	public function test_equal_save_does_not_churn_snapshot(): void {
		$this->load_command();
		$this->install_option_stubs();
		$current = array( 'file_optimisation' => array( 'minifyHTML' => true ) );
		$this->options[ Settings_Store::SETTINGS_SNAPSHOT_OPTION ] = array(
			'settings' => array( 'file_optimisation' => array( 'minifyHTML' => false ) ),
			'taken_at' => 1234567890,
		);

		$this->assertTrue( Settings_Command::save( $current, $current ) );
		$this->assertSame( 1, $this->settings_writes );
		$this->assertSame( array( 'file_optimisation' => array( 'minifyHTML' => false ) ), $this->options[ Settings_Store::SETTINGS_SNAPSHOT_OPTION ]['settings'] );
	}

	/**
	 * Snapshot failure must not block the bounded Store write.
	 *
	 * @return void
	 */
	public function test_snapshot_failure_does_not_block_write(): void {
		$this->load_command();
		$this->install_option_stubs( true );
		$prior = array( 'file_optimisation' => array( 'minifyHTML' => false ) );
		$next  = array( 'file_optimisation' => array( 'minifyHTML' => true ) );

		$this->assertFalse( Settings_Command::save( $next, $prior ) );
		$this->assertSame( 1, $this->settings_writes );
	}

	/**
	 * WordPress reports "unchanged" with the same false as a failed write.
	 *
	 * A caller that treats every false as a failure would reject an idempotent
	 * re-save, so the seam has to tell the two apart: the requested state is
	 * already persisted, which is a success.
	 *
	 * @return void
	 */
	public function test_unchanged_save_is_reported_as_success(): void {
		$this->load_command();
		$this->install_option_stubs();
		$current                        = array( 'file_optimisation' => array( 'minifyHTML' => true ) );
		$this->options['wppo_settings'] = $current;

		// Model WordPress: re-saving an identical value is not a write.
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				if ( 'wppo_settings' === $name ) {
					++$this->settings_writes;
					if ( array_key_exists( $name, $this->options ) && $this->options[ $name ] === $value ) {
						return false;
					}
				}
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Settings_Store::clear_settings_cache();

		$this->assertTrue( Settings_Command::save( $current, $current ), 'an unchanged save is a success' );
		$this->assertSame( 1, $this->settings_writes );
		$this->assertSame( $current, Settings_Store::get_settings(), 'the memo must still describe the stored settings' );
	}

	/**
	 * A failed write must not leave the memo describing an unsaved state.
	 *
	 * @return void
	 */
	public function test_failed_write_leaves_the_memo_on_the_stored_value(): void {
		$this->load_command();
		$this->install_option_stubs( true );
		$stored                         = array( 'file_optimisation' => array( 'minifyHTML' => false ) );
		$this->options['wppo_settings'] = $stored;
		Settings_Store::clear_settings_cache();

		$requested = array( 'file_optimisation' => array( 'minifyHTML' => true ) );
		$this->assertFalse( Settings_Command::save( $requested, $stored ) );
		$this->assertSame(
			$stored,
			Settings_Store::get_settings(),
			'the memo must describe what is stored, not what was requested'
		);
	}

	/**
	 * Runtime settings writes are owned by Settings_Store only.
	 *
	 * @return void
	 */
	public function test_no_direct_runtime_settings_writes_outside_store(): void {
		$violations = array();
		$files      = array_merge(
			(array) glob( WPPO_PLUGIN_PATH . 'includes/*/class-*.php' ),
			(array) glob( WPPO_PLUGIN_PATH . 'includes/class-*.php' )
		);
		foreach ( $files as $file ) {
			if ( 'includes/Settings/class-settings-store.php' === ltrim( str_replace( '\\', '/', substr( (string) $file, strlen( WPPO_PLUGIN_PATH ) ) ), '/' ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local source scan.
			$source = (string) file_get_contents( (string) $file );
			$tokens = token_get_all( $source );
			for ( $index = 0, $count = count( $tokens ); $index < $count; $index++ ) {
				if ( ! is_array( $tokens[ $index ] ) || 'update_option' !== $tokens[ $index ][1] || T_STRING !== $tokens[ $index ][0] ) {
					continue;
				}
				$next = $index + 1;
				while ( $next < $count && is_array( $tokens[ $next ] ) && T_WHITESPACE === $tokens[ $next ][0] ) {
					++$next;
				}
				if ( $next < $count && is_array( $tokens[ $next ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $next ][0] && "'wppo_settings'" === trim( (string) $tokens[ $next ][1], "'\"" ) ) {
					$violations[] = (string) $file;
					break;
				}
			}
		}
		$this->assertSame( array(), $violations );
	}

	/**
	 * Restore delegates to the Store and keeps its null/array return contract.
	 *
	 * @return void
	 */
	public function test_restore_preserves_store_return_contract(): void {
		$this->load_command();
		$this->install_option_stubs();
		$prior                          = array( 'file_optimisation' => array( 'minifyHTML' => false ) );
		$this->options['wppo_settings'] = array( 'file_optimisation' => array( 'minifyHTML' => true ) );
		$this->options[ Settings_Store::SETTINGS_SNAPSHOT_OPTION ] = array(
			'settings' => $prior,
			'taken_at' => 1234567890,
		);

		$this->assertSame( $prior, Settings_Command::restore_snapshot() );
		$this->assertSame( $prior, Settings_Store::get_settings(), 'Restore must keep the Store memo coherent.' );
	}
}
