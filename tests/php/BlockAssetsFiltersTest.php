<?php
/**
 * Tests for Main::register_block_assets_filters().
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Tests for the WP 6.9 block-assets filter registration.
 *
 * @package PerformanceOptimise\Tests
 */
class BlockAssetsFiltersTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Invoke the private filter-registration method.
	 *
	 * @param bool  $loads_on_demand Whether WP 6.9+ core loads separate block assets on demand.
	 * @param array $options         Plugin options (may be partial).
	 * @return void
	 */
	private function register_filters( bool $loads_on_demand, array $options = array() ): void {
		$main = ( new ReflectionClass( Main::class ) )->newInstanceWithoutConstructor();

		$options_prop = new ReflectionProperty( Main::class, 'options' );
		$options_prop->setAccessible( true );
		$options_prop->setValue(
			$main,
			array(
				'file_optimisation' => array_merge( array( 'blockAssetsOnDemand' => false ), $options ),
			)
		);

		$method = new ReflectionMethod( Main::class, 'register_block_assets_filters' );
		$method->setAccessible( true );
		$method->invoke( $main, $loads_on_demand );
	}

	/**
	 * Pre-6.9 core with the toggle OFF must register nothing (legacy combined default).
	 */
	public function test_pre_69_toggle_off_registers_no_filters(): void {
		$this->register_filters( false );

		$this->assertFalse( Filters\has( 'should_load_block_assets_on_demand' ) );
		$this->assertFalse( Filters\has( 'should_load_separate_core_block_assets' ) );
	}

	/**
	 * Pre-6.9 core with the toggle ON must opt in via the legacy filter.
	 */
	public function test_pre_69_toggle_on_registers_legacy_opt_in(): void {
		$this->register_filters( false, array( 'blockAssetsOnDemand' => true ) );

		$this->assertSame( 10, Filters\has( 'should_load_block_assets_on_demand', '__return_true' ) );
		$this->assertFalse( Filters\has( 'should_load_separate_core_block_assets' ) );
	}

	/**
	 * WP 6.9+ classic theme with the toggle OFF must opt out of on-demand loading.
	 */
	public function test_69_classic_toggle_off_registers_opt_out(): void {
		Functions\when( 'wp_is_block_theme' )->justReturn( false );

		$this->register_filters( true );

		$this->assertTrue( Filters\has( 'should_load_separate_core_block_assets', '__return_false', 10 ) );
		$this->assertFalse( Filters\has( 'should_load_block_assets_on_demand' ) );
	}

	/**
	 * WP 6.9+ classic theme with the toggle ON must leave core's default untouched.
	 */
	public function test_69_classic_toggle_on_registers_no_filters(): void {
		$this->register_filters( true, array( 'blockAssetsOnDemand' => true ) );

		$this->assertFalse( Filters\has( 'should_load_separate_core_block_assets' ) );
		$this->assertFalse( Filters\has( 'should_load_block_assets_on_demand' ) );
	}

	/**
	 * WP 6.9+ block theme with the toggle OFF must keep core's separate-assets default.
	 */
	public function test_69_block_theme_toggle_off_registers_no_filters(): void {
		Functions\when( 'wp_is_block_theme' )->justReturn( true );

		$this->register_filters( true );

		$this->assertFalse( Filters\has( 'should_load_separate_core_block_assets' ) );
		$this->assertFalse( Filters\has( 'should_load_block_assets_on_demand' ) );
	}
}
