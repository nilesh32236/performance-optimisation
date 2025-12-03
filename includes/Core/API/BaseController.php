<?php
/**
 * Base Controller Class
 *
 * Provides common functionality for all REST API controllers including
 * authentication, validation, error handling, and response formatting.
 *
 * @package PerformanceOptimisation\Core\API
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\API;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Base Controller class for REST API endpoints.
 */
abstract class BaseController
{

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected string $namespace = 'performance-optimisation/v1';

	/**
	 * Controller route base.
	 *
	 * @var string
	 */
	protected string $rest_base = '';

	/**
	 * Register routes for this controller.
	 *
	 * @return void
	 */
	abstract public function register_routes(): void;

	/**
	 * Check if current user has admin permissions.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return bool|\WP_Error True if permission granted, WP_Error otherwise.
	 */
	public function check_admin_permissions(\WP_REST_Request $request)
	{
		// Load security middleware if not already loaded.
		if (!class_exists('PerformanceOptimisation\Core\Security\SecurityManager')) {
			require_once WPPO_PLUGIN_PATH . 'includes/Core/Security/SecurityManager.php';
		}
		if (!class_exists('PerformanceOptimisation\Core\Security\SecurityMiddleware')) {
			require_once WPPO_PLUGIN_PATH . 'includes/Core/Security/SecurityMiddleware.php';
		}

		$security_manager = new \PerformanceOptimisation\Core\Security\SecurityManager();
		$security_middleware = new \PerformanceOptimisation\Core\Security\SecurityMiddleware($security_manager);

		// Process request through security middleware.
		$security_result = $security_middleware->process_request($request, 'admin');
		if (is_wp_error($security_result)) {
			return $security_result;
		}

		// Original capability check.
		if (!current_user_can('manage_options')) {
			return new \WP_Error(
				'rest_forbidden_context',
				__('Sorry, you are not allowed to manage these options.', 'performance-optimisation'),
				array('status' => rest_authorization_required_code())
			);
		}

		return true;
	}

	/**
	 * Validate request data against schema.
	 *
	 * @param \WP_REST_Request     $request The REST API request.
	 * @param array<string, mixed> $schema Validation schema.
	 * @return array<string, mixed> Validation result.
	 */
	protected function validate_request(\WP_REST_Request $request, array $schema): array
	{
		$errors = array();
		$data = array();

		foreach ($schema as $field => $rules) {
			$value = $request->get_param($field);
			$is_required = $rules['required'] ?? false;

			// Check required fields.
			if ($is_required && (null === $value || '' === $value)) {
				$errors[] = sprintf('Field "%s" is required.', $field);
				continue;
			}

			// Skip validation if field is not provided and not required.
			if (null === $value && !$is_required) {
				if (isset($rules['default'])) {
					$data[$field] = $rules['default'];
				}
				continue;
			}

			// Type validation.
			$expected_type = $rules['type'] ?? 'string';
			$validation_result = $this->validate_field_type($value, $expected_type, $field);

			if ($validation_result['valid']) {
				$data[$field] = $validation_result['value'];
			} else {
				$errors[] = $validation_result['error'];
			}

			// Additional validation rules.
			if (isset($rules['enum']) && !in_array($value, $rules['enum'], true)) {
				$errors[] = sprintf(
					'Field "%s" must be one of: %s',
					$field,
					implode(', ', $rules['enum'])
				);
			}

			if (isset($rules['min']) && is_numeric($value) && $value < $rules['min']) {
				$errors[] = sprintf('Field "%s" must be at least %s.', $field, $rules['min']);
			}

			if (isset($rules['max']) && is_numeric($value) && $value > $rules['max']) {
				$errors[] = sprintf('Field "%s" must be at most %s.', $field, $rules['max']);
			}
		}

		return array(
			'valid' => empty($errors),
			'errors' => $errors,
			'data' => $data,
		);
	}

