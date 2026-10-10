<?php
/**
 * Tests for the used-CSS inline-vs-file delivery target + preload-first-N (issue #1410).
 *
 * Covers the additive `file_optimisation.usedCssDelivery` allowlist
 * (fail-open to file = current behaviour), the `preload_settings.preloadCssFirstN`
 * saturating clamp (0-5, matching the JS normalizer), the `wppo_inline_combined_css` falsy escape
 * hatch for inline output, the bounded inline sidecar reader, and the
 * builder smoke gate that keeps builder pages styled.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Settings_Store;
use PerformanceOptimise\Inc\Used_CSS;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests for Used_CSS inline delivery target / preload-first-N.
 *
 * @package PerformanceOptimise\Tests
 */
class UsedCssInlineFileDelivery1410Test extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Reset memos + Brain Monkey between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Util::clear_settings_cache();
		\Brain\Monkey\tearDown();
		if ( class_exists( 'PerformanceOptimise\Inc\Main' ) ) {
			\PerformanceOptimise\Inc\Main::reset_instance();
		}
		parent::tearDown();
	}

	/**
	 * Delivery target defaults to file and allowlists file/inline.
	 */
	public function test_delivery_target_allowlist(): void {
		$this->assertSame( 'file', Used_CSS::get_used_css_delivery_target( array() ) );
		$this->assertSame( 'inline', Used_CSS::get_used_css_delivery_target( array( 'usedCssDelivery' => 'inline' ) ) );
		$this->assertSame( 'file', Used_CSS::get_used_css_delivery_target( array( 'usedCssDelivery' => 'file' ) ) );
		$this->assertSame( 'file', Used_CSS::get_used_css_delivery_target( array( 'usedCssDelivery' => 'bogus' ) ) );
		$this->assertSame( 'file', Used_CSS::get_used_css_delivery_target( array( 'usedCssDelivery' => '' ) ) );
		$this->assertSame( 'inline', Used_CSS::get_used_css_delivery_target( array( 'usedCssDelivery' => 'Inline' ) ) );
		$this->assertSame( 'inline', Used_CSS::get_used_css_delivery_target( array( 'usedCssDelivery' => ' inline ' ) ) );
		$this->assertContains( 'file', Used_CSS::DELIVERY_TARGETS );
		$this->assertContains( 'inline', Used_CSS::DELIVERY_TARGETS );
	}

	/**
	 * The existing delivery-mode axis is untouched by the new target key.
	 */
	public function test_delivery_mode_axis_unchanged(): void {
		$this->assertSame( 'file', Used_CSS::get_used_css_delivery_mode( array() ) );
		$this->assertSame( 'file', Used_CSS::get_used_css_delivery_mode( array( 'usedCssDelivery' => 'inline' ) ) );
		$this->assertSame( 'delay', Used_CSS::get_used_css_delivery_mode( array( 'usedCSSDeliveryMode' => 'delay' ) ) );
		$this->assertFalse( Used_CSS::is_used_css_inline_enabled( array() ) );
		$this->assertTrue( Used_CSS::is_used_css_inline_enabled( array( 'usedCssDelivery' => 'inline' ) ) );
		$this->assertFalse( Used_CSS::is_used_css_inline_enabled( array( 'usedCssDelivery' => 'bogus' ) ) );
	}

	/**
	 * Preload-first-N defaults to 0 and saturates to 0-5 (matching the JS normalizer).
	 */
	public function test_preload_css_first_n_clamp(): void {
		$this->assertSame( 0, Used_CSS::get_preload_css_first_n( array() ) );
		$this->assertSame( 0, Used_CSS::get_preload_css_first_n( array( 'preloadCssFirstN' => 0 ) ) );
		$this->assertSame( 3, Used_CSS::get_preload_css_first_n( array( 'preloadCssFirstN' => 3 ) ) );
		$this->assertSame( 5, Used_CSS::get_preload_css_first_n( array( 'preloadCssFirstN' => 5 ) ) );
		$this->assertSame( 5, Used_CSS::get_preload_css_first_n( array( 'preloadCssFirstN' => 6 ) ) );
		$this->assertSame( 0, Used_CSS::get_preload_css_first_n( array( 'preloadCssFirstN' => -1 ) ) );
		$this->assertSame( 0, Used_CSS::get_preload_css_first_n( array( 'preloadCssFirstN' => 'bogus' ) ) );
		$this->assertSame( 0, Used_CSS::get_preload_css_first_n( array( 'preloadCssFirstN' => array( 2 ) ) ) );
		$this->assertSame( 2, Used_CSS::get_preload_css_first_n( array( 'preloadCssFirstN' => '2' ) ) );
	}

	/**
	 * Inline output honors the falsy wppo_inline_combined_css escape hatch.
	 */
	public function test_inline_allowed_honors_falsy_filter(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'wppo_inline_combined_css' === $hook ) {
					return false;
				}
				return $value;
			}
		);
		$this->assertFalse( Used_CSS::is_used_css_inline_allowed() );
	}

	/**
	 * Inline output is allowed by default.
	 */
	public function test_inline_allowed_by_default(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				return $value;
			}
		);
		$this->assertTrue( Used_CSS::is_used_css_inline_allowed() );
	}

	/**
	 * The inline sidecar reader fails open on missing/empty files.
	 */
	public function test_inline_css_reader_fails_open(): void {
		$this->assertSame( '', Used_CSS::get_used_css_inline_css( '' ) );
		$this->assertSame( '', Used_CSS::get_used_css_inline_css( '/nonexistent-wppo/used-css.css' ) );
		$empty = tempnam( sys_get_temp_dir(), 'wppo-inline-' );
		$this->assertNotFalse( $empty );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only temp fixture.
		file_put_contents( $empty, "   \n" );
		$this->assertSame( '', Used_CSS::get_used_css_inline_css( $empty ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only temp cleanup.
		unlink( $empty );
	}

	/**
	 * The inline sidecar reader returns small files and neutralizes style breakouts.
	 */
	public function test_inline_css_reader_returns_small_files(): void {
		$path = tempnam( sys_get_temp_dir(), 'wppo-inline-' );
		$this->assertNotFalse( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only temp fixture.
		file_put_contents( $path, '.a{color:red}.elementor-widget{color:blue}' );
		$css = Used_CSS::get_used_css_inline_css( $path );
		$this->assertStringContainsString( '.a{color:red}', $css );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only temp cleanup.
		unlink( $path );

		$evil = tempnam( sys_get_temp_dir(), 'wppo-inline-' );
		$this->assertNotFalse( $evil );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only temp fixture.
		file_put_contents( $evil, '.a{color:red}</style><script>alert(1)</script>' );
		$sanitized = Used_CSS::get_used_css_inline_css( $evil );
		$this->assertStringNotContainsString( '</style', strtolower( $sanitized ) );
		$this->assertStringContainsString( '.a{color:red}', $sanitized );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only temp cleanup.
		unlink( $evil );
	}

	/**
	 * Oversize sidecars stay a separate file (fail-open to the link).
	 */
	public function test_inline_css_reader_rejects_oversize(): void {
		$path = tempnam( sys_get_temp_dir(), 'wppo-inline-' );
		$this->assertNotFalse( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only temp fixture.
		file_put_contents( $path, str_repeat( '.a{color:red}', 9000 ) );
		$this->assertGreaterThan( Used_CSS::MAX_INLINE_BYTES, filesize( $path ) );
		$this->assertSame( '', Used_CSS::get_used_css_inline_css( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only temp cleanup.
		unlink( $path );
	}

	/**
	 * The inline tag builder emits a style tag, or '' on empty input.
	 */
	public function test_build_inline_tag(): void {
		$this->assertSame( '', Used_CSS::build_inline_used_css_tag( '' ) );
		$this->assertSame( '', Used_CSS::build_inline_used_css_tag( "  \n" ) );
		$tag = Used_CSS::build_inline_used_css_tag( '.a{color:red}' );
		$this->assertStringContainsString( '<style id="wppo-used-css-inline">', $tag );
		$this->assertStringContainsString( '.a{color:red}', $tag );
	}

	/**
	 * Builder pages with stripped builder coverage fail the inline smoke check.
	 */
	public function test_inline_smoke_keeps_builder_pages_styled(): void {
		$builder_html = '<html><body><div data-elementor-type="wp-page"><div class="elementor-widget">x</div></div></body></html>';
		$this->assertFalse( Used_CSS::passes_elementor_smoke( $builder_html, '.a{color:red}' ) );
		$this->assertTrue( Used_CSS::passes_elementor_smoke( $builder_html, '.elementor-widget{color:blue}' ) );
		$this->assertTrue( Used_CSS::passes_elementor_smoke( '<html><body><p>Hello</p></body></html>', '.a{color:red}' ) );
	}

	/**
	 * New keys survive the settings sanitizer; unknown values fail open
	 * (delivery to file, count saturated to the nearest 0-5 bound).
	 */
	public function test_sanitizer_pins_new_keys(): void {
		$sanitized = Settings_Store::sanitize_settings_recursively(
			array(
				'usedCssDelivery'  => 'inline',
				'preloadCssFirstN' => 3,
			)
		);
		$this->assertSame( 'inline', $sanitized['usedCssDelivery'] );
		$this->assertSame( 3, $sanitized['preloadCssFirstN'] );

		$fallback = Settings_Store::sanitize_settings_recursively(
			array(
				'usedCssDelivery'  => 'bogus',
				'preloadCssFirstN' => 99,
			)
		);
		$this->assertSame( 'file', $fallback['usedCssDelivery'] );
		$this->assertSame( 5, $fallback['preloadCssFirstN'] );
	}

	/**
	 * Runtime defaults include the new keys with fail-open values.
	 */
	public function test_defaults_include_new_keys(): void {
		$defaults = Settings_Store::get_default_settings();
		$this->assertSame( 'file', $defaults['file_optimisation']['usedCssDelivery'] );
		$this->assertSame( 0, $defaults['preload_settings']['preloadCssFirstN'] );
		// Existing defaults are unchanged (byte-identical current behaviour).
		$this->assertSame( 'file', $defaults['file_optimisation']['usedCSSDeliveryMode'] );
	}

	/**
	 * Staleness info exposes the delivery target without dropping the mode.
	 */
	public function test_staleness_info_exposes_delivery_target(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $key, $fallback = false ) {
				if ( Used_CSS::LAST_FULL_REGEN_OPTION === $key || Used_CSS::TARGETED_REGEN_OPTION === $key ) {
					return 0;
				}
				return $fallback;
			}
		);
		Functions\when( 'has_filter' )->justReturn( false );
		Util::set_settings_cache(
			array(
				'file_optimisation' => array(
					'removeUnusedCSS' => true,
					'usedCssDelivery' => 'inline',
				),
			)
		);

		$info = Used_CSS::get_staleness_info();
		$this->assertSame( 'inline', $info['delivery_target'] );
		$this->assertSame( 'file', $info['delivery_mode'] );
	}
}
