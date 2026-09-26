<?php
/**
 * Tests for one-click safe mode with auto-exclude detector (issue #1465).
 *
 * Covers: detector suggests fragile handles (jQuery, cart fragments,
 * builders first); safe-mode stack state disables the stack in one click;
 * one-click payload preserves settings; upgrade fixture never auto-enables
 * combine (defaults false + sanitizer fail-safe false).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * One-click safe-mode tests.
 *
 * @package PerformanceOptimise\Tests
 */
class SafeModeOneClickTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Detector names the exact fragile handle for jQuery/cart/builder fixtures.
	 */
	public function test_detector_suggests_fragile_handles(): void {
		Functions\when( 'has_filter' )->justReturn( false );

		$suggestions = Main::detect_fragile_handles(
			array( 'jquery-core', 'wc-cart-fragments', 'elementor-frontend', 'my-theme-script' )
		);

		$handles = array_column( $suggestions, 'handle' );
		$this->assertContains( 'jquery-core', $handles );
		$this->assertContains( 'wc-cart-fragments', $handles );
		$this->assertContains( 'elementor-frontend', $handles );
		$this->assertNotContains( 'my-theme-script', $handles );
		foreach ( $suggestions as $suggestion ) {
			$this->assertNotEmpty( $suggestion['fields'] );
			$this->assertContains( 'excludeDeferJS', $suggestion['fields'] );
		}
	}

	/**
	 * Detector is case-insensitive and fail-open on junk input.
	 */
	public function test_detector_case_insensitive_and_fail_open(): void {
		Functions\when( 'has_filter' )->justReturn( false );

		$suggestions = Main::detect_fragile_handles( array( 'JQuery', 'KADENCE-BLOCKS-IMAGE' ) );
		$this->assertNotEmpty( $suggestions );

		$this->assertSame( array(), Main::detect_fragile_handles( array() ) );
		$this->assertSame( array(), Main::detect_fragile_handles( array( new \stdClass() ) ) );
	}

	/**
	 * Stack state reports enabled only when the stack is on and safe mode is off.
	 */
	public function test_stack_state_single_action_disables_stack(): void {
		Functions\when( 'has_filter' )->justReturn( false );

		$on = Main::get_safe_mode_stack_state(
			array(
				'delayJS'         => true,
				'deferJS'         => true,
				'combineCSS'      => true,
				'removeUnusedCSS' => true,
				'safeMode'        => false,
			)
		);
		$this->assertTrue( $on['stack_enabled'] );

		$safe = Main::get_safe_mode_stack_state(
			array(
				'delayJS'         => true,
				'deferJS'         => true,
				'combineCSS'      => true,
				'removeUnusedCSS' => true,
				'safeMode'        => true,
			)
		);
		$this->assertTrue( $safe['safe_mode'] );
		$this->assertFalse( $safe['stack_enabled'] );
	}

	/**
	 * One-click payload forces safeMode on while preserving every setting.
	 */
	public function test_one_click_payload_preserves_settings(): void {
		$payload = Main::build_safe_mode_enable_payload(
			array(
				'delayJS'    => true,
				'deferJS'    => true,
				'combineCSS' => true,
				'safeMode'   => false,
			)
		);
		$this->assertTrue( $payload['safeMode'] );
		$this->assertTrue( $payload['delayJS'] );
		$this->assertTrue( $payload['deferJS'] );
		$this->assertTrue( $payload['combineCSS'] );
	}

	/**
	 * Upgrade fixture: combine stays off unless explicitly enabled.
	 */
	public function test_upgrade_never_auto_enables_combine(): void {
		$defaults = Util::get_default_settings();
		$this->assertFalse( $defaults['file_optimisation']['combineCSS'] );
		$this->assertFalse( $defaults['file_optimisation']['safeMode'] );

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();

		$sanitized = Util::sanitize_settings_recursively(
			array(
				'combineCSS' => 'false',
				'safeMode'   => 'false',
			)
		);
		$this->assertFalse( $sanitized['combineCSS'] );
		$this->assertFalse( $sanitized['safeMode'] );

		$garbage = Util::sanitize_settings_recursively(
			array(
				'combineCSS' => 'not-a-bool-shape-xyz',
			)
		);
		$this->assertFalse( $garbage['combineCSS'] );
	}
}
