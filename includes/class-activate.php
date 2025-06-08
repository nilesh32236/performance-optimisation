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

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
		 * @since 1.0.0
		 * @return void
		 */
		public static function init(): void {
			if ( ! class_exists( 'PerformanceOptimise\Inc\Advanced_Cache_Handler' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-advanced-cache-handler.php';
			}
			if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-util.php';
			}
			if ( ! class_exists( 'PerformanceOptimise\Inc\Log' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-log.php';
			}

			Advanced_Cache_Handler::create();

			// Add WP_CACHE constant to wp-config.php if not already defined.
			self::add_wp_cache_constant();
			self::create_activity_log_table();

			// Ensure cron jobs are scheduled on activation if enabled.
			if ( ! class_exists( 'PerformanceOptimise\Inc\Cron' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-cron.php';
			}
			$cron_manager = new Cron();
			$cron_manager->schedule_cron_jobs();

			flush_rewrite_rules();
		}

		/**
		 * Adds the WP_CACHE constant to wp-config.php if not already defined or set to false.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		private static function add_wp_cache_constant(): void {
			$wp_filesystem = Util::init_filesystem();

			if ( ! $wp_filesystem ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WPPO Activation: Filesystem could not be initialized for wp-config.php modification.' );
				}
				return;
			}

			$wp_config_path = wp_normalize_path( ABSPATH . 'wp-config.php' );

			if ( ! $wp_filesystem->exists( $wp_config_path ) || ! $wp_filesystem->is_writable( $wp_config_path ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WPPO Activation: wp-config.php does not exist or is not writable at ' . $wp_config_path );
				}
				return;
			}

			$config_content = $wp_filesystem->get_contents( $wp_config_path );
			if ( false === $config_content ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WPPO Activation: Could not read wp-config.php content.' );
				}
				return;
			}

			if ( defined( 'WP_CACHE' ) && true === WP_CACHE ) {
				return; // Already correctly defined.
			}

			$constant_definition = "define( 'WP_CACHE', true );";
			$comment             = '/** Enables WordPress Cache (Performance Optimisation Plugin) */';
			$new_content_block   = PHP_EOL . $comment . PHP_EOL . $constant_definition . PHP_EOL;

			if ( preg_match( '/^define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*false\s*\)\s*;/m', $config_content, $matches, PREG_OFFSET_CAPTURE ) ) {
				$config_content = substr_replace( $config_content, $comment . PHP_EOL . $constant_definition, $matches[0][1], strlen( $matches[0][0] ) );
			} elseif ( ! preg_match( '/^define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*true\s*\)\s*;/m', $config_content ) ) {
				$stop_editing_marker = "/* That's all, stop editing!";
				$insert_position     = strpos( $config_content, $stop_editing_marker );

				if ( false !== $insert_position ) {
					$config_content = substr_replace( $config_content, $new_content_block, $insert_position, 0 );
				} else {
					$settings_marker = 'require_once ABSPATH . \'wp-settings.php\'';
					$insert_position = strpos( $config_content, $settings_marker );
					if ( false !== $insert_position ) {
						$config_content = substr_replace( $config_content, $new_content_block, $insert_position, 0 );
					} else {
						$closing_php_tag = strrpos( $config_content, '?>' );
						if ( false !== $closing_php_tag ) {
							$config_content = substr_replace( $config_content, $new_content_block, $closing_php_tag, 0 );
						} else {
							$config_content .= $new_content_block;
						}
					}
				}
			}

			$wp_filesystem->put_contents( $wp_config_path, $config_content, FS_CHMOD_FILE );
		}

		/**
		 * Creates the activity log table in the database if it doesn't exist.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		private static function create_activity_log_table(): void {
			global $wpdb;

			$table_name      = $wpdb->prefix . 'wppo_activity_logs';
			$charset_collate = $wpdb->get_charset_collate();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
				$sql = "CREATE TABLE {$table_name} (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					activity TEXT NOT NULL,
					created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
					PRIMARY KEY (id)
				) {$charset_collate};";

				if ( ! function_exists( 'dbDelta' ) ) {
					require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				}
				dbDelta( $sql );
			}

			if ( class_exists( 'PerformanceOptimise\Inc\Log' ) ) {
				new Log( __( 'Plugin activated on', 'performance-optimisation' ) . ' ' . current_time( 'mysql' ) );
			}
		}
	}
}
