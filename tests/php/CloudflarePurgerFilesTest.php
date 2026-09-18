<?php
/**
 * Direct tests for Cloudflare_Purger::purge_files().
 *
 * Covers empty/blank URL filtering, WP_Error and non-2xx false paths,
 * the 'cloudflare-edge' log-tag passthrough with the Edge prefix, and the
 * wp_json_encode-failure short-circuit (no HTTP issued).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cloudflare_Purger;
use Brain\Monkey\Functions;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Test fixtures are co-located by convention.
// phpcs:disable WordPress.Files.FileName -- Declares a uniquely-named WP_Error stand-in for instanceof checks in error-path tests.

if ( ! class_exists( 'CloudflarePurgerFilesWPError' ) ) {
	/**
	 * Minimal WP_Error stand-in for tests that exercise plugin error paths.
	 *
	 * Uniquely named (cf. TelemetryTest's WP_Error) so parallel test files
	 * never collide in one PHPUnit process.
	 *
	 * @package PerformanceOptimise\Tests
	 */
	class CloudflarePurgerFilesWPError {

		/**
		 * Error codes mapped to messages.
		 *
		 * @var array<string, string>
		 */
		public array $errors = array();

		/**
		 * Constructor.
		 *
		 * @param string|int $code    Error code.
		 * @param string     $message Error message.
		 */
		public function __construct( $code = '', $message = '' ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
			if ( '' !== $code ) {
				$this->errors[ (string) $code ] = $message;
			}
		}

		/**
		 * First error message, or empty string.
		 *
		 * @return string
		 */
		public function get_error_message() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
			return (string) reset( $this->errors );
		}
	}
}

/**
 * Tests for the canonical Cloudflare file-purge transport.
 */
class CloudflarePurgerFilesTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Requests recorded by the stubbed wp_remote_request().
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * Debug-log messages recorded by the stubbed do_action().
	 *
	 * @var array
	 */
	private $logged = array();

	/**
	 * Next wp_remote_request() response (or WP_Error).
	 *
	 * @var mixed
	 */
	private $next_response;

	/**
	 * Next wp_json_encode() result override (null = real json_encode).
	 *
	 * @var mixed
	 */
	private $json_override_set = false;

	/**
	 * Next wp_json_encode() result.
	 *
	 * @var mixed
	 */
	private $json_override;

	/**
	 * Install HTTP + logging stubs.
	 */
	private function install_stubs(): void {
		$this->requests          = array();
		$this->logged            = array();
		$this->next_response     = array( 'response' => array( 'code' => 200 ) );
		$this->json_override_set = false;
		$this->json_override     = null;

		Functions\stubs(
			array(
				'wp_remote_request',
				'wp_remote_retrieve_response_code',
				'is_wp_error',
				'wp_json_encode',
				'do_action',
			)
		);
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args = array() ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return $this->next_response;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \CloudflarePurgerFilesWPError;
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			function ( $data ) {
				if ( $this->json_override_set ) {
					return $this->json_override;
				}
				return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		Functions\when( 'do_action' )->alias(
			function ( $hook, $message = null ) {
				if ( 'wppo_debug_log' === $hook ) {
					$this->logged[] = $message;
				}
			}
		);
	}

	/**
	 * Test that a successful purge sends the {files: [...]} body.
	 */
	public function test_purge_files_sends_files_body(): void {
		$this->install_stubs();

		$result = Cloudflare_Purger::purge_files( 'z123', 'tok', array( 'https://example.com/a/' ) );

		$this->assertTrue( $result );
		$this->assertCount( 1, $this->requests );
		$this->assertSame(
			'https://api.cloudflare.com/client/v4/zones/z123/purge_cache',
			$this->requests[0]['url']
		);
		$this->assertSame( 'Bearer tok', $this->requests[0]['args']['headers']['Authorization'] );
		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertSame( array( 'https://example.com/a/' ), $body['files'] );
	}

	/**
	 * Test that blank and non-string URLs are filtered, and all-blank is a no-op.
	 */
	public function test_purge_files_filters_blank_urls(): void {
		$this->install_stubs();

		$result = Cloudflare_Purger::purge_files(
			'z123',
			'tok',
			array( '', 'https://example.com/keep/', 123, null )
		);

		$this->assertTrue( $result );
		$this->assertCount( 1, $this->requests );
		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertSame( array( 'https://example.com/keep/' ), $body['files'] );
	}

	/**
	 * Test that empty or all-blank URL lists short-circuit without HTTP (with a logged skip).
	 */
	public function test_purge_files_empty_list_skips_http(): void {
		$this->install_stubs();

		$this->assertFalse( Cloudflare_Purger::purge_files( 'z123', 'tok', array() ) );
		$this->assertFalse( Cloudflare_Purger::purge_files( 'z123', 'tok', array( '', 42, null ) ) );
		$this->assertFalse( Cloudflare_Purger::purge_files( '', 'tok', array( 'https://example.com/a/' ) ) );
		$this->assertFalse( Cloudflare_Purger::purge_files( 'z123', '', array( 'https://example.com/a/' ) ) );
		$this->assertCount( 0, $this->requests );
		$this->assertCount( 4, $this->logged );
		$this->assertStringContainsString( 'skipped', $this->logged[0] );
	}

	/**
	 * Test that a WP_Error response returns false and is logged.
	 */
	public function test_purge_files_wp_error_returns_false(): void {
		$this->install_stubs();
		$this->next_response = new \CloudflarePurgerFilesWPError( 'http_request_failed', 'Connection timed out' );

		$result = Cloudflare_Purger::purge_files( 'z123', 'tok', array( 'https://example.com/a/' ) );

		$this->assertFalse( $result );
		$this->assertCount( 1, $this->requests );
		$this->assertCount( 1, $this->logged );
		$this->assertStringContainsString( 'CDN purge failed [cloudflare]', $this->logged[0] );
	}

	/**
	 * Test that a non-2xx response returns false and is logged.
	 */
	public function test_purge_files_non_2xx_returns_false(): void {
		$this->install_stubs();
		$this->next_response = array( 'response' => array( 'code' => 500 ) );

		$result = Cloudflare_Purger::purge_files( 'z123', 'tok', array( 'https://example.com/a/' ) );

		$this->assertFalse( $result );
		$this->assertCount( 1, $this->logged );
		$this->assertStringContainsString( 'HTTP 500', $this->logged[0] );
	}

	/**
	 * Test that the edge log tag and Edge prefix pass through.
	 */
	public function test_purge_files_edge_log_tag_passthrough(): void {
		$this->install_stubs();
		$this->next_response = array( 'response' => array( 'code' => 403 ) );

		$result = Cloudflare_Purger::purge_files(
			'z123',
			'tok',
			array( 'https://example.com/a/' ),
			'cloudflare-edge',
			'Edge purge failed'
		);

		$this->assertFalse( $result );
		$this->assertCount( 1, $this->logged );
		$this->assertStringContainsString( 'Edge purge failed [cloudflare-edge]', $this->logged[0] );
	}

	/**
	 * Test that a JSON-encoding failure short-circuits without HTTP.
	 */
	public function test_purge_files_json_encode_failure_skips_http(): void {
		$this->install_stubs();
		$this->json_override_set = true;
		$this->json_override     = false;

		$result = Cloudflare_Purger::purge_files( 'z123', 'tok', array( 'https://example.com/a/' ) );

		$this->assertFalse( $result );
		$this->assertCount( 0, $this->requests );
		$this->assertCount( 1, $this->logged );
		$this->assertStringContainsString( 'JSON encoding failed', $this->logged[0] );
	}
}
