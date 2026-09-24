<?php
/**
 * Contract tests for the bounded preload transport adapter.
 *
 * @package PerformanceOptimise\Tests
 */

// The isolated test double is intentionally co-located with the contract suite.
// phpcs:disable Generic.Files.OneObjectStructurePerFile
// phpcs:disable WordPress.Files.FileName

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Preload_Transport;

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Isolated WP_Error test double.
	 */
	class PreloadTransportTest_Error {
		/**
		 * Error code.
		 *
		 * @var string
		 */
		public $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		public $message;

		/**
		 * Construct a test error.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 */
		public function __construct( string $code = 'error', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		/**
		 * Return the error message.
		 *
		 * @return string
		 */
		public function get_error_message(): string {
			return $this->message;
		}
	}
	class_alias( 'PreloadTransportTest_Error', 'WP_Error' );
}

/**
 * Preload transport contract tests.
 *
 * @package PerformanceOptimise\Tests
 */
class PreloadTransportTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Install shared URL/HTTP stubs.
	 *
	 * @return void
	 */
	private function install_stubs(): void {
		Functions\when( 'esc_url_raw' )->returnArg();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_http_validate_url' )->justReturn( true );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ): int {
				return (int) ( $response['response']['code'] ?? 0 );
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			static function ( $value ): bool {
				return $value instanceof WP_Error;
			}
		);
	}

	/**
	 * A direct same-host request disables redirect following.
	 *
	 * @return void
	 */
	public function test_direct_request_disables_redirect_following(): void {
		$this->install_stubs();
		$seen = array();
		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url, $args ) use ( &$seen ) {
				$seen[] = array( $url, $args );
				return array( 'response' => array( 'code' => 304 ) );
			}
		);

		$result = Preload_Transport::get( 'https://example.com/about/', 7 );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $seen );
		$this->assertSame( 'https://example.com/about/', $seen[0][0] );
		$this->assertSame( 7, $seen[0][1]['timeout'] );
		$this->assertSame( 0, $seen[0][1]['redirection'] );
	}

	/**
	 * Off-host, unsafe-port, and validator-rejected targets never reach HTTP.
	 *
	 * @return void
	 */
	public function test_unsafe_targets_are_rejected_before_transport(): void {
		$this->install_stubs();
		Functions\expect( 'wp_remote_get' )->never();

		$this->assertFalse( Preload_Transport::is_allowed_url( 'https://evil.example/about/' ) );
		$this->assertFalse( Preload_Transport::is_allowed_url( 'https://example.com:8443/about/' ) );
		Functions\when( 'wp_http_validate_url' )->justReturn( false );
		$this->assertFalse( Preload_Transport::is_allowed_url( 'https://example.com/private/' ) );
	}

	/**
	 * A same-host relative redirect is bounded and followed explicitly.
	 *
	 * @return void
	 */
	public function test_same_host_redirect_is_bounded_and_followed(): void {
		$this->install_stubs();
		$responses = array(
			array(
				'response' => array( 'code' => 302 ),
				'headers'  => array( 'location' => '/final/' ),
			),
			array( 'response' => array( 'code' => 200 ) ),
		);
		$requested = array();
		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url, $args ) use ( &$responses, &$requested ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- HTTP API shape.
				$requested[] = $url;
				return array_shift( $responses );
			}
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			static function ( $response, $name ) {
				return 'location' === $name ? ( $response['headers']['location'] ?? '' ) : '';
			}
		);

		$result = Preload_Transport::get( 'https://example.com/start/', 5, 2 );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'https://example.com/start/', 'https://example.com/final/' ), $requested );
	}

	/**
	 * Off-host redirects and redirect loops fail closed without extra requests.
	 *
	 * @return void
	 */
	public function test_unsafe_and_looping_redirects_fail_closed(): void {
		$this->install_stubs();
		$redirect = array(
			'response' => array( 'code' => 302 ),
			'headers'  => array( 'location' => 'https://evil.example/' ),
		);
		Functions\when( 'wp_remote_get' )->justReturn( $redirect );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( 'https://evil.example/' );

		$result = Preload_Transport::get( 'https://example.com/start/', 5, 2 );
		$this->assertInstanceOf( WP_Error::class, $result );

		$loop = array(
			'response' => array( 'code' => 302 ),
			'headers'  => array( 'location' => 'https://example.com/loop/' ),
		);
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( 'https://example.com/loop/' );
		$count = 0;
		Functions\when( 'wp_remote_get' )->alias(
			static function () use ( &$loop, &$count ) {
				++$count;
				return $loop;
			}
		);
		$result = Preload_Transport::get( 'https://example.com/loop/', 5, 1 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 2, $count );
	}
}
