<?php
/**
 * Tests for used-CSS delivery modes, staleness, and targeted regen (issue #1220).
 *
 * Covers the additive usedCSSDeliveryMode allowlist (fail-open to file),
 * the remove-mode auto-downgrade to delay on builder pages, the read-only
 * staleness signal, and the cooldown-gated targeted requeue (fail-open to 0).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Used_CSS;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests for Used_CSS delivery modes / staleness / targeted regen.
 *
 * @package PerformanceOptimise\Tests
 */
class UsedCssDeliveryModes1220Test extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Reset the Util settings memo between tests.
	 *
	 * Note: this tearDown shadows the trait's tearDown, so it replicates the
	 * Brain Monkey teardown (see UsedCssHostTest) plus the Main singleton
	 * reset to avoid cross-test pollution.
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
	 * Delivery mode defaults to file and allowlists known modes.
	 */
	public function test_delivery_mode_allowlist(): void {
		$this->assertSame( 'file', Used_CSS::get_used_css_delivery_mode( array() ) );
		$this->assertSame( 'delay', Used_CSS::get_used_css_delivery_mode( array( 'usedCSSDeliveryMode' => 'delay' ) ) );
		$this->assertSame( 'async', Used_CSS::get_used_css_delivery_mode( array( 'usedCSSDeliveryMode' => 'async' ) ) );
		$this->assertSame( 'remove', Used_CSS::get_used_css_delivery_mode( array( 'usedCSSDeliveryMode' => 'remove' ) ) );
		$this->assertSame( 'file', Used_CSS::get_used_css_delivery_mode( array( 'usedCSSDeliveryMode' => 'bogus' ) ) );
		$this->assertSame( 'file', Used_CSS::get_used_css_delivery_mode( array( 'usedCSSDeliveryMode' => '' ) ) );
	}

	/**
	 * Remove mode downgrades to delay on builder pages, keeps remove elsewhere.
	 */
	public function test_remove_mode_downgrades_on_builder_pages(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		$instance = ( new \ReflectionClass( Used_CSS::class ) )->newInstanceWithoutConstructor();
		$method   = new \ReflectionMethod( Used_CSS::class, 'resolve_effective_delivery_mode' );
		$method->setAccessible( true );

		$builder_buffer = '<html><body><div data-elementor-type="wp-page"><div class="elementor-widget">x</div></div></body></html>';
		$plain_buffer   = '<html><body><p>Hello</p></body></html>';

		$this->assertSame( 'delay', $method->invoke( $instance, $builder_buffer, 'remove', array( 'theme-style' ) ) );
		$this->assertSame( 'remove', $method->invoke( $instance, $plain_buffer, 'remove', array( 'theme-style' ) ) );
		$this->assertSame( 'file', $method->invoke( $instance, $builder_buffer, 'file', array( 'theme-style' ) ) );
		$this->assertSame( 'delay', $method->invoke( $instance, $builder_buffer, 'delay', array( 'theme-style' ) ) );
	}

	/**
	 * Staleness signal reports never-regenerated state when the feature is on.
	 */
	public function test_staleness_info_reports_stale_when_never_regenerated(): void {
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
					'removeUnusedCSS'     => true,
					'usedCSSDeliveryMode' => 'delay',
				),
			)
		);

		$info = Used_CSS::get_staleness_info();
		$this->assertSame( 0, $info['last_regen'] );
		$this->assertTrue( $info['is_stale'] );
		$this->assertSame( 'delay', $info['delivery_mode'] );
		$this->assertSame( 0, $info['cooldown_remaining'] );
	}

	/**
	 * Staleness signal is not stale right after a recorded regen.
	 */
	public function test_staleness_info_fresh_after_regen(): void {
		$now = time();
		Functions\when( 'get_option' )->alias(
			static function ( $key, $fallback = false ) use ( $now ) {
				if ( Used_CSS::LAST_FULL_REGEN_OPTION === $key ) {
					return $now;
				}
				if ( Used_CSS::TARGETED_REGEN_OPTION === $key ) {
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
				),
			)
		);

		$info = Used_CSS::get_staleness_info();
		$this->assertSame( $now, $info['last_regen'] );
		$this->assertFalse( $info['is_stale'] );
		$this->assertNotSame( '', $info['last_regen_human'] );
	}

	/**
	 * Targeted regen is a fail-open no-op when the feature is off.
	 */
	public function test_targeted_regen_noop_when_feature_off(): void {
		Util::set_settings_cache(
			array(
				'file_optimisation' => array(
					'removeUnusedCSS' => false,
				),
			)
		);
		$this->assertSame( 0, Used_CSS::request_targeted_regen( 'builder-update' ) );
	}

	/**
	 * Targeted regen honours the cooldown window (no flood on repeat updates).
	 */
	public function test_targeted_regen_respects_cooldown(): void {
		$now = time();
		Functions\when( 'get_option' )->alias(
			static function ( $key, $fallback = false ) use ( $now ) {
				if ( Used_CSS::TARGETED_REGEN_OPTION === $key ) {
					return $now;
				}
				return $fallback;
			}
		);
		Functions\when( 'has_filter' )->justReturn( false );
		Util::set_settings_cache(
			array(
				'file_optimisation' => array(
					'removeUnusedCSS' => true,
				),
			)
		);
		$this->assertSame( 0, Used_CSS::request_targeted_regen( 'theme-update' ) );
	}
}
