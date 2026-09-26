<?php
/**
 * P3-016 source and parity tests for the Settings_Migrations bridge (issue #1597).
 *
 * @package PerformanceOptimise\Tests
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Settings_Migrations;

/**
 * Proves the migration service has no stored orchestrator dependency and
 * still observes settings through callable read/invalidation contracts.
 */
class SettingsMigrationsBridgeTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * The source contains callable contracts, not a Main owner reference.
	 *
	 * @return void
	 */
	public function test_migration_source_has_no_main_bridge(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/Settings/class-settings-migrations.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local source scan.
		$this->assertNotFalse( $source );
		$this->assertStringNotContainsString( 'migration_options_ref', $source );
		$this->assertStringNotContainsString( '$this->main', $source );
		$this->assertDoesNotMatchRegularExpression( '/(?:private|protected|public)\s+Main\s+\$/', $source );
		$this->assertStringContainsString( 'callable $options_reader', $source );
		$this->assertStringContainsString( 'callable $options_invalidator', $source );
		$this->assertFalse( method_exists( Main::class, 'migration_options_ref' ) );
	}

	/**
	 * A callable reader and invalidator preserve same-request migration parity.
	 *
	 * @return void
	 */
	public function test_callable_contracts_preserve_persist_and_invalidation(): void {
		global $wpdb;
		$original_wpdb = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wpdb          = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			/**
			 * Activity-log insert stub.
			 *
			 * @param string $table Table name.
			 * @param array  $data  Insert data.
			 * @param array  $format Insert format.
			 * @return int Insert result.
			 */
			public function insert( $table, $data, $format = array() ) {
				return 1;
			}
		};
		$stored        = array( 'file_optimisation' => array( 'minifyJS' => true ) );
		$invalidations = 0;
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default_value = false ) use ( &$stored ) {
				return 'wppo_settings' === $key ? $stored : $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$stored ) {
				if ( 'wppo_settings' === $key ) {
					$stored = $value;
				}
				return true;
			}
		);
		Functions\when( 'wp_kses_post' )->returnArg();

		$runner = new Settings_Migrations(
			static function (): array {
				return array();
			},
			static function ( array $settings ) use ( &$invalidations ): void {
				unset( $settings );
				++$invalidations;
			}
		);
		$runner->migrate_ccss_max_size();

		$this->assertSame( 20480, $stored['file_optimisation']['ccssMaxSize'] );
		$this->assertSame( 1, $invalidations );
		$wpdb = $original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}
}
