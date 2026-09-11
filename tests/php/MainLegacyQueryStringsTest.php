<?php
/**
 * Regression tests for the removed removeQueryStrings `?ver` stripping path (#925).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Actions;
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
	 * The legacy key is retained in the canonical schema as a known-but-inert
	 * entry so `wp wppo verify --check=settings_schema` recognises previously
	 * stored values instead of flagging them as unknown sub-keys. It must
	 * default to `false` and still never enable the removed stripping path.
	 */
	public function test_defaults_retain_legacy_remove_query_strings_key_as_false(): void {
		$defaults = Util::get_default_settings();

		$this->assertArrayHasKey(
			'removeQueryStrings',
			$defaults['file_optimisation'],
			'Canonical defaults must keep the legacy key known for the schema validator'
		);
		$this->assertFalse(
			$defaults['file_optimisation']['removeQueryStrings'],
			'Legacy key default must be false so no default install enables the removed path'
		);
	}

	/**
	 * A stored legacy `true` value must schedule only the one-time removal
	 * notice — never asset-URL filters — so `?ver` survives rendering.
	 */
	public function test_legacy_option_schedules_notice_not_asset_filters(): void {
		$this->stub_main_construction( array( 'removeQueryStrings' => true ) );

		$main = new Main();

		$this->assertNotFalse(
			Actions\has( 'admin_init', array( $main, 'maybe_notify_remove_query_strings_removal' ) ),
			'Legacy opt-in must schedule only the one-time removal notice on admin_init'
		);
	}

	/**
	 * Default installs must not schedule the removal notice.
	 */
	public function test_default_state_schedules_no_notice(): void {
		$this->stub_main_construction( array() );

		$main = new Main();

		$this->assertFalse(
			Actions\has( 'admin_init', array( $main, 'maybe_notify_remove_query_strings_removal' ) ),
			'Default installs must not schedule the removal notice'
		);
	}

	/**
	 * Build a Main instance without invoking the constructor.
	 *
	 * @param array $file_opt file_optimisation options to seed.
	 * @return Main
	 */
	private function make_main( array $file_opt ): Main {
		$reflection = new \ReflectionClass( Main::class );
		$main       = $reflection->newInstanceWithoutConstructor();

		$options = $reflection->getProperty( 'options' );
		$options->setAccessible( true );
		$options->setValue(
			$main,
			array( 'file_optimisation' => $file_opt )
		);

		return $main;
	}

	/**
	 * The one-time removal notice must log once and then stay silent.
	 */
	public function test_removal_notice_logs_once(): void {
		$flag    = 'wppo_remove_query_strings_deprecated_logged';
		$options = array();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( &$options, $flag ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value, $autoload = null ) use ( &$options ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_kses_post' )->returnArg();

		global $wpdb;

		$main = $this->make_main( array( 'removeQueryStrings' => true ) );

		$main->maybe_notify_remove_query_strings_removal();
		$this->assertSame( 1, $wpdb->insert_calls, 'Legacy opt-in must produce a one-time activity log entry' );
		$this->assertTrue( $options[ $flag ], 'One-time flag must be persisted as non-fatal bookkeeping' );

		$main->maybe_notify_remove_query_strings_removal();
		$this->assertSame( 1, $wpdb->insert_calls, 'Second call must stay silent once the flag is set' );
	}

	/**
	 * Without a stored legacy value the notice must be a no-op (no I/O at all).
	 */
	public function test_removal_notice_is_noop_without_legacy_value(): void {
		$reads  = 0;
		$writes = 0;
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( &$reads ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				++$reads;
				return $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value, $autoload = null ) use ( &$writes ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				++$writes;
				return true;
			}
		);

		global $wpdb;

		$this->make_main( array() )->maybe_notify_remove_query_strings_removal();

		$this->assertSame( 0, $reads, 'Default installs must not pay for a flag lookup' );
		$this->assertSame( 0, $writes );
		$this->assertSame( 0, $wpdb->insert_calls );
	}
}
