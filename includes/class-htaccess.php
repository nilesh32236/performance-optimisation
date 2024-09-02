<?php

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Htaccess {
	public static function modify_htaccess() {
		$htaccess_path = ABSPATH . '.htaccess';
		error_log( $htaccess_path );
		$rules         = <<<EOD
<IfModule mod_rewrite.c>
	RewriteEngine On
	RewriteCond %{REQUEST_METHOD} !POST
	RewriteCond %{QUERY_STRING} ^$
	RewriteCond %{DOCUMENT_ROOT}/wp-content/cache/qtpo/%{HTTP_HOST}%{REQUEST_URI}index.html.gz -f
	RewriteRule ^(.*)$ wp-content/cache/qtpo/cache-handler.php?file=%{REQUEST_URI} [L]
	RewriteCond %{DOCUMENT_ROOT}/wp-content/cache/qtpo/%{HTTP_HOST}%{REQUEST_URI}index.html -f
	RewriteRule ^(.*)$ wp-content/cache/qtpo/cache-handler.php?file=%{REQUEST_URI} [L]
	
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
EOD;

		insert_with_markers( $htaccess_path, 'Performance Optimisation', explode( "\n", $rules ) );
	}

	public static function remove_htaccess() {
		$htaccess_path = ABSPATH . '.htaccess';
		insert_with_markers( $htaccess_path, 'Performance Optimisation', array() );
	}
}
