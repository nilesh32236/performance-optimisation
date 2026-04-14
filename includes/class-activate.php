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
			require_once WPPO_PLUGIN_PATH . 'includes/class-htaccess-handler.php';

			$notices = array();

			if ( Advanced_Cache_Handler::foreign_dropin_present() ) {
				$notices[] = 'foreign_dropin';
			} else {
				Advanced_Cache_Handler::create();
			}

			$wp_cache_notice = self::add_wp_cache_constant();
			if ( is_string( $wp_cache_notice ) ) {
				$notices[] = $wp_cache_notice;
			}

			if ( ! empty( $notices ) ) {
				set_transient( 'wppo_activation_notices', array_unique( $notices ), WEEK_IN_SECONDS );
			}

			set_transient( 'wppo_show_welcome_notice', 1, WEEK_IN_SECONDS );

			$options             = get_option( 'wppo_settings', array() );
			$enable_server_rules = isset( $options['file_optimisation']['enableServerRules'] ) ? (bool) $options['file_optimisation']['enableServerRules'] : false;

			if ( $enable_server_rules ) {
				Htaccess_Handler::update_rules( true );
			}

			self::create_activity_log_table();
		}

		/**
		 * Adds the WP_CACHE guard block to wp-config.php when the constant is not enabled.
		 *
		 * @return string|null Notice key for the admin layer, or null if nothing to report.
		 * @since 1.0.0
		 */
		private static function add_wp_cache_constant(): ?string {
			global $wp_filesystem;

			if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
				return null;
			}

			if ( defined( 'WP_CACHE' ) && ! WP_CACHE ) {
				return 'wp_cache_disabled';
			}

			Util::init_filesystem();

			if ( ! $wp_filesystem && ! Util::init_filesystem() ) {
				return 'wp_config_fs';
			}

			$wp_config_path = wp_normalize_path( ABSPATH . 'wp-config.php' );

			if ( ! file_exists( $wp_config_path ) || ! $wp_filesystem->is_writable( $wp_config_path ) ) {
				return 'wp_config_writable';
			}

			$wp_config_content = $wp_filesystem->get_contents( $wp_config_path );

			if ( ! is_string( $wp_config_content ) ) {
				return 'wp_config_read';
			}

			if ( false !== strpos( $wp_config_content, 'Enables WordPress Cache' ) && false !== strpos( $wp_config_content, 'WP_CACHE' ) ) {
				return null;
			}

			$constant_code = "/** Enables WordPress Cache */\nif ( ! defined( 'WP_CACHE' ) ) {\n\tdefine( 'WP_CACHE', true );\n}\n";

			$insert_position = strpos( $wp_config_content, "/* That's all, stop editing!" );

			if ( false !== $insert_position ) {
				$wp_config_content = substr_replace( $wp_config_content, $constant_code, $insert_position, 0 );
			} else {
				$wp_config_content .= $constant_code;
			}

			$ok = $wp_filesystem->put_contents( $wp_config_path, $wp_config_content, FS_CHMOD_FILE );

			return $ok ? null : 'wp_config_write_failed';
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
				require_once wp_normalize_path( ABSPATH . 'wp-admin/includes/upgrade.php' );
				dbDelta( $create_table_sql );
			}

			/* phpcs:enable */
			new Log( 'Plugin activated on ' );
		}
	}
}
