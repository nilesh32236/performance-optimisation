<?php
/**
 * Utility class for the PerformanceOptimise plugin.
 *
 * Provides methods for preparing cache directories and initializing the WP_Filesystem API.
 *
 * @package PerformanceOptimise\Inc
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) ) {
	class Util {

		/**
		 * Recursively prepares the cache directory by creating it if it does not exist.
		 *
		 * This method ensures that the specified directory and all its parent directories
		 * are created if they do not already exist.
		 *
		 * @param string $cache_dir Path to the cache directory.
		 * @return bool True if the directory was created successfully or already exists, false otherwise.
		 */
		public static function prepare_cache_dir( $cache_dir ): bool {
			global $wp_filesystem;

			// Check if the directory already exists
			if ( ! $wp_filesystem->is_dir( $cache_dir ) ) {

				// Recursively create parent directories first
				$parent_dir = dirname( $cache_dir );

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

		/**
		 * Initializes the WP_Filesystem API.
		 *
		 * This method ensures that the WP_Filesystem API is available and initializes it.
		 *
		 */
		public static function init_filesystem() {
			global $wp_filesystem;

			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			if ( WP_Filesystem() ) {
				return $wp_filesystem;
			} else {
				return false;
			}
		}

		public static function get_local_path( string $url ): string {
			$relative_path = str_replace( home_url(), '', $url );
			return ABSPATH . ltrim( $relative_path, '/' );
		}
	}
}
