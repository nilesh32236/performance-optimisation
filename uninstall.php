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

// Delete custom tables.
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wppo_performance_stats" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wppo_cache_queue" );

// Clear cron events.
wp_clear_scheduled_hook( 'wppo_cleanup_cache' );
wp_clear_scheduled_hook( 'wppo_optimize_images' );
wp_clear_scheduled_hook( 'wppo_page_cron_hook' );
wp_clear_scheduled_hook( 'wppo_img_conversation' );

// Remove cached files.
$cache_dir = WP_CONTENT_DIR . '/cache/wppo';
if ( is_dir( $cache_dir ) ) {
	// A simple recursive directory removal function.
	// For a production plugin, a more robust solution might be needed.
	function wppo_recursive_rmdir( $dir ) {
		if ( is_dir( $dir ) ) {
			$objects = scandir( $dir );
			foreach ( $objects as $object ) {
				if ( $object != '.' && $object != '..' ) {
					if ( is_dir( $dir . '/' . $object ) ) {
						wppo_recursive_rmdir( $dir . '/' . $object );
					} else {
						unlink( $dir . '/' . $object );
					}
				}
			}
			rmdir( $dir );
		}
	}
	wppo_recursive_rmdir( $cache_dir );
}
