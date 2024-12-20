<?php
/**
 * Advanced_Cache_Handler class for the PerformanceOptimise plugin.
 *
 * Handles the creation and removal of an advanced-cache.php file used for serving cached content.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Advanced_Cache_Handler' ) ) {
	/**
	 * Class Advanced_Cache_Handler
	 *
	 * Manages the creation and removal of the advanced-cache.php file.
	 *
	 * @since 1.0.0
	 */
	class Advanced_Cache_Handler {

		/**
		 * Path to the advanced cache file.
		 *
		 * @var string
		 * @since 1.0.0
		 */
		private static $handler_file = WP_CONTENT_DIR . '/advanced-cache.php';

		/**
		 * Creates the advanced-cache.php file.
		 *
		 * Generates the file to serve cached content, including gzip versions, and ensures required directories exist.
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public static function create(): void {

			global $wp_filesystem;

			if ( ! $wp_filesystem && ! Util::init_filesystem() ) {
				return;
			}

			$site_url = home_url();

			$handler_code = '<?php' . PHP_EOL .
			'if ( ! defined( \'ABSPATH\' ) ) {' . PHP_EOL .
			'	exit;' . PHP_EOL .
			'}' . PHP_EOL . PHP_EOL .

			'$site_url       = \'' . $site_url . '\';' . PHP_EOL .
			'$root_directory = $_SERVER[\'DOCUMENT_ROOT\'];' . PHP_EOL .
			'$site_domain    = $_SERVER[\'HTTP_HOST\'];' . PHP_EOL .
			'$request_uri    = parse_url( $_SERVER[\'REQUEST_URI\'], PHP_URL_PATH );' . PHP_EOL .
			'$file_path      = WP_CONTENT_DIR . \'/cache/wppo/\' . $site_domain . $request_uri . \'index.html\';' . PHP_EOL .
			'$gzip_file_path = $file_path . \'.gz\';' . PHP_EOL . PHP_EOL .

			'function is_user_logged_in_without_wp( $site_url ) {' . PHP_EOL .
			'	if ( isset( $_COOKIE[ \'wordpress_logged_in_\' . md5( $site_url ) ] ) ) {' . PHP_EOL .
			'		return true;' . PHP_EOL .
			'	}' . PHP_EOL .
			'	return false;' . PHP_EOL .
			'}' . PHP_EOL . PHP_EOL .

			'if ( ! is_user_logged_in_without_wp( $site_url ) &&' . PHP_EOL .
			'	( empty( $_SERVER[\'QUERY_STRING\'] ) || ! preg_match( \'/(?:^|&)(s|ver)(?:=|&|$)/\', $_SERVER[\'QUERY_STRING\'] ) )' . PHP_EOL .
			') {' . PHP_EOL .
			'	if ( file_exists( $gzip_file_path ) ) {' . PHP_EOL .
			'		$last_modified_time = filemtime( $gzip_file_path );' . PHP_EOL .
			'		$etag               = md5_file( $gzip_file_path );' . PHP_EOL .
			'		header( \'Last-Modified: \' . gmdate( \'D, d M Y H:i:s\', $last_modified_time ) . \' GMT\' );' . PHP_EOL .
			'		header( \'ETag: "\' . $etag . \'"\' );' . PHP_EOL .
			'		header( \'Content-Type: text/html\' );' . PHP_EOL .
			'		header( \'Content-Encoding: gzip\' );' . PHP_EOL . PHP_EOL .

			'		if ( ( isset( $_SERVER[\'HTTP_IF_MODIFIED_SINCE\'] ) && strtotime( $_SERVER[\'HTTP_IF_MODIFIED_SINCE\'] ) >= $last_modified_time ) ||' . PHP_EOL .
			'		( isset( $_SERVER[\'HTTP_IF_NONE_MATCH\'] ) && trim( $_SERVER[\'HTTP_IF_NONE_MATCH\'] ) === $etag ) ) {' . PHP_EOL .
			'			header( \'HTTP/1.1 304 Not Modified\' );' . PHP_EOL .
			'			header( \'Connection: close\' );' . PHP_EOL .
			'			exit;' . PHP_EOL .
			'		}' . PHP_EOL . PHP_EOL .

			'		readfile( $gzip_file_path );' . PHP_EOL .
			'		exit;' . PHP_EOL .
			'	} elseif ( file_exists( $file_path ) ) {' . PHP_EOL .
			'		$last_modified_time = filemtime( $file_path );' . PHP_EOL .
			'		$etag               = md5_file( $file_path );' . PHP_EOL .
			'		header( \'Last-Modified: \' . gmdate( \'D, d M Y H:i:s\', $last_modified_time ) . \' GMT\' );' . PHP_EOL .
			'		header( \'ETag: "\' . $etag . \'"\' );' . PHP_EOL .
			'		header( \'Content-Type: text/html\' );' . PHP_EOL . PHP_EOL .

			'		if ( ( isset( $_SERVER[\'HTTP_IF_MODIFIED_SINCE\'] ) && strtotime( $_SERVER[\'HTTP_IF_MODIFIED_SINCE\'] ) >= $last_modified_time ) ||' . PHP_EOL .
			'		( isset( $_SERVER[\'HTTP_IF_NONE_MATCH\'] ) && trim( $_SERVER[\'HTTP_IF_NONE_MATCH\'] ) === $etag ) ) {' . PHP_EOL .
			'			header( \'HTTP/1.1 304 Not Modified\' );' . PHP_EOL .
			'			header( \'Connection: close\' );' . PHP_EOL .
			'			exit;' . PHP_EOL .
			'		}' . PHP_EOL . PHP_EOL .

			'		readfile( $file_path );' . PHP_EOL .
			'		exit;' . PHP_EOL .
			'	}' . PHP_EOL .
			'}' . PHP_EOL;

			// Write the handler file in the wp-content directory as advanced-cache.php.
			$create_file = $wp_filesystem->put_contents( self::$handler_file, $handler_code, FS_CHMOD_FILE );
		}

		/**
		 * Removes the advanced-cache.php file.
		 *
		 * Deletes the advanced-cache.php file if it exists.
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public static function remove(): void {
			global $wp_filesystem;

			if ( Util::init_filesystem() && $wp_filesystem->exists( self::$handler_file ) ) {
				$wp_filesystem->delete( self::$handler_file );
			}
		}
	}
}
