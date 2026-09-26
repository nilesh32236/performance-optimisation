<?php
/**
 * Tests for Main::migrate_block_assets_setting().
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;
use Brain\Monkey\Functions;

/**
 * Tests for the WP 6.9 one-time block-assets setting migration.
 *
 * @package PerformanceOptimise\Tests
 */
class BlockAssetsMigrationTest extends \PHPUnit\Framework\TestCase {
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
			 * Number of insert() invocations.
			 *
			 * @var int
			 */
			public $insert_calls = 0;

			/**
			 * Record an insert into the activity log table.
			 *
			 * @param string $table  Table name.
			 * @param array  $data   Data to insert.
			 * @param array  $format Format array.
			 * @return int
			 */
			public function insert( $table, $data, $format = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
				++$this->insert_calls;
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
	 * Invoke the private migration method on a fresh Main instance.
	 *
	 * @param bool  $loads_on_demand Whether WP 6.9+ core loads separate block assets on demand.
	 * @param array $options         Plugin options to seed on the instance (may be empty).
	 * @return Main The Main instance (for asserting synced options).
	 */
	private function migrate( bool $loads_on_demand, array $options = array() ): Main {
		// Salted-cache gate default (issue #882).
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );

		$main = ( new ReflectionClass( Main::class ) )->newInstanceWithoutConstructor();

		$options_prop = new ReflectionProperty( Main::class, 'options' );
		$options_prop->setValue( $main, $options );

		$method = new ReflectionMethod( Main::class, 'migrate_block_assets_setting' );
		$method->invoke( $main, $loads_on_demand );

		return $main;
	}

	/**
	 * (a) Pre-6.9 core: the migration is a no-op (no option reads or writes).
	 */
	public function test_pre_69_core_is_a_noop(): void {
		$reads  = array();
		$writes = array();

		Functions\when( 'get_option' )->alias(
			function ( $key, $default_value = false ) use ( &$reads ) {
				$reads[] = $key;
				return $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$writes ) {
				$writes[] = array( $key, $value );
				return true;
			}
		);

		$this->migrate( false );

		$this->assertSame( array(), $reads );
		$this->assertSame( array(), $writes );
	}

	/**
	 * (d) Key present: the migration short-circuits without touching settings.
	 *
	 * Key presence is the idempotence marker (issue #1464) — no
	 * `wppo_block_assets_migrated` row is ever read or written.
	 */
	public function test_key_present_short_circuits(): void {
		$reads  = array();
		$writes = array();

		Functions\when( 'get_option' )->alias(
			function ( $key, $default_value = false ) use ( &$reads ) {
				$reads[] = $key;
				if ( 'wppo_settings' === $key ) {
					return array( 'file_optimisation' => array( 'blockAssetsOnDemand' => true ) );
				}
				return $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$writes ) {
				$writes[] = array( $key, $value );
				return true;
			}
		);

		$this->migrate( true );

		$this->assertSame( array( 'wppo_settings' ), $reads );
		$this->assertSame( array(), $writes );
	}

	/**
	 * (b) Explicit stored false is preserved untouched with zero writes.
	 */
	public function test_explicit_stored_false_is_preserved(): void {
		$writes = array();

		Functions\when( 'get_option' )->alias(
			function ( $key, $default_value = false ) {
				if ( 'wppo_settings' === $key ) {
					return array( 'file_optimisation' => array( 'blockAssetsOnDemand' => false ) );
				}
				return $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$writes ) {
				$writes[] = array( $key, $value );
				return true;
			}
		);

		$main = $this->migrate(
			true,
			array( 'file_optimisation' => array( 'blockAssetsOnDemand' => false ) )
		);

		// Nothing is written: the explicit false is untouched and no
		// migration-marker row is allocated (issue #1464).
		$this->assertSame( array(), $writes );

		$options_prop = new ReflectionProperty( Main::class, 'options' );
		$options      = $options_prop->getValue( $main );
		$this->assertFalse( $options['file_optimisation']['blockAssetsOnDemand'] );
	}

	/**
	 * (c) Missing key on 6.9+ is defaulted to true, persisted, and synced.
	 *
	 * Only `wppo_settings` is written — no migration-marker row (issue #1464).
	 */
	public function test_missing_key_is_defaulted_to_true(): void {
		$writes = array();

		Functions\when( 'get_option' )->alias(
			function ( $key, $default_value = false ) {
				if ( 'wppo_settings' === $key ) {
					return array( 'file_optimisation' => array( 'minifyJS' => true ) );
				}
				return $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$writes ) {
				$writes[] = array( $key, $value );
				return true;
			}
		);
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( '__' )->returnArg();

		$main = $this->migrate( true );

		$settings_write = null;
		foreach ( $writes as $entry ) {
			if ( 'wppo_settings' === $entry[0] ) {
				$settings_write = $entry[1];
			}
			$this->assertNotSame( 'wppo_block_assets_migrated', $entry[0], 'must not allocate a migration-marker row' );
		}

		$this->assertNotNull( $settings_write, 'wppo_settings should be persisted' );
		$this->assertTrue( $settings_write['file_optimisation']['blockAssetsOnDemand'] );
		$this->assertTrue( $settings_write['file_optimisation']['minifyJS'] );

		$options_prop = new ReflectionProperty( Main::class, 'options' );
		$options      = $options_prop->getValue( $main );
		$this->assertTrue( $options['file_optimisation']['blockAssetsOnDemand'] );
	}

	/**
	 * (e) No stored option (fresh install): zero reads beyond the settings
	 * check, zero writes — no partial wppo_settings write and no marker row.
	 */
	public function test_no_stored_option_writes_nothing(): void {
		$writes = array();

		Functions\when( 'get_option' )->alias(
			function ( $key, $default_value = false ) {
				return $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$writes ) {
				$writes[] = array( $key, $value );
				return true;
			}
		);

		$this->migrate( true );

		$this->assertSame( array(), $writes, 'fresh installs must allocate zero migration rows' );
	}
}
