<?php
/**
 * Tests for `wp wppo settings` (issue: CLI settings writes bypassed the
 * Settings_Command seam).
 *
 * The two highest-impact WP-CLI settings defects lived in entirely untested
 * code: an unconditional undo snapshot that was rewritten even when the write
 * changed nothing, and a "successfully" message printed when the database
 * write did not persist.
 *
 * @package PerformanceOptimise\Tests
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Settings_Store;
use PerformanceOptimise\Inc\Util;
use PerformanceOptimise\Inc\WPPO_CLI_Command;

require_once __DIR__ . '/stubs/wp-cli.php';

/**
 * WP-CLI settings subcommand tests.
 */
class WppoCliSettingsTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory WordPress option store.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Number of wppo_settings writes that returned false.
	 *
	 * @var bool
	 */
	private bool $writes_fail = false;

	/**
	 * Reset recorded CLI output and the log-table double.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( class_exists( 'WP_CLI' ) && method_exists( 'WP_CLI', 'reset_output' ) ) {
			WP_CLI::reset_output();
		}
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Install an in-memory option store shared by Settings_Store.
	 *
	 * Mirrors WordPress: an identical value is not a write and returns false
	 * without changing the stored value.
	 *
	 * @return void
	 */
	private function install_option_stubs(): void {
		$this->options = array();
		Settings_Store::clear_settings_cache();
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				if ( 'wppo_settings' === $name && $this->writes_fail ) {
					return false;
				}
				if ( array_key_exists( $name, $this->options ) && $this->options[ $name ] === $value ) {
					return false;
				}
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\stubs(
			array(
				'sanitize_key',
				'sanitize_text_field',
				'sanitize_textarea_field',
				'wp_unslash',
				'add_action',
				'current_time',
				'wp_kses_post',
				'wp_check_invalid_utf8',
				'_mb_strlen',
			)
		);
		Functions\when( 'sanitize_key' )->alias(
			static function ( $value ) {
				return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( 1700000000 );
		// Log::add() writes to the activity-log table; keep it out of the way.
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_check_invalid_utf8' )->justReturn( '' );
		Functions\when( '_mb_strlen' )->alias(
			static function ( $value ) {
				return strlen( (string) $value );
			}
		);
		Functions\when( '__' )->returnArg( 1 );
		// Log::add() writes to the activity-log table; a minimal double keeps
		// the assertion surface on the settings behaviour under test.
		$GLOBALS['wpdb'] = new class() {
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Record a row.
			 *
			 * @param string $table Table name.
			 * @param array  $data  Row data.
			 * @param array  $format Unused.
			 * @return int
			 */
			public function insert( $table, $data, $format = null ) {
				unset( $table, $data, $format );
				return 1;
			}
		};
	}

	/**
	 * Run the update subcommand against the in-memory store.
	 *
	 * @param array  $stored Stored wppo_settings.
	 * @param string $tab    Settings tab to update.
	 * @param string $json   JSON settings payload.
	 * @return void
	 */
	private function run_update( array $stored, string $tab = 'file_optimisation', string $json = '{"minifyHTML":true}' ): void {
		$this->options['wppo_settings'] = $stored;
		Settings_Store::clear_settings_cache();
		$command = new WPPO_CLI_Command();
		$command->settings( array( 'update', $tab ), array( 'settings' => $json ) );
	}

	/**
	 * A no-op write must not destroy the one-click undo snapshot.
	 *
	 * The snapshot is a single slot, so rewriting it with the current state on
	 * a write that changed nothing makes the next "Undo settings" restore the
	 * settings already in place, and the operator's last real change becomes
	 * unrecoverable.
	 *
	 * @return void
	 */
	public function test_noop_update_preserves_the_undo_snapshot(): void {
		$this->install_option_stubs();
		$stored                         = array( 'file_optimisation' => array( 'minifyHTML' => true ) );
		$this->options['wppo_settings'] = $stored;
		// A snapshot of a genuinely different earlier state.
		$undo = array( 'settings' => array( 'file_optimisation' => array( 'minifyHTML' => false ) ) );
		$this->options[ Settings_Store::SETTINGS_SNAPSHOT_OPTION ] = $undo;

		$this->run_update( $stored );

		$this->assertSame(
			$undo,
			$this->options[ Settings_Store::SETTINGS_SNAPSHOT_OPTION ],
			'a write that changed nothing must not overwrite the undo snapshot'
		);
	}

	/**
	 * A real change must still take a snapshot of the prior settings.
	 *
	 * @return void
	 */
	public function test_changed_update_still_snapshots_prior_settings(): void {
		$this->install_option_stubs();
		$stored                         = array( 'file_optimisation' => array( 'minifyHTML' => false ) );
		$this->options['wppo_settings'] = $stored;
		$this->options[ Settings_Store::SETTINGS_SNAPSHOT_OPTION ] = array(
			'settings' => array(),
			'taken_at' => 1,
		);

		$this->run_update( $stored );

		$this->assertArrayHasKey( Settings_Store::SETTINGS_SNAPSHOT_OPTION, $this->options );
		$snapshot = $this->options[ Settings_Store::SETTINGS_SNAPSHOT_OPTION ];
		$this->assertIsArray( $snapshot );
		$this->assertArrayHasKey( 'settings', $snapshot );
		// The CLI snapshots the defaults-merged prior state, so assert the
		// changed key rather than exact equality with the raw stored array.
		$this->assertFalse(
			$snapshot['settings']['file_optimisation']['minifyHTML'] ?? null,
			'the snapshot must hold the prior value of the changed key'
		);
		$this->assertTrue( $this->options['wppo_settings']['file_optimisation']['minifyHTML'] );
		$this->assertNotEmpty( WP_CLI::$successes );
	}

	/**
	 * A write that did not persist must be reported as an error.
	 *
	 * @return void
	 */
	public function test_failed_write_is_reported_as_an_error(): void {
		$this->install_option_stubs();
		$stored            = array( 'file_optimisation' => array( 'minifyHTML' => false ) );
		$this->writes_fail = true;

		$this->run_update( $stored );

		$this->assertNotEmpty( WP_CLI::$errors, 'a failed database write must be reported' );
		$this->assertSame( array(), WP_CLI::$successes, 'a failed write must not be announced as a success' );
		$this->assertFalse(
			$this->options['wppo_settings']['file_optimisation']['minifyHTML'],
			'the stored settings must be unchanged after a failed write'
		);
	}
}
