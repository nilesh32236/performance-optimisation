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
		delete_option( 'wppo_version' );
		delete_option( 'wppo_block_assets_migrated' );
		delete_option( 'wppo_cache_last_cleared' );
		delete_option( 'wppo_cache_last_cleared_time' );
		delete_option( 'wppo_activation_time' );
		delete_option( 'wppo_activity_cache_version' );
		delete_option( 'wppo_audit_salt' );
		delete_option( 'wppo_db_cleanup_salt' );
		delete_option( 'wppo_activity_log_salt' );
		delete_option( 'wppo_img_info_salt' );
		delete_option( 'wppo_review_dismissed' );
		delete_option( 'wppo_review_snoozed_until' );
		// Orphan options introduced after 1.9.0 (rum/trends/ai/lcp).
		delete_option( 'wppo_web_vitals_rum' );
		delete_option( 'wppo_web_vitals_trends' );
		delete_option( 'wppo_web_vitals_trends_lock' );
		delete_option( 'wppo_web_vitals_last_rescan' );
		delete_option( 'wppo_ai_model' );
		// Front-page LCP URL options (per strategy).
		delete_option( 'wppo_front_page_lcp_mobile' );
		delete_option( 'wppo_front_page_lcp_desktop' );
		// Wildcard sweep for any future wppo_* options that may have been
		// added without an explicit delete. Keeps uninstall idempotent.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wppo_%'" );

		// Delete post meta using the meta API to respect hooks.
		delete_post_meta_by_key( '_wppo_preload_image_url' );
		delete_post_meta_by_key( '_wppo_disabled_scripts' );
		delete_post_meta_by_key( '_wppo_disabled_styles' );

		// Remove cache directory.
		// NOTE: This path must stay in sync with Cache::CACHE_DIR constant in includes/class-cache.php.
		$cache_dir = WP_CONTENT_DIR . '/cache/wppo/';
		wppo_delete_directory( $cache_dir );

		// Remove converted images directory.
		// NOTE: This path must stay in sync with Img_Converter class uploads paths in includes/class-img-converter.php.
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

		// Delete user meta.
		delete_user_meta_by_key( 'wppo_welcome_dismissed' );

		// Delete transients.
		$transient_prefix = is_multisite() ? get_current_blog_id() . '_' : '';
		delete_transient( $transient_prefix . 'wppo_activation_notices' );
		delete_transient( $transient_prefix . 'wppo_show_welcome_notice' );
		delete_transient( $transient_prefix . 'wppo_cache_size' );
		delete_transient( $transient_prefix . 'wppo_total_js_css' );
		delete_transient( $transient_prefix . 'wppo_wp_cache_fix_checked' );
		delete_transient( $transient_prefix . 'wppo_rum_queue' );
		delete_transient( $transient_prefix . 'wppo_rum_flush_lock' );
		delete_transient( $transient_prefix . 'wppo_web_vitals_rescan_lock' );
		delete_transient( $transient_prefix . 'wppo_preload_cron_lock' );
		delete_transient( $transient_prefix . 'wppo_used_css_lock' );
		delete_transient( $transient_prefix . 'wppo_img_convert_lock' );
		delete_transient( $transient_prefix . 'wppo_db_cleanup_lock' );
		delete_transient( $transient_prefix . 'wppo_db_cleanup_counts' );
		delete_transient( $transient_prefix . 'wppo_ai_learn_lock' );
		delete_transient( $transient_prefix . 'wppo_edge_purge_lock' );
		// Wildcard sweep for remaining wppo_ transients (ratelimit_*, ccss_status_*,
		// pagespeed_*, cache_write_*, inline_drift_*, etc.) that are stored as
		// options when no external object cache is present.
		$like_transient = $wpdb->esc_like( '_transient_' . $transient_prefix . 'wppo_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_transient ) );
		$like_timeout = $wpdb->esc_like( '_transient_timeout_' . $transient_prefix . 'wppo_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_timeout ) );
	}
}

if ( ! function_exists( 'wppo_delete_directory' ) ) {
	/**
	 * Recursively delete a directory using native PHP (safe for uninstall context).
	 *
	 * Symlink guard: never follow symlinks — delete the link itself. This
	 * prevents a planted symlink inside cache/wppo from causing arbitrary
	 * directory deletion on uninstall (classic symlink traversal).
	 *
	 * @param string $dir Absolute path to the directory.
	 * @return void
	 * @since NEXT Symlink traversal hardening (is_link guard).
	 */
	function wppo_delete_directory( string $dir ): void {
		// If $dir itself is a symlink, delete the link only — do not follow.
		// @since NEXT — added.
		if ( is_link( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return;
		}

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

			// Symlink guard: delete the link itself, never recurse into it.
			// Must be before is_dir() because is_dir() follows symlinks.
			// @since NEXT — added.
			if ( is_link( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				continue;
			}

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
