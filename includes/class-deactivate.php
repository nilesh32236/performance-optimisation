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

			require_once WPPO_PLUGIN_PATH . 'includes/class-advanced-cache-handler.php';

			Advanced_Cache_Handler::remove();

			// Remove WP_CACHE constant from wp-config.php
			self::remove_wp_cache_constant();
			new Log( 'Plugin deactivated on ' );
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
			// Unschedule the 'wppo_page_cron_hook' event if it is scheduled
			$timestamp = wp_next_scheduled( 'wppo_page_cron_hook' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'wppo_page_cron_hook' );
			}

			// Unschedule the 'wppo_img_conversation' event if it is scheduled
			$timestamp = wp_next_scheduled( 'wppo_img_conversation' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'wppo_img_conversation' );
			}

			$timestamp = wp_next_scheduled( 'wppo_generate_static_page' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'wppo_img_conversation' );
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

			Util::init_filesystem();

			if ( ! $wp_filesystem && ! Util::init_filesystem() ) {
				return;
			}

			$wp_config_path = ABSPATH . 'wp-config.php'; // Path to wp-config.php

			if ( ! $wp_filesystem->is_writable( $wp_config_path ) ) {
				return;
			}

			if ( ! file_exists( $wp_config_path ) || ! $wp_filesystem->is_writable( $wp_config_path ) ) {
				return; // Exit if the file doesn't exist or is not writable
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
