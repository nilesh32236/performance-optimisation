<?php
/**
 * Regression tests for H-05 logged-in bail.
 *
 * Asset_Manager previously returned early when is_user_logged_in(), preventing
 * per-page dequeue for authenticated users. The fix removes that guard so
 * logged-in visitors still get the optimized asset list (except admin).
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */

use PerformanceOptimise\Inc\Asset_Manager;
use Brain\Monkey\Functions;

/**
 * Tests for Asset_Manager dequeue gate.
 */
class AssetManagerTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Build a fresh Asset_Manager without constructor side-effects.
	 *
	 * @return Asset_Manager
	 */
	private function make_manager(): Asset_Manager {
		return ( new ReflectionClass( Asset_Manager::class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * Helper to install stubs for a dequeue test.
	 *
	 * @param bool $is_admin Whether is_admin() should return true.
	 * @param bool $is_singular Whether is_singular() should return true.
	 * @param int  $post_id Post ID.
	 */
	private function install_dequeue_stubs( bool $is_admin, bool $is_singular, int $post_id ): void {
		Functions\when( 'is_admin' )->justReturn( $is_admin );
		Functions\when( 'is_singular' )->justReturn( $is_singular );
		Functions\when( 'get_the_ID' )->justReturn( $post_id );
		Functions\when( 'is_user_logged_in' )->justReturn( true ); // Should be ignored after fix.
		Functions\when( 'get_post_meta' )->alias(
			static function ( $pid, $key, $single ) {
				if ( '_wppo_disabled_scripts' === $key ) {
					return array( 'my-plugin' );
				}
				if ( '_wppo_disabled_styles' === $key ) {
					return array( 'my-theme' );
				}
				return array();
			}
		);
		Functions\when( 'wp_dequeue_script' )->justReturn( true );
		Functions\when( 'wp_deregister_script' )->justReturn( true );
		Functions\when( 'wp_dequeue_style' )->justReturn( true );
		Functions\when( 'wp_deregister_style' )->justReturn( true );
	}

	/**
	 * Test that dequeue runs for logged-in users on singular frontend.
	 */
	public function test_dequeue_runs_for_logged_in_user(): void {
		$this->install_dequeue_stubs( false, true, 123 );

		$dequeued_scripts = array();
		$dequeued_styles  = array();
		Functions\when( 'wp_dequeue_script' )->alias(
			static function ( $h ) use ( &$dequeued_scripts ) {
				$dequeued_scripts[] = $h;
			}
		);
		Functions\when( 'wp_dequeue_style' )->alias(
			static function ( $h ) use ( &$dequeued_styles ) {
				$dequeued_styles[] = $h;
			}
		);

		$mgr = $this->make_manager();
		$mgr->dequeue_selected_assets();

		$this->assertContains( 'my-plugin', $dequeued_scripts, 'Script must be dequeued even when logged-in' );
		$this->assertContains( 'my-theme', $dequeued_styles, 'Style must be dequeued even when logged-in' );
	}

	/**
	 * Test that dequeue still bails in admin.
	 */
	public function test_dequeue_bails_in_admin(): void {
		$this->install_dequeue_stubs( true, true, 123 );

		$called = false;
		Functions\when( 'wp_dequeue_script' )->alias(
			static function () use ( &$called ) {
				$called = true;
			}
		);
		Functions\when( 'wp_dequeue_style' )->alias(
			static function () use ( &$called ) {
				$called = true;
			}
		);

		$mgr = $this->make_manager();
		$mgr->dequeue_selected_assets();

		$this->assertFalse( $called, 'Must not dequeue in admin' );
	}

	/**
	 * Test that dequeue bails when not singular.
	 */
	public function test_dequeue_bails_when_not_singular(): void {
		$this->install_dequeue_stubs( false, false, 123 );

		$called = false;
		Functions\when( 'wp_dequeue_script' )->alias(
			static function () use ( &$called ) {
				$called = true;
			}
		);
		$called_style = false;
		Functions\when( 'wp_dequeue_style' )->alias(
			static function () use ( &$called_style ) {
				$called_style = true;
			}
		);

		$mgr = $this->make_manager();
		$mgr->dequeue_selected_assets();

		$this->assertFalse( $called, 'Must not dequeue when not singular' );
		$this->assertFalse( $called_style );
	}

	/**
	 * Test that protected handles are not dequeued even for logged-in.
	 */
	public function test_protected_handles_not_dequeued(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 123 );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_post_meta' )->alias(
			static function ( $pid, $key, $single ) {
				if ( '_wppo_disabled_scripts' === $key ) {
					return array( 'jquery', 'my-plugin' );
				}
				return array();
			}
		);
		$dequeued = array();
		Functions\when( 'wp_dequeue_script' )->alias(
			static function ( $h ) use ( &$dequeued ) {
				$dequeued[] = $h;
			}
		);
		Functions\when( 'wp_deregister_script' )->justReturn( true );
		Functions\when( 'wp_dequeue_style' )->justReturn( true );
		Functions\when( 'wp_deregister_style' )->justReturn( true );

		$mgr = $this->make_manager();
		$mgr->dequeue_selected_assets();

		$this->assertNotContains( 'jquery', $dequeued, 'Protected handle jquery must not be dequeued' );
		$this->assertContains( 'my-plugin', $dequeued );
	}

	/**
	 * Verify source does not contain is_user_logged_in guard.
	 */
	public function test_source_has_no_logged_in_guard(): void {
		$src = file_get_contents( WPPO_PLUGIN_PATH . 'includes/class-asset-manager.php' );
		$this->assertIsString( $src );
		// The file should contain `if ( is_admin() )` but not `is_user_logged_in` in dequeue method.
		$dequeue_section = substr( $src, strpos( $src, 'function dequeue_selected_assets' ), 800 );
		$this->assertStringContainsString( 'is_admin()', $dequeue_section );
		$this->assertStringNotContainsString( 'is_user_logged_in', $dequeue_section );
	}
}
