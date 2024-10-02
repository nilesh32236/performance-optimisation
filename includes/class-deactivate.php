<?php
/**
 * Deactivate class for the PerformanceOptimise plugin.
 *
 * Handles the deactivation process by removing .htaccess modifications
 * and static files created by the plugin.
 *
 * @package PerformanceOptimise\Inc
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Deactivate' ) ) {
	/**
	 * Class Deactivate
	 *
	 * Handles the deactivation logic for the plugin.
	 */
	class Deactivate {

		/**
		 * Initialize the deactivation process.
		 *
		 * This method checks if the necessary classes exist before including them.
		 * Then it triggers the removal of static files and htaccess modifications.
		 *
		 * @return void
		 */
		public static function init(): void {

			require_once QTPO_PLUGIN_PATH . 'includes/class-advanced-cache-handler.php';

			Advanced_Cache_Handler::remove();

			// Remove WP_CACHE constant from wp-config.php
			self::remove_wp_cache_constant();
			new Log( 'Plugin deactivated on ' );
		}

		/**
		 * Removes WP_CACHE constant from wp-config.php file if present.
		 *
		 * @return void
		 */
		private static function remove_wp_cache_constant(): void {
			$wp_config_path = ABSPATH . 'wp-config.php'; // Path to wp-config.php

			if ( ! file_exists( $wp_config_path ) || ! is_writable( $wp_config_path ) ) {
				return; // Exit if the file doesn't exist or is not writable
			}

			global $wp_filesystem;

			Util::init_filesystem();

			if ( ! $wp_filesystem && ! Util::init_filesystem() ) {
				return;
			}

			if ( ! $wp_filesystem->is_writable( $wp_config_path ) ) {
				return;
			}

			$wp_config_content = $wp_filesystem->get_contents( $wp_config_path );

			// Check if WP_CACHE is defined and remove it
			$pattern = '/\n?\/\*\* Enables WordPress Cache \*\/\n\s*define\(\s*\'WP_CACHE\',\s*true\s*\);\s*/';

			if ( preg_match( $pattern, $wp_config_content ) ) {
				// Remove the WP_CACHE line
				$wp_config_content = preg_replace( $pattern, '', $wp_config_content );

				// Write the modified content back to wp-config.php
				$wp_filesystem->put_contents( $wp_config_path, $wp_config_content, FS_CHMOD_FILE );
			}
		}
	}
}
