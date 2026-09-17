<?php
/**
 * Tests for Main::add_available_post_types_to_options().
 *
 * Verifies that the get_post_types() result is safeguarded with an is_array()
 * check before being passed to array_diff(), and that availablePostTypes is
 * always populated with a sane value (all public types minus 'attachment').
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;
use Brain\Monkey\Functions;

/**
 * Tests the add_available_post_types_to_options() private method.
 *
 * @package PerformanceOptimise\Tests
 */
class MainAvailablePostTypesTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Stub the WP environment needed to construct Main.
	 *
	 * @return void
	 */
	private function stub_main_construction(): void {
		Functions\stubs(
			array(
				'WP_Filesystem'       => false,
				'sanitize_text_field' => '',
				'wp_unslash'          => '',
				'is_user_logged_in'   => false,
			)
		);

		Functions\when( 'get_option' )->alias(
			static function ( $option, $default_value = false ) {
				return $default_value;
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'content_url' )->returnArg();
		Functions\when( 'trailingslashit' )->returnArg();
		Functions\when( 'wp_is_block_theme' )->justReturn( false );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );

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
	 * Invoke the private add_available_post_types_to_options() method and
	 * return the resulting availablePostTypes option.
	 *
	 * @param mixed $post_types_return The value get_post_types() should return.
	 * @return mixed The availablePostTypes option value.
	 */
	private function run_method_with_post_types( $post_types_return ) {
		Functions\when( 'get_post_types' )->justReturn( $post_types_return );

		$main = new Main();

		$reflection = new \ReflectionMethod( Main::class, 'add_available_post_types_to_options' );
		$reflection->setAccessible( true );
		$reflection->invoke( $main );

		$options_reflection = new \ReflectionProperty( Main::class, 'options' );
		$options_reflection->setAccessible( true );
		$options = $options_reflection->getValue( $main );

		return $options['image_optimisation']['availablePostTypes'] ?? null;
	}

	/**
	 * Test that a normal array input returns all public post types minus
	 * 'attachment'.
	 */
	public function test_returns_public_post_types_excluding_attachment(): void {
		$this->stub_main_construction();

		$result = $this->run_method_with_post_types(
			array(
				'post'          => 'post',
				'page'          => 'page',
				'attachment'    => 'attachment',
				'product'       => 'product',
				'revision'      => 'revision',
				'nav_menu_item' => 'nav_menu_item',
			)
		);

		$this->assertContains( 'post', $result );
		$this->assertContains( 'page', $result );
		$this->assertContains( 'product', $result );
		$this->assertContains( 'revision', $result );
		$this->assertNotContains( 'attachment', $result );
		$this->assertContains( 'nav_menu_item', $result );
	}

	/**
	 * Test that a non-array (false) return from get_post_types() does not cause
	 * an error and results in an empty availablePostTypes array.
	 */
	public function test_non_array_post_types_returns_empty_array_without_error(): void {
		$this->stub_main_construction();

		$result = $this->run_method_with_post_types( false );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test that a null return from get_post_types() also resolves safely to an
	 * empty array.
	 */
	public function test_null_post_types_returns_empty_array_without_error(): void {
		$this->stub_main_construction();

		$result = $this->run_method_with_post_types( null );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}
}
