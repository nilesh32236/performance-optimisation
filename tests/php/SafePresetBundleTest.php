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

		$no_safe_mode                    = $safe;
		$no_safe_mode['delayJSSafeMode'] = false;
		$this->assertFalse( Main::is_safe_preset_active( $no_safe_mode ), 'Disabling delayJSSafeMode must clear the safe-active confirmation.' );

		$no_elementor                      = $safe;
		$no_elementor['elementorSafeMode'] = false;
		$this->assertFalse( Main::is_safe_preset_active( $no_elementor ), 'Disabling elementorSafeMode must clear the safe-active confirmation.' );

		$combine_on               = $safe;
		$combine_on['combineCSS'] = true;
		$this->assertFalse( Main::is_safe_preset_active( $combine_on ), 'Enabling combineCSS must clear the safe-active confirmation (FOUC risk).' );

		$no_html               = $safe;
		$no_html['minifyHTML'] = false;
		$this->assertFalse( Main::is_safe_preset_active( $no_html ), 'Disabling minifyHTML must clear the safe-active confirmation.' );

		$pipelines_off            = $safe;
		$pipelines_off['delayJS'] = false;
		$this->assertFalse( Main::is_safe_preset_active( $pipelines_off ) );

		$this->assertFalse( Main::is_safe_preset_active( array() ) );
	}

	/**
	 * Extract a `export const NAME = { ... };` boolean map from the SPA source.
	 *
	 * @param string $source JS source of FileOptimization.js.
	 * @param string $name   Exported constant name.
	 * @return array<string, bool>
	 */
	private function extract_js_bundle( string $source, string $name ): array {
		$pattern = '/export const ' . preg_quote( $name, '/' ) . '\s*=\s*\{(.*?)\};/s';
		$this->assertSame( 1, preg_match( $pattern, $source, $matches ), "JS constant {$name} must exist in FileOptimization.js." );
		preg_match_all( '/([A-Za-z0-9_]+)\s*:\s*(true|false)/', $matches[1], $pairs, PREG_SET_ORDER );
		$this->assertNotEmpty( $pairs, "JS constant {$name} must contain boolean entries." );
		$bundle = array();
		foreach ( $pairs as $pair ) {
			$bundle[ $pair[1] ] = 'true' === $pair[2];
		}
		return $bundle;
	}

	/**
	 * PHP bundles must match the SPA fallback mirrors value-for-value
	 * (issue #1442 review): the client prefers the server-localised copy
	 * at runtime, but the local constants must stay identical so the
	 * fail-open fallback applies the same settings.
	 *
	 * @phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	 */
	public function test_php_bundles_match_js_fallback_mirrors(): void {
		$path = dirname( __DIR__, 2 ) . '/src/components/FileOptimization.js';
		$this->assertFileExists( $path );
		$source = (string) file_get_contents( $path );

		$this->assertEquals(
			Main::get_safe_preset_bundle(),
			$this->extract_js_bundle( $source, 'SAFE_PRESET_BUNDLE' ),
			'SAFE_PRESET_BUNDLE in FileOptimization.js must match Main::get_safe_preset_bundle() value-for-value.'
		);
		$this->assertEquals(
			Main::get_aggressive_preset_bundle(),
			$this->extract_js_bundle( $source, 'AGGRESSIVE_PRESET_BUNDLE' ),
			'AGGRESSIVE_PRESET_BUNDLE in FileOptimization.js must match Main::get_aggressive_preset_bundle() value-for-value.'
		);
	}

	/**
	 * Preset bundles are localised to the SPA as the authoritative copy
	 * (issue #1442 review): wppoSettings.presetBundles must carry both
	 * bundles next to allowedSettingsKeys.
	 *
	 * @phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	 */
	public function test_preset_bundles_localised_to_spa(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Core/class-main.php' );
		$this->assertStringContainsString( "'presetBundles'", $source, 'wppoSettings must localise presetBundles.' );
		$this->assertStringContainsString( 'get_safe_preset_bundle()', $source );
		$this->assertStringContainsString( 'get_aggressive_preset_bundle()', $source );
		$this->assertStringContainsString( 'resolvePresetBundle', (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/components/FileOptimization.js' ), 'SPA must resolve bundles via resolvePresetBundle().' );
	}
}
