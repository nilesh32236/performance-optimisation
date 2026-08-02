<?php
/**
 * Tests for Main block-assets (WP 6.8/6.9 on-demand) filter registration.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;

/**
 * Tests the pre-init filter registration that reconciles the plugin with the
 * WP 6.9 classic-theme on-demand block-styles default.
 *
 * @package PerformanceOptimise\Tests
 */
class MainBlockAssetsTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Stub the WP environment needed to construct Main with the given
	 * file_optimisation overrides.
	 *
	 * @param array $file_overrides file_optimisation option overrides.
	 * @param bool  $wp_69          Whether to simulate WP 6.9+ (wp_load_classic_theme_block_styles_on_demand exists).
	 * @return void
	 */
	private function stub_main_construction( array $file_overrides, bool $wp_69 = false ): void {
		Functions\stubs(
			array(
				'WP_Filesystem'       => false,
				'sanitize_text_field' => '',
				'wp_unslash'          => '',
				'is_user_logged_in'   => false,
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
		Functions\when( 'function_exists' )->alias(
			static function ( $function_name ) use ( $wp_69 ) {
				if ( 'WP_Filesystem' === $function_name ) {
					return true;
				}
				if ( $wp_69 && 'wp_load_classic_theme_block_styles_on_demand' === $function_name ) {
					return true;
				}
				return false;
			}
		);
	}

	/**
	 * Test that blockAssetsOnDemand registers the WP 6.8 on-demand filter and
	 * never touches the WP 6.9+ separate-assets opt-out.
	 */
	public function test_block_assets_on_demand_registers_68_filter(): void {
		$this->stub_main_construction( array( 'blockAssetsOnDemand' => true ) );
		new Main();

		$this->assertSame(
			10,
			Filters\has( 'should_load_block_assets_on_demand', '__return_true' )
		);
		$this->assertFalse(
			Filters\has( 'should_load_separate_core_block_assets', '__return_false' )
		);
	}

	/**
	 * Test that loadAllCoreBlockAssets (WP 6.9+) registers the opt-out filter
	 * and suppresses the 6.8 on-demand filter.
	 */
	public function test_load_all_core_block_assets_registers_69_optout(): void {
		$this->stub_main_construction( array( 'loadAllCoreBlockAssets' => true ), true );
		new Main();

		$this->assertSame(
			10,
			Filters\has( 'should_load_separate_core_block_assets', '__return_false' )
		);
		$this->assertFalse(
			Filters\has( 'should_load_block_assets_on_demand', '__return_true' )
		);
	}

	/**
	 * Test precedence: when both settings are on, the combined-library opt-out
	 * wins and the on-demand filter is suppressed.
	 */
	public function test_optout_wins_when_both_settings_enabled(): void {
		$this->stub_main_construction(
			array(
				'blockAssetsOnDemand'    => true,
				'loadAllCoreBlockAssets' => true,
			),
			true
		);
		new Main();

		$this->assertSame(
			10,
			Filters\has( 'should_load_separate_core_block_assets', '__return_false' )
		);
		$this->assertFalse(
			Filters\has( 'should_load_block_assets_on_demand', '__return_true' )
		);
	}

	/**
	 * Test that the 6.9 opt-out is not registered when the 6.9 core function is
	 * absent (WP 6.8), keeping the setting inert on older WordPress versions.
	 */
	public function test_optout_inert_on_wp_68(): void {
		$this->stub_main_construction( array( 'loadAllCoreBlockAssets' => true ), false );
		new Main();

		$this->assertFalse(
			Filters\has( 'should_load_separate_core_block_assets', '__return_false' )
		);
		$this->assertFalse(
			Filters\has( 'should_load_block_assets_on_demand', '__return_true' )
		);
	}

	/**
	 * Test backward compatibility: on WP 6.8 with both settings on, the inert
	 * opt-out must not suppress the existing 6.8 on-demand filter.
	 */
	public function test_on_demand_preserved_on_wp_68_when_both_enabled(): void {
		$this->stub_main_construction(
			array(
				'blockAssetsOnDemand'    => true,
				'loadAllCoreBlockAssets' => true,
			),
			false
		);
		new Main();

		$this->assertSame(
			10,
			Filters\has( 'should_load_block_assets_on_demand', '__return_true' )
		);
		$this->assertFalse(
			Filters\has( 'should_load_separate_core_block_assets', '__return_false' )
		);
	}
}
