<?php
/**
 * Security Middleware Class
 *
 * Provides middleware functionality for REST API security including
 * authentication, authorization, input validation, and request filtering.
 *
 * @package PerformanceOptimisation\Core\Security
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security Middleware class for API request processing.
 */
class SecurityMiddleware {

	/**
	 * Security manager instance.
	 *
	 * @var SecurityManager
	 */
	private SecurityManager $security_manager;

	/**
	 * Constructor.
	 *
	 * @param SecurityManager $security_manager Security manager instance.
	 */
	public function __construct( SecurityManager $security_manager ) {
		$this->security_manager = $security_manager;
	}

	/**
	 * Process request through security middleware.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @param string           $context Security context (e.g., 'admin', 'public').
	 * @return bool|\WP_Error True if request is secure, WP_Error otherwise.
	 */
	public function process_request( \WP_REST_Request $request, string $context = 'admin' ) {
		// Step 1: IP blocking check
		$ip_check = $this->check_ip_blocking( $request );
		if ( is_wp_error( $ip_check ) ) {
			return $ip_check;
		}

		// Step 2: Rate limiting
		$rate_limit_check = $this->check_rate_limiting( $request );
		if ( is_wp_error( $rate_limit_check ) ) {
			return $rate_limit_check;
		}

		// Step 3: Authentication (for admin context)
		if ( $context === 'admin' ) {
			$auth_check = $this->check_authentication( $request );
			if ( is_wp_error( $auth_check ) ) {
				return $auth_check;
			}
		}

		// Step 4: Authorization
		$authz_check = $this->check_authorization( $request, $context );
		if ( is_wp_error( $authz_check ) ) {
			return $authz_check;
		}

		// Step 5: Input validation
		$input_check = $this->validate_input( $request );
		if ( is_wp_error( $input_check ) ) {
			return $input_check;
		}

		// Step 6: CSRF protection
		if ( $this->requires_csrf_protection( $request ) ) {
			$csrf_check = $this->check_csrf_protection( $request );
			if ( is_wp_error( $csrf_check ) ) {
				return $csrf_check;
			}
		}

		return true;
	}

	/**
	 * Check IP blocking.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return bool|\WP_Error True if allowed, WP_Error if blocked.
	 */
	private function check_ip_blocking( \WP_REST_Request $request ) {
		$client_ip = $this->security_manager->get_client_ip();

		if ( $this->security_manager->is_ip_blocked( $client_ip ) ) {
			$this->security_manager->log_security_event(
				'blocked_ip_attempt',
				array(
					'ip'     => $client_ip,
					'route'  => $request->get_route(),
					'method' => $request->get_method(),
				)
			);

			return new \WP_Error(
				'ip_blocked',
				'Access denied. Your IP address has been temporarily blocked.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check rate limiting.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return bool|\WP_Error True if within limits, WP_Error otherwise.
	 */
	private function check_rate_limiting( \WP_REST_Request $request ) {
		return $this->security_manager->check_rate_limits( $request );
	}

	/**
	 * Check authentication.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return bool|\WP_Error True if authenticated, WP_Error otherwise.
	 */
	private function check_authentication( \WP_REST_Request $request ) {
		// Check if user is logged in
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'not_authenticated',
				'You must be logged in to access this resource.',
				array( 'status' => 401 )
			);
		}

		// Additional authentication checks can be added here
		// e.g., API key validation, JWT token verification, etc.

		return true;
	}

	/**
	 * Check authorization.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @param string           $context Security context.
	 * @return bool|\WP_Error True if authorized, WP_Error otherwise.
	 */
	private function check_authorization( \WP_REST_Request $request, string $context ) {
		if ( $context === 'admin' ) {
			return $this->security_manager->check_user_capabilities( $request );
		}

		// Add other context-specific authorization checks here
		return true;
	}

	/**
	 * Validate input data.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return bool|\WP_Error True if valid, WP_Error otherwise.
	 */
	private function validate_input( \WP_REST_Request $request ) {
		return $this->security_manager->validate_request_data( $request );
	}

	/**
	 * Check if request requires CSRF protection.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return bool True if CSRF protection is required.
	 */
	private function requires_csrf_protection( \WP_REST_Request $request ): bool {
		// CSRF protection is required for state-changing operations
		return in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true );
	}

	/**
	 * Check CSRF protection.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return bool|\WP_Error True if valid, WP_Error otherwise.
	 */
	private function check_csrf_protection( \WP_REST_Request $request ) {
		return $this->security_manager->verify_nonce( $request );
	}

	/**
	 * Sanitize request data.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Request Sanitized request.
	 */
	public function sanitize_request( \WP_REST_Request $request ): \WP_REST_Request {
		$params           = $request->get_params();
		$sanitized_params = $this->security_manager->sanitize_input( $params );

		// Create new request with sanitized data
		$sanitized_request = new \WP_REST_Request( $request->get_method(), $request->get_route() );
		$sanitized_request->set_headers( $request->get_headers() );

		foreach ( $sanitized_params as $key => $value ) {
			$sanitized_request->set_param( $key, $value );
		}

		return $sanitized_request;
	}

