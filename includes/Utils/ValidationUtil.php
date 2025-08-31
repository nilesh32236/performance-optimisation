<?php
/**
 * Enhanced Validation Utility
 *
 * Provides comprehensive input validation, sanitization, and security checks
 * for the Performance Optimisation plugin.
 *
 * @package PerformanceOptimisation\Utils
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enhanced ValidationUtil Class
 *
 * Centralized validation and sanitization with security-first approach
 * and comprehensive input handling.
 *
 * @package PerformanceOptimisation\Utils
 * @since 2.0.0
 */
class ValidationUtil {

	/**
	 * Supported cache types for validation.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	private const VALID_CACHE_TYPES = array(
		'page', 'object', 'minified', 'image', 'database', 'all'
	);

	/**
	 * Supported image formats for validation.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	private const VALID_IMAGE_FORMATS = array(
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'
	);

	/**
	 * Process and validate URL lists.
	 *
	 * @since 2.0.0
	 *
	 * @param string $input URL string with newline separators.
	 * @return array Array of validated URLs.
	 */
	public static function processUrls( string $input ): array {
		$urls = array_filter( array_map( 'trim', explode( "\n", $input ) ) );
		$validated_urls = array();

		foreach ( $urls as $url ) {
			if ( self::isValidUrl( $url ) ) {
				$validated_urls[] = esc_url_raw( $url );
			} else {
				LoggingUtil::warning( 'Invalid URL filtered out during processing', array( 'url' => $url ) );
			}
		}

		return $validated_urls;
	}

	/**
	 * Sanitize file path with security checks.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path File path to sanitize.
	 * @return string Sanitized file path.
	 */
	public static function sanitizeFilePath( string $path ): string {
		// Remove any directory traversal attempts
		$path = preg_replace( '/\.\.+/', '.', $path );
		
		// Remove null bytes
		$path = str_replace( "\0", '', $path );
		
		// Normalize path separators
		$path = wp_normalize_path( $path );
		
		// Remove leading/trailing whitespace
		$path = trim( $path );
		
		return $path;
	}

	/**
	 * Validate image format.
	 *
	 * @since 2.0.0
	 *
	 * @param string $format Image format to validate.
	 * @return bool True if valid format, false otherwise.
	 */
	public static function validateImageFormat( string $format ): bool {
		$format = strtolower( trim( $format ) );
		return in_array( $format, self::VALID_IMAGE_FORMATS, true );
	}

	/**
	 * Sanitize settings array with type-specific validation.
	 *
	 * @since 2.0.0
	 *
	 * @param array $settings Settings array to sanitize.
	 * @param array $schema   Settings schema for validation.
	 * @return array Sanitized settings array.
	 */
	public static function sanitizeSettings( array $settings, array $schema = array() ): array {
		$sanitized = array();

		foreach ( $settings as $key => $value ) {
			$field_type = $schema[ $key ]['type'] ?? 'string';
			$sanitized[ $key ] = self::sanitizeSetting( $value, $field_type );
		}

		return $sanitized;
	}

	/**
	 * Validate cache type.
	 *
	 * @since 2.0.0
	 *
	 * @param string $type Cache type to validate.
	 * @return bool True if valid cache type, false otherwise.
	 */
	public static function validateCacheType( string $type ): bool {
		$type = strtolower( trim( $type ) );
		return in_array( $type, self::VALID_CACHE_TYPES, true );
	}

