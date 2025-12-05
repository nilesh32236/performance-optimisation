<?php
/**
 * Security Service
 *
 * @package PerformanceOptimisation\Services
 * @since   2.1.0
 */

namespace PerformanceOptimisation\Services;

use PerformanceOptimisation\Utils\LoggingUtil;

/**
 * Security Service Class
 */
class SecurityService {

	private array $security_headers = array(
		'X-Content-Type-Options' => 'nosniff',
		'X-Frame-Options'        => 'SAMEORIGIN',
		'X-XSS-Protection'       => '1; mode=block',
		'Referrer-Policy'        => 'strict-origin-when-cross-origin',
	);

	public function __construct() {
		$this->initializeSecurityMeasures();
	}

	private function initializeSecurityMeasures(): void {
		add_action( 'init', array( $this, 'setupSecurityHeaders' ) );
		add_action( 'wp_loaded', array( $this, 'setupSecurityFilters' ) );
		add_filter( 'wp_headers', array( $this, 'addSecurityHeaders' ) );
	}

	public function setupSecurityHeaders(): void {
		if ( ! headers_sent() ) {
			foreach ( $this->security_headers as $header => $value ) {
				header( "{$header}: {$value}" );
			}
		}
	}

	public function addSecurityHeaders( array $headers ): array {
		return array_merge( $headers, $this->security_headers );
	}

	public function setupSecurityFilters(): void {
		// Remove WordPress version from various places
		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'the_generator', '__return_empty_string' );

		// Remove version from scripts and styles
		add_filter( 'style_loader_src', array( $this, 'removeVersionFromAssets' ), 15 );
		add_filter( 'script_loader_src', array( $this, 'removeVersionFromAssets' ), 15 );

		// Disable file editing
		if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			define( 'DISALLOW_FILE_EDIT', true );
		}

		// Remove unnecessary meta tags
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
	}

	public function removeVersionFromAssets( string $src ): string {
		if ( strpos( $src, 'ver=' ) ) {
			$src = remove_query_arg( 'ver', $src );
		}
		return $src;
	}

	public function validateNonce( string $action, string $nonce_field = '_wpnonce' ): bool {
		$nonce = sanitize_text_field( wp_unslash( $_REQUEST[ $nonce_field ] ?? '' ) );
		return wp_verify_nonce( $nonce, $action );
	}

	public function sanitizeInput( array $data ): array {
		return array_map( array( $this, 'sanitizeValue' ), $data );
	}

	private function sanitizeValue( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( $this, 'sanitizeValue' ), $value );
		}

		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}

		return $value;
	}

	public function checkPermissions( string $capability = 'manage_options' ): bool {
		return current_user_can( $capability );
	}

	public function logSecurityEvent( string $event, array $context = array() ): void {
		LoggingUtil::warning(
			"Security event: {$event}",
			array_merge(
				$context,
				array(
					'user_id'    => get_current_user_id(),
					'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
					'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
				)
			)
		);
	}
}
