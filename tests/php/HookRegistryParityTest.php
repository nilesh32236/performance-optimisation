<?php
/**
 * Tests for Hook_Registry hook-manifest parity (REF-005).
 *
 * Pins that moving hook registration from Main::setup_hooks() into
 * Hook_Registry changed nothing observable: the full ordered manifest
 * (hook, callback, priority, accepted args) is byte-identical, callback
 * identity still targets the Main instance and its collaborators, and
 * Main option-state prepared during setup is unchanged.
 *
 * @package PerformanceOptimise\Tests
 */

require_once __DIR__ . '/../../includes/class-main.php';
require_once __DIR__ . '/../../includes/class-hook-registry.php';

use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Hook_Registry;
use PerformanceOptimise\Inc\Image_Optimisation;
use PerformanceOptimise\Inc\Google_Fonts;
use PerformanceOptimise\Inc\LiteSpeed_Integration;
use Brain\Monkey\Functions;

/**
 * Hook-registry parity tests.
 *
 * @package PerformanceOptimise\Tests
 */
class HookRegistryParityTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Shared registration log: each entry is
	 * [type, hook, callback, priority, args] with type 'A' (action) or
	 * 'F' (filter), in true global registration order.
	 *
	 * @var array<int, array{0:string,1:string,2:mixed,3:int,4:int}>
	 */
	private array $recorded = array();

	/**
	 * Reset globals touched by the version-gated fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp_version'] );
		unset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
		parent::tearDown();
	}

	/**
	 * Build a Main instance without running its constructor.
	 *
	 * @param array $options Options to seed.
	 * @return Main
	 */
	private function make_main( array $options ): Main {
		$main = ( new \ReflectionClass( Main::class ) )->newInstanceWithoutConstructor();
		$prop = new \ReflectionProperty( Main::class, 'options' );
		$prop->setValue( $main, $options );
		$prop = new \ReflectionProperty( Main::class, 'image_optimisation' );
		$prop->setValue( $main, new Image_Optimisation( $options ) );
		$prop = new \ReflectionProperty( Main::class, 'google_fonts' );
		$prop->setValue( $main, new Google_Fonts( $options ) );
		$prop = new \ReflectionProperty( Main::class, 'used_css_buffer_enhanced' );
		$prop->setValue( $main, false );
		return $main;
	}

	/**
	 * Read a private property off a Main instance.
	 *
	 * @param Main   $main Main instance.
	 * @param string $prop Property name.
	 * @return mixed Property value.
	 */
	private function read_main_prop( Main $main, string $prop ) {
		$reflection = new \ReflectionProperty( Main::class, $prop );
		return $reflection->getValue( $main );
	}

	/**
	 * Install the hook recorder plus the WP environment a scenario needs.
	 *
	 * add_action()/add_filter() append to the in-test recorders while
	 * has_action()/has_filter() consult them with WordPress semantics (first
	 * matching priority or false), so the purge-fallback self-gating behaves
	 * exactly like production. function_exists() probes for version-gated
	 * APIs are pinned explicitly so the manifest is deterministic regardless
	 * of which other suites ran first in the process.
	 *
	 * @param bool   $is_admin   is_admin() return value.
	 * @param string $wp_version Simulated core version.
	 * @param array  $probes     function_exists() probe overrides (name => bool).
	 * @return void
	 */
	private function install_stubs( bool $is_admin, string $wp_version, array $probes ): void {
		$GLOBALS['wp_version']  = $wp_version;
		$_SERVER['HTTP_HOST']   = 'example.com';
		$_SERVER['REQUEST_URI'] = '/';
		$this->recorded         = array();

		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback = null, $priority = 10, $args = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Test mock signature must match add_action().
				$this->recorded[] = array( 'A', $hook, $callback, $priority, $args );
				return true;
			}
		);
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback = null, $priority = 10, $args = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Test mock signature must match add_filter().
				$this->recorded[] = array( 'F', $hook, $callback, $priority, $args );
				return true;
			}
		);
		Functions\when( 'has_action' )->alias(
			function ( $hook, $callback = false ) {
				foreach ( $this->recorded as $entry ) {
					if ( 'A' === $entry[0] && $entry[1] === $hook && ( false === $callback || $entry[2] == $callback ) ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- Mirrors WP core callback matching.
						return $entry[3];
					}
				}
				return false;
			}
		);
		Functions\when( 'has_filter' )->alias(
			function ( $hook, $callback = false ) {
				foreach ( $this->recorded as $entry ) {
					if ( 'F' === $entry[0] && $entry[1] === $hook && ( false === $callback || $entry[2] == $callback ) ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- Mirrors WP core callback matching.
						return $entry[3];
					}
				}
				return false;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Test mock signature must match apply_filters().
				return $value;
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Only the fallback matters here.
				return $fallback;
			}
		);
		Functions\when( 'is_admin' )->justReturn( $is_admin );
		Functions\when( 'get_bloginfo' )->justReturn( $wp_version );
		Functions\when( 'wp_is_block_theme' )->justReturn( false );
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'content_url' )->returnArg();
		Functions\when( 'trailingslashit' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias(
			static function ( $maybeint ) {
				return (int) $maybeint;
			}
		);
		// Pin version-gated API probes LAST: Brain Monkey consults
		// function_exists() when declaring stubs, so every other when()
		// call above must happen first (MainDelayDeferTest pattern).
		Functions\when( 'function_exists' )->alias(
			static function ( $function_name ) use ( $probes ) {
				if ( array_key_exists( $function_name, $probes ) ) {
					return $probes[ $function_name ];
				}
				return \function_exists( $function_name );
			}
		);
	}

	/**
	 * Version-gated API probes faked present for the WP 6.9 scenario.
	 *
	 * @return array<string, bool>
	 */
	private function wp69_probes(): array {
		return array(
			'wp_script_add_data'                             => true,
			'wp_script_modules'                              => true,
			'wp_enqueue_script_module'                       => true,
			'wp_should_output_buffer_template_for_enhancement' => true,
			'wp_load_classic_theme_block_styles_on_demand'   => true,
			'wp_get_speculation_rules'                       => false,
		);
	}

	/**
	 * Version-gated API probes faked absent for the pre-6.9 scenario.
	 *
	 * @return array<string, bool>
	 */
	private function legacy_probes(): array {
		return array(
			'wp_script_add_data'                             => false,
			'wp_script_modules'                              => false,
			'wp_enqueue_script_module'                       => false,
			'wp_should_output_buffer_template_for_enhancement' => false,
			'wp_load_classic_theme_block_styles_on_demand'   => false,
			'wp_get_speculation_rules'                       => false,
		);
	}

	/**
	 * Kitchen-sink options with every setup-gated feature enabled.
	 *
	 * @return array<string, mixed>
	 */
	private function kitchen_sink_options(): array {
		return array(
			'cache_settings'     => array(
				'enableCache' => true,
			),
			'file_optimisation'  => array(
				'delayJS'              => true,
				'deferJS'              => true,
				'excludeDelayJS'       => 'my-delay-script',
				'excludeDeferJS'       => 'my-deferred-script',
				'delayJSIdleList'      => 'my-idle-script',
				'delayJSViewportList'  => 'my-viewport-script',
				'delayJSPriority'      => 'my-idle-script:high',
				'delayJSIdleTimeout'   => '4500',
				'minifyJS'             => true,
				'minifyCSS'            => true,
				'excludeJS'            => 'my-excluded.js',
				'excludeCSS'           => 'my-excluded.css',
				'combineCSS'           => true,
				'criticalCSS'          => true,
				'hostGoogleFontsLocally' => true,
				'removeWooCSSJS'       => true,
				'blockAssetsOnDemand'  => true,
			),
			'image_optimisation' => array(
				'prioritizeLCPImages' => true,
				'cssHeroPreload'      => true,
			),
			'performance_audit'  => array(
				'server_timing_enabled' => true,
			),
		);
	}

	/**
	 * Minimal legacy options: page cache off, delay JS on, used CSS standalone.
	 *
	 * @return array<string, mixed>
	 */
	private function legacy_options(): array {
		return array(
			'cache_settings'     => array(),
			'file_optimisation'  => array(
				'removeUnusedCSS'     => true,
				'delayJS'             => true,
				'excludeDelayJS'      => 'my-delay-script',
				'blockAssetsOnDemand' => true,
			),
			'image_optimisation' => array(),
		);
	}

	/**
	 * Normalize a hook callback for manifest comparison.
	 *
	 * Object callbacks become "Class@method" (identity is asserted
	 * separately); static callbacks become "Class::method".
	 *
	 * @param mixed $callback Recorded callback.
	 * @return string Normalized callback.
	 */
	private function normalize_callback( $callback ): string {
		if ( is_string( $callback ) ) {
			return 'str:' . $callback;
		}
		if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
			if ( is_object( $callback[0] ) ) {
				return get_class( $callback[0] ) . '@' . (string) $callback[1];
			}
			return (string) $callback[0] . '::' . (string) $callback[1];
		}
		return 'other:' . gettype( $callback );
	}

	/**
	 * Run registration and return the raw ordered log.
	 *
	 * @param callable $register Registration runner.
	 * @return array<int, array{0:string,1:string,2:mixed,3:int,4:int}> Ordered [type, hook, callback, priority, args].
	 */
	private function capture_raw( callable $register ): array {
		$before = count( $this->recorded );
		$register();
		return array_slice( $this->recorded, $before );
	}

	/**
	 * Run registration and return the normalized ordered manifest.
	 *
	 * Each entry is [type, hook, callback, priority, args] with type
	 * 'A' (action) or 'F' (filter), in true global registration order.
	 *
	 * @param callable $register Registration runner.
	 * @return array<int, array{0:string,1:string,2:string,3:int,4:int}>
	 */
	private function capture_sequence( callable $register ): array {
		$sequence = array();
		foreach ( $this->capture_raw( $register ) as $entry ) {
			$sequence[] = array( $entry[0], $entry[1], $this->normalize_callback( $entry[2] ), $entry[3], $entry[4] );
		}
		return $sequence;
	}

	/**
	 * Run registration through Main::setup_hooks() (private, via reflection).
	 *
	 * @param Main $main Main instance.
	 * @return void
	 */
	private function register_via_main( Main $main ): void {
		$method = new \ReflectionMethod( Main::class, 'setup_hooks' );
		$method->invoke( $main );
	}

	/**
	 * Run registration directly through Hook_Registry.
	 *
	 * @param Main  $main    Main instance.
	 * @param array $options Options snapshot (same array seeded on $main).
	 * @return void
	 */
	private function register_via_registry( Main $main, array $options ): void {
		$registry = new Hook_Registry(
			$main,
			$options,
			$this->read_main_prop( $main, 'image_optimisation' ),
			$this->read_main_prop( $main, 'google_fonts' )
		);
		$registry->register();
	}

	/**
	 * Reset the LiteSpeed static registration guard so each capture starts equal.
	 *
	 * @return void
	 */
	private function reset_litespeed_guard(): void {
		LiteSpeed_Integration::reset_cache();
	}
}
