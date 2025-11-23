<?php
/**
 * Security Manager Class
 *
 * Provides comprehensive security features for the plugin including
 * nonce verification, capability checking, input validation, rate limiting,
 * and security logging.
 *
 * @package PerformanceOptimisation\Core\Security
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security Manager class for handling plugin security.
 */
class SecurityManager {

	/**
	 * Security log option name.
	 *
	 * @var string
	 */
	private const SECURITY_LOG_OPTION = 'wppo_security_log';

	/**
	 * Rate limit option prefix.
	 *
	 * @var string
	 */
	private const RATE_LIMIT_PREFIX = 'wppo_rate_limit_';

	/**
	 * Failed attempts option prefix.
	 *
	 * @var string
	 */
	private const FAILED_ATTEMPTS_PREFIX = 'wppo_failed_attempts_';

	/**
	 * Maximum failed attempts before lockout.
	 *
	 * @var int
	 */
	private const MAX_FAILED_ATTEMPTS = 5;

	/**
	 * Lockout duration in seconds.
	 *
	 * @var int
	 */
	private const LOCKOUT_DURATION = 900; // 15 minutes

	/**
	 * Initialize security manager.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_security_hooks' ) );
		add_action( 'wp_login_failed', array( $this, 'handle_login_failure' ) );
		add_action( 'wp_login', array( $this, 'clear_failed_attempts' ), 10, 2 );
	}

	/**
	 * Register security hooks for REST API.
	 *
	 * @return void
	 */
	public function register_security_hooks(): void {
		add_filter( 'rest_pre_dispatch', array( $this, 'security_check' ), 10, 3 );
		add_filter( 'rest_request_before_callbacks', array( $this, 'validate_request_security' ), 10, 3 );
	}

	/**
	 * Perform security checks before REST API dispatch.
	 *
	 * @param mixed            $result Response to replace the requested version with.
	 * @param \WP_REST_Server  $server Server instance.
	 * @param \WP_REST_Request $request Request used to generate the response.
	 * @return mixed Original result or error response.
	 */
	public function security_check( $result, \WP_REST_Server $server, \WP_REST_Request $request ) {
		// Only check our plugin's endpoints
		if ( strpos( $request->get_route(), '/performance-optimisation/' ) === false ) {
			return $result;
		}

		// Check if IP is blocked
		$client_ip = $this->get_client_ip();
		if ( $this->is_ip_blocked( $client_ip ) ) {
			$this->log_security_event(
				'blocked_ip_attempt',
				array(
					'ip'     => $client_ip,
					'route'  => $request->get_route(),
					'method' => $request->get_method(),
				)
			);

			return new \WP_Error(
				'ip_blocked',
				'Access denied. Your IP address has been temporarily blocked due to suspicious activity.',
				array( 'status' => 403 )
			);
		}

		// Check rate limits
		$rate_limit_result = $this->check_rate_limits( $request );
		if ( is_wp_error( $rate_limit_result ) ) {
			return $rate_limit_result;
		}

		return $result;
	}

