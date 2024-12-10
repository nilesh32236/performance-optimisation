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
			self::create_activity_log_table();
		}

		private static function add_wp_cache_constant(): void {
			global $wp_filesystem;

			Util::init_filesystem();

			if ( ! $wp_filesystem && ! Util::init_filesystem() ) {
				return;
			}

			$wp_config_path = ABSPATH . 'wp-config.php'; // Path to wp-config.php

			if ( ! file_exists( $wp_config_path ) || ! $wp_filesystem->is_writable( $wp_config_path ) ) {
				return; // Exit if the file doesn't exist or is not writable
			}

			if ( ! $wp_filesystem->is_writable( $wp_config_path ) ) {
				return;
			}

			$wp_config_content = $wp_filesystem->get_contents( $wp_config_path );

			// Check if WP_CACHE is already defined
			if ( defined( 'WP_CACHE' ) && ! WP_CACHE ) {
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

		/**
		 * Create the activity log table if it doesn't already exist.
		 *
		 * This uses a direct database query because WordPress does not provide APIs
		 * for custom table creation or schema management. The `dbDelta()` function
		 * is the standard approach for such tasks and ensures compatibility.
		 *
		 */

		private static function create_activity_log_table() {
			global $wpdb;

			$table_name      = $wpdb->prefix . 'qtpo_activity_logs'; // Table name
			$charset_collate = $wpdb->get_charset_collate();

			/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange */
			// Direct query is required here because WordPress does not offer APIs for custom table creation.
			// This operation is performed during plugin activation, so it does not require caching.
			// Schema changes are necessary during plugin activation to create a custom table for storing plugin-specific data.
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
				// SQL to create the table if it doesn't exist
				$create_table_sql = "CREATE TABLE $table_name (
					id mediumint(9) NOT NULL AUTO_INCREMENT,
					activity varchar(255) NOT NULL,
					created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
					PRIMARY KEY (id)
				) $charset_collate;";

				// Include the required file for dbDelta function
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				dbDelta( $create_table_sql );
			}

			/* phpcs:enable */
			new Log( 'Plugin activated on ' );
		}
	}
}
