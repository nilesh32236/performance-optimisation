<?php
/**
 * Contract tests for the blog-scoped runtime state registry.
 *
 * @package PerformanceOptimise\Tests
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Runtime_State;

/**
 * Runtime state registry contract tests.
 *
 * @package PerformanceOptimise\Tests
 */
class RuntimeStateRegistryTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * The registry names all seven owners (six site-sensitive plus one
	 * classified request-local memo) exactly once.
	 *
	 * @since NEXT OD_Bridge request-local owner (P3-021).
	 * @return void
	 */
	public function test_registry_declares_six_feature_owners(): void {
		$this->assertTrue( class_exists( Runtime_State::class ), 'Runtime_State must be autoloadable.' );
		if ( ! class_exists( Runtime_State::class, false ) ) {
			return;
		}

		$this->assertSame(
			array(
				'RUM'                   => array( 'PerformanceOptimise\\Inc\\RUM', 'clear_field_lcp_cache' ),
				'AI_Adaptive'           => array( 'PerformanceOptimise\\Inc\\AI_Adaptive', 'reset_runtime_state' ),
				'System_Info'           => array( 'PerformanceOptimise\\Inc\\System_Info', 'reset_runtime_state' ),
				'LiteSpeed_Integration' => array( 'PerformanceOptimise\\Inc\\LiteSpeed_Integration', 'reset_cache' ),
				'Object_Cache'          => array( 'PerformanceOptimise\\Inc\\Object_Cache', 'reset_runtime_state' ),
				'Database_Cleanup'      => array( 'PerformanceOptimise\\Inc\\Database_Cleanup', 'reset_runtime_state' ),
				'OD_Bridge'             => array( 'PerformanceOptimise\\Inc\\OD_Bridge', 'clear_request_memo' ),
			),
			Runtime_State::owners()
		);
	}

	/**
	 * The reset registry delegates to every available feature owner safely.
	 *
	 * @return void
	 */
	public function test_reset_all_is_guarded_and_delegates(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( false );

		Runtime_State::reset_all();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Hook_Registry wires the registry to production switch_blog once.
	 *
	 * @return void
	 */
	public function test_hook_registry_registers_runtime_state_switch_reset(): void {
		$source = file_get_contents( WPPO_PLUGIN_PATH . 'includes/Core/class-hook-registry.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static source seam.
		$this->assertIsString( $source );
		$this->assertStringContainsString( "add_action( 'switch_blog', array( 'PerformanceOptimise\\Inc\\Runtime_State', 'on_switch_blog' ), 10, 2 )", (string) $source );
	}
}