	/**
	 * Validate field type.
	 *
	 * @param mixed  $value Field value.
	 * @param string $expected_type Expected type.
	 * @param string $field_name Field name for error messages.
	 * @return array<string, mixed> Validation result.
	 */
	protected function validate_field_type($value, string $expected_type, string $field_name): array
	{
		switch ($expected_type) {
			case 'string':
				if (!is_string($value)) {
					return array(
						'valid' => false,
						'error' => sprintf('Field "%s" must be a string.', $field_name),
					);
				}
				return array(
					'valid' => true,
					'value' => sanitize_text_field($value),
				);

			case 'integer':
				if (!is_numeric($value)) {
					return array(
						'valid' => false,
						'error' => sprintf('Field "%s" must be an integer.', $field_name),
					);
				}
				return array(
					'valid' => true,
					'value' => (int) $value,
				);

			case 'number':
				if (!is_numeric($value)) {
					return array(
						'valid' => false,
						'error' => sprintf('Field "%s" must be a number.', $field_name),
					);
				}
				return array(
					'valid' => true,
					'value' => (float) $value,
				);

			case 'boolean':
				return array(
					'valid' => true,
					'value' => (bool) $value,
				);

			case 'array':
				if (!is_array($value)) {
					return array(
						'valid' => false,
						'error' => sprintf('Field "%s" must be an array.', $field_name),
					);
				}
				return array(
					'valid' => true,
					'value' => $this->sanitize_array($value),
				);

			case 'object':
				if (!is_array($value) && !is_object($value)) {
					return array(
						'valid' => false,
						'error' => sprintf('Field "%s" must be an object.', $field_name),
					);
				}
				return array(
					'valid' => true,
					'value' => $this->sanitize_array((array) $value),
				);

			default:
				return array(
					'valid' => true,
					'value' => $value,
				);
		}
	}

	/**
	 * Sanitize array recursively.
	 *
	 * @param array $array Array to sanitize.
	 * @return array Sanitized array.
	 */
	protected function sanitize_array(array $array): array
	{
		$sanitized = array();
		foreach ($array as $key => $value) {
			if (is_array($value)) {
				$sanitized[$key] = $this->sanitize_array($value);
			} else {
				$sanitized[$key] = sanitize_text_field($value);
			}
		}
		return $sanitized;
	}

	/**
	 * Send success response.
	 *
	 * @param mixed $data Response data.
	 * @param int   $status_code HTTP status code.
	 * @return \WP_REST_Response Response object.
	 */
	protected function send_success_response($data = null, int $status_code = 200): \WP_REST_Response
	{
		$response_data = array(
			'success' => true,
			'data' => $data,
			'timestamp' => current_time('mysql'),
		);

		return new \WP_REST_Response($response_data, $status_code);
	}

	/**
	 * Send error response.
	 *
	 * @param string               $error_code Machine-readable error code.
	 * @param string               $message Human-readable error message.
	 * @param int                  $status_code HTTP status code.
	 * @param array<string, mixed> $additional_data Additional error data.
	 * @return \WP_REST_Response Response object.
	 */
	protected function send_error_response(
		string $error_code,
		string $message,
		int $status_code = 400,
		array $additional_data = array()
	): \WP_REST_Response {
		$response_data = array(
			'success' => false,
			'error' => array(
				'code' => $error_code,
				'message' => $message,
				'data' => array_merge(
					array('status' => $status_code),
					$additional_data
				),
			),
			'timestamp' => current_time('mysql'),
		);

		return new \WP_REST_Response($response_data, $status_code);
	}

	/**
	 * Send validation error response.
	 *
	 * @param array<string> $errors Validation errors.
	 * @return \WP_REST_Response Response object.
	 */
	protected function send_validation_error_response(array $errors): \WP_REST_Response
	{
		return $this->send_error_response(
			'validation_failed',
			'Request validation failed.',
			400,
			array('validation_errors' => $errors)
		);
	}

