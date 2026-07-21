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

if ( ! function_exists( 'wppo_cleanup_site' ) ) {
	/**
	 * Clean up plugin data for a single site.
	 *
	 * @return void
	 */
	function wppo_cleanup_site(): void {
		global $wpdb;

		// Drop custom table.
		$table_name = $wpdb->prefix . 'wppo_activity_logs';
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Delete options.
		delete_option( 'wppo_settings' );
		delete_option( 'wppo_img_info' );
		delete_option( 'wppo_transient_index' );
		delete_option( 'wppo_preload_cron_offset' );
		delete_option( 'wppo_last_db_cleanup' );

		// Delete post meta using the meta API to respect hooks.
		delete_post_meta_by_key( '_wppo_preload_image_url' );
		delete_post_meta_by_key( '_wppo_disabled_scripts' );
		delete_post_meta_by_key( '_wppo_disabled_styles' );

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
			if ( false !== $content && ( false !== strpos( $content, 'WPPO_ADVANCED_CACHE_DROPIN' ) || false !== strpos( $content, 'is_user_logged_in_without_wp' ) ) ) {
				wp_delete_file( $advanced_cache );
			}
		}

		// Remove object-cache.php drop-in if it belongs to this plugin.
		$object_cache = WP_CONTENT_DIR . '/object-cache.php';
		if ( file_exists( $object_cache ) ) {
			$content = file_get_contents( $object_cache ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== $content && false !== strpos( $content, 'Redis Object Cache Drop-in for Performance Optimisation' ) ) {
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
}

if ( ! function_exists( 'wppo_delete_directory' ) ) {
	/**
	 * Recursively delete a directory using native PHP (safe for uninstall context).
	 *
	 * @param string $dir Absolute path to the directory.
	 * @return void
	 */
	function wppo_delete_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.scandir_scandir
		if ( false === $items ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $dir . '/' . $item;

			if ( is_dir( $path ) ) {
				wppo_delete_directory( $path );
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
		@rmdir( $dir );
	}
}

// Clean up current site.
wppo_cleanup_site();

// Clean up all sites in a multisite network.
if ( is_multisite() && function_exists( 'get_sites' ) ) {
	$site_page      = 1;
	$limit          = 100;
	$has_more_sites = true;
	do {
		$offset = ( $site_page - 1 ) * $limit;
		$sites  = get_sites(
			array(
				'number' => $limit,
				'offset' => $offset,
			)
		);
		if ( empty( $sites ) ) {
			break;
		}
		$has_more_sites = ( count( $sites ) === $limit );
		foreach ( $sites as $site ) {
			switch_to_blog( $site->blog_id );
			wppo_cleanup_site();
			restore_current_blog();
		}
		++$site_page;
	} while ( $has_more_sites );
}
