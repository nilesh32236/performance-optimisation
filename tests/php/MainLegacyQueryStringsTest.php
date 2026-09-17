<?php
/**
 * Regression tests for the removed removeQueryStrings `?ver` stripping path (#925).
 *
 * @package PerformanceOptimise\Tests
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level absence assertions read local files.

use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Asserts the stripping path is gone and stored legacy values are fail-open.
 *
 * With the path removed there is no code left that can strip `?ver` from
 * enqueued assets, so `?ver` is preserved for every setting state.
 *
 * @package PerformanceOptimise\Tests
 */
class MainLegacyQueryStringsTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap {
		setUp as protected wppoSetUp;
		tearDown as protected wppoTearDown;
	}

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
		$this->wppoSetUp();
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
		unset( $GLOBALS['wp_version'] );
		$this->wppoTearDown();
	}

	/**
	 * Stub the WP environment needed to construct Main with the given
	 * file_optimisation overrides.
	 *
	 * @param array $file_overrides file_optimisation option overrides.
	 * @return void
	 */
	private function stub_main_construction( array $file_overrides ): void {
		Functions\stubs(
			array(
				'WP_Filesystem'       => false,
				'sanitize_text_field' => '',
				'wp_unslash'          => '',
				'is_user_logged_in'   => false,
				'absint'              => 3000,
			)
		);

		Functions\when( 'get_option' )->alias(
			static function ( $option, $default_value = false ) use ( $file_overrides ) {
				if ( 'wppo_settings' === $option && is_array( $default_value ) ) {
					$default_value['file_optimisation'] = array_merge( $default_value['file_optimisation'] ?? array(), $file_overrides );
				}
				return $default_value;
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'content_url' )->returnArg();
		Functions\when( 'trailingslashit' )->returnArg();
		Functions\when( 'wp_is_block_theme' )->justReturn( false );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );

		// Only the target function_exists probes are faked; everything else is
		// delegated to the real function_exists so the rest of the Main
		// constructor keeps running on its real branch.
		Functions\when( 'function_exists' )->alias(
			static function ( $function_name ) {
				if ( 'WP_Filesystem' === $function_name || 'wp_is_block_theme' === $function_name ) {
					return true;
				}
				return \function_exists( $function_name );
			}
		);
	}

	/**
	 * The stripping method must no longer exist.
	 */
	public function test_strip_static_query_strings_method_removed(): void {
		$this->assertFalse(
			method_exists( Main::class, 'strip_static_query_strings' ),
			'Main::strip_static_query_strings() must be removed (#925)'
		);
	}

	/**
	 * The plugin-cache helpers that only served the stripping path must be gone.
	 */
	public function test_plugin_cache_url_helpers_removed(): void {
		$this->assertFalse(
			method_exists( Main::class, 'is_plugin_cache_url' ),
			'Main::is_plugin_cache_url() must be removed with the stripping path (#925)'
		);
		$this->assertFalse(
			method_exists( Main::class, 'is_plugin_cache_url_uncached' ),
			'Main::is_plugin_cache_url_uncached() must be removed with the stripping path (#925)'
		);
	}

	/**
	 * Canonical defaults must not contain the legacy key.
	 */
	public function test_defaults_have_no_remove_query_strings_key(): void {
		$defaults = Util::get_default_settings();

		$this->assertArrayNotHasKey(
			'removeQueryStrings',
			$defaults['file_optimisation'],
			'Canonical defaults must not seed the removed legacy key (#925)'
		);
	}

	/**
	 * The removal shim is gone (audit #1373): no notify method, no
	 * admin_init registration — legacy keys simply do nothing.
	 */
	public function test_removal_shim_fully_removed(): void {
		$this->assertFalse(
			method_exists( Main::class, 'maybe_notify_remove_query_strings_removal' ),
			'Notify shim must be removed (#1373)'
		);
	}

	/**
	 * No registration of the removed shim survives (source-level, mirroring
	 * VersionGuardTest: constructing Main under Brain Monkey cannot
	 * register array-callback hooks).
	 */
	public function test_legacy_option_schedules_nothing(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-main.php' );
		$this->assertStringNotContainsString(
			'maybe_notify_remove_query_strings_removal',
			$source,
			'No removal notice registration may survive'
		);
	}

	/**
	 * Legacy stored keys still succeed through sanitization (fail-open).
	 */
	public function test_legacy_key_still_succeeds(): void {
		$sanitized = Util::sanitize_settings_recursively(
			array( 'file_optimisation' => array( 'removeQueryStrings' => true ) )
		);
		$this->assertTrue( is_array( $sanitized ), 'Legacy input must not fatal sanitization' );
	}
}