	/**
	 * Log API request for debugging.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @param string           $action Action being performed.
	 * @return void
	 */
	protected function log_request(\WP_REST_Request $request, string $action): void
	{
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log(
				sprintf(
					'[Performance Optimisation API] %s - %s %s - User: %d',
					$action,
					$request->get_method(),
					$request->get_route(),
					get_current_user_id()
				)
			);
		}
	}

	/**
	 * Handle exceptions and convert to error response.
	 *
	 * @param \Exception $exception Exception to handle.
	 * @param string     $default_message Default error message.
	 * @return \WP_REST_Response Error response.
	 */
	protected function handle_exception(\Exception $exception, string $default_message = 'An error occurred'): \WP_REST_Response
	{
		// Log the exception.
		error_log(
			sprintf(
				'[Performance Optimisation API] Exception: %s in %s:%d',
				$exception->getMessage(),
				$exception->getFile(),
				$exception->getLine()
			)
		);

		// Don't expose internal errors in production.
		$message = (defined('WP_DEBUG') && WP_DEBUG)
			? $exception->getMessage()
			: $default_message;

		return $this->send_error_response(
			'internal_error',
			$message,
			500
		);
	}

	/**
	 * Get pagination parameters from request.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return array<string, int> Pagination parameters.
	 */
	protected function get_pagination_params(\WP_REST_Request $request): array
	{
		$page = $request->get_param('page') ?: 1;
		$per_page = $request->get_param('per_page') ?: 10;

		// Validate and sanitize.
		$page = max(1, (int) $page);
		$per_page = max(1, min(100, (int) $per_page)); // Cap at 100 items per page.

		return array(
			'page' => $page,
			'per_page' => $per_page,
			'offset' => ($page - 1) * $per_page,
		);
	}

	/**
	 * Add pagination headers to response.
	 *
	 * @param \WP_REST_Response $response Response object.
	 * @param int               $total_items Total number of items.
	 * @param int               $page Current page.
	 * @param int               $per_page Items per page.
	 * @return \WP_REST_Response Modified response.
	 */
	protected function add_pagination_headers(
		\WP_REST_Response $response,
		int $total_items,
		int $page,
		int $per_page
	): \WP_REST_Response {
		$total_pages = ceil($total_items / $per_page);

		$response->header('X-WP-Total', (string) $total_items);
		$response->header('X-WP-TotalPages', (string) $total_pages);

		return $response;
	}

	/**
	 * Check if request has valid nonce.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @param string           $action Nonce action.
	 * @return bool True if nonce is valid.
	 */
	protected function verify_nonce(\WP_REST_Request $request, string $action = 'wp_rest'): bool
	{
		$nonce = $request->get_header('X-WP-Nonce');

		if (!$nonce) {
			return false;
		}

		return wp_verify_nonce($nonce, $action);
	}

	/**
	 * Rate limit check for API endpoints.
	 *
	 * @param string $key Rate limit key.
	 * @param int    $limit Number of requests allowed.
	 * @param int    $window Time window in seconds.
	 * @return bool True if within rate limit.
	 */
	protected function check_rate_limit(string $key, int $limit = 60, int $window = 3600): bool
	{
		$transient_key = 'wppo_rate_limit_' . md5($key);
		$current_count = get_transient($transient_key);

		if (false === $current_count) {
			set_transient($transient_key, 1, $window);
			return true;
		}

		if ($current_count >= $limit) {
			return false;
		}

		set_transient($transient_key, $current_count + 1, $window);
		return true;
	}

	/**
	 * Get rate limit key for current user/IP.
	 *
	 * @return string Rate limit key.
	 */
	protected function get_rate_limit_key(): string
	{
		$user_id = get_current_user_id();

		if ($user_id) {
			return 'user_' . $user_id;
		}

		// Fallback to IP address for non-authenticated requests.
		$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
		return 'ip_' . $ip;
	}
}
