<?php
/**
 * P3-013 regression tests for Main settings/cache lifecycle ownership (issue #1591).
 *
 * @package PerformanceOptimise\Tests
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\Cache_Coordinator;
use PerformanceOptimise\Inc\Google_Fonts;
use PerformanceOptimise\Inc\Image_Optimisation;
use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Settings_Store;
use PerformanceOptimise\Inc\Util;

/**
 * Effective settings and cache coordination regression tests.
 */
class MainSettingsOwnershipTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Per-blog raw settings fixture.
	 *
	 * @var array<int, array>
	 */
	private array $settings_by_blog = array();

	/**
	 * Number of direct wppo_settings writes attempted.
	 *
	 * @var int
	 */
	private int $settings_writes = 0;

	/**
	 * Install deterministic per-blog options and clear both settings memos.
	 *
	 * @return void
	 */
	private function install_settings_store(): void {
		$this->settings_by_blog = array();
		$this->settings_writes  = 0;
		Settings_Store::clear_settings_cache();
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				if ( 'wppo_settings' !== $name ) {
					return $fallback;
				}
				$blog_id = (int) get_current_blog_id();
				return array_key_exists( $blog_id, $this->settings_by_blog ) ? $this->settings_by_blog[ $blog_id ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				if ( 'wppo_settings' === $name ) {
					++$this->settings_writes;
					$this->settings_by_blog[ (int) get_current_blog_id() ] = $value;
				}
				return true;
			}
		);
		Functions\when( 'add_action' )->justReturn( true );
	}

	/**
	 * Select the current multisite blog.
	 *
	 * @param int $blog_id Blog ID.
	 * @return void
	 */
	private function use_blog( int $blog_id ): void {
		Functions\when( 'get_current_blog_id' )->justReturn( $blog_id );
	}

	/**
	 * Build Main without running its eager constructor.
	 *
	 * @return Main
	 */
	private function make_main(): Main {
		$reflection = new \ReflectionClass( Main::class );
		return $reflection->newInstanceWithoutConstructor();
	}

	/**
	 * Empty storage resolves to canonical defaults plus historical backfills.
	 *
	 * @return void
	 */
	public function test_stored_default_and_backfill_resolution_stays_in_settings_store(): void {
		$this->install_settings_store();
		$this->use_blog( 1 );

		$resolved = Settings_Store::get_resolved_settings();

		$this->assertSame( Util::get_default_settings(), $resolved );
		$this->assertSame( 0, $this->settings_writes, 'Read resolution must never write settings.' );
		$this->assertSame( 0, $this->settings_by_blog[1] ?? 0, 'Resolution must not seed a missing option.' );
	}

	/**
	 * Stored values win and every historical compatibility backfill remains.
	 *
	 * @return void
	 */
	public function test_stored_values_win_and_historical_backfills_apply(): void {
		$this->install_settings_store();
		$this->use_blog( 1 );
		$this->settings_by_blog[1] = array(
			'cache_settings'    => array( 'enableCache' => false ),
			'file_optimisation' => array( 'delayJSPreset' => 'invalid' ),
		);

		$resolved = self::resolve_through_main_facade();

		$this->assertFalse( $resolved['cache_settings']['enableCache'] );
		$this->assertTrue( $resolved['cache_settings']['wooSafeMode'] );
		$this->assertSame( 'safe', $resolved['file_optimisation']['delayJSPreset'] );
		$this->assertTrue( $resolved['file_optimisation']['delayJSSafeMode'] );
		$this->assertFalse( $resolved['image_optimisation']['lazyLoadImages'] );
		$this->assertSame( 2, $resolved['preload_settings']['speculationTopUrlsLimit'] );
		$this->assertSame( 20480, $resolved['file_optimisation']['ccssMaxSize'] );
		$this->assertSame( 0, $this->settings_writes );
	}

	/**
	 * Build a no-constructor Main and resolve through its public facade.
	 *
	 * @return array
	 */
	private static function resolve_through_main_facade(): array {
		$reflection = new \ReflectionClass( Main::class );
		$main       = $reflection->newInstanceWithoutConstructor();
		return $main->get_options();
	}

	/**
	 * Blog-keyed effective snapshots never leak across switch_to_blog().
	 *
	 * @return void
	 */
	public function test_multisite_blog_switching_isolates_resolved_options(): void {
		$this->install_settings_store();
		$this->settings_by_blog = array(
			1 => array( 'cache_settings' => array( 'enableCache' => false ) ),
			2 => array( 'cache_settings' => array( 'enableCache' => true ) ),
		);
		$main                   = $this->make_main();

		$this->use_blog( 1 );
		$this->assertFalse( $main->get_options()['cache_settings']['enableCache'] );
		$this->use_blog( 2 );
		$blog2 = $main->get_options();
		$this->assertTrue( $blog2['cache_settings']['enableCache'] );
		$this->assertTrue( $blog2['cache_settings']['wooSafeMode'] );
		$this->use_blog( 1 );
		$this->assertFalse( $main->get_options()['cache_settings']['enableCache'] );
	}

	/**
	 * Add_option seeds invalidate Main and produce backfilled same-request reads.
	 *
	 * @return void
	 */
	public function test_add_option_seed_invalidates_main_and_store_memos(): void {
		$this->install_settings_store();
		$this->use_blog( 1 );
		$main = $this->make_main();
		$main->get_options();
		$this->track_main( $main );
		$seed = array( 'cache_settings' => array( 'enableCache' => true ) );

		Settings_Store::on_settings_add( 'wppo_settings', $seed );
		Main::on_settings_add( 'wppo_settings', $seed );

		$resolved = $main->get_options();
		$this->assertTrue( $resolved['cache_settings']['enableCache'] );
		$this->assertTrue( $resolved['cache_settings']['wooSafeMode'] );
	}

	/**
	 * Update_option invalidation is observed by Main in the same request.
	 *
	 * @return void
	 */
	public function test_update_option_invalidates_main_and_store_memos(): void {
		$this->install_settings_store();
		$this->use_blog( 1 );
		$old = array( 'cache_settings' => array( 'enableCache' => false ) );
		$new = array( 'cache_settings' => array( 'enableCache' => true ) );
		Settings_Store::set_settings_cache( $old );
		$main = $this->make_main();
		$this->assertFalse( $main->get_options()['cache_settings']['enableCache'] );
		$this->track_main( $main );

		// Store and Main both retain their historical hook callback identities;
		// invoke both directly here without emitting unrelated cache side effects.
		Settings_Store::on_settings_update( $old, $new );
		Main::on_settings_update( $new, $new );

		$resolved = $main->get_options();
		$this->assertTrue( $resolved['cache_settings']['enableCache'] );
		$this->assertTrue( $resolved['cache_settings']['wooSafeMode'] );
	}

	/**
	 * Track a no-constructor Main as the callback target for this test.
	 *
	 * @param Main $main Main instance.
	 * @return void
	 */
	private function track_main( Main $main ): void {
		$property = new \ReflectionProperty( Main::class, 'instance' );
		$property->setValue( null, $main );
	}

	/**
	 * Cache_Coordinator preserves collaborator identity and filter injection.
	 *
	 * @return void
	 */
	public function test_cache_coordinator_preserves_injection_and_filter_contract(): void {
		$_SERVER['HTTP_HOST']   = 'example.com';
		$_SERVER['REQUEST_URI'] = '/sample/';
		Functions\stubs( array( 'wp_normalize_path', 'wp_parse_url', 'sanitize_text_field', 'wp_unslash' ) );
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'wp_parse_url' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$options = array( 'debug' => false );
		$image   = ( new \ReflectionClass( Image_Optimisation::class ) )->newInstanceWithoutConstructor();
		$fonts   = ( new \ReflectionClass( Google_Fonts::class ) )->newInstanceWithoutConstructor();
		$stub    = new \stdClass();
		\Brain\Monkey\Filters\expectApplied( 'wppo_cache_instance' )
			->once()
			->andReturnUsing(
				function ( $cache, $filtered_options ) use ( $options, $image, $fonts, $stub ) {
					$this->assertInstanceOf( Cache::class, $cache );
					$this->assertSame( $options, $filtered_options );
					$this->assertSame( $image, ( new \ReflectionProperty( Cache::class, 'image_optimisation' ) )->getValue( $cache ) );
					$this->assertSame( $fonts, ( new \ReflectionProperty( Cache::class, 'google_fonts' ) )->getValue( $cache ) );
					return $stub;
				}
			);

		$this->assertSame( $stub, Cache_Coordinator::create( $options, $image, $fonts ) );
	}

	/**
	 * Main's unchanged facade delegates to the bounded cache coordinator.
	 *
	 * @return void
	 */
	public function test_main_create_cache_facade_uses_coordinator_filter_once(): void {
		$_SERVER['HTTP_HOST']   = 'example.com';
		$_SERVER['REQUEST_URI'] = '/sample/';
		Functions\stubs( array( 'wp_normalize_path', 'wp_parse_url', 'sanitize_text_field', 'wp_unslash' ) );
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'wp_parse_url' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$stub = new \stdClass();
		\Brain\Monkey\Filters\expectApplied( 'wppo_cache_instance' )->once()->andReturn( $stub );

		$this->assertSame( $stub, Main::create_cache( array( 'debug' => false ) ) );
	}

	/**
	 * Main contains no direct wppo_settings write path.
	 *
	 * @return void
	 */
	public function test_main_has_no_direct_settings_write_path(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local source scan.
		$source        = (string) file_get_contents( WPPO_PLUGIN_PATH . 'includes/Core/class-main.php' );
		$tokens        = token_get_all( $source );
		$writes        = array();
		$command_saves = 0;
		for ( $index = 0, $count = count( $tokens ); $index < $count; $index++ ) {
			if ( ! is_array( $tokens[ $index ] ) || T_STRING !== $tokens[ $index ][0] ) {
				continue;
			}
			$next = $index + 1;
			while ( $next < $count && is_array( $tokens[ $next ] ) && T_WHITESPACE === $tokens[ $next ][0] ) {
				++$next;
			}
			if ( 'update_option' === $tokens[ $index ][1] ) {
				$argument = $next + 1;
				while ( $argument < $count && is_array( $tokens[ $argument ] ) && T_WHITESPACE === $tokens[ $argument ][0] ) {
					++$argument;
				}
				if ( $argument < $count && is_array( $tokens[ $argument ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $argument ][0] && 'wppo_settings' === trim( (string) $tokens[ $argument ][1], "'\"" ) ) {
					$writes[] = 'update_option';
				}
			}
			if ( 'Util' === $tokens[ $index ][1] && $next < $count && is_array( $tokens[ $next ] ) && T_DOUBLE_COLON === $tokens[ $next ][0] ) {
				$method = $next + 1;
				while ( $method < $count && is_array( $tokens[ $method ] ) && T_WHITESPACE === $tokens[ $method ][0] ) {
					++$method;
				}
				if ( $method < $count && is_array( $tokens[ $method ] ) && 'save_settings' === $tokens[ $method ][1] ) {
					$writes[] = 'Util::save_settings';
				}
			}
			if ( 'Settings_Command' === $tokens[ $index ][1] && $next < $count && is_array( $tokens[ $next ] ) && T_DOUBLE_COLON === $tokens[ $next ][0] ) {
				$method = $next + 1;
				while ( $method < $count && is_array( $tokens[ $method ] ) && T_WHITESPACE === $tokens[ $method ][0] ) {
					++$method;
				}
				if ( $method < $count && is_array( $tokens[ $method ] ) && 'save' === $tokens[ $method ][1] ) {
					++$command_saves;
				}
			}
		}
		$this->assertSame( array(), $writes );
		$this->assertSame( 1, $command_saves );
	}

	/**
	 * The WP-CLI settings subcommands write through the same seam.
	 *
	 * The CLI hand-rolled snapshot-then-save, which is how an unchanged write
	 * came to overwrite the single one-click undo slot. Routing it through
	 * Settings_Command::save() is what gives it the change guard, so the
	 * invariant is pinned here rather than left to review.
	 *
	 * @return void
	 */
	public function test_cli_settings_writes_go_through_the_settings_command(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local source scan.
		$source = (string) file_get_contents( WPPO_PLUGIN_PATH . 'includes/Admin/class-wppo-cli-command.php' );
		$this->assertStringNotContainsString(
			'Util::save_settings',
			$source,
			'WP-CLI settings writes must go through Settings_Command, not the raw Util facade'
		);
		$this->assertStringNotContainsString(
			'Util::take_settings_snapshot',
			$source,
			'WP-CLI must not hand-roll the undo snapshot; the seam owns the change guard'
		);
		$this->assertSame(
			2,
			substr_count( $source, 'Settings_Command::save(' ),
			'both the import and update branches must save through the seam'
		);
	}
}
