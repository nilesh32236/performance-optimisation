<?php
/**
 * Parity and source-boundary tests for P3-014 Minify_Policy.
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Loader_Map;
use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Minify\Minify_Policy;

/**
 * P3-014 minification-policy ownership and loader tests.
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */
class MinifyPolicyParityTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Verify the public Main callback facades and owner methods remain aligned.
	 *
	 * @return void
	 */
	public function test_public_facades_and_owner_methods_exist(): void {
		foreach ( array( 'minify_queued_styles', 'minify_css', 'minify_js' ) as $method ) {
			$this->assertTrue( method_exists( Main::class, $method ), "Main::{$method}() must remain a hook-visible facade." );
			$this->assertTrue( method_exists( Minify_Policy::class, $method ), "Minify_Policy::{$method}() must own the callback behavior." );
		}
	}

	/**
	 * Verify stale local classmaps can resolve the new owner.
	 *
	 * @return void
	 */
	public function test_loader_map_resolves_minify_policy(): void {
		$map = Loader_Map::fallback_map();
		$this->assertSame( 'minify/class-minify-policy.php', $map['Minify_Policy'] );
		$this->assertFileExists( (string) Loader_Map::path_for( 'Minify_Policy' ) );
	}

	/**
	 * Pin the name-based minification check under the new owner.
	 *
	 * @return void
	 */
	public function test_minified_name_check_preserved(): void {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		$method = new \ReflectionMethod( Minify_Policy::class, 'is_minified_asset_name' );
		$owner  = ( new \ReflectionClass( Minify_Policy::class ) )->newInstanceWithoutConstructor();

		$this->assertTrue( $method->invoke( $owner, 'https://example.test/app.min.js?ver=1', 'js' ) );
		$this->assertTrue( $method->invoke( $owner, '/themes/example/style.bundle.css', 'css' ) );
		$this->assertFalse( $method->invoke( $owner, 'https://example.test/app.js', 'js' ) );
	}

	/**
	 * Assert the bounded cluster moved without changing hook registration.
	 *
	 * @return void
	 */
	public function test_main_keeps_callbacks_and_owner_owns_cluster(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-boundary assertion reads local first-party source.
		$main_source = file_get_contents( WPPO_PLUGIN_PATH . 'includes/Core/class-main.php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-boundary assertion reads local first-party source.
		$policy_source = file_get_contents( WPPO_PLUGIN_PATH . 'includes/minify/class-minify-policy.php' );
		$this->assertIsString( $main_source );
		$this->assertIsString( $policy_source );
		$this->assertStringContainsString( 'minify_policy()->minify_queued_styles()', $main_source );
		$this->assertStringContainsString( 'minify_policy()->minify_css(', $main_source );
		$this->assertStringContainsString( 'minify_policy()->minify_js(', $main_source );
		$this->assertStringNotContainsString( 'private function is_file_minified', $main_source );
		$this->assertStringContainsString( 'wppo_exclude_minification', $policy_source );
		$this->assertStringContainsString( 'wppo_exclude_randomized_from_combine', $policy_source );
		$this->assertStringContainsString( 'WP_CONTENT_DIR', $policy_source );
	}
}
