<?php
/**
 * Activate class for the PerformanceOptimise plugin.
 *
 * Handles the activation process by modifying .htaccess and creating static files.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Activate' ) ) {
	/**
	 * Class Activate
	 *
	 * Handles the plugin activation logic.
	 *
	 * @since 1.0.0
	 */
	class Activate {

		/**
		 * Initializes the activation process.
		 *
		 * Includes required files and triggers necessary modifications.
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public static function init(): void {
			require_once WPPO_PLUGIN_PATH . 'includes/class-advanced-cache-handler.php';

			Advanced_Cache_Handler::create();

			// Add WP_CACHE constant to wp-config.php if not already defined.
			self::add_wp_cache_constant();
			self::create_activity_log_table();
		}

		/**
		 * Adds the WP_CACHE constant to wp-config.php if not already defined.
		 *
		 * @return void
		 * @since 1.0.0
		 */
		private static function add_wp_cache_constant(): void {
			global $wp_filesystem;

			Util::init_filesystem();

			if ( ! $wp_filesystem && ! Util::init_filesystem() ) {
				return;
			}

			$wp_config_path = ABSPATH . 'wp-config.php';

			if ( ! file_exists( $wp_config_path ) || ! $wp_filesystem->is_writable( $wp_config_path ) ) {
				return; // Exit if the file doesn't exist or is not writable.
			}

			if ( ! $wp_filesystem->is_writable( $wp_config_path ) ) {
				return;
			}

			$wp_config_content = $wp_filesystem->get_contents( $wp_config_path );

			// Check if WP_CACHE is already defined.
			if ( defined( 'WP_CACHE' ) && ! WP_CACHE ) {
				// Insert WP_CACHE just before the line that says "That's all, stop editing!" or at the end.
				$insert_position = strpos( $wp_config_content, "/* That's all, stop editing!" );

				$constant_code = "\n/** Enables WordPress Cache */\ndefine( 'WP_CACHE', true );\n";

				if ( false !== $insert_position ) {
					// Insert WP_CACHE constant before "That's all, stop editing!".
					$wp_config_content = substr_replace( $wp_config_content, $constant_code, $insert_position, 0 );
				} else {
					// If the marker isn't found, append the constant at the end of the file.
					$wp_config_content .= $constant_code;
				}

				// Write the modified content back to wp-config.php.
				$wp_filesystem->put_contents( $wp_config_path, $wp_config_content, FS_CHMOD_FILE );
			}
		}

		/**
		 * Creates the activity log table in the database if it doesn't exist.
		 *
		 * @return void
		 * @since 1.0.0
		 */
		private static function create_activity_log_table() {
			global $wpdb;

			$table_name      = $wpdb->prefix . 'wppo_activity_logs';
			$charset_collate = $wpdb->get_charset_collate();

			/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange */
			// Direct query is required here because WordPress does not offer APIs for custom table creation.
			// This operation is performed during plugin activation, so it does not require caching.
			// Schema changes are necessary during plugin activation to create a custom table for storing plugin-specific data.
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
				$create_table_sql = "CREATE TABLE $table_name (
					id mediumint(9) NOT NULL AUTO_INCREMENT,
					activity varchar(255) NOT NULL,
					created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
					PRIMARY KEY (id)
				) $charset_collate;";

				// Include the required file for dbDelta function.
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				dbDelta( $create_table_sql );
			}

			/* phpcs:enable */
			new Log( 'Plugin activated on ' );
		}
	}
}
