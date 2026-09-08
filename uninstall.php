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
		//
		// Must stay in sync with Util::UNINSTALL_OPTIONS in
		// includes/class-util.php (audit #899) — this file runs standalone
		// under WP_UNINSTALL_PLUGIN, without the plugin's classes autoloaded.
		$transient_prefix = is_multisite() ? (string) get_current_blog_id() . '_' : '';
		$wppo_options     = array(
			'wppo_settings',
			'wppo_img_info',
			'wppo_transient_index',
			'wppo_preload_cron_offset',
			'wppo_last_db_cleanup',
			'wppo_version',
			'wppo_block_assets_migrated',
			'wppo_cache_last_cleared',
			'wppo_cache_last_cleared_time',
			'wppo_activation_time',
			'wppo_activity_cache_version',
			'wppo_audit_salt',
			'wppo_db_cleanup_salt',
			'wppo_activity_log_salt',
			'wppo_img_info_salt',
			'wppo_review_dismissed',
			'wppo_review_snoozed_until',
			'wppo_web_vitals_rum',
			'wppo_ai_model',
			'wppo_web_vitals_trends',
			'wppo_web_vitals_trends_lock',
			'wppo_web_vitals_last_rescan',
			'wppo_preload_cron_last_id',
			'wppo_preload_cron_migrated',
			// Image library scan cursors (class-img-converter.php) — previously
			// only removed on deactivation, never on uninstall (audit #899).
			'wppo_img_scan_cursor',
			'wppo_img_scan_cursor_max',
			// LiteSpeed purge-queue fallback option. The real name is
			// blog-prefixed on multisite via Util::transient_key()
			// (LiteSpeed_Integration::get_db_queue_key()), so compose it here
			// (audit #899) — a plain-name delete orphaned per-site rows.
			$transient_prefix . 'wppo_litespeed_purge_queue',
		);
		foreach ( $wppo_options as $wppo_option ) {
			delete_option( $wppo_option );
		}

		// Dynamic per-strategy front-page LCP image URL options
		// (wppo_front_page_lcp_{mobile|desktop} — Pagespeed::store_lcp_image_url())
		// were deleted nowhere (audit #899). The strategy suffix is dynamic, so
		// remove them with an options-table LIKE match inside the per-site
		// cleanup (this runs under switch_to_blog() in the multisite loop).
		$like_lcp = $wpdb->esc_like( 'wppo_front_page_lcp_' ) . '%';
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '{$like_lcp}'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Delete post meta using the meta API to respect hooks.
		delete_post_meta_by_key( '_wppo_preload_image_url' );
		delete_post_meta_by_key( '_wppo_disabled_scripts' );
		delete_post_meta_by_key( '_wppo_disabled_styles' );

		// Per-strategy LCP image URL post meta (`_wppo_lcp_image_url_{strategy}`,
		// written by Pagespeed::store_lcp_image_url(), read by the LCP
		// prioritizer) has a dynamic strategy suffix, so remove it with a
		// postmeta LIKE sweep — a per-key delete would miss strategies
		// (PR #912 review follow-up). Runs inside the per-site cleanup, so
		// $wpdb->postmeta already points at the current blog's table.
		$like_lcp_meta = $wpdb->esc_like( '_wppo_lcp_image_url_' ) . '%';
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '{$like_lcp_meta}'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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

		// Delete transients (prefix computed above with the option cleanup).
		delete_transient( $transient_prefix . 'wppo_activation_notices' );
		delete_transient( $transient_prefix . 'wppo_show_welcome_notice' );
		delete_transient( $transient_prefix . 'wppo_cache_size' );
		delete_transient( $transient_prefix . 'wppo_total_js_css' );
		delete_transient( $transient_prefix . 'wppo_wp_cache_fix_checked' );
		delete_transient( $transient_prefix . 'wppo_crawler_server_ip' );

		// Bulk-delete remaining plugin transients (per-URL crawler blacklist
		// entries, rate-limit counters, CCSS statuses, ESI nonces, audit
		// results, lock keys, and their timeout rows). Deleting transients is
		// always safe — they are regenerable caches.
		$like_transient = $wpdb->esc_like( '_transient_' ) . '%';
		$like_site      = $wpdb->esc_like( '_site_transient_' ) . '%';
		$like_wppo      = '%' . $wpdb->esc_like( 'wppo_' ) . '%';
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE ( option_name LIKE '{$like_transient}' OR option_name LIKE '{$like_site}' ) AND option_name LIKE '{$like_wppo}'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * Path-containment guard: refuse to delete anything outside
	 * WP_CONTENT_DIR (normalised prefix + realpath check), so a crafted
	 * path can never escape to the filesystem root.
	 *
	 * @param string $dir Absolute path to the directory.
	 * @return void
	 * @since NEXT Symlink traversal hardening (is_link guard).
	 * @since NEXT Path-containment guard (WP_CONTENT_DIR prefix + realpath).
	 */
	function wppo_delete_directory( string $dir ): void {
		// If $dir itself is a symlink, delete the link only — do not follow.
		// @since NEXT — added.
		if ( is_link( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return;
		}

		// Path-containment guard: never delete outside WP_CONTENT_DIR.
		// @since NEXT — added.
		$normalized_dir  = wp_normalize_path( $dir );
		$normalized_root = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );

		if ( trailingslashit( $normalized_dir ) === $normalized_root ) {
			return;
		}

		if ( preg_match( '#(^|/)\.\.(/|$)#', $normalized_dir ) ) {
			return;
		}

		$real_dir  = realpath( $dir );
		$real_root = realpath( WP_CONTENT_DIR );

		if ( false !== $real_dir && false !== $real_root ) {
			$normalized_real_dir  = wp_normalize_path( $real_dir );
			$normalized_real_root = trailingslashit( wp_normalize_path( $real_root ) );

			if ( trailingslashit( $normalized_real_dir ) === $normalized_real_root ) {
				return;
			}

			if ( 0 !== strpos( $normalized_real_dir, $normalized_real_root ) ) {
				return;
			}
		} elseif ( 0 !== strpos( $normalized_dir, $normalized_root ) ) {
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
