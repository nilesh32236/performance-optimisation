<?php
/**
 * Deactivate class for the PerformanceOptimise plugin.
 *
 * Handles the deactivation process by removing .htaccess modifications
 * and static files created by the plugin.
 *
 * @package PerformanceOptimise\Inc
 * @since   1.0.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
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
		 * .htaccess modifications, and WP_CACHE constant.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public static function init(): void {
			self::unschedule_crons();
			self::unschedule_database_cleanup_cron();
			delete_option( 'wppo_preload_cron_offset' );

			require_once WPPO_PLUGIN_PATH . 'includes/class-util.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-advanced-cache-handler.php';

			Advanced_Cache_Handler::remove();

			global $wp_filesystem;
			if ( ! $wp_filesystem ) {
				Util::init_filesystem();
			}

			// Remove Redis object cache drop-in if it belongs to this plugin.
			$object_cache_file = wp_normalize_path( WP_CONTENT_DIR . '/object-cache.php' );
			if ( $wp_filesystem && $wp_filesystem->exists( $object_cache_file ) ) {
				$content = $wp_filesystem->get_contents( $object_cache_file );
				if ( false !== $content && false !== strpos( $content, 'Redis Object Cache Drop-in for Performance Optimisation' ) ) {
					$wp_filesystem->delete( $object_cache_file );
				}
			}

			// Remove Redis config file.
			$redis_config_file = wp_normalize_path( WP_CONTENT_DIR . '/wppo-redis-config.php' );
			if ( $wp_filesystem && $wp_filesystem->exists( $redis_config_file ) ) {
				$wp_filesystem->delete( $redis_config_file );
			}

			// Remove WP_CACHE constant from wp-config.php.
			self::remove_wp_cache_constant();
			require_once WPPO_PLUGIN_PATH . 'includes/class-log.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-cache.php';
			Log::add( __( 'Plugin deactivated', 'performance-optimisation' ) );
			Cache::clear_cache();
		}

		/**
		 * Unschedule cron jobs.
		 *
		 * Removes any scheduled cron jobs created by the plugin.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		private static function unschedule_crons(): void {
			// Unschedule the 'wppo_page_cron_hook' event if it is scheduled.
			$timestamp = wp_next_scheduled( 'wppo_page_cron_hook' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'wppo_page_cron_hook' );
			}

			$timestamp = wp_next_scheduled( 'wppo_page_cron_batch' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'wppo_page_cron_batch' );
			}

			// Unschedule image conversion cron events (old + new hook name for backward compat).
			foreach ( array( 'wppo_img_conversation', 'wppo_img_conversion' ) as $hook ) {
				$timestamp = wp_next_scheduled( $hook );
				if ( $timestamp ) {
					wp_unschedule_event( $timestamp, $hook );
				}
			}

			$timestamp = wp_next_scheduled( 'wppo_generate_static_page' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'wppo_generate_static_page' );
			}
		}

		/**
		 * Unschedule the database cleanup cron job.
		 *
		 * @since 1.6.0
		 * @return void
		 */
		private static function unschedule_database_cleanup_cron(): void {
			$timestamp = wp_next_scheduled( 'wppo_database_cleanup_cron' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'wppo_database_cleanup_cron' );
			}
		}

		/**
		 * Removes WP_CACHE constant from wp-config.php file if present.
		 *
		 * Ensures that the constant enabling WordPress caching is deleted
		 * during deactivation to prevent conflicts.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		private static function remove_wp_cache_constant(): void {
			global $wp_filesystem;

			if ( ! $wp_filesystem && ! Util::init_filesystem() ) {
				return;
			}

			$wp_config_path = wp_normalize_path( ABSPATH . 'wp-config.php' );

			if ( ! $wp_filesystem->exists( $wp_config_path ) ) {
				$wp_config_path = wp_normalize_path( dirname( ABSPATH ) . '/wp-config.php' );
			}

			if ( ! $wp_filesystem->is_writable( $wp_config_path ) ) {
				return;
			}

			$wp_config_content = $wp_filesystem->get_contents( $wp_config_path );

			$pattern = '/\/\*\*\s*Enables WordPress Cache\s*\*\/\s*(?:\r?\n|\n)if\s*\(\s*!\s*defined\s*\(\s*[\'"]WP_CACHE[\'"]\s*\)\s*\)\s*\{\s*define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*true\s*\)\s*;\s*\}\s*/';

			if ( preg_match( $pattern, $wp_config_content, $matches ) ) {
				$wp_config_content = preg_replace( $pattern, '', $wp_config_content );
			} else {
				$wp_config_content = preg_replace( '/\n?define\(\s*[\'"]WP_CACHE[\'"]\s*,\s*true\s*\);\s*/', '', $wp_config_content );
			}

			$wp_filesystem->put_contents( $wp_config_path, $wp_config_content, FS_CHMOD_FILE );
		}
	}
}
