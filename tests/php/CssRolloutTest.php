<?php
/**
 * Tests for safe CSS/JS rollout with rollback and status (issue #1348).
 *
 * Covers the Css_Rollout state machine (dry-run preview, health gate,
 * hit-reason logging, multisite slot isolation), the additive Util
 * settings defaults + sanitizer, and the Used_CSS sidecar path helpers.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Css_Rollout;
use PerformanceOptimise\Inc\Used_CSS;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;


/**
 * CSS rollout tests.
 */
class CssRolloutTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap {
		setUp as private trait_set_up;
		tearDown as private trait_tear_down;
	}

	/**
	 * In-memory transient store backing the transient stubs.
	 *
	 * @var array
	 */
	private array $transients = array();

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$this->trait_set_up();
		$this->transients = array();
		if ( class_exists( 'PerformanceOptimise\Inc\Log' ) && method_exists( 'PerformanceOptimise\Inc\Log', 'reset_version_memo' ) ) {
			\PerformanceOptimise\Inc\Log::reset_version_memo();
		}
		$transients = &$this->transients;
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$transients ) {
				return array_key_exists( $key, $transients ) ? $transients[ $key ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$transients ) {
				$transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( $key ) use ( &$transients ) {
				unset( $transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
	}

	/**
	 * Tear down test fixtures.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		if ( class_exists( 'PerformanceOptimise\Inc\Log' ) && method_exists( 'PerformanceOptimise\Inc\Log', 'reset_version_memo' ) ) {
			\PerformanceOptimise\Inc\Log::reset_version_memo();
		}
		$this->trait_tear_down();
	}

	/**
	 * Empty payloads fail the content health check with a miss reason.
	 */
	public function test_health_check_rejects_empty_payload(): void {
		$result = Css_Rollout::health_check_content( '   ' );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'empty', $result['reason'] );
		$this->assertStringContainsString( 'miss', $result['hit'] );
	}

	/**
	 * Unsafe breakout tokens fail the health check with a bypass reason.
	 */
	public function test_health_check_rejects_unsafe_tokens(): void {
		$result = Css_Rollout::health_check_content( '.a{color:red}</style><script>alert(1)</script>' );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'bypass', $result['hit'] );
	}

	/**
	 * Healthy CSS passes the health check.
	 */
	public function test_health_check_accepts_healthy_css(): void {
		$result = Css_Rollout::health_check_content( '.hero{color:#fff;margin:0}' );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'hit', $result['hit'] );
	}

	/**
	 * Missing live files probe as 404 misses (fail-open, never fatal).
	 */
	public function test_probe_missing_file_is_404_miss(): void {
		$result = Css_Rollout::probe_live_file( '/nonexistent/wppo-1348-missing.css' );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( '404', $result['reason'] );
	}

	/**
	 * Preview snippets truncate at the 5000-char budget.
	 */
	public function test_build_preview_truncates_snippet(): void {
		$staged  = str_repeat( '.a{color:red}', 1000 );
		$preview = Css_Rollout::build_preview( '.a{color:blue}', $staged );
		$this->assertTrue( $preview['truncated'] );
		$this->assertLessThanOrEqual( Css_Rollout::PREVIEW_SNIPPET_MAX, strlen( $preview['snippet'] ) );
		$this->assertSame( strlen( $staged ) - strlen( '.a{color:blue}' ), $preview['delta_bytes'] );
		$this->assertNotSame( '', $preview['staged_sha'] );
	}

	/**
	 * Dry-run stages without promoting: state records staged + hit reason.
	 */
	public function test_record_and_get_staged_state(): void {
		$stored = Css_Rollout::record_event( 'abc123', 'staged', 'dry-run staged 100 bytes', 'bypass (staged preview)' );
		$this->assertSame( 'staged', $stored['state'] );
		$this->assertSame( 1, $stored['version'] );
		$read = Css_Rollout::get_state( 'abc123' );
		$this->assertSame( 'staged', $read['state'] );
		$this->assertSame( 'bypass (staged preview)', $read['hit'] );
	}

	/**
	 * A 404 triggers the rolled_back state with a last-good hit reason.
	 */
	public function test_rollback_state_logged_with_hit_reason(): void {
		Css_Rollout::record_event( 'abc123', 'staged', 'dry-run', 'bypass (staged preview)' );
		$stored = Css_Rollout::record_event( 'abc123', 'rolled_back', 'auto-rollback: missing live file (404)', 'hit (last-good restored)' );
		$this->assertSame( 'rolled_back', $stored['state'] );
		$this->assertSame( 2, $stored['version'] );
		$read = Css_Rollout::get_state( 'abc123' );
		$this->assertSame( 'hit (last-good restored)', $read['hit'] );
	}

	/**
	 * Slots are multisite-isolated via blog-aware transient keys.
	 */
	public function test_multisite_slot_isolation(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 7 );
		$key_site_7 = Css_Rollout::state_key( 'abc123' );
		$this->assertStringStartsWith( '7_', $key_site_7 );
		Functions\when( 'get_current_blog_id' )->justReturn( 9 );
		$key_site_9 = Css_Rollout::state_key( 'abc123' );
		$this->assertStringStartsWith( '9_', $key_site_9 );
		$this->assertNotSame( $key_site_7, $key_site_9 );
	}

	/**
	 * Hostile slot identifiers are refused.
	 */
	public function test_normalize_slot_rejects_traversal(): void {
		$this->assertSame( '', Css_Rollout::normalize_slot( '../../etc' ) );
		$this->assertSame( '', Css_Rollout::normalize_slot( '' ) );
		$this->assertSame( 'abc-123_XYZ', Css_Rollout::normalize_slot( 'abc-123_XYZ' ) );
	}

	/**
	 * Rollout mode sanitizes to the direct/staged allowlist.
	 */
	public function test_sanitize_mode_allowlist(): void {
		$this->assertSame( 'staged', Css_Rollout::sanitize_mode( 'staged' ) );
		$this->assertSame( 'direct', Css_Rollout::sanitize_mode( 'banana' ) );
		$this->assertSame( 'direct', Css_Rollout::sanitize_mode( '' ) );
	}

	/**
	 * Non-scalar mode input fails open to direct without a warning (issue #1348 review).
	 */
	public function test_sanitize_mode_rejects_array_fail_open(): void {
		$this->assertSame( 'direct', Css_Rollout::sanitize_mode( array( 'staged' ) ) );
		$this->assertSame( 'direct', Css_Rollout::sanitize_mode( null ) );
	}

	/**
	 * Strict-gate parity: scroll-behavior is healthy, encoded breakouts fail (issue #1348 review).
	 */
	public function test_health_check_strict_gate_parity(): void {
		$this->assertTrue( Css_Rollout::health_check_content( '.a{scroll-behavior:smooth}' )['ok'] );
		$this->assertFalse( Css_Rollout::health_check_content( '.a{color:red}&#60;/style>' )['ok'] );
		$this->assertFalse( Css_Rollout::health_check_content( '.a{x:expression (alert(1))}' )['ok'] );
	}

	/**
	 * Background workers skip the loopback HTTP leg via $check_http=false (issue #1348 review).
	 */
	public function test_probe_skips_http_for_background_workers(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'wppo-probe-' );
		$this->assertNotFalse( $tmp );
		file_put_contents( $tmp, '.a{color:red}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture write.
		$result = Css_Rollout::probe_live_file( $tmp, 'http://127.0.0.1:9/unreachable.css', false );
		$this->assertTrue( $result['ok'] );
		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
	}

	/**
	 * Additive Util defaults preserve backward compatibility.
	 */
	public function test_util_defaults_additive(): void {
		$defaults = Util::get_default_settings();
		$this->assertSame( 'direct', $defaults['file_optimisation']['cssRolloutMode'] );
		$this->assertTrue( $defaults['file_optimisation']['cssRolloutHealthCheck'] );
		$this->assertTrue( $defaults['file_optimisation']['cssRolloutKeepLastGood'] );
	}

	/**
	 * Sanitizer normalizes the new rollout keys.
	 */
	public function test_util_sanitizer_rollout_keys(): void {
		$clean = Util::sanitize_settings_recursively(
			array(
				'cssRolloutMode'         => 'STAGED',
				'cssRolloutHealthCheck'  => 'false',
				'cssRolloutKeepLastGood' => 'yes',
				'cssRolloutModeBad'      => 'staged',
			)
		);
		$this->assertSame( 'staged', $clean['cssRolloutMode'] );
		$this->assertFalse( $clean['cssRolloutHealthCheck'] );
		$this->assertTrue( $clean['cssRolloutKeepLastGood'] );
		$rogue = Util::sanitize_settings_recursively( array( 'cssRolloutMode' => 'banana' ) );
		$this->assertSame( 'direct', $rogue['cssRolloutMode'] );
	}

	/**
	 * Used-CSS sidecar helpers map the canonical filename only.
	 */
	public function test_used_css_sidecar_paths(): void {
		$this->assertSame( '/tmp/used-css.staged.css', Used_CSS::get_staged_path_for( '/tmp/used-css.css' ) );
		$this->assertSame( '/tmp/used-css.last-good.css', Used_CSS::get_last_good_path_for( '/tmp/used-css.css' ) );
		$this->assertSame( '', Used_CSS::get_staged_path_for( '/tmp/other.css' ) );
		$this->assertSame( '', Used_CSS::get_last_good_path_for( '/tmp/evil/../other.css' ) );
	}

	/**
	 * Used-CSS rollout slots are bounded word-char identifiers.
	 */
	public function test_used_css_rollout_slot_bounded(): void {
		$slot = Used_CSS::rollout_slot_for_url( 'http://example.com/some/page/' );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', $slot );
		$this->assertSame( 'used-css-global', Used_CSS::rollout_slot_for_url( '' ) );
	}

	/**
	 * Health check is case-insensitive without allocating a copy (issue #1348 review).
	 */
	public function test_health_check_rejects_uppercase_tokens(): void {
		$result = Css_Rollout::health_check_content( '.a{color:red}</STYLE>' );
		$this->assertFalse( $result['ok'] );
		$result = Css_Rollout::health_check_content( '.a{background:JAVASCRIPT:alert(1)}' );
		$this->assertFalse( $result['ok'] );
	}

	/**
	 * Post-promote health gate keeps parity with the strict pre-cache gate (issue #1348 review).
	 */
	public function test_health_check_rejects_strict_gate_tokens(): void {
		$this->assertFalse( Css_Rollout::health_check_content( '.a{background:url(data:text/html;base64,xxx)}' )['ok'] );
		$this->assertFalse( Css_Rollout::health_check_content( '.a{background:url(data:image/svg+xml;utf8,xxx)}' )['ok'] );
		$this->assertFalse( Css_Rollout::health_check_content( '.a{-moz-binding:url(x)}' )['ok'] );
		$this->assertFalse( Css_Rollout::health_check_content( '.a<!--comment' )['ok'] );
	}

	/**
	 * Rollout state TTL stays short-lived so per-page transients cannot
	 * accumulate rows in wp_options without a persistent object cache (issue #1348 review).
	 */
	public function test_state_ttl_is_one_day(): void {
		$this->assertSame( 86400, Css_Rollout::STATE_TTL );
	}

	/**
	 * Stage -> preview -> promote -> verify state machine transitions (issue #1348 review).
	 */
	public function test_state_machine_stage_promote_verify(): void {
		$slot = 'statemachine1';
		Css_Rollout::clear_state( $slot );
		$this->assertSame( 'none', Css_Rollout::get_state( $slot )['state'] );

		$live   = '.hero{color:#fff}';
		$staged = '.hero{color:#000;margin:0}';
		Css_Rollout::record_event( $slot, 'staged', 'dry-run staged', 'bypass (staged preview)' );
		$this->assertSame( 'staged', Css_Rollout::get_state( $slot )['state'] );

		$preview = Css_Rollout::build_preview( $live, $staged );
		$this->assertSame( strlen( $live ), $preview['live_size'] );
		$this->assertSame( strlen( $staged ), $preview['staged_size'] );
		$this->assertSame( strlen( $staged ) - strlen( $live ), $preview['delta_bytes'] );

		$healthy = Css_Rollout::health_check_content( $staged );
		$this->assertTrue( $healthy['ok'] );
		Css_Rollout::record_event( $slot, 'done', 'staged output promoted to live', 'hit (promoted)' );
		$this->assertSame( 'done', Css_Rollout::get_state( $slot )['state'] );
		$this->assertSame( 2, Css_Rollout::get_state( $slot )['version'] );

		// No-backup fail-open: a rolled_back event is recordable and readable.
		Css_Rollout::record_event( $slot, 'rolled_back', 'auto-rollback: empty live payload', 'bypass (unoptimized)' );
		$this->assertSame( 'rolled_back', Css_Rollout::get_state( $slot )['state'] );
		Css_Rollout::clear_state( $slot );
		$this->assertSame( 'none', Css_Rollout::get_state( $slot )['state'] );
	}
}