	/**
	 * Add security headers to response.
	 *
	 * @param \WP_REST_Response $response The REST API response.
	 * @return \WP_REST_Response Response with security headers.
	 */
	public function add_security_headers( \WP_REST_Response $response ): \WP_REST_Response {
		// Add security headers
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		$response->header( 'X-Frame-Options', 'DENY' );
		$response->header( 'X-XSS-Protection', '1; mode=block' );
		$response->header( 'Referrer-Policy', 'strict-origin-when-cross-origin' );

		// Add CORS headers if needed
		if ( $this->should_add_cors_headers() ) {
			$response->header( 'Access-Control-Allow-Origin', $this->get_allowed_origins() );
			$response->header( 'Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS' );
			$response->header( 'Access-Control-Allow-Headers', 'Content-Type, Authorization, X-WP-Nonce' );
			$response->header( 'Access-Control-Allow-Credentials', 'true' );
		}

		return $response;
	}

	/**
	 * Check if CORS headers should be added.
	 *
	 * @return bool True if CORS headers should be added.
	 */
	private function should_add_cors_headers(): bool {
		// Add CORS headers if request is from a different origin
		$origin   = $_SERVER['HTTP_ORIGIN'] ?? '';
		$site_url = get_site_url();

		return ! empty( $origin ) && $origin !== $site_url;
	}

	/**
	 * Get allowed origins for CORS.
	 *
	 * @return string Allowed origins.
	 */
	private function get_allowed_origins(): string {
		// For security, only allow the current site's origin
		// In a multi-site setup, you might want to allow other origins
		return get_site_url();
	}

	/**
	 * Log security event with request context.
	 *
	 * @param string               $event_type Type of security event.
	 * @param \WP_REST_Request     $request The REST API request.
	 * @param array<string, mixed> $additional_data Additional event data.
	 * @return void
	 */
	public function log_security_event( string $event_type, \WP_REST_Request $request, array $additional_data = array() ): void {
		$event_data = array_merge(
			array(
				'ip'         => $this->security_manager->get_client_ip(),
				'user_id'    => get_current_user_id(),
				'route'      => $request->get_route(),
				'method'     => $request->get_method(),
				'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
			),
			$additional_data
		);

		$this->security_manager->log_security_event( $event_type, $event_data );
	}

	/**
	 * Create security context for request.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return array<string, mixed> Security context.
	 */
	public function create_security_context( \WP_REST_Request $request ): array {
		return array(
			'ip'         => $this->security_manager->get_client_ip(),
			'user_id'    => get_current_user_id(),
			'user_roles' => wp_get_current_user()->roles ?? array(),
			'route'      => $request->get_route(),
			'method'     => $request->get_method(),
			'timestamp'  => current_time( 'mysql' ),
			'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
			'referer'    => $_SERVER['HTTP_REFERER'] ?? '',
		);
	}

	/**
	 * Validate API key if present.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return bool|\WP_Error True if valid or not present, WP_Error if invalid.
	 */
	public function validate_api_key( \WP_REST_Request $request ) {
		$api_key = $request->get_header( 'X-API-Key' );

		if ( ! $api_key ) {
			// API key is optional, so return true if not present
			return true;
		}

		// Validate API key format
		if ( ! preg_match( '/^[a-zA-Z0-9]{32,64}$/', $api_key ) ) {
			return new \WP_Error(
				'invalid_api_key_format',
				'API key format is invalid.',
				array( 'status' => 401 )
			);
		}

		// Check if API key exists and is valid
		$stored_keys = get_option( 'wppo_api_keys', array() );

		foreach ( $stored_keys as $key_data ) {
			if ( hash_equals( $key_data['key'], $api_key ) ) {
				// Check if key is active
				if ( ! $key_data['active'] ) {
					return new \WP_Error(
						'api_key_inactive',
						'API key is inactive.',
						array( 'status' => 401 )
					);
				}

				// Check expiration
				if ( isset( $key_data['expires'] ) && $key_data['expires'] < time() ) {
					return new \WP_Error(
						'api_key_expired',
						'API key has expired.',
						array( 'status' => 401 )
					);
				}

				// Log API key usage
				$this->log_security_event(
					'api_key_used',
					$request,
					array(
						'key_id'   => $key_data['id'],
						'key_name' => $key_data['name'],
					)
				);

				return true;
			}
		}

		// API key not found
		$this->log_security_event(
			'invalid_api_key',
			$request,
			array(
				'provided_key' => substr( $api_key, 0, 8 ) . '...',
			)
		);

		return new \WP_Error(
			'invalid_api_key',
			'Invalid API key.',
			array( 'status' => 401 )
		);
	}

	/**
	 * Check request signature for webhook validation.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @param string           $secret Webhook secret.
	 * @return bool|\WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_webhook_signature( \WP_REST_Request $request, string $secret ) {
		$signature = $request->get_header( 'X-Signature' );

		if ( ! $signature ) {
			return new \WP_Error(
				'missing_signature',
				'Webhook signature is missing.',
				array( 'status' => 401 )
			);
		}

		$body               = $request->get_body();
		$expected_signature = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

		if ( ! hash_equals( $expected_signature, $signature ) ) {
			$this->log_security_event( 'invalid_webhook_signature', $request );

			return new \WP_Error(
				'invalid_signature',
				'Webhook signature is invalid.',
				array( 'status' => 401 )
			);
		}

		return true;
	}
}
