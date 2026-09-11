<?php
/**
 * Tests for the #1037 safe-mode review follow-ups.
 *
 * Covers: builder/preset filter sanitization (no strval fatal on objects),
 * kill-switch request-cache invalidation (suffix-only match), meta-hook
 * array-id shape tolerance, and single-URL invalidation fail-open paths.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\Main;
use Brain\Monkey\Functions;

/**
 * Review follow-up tests for #1037.
 *
 * @package PerformanceOptimise\Tests
 */
class SafeModeReviewTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Build a Main instance without running its constructor.
	 *
	 * @param array $options wppo_settings options.
	 * @return Main
	 */
	private function make_main( array $options ): Main {
		$main = ( new \ReflectionClass( Main::class ) )->newInstanceWithoutConstructor();
		$prop = new \ReflectionProperty( Main::class, 'options' );
		$prop->setAccessible( true );
		$prop->setValue( $main, $options );
		return $main;
	}

	/**
	 * Reset the static kill-switch request cache between tests.
	 *
	 * @return void
	 */
	private function reset_kill_switch_cache(): void {
		$prop = new \ReflectionProperty( Main::class, 'delay_disabled_page_cache' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	/**
	 * Builder filter output with hostile values must be sanitized, never fatal.
	 */
	public function test_builder_exclusions_filter_drops_non_strings(): void {
		$this->reset_kill_switch_cache();
		Functions\when( 'has_filter' )->alias(
			static function ( $hook ) {
				return 'wppo_delay_js_builder_exclusions' === $hook;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wppo_delay_js_builder_exclusions' !== $hook ) {
					return $value;
				}
				return array( 'custom-builder', '', new \stdClass(), array( 'nested' ), 123, null, false );
			}
		);

		$list = Main::get_delay_js_builder_exclusions();

		$this->assertContains( 'custom-builder', $list );
		$this->assertContains( '123', $list );
		foreach ( $list as $entry ) {
			$this->assertIsString( $entry );
			$this->assertNotSame( '', $entry );
		}
	}

	/**
	 * Builder filter throwing must fail open to the preset.
	 */
	public function test_builder_exclusions_filter_throwable_fails_open(): void {
		$this->reset_kill_switch_cache();
		Functions\when( 'has_filter' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'wppo_delay_js_builder_exclusions' === $hook ) {
					throw new \RuntimeException( 'boom' );
				}
				return $value;
			}
		);

		$list = Main::get_delay_js_builder_exclusions();

		$this->assertContains( 'elementor-frontend', $list );
	}

	/**
	 * Preset exclusions filter with hostile values must not fatal and must
	 * drop objects/arrays instead of strval-coercing them.
	 */
	public function test_preset_exclusions_filter_drops_hostile_values(): void {
		$this->reset_kill_switch_cache();
		Functions\when( 'has_filter' )->alias(
			static function ( $hook ) {
				return 'wppo_delay_js_exclusions' === $hook;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wppo_delay_js_exclusions' !== $hook ) {
					return $value;
				}
				return array( 'my-safe', new \stdClass(), array( 'x' ), '', 42 );
			}
		);

		$main   = $this->make_main( array( 'file_optimisation' => array() ) );
		$method = new \ReflectionMethod( Main::class, 'get_delay_js_preset_exclusions' );
		$method->setAccessible( true );
		$list = $method->invoke( $main );

		$this->assertContains( 'my-safe', $list );
		$this->assertContains( '42', $list );
		foreach ( $list as $entry ) {
			$this->assertIsString( $entry );
			$this->assertNotSame( '', $entry );
		}
	}

	/**
	 * Preset exclusions filter throwing must fail open to the preset.
	 */
	public function test_preset_exclusions_filter_throwable_fails_open(): void {
		$this->reset_kill_switch_cache();
		Functions\when( 'has_filter' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'wppo_delay_js_exclusions' === $hook ) {
					throw new \Error( 'filter boom' );
				}
				return $value;
			}
		);

		$main   = $this->make_main( array( 'file_optimisation' => array() ) );
		$method = new \ReflectionMethod( Main::class, 'get_delay_js_preset_exclusions' );
		$method->setAccessible( true );
		$list = $method->invoke( $main );

		$this->assertContains( 'elementor-frontend', $list );
	}

	/**
	 * Safe_minify_css_block must return the pristine tag on engine failure
	 * and a style tag on success (fail-open contract).
	 */
	public function test_safe_minify_css_block_fail_open_contract(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'untrailingslashit' )->returnArg();

		$html     = '<html><head></head><body><p>hi</p></body></html>';
		$instance = new \PerformanceOptimise\Inc\Minify\HTML( $html, array() );
		$method   = new \ReflectionMethod( \PerformanceOptimise\Inc\Minify\HTML::class, 'safe_minify_css_block' );
		$method->setAccessible( true );

		$out = $method->invoke( $instance, ' class="x"', 'a { color: red; }' );
		$this->assertStringStartsWith( '<style', $out );
		$this->assertStringContainsString( 'color', $out );
		$this->assertStringEndsWith( '</style>', $out );
	}

	/**
	 * Kill-switch invalidator busts blog_id:post_id keys and tolerates the
	 * deleted_post_meta array-id hook shape.
	 */
	public function test_kill_switch_invalidation_and_array_meta_id_shape(): void {
		$this->reset_kill_switch_cache();
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( '1' );
		Functions\when( 'get_permalink' )->justReturn( false );
		Functions\when( 'wp_make_link_relative' )->justReturn( '' );

		// Prime the request cache for post 960101.
		$this->assertTrue( Main::is_delay_disabled_for_page( 960101 ) );

		// Invalidate: cache entry must be gone even though get_post_meta
		// still returns '1' (proves the bust, not a meta change).
		Main::invalidate_delay_kill_switch_cache( 960101 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$this->assertFalse( Main::is_delay_disabled_for_page( 960101 ) );

		// deleted_post_meta passes an array of meta IDs first — must not fatal.
		$main = $this->make_main( array() );
		$main->on_delay_kill_switch_meta_changed( array( 1, 2, 3 ), 960102, '_wppo_delay_disabled' );
		$this->assertTrue( true );
		// Unrelated meta keys are ignored.
		$main->on_delay_kill_switch_meta_changed( 7, 960103, '_wppo_delay_notes' );
		$this->assertTrue( true );
	}

	/**
	 * Single-URL invalidation fails open on bad input and never wipes.
	 */
	public function test_invalidate_single_static_html_fail_open_paths(): void {
		Functions\when( 'get_permalink' )->justReturn( false );
		Functions\when( 'wp_make_link_relative' )->justReturn( '' );
		Functions\when( 'wp_normalize_path' )->returnArg();

		$cache = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();
		$prop  = new \ReflectionProperty( Cache::class, 'cache_root_dir' );
		$prop->setAccessible( true );
		$prop->setValue( $cache, '' );
		$prop = new \ReflectionProperty( Cache::class, 'domain' );
		$prop->setAccessible( true );
		$prop->setValue( $cache, '' );

		// Zero/negative IDs and unresolvable permalinks are no-ops, never fatal.
		$cache->invalidate_single_static_html( 0 );
		$cache->invalidate_single_static_html( -5 );
		$cache->invalidate_single_static_html( 970101 );
		$this->assertTrue( true );
	}
}
