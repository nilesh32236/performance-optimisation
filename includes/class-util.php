<?php

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Util {
	public static function prepare_cache_dir( $cache_dir ) {
		global $wp_filesystem;

		// Check if the directory already exists
		if ( ! $wp_filesystem->is_dir( $cache_dir ) ) {

			// Recursively create parent directories first
			$parent_dir = dirname( $cache_dir );
			error_log( 'Parent directory: ' . $parent_dir );
			if ( ! $wp_filesystem->is_dir( $parent_dir ) ) {
				self::prepare_cache_dir( $parent_dir );
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