	/**
	 * Validate request security before callbacks.
	 *
	 * @param \WP_REST_Response|\WP_Error $response Current response.
	 * @param array<string, mixed>        $handler Route handler used for the request.
	 * @param \WP_REST_Request            $request Request used to generate the response.
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function validate_request_security( $response, array $handler, \WP_REST_Request $request ) {
		// Only check our plugin's endpoints
		if ( strpos( $request->get_route(), '/performance-optimisation/' ) === false ) {
			return $response;
		}

		// Verify nonce for state-changing operations
		if ( in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
			$nonce_result = $this->verify_nonce( $request );
			if ( is_wp_error( $nonce_result ) ) {
				$this->log_security_event(
					'invalid_nonce',
					array(
						'ip'      => $this->get_client_ip(),
						'user_id' => get_current_user_id(),
						'route'   => $request->get_route(),
						'method'  => $request->get_method(),
					)
				);

				return $nonce_result;
			}
		}

		// Check user capabilities
		$capability_result = $this->check_user_capabilities( $request );
		if ( is_wp_error( $capability_result ) ) {
			$this->log_security_event(
				'insufficient_capabilities',
				array(
					'ip'                  => $this->get_client_ip(),
					'user_id'             => get_current_user_id(),
					'route'               => $request->get_route(),
					'required_capability' => 'manage_options',
				)
			);

			return $capability_result;
		}

		// Validate request data
		$validation_result = $this->validate_request_data( $request );
		if ( is_wp_error( $validation_result ) ) {
			$this->log_security_event(
				'malicious_request_data',
				array(
					'ip'        => $this->get_client_ip(),
					'user_id'   => get_current_user_id(),
					'route'     => $request->get_route(),
					'violation' => $validation_result->get_error_message(),
				)
			);

			return $validation_result;
		}

		return $response;
	}

	/**
	 * Verify nonce for request.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return bool|\WP_Error True if valid, WP_Error otherwise.
	 */
	public function verify_nonce( \WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! $nonce ) {
			return new \WP_Error(
				'missing_nonce',
				'Security token is missing.',
				array( 'status' => 403 )
			);
		}

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error(
				'invalid_nonce',
				'Security token is invalid.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check user capabilities.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return bool|\WP_Error True if authorized, WP_Error otherwise.
	 */
	public function check_user_capabilities( \WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'insufficient_capabilities',
				'You do not have permission to perform this action.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Validate request data for security threats.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return bool|\WP_Error True if safe, WP_Error if threat detected.
	 */
	public function validate_request_data( \WP_REST_Request $request ) {
		$params = $request->get_params();

		// Check for common attack patterns
		$malicious_patterns = array(
			'/<script[^>]*>.*?<\/script>/i',           // Script tags
			'/javascript:/i',                          // JavaScript protocol
			'/on\w+\s*=/i',                           // Event handlers
			'/\beval\s*\(/i',                         // eval() function
			'/\bexec\s*\(/i',                         // exec() function
			'/\bsystem\s*\(/i',                       // system() function
			'/\.\.\//i',                              // Directory traversal
			'/\bunion\s+select/i',                    // SQL injection
			'/\bselect\s+.*\bfrom\s+/i',             // SQL injection
			'/\bdrop\s+table/i',                      // SQL injection
			'/\binsert\s+into/i',                     // SQL injection
			'/\bdelete\s+from/i',                     // SQL injection
		);

		foreach ( $params as $key => $value ) {
			if ( is_string( $value ) ) {
				foreach ( $malicious_patterns as $pattern ) {
					if ( preg_match( $pattern, $value ) ) {
						return new \WP_Error(
							'malicious_content',
							'Request contains potentially malicious content.',
							array( 'status' => 400 )
						);
					}
				}
			} elseif ( is_array( $value ) ) {
				$validation_result = $this->validate_array_data( $value );
				if ( is_wp_error( $validation_result ) ) {
					return $validation_result;
				}
			}
		}

		return true;
	}

	/**
	 * Validate array data recursively.
	 *
	 * @param array<mixed> $data Array data to validate.
	 * @return bool|\WP_Error True if safe, WP_Error if threat detected.
	 */
	private function validate_array_data( array $data ) {
		foreach ( $data as $key => $value ) {
			if ( is_string( $value ) ) {
				// Create a temporary request to validate the string
				$temp_request = new \WP_REST_Request();
				$temp_request->set_param( 'temp', $value );
				$result = $this->validate_request_data( $temp_request );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			} elseif ( is_array( $value ) ) {
				$result = $this->validate_array_data( $value );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		return true;
	}

	/**
	 * Check rate limits for request.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return bool|\WP_Error True if within limits, WP_Error otherwise.
	 */
	public function check_rate_limits( \WP_REST_Request $request ) {
		// Rate limiting temporarily disabled
		return true;
		
		$client_ip = $this->get_client_ip();
		$user_id   = get_current_user_id();
		$route     = $request->get_route();
		$method    = $request->get_method();

		// Different rate limits for different endpoints
		$rate_limits = $this->get_rate_limits();
		$limit_key   = $this->get_rate_limit_key( $route, $method );

		if ( ! isset( $rate_limits[ $limit_key ] ) ) {
			$limit_key = 'default';
		}

		$limit_config = $rate_limits[ $limit_key ];
		$identifier   = $user_id ? "user_{$user_id}" : "ip_{$client_ip}";

		$is_within_limit = $this->check_rate_limit(
			$identifier . '_' . $limit_key,
			$limit_config['requests'],
			$limit_config['window']
		);

		if ( ! $is_within_limit ) {
			$this->log_security_event(
				'rate_limit_exceeded',
				array(
					'ip'      => $client_ip,
					'user_id' => $user_id,
					'route'   => $route,
					'method'  => $method,
					'limit'   => $limit_config,
				)
			);

			return new \WP_Error(
				'rate_limit_exceeded',
				sprintf(
					'Rate limit exceeded. Maximum %d requests per %d seconds allowed.',
					$limit_config['requests'],
					$limit_config['window']
				),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Check individual rate limit.
	 *
	 * @param string $key Rate limit key.
	 * @param int    $limit Number of requests allowed.
	 * @param int    $window Time window in seconds.
	 * @return bool True if within limit.
	 */
	public function check_rate_limit( string $key, int $limit = 60, int $window = 3600 ): bool {
		$transient_key = self::RATE_LIMIT_PREFIX . md5( $key );
		$current_count = get_transient( $transient_key );

		if ( false === $current_count ) {
			set_transient( $transient_key, 1, $window );
			return true;
		}

		if ( $current_count >= $limit ) {
			return false;
		}

		set_transient( $transient_key, $current_count + 1, $window );
		return true;
	}

	/**
	 * Handle login failure for security monitoring.
	 *
	 * @param string $username Username used in failed login.
	 * @return void
	 */
	public function handle_login_failure( string $username ): void {
		$client_ip    = $this->get_client_ip();
		$attempts_key = self::FAILED_ATTEMPTS_PREFIX . md5( $client_ip );

		$attempts = get_transient( $attempts_key );
		$attempts = $attempts ? $attempts + 1 : 1;

		set_transient( $attempts_key, $attempts, self::LOCKOUT_DURATION );

		$this->log_security_event(
			'login_failure',
			array(
				'ip'       => $client_ip,
				'username' => $username,
				'attempts' => $attempts,
			)
		);

		// Block IP if too many failed attempts
		if ( $attempts >= self::MAX_FAILED_ATTEMPTS ) {
			$this->block_ip( $client_ip, self::LOCKOUT_DURATION );

			$this->log_security_event(
				'ip_blocked',
				array(
					'ip'       => $client_ip,
					'reason'   => 'too_many_failed_logins',
					'attempts' => $attempts,
					'duration' => self::LOCKOUT_DURATION,
				)
			);
		}
	}

	/**
	 * Clear failed attempts on successful login.
	 *
	 * @param string   $user_login Username.
	 * @param \WP_User $user User object.
	 * @return void
	 */
	public function clear_failed_attempts( string $user_login, \WP_User $user ): void {
		$client_ip    = $this->get_client_ip();
		$attempts_key = self::FAILED_ATTEMPTS_PREFIX . md5( $client_ip );

		delete_transient( $attempts_key );
	}

	/**
	 * Block IP address.
	 *
	 * @param string $ip IP address to block.
	 * @param int    $duration Block duration in seconds.
	 * @return void
	 */
	public function block_ip( string $ip, int $duration = 3600 ): void {
		$blocked_ips        = get_option( 'wppo_blocked_ips', array() );
		$blocked_ips[ $ip ] = time() + $duration;

		update_option( 'wppo_blocked_ips', $blocked_ips );
	}

	/**
	 * Check if IP is blocked.
	 *
	 * @param string $ip IP address to check.
	 * @return bool True if blocked.
	 */
	public function is_ip_blocked( string $ip ): bool {
		$blocked_ips = get_option( 'wppo_blocked_ips', array() );

		if ( ! isset( $blocked_ips[ $ip ] ) ) {
			return false;
		}

		// Check if block has expired
		if ( $blocked_ips[ $ip ] < time() ) {
			unset( $blocked_ips[ $ip ] );
			update_option( 'wppo_blocked_ips', $blocked_ips );
			return false;
		}

		return true;
	}

	/**
	 * Unblock IP address.
	 *
	 * @param string $ip IP address to unblock.
	 * @return bool True if unblocked.
	 */
	public function unblock_ip( string $ip ): bool {
		$blocked_ips = get_option( 'wppo_blocked_ips', array() );

		if ( isset( $blocked_ips[ $ip ] ) ) {
			unset( $blocked_ips[ $ip ] );
			update_option( 'wppo_blocked_ips', $blocked_ips );

			$this->log_security_event(
				'ip_unblocked',
				array(
					'ip'           => $ip,
					'unblocked_by' => get_current_user_id(),
				)
			);

			return true;
		}

		return false;
	}

	/**
	 * Get client IP address.
	 *
	 * @return string Client IP address.
	 */
	public function get_client_ip(): string {
		// Check for various headers that might contain the real IP
		$ip_headers = array(
			'HTTP_CF_CONNECTING_IP',     // Cloudflare
			'HTTP_X_REAL_IP',            // Nginx proxy
			'HTTP_X_FORWARDED_FOR',      // Load balancer/proxy
			'HTTP_X_FORWARDED',          // Proxy
			'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
			'HTTP_FORWARDED_FOR',        // Proxy
			'HTTP_FORWARDED',            // Proxy
			'REMOTE_ADDR',               // Standard
		);

		foreach ( $ip_headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = $_SERVER[ $header ];

				// Handle comma-separated IPs (X-Forwarded-For can contain multiple IPs)
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}

				// Validate IP address
				if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					return $ip;
				}
			}
		}

		return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
	}

	/**
	 * Log security event.
	 *
	 * @param string               $event_type Type of security event.
	 * @param array<string, mixed> $data Event data.
	 * @return void
	 */
	public function log_security_event( string $event_type, array $data = array() ): void {
		$log_entry = array(
			'timestamp'   => current_time( 'mysql' ),
			'event_type'  => $event_type,
			'data'        => $data,
			'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
			'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
		);

		$security_log = get_option( self::SECURITY_LOG_OPTION, array() );

		// Keep only last 1000 entries
		if ( count( $security_log ) >= 1000 ) {
			$security_log = array_slice( $security_log, -999 );
		}

		$security_log[] = $log_entry;
		update_option( self::SECURITY_LOG_OPTION, $security_log );

		// Also log to WordPress debug log if enabled
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log(
				sprintf(
					'[Performance Optimisation Security] %s: %s',
					$event_type,
					wp_json_encode( $data )
				)
			);
		}
	}

	/**
	 * Get security log entries.
	 *
	 * @param int $limit Number of entries to return.
	 * @param int $offset Offset for pagination.
	 * @return array<string, mixed> Log entries and pagination info.
	 */
	public function get_security_log( int $limit = 50, int $offset = 0 ): array {
		$security_log  = get_option( self::SECURITY_LOG_OPTION, array() );
		$total_entries = count( $security_log );

		// Reverse to show newest first
		$security_log = array_reverse( $security_log );

		// Apply pagination
		$entries = array_slice( $security_log, $offset, $limit );

		return array(
			'entries' => $entries,
			'total'   => $total_entries,
			'limit'   => $limit,
			'offset'  => $offset,
		);
	}

	/**
	 * Clear security log.
	 *
	 * @return bool True if cleared successfully.
	 */
	public function clear_security_log(): bool {
		$result = delete_option( self::SECURITY_LOG_OPTION );

		if ( $result ) {
			$this->log_security_event(
				'security_log_cleared',
				array(
					'cleared_by' => get_current_user_id(),
				)
			);
		}

		return $result;
	}

	/**
	 * Get rate limit configuration.
	 *
	 * @return array<string, array<string, int>> Rate limit configuration.
	 */
	private function get_rate_limits(): array {
		return array(
			'cache_clear'     => array(
				'requests' => 10,
				'window'   => 300, // 5 minutes
			),
			'image_optimize'  => array(
				'requests' => 5,
				'window'   => 300, // 5 minutes
			),
			'settings_update' => array(
				'requests' => 20,
				'window'   => 300, // 5 minutes
			),
			'bulk_operations' => array(
				'requests' => 3,
				'window'   => 600, // 10 minutes
			),
			'analysis'        => array(
				'requests' => 5,
				'window'   => 300, // 5 minutes
			),
			'default'         => array(
				'requests' => 60,
				'window'   => 3600, // 1 hour
			),
		);
	}

	/**
	 * Get rate limit key for route and method.
	 *
	 * @param string $route API route.
	 * @param string $method HTTP method.
	 * @return string Rate limit key.
	 */
	private function get_rate_limit_key( string $route, string $method ): string {
		// Map routes to rate limit keys
		$route_mappings = array(
			'/cache/clear'                  => 'cache_clear',
			'/optimization/images/optimize' => 'image_optimize',
			'/optimization/bulk'            => 'bulk_operations',
			'/optimization/analyze'         => 'analysis',
			'/settings'                     => 'settings_update',
		);

		foreach ( $route_mappings as $pattern => $key ) {
			if ( strpos( $route, $pattern ) !== false ) {
				return $key;
			}
		}

		return 'default';
	}

	/**
	 * Sanitize input data recursively.
	 *
	 * @param mixed $data Data to sanitize.
	 * @return mixed Sanitized data.
	 */
	public function sanitize_input( $data ) {
		if ( is_string( $data ) ) {
			return sanitize_text_field( $data );
		} elseif ( is_array( $data ) ) {
			return array_map( array( $this, 'sanitize_input' ), $data );
		} elseif ( is_object( $data ) ) {
			$sanitized = new \stdClass();
			foreach ( $data as $key => $value ) {
				$sanitized->{ sanitize_key( $key ) } = $this->sanitize_input( $value );
			}
			return $sanitized;
		}

		return $data;
	}

	/**
	 * Escape output data for safe display.
	 *
	 * @param mixed $data Data to escape.
	 * @return mixed Escaped data.
	 */
	public function escape_output( $data ) {
		if ( is_string( $data ) ) {
			return esc_html( $data );
		} elseif ( is_array( $data ) ) {
			return array_map( array( $this, 'escape_output' ), $data );
		} elseif ( is_object( $data ) ) {
			$escaped = new \stdClass();
			foreach ( $data as $key => $value ) {
				$escaped->{ esc_attr( $key ) } = $this->escape_output( $value );
			}
			return $escaped;
		}

		return $data;
	}
}
