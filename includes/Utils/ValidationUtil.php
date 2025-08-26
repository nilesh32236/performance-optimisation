<?php
/**
 * Validation Utility
 *
 * @package PerformanceOptimisation\Utils
 * @since   2.0.0
 */

namespace PerformanceOptimisation\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ValidationUtil
 *
 * @package PerformanceOptimisation\Utils
 */
class ValidationUtil {

	public static function validateUrl( string $url ): bool {
		return filter_var( $url, FILTER_VALIDATE_URL ) !== false;
	}

	public static function sanitize_setting( $value, string $type ) {
		switch ( $type ) {
			case 'string':
				return sanitize_text_field( $value );
			case 'int':
				return intval( $value );
			case 'bool':
				return rest_sanitize_boolean( $value );
			case 'url_list':
				return implode( "\n", array_map( 'esc_url_raw', explode( "\n", $value ) ) );
			default:
				return sanitize_text_field( $value );
		}
	}

	public static function validateNonce( string $nonce, string $action ): bool {
		return wp_verify_nonce( $nonce, $action );
	}

	public static function validateCapability( string $capability ): bool {
		return current_user_can( $capability );
	}

	public static function escapeOutput( string $output, string $context = 'html' ): string {
		switch ( $context ) {
			case 'js':
				return esc_js( $output );
			case 'attr':
				return esc_attr( $output );
			case 'url':
				return esc_url( $output );
			default:
				return esc_html( $output );
		}
	}
}