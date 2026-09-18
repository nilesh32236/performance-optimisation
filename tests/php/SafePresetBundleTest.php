<?php
/**
 * Tests for the one-click Safe / Aggressive preset bundles (issue #1442).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;
use Brain\Monkey\Functions;

/**
 * Covers Main::get_safe_preset_bundle(), get_aggressive_preset_bundle(),
 * apply_preset_bundle() and is_safe_preset_active().
 *
 * @package PerformanceOptimise\Tests
 */
class SafePresetBundleTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap {
		setUp as protected wppoSetUp;
	}

	/**
	 * Stub the filter API so preset getters run unfiltered.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->wppoSetUp();
		Functions\when( 'has_filter' )->justReturn( false );
	}

	/**
	 * Safe bundle enables minify/defer/delay with builder, jQuery and Woo exclusions.
	 */
	public function test_safe_preset_bundle_enables_pipelines_with_excludes(): void {
		$bundle = Main::get_safe_preset_bundle();

		$this->assertTrue( $bundle['minifyJS'] );
		$this->assertTrue( $bundle['minifyCSS'] );
		$this->assertTrue( $bundle['minifyHTML'] );
		$this->assertTrue( $bundle['deferJS'] );
		$this->assertTrue( $bundle['delayJS'] );
		$this->assertTrue( $bundle['delayJSBuilderPreset'] );
		$this->assertTrue( $bundle['delayJSCommercePreset'] );
		$this->assertTrue( $bundle['delayJSInteractionPreset'] );
		$this->assertTrue( $bundle['delayJSJqueryPreset'] );
		$this->assertTrue( $bundle['delayJSSafeMode'] );
		$this->assertTrue( $bundle['elementorSafeMode'] );
		// Opt-in presets stay off; combining stays off (FOUC risk).
		$this->assertFalse( $bundle['delayJSConsentPreset'] );
		$this->assertFalse( $bundle['delayJSAnalyticsPreset'] );
		$this->assertFalse( $bundle['delayJSGalleryPreset'] );
		$this->assertFalse( $bundle['combineCSS'] );
	}

	/**
	 * Aggressive bundle drops the safe exclusions behind the explicit warning.
	 */
	public function test_aggressive_preset_bundle_drops_safe_presets(): void {
		$bundle = Main::get_aggressive_preset_bundle();

		$this->assertTrue( $bundle['delayJS'] );
		$this->assertTrue( $bundle['deferJS'] );
		$this->assertFalse( $bundle['delayJSBuilderPreset'] );
		$this->assertFalse( $bundle['delayJSCommercePreset'] );
		$this->assertFalse( $bundle['delayJSInteractionPreset'] );
		$this->assertFalse( $bundle['delayJSJqueryPreset'] );
		$this->assertTrue( $bundle['combineCSS'] );
	}

	/**
	 * Bundles use only pre-existing keys: no schema change.
	 */
	public function test_preset_bundles_use_existing_keys_only(): void {
		$defaults = \PerformanceOptimise\Inc\Util::get_default_settings();
		$known    = array_keys( $defaults['file_optimisation'] );

		foreach ( array( Main::get_safe_preset_bundle(), Main::get_aggressive_preset_bundle() ) as $bundle ) {
			foreach ( array_keys( $bundle ) as $key ) {
				$this->assertContains( $key, $known, "Preset key {$key} must already exist in file_optimisation defaults." );
			}
		}
	}

	/**
	 * Bundle application merges additively and skips unknown keys.
	 */
	public function test_apply_preset_bundle_merges_additively(): void {
		$current = array(
			'minifyJS'            => false,
			'excludeJS'           => 'keep-me.js',
			'cdnURL'              => 'https://cdn.example.com',
			'combineCSS'          => false,
			'delayJSJqueryPreset' => false,
		);

		$merged = Main::apply_preset_bundle( $current, Main::get_safe_preset_bundle() );

		$this->assertTrue( $merged['minifyJS'] );
		$this->assertTrue( $merged['delayJSJqueryPreset'] );
		// Untouched keys survive.
		$this->assertSame( 'keep-me.js', $merged['excludeJS'] );
		$this->assertSame( 'https://cdn.example.com', $merged['cdnURL'] );

		// Unknown keys never widen the schema.
		$merged = Main::apply_preset_bundle( $current, array( 'notARealKey' => true ) );
		$this->assertArrayNotHasKey( 'notARealKey', $merged );
	}

	/**
	 * Safe-preset detection gates on pipelines plus all four safe presets.
	 */
	public function test_is_safe_preset_active_gates_correctly(): void {
		$safe = Main::apply_preset_bundle( array(), Main::get_safe_preset_bundle() );
		$this->assertTrue( Main::is_safe_preset_active( $safe ) );

		$no_jquery                        = $safe;
		$no_jquery['delayJSJqueryPreset'] = false;
		$this->assertFalse( Main::is_safe_preset_active( $no_jquery ) );

		$pipelines_off            = $safe;
		$pipelines_off['delayJS'] = false;
		$this->assertFalse( Main::is_safe_preset_active( $pipelines_off ) );

		$this->assertFalse( Main::is_safe_preset_active( array() ) );
	}
}
