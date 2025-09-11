<?php
/**
 * Security Configuration Class
 *
 * Manages security configuration settings including rate limits,
 * security policies, and threat detection rules.
 *
 * @package PerformanceOptimisation\Core\Security
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security Configuration class for managing security settings.
 */
class SecurityConfig {

	/**
	 * Security settings option name.
	 *
	 * @var string
	 */
	private const SETTINGS_OPTION = 'wppo_security_settings';

	/**
	 * Default security configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $default_config;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->default_config = $this->get_default_config();
	}

	/**
	 * Get security configuration.
	 *
	 * @return array<string, mixed> Security configuration.
	 */
	public function get_config(): array {
		$saved_config = get_option( self::SETTINGS_OPTION, array() );
		return array_replace_recursive( $this->default_config, $saved_config );
	}

	/**
	 * Update security configuration.
	 *
	 * @param array<string, mixed> $config New configuration.
	 * @return bool True if updated successfully.
	 */
	public function update_config( array $config ): bool {
		$current_config = $this->get_config();
		$updated_config = array_replace_recursive( $current_config, $config );

		// Validate configuration
		$validation_result = $this->validate_config( $updated_config );
		if ( ! $validation_result['valid'] ) {
			return false;
		}

		return update_option( self::SETTINGS_OPTION, $updated_config );
	}

	/**
	 * Get rate limit configuration.
	 *
	 * @return array<string, array<string, int>> Rate limit configuration.
	 */
	public function get_rate_limits(): array {
		$config = $this->get_config();
		return $config['rate_limits'] ?? $this->default_config['rate_limits'];
	}

	/**
	 * Get security policies.
	 *
	 * @return array<string, mixed> Security policies.
	 */
	public function get_security_policies(): array {
		$config = $this->get_config();
		return $config['security_policies'] ?? $this->default_config['security_policies'];
	}

	/**
	 * Get threat detection rules.
	 *
	 * @return array<string, mixed> Threat detection rules.
	 */
	public function get_threat_detection_rules(): array {
		$config = $this->get_config();
		return $config['threat_detection'] ?? $this->default_config['threat_detection'];
	}

	/**
	 * Check if security feature is enabled.
	 *
	 * @param string $feature Feature name.
	 * @return bool True if enabled.
	 */
	public function is_feature_enabled( string $feature ): bool {
		$config = $this->get_config();
		return $config['features'][ $feature ] ?? false;
	}

	/**
	 * Enable or disable security feature.
	 *
	 * @param string $feature Feature name.
	 * @param bool   $enabled Whether to enable the feature.
	 * @return bool True if updated successfully.
	 */
	public function set_feature_enabled( string $feature, bool $enabled ): bool {
		$config                         = $this->get_config();
		$config['features'][ $feature ] = $enabled;
		return $this->update_config( $config );
	}

	/**
	 * Get default security configuration.
	 *
	 * @return array<string, mixed> Default configuration.
	 */
	private function get_default_config(): array {
		return array(
			'features'          => array(
				'rate_limiting'       => true,
				'ip_blocking'         => true,
				'request_validation'  => true,
				'security_logging'    => true,
				'nonce_verification'  => true,
				'capability_checking' => true,
			),
			'rate_limits'       => array(
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
			),
			'security_policies' => array(
				'max_failed_attempts'      => 5,
				'lockout_duration'         => 900, // 15 minutes
				'session_timeout'          => 3600, // 1 hour
				'require_strong_passwords' => false,
				'two_factor_auth'          => false,
			),
			'threat_detection'  => array(
				'enabled'         => true,
				'patterns'        => array(
					'script_injection'     => '/<script[^>]*>.*?<\/script>/i',
					'javascript_protocol'  => '/javascript:/i',
					'event_handlers'       => '/on\w+\s*=/i',
					'eval_function'        => '/\beval\s*\(/i',
					'exec_function'        => '/\bexec\s*\(/i',
					'system_function'      => '/\bsystem\s*\(/i',
					'directory_traversal'  => '/\.\.\//i',
					'sql_injection_union'  => '/\bunion\s+select/i',
					'sql_injection_select' => '/\bselect\s+.*\bfrom\s+/i',
					'sql_injection_drop'   => '/\bdrop\s+table/i',
					'sql_injection_insert' => '/\binsert\s+into/i',
					'sql_injection_delete' => '/\bdelete\s+from/i',
				),
				'severity_levels' => array(
					'script_injection'     => 'high',
					'javascript_protocol'  => 'high',
					'event_handlers'       => 'medium',
					'eval_function'        => 'high',
					'exec_function'        => 'critical',
					'system_function'      => 'critical',
					'directory_traversal'  => 'high',
					'sql_injection_union'  => 'critical',
					'sql_injection_select' => 'high',
					'sql_injection_drop'   => 'critical',
					'sql_injection_insert' => 'high',
					'sql_injection_delete' => 'critical',
				),
			),
			'logging'           => array(
				'max_entries'             => 1000,
				'retention_days'          => 30,
				'log_successful_requests' => false,
				'log_failed_requests'     => true,
				'log_blocked_requests'    => true,
			),
			'notifications'     => array(
				'email_alerts'    => false,
				'alert_threshold' => 10, // Number of security events before alert
				'alert_window'    => 3600, // Time window for alert threshold
				'admin_email'     => get_option( 'admin_email' ),
			),
		);
	}

