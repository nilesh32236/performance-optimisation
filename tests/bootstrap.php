<?php
/**
 * PHPUnit Bootstrap File
 *
 * @package PerformanceOptimisation\Tests
 * @since   2.0.0
 */

// Load Composer autoloader
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Define test constants
define( 'WPPO_TESTS_DIR', __DIR__ );
define( 'WPPO_PLUGIN_DIR', dirname( __DIR__ ) );

// Mock WordPress functions for unit tests
if ( ! function_exists( 'wp_normalize_path' ) ) {
	/**
	 * Mock wp_normalize_path function.
	 *
	 * @param string $path Path to normalize.
	 * @return string Normalized path.
	 */
	function wp_normalize_path( string $path ): string {
		return str_replace( '\\', '/', $path );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Mock esc_html function.
	 *
	 * @param string $text Text to escape.
	 * @return string Escaped text.
	 */
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Mock esc_url function.
	 *
	 * @param string $url URL to escape.
	 * @return string Escaped URL.
	 */
	function esc_url( string $url ): string {
		return filter_var( $url, FILTER_SANITIZE_URL );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Mock translation function.
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 * @return string Translated text.
	 */
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Mock escaped translation function.
	 *
	 * @param string $text   Text to translate and escape.
	 * @param string $domain Text domain.
	 * @return string Translated and escaped text.
	 */
	function esc_html__( string $text, string $domain = 'default' ): string {
		return esc_html( __( $text, $domain ) );
	}
}
