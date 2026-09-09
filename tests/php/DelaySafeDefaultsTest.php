<?php
/**
 * Tests for the Delay JS + Remove-Unused-CSS safe defaults (issue #966 review).
 *
 * Covers the review follow-ups: builder-handle variant exclusion on the
 * external delay path, whitespace-anchored src detection for
 * external-only mode, used-CSS safelist prefix/attribute matching,
 * regression-guard trip boundaries + threshold clamp, and the per-page
 * delay kill-switch.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Used_CSS;
use Brain\Monkey\Functions;

/**
 * Tests for the #966 safe-default branches.
 *
 * @package PerformanceOptimise\Tests
 */
class DelaySafeDefaultsTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Build a Used_CSS instance without constructor, with given options/safelist.
	 *
	 * @param array $options  wppo_settings options.
	 * @param array $safelist Safelist entries.
	 * @return Used_CSS
	 */
	private function make_used_css( array $options, array $safelist ): Used_CSS {
		$instance = ( new \ReflectionClass( Used_CSS::class ) )->newInstanceWithoutConstructor();
		$prop     = new \ReflectionProperty( Used_CSS::class, 'options' );
		$prop->setAccessible( true );
		$prop->setValue( $instance, $options );
		$safe_prop = new \ReflectionProperty( Used_CSS::class, 'safelist' );
		$safe_prop->setAccessible( true );
		$safe_prop->setValue( $instance, $safelist );
		return $instance;
	}

	/**
	 * Default safelist under test (mirrors the built-in builder entries).
	 *
	 * @return array
	 */
	private function default_safelist(): array {
		return array(
			'.elementor-',
			'.e-con',
			'.e-con*',
			'.et_*',
			'.et_pb_*',
			'.et-pb-',
			'.vc_*',
			'.wpb_*',
			'.oxygen-',
			'[data-elementor-type]',
		);
	}

	/**
	 * Empty used-selector map for safelist-only assertions.
	 *
	 * @return array
	 */
	private function empty_used(): array {
		return array(
			'tags'    => array(),
			'classes' => array(),
			'ids'     => array(),
			'attrs'   => array(),
		);
	}

	/**
	 * Underscore/wildcard builder prefixes must keep real-world selectors.
	 */
	public function test_safelist_keeps_builder_prefixed_selectors(): void {
		$css = $this->make_used_css( array(), $this->default_safelist() );

		$this->assertTrue( $css->is_selector_used( '.et_pb_text', $this->empty_used() ) );
		$this->assertTrue( $css->is_selector_used( '.et_pb_text_0', $this->empty_used() ) );
		$this->assertTrue( $css->is_selector_used( '.vc_row', $this->empty_used() ) );
		$this->assertTrue( $css->is_selector_used( '.wpb_button', $this->empty_used() ) );
		$this->assertTrue( $css->is_selector_used( '.e-con-boxed', $this->empty_used() ) );
		$this->assertTrue( $css->is_selector_used( '.e-con-full', $this->empty_used() ) );
		$this->assertTrue( $css->is_selector_used( '.e-con', $this->empty_used() ) );
	}

	/**
	 * Attribute safelist must keep compound selectors, not just the exact string.
	 */
	public function test_safelist_keeps_compound_attribute_selectors(): void {
		$css = $this->make_used_css( array(), $this->default_safelist() );

		$this->assertTrue( $css->is_selector_used( '[data-elementor-type]', $this->empty_used() ) );
		$this->assertTrue( $css->is_selector_used( 'div[data-elementor-type]', $this->empty_used() ) );
		$this->assertTrue( $css->is_selector_used( '[data-elementor-type="page"]', $this->empty_used() ) );
	}

	/**
	 * Unrelated selectors must still purge.
	 */
	public function test_safelist_does_not_keep_unrelated_selectors(): void {
		$css = $this->make_used_css( array(), $this->default_safelist() );

		$this->assertFalse( $css->is_selector_used( '.totally-unrelated-widget', $this->empty_used() ) );
	}

	/**
	 * Regression guard backfills to enabled with a 20% default threshold.
	 */
	public function test_regression_guard_defaults_enabled_at_twenty(): void {
		$css = $this->make_used_css( array( 'file_optimisation' => array() ), array() );

		$this->assertTrue( $css->is_regression_guard_enabled() );
		$this->assertSame( 20, $css->get_regression_threshold() );
	}

	/**
	 * Out-of-range thresholds fail safe to 20.
	 */
	public function test_regression_threshold_clamps_out_of_range(): void {
		Functions\when( 'has_filter' )->justReturn( false );

		$low = $this->make_used_css( array( 'file_optimisation' => array( 'unusedCSSRegressionThreshold' => 4 ) ), array() );
		$this->assertSame( 20, $low->get_regression_threshold() );

		$high = $this->make_used_css( array( 'file_optimisation' => array( 'unusedCSSRegressionThreshold' => 51 ) ), array() );
		$this->assertSame( 20, $high->get_regression_threshold() );

		$valid = $this->make_used_css( array( 'file_optimisation' => array( 'unusedCSSRegressionThreshold' => 30 ) ), array() );
		$this->assertSame( 30, $valid->get_regression_threshold() );
	}

	/**
	 * Guard trips below the threshold and passes above it.
	 */
	public function test_regression_guard_trip_boundaries(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		$css = $this->make_used_css( array( 'file_optimisation' => array( 'unusedCSSRegressionThreshold' => 20 ) ), array() );

		$combined = str_repeat( 'a', 1000 );
		// 10% retained < 20% threshold: trips.
		$this->assertTrue( $css->is_regression_guard_tripped( $combined, str_repeat( 'b', 100 ) ) );
		// 50% retained > 20% threshold: passes.
		$this->assertFalse( $css->is_regression_guard_tripped( $combined, str_repeat( 'b', 500 ) ) );
		// Empty input never trips.
		$this->assertFalse( $css->is_regression_guard_tripped( '', '' ) );
		// Large input purged to nearly nothing trips regardless of ratio.
		$this->assertTrue( $css->is_regression_guard_tripped( str_repeat( 'a', 11000 ), str_repeat( 'b', 500 ) ) );
	}

	/**
	 * Disabled guard never trips.
	 */
	public function test_regression_guard_disabled_never_trips(): void {
		$css = $this->make_used_css( array( 'file_optimisation' => array( 'unusedCSSRegressionGuard' => false ) ), array() );

		$this->assertFalse( $css->is_regression_guard_enabled() );
		$this->assertFalse( $css->is_regression_guard_tripped( str_repeat( 'a', 1000 ), '' ) );
	}

	/**
	 * Builder exclusion list covers the headline runtimes.
	 */
	public function test_builder_exclusions_cover_runtimes(): void {
		$list = Main::get_delay_js_builder_exclusions();

		$this->assertContains( 'elementor-frontend', $list );
		$this->assertContains( 'oxygen', $list );
		$this->assertContains( 'wp-interactivity', $list );
	}

	/**
	 * Kill-switch returns true when the meta is set, false otherwise.
	 *
	 * Distinct post IDs per branch: is_delay_disabled_for_page() caches
	 * per post ID for the request.
	 */
	public function test_delay_kill_switch_meta_branches(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_post_meta' )->alias(
			static function ( $post_id, $key ) {
				if ( 9101 === $post_id && '_wppo_delay_disabled' === $key ) {
					return '1';
				}
				return '';
			}
		);

		$this->assertTrue( Main::is_delay_disabled_for_page( 9101 ) );
		$this->assertFalse( Main::is_delay_disabled_for_page( 9102 ) );
	}

	/**
	 * Stub the WP environment needed to construct Main with the given
	 * file_optimisation overrides (mirrors MainDelayDeferTest).
	 *
	 * @param array $file_overrides file_optimisation option overrides.
	 * @return void
	 */
	private function stub_main_construction( array $file_overrides ): void {
		Functions\stubs(
			array(
				'WP_Filesystem'       => false,
				'sanitize_text_field' => '',
				'wp_unslash'          => '',
				'is_user_logged_in'   => false,
			)
		);

		Functions\when( 'absint' )->alias(
			static function ( $maybeint ) {
				return (int) $maybeint;
			}
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
		Functions\when( 'wp_is_block_theme' )->justReturn( false );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );

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
	 * Reset the request superglobals read by is_delay_excluded_context().
	 *
	 * @return void
	 */
	private function reset_delay_guard_superglobals(): void {
		unset( $_SERVER['REQUEST_URI'], $_SERVER['QUERY_STRING'] );
		foreach ( array( 'elementor-preview', 'et_fb', 'et_pb_preview', 'vc_action', 'vc_editable', 'bricks', 'wc-ajax', 'add-to-cart' ) as $key ) {
			unset( $_GET[ $key ] );
		}
	}

	/**
	 * Builder-handle variants stay un-delayed on the external-script path,
	 * matching the inline path, while unrelated handles still delay.
	 */
	public function test_external_delay_excludes_builder_handle_variants(): void {
		$this->stub_main_construction( array( 'delayJS' => true ) );
		$this->reset_delay_guard_superglobals();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$main = new Main();

		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for add_defer_attribute() tests.
		$tag = '<script src="https://example.com/app.js" type="text/javascript"></script>';
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript

		// Separator variants of curated entries stay excluded.
		$this->assertSame( $tag, $main->add_defer_attribute( $tag, 'oxygen-custom-widget' ) );
		$this->assertSame( $tag, $main->add_defer_attribute( $tag, 'vc_tta-custom' ) );
		// Pure-prefix entry covers the Divi underscore family.
		$this->assertSame( $tag, $main->add_defer_attribute( $tag, 'et_core_api_shortcodes' ) );
		// Unrelated handles still delay.
		$this->assertStringContainsString( 'wppo-src', $main->add_defer_attribute( $tag, 'my-app-script' ) );

		$this->reset_delay_guard_superglobals();
	}

	/**
	 * External-only mode delays real src scripts but leaves data-src
	 * (src-less) scripts untouched.
	 */
	public function test_external_only_mode_src_detection(): void {
		$this->stub_main_construction(
			array(
				'delayJS'             => true,
				'delayJSExternalOnly' => true,
			)
		);
		$this->reset_delay_guard_superglobals();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$main = new Main();

		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for add_defer_attribute() tests.
		$external = '<script src="https://example.com/app.js" type="text/javascript"></script>';
		$data_src = '<script data-src="https://example.com/app.js" type="text/javascript"></script>';
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript

		$this->assertStringContainsString( 'wppo-src', $main->add_defer_attribute( $external, 'my-app-script' ) );
		$this->assertSame( $data_src, $main->add_defer_attribute( $data_src, 'my-app-script' ) );

		$this->reset_delay_guard_superglobals();
	}

	/**
	 * The cached per-page flag short-circuits delay without a meta lookup.
	 */
	public function test_cached_delay_disabled_flag_skips_rewrite(): void {
		$this->stub_main_construction( array( 'delayJS' => true ) );
		$this->reset_delay_guard_superglobals();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$main = new Main();
		$prop = new \ReflectionProperty( Main::class, 'delay_disabled_for_page' );
		$prop->setAccessible( true );
		$prop->setValue( $main, true );

		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for add_defer_attribute() tests.
		$tag = '<script src="https://example.com/app.js" type="text/javascript"></script>';
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript

		$this->assertSame( $tag, $main->add_defer_attribute( $tag, 'my-app-script' ) );

		$this->reset_delay_guard_superglobals();
	}
}
