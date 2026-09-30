<?php
/**
 * CHARACTERISATION TESTS for includes/Core/class-hook-registry.php.
 *
 * Records what the class does today. Hook_Registry owns the boot-time hook
 * contract: Main's constructor delegates to its register() method, so this
 * is the class that actually decides what the plugin hooks.
 *
 * DELIBERATE NON-DUPLICATION. #1746 already pins the resulting hook set
 * from Main's side (103 unique hooks observed through the constructor). This
 * file therefore does NOT pin that union a second time. It pins what
 * #1746 cannot see:
 *
 *   1. Hook_Registry's own API surface (7 methods, 4 properties, final).
 *   2. The DECOMPOSITION - which hooks each register_*() method owns, which
 *      the union hides.
 *   3. The `register_block_assets_filters()` branch argument and its two
 *      mutually exclusive outcomes.
 *   4. The double-registration wart: calling register() twice does not
 *      simply double everything. Recorded as-is, not fixed.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Google_Fonts;
use PerformanceOptimise\Inc\Hook_Registry;
use PerformanceOptimise\Inc\Image_Optimisation;
use PerformanceOptimise\Inc\Main;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Class HookRegistryCharacterisationTest.
 *
 * @package PerformanceOptimise\Tests
 */
class HookRegistryCharacterisationTest extends \PHPUnit\Framework\TestCase {

	use WPPO_Test_Bootstrap;

	/**
	 * Recorded add_action()/add_filter() calls as "type name @priority/args".
	 *
	 * @var string[]
	 */
	private $hooks = array();

