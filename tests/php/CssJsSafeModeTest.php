<?php
/**
 * Tests for breakage-free CSS/JS Safe Mode (issue #1404).
 *
 * Covers Safe Mode defaults (absent key = enabled), builder
 * auto-exclusion preset, effective combine exclusions, offender
 * isolation persistence per URL with a 3-purge cap, the WPPO_SAFE_MODE
 * bypass constant path, and sandbox staging of minify keys.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Sandbox_Preview;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * CSS/JS Safe Mode tests.
 */
class CssJsSafeModeTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Set up Brain Monkey stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Sandbox_Preview::reset_memo();
	}

	/**
	 * Tear down Brain Monkey.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		Sandbox_Preview::reset_memo();
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Defaults carry Safe Mode on with an empty offender map.
	 */
	public function test_default_settings_enable_css_js_safe_mode(): void {
		$defaults = Util::get_default_settings();
		$this->assertTrue( ! empty( $defaults['file_optimisation']['cssJsSafeMode'] ) );
		$this->assertSame( array(), $defaults['file_optimisation']['combineOffenders'] );
	}

	/**
	 * Absent key reads as enabled (pre-migration fail-open).
	 */
	public function test_safe_mode_absent_key_is_enabled(): void {
		$this->assertTrue( Main::is_css_js_safe_mode_active( array() ) );
		$this->assertTrue( Main::is_css_js_safe_mode_active( array( 'combineCSS' => true ) ) );
		$this->assertFalse( Main::is_css_js_safe_mode_active( array( 'cssJsSafeMode' => false ) ) );
	}

	/**
	 * Preset covers Elementor, Salient/WPBakery, Woo fragments, jquery-core.
	 */
	public function test_preset_covers_builder_handles(): void {
		$preset = Main::get_safe_mode_combine_exclusions();
		foreach ( array( 'elementor-frontend', 'salient-child-style', 'js_composer_front', 'wc-cart-fragments', 'jquery-core' ) as $handle ) {
			$this->assertContains( $handle, $preset );
		}
	}

	/**
	 * Effective exclusions merge user list + preset when Safe Mode is on.
	 */
	public function test_effective_exclusions_merge_preset_when_on(): void {
		$effective = Main::get_effective_combine_exclusions(
			array(
				'cssJsSafeMode'     => true,
				'excludeCombineCSS' => 'custom-handle',
			),
			'https://example.com/'
		);
		$this->assertContains( 'custom-handle', $effective );
		$this->assertContains( 'elementor-frontend', $effective );
	}

	/**
	 * Effective exclusions skip the preset when Safe Mode is off.
	 */
	public function test_effective_exclusions_skip_preset_when_off(): void {
		$effective = Main::get_effective_combine_exclusions(
			array(
				'cssJsSafeMode'     => false,
				'excludeCombineCSS' => 'custom-handle',
			),
			'https://example.com/'
		);
		$this->assertContains( 'custom-handle', $effective );
		$this->assertNotContains( 'elementor-frontend', $effective );
	}

	/**
	 * Bisect halves the suspect set so isolation converges quickly.
	 */
	public function test_bisect_halves_handles(): void {
		$suspects = Main::bisect_combine_handles( array( 'a', 'b', 'c', 'd' ) );
		$this->assertSame( array( 'a', 'b' ), $suspects );
		$this->assertSame( array( 'solo' ), Main::bisect_combine_handles( array( 'solo' ) ) );
	}

	/**
	 * Offender isolation persists per URL and caps at 3 purges.
	 */
	public function test_record_offender_persists_per_url_with_cap(): void {
		$stored = array( 'file_optimisation' => array( 'combineOffenders' => array() ) );
		Functions\when( 'get_option' )->justReturn( $stored );
		$saved = null;
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$saved ) {
				$saved = $value;
				return true;
			}
		);
		// Util::save_settings() wraps update_option; stub get_settings path
		// via get_option above is enough because Util memo is cleared.
		Util::clear_settings_cache();
		$this->assertTrue( Main::record_combine_offender( 'https://example.com/page/', 'bad-handle' ) );
		$this->assertNotEmpty( $saved );
		$this->assertArrayHasKey( 'file_optimisation', $saved );
		$map = $saved['file_optimisation']['combineOffenders'];
		$key = md5( Main::normalize_combine_offender_url( 'https://example.com/page/' ) );
		$this->assertArrayHasKey( $key, $map );
		$this->assertContains( 'bad-handle', $map[ $key ]['handles'] );
		$this->assertSame( 1, $map[ $key ]['purges'] );
	}

	/**
	 * URL normalization shares one map entry across slash/case/fragment variants.
	 */
	public function test_normalize_offender_url_dedupes_variants(): void {
		$canonical = Main::normalize_combine_offender_url( 'https://example.com/page/' );
		$this->assertSame( $canonical, Main::normalize_combine_offender_url( 'https://example.com/page' ) );
		$this->assertSame( $canonical, Main::normalize_combine_offender_url( 'https://example.com/page#frag' ) );
		$this->assertSame( $canonical, Main::normalize_combine_offender_url( 'HTTPS://EXAMPLE.COM/page/' ) );
		$this->assertSame( md5( $canonical ), md5( Main::normalize_combine_offender_url( 'https://example.com/page' ) ) );
	}

	/**
	 * Offender map never exceeds 50 URL entries (oldest-first eviction).
	 */
	public function test_record_offender_caps_map_at_fifty_entries(): void {
		$map = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$k         = md5( Main::normalize_combine_offender_url( 'https://example.com/p' . $i . '/' ) );
			$map[ $k ] = array(
				'url'     => Main::normalize_combine_offender_url( 'https://example.com/p' . $i . '/' ),
				'handles' => array( 'h' . $i ),
				'purges'  => 1,
			);
		}
		$stored = array( 'file_optimisation' => array( 'combineOffenders' => $map ) );
		Functions\when( 'get_option' )->justReturn( $stored );
		$saved = null;
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$saved ) {
				$saved = $value;
				return true;
			}
		);
		Util::clear_settings_cache();
		$this->assertTrue( Main::record_combine_offender( 'https://example.com/brand-new/', 'new-handle' ) );
		$this->assertCount( 50, $saved['file_optimisation']['combineOffenders'] );
		$new_key = md5( Main::normalize_combine_offender_url( 'https://example.com/brand-new/' ) );
		$this->assertArrayHasKey( $new_key, $saved['file_optimisation']['combineOffenders'] );
	}

	/**
	 * Record hands back the post-write slice (no second read).
	 */
	public function test_record_offender_returns_fresh_slice(): void {
		$stored = array( 'file_optimisation' => array( 'combineOffenders' => array() ) );
		Functions\when( 'get_option' )->justReturn( $stored );
		Functions\when( 'update_option' )->justReturn( true );
		Util::clear_settings_cache();
		$fresh = null;
		$this->assertTrue( Main::record_combine_offender( 'https://example.com/slice/', 'slice-handle', $fresh ) );
		$this->assertIsArray( $fresh );
		$this->assertSame( array( 'slice-handle' ), Main::get_combine_offenders_for_url( 'https://example.com/slice/', $fresh ) );
	}

	/**
	 * Failed save clears the fresh slice (no masked success).
	 */
	public function test_record_offender_save_failure_clears_fresh_slice(): void {
		$stored = array( 'file_optimisation' => array( 'combineOffenders' => array() ) );
		Functions\when( 'get_option' )->justReturn( $stored );
		Functions\when( 'update_option' )->justReturn( false );
		Util::clear_settings_cache();
		$fresh = array( 'sentinel' => true );
		$this->assertFalse( Main::record_combine_offender( 'https://example.com/nosave/', 'lost-handle', $fresh ) );
		$this->assertNull( $fresh );
	}

	/**
	 * Handles canonicalize to lowercase so REST + direct callers converge.
	 */
	public function test_record_offender_normalizes_handle_case(): void {
		$stored = array( 'file_optimisation' => array( 'combineOffenders' => array() ) );
		Functions\when( 'get_option' )->justReturn( $stored );
		$saved = null;
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$saved ) {
				$saved = $value;
				return true;
			}
		);
		Util::clear_settings_cache();
		$this->assertTrue( Main::record_combine_offender( 'https://example.com/case/', 'Mixed-Case_HANDLE' ) );
		$key = md5( Main::normalize_combine_offender_url( 'https://example.com/case/' ) );
		$this->assertSame( array( 'mixed-case_handle' ), $saved['file_optimisation']['combineOffenders'][ $key ]['handles'] );
	}

	/**
	 * Malformed staged cssJsSafeMode fail-safes to true (matches Util).
	 */
	public function test_sandbox_malformed_safe_mode_fails_safe_true(): void {
		$clean = Sandbox_Preview::sanitize_staged( array( 'cssJsSafeMode' => 'not-a-bool!!!' ) );
		$this->assertTrue( $clean['cssJsSafeMode'] );
	}

	/**
	 * Sandbox allowlist stages minify + Safe Mode keys for preview.
	 */
	public function test_sandbox_stages_minify_and_safe_mode_keys(): void {
		$this->assertContains( 'minifyJS', Sandbox_Preview::ALLOWED_STAGED_KEYS );
		$this->assertContains( 'minifyCSS', Sandbox_Preview::ALLOWED_STAGED_KEYS );
		$this->assertContains( 'excludeCSS', Sandbox_Preview::ALLOWED_STAGED_KEYS );
		$this->assertContains( 'excludeJS', Sandbox_Preview::ALLOWED_STAGED_KEYS );
		$this->assertContains( 'cssJsSafeMode', Sandbox_Preview::ALLOWED_STAGED_KEYS );
		$clean = Sandbox_Preview::sanitize_staged(
			array(
				'minifyJS'      => 'true',
				'minifyCSS'     => 'false',
				'cssJsSafeMode' => 'true',
				'not_allowed'   => 'x',
			)
		);
		$this->assertTrue( $clean['minifyJS'] );
		$this->assertFalse( $clean['minifyCSS'] );
		$this->assertTrue( $clean['cssJsSafeMode'] );
		$this->assertArrayNotHasKey( 'not_allowed', $clean );
	}

	/**
	 * Sandbox discard clears staged values (identical-HTML restore path).
	 */
	public function test_sandbox_discard_clears_staged(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$stored = array( 'file_optimisation' => array( 'sandboxStaged' => array( 'minifyCSS' => true ) ) );
		Functions\when( 'get_option' )->justReturn( $stored );
		$saved = null;
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$saved ) {
				$saved = $value;
				return true;
			}
		);
		Util::clear_settings_cache();
		$this->assertTrue( Sandbox_Preview::discard_staged() );
		$this->assertSame( array(), $saved['file_optimisation']['sandboxStaged'] );
	}
}
