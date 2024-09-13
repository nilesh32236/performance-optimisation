<?php
/**
 * Htaccess class for the PerformanceOptimise plugin.
 *
 * Handles modifications and removal of rules in the .htaccess file for performance optimization.
 *
 * @package PerformanceOptimise\Inc
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Htaccess' ) ) {
	/**
	 * Class Htaccess
	 *
	 * Provides methods to modify and remove performance optimization rules in the .htaccess file.
	 */
	class Htaccess {

		/**
		 * Adds performance optimization rules to the .htaccess file.
		 *
		 * This method writes the rules to the top of the .htaccess file, including caching,
		 * compression, and security headers.
		 *
		 * @return void
		 */
		public static function modify_htaccess(): void {
			$rules = <<<EOD
# BEGIN Performance Optimisation
<IfModule mod_rewrite.c>
	RewriteEngine On
	RewriteCond %{REQUEST_METHOD} !POST
	RewriteCond %{QUERY_STRING} ^$
	RewriteCond %{DOCUMENT_ROOT}/wp-content/cache/qtpo/%{HTTP_HOST}%{REQUEST_URI}index.html.gz -f
	RewriteRule ^(.*)$ wp-content/cache/qtpo/cache-handler.php [L]
	RewriteCond %{DOCUMENT_ROOT}/wp-content/cache/qtpo/%{HTTP_HOST}%{REQUEST_URI}index.html -f
	RewriteRule ^(.*)$ wp-content/cache/qtpo/cache-handler.php [L]
	
	# Serve compressed CSS and JS files if they exist
	RewriteCond %{REQUEST_FILENAME}\.gz -f
	RewriteCond %{REQUEST_URI} \.(css|js)$
	RewriteRule ^(.*)$ $1\.gz [L]
</IfModule>

<IfModule mod_mime.c>
	AddType font/woff2 .woff2
	AddType image/webp .webp
	AddType image/avif .avif
	AddEncoding gzip .gz
</IfModule>

# Compression
<IfModule mod_deflate.c>
	# Compress all output labeled with one of the following MIME-types
	AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css application/xml application/xhtml+xml application/rss+xml application/javascript application/x-javascript application/json
	AddOutputFilterByType DEFLATE image/svg+xml font/truetype font/opentype font/woff2 application/font-woff application/font-woff2

	# Do not compress images and other binary formats
	SetEnvIfNoCase Request_URI \.(gif|jpe?g|png|bmp|ico|tiff|zip|gz|bz2|tar|7z|rar|pdf|avi|mov|mp3|mp4|mkv|flv|wmv|wav|wma)$ no-gzip dont-vary

	# Ensure proxies don't deliver the wrong content
	Header append Vary User-Agent env=!dont-vary
</IfModule>

# Browser Caching
<IfModule mod_expires.c>
	ExpiresActive On
	ExpiresByType image/jpg "access plus 1 year"
	ExpiresByType image/jpeg "access plus 1 year"
	ExpiresByType image/gif "access plus 1 year"
	ExpiresByType image/png "access plus 1 year"
	ExpiresByType image/webp "access plus 1 year"
	ExpiresByType image/avif "access plus 1 year"
	ExpiresByType image/svg+xml "access plus 1 year"
	ExpiresByType image/x-icon "access plus 1 year"
	ExpiresByType text/css "access plus 1 year"
	ExpiresByType text/javascript "access plus 1 year"
	ExpiresByType text/x-javascript "access plus 1 year"
	ExpiresByType application/javascript "access plus 1 year"
	ExpiresByType application/pdf "access plus 1 month"
	ExpiresByType application/x-shockwave-flash "access plus 1 year"
	ExpiresByType font/ttf "access plus 1 year"
	ExpiresByType font/otf "access plus 1 year"
	ExpiresByType application/font-woff "access plus 1 year"
	ExpiresByType application/font-woff2 "access plus 1 year"
	ExpiresByType font/truetype "access plus 1 year"
	ExpiresByType font/opentype "access plus 1 year"
	ExpiresByType font/woff2 "access plus 1 year"
	ExpiresByType font/woff "access plus 1 year"

	ExpiresByType text/html "access plus 1 hour"
	ExpiresByType application/xhtml+xml "access plus 1 hour"

	ExpiresDefault "access plus 2 days"
</IfModule>
<IfModule mod_headers.c>
	<FilesMatch "\.(ico|pdf|flv|jpg|jpeg|png|webp|avif|gif|css|swf|svg)$">
		Header set Cache-Control "max-age=31536000, public"
		Header set Access-Control-Allow-Origin "*"
	</FilesMatch>
	<FilesMatch "\.(js)$">
		Header set Cache-Control "max-age=31536000, private"
	</FilesMatch>
	<FilesMatch "\.(ttf|otf|eot|woff|woff2)$">
		Header set Cache-Control "max-age=31536000, public"
		Header set Access-Control-Allow-Origin "*"
	</FilesMatch>
	<FilesMatch "\.(x?html?|php)$">
		Header set Cache-Control "private, must-revalidate"
	</FilesMatch>
	<FilesMatch "\.css\.gz$">
		Header set Content-Encoding gzip
		Header set Content-Type text/css
	</FilesMatch>
	<FilesMatch "\.js\.gz$">
		Header set Content-Encoding gzip
		Header set Content-Type application/javascript
	</FilesMatch>

	# Security Headers
	Header always append X-Frame-Options SAMEORIGIN
	Header set X-Content-Type-Options nosniff
	Header set X-XSS-Protection "1; mode=block"
	Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
	Header set Referrer-Policy "no-referrer-when-downgrade"
</IfModule>
# END Performance Optimisation
EOD;

			global $wp_filesystem;

			if ( ! Util::init_filesystem() ) {
				return;
			}

			$htaccess_path     = ABSPATH . '.htaccess';
			$htaccess_contents = '';
			if ( $wp_filesystem->exists( $htaccess_path ) ) {
				$htaccess_contents = $wp_filesystem->get_contents( $htaccess_path );
			}

			// Add the new rules at the top
			$new_htaccess_contents = $rules . "\n\n" . $htaccess_contents;

			// Write the contents back to the .htaccess file
			$wp_filesystem->put_contents( $htaccess_path, $new_htaccess_contents, FS_CHMOD_FILE );
		}

		/**
		 * Removes performance optimization rules from the .htaccess file.
		 *
		 * This method removes the section of the .htaccess file that was added for
		 * performance optimization, if it exists.
		 *
		 * @return void
		 */
		public static function remove_htaccess(): void {
			global $wp_filesystem;

			if ( ! Util::init_filesystem() ) {
				return;
			}

			$htaccess_path = ABSPATH . '.htaccess';

			if ( $wp_filesystem->exists( $htaccess_path ) ) {
				$htaccess_contents = $wp_filesystem->get_contents( $htaccess_path );

				// Remove the section marked with "# BEGIN Performance Optimisation" and "# END Performance Optimisation"
				$htaccess_contents = preg_replace( '/# BEGIN Performance Optimisation.*?# END Performance Optimisation\s*/is', '', $htaccess_contents );

				// Write the cleaned contents back to the .htaccess file
				$wp_filesystem->put_contents( $htaccess_path, $htaccess_contents, FS_CHMOD_FILE );
			}
		}
	}
}
