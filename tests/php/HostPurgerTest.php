<?php
/**
 * Tests for managed-host purge fan-out (issue #911).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Host_Purger;
use Brain\Monkey\Functions;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Test fixture is co-located by convention.
// phpcs:disable WordPress.Files.FileName -- Declares a uniquely-named WP_Error stand-in for the failure seam.

require_once __DIR__ . '/../../includes/Integrations/class-host-detect.php';
require_once __DIR__ . '/../../includes/Edge/class-host-purger.php';

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Test fixture is co-located by convention.
if ( ! class_exists( 'HostPurgerWPError' ) ) {
	/**
	 * Minimal WP_Error stand-in for the Varnish failure path.
	 */
	class HostPurgerWPError {
		/**
		 * Failure message.
		 *
		 * @var string
		 */
		private string $message;

		/**
		 * Build a failure response.
		 *
		 * @param string $message Failure message.
		 */
		public function __construct( string $message ) {
			$this->message = $message;
		}

		/**
		 * Return the failure message.
		 *
		 * @return string Failure message.
		 */
		public function get_error_message() {
			return $this->message;
		}
	}
}

/**
 * Host purger tests.
 */
class HostPurgerTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Host slug forced through the adapter filter.
	 *
	 * @var string|null
	 */
	private $host_override = null;

	/**
	 * Actions fired during one purge.
	 *
	 * @var string[]
	 */
	private array $fired_actions = array();

	/**
	 * Requests reaching the HTTP transport.
	 *
	 * @var array
	 */
	private array $requests = array();

	/**
	 * Response returned by the transport stub.
	 *
	 * @var mixed
	 */
	private $transport_response = array( 'response' => array( 'code' => 200 ) );

	/**
	 * Install stubs.
	 *
	 * @return void
	 */
	private function install_stubs(): void {
		$this->fired_actions      = array();
		$this->requests           = array();
		$this->transport_response = array( 'response' => array( 'code' => 200 ) );
		foreach ( array( 'KINSTA_CACHE', 'HTTP_X_KINSTA_CACHE', 'X_WPE_CACHE', 'HTTP_X_WPE_CACHE', 'HTTP_X_SG_CACHE', 'SG_CACHEPRESS' ) as $key ) {
			unset( $_SERVER[ $key ] );
		}
		\PerformanceOptimise\Inc\Host_Detect::reset_cache();

		Functions\stubs(
			array(
				'apply_filters',
				'do_action',
				'home_url',
				'wp_remote_request',
				'wp_remote_retrieve_response_code',
				'is_wp_error',
			)
		);
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				if ( 'wppo_host_adapter' === $hook && null !== $this->host_override ) {
					return $this->host_override;
				}
				return $value;
			}
		);
		Functions\when( 'do_action' )->alias(
			function ( $hook ) {
				$this->fired_actions[] = (string) $hook;
				return null;
			}
		);
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'http://example.com' . (string) $path;
			}
		);
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args = array() ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return $this->transport_response;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			static function ( $value ) {
				return $value instanceof \HostPurgerWPError;
			}
		);
	}

	/**
	 * Unknown hosts no-op with success and no transport.
	 *
	 * @return void
	 */
	public function test_unknown_host_is_successful_noop(): void {
		$this->host_override = null;
		$this->install_stubs();
		$this->assertTrue( Host_Purger::purge_all( 'all' ) );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( array(), $this->fired_actions );
	}

	/**
	 * Kinsta fans out through its host hook and reports success.
	 *
	 * @return void
	 */
	public function test_kinsta_fires_host_hook(): void {
		$this->host_override = 'kinsta';
		$this->install_stubs();
		$this->assertTrue( Host_Purger::purge_all( 'all' ) );
		$this->assertContains( 'kinsta_cache_flush_all', $this->fired_actions );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * Cloudways Varnish transport failure returns false (no false success).
	 *
	 * @return void
	 */
	public function test_cloudways_varnish_failure_returns_false(): void {
		$this->host_override = 'cloudways';
		$this->install_stubs();
		$this->transport_response = new \HostPurgerWPError( 'connection refused' );
		$this->assertFalse( Host_Purger::purge_all( 'all' ) );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'PURGE', $this->requests[0]['args']['method'] );
	}

	/**
	 * Cloudways Varnish success returns true.
	 *
	 * @return void
	 */
	public function test_cloudways_varnish_success_returns_true(): void {
		$this->host_override = 'cloudways';
		$this->install_stubs();
		$this->assertTrue( Host_Purger::purge_all( 'all' ) );
		$this->assertCount( 1, $this->requests );
	}

	/**
	 * Invalid payloads no-op with success instead of throwing.
	 *
	 * @return void
	 */
	public function test_invalid_payload_is_successful_noop(): void {
		$this->host_override = null;
		$this->install_stubs();
		$this->assertTrue( Host_Purger::purge_all( array( 'invalid' ) ) );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * Unknown purge types no-op with success and surface a debug log.
	 *
	 * @return void
	 */
	public function test_unknown_type_noops_with_debug_log(): void {
		$this->host_override = 'kinsta';
		$this->install_stubs();
		$this->assertTrue( Host_Purger::purge_all( 'bogus-type' ) );
		$this->assertSame( array(), $this->requests );
		$this->assertContains( 'wppo_debug_log', $this->fired_actions );
	}

	/**
	 * The Varnish leg uses a short blocking timeout.
	 *
	 * @return void
	 */
	public function test_cloudways_varnish_uses_short_blocking_timeout(): void {
		$this->host_override = 'cloudways';
		$this->install_stubs();
		$this->assertTrue( Host_Purger::purge_all( 'all' ) );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 3, $this->requests[0]['args']['timeout'] );
		$this->assertTrue( $this->requests[0]['args']['blocking'] );
	}
}
