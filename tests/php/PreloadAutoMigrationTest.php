<?php
/**
 * Tests for Main::maybe_migrate_preload_auto_defaults().
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;
use Brain\Monkey\Functions;

/**
 * Behavioral coverage for the automatic LCP + font-discovery backfill
 * (issue #1216): fresh installs are skipped, absent keys backfill to
 * false, explicit values (including true) are preserved, and completed
 * migrations are idempotent.
 *
 * @package PerformanceOptimise\Tests
 */
class PreloadAutoMigrationTest extends \PHPUnit\Framework\TestCase {
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
		// Re-register the shared stubs: this setUp() shadows the trait
		// method, and Brain Monkey eval-declared functions persist per
		// process, so without this Log::add()'s salted-cache gate throws
		// MissingFunctionExpectations when an earlier file declared
		// wp_using_ext_object_cache().
		$this->register_common_function_stubs();
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
	 * Run the migration against a stored wppo_settings value.
	 *
	 * @param mixed $stored Stored wppo_settings value (or false for no row).
	 * @return array Tuple of (Main instance, writes list).
	 */
	private function migrate( $stored ): array {
		$writes = array();

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default_value = false ) use ( $stored ) {
				return 'wppo_settings' === $key ? $stored : $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$writes ) {
				$writes[] = array( $key, $value );
				return true;
			}
		);
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( '__' )->returnArg();

		$main = ( new ReflectionClass( Main::class ) )->newInstanceWithoutConstructor();

		$options_prop = new ReflectionProperty( Main::class, 'options' );
		$options_prop->setAccessible( true );
		$options_prop->setValue( $main, array() );

		$method = new ReflectionMethod( Main::class, 'maybe_migrate_preload_auto_defaults' );
		$method->setAccessible( true );
		$method->invoke( $main );

		return array( $main, $writes );
	}

	/**
	 * Fresh install (no stored row): skipped with no writes.
	 */
	public function test_fresh_install_is_skipped(): void {
		list( $_main, $writes ) = $this->migrate( false );

		$this->assertSame( array(), $writes );
	}

	/**
	 * Stored settings predating the keys: both backfill to false, sibling
	 * keys are preserved, and the in-memory options sync.
	 */
	public function test_missing_keys_backfill_to_false(): void {
		list( $main, $writes ) = $this->migrate(
			array(
				'preload_settings' => array( 'enablePreloadCache' => true ),
			)
		);

		$settings_writes = array_values(
			array_filter(
				$writes,
				static function ( $write ) {
					return 'wppo_settings' === $write[0];
				}
			)
		);
		$this->assertCount( 1, $settings_writes, 'Migration must persist exactly one wppo_settings write (Log::add() may bump its own cache-version option).' );
		$persisted = $settings_writes[0][1]['preload_settings'];
		$this->assertFalse( $persisted['autoLcpPreload'] );
		$this->assertFalse( $persisted['autoDiscoverFonts'] );
		$this->assertTrue( $persisted['enablePreloadCache'], 'Sibling keys must survive the backfill' );

		$options_prop = new ReflectionProperty( Main::class, 'options' );
		$options_prop->setAccessible( true );
		$options = $options_prop->getValue( $main );
		$this->assertFalse( $options['preload_settings']['autoLcpPreload'] );
		$this->assertFalse( $options['preload_settings']['autoDiscoverFonts'] );
	}

	/**
	 * Explicit stored values (including true) are preserved: idempotent,
	 * no writes.
	 */
	public function test_explicit_values_are_preserved(): void {
		list( $_main, $writes ) = $this->migrate(
			array(
				'preload_settings' => array(
					'autoLcpPreload'    => true,
					'autoDiscoverFonts' => false,
				),
			)
		);

		$this->assertSame( array(), $writes );
	}

	/**
	 * Partial presence: only the absent key backfills, the present value
	 * (even true) is preserved verbatim.
	 */
	public function test_partial_presence_backfills_only_the_absent_key(): void {
		list( $_main, $writes ) = $this->migrate(
			array(
				'preload_settings' => array( 'autoLcpPreload' => true ),
			)
		);

		$settings_writes = array_values(
			array_filter(
				$writes,
				static function ( $write ) {
					return 'wppo_settings' === $write[0];
				}
			)
		);
		$this->assertCount( 1, $settings_writes, 'Migration must persist exactly one wppo_settings write (Log::add() may bump its own cache-version option).' );
		$persisted = $settings_writes[0][1]['preload_settings'];
		$this->assertTrue( $persisted['autoLcpPreload'], 'Explicit true must be preserved verbatim' );
		$this->assertFalse( $persisted['autoDiscoverFonts'] );
	}
}
