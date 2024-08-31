<?php

class Static_File_Handler {

	private static $handler_file = WP_CONTENT_DIR . '/cache/qtpm/cache-handler.php';

	public static function create() {

		global $wp_filesystem;

		if ( ! $wp_filesystem && ! self::init_wp_filesystem() ) {
			return;
		}

		$site_url = home_url();

		$handler_code = <<<PHP
<?php
\$site_url       = '{$site_url}';
\$root_directory = \$_SERVER['DOCUMENT_ROOT'];
\$site_domain    = \$_SERVER['HTTP_HOST'];
\$request_uri    = parse_url( \$_SERVER['REQUEST_URI'], PHP_URL_PATH );
\$file_path      = \$root_directory . '/wp-content/cache/qtpm/' . \$site_domain . \$request_uri . 'index.html';
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

		if ( ! $wp_filesystem->is_dir( WP_CONTENT_DIR . '/cache' ) ) {
			$wp_filesystem->mkdir( WP_CONTENT_DIR . '/cache', FS_CHMOD_DIR );
		}

		if ( ! $wp_filesystem->is_dir( $cache_dir ) ) {
			$wp_filesystem->mkdir( $cache_dir, FS_CHMOD_DIR );
		}

		// Write the handler file
		$wp_filesystem->put_contents( self::$handler_file, $handler_code, FS_CHMOD_FILE );
	}

	public static function remove() {
		global $wp_filesystem;

		if ( self::init_wp_filesystem() && $wp_filesystem->exists( self::$handler_file ) ) {
			$wp_filesystem->delete( self::$handler_file );
		}
	}

	private static function init_wp_filesystem() {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		return WP_Filesystem();
	}

	private function prepare_cache_dir( $cache_dir ) {
		global $wp_filesystem;

		// Check if the directory already exists
		if ( ! $wp_filesystem->is_dir( $cache_dir ) ) {

			// Recursively create parent directories first
			$parent_dir = dirname( $cache_dir );
			error_log( '$parent_dir: ' . $parent_dir );
			if ( ! $wp_filesystem->is_dir( $parent_dir ) ) {
				$this->prepare_cache_dir( $parent_dir );
			}

			// Create the final directory
			if ( ! $wp_filesystem->mkdir( $cache_dir, FS_CHMOD_DIR ) ) {
				error_log( "Failed to create directory using WP_Filesystem: $cache_dir" );
				return false;
			}
		}

		return true;
	}
}
