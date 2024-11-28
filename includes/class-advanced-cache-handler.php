<?php

/**
 * Advanced_Cache_Handler class for the PerformanceOptimise plugin.
 *
 * Handles the creation and removal of a advanced-cache.php file used for serving cached content.
 *
 * @package PerformanceOptimise\Inc
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Advanced_Cache_Handler' ) ) {
	class Advanced_Cache_Handler {

		/**
		 * Path to the advanced cache file.
		 *
		 * @var string
		 */
		private static $handler_file = WP_CONTENT_DIR . '/advanced-cache.php';

		/**
		 * Creates the advanced-cache.php file.
		 *
		 * This method generates the advanced-cache.php file which handles serving cached content
		 * including gzip and non-gzip versions, and ensures that required directories exist.
		 *
		 * @return void
		 */
		public static function create(): void {

			error_log( 'create ' );
			global $wp_filesystem;

			if ( ! $wp_filesystem && ! Util::init_filesystem() ) {
				return;
			}

			$site_url = home_url();

			$handler_code = <<<PHP
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

\$site_url       = '{$site_url}';
\$root_directory = \$_SERVER['DOCUMENT_ROOT'];
\$site_domain    = \$_SERVER['HTTP_HOST'];
\$request_uri    = parse_url( \$_SERVER['REQUEST_URI'], PHP_URL_PATH );
\$file_path      = WP_CONTENT_DIR . '/cache/qtpo/' . \$site_domain . \$request_uri . 'index.html';
\$gzip_file_path = \$file_path . '.gz';

function is_user_logged_in_without_wp( \$site_url ) {
	if ( isset( \$_COOKIE[ 'wordpress_logged_in_' . md5( \$site_url ) ] ) ) {
		return true;
	}
	return false;
}

if ( 
	! is_user_logged_in_without_wp( \$site_url ) && 
	( empty( \$_SERVER['QUERY_STRING'] ) || ! preg_match( '/(?:^|&)(s|ver)(?:=|&|$)/', \$_SERVER['QUERY_STRING'] ) )
) {
	if ( file_exists( \$gzip_file_path ) ) {
		\$last_modified_time = filemtime( \$gzip_file_path );
		\$etag               = md5_file( \$gzip_file_path );

		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', \$last_modified_time ) . ' GMT' );
		header( 'ETag: "' . \$etag . '"' );
		header( 'Content-Type: text/html' );
		header( 'Content-Encoding: gzip' );

		if ( ( isset( \$_SERVER['HTTP_IF_MODIFIED_SINCE'] ) && strtotime( \$_SERVER['HTTP_IF_MODIFIED_SINCE'] ) >= \$last_modified_time ) ||
			( isset( \$_SERVER['HTTP_IF_NONE_MATCH'] ) && trim( \$_SERVER['HTTP_IF_NONE_MATCH'] ) === \$etag ) ) {
			header( 'HTTP/1.1 304 Not Modified' );
			header( 'Connection: close' );
			exit;
		}

		readfile( \$gzip_file_path );
		exit;
	} elseif ( file_exists( \$file_path ) ) {
		\$last_modified_time = filemtime( \$file_path );
		\$etag               = md5_file( \$file_path );

		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', \$last_modified_time ) . ' GMT' );
		header( 'ETag: "' . \$etag . '"' );
		header( 'Content-Type: text/html' );

		if ( ( isset( \$_SERVER['HTTP_IF_MODIFIED_SINCE'] ) && strtotime( \$_SERVER['HTTP_IF_MODIFIED_SINCE'] ) >= \$last_modified_time ) ||
			( isset( \$_SERVER['HTTP_IF_NONE_MATCH'] ) && trim( \$_SERVER['HTTP_IF_NONE_MATCH'] ) === \$etag ) ) {
			header( 'HTTP/1.1 304 Not Modified' );
			header( 'Connection: close' );
			exit;
		}

		readfile( \$file_path );
		exit;
	}
}

PHP;

			// Write the handler file in the wp-content directory as advanced-cache.php
			$create_file = $wp_filesystem->put_contents( self::$handler_file, $handler_code, FS_CHMOD_FILE );

			error_log( var_export( $create_file, true ) );
		}

		/**
		 * Removes the advanced cache file.
		 *
		 * This method deletes the advanced-cache.php file if it exists.
		 *
		 * @return void
		 */
		public static function remove(): void {
			global $wp_filesystem;

			if ( Util::init_filesystem() && $wp_filesystem->exists( self::$handler_file ) ) {
				$wp_filesystem->delete( self::$handler_file );
			}
		}
	}
}