	/**
	 * Validate security configuration.
	 *
	 * @param array<string, mixed> $config Configuration to validate.
	 * @return array<string, mixed> Validation result.
	 */
	private function validate_config( array $config ): array {
		$errors = array();

		// Validate rate limits
		if ( isset( $config['rate_limits'] ) ) {
			foreach ( $config['rate_limits'] as $key => $limit ) {
				if ( ! isset( $limit['requests'] ) || ! is_int( $limit['requests'] ) || $limit['requests'] < 1 ) {
					$errors[] = "Invalid requests value for rate limit '{$key}'";
				}
				if ( ! isset( $limit['window'] ) || ! is_int( $limit['window'] ) || $limit['window'] < 60 ) {
					$errors[] = "Invalid window value for rate limit '{$key}' (minimum 60 seconds)";
				}
			}
		}

		// Validate security policies
		if ( isset( $config['security_policies'] ) ) {
			$policies = $config['security_policies'];

			if ( isset( $policies['max_failed_attempts'] ) ) {
				if ( ! is_int( $policies['max_failed_attempts'] ) || $policies['max_failed_attempts'] < 1 ) {
					$errors[] = 'max_failed_attempts must be a positive integer';
				}
			}

			if ( isset( $policies['lockout_duration'] ) ) {
				if ( ! is_int( $policies['lockout_duration'] ) || $policies['lockout_duration'] < 60 ) {
					$errors[] = 'lockout_duration must be at least 60 seconds';
				}
			}
		}

		// Validate threat detection patterns
		if ( isset( $config['threat_detection']['patterns'] ) ) {
			foreach ( $config['threat_detection']['patterns'] as $name => $pattern ) {
				if ( @preg_match( $pattern, '' ) === false ) {
					$errors[] = "Invalid regex pattern for threat detection rule '{$name}'";
				}
			}
		}

		// Validate logging settings
		if ( isset( $config['logging'] ) ) {
			$logging = $config['logging'];

			if ( isset( $logging['max_entries'] ) ) {
				if ( ! is_int( $logging['max_entries'] ) || $logging['max_entries'] < 100 ) {
					$errors[] = 'max_entries must be at least 100';
				}
			}

			if ( isset( $logging['retention_days'] ) ) {
				if ( ! is_int( $logging['retention_days'] ) || $logging['retention_days'] < 1 ) {
					$errors[] = 'retention_days must be a positive integer';
				}
			}
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}

	/**
	 * Reset configuration to defaults.
	 *
	 * @return bool True if reset successfully.
	 */
	public function reset_to_defaults(): bool {
		return update_option( self::SETTINGS_OPTION, $this->default_config );
	}

	/**
	 * Export security configuration.
	 *
	 * @return array<string, mixed> Exported configuration.
	 */
	public function export_config(): array {
		return array(
			'config'         => $this->get_config(),
			'exported_at'    => current_time( 'mysql' ),
			'exported_by'    => get_current_user_id(),
			'plugin_version' => WPPO_VERSION ?? '1.0.0',
		);
	}

	/**
	 * Import security configuration.
	 *
	 * @param array<string, mixed> $import_data Imported configuration data.
	 * @return array<string, mixed> Import result.
	 */
	public function import_config( array $import_data ): array {
		if ( ! isset( $import_data['config'] ) ) {
			return array(
				'success' => false,
				'errors'  => array( 'Invalid import data format' ),
			);
		}

		$config            = $import_data['config'];
		$validation_result = $this->validate_config( $config );

		if ( ! $validation_result['valid'] ) {
			return array(
				'success' => false,
				'errors'  => $validation_result['errors'],
			);
		}

		$success = update_option( self::SETTINGS_OPTION, $config );

		return array(
			'success' => $success,
			'errors'  => $success ? array() : array( 'Failed to save configuration' ),
		);
	}

	/**
	 * Get security configuration schema for validation.
	 *
	 * @return array<string, mixed> Configuration schema.
	 */
	public function get_config_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'features'          => array(
					'type'       => 'object',
					'properties' => array(
						'rate_limiting'       => array( 'type' => 'boolean' ),
						'ip_blocking'         => array( 'type' => 'boolean' ),
						'request_validation'  => array( 'type' => 'boolean' ),
						'security_logging'    => array( 'type' => 'boolean' ),
						'nonce_verification'  => array( 'type' => 'boolean' ),
						'capability_checking' => array( 'type' => 'boolean' ),
					),
				),
				'rate_limits'       => array(
					'type'              => 'object',
					'patternProperties' => array(
						'.*' => array(
							'type'       => 'object',
							'properties' => array(
								'requests' => array(
									'type'    => 'integer',
									'minimum' => 1,
								),
								'window'   => array(
									'type'    => 'integer',
									'minimum' => 60,
								),
							),
							'required'   => array( 'requests', 'window' ),
						),
					),
				),
				'security_policies' => array(
					'type'       => 'object',
					'properties' => array(
						'max_failed_attempts'      => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'lockout_duration'         => array(
							'type'    => 'integer',
							'minimum' => 60,
						),
						'session_timeout'          => array(
							'type'    => 'integer',
							'minimum' => 300,
						),
						'require_strong_passwords' => array( 'type' => 'boolean' ),
						'two_factor_auth'          => array( 'type' => 'boolean' ),
					),
				),
			),
		);
	}
}
