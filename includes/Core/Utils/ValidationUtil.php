<?php
/**
 * Input Validation Utility
 *
 * @package PerformanceOptimisation\Core\Utils
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Core\Utils;

/**
 * Class ValidationUtil
 *
 * Provides input validation and sanitization utilities.
 *
 * @package PerformanceOptimisation\Core\Utils
 * @since 2.0.0
 */
class ValidationUtil {

	/**
	 * Validate and sanitize text input.
	 *
	 * @param string $input      Input text to sanitize.
	 * @param int    $max_length Maximum length.
	 * @return string Sanitized text.
	 */
	public static function sanitize_text( string $input, int $max_length = 255 ): string {
		$sanitized = sanitize_text_field( $input );
		return substr( $sanitized, 0, $max_length );
	}

	/**
	 * Validate numeric input with bounds.
	 *
	 * @param mixed $value Value to validate.
	 * @param int   $min   Minimum value.
	 * @param int   $max   Maximum value.
	 * @return int Validated number.
	 * @throws \InvalidArgumentException If number is out of bounds.
	 */
	public static function validate_number( $value, int $min = 0, int $max = PHP_INT_MAX ): int {
		$num = intval( $value );
		if ( $num < $min || $num > $max ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \InvalidArgumentException( "Number must be between {$min} and {$max}" );
		}
		return $num;
	}

	/**
	 * Validate URL input.
	 *
	 * @param string $url URL to validate.
	 * @return string Validated URL.
	 * @throws \InvalidArgumentException If URL is invalid.
	 */
	public static function validate_url( string $url ): string {
		$sanitized = esc_url_raw( $url );
		if ( ! filter_var( $sanitized, FILTER_VALIDATE_URL ) ) {
			throw new \InvalidArgumentException( 'Invalid URL format' );
		}
		return $sanitized;
	}

	/**
	 * Validate file path for security.
	 *
	 * @param string $path File path to validate.
	 * @return string Validated file path.
	 * @throws \InvalidArgumentException If path is invalid.
	 */
	public static function validate_file_path( string $path ): string {
		$real_path       = realpath( $path );
		$wp_content_real = realpath( WP_CONTENT_DIR );

		if ( ! $real_path || strpos( $real_path, $wp_content_real ) !== 0 ) {
			throw new \InvalidArgumentException( 'Invalid file path' );
		}

		return $real_path;
	}

	/**
	 * Validate array of allowed values.
	 *
	 * @param mixed $value   Value to validate.
	 * @param array $allowed Allowed values.
	 * @return string Validated value.
	 * @throws \InvalidArgumentException If value not in allowed list.
	 */
	public static function validate_choice( $value, array $allowed ): string {
		if ( ! in_array( $value, $allowed, true ) ) {
			throw new \InvalidArgumentException( 'Invalid choice' );
		}
		return $value;
	}
}
