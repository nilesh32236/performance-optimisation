<?php
/**
 * Source/parity tests for the P3-016 Settings_Migrations Main-bridge removal.
 *
 * Proves Settings_Migrations no longer stores or calls Main (narrow
 * reader/sync ports only) while options/invalidation semantics are unchanged:
 * backfills persist, memo sync fires, explicit values and fresh installs stay
 * no-ops, and the Main facade still delegates with unchanged callback
 * identity.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Settings_Migrations;
use Brain\Monkey\Functions;

/**
 * Bridge-removal coverage for the settings-migration cluster (issue #1597).
 *
 * @package PerformanceOptimise\Tests
 */
class SettingsMigrationsBridgeTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Original global $wpdb before it is swapped for the test fake.
	 *
	 * @var object
	 */
	private $original_wpdb;

	/**
	 * Set up BrainMonkey and swap in a fake $wpdb so Log::add() can run.
	 */
	protected function setUp(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		parent::setUp();
		$this->register_common_function_stubs();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		global $wpdb;
		$this->original_wpdb = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wpdb                = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Record an insert into the activity log table.
			 *
			 * @param string $table  Table name.
			 * @param array  $data   Data to insert.
			 * @param array  $format Format array.
			 * @return int
			 */
			public function insert( $table, $data, $format = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
				return 1;
			}
		};
	}

	/**
	 * Restore the original $wpdb and tear down BrainMonkey.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		global $wpdb;
		$wpdb = $this->original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tearDown();
	}

	/**
	 * Build an array-backed runner with observable ports.
	 *
	 * @param array $memo    Seeded memo snapshot.
	 * @param array $synced  Out-param collecting [section, key, value] syncs.
	 * @param int   $reads   Out-param counting reader invocations.
	 * @return Settings_Migrations Runner under test.
	 */
	private function make_array_runner( array $memo, array &$synced, int &$reads ): Settings_Migrations {
		$box       = new \stdClass();
		$box->memo = $memo;

		$reader = static function () use ( $box, &$reads ): ?array {
			++$reads;
			return is_array( $box->memo ) ? $box->memo : null;
		};

		$sync = static function ( string $section, string $key, $value ) use ( $box, &$synced ): void {
			$synced[] = array( $section, $key, $value );
			if ( ! is_array( $box->memo ) ) {
				$box->memo = array();
			}
			if ( ! isset( $box->memo[ $section ] ) || ! is_array( $box->memo[ $section ] ) ) {
				$box->memo[ $section ] = array();
			}
			$box->memo[ $section ][ $key ] = $value;
		};

		$runner = new Settings_Migrations( null, $reader, $sync );
		return $runner;
	}

	/**
	 * Stub the settings option with write-through so re-runs observe writes.
	 *
	 * @param mixed $stored Stored wppo_settings value (or false for no row).
	 * @param array $writes Out-param collecting [key, value] writes.
	 * @return void
	 */
	private function stub_store( &$stored, &$writes ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default_value = false ) use ( &$stored ) {
				return 'wppo_settings' === $key ? $stored : $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$stored, &$writes ) {
				$writes[] = array( $key, $value );
				if ( 'wppo_settings' === $key ) {
					$stored = $value;
				}
				return true;
			}
		);
		Functions\when( 'wp_kses_post' )->returnArg();
	}

	/**
	 * Filter collected writes down to wppo_settings persists.
	 *
	 * Log::add() may bump its own salt/cache-version option; only
	 * wppo_settings writes count as migration writes.
	 *
	 * @param array $writes Collected [key, value] writes.
	 * @return array wppo_settings writes only.
	 */
	private function settings_writes( array $writes ): array {
		return array_values(
			array_filter(
				$writes,
				static function ( $write ) {
					return 'wppo_settings' === $write[0];
				}
			)
		);
	}

	/**
	 * Settings_Migrations must not store a Main-typed property.
	 */
	public function test_no_main_typed_property(): void {
		$reflection = new ReflectionClass( Settings_Migrations::class );
		$props      = $reflection->getProperties();
		$this->assertNotEmpty( $props, 'Settings_Migrations must declare its ports' );
		foreach ( $props as $property ) {
			$type = $property->getType();
			if ( $type instanceof ReflectionNamedType ) {
				$this->assertNotSame(
					Main::class,
					ltrim( $type->getName(), '\\' ),
					'Settings_Migrations::$' . $property->getName() . ' must not be Main-typed (P3-016)'
				);
			} else {
				$this->assertFalse(
					$property->isReadOnly(),
					'Settings_Migrations::$' . $property->getName() . ' checked (untyped port, no Main type)'
				);
			}
		}
		$this->assertTrue( true );
	}

	/**
	 * Migration bodies must not call Main or the removed reference bridge.
	 *
	 * Scans code lines only (docblocks stripped) so historical @see notes do
	 * not count as calls.
	 */
	public function test_no_main_calls_in_bodies(): void {
		$path   = dirname( __DIR__, 2 ) . '/includes/Settings/class-settings-migrations.php';
		$source = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local file scan (no remote URL, no WP_Filesystem in unit tests).
		$this->assertNotSame( '', $source );

		$code = (string) preg_replace( '#/\*.*?\*/#s', '', $source );
		$code = (string) preg_replace( '#//[^\n]*#', '', $code );

		$this->assertDoesNotMatchRegularExpression(
			'/\\$this->main/',
			$code,
			'Settings_Migrations bodies must not touch $this->main (P3-016)'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/migration_options_ref/',
			$code,
			'Settings_Migrations bodies must not call migration_options_ref() (P3-016)'
		);
	}

	/**
	 * Main must no longer expose the removed reference bridge.
	 */
	public function test_main_bridge_removed(): void {
		$this->assertFalse(
			method_exists( Main::class, 'migration_options_ref' ),
			'Main::migration_options_ref() must be removed (P3-016)'
		);
		$this->assertTrue(
			method_exists( Main::class, 'sync_migration_memo' ),
			'Main::sync_migration_memo() must exist as the narrow port (P3-016)'
		);
	}

	/**
	 * Backfill parity through callables: persists defaults and syncs the memo.
	 */
	public function test_callable_backfill_persists_and_syncs(): void {
		$stored = array( 'file_optimisation' => array( 'minifyJS' => true ) );
		$writes = array();
		$this->stub_store( $stored, $writes );

		$synced = array();
		$reads  = 0;
		$runner = $this->make_array_runner( array(), $synced, $reads );
		$runner->migrate_ccss_max_size();

		$persisted = $this->settings_writes( $writes );
		$this->assertCount( 1, $persisted );
		$this->assertSame( 20480, $persisted[0][1]['file_optimisation']['ccssMaxSize'] );
		$this->assertTrue( $persisted[0][1]['file_optimisation']['minifyJS'] );
		$this->assertSame(
			array( array( 'file_optimisation', 'ccssMaxSize', 20480 ) ),
			$synced,
			'memo sync must fire exactly once with the backfilled pair'
		);
	}

	/**
	 * Explicit stored values stay verbatim with zero writes and zero syncs.
	 */
	public function test_callable_explicit_values_are_preserved(): void {
		$stored = array( 'file_optimisation' => array( 'ccssMaxSize' => 1024 ) );
		$writes = array();
		$this->stub_store( $stored, $writes );

		$synced = array();
		$reads  = 0;
		$runner = $this->make_array_runner( array( 'file_optimisation' => array( 'ccssMaxSize' => 1024 ) ), $synced, $reads );
		$runner->migrate_ccss_max_size();

		$this->assertSame( array(), $this->settings_writes( $writes ) );
		$this->assertSame( array(), $synced );
	}

	/**
	 * Fresh installs (no stored row) perform zero writes and zero syncs.
	 */
	public function test_callable_fresh_install_writes_nothing(): void {
		$stored = false;
		$writes = array();
		$this->stub_store( $stored, $writes );

		$synced = array();
		$reads  = 0;
		$runner = $this->make_array_runner( array(), $synced, $reads );
		$runner->migrate_ccss_max_size();

		$this->assertSame( array(), $this->settings_writes( $writes ) );
		$this->assertSame( array(), $synced );
	}

	/**
	 * Memo fast-path parity: a completed migration avoids the stored read.
	 */
	public function test_callable_memo_early_return_skips_read(): void {
		$reads = array();

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default_value = false ) use ( &$reads ) {
				$reads[] = $key;
				return $default_value;
			}
		);

		$synced       = array();
		$reader_calls = 0;
		$runner       = $this->make_array_runner( array( 'object_cache' => array( 'outage_bypassed' => false ) ), $synced, $reader_calls );
		$runner->migrate_object_cache_outage_flag();

		$this->assertNotContains( 'wppo_settings', $reads );
		$this->assertSame( array(), $synced );
		$this->assertGreaterThan( 0, $reader_calls, 'fast path must consult the reader port' );
	}

	/**
	 * Retain-unless-absent parity: present memo keys are never overwritten.
	 */
	public function test_callable_retain_unless_absent(): void {
		$stored = array( 'file_optimisation' => array( 'minifyJS' => true ) );
		$writes = array();
		$this->stub_store( $stored, $writes );

		$synced = array();
		$reads  = 0;
		$runner = $this->make_array_runner( array( 'file_optimisation' => array( 'ccssQueueCap' => 9 ) ), $synced, $reads );
		$runner->migrate_css_queue_defaults();

		$persisted = $this->settings_writes( $writes );
		$this->assertCount( 1, $persisted );
		// Stored row backfills every absent key, including the one the memo has.
		$this->assertSame( 5, $persisted[0][1]['file_optimisation']['ccssQueueCap'] );

		$synced_keys = array_column( $synced, 1 );
		$this->assertNotContains(
			'ccssQueueCap',
			$synced_keys,
			'memo keys already present must be retained, never overwritten'
		);
		$this->assertContains( 'usedCssQueueCap', $synced_keys );
	}

	/**
	 * Legacy Main-arg construction still works (compatibility, never stored).
	 */
	public function test_legacy_main_arg_derives_ports(): void {
		$main = ( new ReflectionClass( Main::class ) )->newInstanceWithoutConstructor();

		$options_prop = new ReflectionProperty( Main::class, 'options' );
		$options_prop->setValue( $main, array() );

		$stored = array( 'file_optimisation' => array( 'minifyJS' => true ) );
		$writes = array();
		$this->stub_store( $stored, $writes );

		$runner = new Settings_Migrations( $main );
		$runner->migrate_ccss_max_size();

		$persisted = $this->settings_writes( $writes );
		$this->assertCount( 1, $persisted );
		$this->assertSame( 20480, $persisted[0][1]['file_optimisation']['ccssMaxSize'] );

		$memo = $options_prop->getValue( $main );
		$this->assertSame( 20480, $memo['file_optimisation']['ccssMaxSize'] );
	}
}
