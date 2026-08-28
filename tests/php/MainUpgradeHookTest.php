<?php
/**
 * Regression test for C-01 namespace typo.
 *
 * Verifies the wppo_run_upgrades hook resolves to the correct class
 * PerformanceOptimise\Inc\Activate (not PerformanceOptimisation) and that
 * Main::setup_hooks registers it.
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */

use PerformanceOptimise\Inc\Activate;
use PerformanceOptimise\Inc\Main;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

/**
 * Tests C-01 fix: namespace typo in wppo_run_upgrades hook.
 */
class MainUpgradeHookTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Test that the correct class exists and the typo class does not.
	 */
	public function test_activate_class_exists_correct_namespace(): void {
		$this->assertTrue( class_exists( Activate::class ), 'PerformanceOptimise\\Inc\\Activate must exist' );
		$this->assertTrue( method_exists( Activate::class, 'maybe_run_upgrades' ), 'maybe_run_upgrades must exist' );
		$this->assertFalse( class_exists( 'PerformanceOptimisation\\Inc\\Activate' ), 'Typo namespace must NOT exist' );
	}

	/**
	 * Test that the source file contains the corrected namespace string.
	 */
	public function test_source_contains_corrected_hook_callback(): void {
		$main_file = file_get_contents( WPPO_PLUGIN_PATH . 'includes/class-main.php' );
		$this->assertIsString( $main_file );
		$this->assertStringContainsString( 'PerformanceOptimise\\Inc\\Activate', $main_file );
		// Ensure the old typo string does not appear in code (docs/AUDIT may still mention it).
		$code_hits = 0;
		if ( preg_match_all( '/PerformanceOptimisation\\\\Inc\\\\Activate/', $main_file, $m ) ) {
			$code_hits = count( $m[0] );
		}
		$this->assertSame( 0, $code_hits, 'Old typo PerformanceOptimisation\\Inc\\Activate must not be in class-main.php' );
	}

	/**
	 * Test that Main registers wppo_run_upgrades with the correct callback.
	 */
	public function test_main_registers_wppo_run_upgrades_with_correct_class(): void {
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
		Functions\when( 'function_exists' )->alias(
			static function ( $function_name ) {
				if ( 'WP_Filesystem' === $function_name || 'wp_is_block_theme' === $function_name ) {
					return true;
				}
				return \function_exists( $function_name );
			}
		);

		new Main();

		// Branch: Actions::has checks isHookAdded for wppo_run_upgrades.
		$this->assertTrue( Actions\has( 'wppo_run_upgrades' ), 'wppo_run_upgrades action must be registered' );
		// Check exact callback.
		$priority = Actions\has( 'wppo_run_upgrades', array( 'PerformanceOptimise\\Inc\\Activate', 'maybe_run_upgrades' ) );
		$this->assertNotFalse( $priority, 'Correct callback PerformanceOptimise\\Inc\\Activate::maybe_run_upgrades must be registered' );
		// Ensure typo callback not registered.
		$typo_priority = Actions\has( 'wppo_run_upgrades', array( 'PerformanceOptimisation\\Inc\\Activate', 'maybe_run_upgrades' ) );
		$this->assertFalse( $typo_priority, 'Typo callback must NOT be registered' );
	}
}
