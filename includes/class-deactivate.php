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
			// Ensure dependent classes are loaded.
			if ( ! class_exists( 'PerformanceOptimise\Inc\Cron' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-cron.php';
			}
			if ( ! class_exists( 'PerformanceOptimise\Inc\Advanced_Cache_Handler' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-advanced-cache-handler.php';
			}
			if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-util.php';
			}
			if ( ! class_exists( 'PerformanceOptimise\Inc\Cache' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-cache.php';
			}
			if ( ! class_exists( 'PerformanceOptimise\Inc\Log' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-log.php';
			}

			// Unschedule all plugin-specific cron jobs.
			Cron::clear_all_plugin_cron_jobs();

			// Remove advanced-cache.php.
			Advanced_Cache_Handler::remove();

			// Remove WP_CACHE constant from wp-config.php.
			self::remove_wp_cache_constant();

			// Clear all plugin-generated cache files.
			Cache::clear_cache(); // This clears both page cache and minified assets.

			// Log plugin deactivation.
			new Log( __( 'Plugin deactivated on', 'performance-optimisation' ) . ' ' . current_time( 'mysql' ) );

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

			if ( ! $wp_filesystem->exists( $wp_config_path ) || ! $wp_filesystem->is_writable( $wp_config_path ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WPPO Deactivation: wp-config.php does not exist or is not writable at ' . $wp_config_path );
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

			// Pattern to find the WP_CACHE definition possibly added by this plugin.
			// It looks for the definition along with the specific comment.
			$pattern = '/\/\*\* Enables WordPress Cache \(Performance Optimisation Plugin\) \*\/\s*define\s*\(\s*([\'"])WP_CACHE\1\s*,\s*true\s*\);?\s*/s';

			if ( preg_match( $pattern, $config_content ) ) {
				$config_content_modified = preg_replace( $pattern, '', $config_content );

				// Only write if changes were made.
				if ( $config_content_modified !== $config_content ) {
					$wp_filesystem->put_contents( $wp_config_path, $config_content_modified, FS_CHMOD_FILE );
				}
			} elseif ( defined( 'WP_CACHE' ) && WP_CACHE ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'WPPO Deactivation: WP_CACHE is true but was not added by this plugin (comment missing). Leaving as is.' );
				}
			}
		}
	}
}
