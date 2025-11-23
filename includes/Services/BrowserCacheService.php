<?php
/**
 * Browser Cache Service
 *
 * @package PerformanceOptimisation\Services
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Services;

use PerformanceOptimisation\Utils\LoggingUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BrowserCacheService
 */
class BrowserCacheService {

	private SettingsService $settings;
	private LoggingUtil $logger;
	private array $cache_rules;

	public function __construct( SettingsService $settings, LoggingUtil $logger ) {
		$this->settings = $settings;
		$this->logger = $logger;
		$this->cache_rules = $this->get_default_cache_rules();
		$this->setup_hooks();
	}

	/**
	 * Setup WordPress hooks
	 */
	private function setup_hooks(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		add_action( 'send_headers', array( $this, 'add_cache_headers' ) );
		add_filter( 'mod_rewrite_rules', array( $this, 'add_htaccess_rules' ) );
	}

	/**
	 * Check if browser cache is enabled
	 */
	private function is_enabled(): bool {
		return (bool) $this->settings->get_setting( 'cache_settings', 'browser_cache_enabled', false );
	}

	/**
	 * Get default cache rules for different file types
	 */
	private function get_default_cache_rules(): array {
		return array(
			'images' => array(
				'extensions' => array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'ico', 'svg' ),
				'max_age' => YEAR_IN_SECONDS,
				'public' => true,
			),
			'fonts' => array(
				'extensions' => array( 'woff', 'woff2', 'ttf', 'otf', 'eot' ),
				'max_age' => YEAR_IN_SECONDS,
				'public' => true,
			),
			'css' => array(
				'extensions' => array( 'css' ),
				'max_age' => MONTH_IN_SECONDS,
				'public' => true,
			),
			'js' => array(
				'extensions' => array( 'js' ),
				'max_age' => MONTH_IN_SECONDS,
				'public' => true,
			),
			'media' => array(
				'extensions' => array( 'mp4', 'webm', 'ogg', 'mp3', 'wav' ),
				'max_age' => YEAR_IN_SECONDS,
				'public' => true,
			),
			'documents' => array(
				'extensions' => array( 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx' ),
				'max_age' => WEEK_IN_SECONDS,
				'public' => true,
			),
		);
	}

