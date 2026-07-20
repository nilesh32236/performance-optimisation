<?php
/**
 * Uninstall script for Performance Optimisation.
 *
 * Cleans up all plugin data including database tables, options,
 * post meta, cache directories, and drop-in files.
 *
 * @package PerformanceOptimise
 * @since 1.6.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/**
 * Clean up plugin data for a single site.
 *
 * @return void
 */
function wppo_cleanup_site(): void {
	global $wpdb;

	// Drop custom table.
	$table_name = $wpdb->prefix . 'wppo_activity_logs';
	$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

	// Delete options.
	delete_option( 'wppo_settings' );
	delete_option( 'wppo_img_info' );
	delete_option( 'wppo_transient_index' );
	delete_option( 'wppo_preload_cron_offset' );
	delete_option( 'wppo_last_db_cleanup' );

	// Delete post meta.
	$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->postmeta,
		array( 'meta_key' => '_wppo_preload_image_url' )
	);
	$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->postmeta,
		array( 'meta_key' => '_wppo_disabled_scripts' )
	);
	$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->postmeta,
		array( 'meta_key' => '_wppo_disabled_styles' )
	);

	// Remove cache directory.
	$cache_dir = WP_CONTENT_DIR . '/cache/wppo/';
	wppo_delete_directory( $cache_dir );

	// Remove converted images directory.
	$wppo_dir = WP_CONTENT_DIR . '/wppo/';
	wppo_delete_directory( $wppo_dir );

	// Remove Redis config file.
	$redis_config = WP_CONTENT_DIR . '/wppo-redis-config.php';
	if ( file_exists( $redis_config ) ) {
		wp_delete_file( $redis_config );
	}

	// Remove advanced-cache.php drop-in if it belongs to this plugin.
	$advanced_cache = WP_CONTENT_DIR . '/advanced-cache.php';
	if ( file_exists( $advanced_cache ) ) {
		$content = file_get_contents( $advanced_cache ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false !== strpos( $content, 'WPPO_ADVANCED_CACHE_DROPIN' ) || false !== strpos( $content, 'is_user_logged_in_without_wp' ) ) {
			wp_delete_file( $advanced_cache );
		}
	}

	// Remove object-cache.php drop-in if it belongs to this plugin.
	$object_cache = WP_CONTENT_DIR . '/object-cache.php';
	if ( file_exists( $object_cache ) ) {
		$content = file_get_contents( $object_cache ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false !== strpos( $content, 'Redis Object Cache Drop-in for Performance Optimisation' ) ) {
			wp_delete_file( $object_cache );
		}
	}

	// Delete transients.
	delete_transient( 'wppo_activation_notices' );
	delete_transient( 'wppo_show_welcome_notice' );
	delete_transient( 'wppo_cache_size' );
	delete_transient( 'wppo_total_js_css' );
	delete_transient( 'wppo_wp_cache_fix_checked' );
}

/**
 * Recursively delete a directory using WP_Filesystem.
 *
 * @param string $dir Absolute path to the directory.
 * @return void
 */
function wppo_delete_directory( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	global $wp_filesystem;

	if ( ! $wp_filesystem && ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	WP_Filesystem();

	if ( ! $wp_filesystem ) {
		return;
	}

	$wp_filesystem->rmdir( $dir, true );
}

// Clean up current site.
wppo_cleanup_site();

// Clean up all sites in a multisite network.
if ( is_multisite() && function_exists( 'get_sites' ) ) {
	$sites = get_sites();
	foreach ( $sites as $site ) {
		switch_to_blog( $site->blog_id );
		wppo_cleanup_site();
		restore_current_blog();
	}
}
