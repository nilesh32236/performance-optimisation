<?php
/**
 * Input Validation Utility
 *
 * @package PerformanceOptimisation\Core\Utils
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Core\Utils;

class ValidationUtil {

	/**
	 * Validate and sanitize text input
	 */
	public static function sanitize_text( string $input, int $max_length = 255 ): string {
		$sanitized = sanitize_text_field( $input );
		return substr( $sanitized, 0, $max_length );
	}

	/**
	 * Validate numeric input with bounds
	 */
	public static function validate_number( $value, int $min = 0, int $max = PHP_INT_MAX ): int {
		$num = intval( $value );
		if ( $num < $min || $num > $max ) {
			throw new \InvalidArgumentException( "Number must be between {$min} and {$max}" );
		}
		return $num;
	}

	/**
	 * Validate URL input
	 */
	public static function validate_url( string $url ): string {
		$sanitized = esc_url_raw( $url );
		if ( ! filter_var( $sanitized, FILTER_VALIDATE_URL ) ) {
			throw new \InvalidArgumentException( 'Invalid URL format' );
		}
		return $sanitized;
	}

	/**
	 * Validate file path for security
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
	 * Validate array of allowed values
	 */
	public static function validate_choice( $value, array $allowed ): string {
		if ( ! in_array( $value, $allowed, true ) ) {
			throw new \InvalidArgumentException( 'Invalid choice' );
		}
		return $value;
	}
}
