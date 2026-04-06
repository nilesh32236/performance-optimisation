<?php
/**
 * Htaccess_Handler class for the PerformanceOptimise plugin.
 *
 * Handles the generation and insertion of .htaccess rules for performance optimization.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.2.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Htaccess_Handler' ) ) {
	/**
	 * Class Htaccess_Handler
	 *
	 * Manages .htaccess rules for Gzip and Browser Caching.
	 *
	 * @since 1.2.0
	 */
	class Htaccess_Handler {

		/**
		 * The marker used for .htaccess rules.
		 *
		 * @var string
		 * @since 1.2.0
		 */
		private const MARKER = 'wppo_rules';

		/**
		 * Updates the .htaccess rules based on plugin settings.
		 *
		 * @param bool $enable Whether to enable or disable the rules.
		 * @return bool True on success, false on failure.
		 * @since 1.2.0
		 */
		public static function update_rules( bool $enable = true ): bool {
			if ( ! function_exists( 'insert_with_markers' ) ) {
				require_once wp_normalize_path( ABSPATH . 'wp-admin/includes/misc.php' );
			}

			if ( ! function_exists( 'get_home_path' ) ) {
				require_once wp_normalize_path( ABSPATH . 'wp-admin/includes/file.php' );
			}

			$htaccess_file = wp_normalize_path( get_home_path() . '.htaccess' );

			$wp_filesystem = Util::init_filesystem();

			if ( ! $wp_filesystem->exists( $htaccess_file ) && ! $wp_filesystem->is_writable( dirname( $htaccess_file ) ) ) {
				return false;
			}

			if ( $wp_filesystem->exists( $htaccess_file ) && ! $wp_filesystem->is_writable( $htaccess_file ) ) {
				return false;
			}

			$rules = array();

			if ( $enable ) {
				$rules = self::get_rules();
			}

			return insert_with_markers( $htaccess_file, self::MARKER, $rules );
		}

		/**
		 * Generates the performance optimization rules for .htaccess.
		 *
		 * @return array The list of rules.
		 * @since 1.2.0
		 */
		private static function get_rules(): array {
			return array(
				'<IfModule mod_deflate.c>',
				'    # Compress HTML, CSS, JavaScript, Text, XML, and Fonts',
				'    AddOutputFilterByType DEFLATE text/plain',
				'    AddOutputFilterByType DEFLATE text/html',
				'    AddOutputFilterByType DEFLATE text/xml',
				'    AddOutputFilterByType DEFLATE text/css',
				'    AddOutputFilterByType DEFLATE text/javascript',
				'    AddOutputFilterByType DEFLATE application/xml',
				'    AddOutputFilterByType DEFLATE application/xhtml+xml',
				'    AddOutputFilterByType DEFLATE application/rss+xml',
				'    AddOutputFilterByType DEFLATE application/javascript',
				'    AddOutputFilterByType DEFLATE application/x-javascript',
				'    AddOutputFilterByType DEFLATE application/x-font-ttf',
				'    AddOutputFilterByType DEFLATE application/vnd.ms-fontobject',
				'    AddOutputFilterByType DEFLATE font/opentype',
				'    AddOutputFilterByType DEFLATE font/truetype',
				'    AddOutputFilterByType DEFLATE font/eot',
				'    AddOutputFilterByType DEFLATE font/otf',
				'    AddOutputFilterByType DEFLATE image/svg+xml',
				'    AddOutputFilterByType DEFLATE image/x-icon',
				'</IfModule>',
				'',
				'<IfModule mod_expires.c>',
				'    ExpiresActive On',
				'    # Default cache',
				'    ExpiresDefault "access plus 2 days"',
				'    # Dynamic items',
				'    ExpiresByType text/html "access plus 0 seconds"',
				'    # CSS and JS',
				'    ExpiresByType text/css "access plus 1 month"',
				'    ExpiresByType application/javascript "access plus 1 month"',
				'    ExpiresByType application/x-javascript "access plus 1 month"',
				'    # Images and Icons',
				'    ExpiresByType image/jpg "access plus 1 month"',
				'    ExpiresByType image/jpeg "access plus 1 month"',
				'    ExpiresByType image/gif "access plus 1 month"',
				'    ExpiresByType image/png "access plus 1 month"',
				'    ExpiresByType image/svg+xml "access plus 1 month"',
				'    ExpiresByType image/x-icon "access plus 1 month"',
				'    # Fonts',
				'    ExpiresByType application/vnd.ms-fontobject "access plus 1 month"',
				'    ExpiresByType application/x-font-ttf "access plus 1 month"',
				'    ExpiresByType font/opentype "access plus 1 month"',
				'    ExpiresByType font/eot "access plus 1 month"',
				'    ExpiresByType font/otf "access plus 1 month"',
				'</IfModule>',
			);
		}
	}
}
