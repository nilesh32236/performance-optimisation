<?php
/**
 * Security Controller Class
 *
 * Handles REST API endpoints for security management including
 * security logs, blocked IPs, and security settings.
 *
 * @package PerformanceOptimisation\Core\API
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\API;

use PerformanceOptimisation\Core\Security\SecurityManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security Controller class for security-related API endpoints.
 */
class SecurityController extends BaseController {

	/**
	 * Controller route base.
	 *
	 * @var string
	 */
	protected string $rest_base = 'security';

	/**
	 * Security manager instance.
	 *
	 * @var SecurityManager
	 */
	private SecurityManager $security_manager;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->security_manager = new SecurityManager();
	}

	/**
	 * Register routes for this controller.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Security log endpoints.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/log',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_security_log' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
				'args'                => $this->get_pagination_args(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/log/clear',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'clear_security_log' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		// Blocked IPs endpoints.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/blocked-ips',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_blocked_ips' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/blocked-ips/(?P<ip>[0-9a-fA-F:.]+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'unblock_ip' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);
	}   /**

		 * Get security log endpoint handler.
		 *
		 * @param \WP_REST_Request $request The REST API request.
		 * @return \WP_REST_Response Response object.
		 */
	public function get_security_log( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->log_request( $request, 'Get Security Log' );

			$pagination = $this->get_pagination_params( $request );
			$log_data   = $this->security_manager->get_security_log( $pagination['per_page'], $pagination['offset'] );

			$response = $this->send_success_response( $log_data );

			return $this->add_pagination_headers(
				$response,
				$log_data['total'],
				$pagination['page'],
				$pagination['per_page']
			);

		} catch ( \Exception $e ) {
			return $this->handle_exception( $e, 'Failed to get security log' );
		}
	}

	/**
	 * Clear security log endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function clear_security_log( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->log_request( $request, 'Clear Security Log' );

			$result = $this->security_manager->clear_security_log();

			if ( $result ) {
				return $this->send_success_response(
					array(
						'message' => 'Security log cleared successfully.',
					)
				);
			} else {
				return $this->send_error_response(
					'clear_failed',
					'Failed to clear security log.',
					500
				);
			}
		} catch ( \Exception $e ) {
			return $this->handle_exception( $e, 'Failed to clear security log' );
		}
	}

	/**
	 * Get blocked IPs endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function get_blocked_ips( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->log_request( $request, 'Get Blocked IPs' );

			$blocked_ips   = get_option( 'wppo_blocked_ips', array() );
			$current_time  = time();
			$active_blocks = array();

			foreach ( $blocked_ips as $ip => $expiry ) {
				if ( $expiry > $current_time ) {
					$active_blocks[] = array(
						'ip'                => $ip,
						'expires_at'        => date( 'Y-m-d H:i:s', $expiry ),
						'remaining_seconds' => $expiry - $current_time,
					);
				}
			}

			return $this->send_success_response(
				array(
					'blocked_ips'   => $active_blocks,
					'total_blocked' => count( $active_blocks ),
				)
			);

		} catch ( \Exception $e ) {
			return $this->handle_exception( $e, 'Failed to get blocked IPs' );
		}
	}

	/**
	 * Unblock IP endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function unblock_ip( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$ip = $request->get_param( 'ip' );
			$this->log_request( $request, "Unblock IP: {$ip}" );

			// Validate IP address.
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $this->send_error_response(
					'invalid_ip',
					'Invalid IP address format.',
					400
				);
			}

			$result = $this->security_manager->unblock_ip( $ip );

			if ( $result ) {
				return $this->send_success_response(
					array(
						'message' => sprintf( 'IP address %s has been unblocked.', $ip ),
						'ip'      => $ip,
					)
				);
			} else {
				return $this->send_error_response(
					'ip_not_blocked',
					sprintf( 'IP address %s is not currently blocked.', $ip ),
					404
				);
			}
		} catch ( \Exception $e ) {
			return $this->handle_exception( $e, 'Failed to unblock IP' );
		}
	}

	/**
	 * Get pagination arguments for endpoints.
	 *
	 * @return array<string, array<string, mixed>> Pagination arguments.
	 */
	private function get_pagination_args(): array {
		return array(
			'page'     => array(
				'required'    => false,
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
				'description' => 'Page number for pagination.',
			),
			'per_page' => array(
				'required'    => false,
				'type'        => 'integer',
				'default'     => 50,
				'minimum'     => 1,
				'maximum'     => 100,
				'description' => 'Number of items per page.',
			),
		);
	}
}
