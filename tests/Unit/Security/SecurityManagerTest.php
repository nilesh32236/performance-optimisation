<?php
/**
 * Tests for SecurityManager class
 *
 * @package PerformanceOptimisation\Tests\Unit\Security
 */

namespace PerformanceOptimisation\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use PerformanceOptimisation\Core\Security\SecurityManager;

/**
 * Test case for SecurityManager class.
 */
class SecurityManagerTest extends TestCase {

	/**
	 * SecurityManager instance.
	 *
	 * @var SecurityManager
	 */
	private SecurityManager $security_manager;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mockWordPressFunctions();
		$this->security_manager = new SecurityManager();
	}

	/**
	 * Test nonce verification.
	 */
	public function test_verify_nonce(): void {
		// Mock request with valid nonce
		$request = $this->createMockRequest();
		$request->method( 'get_header' )
			->with( 'X-WP-Nonce' )
			->willReturn( 'valid_nonce' );

		// Mock wp_verify_nonce to return true
		$this->mockFunction( 'wp_verify_nonce', true );

		$result = $this->security_manager->verify_nonce( $request );
		$this->assertTrue( $result );
	}

	/**
	 * Test nonce verification failure.
	 */
	public function test_verify_nonce_failure(): void {
		// Mock request with invalid nonce
		$request = $this->createMockRequest();
		$request->method( 'get_header' )
			->with( 'X-WP-Nonce' )
			->willReturn( 'invalid_nonce' );

		// Mock wp_verify_nonce to return false
		$this->mockFunction( 'wp_verify_nonce', false );

		$result = $this->security_manager->verify_nonce( $request );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'invalid_nonce', $result->get_error_code() );
	}

	/**
	 * Test missing nonce.
	 */
	public function test_missing_nonce(): void {
		// Mock request without nonce
		$request = $this->createMockRequest();
		$request->method( 'get_header' )
			->with( 'X-WP-Nonce' )
			->willReturn( null );

		$result = $this->security_manager->verify_nonce( $request );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'missing_nonce', $result->get_error_code() );
	}

	/**
	 * Test user capability check.
	 */
	public function test_check_user_capabilities(): void {
		// Mock current_user_can to return true
		$this->mockFunction( 'current_user_can', true );

		$request = $this->createMockRequest();
		$result  = $this->security_manager->check_user_capabilities( $request );
		$this->assertTrue( $result );
	}

	/**
	 * Test user capability check failure.
	 */
	public function test_check_user_capabilities_failure(): void {
		// Mock current_user_can to return false
		$this->mockFunction( 'current_user_can', false );

		$request = $this->createMockRequest();
		$result  = $this->security_manager->check_user_capabilities( $request );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'insufficient_capabilities', $result->get_error_code() );
	}

	/**
	 * Test malicious content detection.
	 */
	public function test_validate_request_data_malicious(): void {
		$request = $this->createMockRequest();
		$request->method( 'get_params' )
			->willReturn(
				array(
					'content' => '<script>alert("xss")</script>',
				)
			);

		$result = $this->security_manager->validate_request_data( $request );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'malicious_content', $result->get_error_code() );
	}

	/**
	 * Test safe content validation.
	 */
	public function test_validate_request_data_safe(): void {
		$request = $this->createMockRequest();
		$request->method( 'get_params' )
			->willReturn(
				array(
					'content' => 'This is safe content',
					'number'  => 123,
				)
			);

		$result = $this->security_manager->validate_request_data( $request );
		$this->assertTrue( $result );
	}

	/**
	 * Test rate limiting.
	 */
	public function test_check_rate_limit(): void {
		// First request should pass
		$result1 = $this->security_manager->check_rate_limit( 'test_key', 2, 3600 );
		$this->assertTrue( $result1 );

		// Second request should pass
		$result2 = $this->security_manager->check_rate_limit( 'test_key', 2, 3600 );
		$this->assertTrue( $result2 );

		// Third request should fail (limit is 2)
		$result3 = $this->security_manager->check_rate_limit( 'test_key', 2, 3600 );
		$this->assertFalse( $result3 );
	}

	/**
	 * Test IP blocking.
	 */
	public function test_ip_blocking(): void {
		$ip = '192.168.1.100';

		// IP should not be blocked initially
		$this->assertFalse( $this->security_manager->is_ip_blocked( $ip ) );

		// Block the IP
		$this->security_manager->block_ip( $ip, 3600 );

		// IP should now be blocked
		$this->assertTrue( $this->security_manager->is_ip_blocked( $ip ) );

		// Unblock the IP
		$result = $this->security_manager->unblock_ip( $ip );
		$this->assertTrue( $result );

		// IP should no longer be blocked
		$this->assertFalse( $this->security_manager->is_ip_blocked( $ip ) );
	}

	/**
	 * Test client IP detection.
	 */
	public function test_get_client_ip(): void {
		// Test with REMOTE_ADDR
		$_SERVER['REMOTE_ADDR'] = '192.168.1.1';
		$ip                     = $this->security_manager->get_client_ip();
		$this->assertEquals( '192.168.1.1', $ip );

		// Test with X-Forwarded-For
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.1, 192.168.1.1';
		$ip                              = $this->security_manager->get_client_ip();
		$this->assertEquals( '203.0.113.1', $ip );

		// Test with Cloudflare header
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.2';
		$ip                               = $this->security_manager->get_client_ip();
		$this->assertEquals( '203.0.113.2', $ip );
	}

	/**
	 * Test security logging.
	 */
	public function test_log_security_event(): void {
		$event_type = 'test_event';
		$event_data = array( 'test' => 'data' );

		// Log should be empty initially
		$log           = $this->security_manager->get_security_log( 10, 0 );
		$initial_count = $log['total'];

		// Log an event
		$this->security_manager->log_security_event( $event_type, $event_data );

		// Log should now have one more entry
		$log = $this->security_manager->get_security_log( 10, 0 );
		$this->assertEquals( $initial_count + 1, $log['total'] );

		// Check the logged entry
		$latest_entry = $log['entries'][0];
		$this->assertEquals( $event_type, $latest_entry['event_type'] );
		$this->assertEquals( $event_data, $latest_entry['data'] );
	}

	/**
	 * Test input sanitization.
	 */
	public function test_sanitize_input(): void {
		$input = array(
			'string' => '<script>alert("test")</script>',
			'number' => 123,
			'array'  => array(
				'nested' => '<b>bold</b>',
			),
		);

		$sanitized = $this->security_manager->sanitize_input( $input );

		$this->assertIsArray( $sanitized );
		$this->assertStringNotContainsString( '<script>', $sanitized['string'] );
		$this->assertEquals( 123, $sanitized['number'] );
		$this->assertIsArray( $sanitized['array'] );
	}

	/**
	 * Create mock WP_REST_Request.
	 *
	 * @return \PHPUnit\Framework\MockObject\MockObject
	 */
	private function createMockRequest() {
		return $this->createMock( \WP_REST_Request::class );
	}

	/**
	 * Mock WordPress functions for testing.
	 */
	private function mockWordPressFunctions(): void {
		if ( ! function_exists( 'get_option' ) ) {
			function get_option( $option, $default = false ) {
				static $options = array();
				return $options[ $option ] ?? $default;
			}
		}

		if ( ! function_exists( 'update_option' ) ) {
			function update_option( $option, $value ) {
				static $options     = array();
				$options[ $option ] = $value;
				return true;
			}
		}

		if ( ! function_exists( 'delete_option' ) ) {
			function delete_option( $option ) {
				static $options = array();
				unset( $options[ $option ] );
				return true;
			}
		}

		if ( ! function_exists( 'get_transient' ) ) {
			function get_transient( $transient ) {
				static $transients = array();
				$data              = $transients[ $transient ] ?? false;

				if ( $data && $data['expiry'] < time() ) {
					unset( $transients[ $transient ] );
					return false;
				}

				return $data ? $data['value'] : false;
			}
		}

		if ( ! function_exists( 'set_transient' ) ) {
			function set_transient( $transient, $value, $expiration ) {
				static $transients        = array();
				$transients[ $transient ] = array(
					'value'  => $value,
					'expiry' => time() + $expiration,
				);
				return true;
			}
		}

		if ( ! function_exists( 'delete_transient' ) ) {
			function delete_transient( $transient ) {
				static $transients = array();
				unset( $transients[ $transient ] );
				return true;
			}
		}

		if ( ! function_exists( 'current_time' ) ) {
			function current_time( $type ) {
				return date( 'Y-m-d H:i:s' );
			}
		}

		if ( ! function_exists( 'get_current_user_id' ) ) {
			function get_current_user_id() {
				return 1;
			}
		}

		if ( ! function_exists( 'wp_json_encode' ) ) {
			function wp_json_encode( $data, $options = 0 ) {
				return json_encode( $data, $options );
			}
		}

		if ( ! function_exists( 'sanitize_text_field' ) ) {
			function sanitize_text_field( $str ) {
				return strip_tags( $str );
			}
		}

		if ( ! function_exists( 'sanitize_key' ) ) {
			function sanitize_key( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
			}
		}
	}

	/**
	 * Mock a function for testing.
	 *
	 * @param string $function_name Function name.
	 * @param mixed  $return_value Return value.
	 */
	private function mockFunction( string $function_name, $return_value ): void {
		if ( ! function_exists( $function_name ) ) {
			eval( "function {$function_name}() { return " . var_export( $return_value, true ) . '; }' );
		}
	}
}
