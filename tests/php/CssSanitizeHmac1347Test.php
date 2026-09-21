<?php
/**
 * Tests for issue #1347: CSS ingest sanitization + HMAC callback auth.
 *
 * Covers Util::sanitize_css_for_storage() (script-capable constructs
 * stripped, benign lookalikes preserved), Util::css_within_storage_bounds()
 * (size/charset gates), the callback-secret sign/verify round-trip
 * (mismatch + tampering rejected), and Used_CSS::sanitize_used_css_output()
 * + generate_used_css() poison handling.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Used_CSS;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * CSS sanitize + HMAC tests for issue #1347.
 */
class CssSanitizeHmac1347Test extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Option store backing the get_option/add_option/update_option stubs.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Original global $wpdb before it is swapped for the test fake.
	 *
	 * @var object
	 */
	private $original_wpdb;

	/**
	 * Swap in a fake $wpdb so Log::add() can run, then seed stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		parent::setUp();
		global $wpdb;
		$this->original_wpdb = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wpdb                = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Record an insert into the activity log table.
			 *
			 * @param string $table  Table name.
			 * @param array  $data   Data to insert.
			 * @param array  $format Format array.
			 * @return int
			 */
			public function insert( $table, $data, $format = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
				return 1;
			}
		};
	}

	/**
	 * Restore the original $wpdb.
	 *
	 * @return void
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		global $wpdb;
		$wpdb = $this->original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tearDown();
	}

	/**
	 * Seed the WP function stubs used across these tests.
	 *
	 * @return void
	 */
	private function install_stubs(): void {
		Functions\stubs(
			array(
				'get_option',
				'add_option',
				'update_option',
				'get_transient',
				'set_transient',
				'delete_transient',
				'wp_generate_password',
				'wp_json_encode',
				'is_multisite',
				'get_current_blog_id',
				'wp_kses_post',
				'wp_normalize_path',
				'sanitize_text_field',
				'wp_unslash',
				'sanitize_key',
				'wp_using_ext_object_cache',
				'__',
			)
		);
		$this->options = array();
		Util::reset_callback_secret_memo();
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_generate_password' )->alias(
			function ( $length = 64 ) {
				return str_repeat( 's', (int) $length );
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( '__' )->alias(
			static function ( $text ) {
				return $text;
			}
		);
		Functions\when( 'wp_normalize_path' )->alias(
			static function ( $path ) {
				return str_replace( '\\', '/', (string) $path );
			}
		);
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
	}

	/**
	 * Benign CSS passes through unchanged.
	 *
	 * @return void
	 */
	public function test_benign_css_passes_through(): void {
		$css    = '.a{color:red;scroll-behavior:smooth}.behavior-badge{display:block}';
		$result = Util::sanitize_css_for_storage( $css );
		$this->assertSame( $css, $result );
		$this->assertTrue( Util::css_within_storage_bounds( $css ) );
	}

	/**
	 * Expression/javascript/vbscript vectors are neutralized.
	 *
	 * @return void
	 */
	public function test_expression_and_scheme_vectors_neutralized(): void {
		$result = Util::sanitize_css_for_storage( '.x{width:expression(alert(1))}' );
		$this->assertStringNotContainsString( 'expression(', $result );
		$result = Util::sanitize_css_for_storage( '.x{background:url(javascript:alert(1))}' );
		$this->assertStringNotContainsString( 'javascript:', $result );
		$result = Util::sanitize_css_for_storage( '.x{background:url(vbscript:msgbox(1))}' );
		$this->assertStringNotContainsString( 'vbscript:', $result );
	}

	/**
	 * Style-element breakout tokens are neutralized.
	 *
	 * @return void
	 */
	public function test_breakout_tokens_neutralized(): void {
		$result = Util::sanitize_css_for_storage( '.a{color:red}</style><script>alert(1)</script>' );
		$this->assertStringNotContainsString( '</style', $result );
		$this->assertStringNotContainsString( '<script', $result );
		$this->assertStringContainsString( '.a', $result );
	}

	/**
	 * Encoded payloads cannot smuggle angle brackets past the encoder.
	 *
	 * @return void
	 */
	public function test_encoded_payloads_neutralized(): void {
		$result = Util::sanitize_css_for_storage( '.a{content:"&#60;script&#62;"}' );
		$this->assertStringNotContainsString( '<script', $result );
		$this->assertStringNotContainsString( '&#60;', $result );
	}

	/**
	 * Whitespace-prefixed event-handler shapes are broken; attribute
	 * selectors are preserved.
	 *
	 * @return void
	 */
	public function test_event_handler_shapes_broken(): void {
		$result = Util::sanitize_css_for_storage( '.a{color:red} onload=alert(1)' );
		$this->assertStringNotContainsString( ' onload=', $result );
		$attr = '.a[onload="x"]{color:red}';
		$this->assertSame( $attr, Util::sanitize_css_for_storage( $attr ) );
	}

	/**
	 * Oversized payloads are refused.
	 *
	 * @return void
	 */
	public function test_oversized_payload_refused(): void {
		$big = str_repeat( 'a{}', 400000 );
		$this->assertFalse( Util::css_within_storage_bounds( $big ) );
		$this->assertSame( '', Used_CSS::sanitize_used_css_output( $big ) );
		$this->assertTrue( Util::css_within_storage_bounds( '.a{}' ) );
	}

	/**
	 * Empty input stays empty.
	 *
	 * @return void
	 */
	public function test_empty_input_returns_empty(): void {
		$this->assertSame( '', Util::sanitize_css_for_storage( '' ) );
		$this->assertFalse( Util::css_within_storage_bounds( '' ) );
		$this->assertSame( '', Used_CSS::sanitize_used_css_output( '' ) );
	}

	/**
	 * HMAC sign/verify round-trips; tampering and empty sigs fail.
	 *
	 * @return void
	 */
	public function test_hmac_round_trip_and_mismatch(): void {
		$this->install_stubs();
		if ( ! function_exists( 'hash_hmac' ) ) {
			$this->markTestSkipped( 'hash_hmac unavailable.' );
		}
		$payload = array( 'post_id' => 42 );
		$sig     = Util::sign_callback_payload( $payload );
		$this->assertNotSame( '', $sig );
		$this->assertTrue( Util::verify_callback_signature( $payload, $sig ) );
		$this->assertFalse( Util::verify_callback_signature( array( 'post_id' => 43 ), $sig ) );
		$this->assertFalse( Util::verify_callback_signature( $payload, 'deadbeef' ) );
		$this->assertFalse( Util::verify_callback_signature( $payload, '' ) );
	}

	/**
	 * The secret persists in its own option and signs deterministically.
	 *
	 * @return void
	 */
	public function test_secret_persists_and_signs_deterministically(): void {
		$this->install_stubs();
		if ( ! function_exists( 'hash_hmac' ) ) {
			$this->markTestSkipped( 'hash_hmac unavailable.' );
		}
		$payload = array( 'template_hash' => 'abc123' );
		$first   = Util::sign_callback_payload( $payload );
		$second  = Util::sign_callback_payload( $payload );
		$this->assertSame( $first, $second );
		$this->assertArrayHasKey( Util::option_key( Util::CALLBACK_SECRET_OPTION ), $this->options );
	}

	/**
	 * Poisoned combined CSS is cleaned by generate_used_css() on every
	 * path (purged output and degraded fallback alike).
	 *
	 * @return void
	 */
	public function test_generate_used_css_cleans_poisoned_fallback(): void {
		$this->install_stubs();
		$used_css = new Used_CSS( array( 'sentinel' => true ) );
		$result   = $used_css->generate_used_css( '<html></html>', array( 'h1' => '.a{color:red}</style><script>alert(1)</script>' ) );
		$this->assertStringNotContainsString( '</style', $result );
		$this->assertStringNotContainsString( '<script', $result );
		$this->assertStringContainsString( '.a', $result );
	}

	/**
	 * The uninstall constant covers the callback secret.
	 *
	 * @return void
	 */
	public function test_uninstall_options_cover_callback_secret(): void {
		$this->assertContains( Util::CALLBACK_SECRET_OPTION, Util::UNINSTALL_OPTIONS );
	}
}
