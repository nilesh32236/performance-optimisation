<?php

/**
 * Static_File_Handler class for the PerformanceOptimise plugin.
 *
 * Handles the creation and removal of a cache handler PHP file used for serving cached content.
 *
 * @package PerformanceOptimise\Inc
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Static_File_Handler' ) ) {
	class Static_File_Handler {

		/**
		 * Path to the cache handler file.
		 *
		 * @var string
		 */
		private static $handler_file = WP_CONTENT_DIR . '/cache/qtpo/cache-handler.php';

		/**
		 * Creates the cache handler file.
		 *
		 * This method generates a PHP file that handles serving cached content, including gzip and non-gzip versions,
		 * and ensures that required directories exist.
		 *
		 * @return void
		 */
		public static function create(): void {

			global $wp_filesystem;

			if ( ! $wp_filesystem && ! Util::init_filesystem() ) {
				return;
			}

			$site_url = home_url();

			$handler_code = <<<PHP
<?php
\$site_url       = '{$site_url}';
\$root_directory = \$_SERVER['DOCUMENT_ROOT'];
\$site_domain    = \$_SERVER['HTTP_HOST'];
\$request_uri    = parse_url( \$_SERVER['REQUEST_URI'], PHP_URL_PATH );
\$file_path      = \$root_directory . '/wp-content/cache/qtpo/' . \$site_domain . \$request_uri . 'index.html';
\$gzip_file_path = \$file_path . '.gz';

function is_user_logged_in_without_wp( \$site_url ) {
	if ( isset( \$_COOKIE[ 'wordpress_logged_in_' . md5( \$site_url ) ] ) ) {
		return true;
	}
	return false;
}

if ( is_user_logged_in_without_wp( \$site_url ) ) {
	require_once \$_SERVER['DOCUMENT_ROOT'] . '/wp-load.php';
	if ( is_user_logged_in() ) {
		require_once \$_SERVER['DOCUMENT_ROOT'] . '/index.php';
		exit;
	}
}

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
} else {
	require_once \$_SERVER['DOCUMENT_ROOT'] . '/index.php';
	exit;
}

PHP;

			$cache_dir = dirname( self::$handler_file );

			// Ensure the cache directory exists
			Util::prepare_cache_dir( $cache_dir );

			// Write the handler file
			$wp_filesystem->put_contents( self::$handler_file, $handler_code, FS_CHMOD_FILE );
		}

		/**
		 * Removes the cache handler file.
		 *
		 * This method deletes the cache handler PHP file if it exists.
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
