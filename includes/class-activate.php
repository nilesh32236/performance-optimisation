<?php
/**
 * Activate class for the PerformanceOptimise plugin.
 *
 * Handles the activation process by modifying .htaccess and creating static files.
 *
 * @package PerformanceOptimise\Inc
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Activate' ) ) {
	/**
	 * Class Activate
	 *
	 * Handles the activation logic for the plugin.
	 */
	class Activate {

		/**
		 * Initialize the activation process.
		 *
		 * This method checks if the necessary classes exist before including them.
		 * Then it triggers the required static file and htaccess modifications.
		 *
		 * @return void
		 */
		public static function init(): void {
			require_once QTPO_PLUGIN_PATH . 'includes/class-advanced-cache-handler.php';

			Advanced_Cache_Handler::create();

			// Add WP_CACHE constant to wp-config.php if not already defined.
			self::add_wp_cache_constant();
		}

		private static function add_wp_cache_constant(): void {
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

			// Check if WP_CACHE is already defined
			if ( strpos( $wp_config_content, "define('WP_CACHE', true);" ) === false &&
				strpos( $wp_config_content, 'define( "WP_CACHE", true );' ) === false ) {

				// Insert WP_CACHE just before the line that says "That's all, stop editing!" or at the end.
				$insert_position = strpos( $wp_config_content, "/* That's all, stop editing!" );

				$constant_code = "\n/** Enables WordPress Cache */\ndefine( 'WP_CACHE', true );\n";

				if ( false !== $insert_position ) {
					// Insert WP_CACHE constant before "That's all, stop editing!"
					$wp_config_content = substr_replace( $wp_config_content, $constant_code, $insert_position, 0 );
				} else {
					// If the marker isn't found, append the constant at the end of the file.
					$wp_config_content .= $constant_code;
				}

				// Write the modified content back to wp-config.php
				$wp_filesystem->put_contents( $wp_config_path, $wp_config_content, FS_CHMOD_FILE );
			}
		}
	}
}