	/**
	 * Add cache headers to current request
	 */
	public function add_cache_headers(): void {
		if ( is_admin() || is_user_logged_in() ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$extension = pathinfo( $request_uri, PATHINFO_EXTENSION );

		if ( empty( $extension ) ) {
			return;
		}

		$rule = $this->get_cache_rule_for_extension( $extension );
		
		if ( ! $rule ) {
			return;
		}

		$this->set_cache_headers( $rule['max_age'], $rule['public'] );
		
		$this->logger->debug( 'Browser cache headers added', array(
			'extension' => $extension,
			'max_age' => $rule['max_age'],
		) );
	}

	/**
	 * Get cache rule for file extension
	 */
	private function get_cache_rule_for_extension( string $extension ): ?array {
		foreach ( $this->cache_rules as $rule ) {
			if ( in_array( strtolower( $extension ), $rule['extensions'], true ) ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Set cache headers
	 */
	private function set_cache_headers( int $max_age, bool $public = true ): void {
		if ( headers_sent() ) {
			return;
		}

		$visibility = $public ? 'public' : 'private';
		
		header( "Cache-Control: {$visibility}, max-age={$max_age}, immutable" );
		header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + $max_age ) . ' GMT' );
		header( 'Pragma: public' );
		
		// Add ETag for validation
		$etag = md5( $max_age . $visibility );
		header( "ETag: \"{$etag}\"" );
	}

	/**
	 * Add .htaccess rules for Apache
	 */
	public function add_htaccess_rules( string $rules ): string {
		if ( ! $this->is_enabled() ) {
			return $rules;
		}

		$cache_rules = $this->generate_htaccess_rules();
		return $cache_rules . "\n" . $rules;
	}

	/**
	 * Generate .htaccess rules
	 */
	private function generate_htaccess_rules(): string {
		$rules = array();
		$rules[] = '# BEGIN Performance Optimisation Browser Cache';
		$rules[] = '<IfModule mod_expires.c>';
		$rules[] = '  ExpiresActive On';
		$rules[] = '  ExpiresDefault "access plus 1 month"';
		$rules[] = '';
		
		foreach ( $this->cache_rules as $type => $rule ) {
			$extensions = implode( '|', $rule['extensions'] );
			$max_age = $rule['max_age'];
			
			$rules[] = "  # {$type}";
			$rules[] = "  <FilesMatch \"\\.({$extensions})$\">";
			$rules[] = "    ExpiresDefault \"access plus {$max_age} seconds\"";
			$rules[] = "    Header set Cache-Control \"public, max-age={$max_age}, immutable\"";
			$rules[] = '  </FilesMatch>';
			$rules[] = '';
		}
		
		$rules[] = '</IfModule>';
		$rules[] = '';
		$rules[] = '<IfModule mod_headers.c>';
		$rules[] = '  # Remove ETags (we use Cache-Control instead)';
		$rules[] = '  Header unset ETag';
		$rules[] = '  FileETag None';
		$rules[] = '</IfModule>';
		$rules[] = '# END Performance Optimisation Browser Cache';
		
		return implode( "\n", $rules );
	}

	/**
	 * Write .htaccess rules
	 */
	public function write_htaccess_rules(): bool {
		$htaccess_file = ABSPATH . '.htaccess';
		
		if ( ! file_exists( $htaccess_file ) || ! is_writable( $htaccess_file ) ) {
			$this->logger->warning( '.htaccess file not writable', array( 'file' => $htaccess_file ) );
			return false;
		}

		$content = file_get_contents( $htaccess_file );
		
		// Remove old rules if they exist
		$content = preg_replace(
			'/# BEGIN Performance Optimisation Browser Cache.*?# END Performance Optimisation Browser Cache\n?/s',
			'',
			$content
		);

		// Add new rules at the top
		$new_content = $this->generate_htaccess_rules() . "\n\n" . $content;
		
		if ( file_put_contents( $htaccess_file, $new_content ) ) {
			$this->logger->info( 'Browser cache rules written to .htaccess' );
			return true;
		}

		return false;
	}

	/**
	 * Remove .htaccess rules
	 */
	public function remove_htaccess_rules(): bool {
		$htaccess_file = ABSPATH . '.htaccess';
		
		if ( ! file_exists( $htaccess_file ) || ! is_writable( $htaccess_file ) ) {
			return false;
		}

		$content = file_get_contents( $htaccess_file );
		
		$new_content = preg_replace(
			'/# BEGIN Performance Optimisation Browser Cache.*?# END Performance Optimisation Browser Cache\n?/s',
			'',
			$content
		);

		if ( $new_content !== $content ) {
			file_put_contents( $htaccess_file, $new_content );
			$this->logger->info( 'Browser cache rules removed from .htaccess' );
			return true;
		}

		return false;
	}

	/**
	 * Enable browser cache
	 */
	public function enable(): bool {
		$result = $this->write_htaccess_rules();
		
		if ( $result ) {
			$this->logger->info( 'Browser cache enabled' );
		}
		
		return $result;
	}

	/**
	 * Disable browser cache
	 */
	public function disable(): bool {
		$result = $this->remove_htaccess_rules();
		
		if ( $result ) {
			$this->logger->info( 'Browser cache disabled' );
		}
		
		return $result;
	}

	/**
	 * Get cache statistics
	 */
	public function get_stats(): array {
		return array(
			'enabled' => $this->is_enabled(),
			'rules_count' => count( $this->cache_rules ),
			'htaccess_writable' => is_writable( ABSPATH . '.htaccess' ),
		);
	}
}
