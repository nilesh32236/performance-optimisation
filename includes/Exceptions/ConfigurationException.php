<?php
/**
 * Configuration Exception
 *
 * Exception thrown when configuration validation or operations fail.
 *
 * @package PerformanceOptimisation\Exceptions
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Exceptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configuration Exception Class
 */
class ConfigurationException extends \Exception {

	/**
	 * Validation errors.
	 *
	 * @var array
	 */
	private array $validation_errors = array();

	/**
	 * Configuration key that caused the error.
	 *
	 * @var string|null
	 */
	private ?string $config_key = null;

	/**
	 * Constructor.
	 *
	 * @param string         $message           Exception message.
	 * @param int            $code              Exception code.
	 * @param \Throwable|null $previous          Previous exception.
	 * @param array          $validation_errors Validation errors.
	 * @param string|null    $config_key        Configuration key.
	 */
	public function __construct( 
		string $message = '', 
		int $code = 0, 
		?\Throwable $previous = null,
		array $validation_errors = array(),
		?string $config_key = null
	) {
		parent::__construct( $message, $code, $previous );
		$this->validation_errors = $validation_errors;
		$this->config_key = $config_key;
	}

	/**
	 * Get validation errors.
	 *
	 * @return array Validation errors.
	 */
	public function getValidationErrors(): array {
		return $this->validation_errors;
	}

	/**
	 * Get configuration key.
	 *
	 * @return string|null Configuration key.
	 */
	public function getConfigKey(): ?string {
		return $this->config_key;
	}

	/**
	 * Check if exception has validation errors.
	 *
	 * @return bool True if has validation errors, false otherwise.
	 */
	public function hasValidationErrors(): bool {
		return ! empty( $this->validation_errors );
	}

	/**
	 * Get formatted error message with validation details.
	 *
	 * @return string Formatted error message.
	 */
	public function getFormattedMessage(): string {
		$message = $this->getMessage();
		
		if ( $this->config_key ) {
			$message = "Configuration key '{$this->config_key}': {$message}";
		}
		
		if ( ! empty( $this->validation_errors ) ) {
			$message .= "\nValidation errors:\n- " . implode( "\n- ", $this->validation_errors );
		}
		
		return $message;
	}

	/**
	 * Convert to array for API responses.
	 *
	 * @return array Exception data as array.
	 */
	public function toArray(): array {
		return array(
			'message' => $this->getMessage(),
			'code' => $this->getCode(),
			'config_key' => $this->config_key,
			'validation_errors' => $this->validation_errors,
			'file' => $this->getFile(),
			'line' => $this->getLine(),
		);
	}
}