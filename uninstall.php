<?php
/**
 * Uninstall script for Performance Optimisation
 *
 * This script is executed when the plugin is deleted from the WordPress admin.
 * It removes all plugin data, including options, custom database tables, and cached files.
 *
 * @package PerformanceOptimisation
 * @since 2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete options.
delete_option( 'wppo_settings' );
delete_option( 'wppo_version' );
delete_option( 'wppo_img_info' );
delete_option( 'wppo_setup_wizard_completed' );

// Delete custom tables with proper escaping
global $wpdb;
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %s', $wpdb->prefix . 'wppo_performance_stats' ) );
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %s', $wpdb->prefix . 'wppo_cache_queue' ) );

// Clear cron events.
wp_clear_scheduled_hook( 'wppo_cleanup_cache' );
wp_clear_scheduled_hook( 'wppo_optimize_images' );
wp_clear_scheduled_hook( 'wppo_page_cron_hook' );
wp_clear_scheduled_hook( 'wppo_img_conversation' );

// Remove cached files with proper validation
$cache_dir = WP_CONTENT_DIR . '/cache/wppo';
if ( is_dir( $cache_dir ) ) {
	// Validate path is within WP_CONTENT_DIR for security
	$real_cache_dir   = realpath( $cache_dir );
	$real_content_dir = realpath( WP_CONTENT_DIR );

	if ( $real_cache_dir && $real_content_dir && strpos( $real_cache_dir, $real_content_dir ) === 0 ) {
		function wppo_recursive_rmdir( $dir ) {
			if ( ! is_dir( $dir ) ) {
				return false;
			}

			// Additional security check
			if ( strpos( realpath( $dir ), realpath( WP_CONTENT_DIR ) ) !== 0 ) {
				return false;
			}

			$objects = scandir( $dir );
			foreach ( $objects as $object ) {
				if ( $object != '.' && $object != '..' ) {
					$path = $dir . '/' . $object;
					if ( is_dir( $path ) ) {
						wppo_recursive_rmdir( $path );
					} else {
						unlink( $path );
					}
				}
			}
			return rmdir( $dir );
		}
		wppo_recursive_rmdir( $cache_dir );
	}
}
