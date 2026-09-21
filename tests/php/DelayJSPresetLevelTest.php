<?php
/**
 * Tests for one-click Delay-JS Safe/Balanced/Aggressive presets, labelled
 * auto third-party categories, and the commerce skip (issue #1385).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests for Delay-JS preset levels and auto labelling.
 *
 * @package PerformanceOptimise\Tests
 */
class DelayJSPresetLevelTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap {
		setUp as protected wppoSetUp;
		tearDown as protected wppoTearDown;
	}

	/**
	 * Run the shared bootstrap and reset process-wide memos.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->wppoSetUp();
		Main::reset_delay_third_party_auto_cache();
		Main::reset_delay_context_memo();
	}

	/**
	 * Clean up memos between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Main::reset_delay_third_party_auto_cache();
		Main::reset_delay_context_memo();
		$this->wppoTearDown();
	}

	/**
	 * Safe, Balanced, and Aggressive map to the existing exclusion getters.
	 */
	public function test_preset_levels_map_to_existing_getters(): void {
		Functions\when( 'has_filter' )->justReturn( false );

		$safe       = Main::get_delay_js_preset_level_exclusions( 'safe' );
		$balanced   = Main::get_delay_js_preset_level_exclusions( 'balanced' );
		$aggressive = Main::get_delay_js_preset_level_exclusions( 'aggressive' );

		// Builder plus commerce are forced ON at every level.
		foreach ( array( $safe, $balanced, $aggressive ) as $list ) {
			$this->assertContains( 'elementor-frontend', $list );
			$this->assertContains( 'wc-cart-fragments', $list );
			$this->assertContains( 'add-to-cart', $list );
		}

		// Safe delays least (superset), aggressive delays most (subset).
		foreach ( $aggressive as $entry ) {
			$this->assertContains( $entry, $safe, "Aggressive entry {$entry} must also be excluded at safe level" );
		}
		$this->assertGreaterThan( count( $aggressive ), count( $safe ), 'Safe must exclude more than aggressive' );

		// Balanced sits between: interaction exclusions on, compat presets off.
		$this->assertContains( 'mobile-menu', $balanced );
		$this->assertNotContains( 'cookieyes', $balanced );

		// Unknown levels fail safe to the safe list.
		$this->assertSame( $safe, Main::get_delay_js_preset_level_exclusions( 'nope' ) );
	}

	/**
	 * The one-click toggle map forces builder plus commerce ON.
	 */
	public function test_preset_level_settings_force_builder_and_commerce(): void {
		Functions\when( 'has_filter' )->justReturn( false );

		foreach ( array( 'safe', 'balanced', 'aggressive' ) as $level ) {
			$settings = Main::get_delay_js_preset_level_settings( $level );
			$this->assertTrue( $settings['delayJSBuilderPreset'] );
			$this->assertTrue( $settings['delayJSCommercePreset'] );
		}

		$safe = Main::get_delay_js_preset_level_settings( 'safe' );
		$this->assertTrue( $safe['delayJSInteractionPreset'] );
		$this->assertFalse( $safe['delayJSThirdPartyAuto'] );

		$aggressive = Main::get_delay_js_preset_level_settings( 'aggressive' );
		$this->assertFalse( $aggressive['delayJSInteractionPreset'] );
		$this->assertTrue( $aggressive['delayJSThirdPartyAuto'] );
	}

	/**
	 * The auto detector labels analytics, ads, and social domains.
	 */
	public function test_auto_label_covers_analytics_ads_social(): void {
		Functions\when( 'has_filter' )->justReturn( false );

		$categories = Main::get_delay_js_third_party_auto_categories();
		$this->assertArrayHasKey( 'analytics', $categories );
		$this->assertArrayHasKey( 'ads', $categories );
		$this->assertArrayHasKey( 'social', $categories );

		$this->assertSame( 'analytics', Main::get_delay_js_third_party_auto_label( 'https://www.googletagmanager.com/gtm.js' ) );
		$this->assertSame( 'ads', Main::get_delay_js_third_party_auto_label( 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js' ) );
		$this->assertSame( 'social', Main::get_delay_js_third_party_auto_label( 'https://connect.facebook.net/en_US/sdk.js' ) );

		// Flat patterns stay the merged buckets (no drift).
		$flat = Main::get_delay_js_third_party_auto_patterns();
		foreach ( array_merge( $categories['analytics'], $categories['ads'], $categories['social'] ) as $pattern ) {
			$this->assertContains( $pattern, $flat );
		}
	}

	/**
	 * WooCommerce fragments plus cart AJAX never carry a third-party label.
	 */
	public function test_auto_label_skips_commerce_fragments_and_cart_ajax(): void {
		Functions\when( 'has_filter' )->justReturn( false );

		$this->assertSame( '', Main::get_delay_js_third_party_auto_label( 'wc-cart-fragments' ) );
		$this->assertSame( '', Main::get_delay_js_third_party_auto_label( 'https://example.com/?wc-ajax=get_refreshed_fragments' ) );
		$this->assertSame( '', Main::get_delay_js_third_party_auto_label( 'my-add-to-cart-button' ) );
		$this->assertSame( '', Main::get_delay_js_third_party_auto_label( 'cart-fragments' ) );
	}

	/**
	 * The preset level key defaults to safe and sanitizes unknown values.
	 */
	public function test_preset_level_default_and_sanitizer(): void {
		$defaults = Util::get_default_settings();
		$this->assertSame( 'safe', $defaults['file_optimisation']['delayJSPreset'] );

		Functions\when( 'has_filter' )->justReturn( false );

		$clean = Util::sanitize_settings_recursively( array( 'delayJSPreset' => 'aggressive' ) );
		$this->assertSame( 'aggressive', $clean['delayJSPreset'] );

		$clean = Util::sanitize_settings_recursively( array( 'delayJSPreset' => 'nope' ) );
		$this->assertSame( 'safe', $clean['delayJSPreset'] );
	}

	/**
	 * Delay OFF keeps pages byte identical in script order.
	 */
	public function test_delay_off_is_byte_identical(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_option' )->alias(
			static function ( $option, $default_value = false ) {
				if ( 'wppo_settings' === $option && is_array( $default_value ) ) {
					$default_value['file_optimisation'] = array_merge(
						$default_value['file_optimisation'] ?? array(),
						array( 'delayJS' => false )
					);
				}
				return $default_value;
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'is_wc_endpoint_url' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'get_the_ID' )->justReturn( 0 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'has_block' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				return $value;
			}
		);

		$main = new Main();

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for byte-identical tests.
		$first = '<script src="https://example.com/a.js"></script>';
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for byte-identical tests.
		$second = '<script src="https://example.com/b.js"></script>';
		$this->assertSame( $first, $main->add_defer_attribute( $first, 'a' ) );
		$this->assertSame( $second, $main->add_defer_attribute( $second, 'b' ) );
	}
}
