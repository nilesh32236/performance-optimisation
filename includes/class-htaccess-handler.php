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
		 * Note on LiteSpeed ordering: `# BEGIN LSCACHE` must stay above
		 * `# BEGIN WordPress` and `# BEGIN wppo_rules`. This method uses
		 * `insert_with_markers()` with the `wppo_rules` marker only, so it
		 * never reorders the LSCACHE block — even on LiteSpeed hosts where
		 * this method is now called. Do not call `insert_with_markers()`
		 * with the `wordpress` marker or otherwise reorder markers.
		 *
		 * LiteSpeed (and OpenLiteSpeed) reads `.htaccess` like Apache. On
		 * OpenLiteSpeed a restart (`systemctl restart lsws`) is required
		 * after changes for the new rules to take effect; LSWS reloads live.
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

			if ( ! $wp_filesystem ) {
				return false;
			}

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
		 * Retrieve the rules to be added to .htaccess.
		 *
		 * Includes LiteSpeed/Apache next-gen Vary:Accept rewrite (LS-401)
		 * when enableNextGenRewrite is true, LiteSpeed is detected, and
		 * convertImg is enabled. Opt-in default false. Filterable via
		 * wppo_litespeed_nextgen_rewrite.
		 *
		 * @return array Array of rules.
		 * @since  1.0.0
		 */
		public static function get_rules(): array {
			$rules = array(
				'<IfModule mod_deflate.c>',
				'    # Compress HTML, CSS, JavaScript, Text, XML, and Fonts',
				'    # NOTE: WOFF2 is omitted intentionally — WOFF2 files are already pre-compressed (Brotli)',
				'    # at the binary level, so DEFLATE would add no benefit (and can bloat output).',
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
				'    AddOutputFilterByType DEFLATE application/x-font-otf',
				'    AddOutputFilterByType DEFLATE application/x-font-opentype',
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
				'    ExpiresByType text/css "access plus 1 year"',
				'    ExpiresByType text/javascript "access plus 1 year"',
				'    ExpiresByType application/javascript "access plus 1 year"',
				'    ExpiresByType application/x-javascript "access plus 1 year"',
				'    # Images and Icons',
				'    ExpiresByType image/jpg "access plus 1 year"',
				'    ExpiresByType image/jpeg "access plus 1 year"',
				'    ExpiresByType image/gif "access plus 1 year"',
				'    ExpiresByType image/png "access plus 1 year"',
				'    ExpiresByType image/webp "access plus 1 year"',
				'    ExpiresByType image/avif "access plus 1 year"',
				'    ExpiresByType image/svg+xml "access plus 1 year"',
				'    ExpiresByType image/x-icon "access plus 1 year"',
				'    # Fonts',
				'    ExpiresByType application/vnd.ms-fontobject "access plus 1 year"',
				'    ExpiresByType application/x-font-ttf "access plus 1 year"',
				'    ExpiresByType application/font-woff2 "access plus 1 year"',
				'    ExpiresByType font/opentype "access plus 1 year"',
				'    ExpiresByType font/truetype "access plus 1 year"',
				'    ExpiresByType font/eot "access plus 1 year"',
				'    ExpiresByType font/otf "access plus 1 year"',
				'    ExpiresByType font/woff "access plus 1 year"',
				'    ExpiresByType font/woff2 "access plus 1 year"',
				'</IfModule>',
			);

			// LS-401: Next-gen Vary:Accept rewrite — sibling .webp/.avif.
			$use_nextgen = false;
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'is_nextgen_rewrite_enabled' ) ) {
				$use_nextgen = LiteSpeed_Integration::is_nextgen_rewrite_enabled();
			} else {
				// Fallback when LiteSpeed_Integration not loaded: raw option check with filters.
				$opts        = Util::get_settings();
				$enabled     = ! empty( $opts['litespeed_integration']['enableNextGenRewrite'] );
				$convert     = ! empty( $opts['image_optimisation']['convertImg'] );
				$is_ls       = class_exists( 'PerformanceOptimise\Inc\Server_Rules' ) && method_exists( 'PerformanceOptimise\Inc\Server_Rules', 'is_litespeed' ) ? Server_Rules::is_litespeed() : false;
				$use_nextgen = $enabled && $convert && $is_ls;
				/**
				 * Filter whether next-gen rewrite is enabled (fallback).
				 *
				 * @since NEXT
				 * @param bool $use_nextgen Whether next-gen rewrite is enabled.
				 */
				$use_nextgen = (bool) apply_filters( 'wppo_litespeed_nextgen_rewrite', $use_nextgen );
			}

			if ( $use_nextgen ) {
				$rules = array_merge(
					$rules,
					array(
						'',
						'# WPPO Next-gen delivery (LiteSpeed/Apache) — sibling .webp/.avif',
						'<IfModule mod_rewrite.c>',
						'    RewriteEngine On',
						'    # Serve .webp when client supports it and file exists',
						'    RewriteCond %{HTTP:Accept} image/webp',
						'    RewriteCond %{REQUEST_FILENAME}.webp -f',
						'    RewriteRule ^(.+)\.(jpe?g|png)$ $1.webp [T=image/webp,E=accept:1]',
						'    # Serve .avif when client prefers it (prefer AVIF over WebP — order after)',
						'    RewriteCond %{HTTP:Accept} image/avif',
						'    RewriteCond %{REQUEST_FILENAME}.avif -f',
						'    RewriteRule ^(.+)\.(jpe?g|png)$ $1.avif [T=image/avif,E=accept:1]',
						'</IfModule>',
						'<IfModule mod_headers.c>',
						'    Header append Vary Accept env=accept',
						'</IfModule>',
						'AddType image/webp .webp',
						'AddType image/avif .avif',
					)
				);

				/**
				 * Filter the next-gen htaccess block.
				 *
				 * @since NEXT
				 * @param bool $use_nextgen Whether next-gen block was added.
				 */
				$rules = (array) apply_filters( 'wppo_htaccess_nextgen_rules', $rules );
			}

			/**
			 * Filter htaccess rules.
			 *
			 * @since NEXT
			 * @param array $rules Htaccess rules.
			 */
			$rules = (array) apply_filters( 'wppo_htaccess_rules', $rules );

			return $rules;
		}
	}
}
