<?php
/**
 * Tests for the sandbox preview controller (issue #1163).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Sandbox_Preview;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Sandbox preview tests.
 */
class SandboxPreviewTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap {
		setUp as private trait_set_up;
		tearDown as private trait_tear_down;
	}

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$this->trait_set_up();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'has_filter' )->justReturn( false );
		Sandbox_Preview::reset_memo();
		unset( $_GET[ Sandbox_Preview::QUERY_VAR ], $_GET[ Sandbox_Preview::NONCE_VAR ] );
	}

	/**
	 * Tear down test fixtures.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		Sandbox_Preview::reset_memo();
		unset( $_GET[ Sandbox_Preview::QUERY_VAR ], $_GET[ Sandbox_Preview::NONCE_VAR ] );
		$this->trait_tear_down();
	}

	/**
	 * Test visitor without param never sees preview.
	 */
	public function test_visitor_without_param_never_sees_preview(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertFalse( Sandbox_Preview::is_preview_request() );
		$production = array( 'delayJS' => false );
		$this->assertSame( $production, Sandbox_Preview::get_effective_file_optimisation( $production ) );
	}

	/**
	 * Test admin with valid param and nonce gets preview.
	 */
	public function test_admin_with_valid_param_and_nonce_gets_preview(): void {
		$_GET[ Sandbox_Preview::QUERY_VAR ] = 'assets';
		$_GET[ Sandbox_Preview::NONCE_VAR ] = 'valid-nonce';
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$this->assertTrue( Sandbox_Preview::is_preview_request() );
	}

	/**
	 * Test non admin with param gets production.
	 */
	public function test_non_admin_with_param_gets_production(): void {
		$_GET[ Sandbox_Preview::QUERY_VAR ] = 'assets';
		$_GET[ Sandbox_Preview::NONCE_VAR ] = 'valid-nonce';
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$this->assertFalse( Sandbox_Preview::is_preview_request() );
	}

	/**
	 * Test bad nonce gets production.
	 */
	public function test_bad_nonce_gets_production(): void {
		$_GET[ Sandbox_Preview::QUERY_VAR ] = 'assets';
		$_GET[ Sandbox_Preview::NONCE_VAR ] = 'bad';
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$this->assertFalse( Sandbox_Preview::is_preview_request() );
	}

	/**
	 * Test preview gate rejects a wrong query value.
	 */
	public function test_preview_gate_rejects_wrong_query_value(): void {
		$_GET[ Sandbox_Preview::QUERY_VAR ] = 'other';
		$_GET[ Sandbox_Preview::NONCE_VAR ] = 'valid-nonce';
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$this->assertFalse( Sandbox_Preview::is_preview_request() );
	}

	/**
	 * Test preview gate rejects a missing nonce.
	 */
	public function test_preview_gate_rejects_missing_nonce(): void {
		$_GET[ Sandbox_Preview::QUERY_VAR ] = 'assets';
		unset( $_GET[ Sandbox_Preview::NONCE_VAR ] );
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->assertFalse( Sandbox_Preview::is_preview_request() );
	}

	/**
	 * Test save/promote/discard require manage_options and never write
	 * without the capability.
	 */
	public function test_save_promote_discard_require_manage_options(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'update_option' )->never();

		$this->assertFalse( Sandbox_Preview::save_staged( array( 'delayJS' => true ) ) );
		$this->assertFalse( Sandbox_Preview::promote_staged() );
		$this->assertFalse( Sandbox_Preview::discard_staged() );
	}

	/**
	 * Test effective overlay applies staged only in preview.
	 */
	public function test_effective_overlay_applies_staged_only_in_preview(): void {
		$stored = array(
			'file_optimisation' => array(
				'delayJS'       => false,
				'deferJS'       => false,
				'sandboxStaged' => array(
					'delayJS' => true,
					'deferJS' => true,
				),
			),
		);
		Functions\when( 'get_option' )->justReturn( $stored );
		Util::clear_settings_cache();
		// Prime the Util memo via get_settings (uses get_option stub above).
		$settings = Util::get_settings();
		$this->assertNotEmpty( $settings );

		// Visitor: production unchanged.
		Functions\when( 'current_user_can' )->justReturn( false );
		Sandbox_Preview::reset_memo();
		$effective = Sandbox_Preview::get_effective_file_optimisation(
			array(
				'delayJS' => false,
				'deferJS' => false,
			)
		);
		$this->assertFalse( ! empty( $effective['delayJS'] ) );

		// Admin preview: staged overlays production.
		$_GET[ Sandbox_Preview::QUERY_VAR ] = 'assets';
		$_GET[ Sandbox_Preview::NONCE_VAR ] = 'valid-nonce';
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		// current_user_can already stubbed false; re-stub to true.
		Functions\when( 'current_user_can' )->justReturn( true );
		Sandbox_Preview::reset_memo();
		$effective = Sandbox_Preview::get_effective_file_optimisation(
			array(
				'delayJS' => false,
				'deferJS' => false,
			)
		);
		$this->assertTrue( ! empty( $effective['delayJS'] ) );
		$this->assertTrue( ! empty( $effective['deferJS'] ) );
	}

	/**
	 * Test save_staged persists allowlisted keys only.
	 */
	public function test_save_staged_persists_allowlisted_keys(): void {
		$stored = array(
			'file_optimisation' => array(
				'delayJS'       => false,
				'sandboxStaged' => array(),
			),
		);
		Functions\when( 'get_option' )->justReturn( $stored );
		$saved = null;
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$saved ) {
				if ( 'wppo_settings' === $key ) {
					$saved = $value;
				}
				return true;
			}
		);
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertTrue(
			Sandbox_Preview::save_staged(
				array(
					'delayJS' => true,
					'evil'    => 'x',
				)
			)
		);
		$this->assertTrue( ! empty( $saved['file_optimisation']['sandboxStaged']['delayJS'] ) );
		$this->assertArrayNotHasKey( 'evil', $saved['file_optimisation']['sandboxStaged'] );
	}

	/**
	 * Test promote_staged copies staged into production and clears staged.
	 */
	public function test_promote_staged_copies_to_production_and_clears(): void {
		$saved = null;
		Functions\when( 'get_option' )->justReturn(
			array(
				'file_optimisation' => array(
					'delayJS'       => false,
					'sandboxStaged' => array( 'delayJS' => true ),
				),
			)
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$saved ) {
				// Snapshot writes use a different option key; only track wppo_settings.
				if ( 'wppo_settings' === $key ) {
					$saved = $value;
				}
				return true;
			}
		);
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertTrue( Sandbox_Preview::promote_staged() );
		$this->assertTrue( ! empty( $saved['file_optimisation']['delayJS'] ) );
		$this->assertSame( array(), $saved['file_optimisation']['sandboxStaged'] );
	}

	/**
	 * Test discard_staged clears the staged slot.
	 */
	public function test_discard_staged_clears_staged_slot(): void {
		$saved = null;
		Functions\when( 'get_option' )->justReturn(
			array(
				'file_optimisation' => array(
					'delayJS'       => true,
					'sandboxStaged' => array( 'delayJS' => true ),
				),
			)
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$saved ) {
				if ( 'wppo_settings' === $key ) {
					$saved = $value;
				}
				return true;
			}
		);
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertTrue( Sandbox_Preview::discard_staged() );
		$this->assertSame( array(), $saved['file_optimisation']['sandboxStaged'] );
		// Production values survive the discard.
		$this->assertTrue( ! empty( $saved['file_optimisation']['delayJS'] ) );
	}

	/**
	 * Test promote_staged reports a real write failure.
	 */
	public function test_promote_staged_reports_write_failure(): void {
		$stored = array(
			'file_optimisation' => array(
				'delayJS'       => false,
				'sandboxStaged' => array( 'delayJS' => true ),
			),
		);
		Functions\when( 'get_option' )->justReturn( $stored );
		Functions\when( 'update_option' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertFalse( Sandbox_Preview::promote_staged() );
	}

	/**
	 * Test discard_staged reports a real write failure.
	 */
	public function test_discard_staged_reports_write_failure(): void {
		$stored = array(
			'file_optimisation' => array(
				'delayJS'       => true,
				'sandboxStaged' => array( 'delayJS' => true ),
			),
		);
		Functions\when( 'get_option' )->justReturn( $stored );
		Functions\when( 'update_option' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertFalse( Sandbox_Preview::discard_staged() );
	}

	/**
	 * Test discard_staged with no stored row is an idempotent success.
	 */
	public function test_discard_staged_without_stored_row_is_idempotent_success(): void {
		Functions\when( 'get_option' )->justReturn( false );
		Functions\expect( 'update_option' )->never();
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertTrue( Sandbox_Preview::discard_staged() );
	}

	/**
	 * Test sanitize staged keeps allowlist only.
	 */
	public function test_sanitize_staged_keeps_allowlist_only(): void {
		$clean = Sandbox_Preview::sanitize_staged(
			array(
				'delayJS' => true,
				'notAKey' => 'x',
			)
		);
		$this->assertArrayHasKey( 'delayJS', $clean );
		$this->assertArrayNotHasKey( 'notAKey', $clean );
	}

	/**
	 * Test minify keys have no staged preview path and are dropped.
	 */
	public function test_sanitize_staged_drops_minify_without_preview_path(): void {
		$clean = Sandbox_Preview::sanitize_staged(
			array(
				'delayJS'   => true,
				'minifyJS'  => true,
				'minifyCSS' => true,
			)
		);
		$this->assertArrayHasKey( 'delayJS', $clean );
		$this->assertArrayNotHasKey( 'minifyJS', $clean );
		$this->assertArrayNotHasKey( 'minifyCSS', $clean );
	}

	/**
	 * Test sanitize_staged normalizes string booleans fail-safe.
	 */
	public function test_sanitize_staged_normalizes_string_booleans(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		$clean = Sandbox_Preview::sanitize_staged(
			array(
				'delayJS'    => 'false',
				'deferJS'    => 'true',
				'combineCSS' => 'yes',
			)
		);
		$this->assertFalse( $clean['delayJS'] );
		$this->assertTrue( $clean['deferJS'] );
		$this->assertTrue( $clean['combineCSS'] );
	}

	/**
	 * Test save_staged reports a real write failure instead of always true.
	 */
	public function test_save_staged_reports_write_failure(): void {
		$stored = array(
			'file_optimisation' => array(
				'delayJS'       => false,
				'sandboxStaged' => array(),
			),
		);
		Functions\when( 'get_option' )->justReturn( $stored );
		Functions\when( 'update_option' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertFalse( Sandbox_Preview::save_staged( array( 'delayJS' => true ) ) );
	}

	/**
	 * Test save_staged treats unchanged stored values as success.
	 */
	public function test_save_staged_no_change_counts_as_success(): void {
		$clean  = Sandbox_Preview::sanitize_staged( array( 'delayJS' => true ) );
		$stored = array(
			'file_optimisation' => array(
				'delayJS'       => false,
				'sandboxStaged' => $clean,
			),
		);
		Functions\when( 'get_option' )->justReturn( $stored );
		Functions\when( 'update_option' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertTrue( Sandbox_Preview::save_staged( array( 'delayJS' => true ) ) );
	}

	/**
	 * Test promote_staged with an empty staged slot returns false.
	 */
	public function test_promote_staged_with_empty_staged_returns_false(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'file_optimisation' => array(
					'delayJS'       => false,
					'sandboxStaged' => array(),
				),
			)
		);
		Functions\expect( 'update_option' )->never();
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertFalse( Sandbox_Preview::promote_staged() );
	}

	/**
	 * Test preview url contains nonce.
	 */
	public function test_preview_url_contains_nonce(): void {
		Functions\when( 'home_url' )->justReturn( 'http://example.com/' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'abc123' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		$url = Sandbox_Preview::get_preview_url( 'http://example.com/hello/' );
		$this->assertStringContainsString( 'wppo_preview=assets', $url );
		$this->assertStringContainsString( '_wppo_preview_nonce=abc123', $url );
	}
}