	/**
	 * Install the WP stubs and start recording.
	 *
	 * @return void
	 */
	private function begin(): void {
		unset( $GLOBALS['wp_version'] );
		$this->hooks = array();

		Functions\stubs(
			array(
				'get_option',
				'update_option',
				'add_option',
				'delete_option',
				'get_transient',
				'set_transient',
				'delete_transient',
				'wp_hash',
				'sanitize_text_field',
				'esc_url_raw',
				'wp_unslash',
				'is_multisite',
				'wp_rand',
				'absint',
				'wp_parse_url',
				'do_action',
				'apply_filters',
				'remove_action',
				'remove_filter',
				'doing_filter',
				'has_action',
				'has_filter',
				'is_admin',
				'is_user_logged_in',
				'wp_doing_ajax',
				'wp_doing_cron',
				'wp_using_ext_object_cache',
				'wp_parse_args',
				'sanitize_key',
				'wp_list_pluck',
				'wp_json_encode',
				'current_time',
				'home_url',
				'site_url',
				'plugins_url',
				'plugin_dir_path',
				'__',
				'esc_html__',
				'trailingslashit',
				'untrailingslashit',
				'current_user_can',
				'wp_verify_nonce',
				'wp_is_block_theme',
			)
		);
		foreach ( array( 'is_admin', 'is_user_logged_in', 'wp_doing_ajax', 'wp_doing_cron', 'has_action', 'has_filter', 'doing_filter', 'wp_using_ext_object_cache', 'get_transient', 'is_multisite', 'current_time', 'remove_action', 'remove_filter', 'current_user_can', 'wp_is_block_theme' ) as $flag ) {
			Functions\when( $flag )->justReturn( false );
		}
		Functions\when( 'wp_rand' )->justReturn( 1 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_option' )->justReturn( false );
		foreach ( array( 'sanitize_text_field', 'esc_url_raw', 'wp_unslash', 'sanitize_key', 'trailingslashit' ) as $passthrough ) {
			Functions\when( $passthrough )->returnArg();
		}
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return $value;
			}
		);
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback = null, $priority = 10, $accepted_args = 1 ) {
				$this->hooks[] = 'action  ' . $hook . ' @' . $priority . '/' . $accepted_args;
				return true;
			}
		);
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback = null, $priority = 10, $accepted_args = 1 ) {
				$this->hooks[] = 'filter ' . $hook . ' @' . $priority . '/' . $accepted_args;
				return true;
			}
		);

		$GLOBALS['wp_version'] = '6.8';
	}

	/**
	 * A registry wired to constructor-less collaborators.
	 *
	 * The collaborators are only stored and never invoked (every callback is
	 * captured by the add_* stubs), so bypassing their constructors keeps this
	 * file about Hook_Registry alone.
	 *
	 * @param array $options The options array handed to the registry.
	 * @return Hook_Registry
	 */
	private function registry( array $options = array() ): Hook_Registry {
		return new Hook_Registry(
			( new \ReflectionClass( Main::class ) )->newInstanceWithoutConstructor(),
			$options,
			( new \ReflectionClass( Image_Optimisation::class ) )->newInstanceWithoutConstructor(),
			( new \ReflectionClass( Google_Fonts::class ) )->newInstanceWithoutConstructor()
		);
	}

	/**
	 * The hooks recorded so far, de-duplicated and sorted.
	 *
	 * @return string[]
	 */
	private function recorded(): array {
		$unique = array_values( array_unique( $this->hooks ) );
		sort( $unique );
		return $unique;
	}

	/**
	 * The pinned public API surface: name => array( visibility, param_count, is_static, signature ).
	 *
	 * @return array
	 */
	private function pinned_methods(): array {
		return array(
			'__construct'                   => array( 'public', 4, 0, '$main,$options,$image_optimisation,$google_fonts' ),
			'register'                      => array( 'public', 0, 0, '' ),
			'register_background_hooks'     => array( 'public', 0, 0, '' ),
			'register_block_assets_filters' => array( 'public', 1, 0, '$loads_separate_core_block_assets_on_demand' ),
			'register_head_hint_hooks'      => array( 'public', 0, 0, '' ),
			'register_integration_hooks'    => array( 'public', 0, 0, '' ),
			'register_invalidation_hooks'   => array( 'public', 0, 0, '' ),
		);
	}

	/**
	 * The pinned property surface: name => visibility plus static/readonly flags.
	 *
	 * @return array
	 */
	private function pinned_properties(): array {
		return array(
			'google_fonts'       => 'private',
			'image_optimisation' => 'private',
			'main'               => 'private',
			'options'            => 'private',
		);
	}

	/**
	 * The API surface is exactly what it is today, and the class is final.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function test_api_surface_is_pinned(): void {
		$this->begin();
		$reflection = new \ReflectionClass( Hook_Registry::class );
		$actual     = array();

		foreach ( $reflection->getMethods() as $method ) {
			if ( Hook_Registry::class !== $method->getDeclaringClass()->getName() ) {
				continue;
			}
			$signature = array();
			foreach ( $method->getParameters() as $parameter ) {
				$default = '';
				if ( $parameter->isDefaultValueAvailable() ) {
					$value = $parameter->getDefaultValue();
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Pinning a default value needs its exact literal.
					$default = '=' . ( is_array( $value ) ? 'array' : var_export( $value, true ) );
				}
				$signature[] = '$' . $parameter->getName() . $default;
			}
			$actual[ $method->getName() ] = array(
				$method->isPublic() ? 'public' : ( $method->isProtected() ? 'protected' : 'private' ),
				count( $method->getParameters() ),
				(int) $method->isStatic(),
				implode( ',', $signature ),
			);
		}
		ksort( $actual );

		$this->assertSame( $this->pinned_methods(), $actual, 'Hook_Registry method surface changed.' );
		$this->assertCount( 7, $actual, 'Hook_Registry exposes 7 methods today.' );
		$this->assertTrue( $reflection->isFinal(), 'Hook_Registry is final.' );
	}

	/**
	 * The property surface is exactly what it is today.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function test_property_surface_is_pinned(): void {
		$this->begin();
		$reflection = new \ReflectionClass( Hook_Registry::class );
		$actual     = array();

		foreach ( $reflection->getProperties() as $property ) {
			if ( Hook_Registry::class !== $property->getDeclaringClass()->getName() ) {
				continue;
			}
			$actual[ $property->getName() ] = ( $property->isPublic() ? 'public' : ( $property->isProtected() ? 'protected' : 'private' ) )
				. ( $property->isStatic() ? ' static' : '' )
				. ( $property->isReadOnly() ? ' readonly' : '' );
		}
		ksort( $actual );

		$this->assertSame( $this->pinned_properties(), $actual, 'Hook_Registry property surface changed.' );
		$this->assertCount( 4, $actual, 'Hook_Registry declares 4 properties today.' );
	}

	/**
	 * Which hooks each register_*() method owns. The union is already pinned
	 * by #1746 from Main's side; this is the decomposition it cannot see.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function test_each_register_method_owns_exactly_these_hooks(): void {
		$expected = array(
			'register_head_hint_hooks'    => array(
				'action  wp_head @0/1',
				'action  wp_head @1/1',
				'filter wp_resource_hints @10/2',
			),
			'register_background_hooks'   => array(
				'action  save_post @10/3',
				'action  wppo_ai_css_refresh_queued @10/3',
				'action  wppo_convert_image_background @10/1',
				'action  wppo_google_fonts_download @10/1',
				'action  wppo_pagespeed_scan @10/1',
				'action  wppo_used_css_generate @10/2',
			),
			'register_invalidation_hooks' => array(
				'action  activated_plugin @10/1',
				'action  add_option_wppo_settings @10/2',
				'action  deactivated_plugin @10/1',
				'action  switch_theme @10/1',
				'action  switch_theme @20/3',
				'action  update_option_home @10/3',
				'action  update_option_permalink_structure @10/1',
				'action  update_option_siteurl @10/3',
				'action  update_option_wppo_settings @10/2',
				'action  upgrader_process_complete @20/2',
			),
			'register_integration_hooks'  => array(
				'action  delete_post @10/1',
				'action  init @1/1',
				'action  litespeed_purge_finalize @10/1',
				'action  litespeed_purged_all @10/1',
				'action  litespeed_purged_post @10/1',
				'action  permalink_structure_changed @10/1',
				'action  save_post @10/1',
				'action  send_headers @0/1',
				'action  wp_logout @10/1',
				'filter litespeed_vary @10/1',
			),
		);

		foreach ( $expected as $method => $hooks ) {
			$this->begin();
			$this->registry()->{$method}();
			$this->assertSame(
				$hooks,
				$this->recorded(),
				$method . ' owns a different set of hooks.'
			);
		}
	}

	/**
	 * The two branches of register_block_assets_filters() are mutually
	 * exclusive and neither is a superset: the boolean argument selects
	 * pre-6.9 opt-in versus 6.9+ opt-out restore, and with the default empty
	 * options the pre-6.9 branch registers nothing at all.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function test_block_assets_branches_are_mutually_exclusive(): void {
		$this->begin();
		$this->registry()->register_block_assets_filters( false );
		$this->assertSame( array(), $this->recorded(), 'The pre-6.9 branch registers nothing when blockAssetsOnDemand is empty.' );

		$this->begin();
		$this->registry()->register_block_assets_filters( true );
		$this->assertSame(
			array(
				'filter should_load_separate_core_block_assets @10/1',
			),
			$this->recorded(),
			'The 6.9+ opt-out branch registers a different hook than today.'
		);
	}

	/**
	 * The double-registration wart, recorded as-is: register() has no
	 * idempotency guard of its own, and a second call is NOT simply a repeat
	 * of the first - it registers strictly fewer distinct hooks, because
	 * something in the first pass has already satisfied a class_exists or
	 * option check by the time the second runs.
	 *
	 * A refactor that "fixes" this would break this test. That is the point:
	 * it pins today's behaviour, not today's wishes.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function test_register_twice_is_not_a_repeat_of_once(): void {
		$registry = $this->registry();

		$this->begin();
		$registry->register();
		$first = $this->recorded();

		$this->begin();
		$registry->register();
		$second = $this->recorded();

		$this->assertNotSame(
			$first,
			$second,
			'The second register() call now matches the first; the shape of the double-registration behaviour changed.'
		);
		$this->assertCount( 94, $first, 'One register() pass records 94 distinct hooks today.' );
		$this->assertCount( 84, $second, 'A second pass records 84 distinct hooks today - ten fewer.' );
	}
}
