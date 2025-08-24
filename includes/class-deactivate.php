<?php
/**
 * Deactivate class for the PerformanceOptimise plugin.
 *
 * Handles the deactivation process by removing .htaccess modifications
 * and static files created by the plugin.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Deactivate' ) ) {
	/**
	 * Class Deactivate
	 *
	 * Handles the deactivation logic for the plugin.
	 *
	 * @since 1.0.0
	 */
	class Deactivate {

		/**
		 * Initialize the deactivation process.
		 *
		 * Cleans up resources by removing cron jobs, static files,
		 * and the WP_CACHE constant from wp-config.php.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public static function init(): void {
			require_once WPPO_PLUGIN_PATH . 'includes/class-cron.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-advanced-cache-handler.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-util.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-cache.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-log.php';

			// Unschedule all plugin-specific cron jobs.
			Cron::clear_all_plugin_cron_jobs();

			// Remove advanced-cache.php.
			Advanced_Cache_Handler::remove();

			// Remove WP_CACHE constant from wp-config.php.
			self::remove_wp_cache_constant();

			// Clear all plugin-generated cache files.
			Cache::clear_cache();

			// Log plugin deactivation.
			new Log( __( 'Plugin deactivated', 'performance-optimisation' ) );

			// Flush rewrite rules.
			flush_rewrite_rules();
		}


		/**
		 * Removes WP_CACHE constant from wp-config.php file if present and set by this plugin.
		 *
		 * Ensures that the constant enabling WordPress caching, if added by this plugin,
		 * is removed during deactivation.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		private static function remove_wp_cache_constant(): void {
			$wp_filesystem = Util::init_filesystem();

			if ( ! $wp_filesystem ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WPPO Deactivation: Filesystem could not be initialized for wp-config.php modification.' );
				}
				return;
			}

			$wp_config_path = wp_normalize_path( ABSPATH . 'wp-config.php' );

			if ( ! $wp_filesystem->is_writable( $wp_config_path ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WPPO Deactivation: wp-config.php is not writable at ' . esc_html( $wp_config_path ) );
				}
				return;
			}

			$config_content = $wp_filesystem->get_contents( $wp_config_path );
			if ( false === $config_content ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WPPO Deactivation: Could not read wp-config.php content.' );
				}
				return;
			}

			$pattern = '/\/\*\* Enables WordPress Cache \(Performance Optimisation Plugin\) \*\/\s*define\s*\(\s*([\'"])WP_CACHE\1\s*,\s*true\s*\);?\s*/s';

			if ( preg_match( $pattern, $config_content ) ) {
				$config_content = preg_replace( $pattern, '', $config_content );
				$wp_filesystem->put_contents( $wp_config_path, $config_content, FS_CHMOD_FILE );
			}
		}
	}
}