	/**
	 * Validate URL format.
	 *
	 * @since 2.0.0
	 *
	 * @param string $url URL to validate.
	 * @return bool True if valid URL, false otherwise.
	 */
	public static function isValidUrl( string $url ): bool {
		// Basic URL validation
		if ( false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		// Additional security checks
		$parsed = parse_url( $url );
		
		// Check for valid scheme
		if ( ! isset( $parsed['scheme'] ) || ! in_array( $parsed['scheme'], array( 'http', 'https' ), true ) ) {
			return false;
		}

		// Check for valid host
		if ( ! isset( $parsed['host'] ) || empty( $parsed['host'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Sanitize HTML content with allowed tags.
	 *
	 * @since 2.0.0
	 *
	 * @param string $html    HTML content to sanitize.
	 * @param array  $allowed Allowed HTML tags and attributes.
	 * @return string Sanitized HTML content.
	 */
	public static function sanitizeHtml( string $html, array $allowed = array() ): string {
		if ( empty( $allowed ) ) {
			// Default allowed tags for plugin content
			$allowed = array(
				'p'      => array(),
				'br'     => array(),
				'strong' => array(),
				'em'     => array(),
				'a'      => array( 'href' => array(), 'title' => array() ),
				'ul'     => array(),
				'ol'     => array(),
				'li'     => array(),
			);
		}

		return wp_kses( $html, $allowed );
	}

	/**
	 * Validate numeric range.
	 *
	 * @since 2.0.0
	 *
	 * @param int $value Value to validate.
	 * @param int $min   Minimum allowed value.
	 * @param int $max   Maximum allowed value.
	 * @return int Validated value within range.
	 */
	public static function validateNumericRange( int $value, int $min, int $max ): int {
		return max( $min, min( $max, $value ) );
	}

	/**
	 * Validate email address.
	 *
	 * @since 2.0.0
	 *
	 * @param string $email Email address to validate.
	 * @return bool True if valid email, false otherwise.
	 */
	public static function validateEmail( string $email ): bool {
		return is_email( $email ) !== false;
	}

	/**
	 * Sanitize array of strings.
	 *
	 * @since 2.0.0
	 *
	 * @param array $array Array to sanitize.
	 * @return array Sanitized array.
	 */
	public static function sanitizeStringArray( array $array ): array {
		return array_map( 'sanitize_text_field', $array );
	}

	/**
	 * Validate file extension against allowed list.
	 *
	 * @since 2.0.0
	 *
	 * @param string $filename        File name or path.
	 * @param array  $allowed_extensions Allowed file extensions.
	 * @return bool True if extension is allowed, false otherwise.
	 */
	public static function validateFileExtension( string $filename, array $allowed_extensions ): bool {
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		return in_array( $extension, array_map( 'strtolower', $allowed_extensions ), true );
	}

	/**
	 * Sanitize CSS content.
	 *
	 * @since 2.0.0
	 *
	 * @param string $css CSS content to sanitize.
	 * @return string Sanitized CSS content.
	 */
	public static function sanitizeCss( string $css ): string {
		// Remove potentially dangerous CSS
		$css = preg_replace( '/javascript\s*:/i', '', $css );
		$css = preg_replace( '/expression\s*\(/i', '', $css );
		$css = preg_replace( '/@import/i', '', $css );
		
		return $css;
	}

	/**
	 * Validate JSON string.
	 *
	 * @since 2.0.0
	 *
	 * @param string $json JSON string to validate.
	 * @return bool True if valid JSON, false otherwise.
	 */
	public static function validateJson( string $json ): bool {
		json_decode( $json );
		return json_last_error() === JSON_ERROR_NONE;
	}

	/**
	 * Sanitize database table name.
	 *
	 * @since 2.0.0
	 *
	 * @param string $table_name Table name to sanitize.
	 * @return string Sanitized table name.
	 */
	public static function sanitizeTableName( string $table_name ): string {
		// Only allow alphanumeric characters and underscores
		return preg_replace( '/[^a-zA-Z0-9_]/', '', $table_name );
	}

	/**
	 * Validate WordPress capability.
	 *
	 * @since 2.0.0
	 *
	 * @param string $capability Capability to validate.
	 * @return bool True if user has capability, false otherwise.
	 */
	public static function validateCapability( string $capability ): bool {
		return current_user_can( $capability );
	}

	/**
	 * Validate WordPress nonce.
	 *
	 * @since 2.0.0
	 *
	 * @param string $nonce  Nonce value to validate.
	 * @param string $action Action associated with nonce.
	 * @return bool True if valid nonce, false otherwise.
	 */
	public static function validateNonce( string $nonce, string $action ): bool {
		return wp_verify_nonce( $nonce, $action ) !== false;
	}

	/**
	 * Escape output for different contexts.
	 *
	 * @since 2.0.0
	 *
	 * @param string $output  Output to escape.
	 * @param string $context Context for escaping (html, attr, js, url, css).
	 * @return string Escaped output.
	 */
	public static function escapeOutput( string $output, string $context = 'html' ): string {
		switch ( $context ) {
			case 'js':
				return esc_js( $output );
			case 'attr':
				return esc_attr( $output );
			case 'url':
				return esc_url( $output );
			case 'css':
				return self::sanitizeCss( $output );
			case 'textarea':
				return esc_textarea( $output );
			default:
				return esc_html( $output );
		}
	}

	/**
	 * Sanitize individual setting based on type.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed  $value Setting value to sanitize.
	 * @param string $type  Setting type.
	 * @return mixed Sanitized setting value.
	 */
	public static function sanitizeSetting( $value, string $type ) {
		switch ( $type ) {
			case 'string':
				return sanitize_text_field( $value );
				
			case 'textarea':
				return sanitize_textarea_field( $value );
				
			case 'int':
			case 'integer':
				return intval( $value );
				
			case 'float':
				return floatval( $value );
				
			case 'bool':
			case 'boolean':
				return rest_sanitize_boolean( $value );
				
			case 'url':
				return esc_url_raw( $value );
				
			case 'url_list':
				return implode( "\n", self::processUrls( $value ) );
				
			case 'email':
				return sanitize_email( $value );
				
			case 'array':
				return is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : array();
				
			case 'json':
				return self::validateJson( $value ) ? $value : '{}';
				
			case 'css':
				return self::sanitizeCss( $value );
				
			case 'html':
				return self::sanitizeHtml( $value );
				
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Validate plugin settings schema.
	 *
	 * @since 2.0.0
	 *
	 * @param array $settings Settings to validate.
	 * @param array $schema   Expected schema.
	 * @return array Validation results with errors.
	 */
	public static function validateSettingsSchema( array $settings, array $schema ): array {
		$errors = array();
		$validated = array();

		foreach ( $schema as $field => $rules ) {
			$value = $settings[ $field ] ?? $rules['default'] ?? null;
			
			// Required field check
			if ( ! empty( $rules['required'] ) && ( null === $value || '' === $value ) ) {
				$errors[ $field ] = 'Field is required';
				continue;
			}

			// Type validation
			if ( null !== $value && isset( $rules['type'] ) ) {
				$sanitized_value = self::sanitizeSetting( $value, $rules['type'] );
				
				// Additional validation rules
				if ( isset( $rules['min'] ) && is_numeric( $sanitized_value ) && $sanitized_value < $rules['min'] ) {
					$errors[ $field ] = "Value must be at least {$rules['min']}";
					continue;
				}
				
				if ( isset( $rules['max'] ) && is_numeric( $sanitized_value ) && $sanitized_value > $rules['max'] ) {
					$errors[ $field ] = "Value must not exceed {$rules['max']}";
					continue;
				}
				
				if ( isset( $rules['options'] ) && ! in_array( $sanitized_value, $rules['options'], true ) ) {
					$errors[ $field ] = 'Invalid option selected';
					continue;
				}

				$validated[ $field ] = $sanitized_value;
			}
		}

		return array(
			'valid' => empty( $errors ),
			'errors' => $errors,
			'data' => $validated,
		);
	}

	/**
	 * Validate AJAX request.
	 *
	 * @since 2.0.0
	 *
	 * @param string $action     AJAX action name.
	 * @param string $capability Required capability.
	 * @param bool   $check_nonce Whether to check nonce.
	 * @return bool True if valid request, false otherwise.
	 */
	public static function validateAjaxRequest( string $action, string $capability = 'manage_options', bool $check_nonce = true ): bool {
		// Check if it's an AJAX request
		if ( ! wp_doing_ajax() ) {
			return false;
		}

		// Check user capability
		if ( ! self::validateCapability( $capability ) ) {
			return false;
		}

		// Check nonce if required
		if ( $check_nonce ) {
			$nonce = $_POST['nonce'] ?? $_GET['nonce'] ?? '';
			if ( ! self::validateNonce( $nonce, $action ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Sanitize and validate file upload.
	 *
	 * @since 2.0.0
	 *
	 * @param array $file            File upload array from $_FILES.
	 * @param array $allowed_types   Allowed MIME types.
	 * @param int   $max_size        Maximum file size in bytes.
	 * @return array Validation result with file info or errors.
	 */
	public static function validateFileUpload( array $file, array $allowed_types = array(), int $max_size = 1048576 ): array {
		// Check for upload errors
		if ( $file['error'] !== UPLOAD_ERR_OK ) {
			return array(
				'valid' => false,
				'error' => 'File upload error: ' . $file['error'],
			);
		}

		// Check file size
		if ( $file['size'] > $max_size ) {
			return array(
				'valid' => false,
				'error' => 'File size exceeds maximum allowed size',
			);
		}

		// Check MIME type
		$file_type = wp_check_filetype( $file['name'] );
		if ( ! empty( $allowed_types ) && ! in_array( $file_type['type'], $allowed_types, true ) ) {
			return array(
				'valid' => false,
				'error' => 'File type not allowed',
			);
		}

		return array(
			'valid' => true,
			'file' => array(
				'name' => sanitize_file_name( $file['name'] ),
				'type' => $file_type['type'],
				'size' => $file['size'],
				'tmp_name' => $file['tmp_name'],
			),
		);
	}
}
