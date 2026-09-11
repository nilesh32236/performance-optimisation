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

if ( ! function_exists( 'wppo_cleanup_network_files' ) ) {
	/**
	 * Clean up network-global filesystem artifacts (runs once per process).
	 *
	 * Covers the static HTML cache, converted-image dir, redis config, both
	 * drop-ins (+ `.wppo-backup` / `.tmp.*` siblings), and the `.htaccess`
	 * wppo_rules marker (+ `.wppo-bak` / `.wppo-tmp-*` siblings). These paths
	 * live under WP_CONTENT_DIR / the site home directory and are shared by
	 * every subsite, so the multisite loop must not repeat them per site —
	 * a static once-guard makes repeats a no-op (fail-open throughout).
	 *
	 * @return void
	 */
	function wppo_cleanup_network_files(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		// Remove cache directory.
		// NOTE: This path must stay in sync with Cache::CACHE_DIR constant in includes/class-cache.php.
		$cache_dir = WP_CONTENT_DIR . '/cache/wppo/';
		wppo_delete_directory( $cache_dir );

		// Remove converted images directory.
		// NOTE: This path must stay in sync with Img_Converter class uploads paths in includes/class-img-converter.php.
		$wppo_dir = WP_CONTENT_DIR . '/wppo/';
		wppo_delete_directory( $wppo_dir );

		// Remove Redis config file (verified: retry once, fail-open).
		$redis_config = WP_CONTENT_DIR . '/wppo-redis-config.php';
		if ( file_exists( $redis_config ) ) {
			wp_delete_file( $redis_config );
			if ( file_exists( $redis_config ) ) {
				wp_delete_file( $redis_config );
			}
		}

		// Remove advanced-cache.php drop-in if it belongs to this plugin.
		$advanced_cache = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( file_exists( $advanced_cache ) ) {
			$content = file_get_contents( $advanced_cache ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== $content && ( false !== strpos( $content, 'WPPO_ADVANCED_CACHE_DROPIN' ) || false !== strpos( $content, 'is_user_logged_in_without_wp' ) ) ) {
				wp_delete_file( $advanced_cache );
				if ( file_exists( $advanced_cache ) ) {
					wp_delete_file( $advanced_cache );
				}
			}
		}
		// Sweep backup/tmp siblings left by atomic writes (crashed or not),
		// even when the live drop-in is already gone.
		wppo_cleanup_dropin_artifacts();

		// Remove object-cache.php drop-in if it belongs to this plugin.
		// Marker-verified (current + legacy with WPPO signal) — never delete foreign drop-ins (fail-open).
		$object_cache = WP_CONTENT_DIR . '/object-cache.php';
		if ( file_exists( $object_cache ) && is_readable( $object_cache ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_readable
			$size = filesize( $object_cache ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize
			if ( false !== $size && $size < 1048576 ) {
				$content = file_get_contents( $object_cache ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$is_own  = false;
				if ( is_string( $content ) ) {
					if ( class_exists( 'PerformanceOptimise\Inc\Object_Cache' ) && method_exists( 'PerformanceOptimise\Inc\Object_Cache', 'is_own_dropin_content' ) ) {
						$is_own = \PerformanceOptimise\Inc\Object_Cache::is_own_dropin_content( $content );
					} else {
						$is_own = ( false !== strpos( $content, 'Redis Object Cache Drop-in for Performance Optimisation' ) || ( false !== strpos( $content, 'Redis Object Cache Drop-in' ) && false !== strpos( $content, 'wppo-redis-config' ) ) );
					}
				}
				if ( $is_own ) {
					wp_delete_file( $object_cache );
					if ( file_exists( $object_cache ) ) {
						wp_delete_file( $object_cache );
					}
				}
			}
		}

		// Remove the .htaccess marker block (delete-plugin-without-deactivate
		// path) plus backup/tmp siblings so no residue survives uninstall.
		wppo_remove_htaccess_rules();
	}
}

if ( ! function_exists( 'wppo_cleanup_dropin_artifacts' ) ) {
	/**
	 * Delete advanced-cache backup/tmp siblings (fail-open).
	 *
	 * Removes `advanced-cache.php.wppo-backup` and orphaned
	 * `advanced-cache.php.tmp.*` files from crashed atomic writes.
	 *
	 * @return void
	 */
	function wppo_cleanup_dropin_artifacts(): void {
		$backup = WP_CONTENT_DIR . '/advanced-cache.php.wppo-backup';
		if ( file_exists( $backup ) ) {
			wp_delete_file( $backup );
		}
		$pattern = WP_CONTENT_DIR . '/advanced-cache.php.tmp.*';
		$matches = glob( $pattern ); // phpcs:ignore WordPress.WP.AlternativeFunctions.glob_glob
		if ( is_array( $matches ) ) {
			foreach ( $matches as $tmp_file ) {
				if ( is_file( $tmp_file ) ) {
					wp_delete_file( $tmp_file );
				}
			}
		}
	}
}

if ( ! function_exists( 'wppo_remove_htaccess_rules' ) ) {
	/**
	 * Remove the wppo_rules marker block from .htaccess (fail-open).
	 *
	 * Standalone-safe: uninstall runs without the plugin's classes, so the
	 * marker splice is implemented natively here. Deletes the
	 * `.htaccess.wppo-bak` backup generation and `.htaccess.wppo-tmp-*`
	 * orphans as well. Never fatals; residue is retried on the next
	 * deactivate/uninstall.
	 *
	 * @return void
	 */
	function wppo_remove_htaccess_rules(): void {
		$home_path = '';
		if ( function_exists( 'get_home_path' ) ) {
			$home_path = get_home_path();
		} elseif ( defined( 'ABSPATH' ) ) {
			$home_path = ABSPATH;
		}
		if ( ! is_string( $home_path ) || '' === $home_path ) {
			return;
		}
		$htaccess_file = rtrim( $home_path, '/\\' ) . '/.htaccess';
		if ( function_exists( 'wp_normalize_path' ) ) {
			$htaccess_file = wp_normalize_path( $htaccess_file );
		}

		if ( ! file_exists( $htaccess_file ) || ! is_readable( $htaccess_file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_readable
			wppo_cleanup_htaccess_artifacts( $htaccess_file );
			return;
		}

		$contents = file_get_contents( $htaccess_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $contents ) ) {
			return;
		}

		$begin = '# BEGIN wppo_rules';
		$end   = '# END wppo_rules';
		$start = strpos( $contents, $begin );
		$stop  = strpos( $contents, $end );
		if ( false !== $start && false !== $stop && $stop > $start ) {
			$line_end = strpos( $contents, "\n", $stop );
			$head     = substr( $contents, 0, $start );
			$tail     = false === $line_end ? '' : substr( $contents, $line_end + 1 );
			if ( '' === trim( (string) $tail ) ) {
				$new_contents = rtrim( (string) $head, "\r\n" );
				if ( '' !== $new_contents ) {
					$new_contents .= "\n";
				}
			} else {
				$new_contents = rtrim( (string) $head, "\r\n" ) . "\n" . ltrim( (string) $tail, "\r\n" );
			}
			if ( is_writable( $htaccess_file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
				file_put_contents( $htaccess_file, $new_contents, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}

		wppo_cleanup_htaccess_artifacts( $htaccess_file );
	}
}

if ( ! function_exists( 'wppo_cleanup_htaccess_artifacts' ) ) {
	/**
	 * Delete .htaccess backup/tmp siblings (fail-open).
	 *
	 * @param string $htaccess_file Absolute path to the .htaccess file.
	 * @return void
	 */
	function wppo_cleanup_htaccess_artifacts( string $htaccess_file ): void {
		$backup = $htaccess_file . '.wppo-bak';
		if ( file_exists( $backup ) ) {
			wp_delete_file( $backup );
		}
		$pattern = $htaccess_file . '.wppo-tmp-*';
		$matches = glob( $pattern ); // phpcs:ignore WordPress.WP.AlternativeFunctions.glob_glob
		if ( is_array( $matches ) ) {
			foreach ( $matches as $tmp_file ) {
				if ( is_file( $tmp_file ) ) {
					wp_delete_file( $tmp_file );
				}
			}
		}
	}
}

if ( ! function_exists( 'wppo_cleanup_site' ) ) {
	/**
	 * Clean up plugin data for a single site.
	 *
	 * Per-site data (options, post/user meta, activity table, transients) is
	 * removed on every call; network-global filesystem artifacts (cache dirs,
	 * drop-ins, redis config, .htaccess marker) are delegated to
	 * wppo_cleanup_network_files(), which runs once per process so the
	 * multisite loop below does not repeat shared file deletes per subsite.
	 *
	 * @param bool $clean_network_files Whether to clean network-global files.
	 * @return void
	 */
	function wppo_cleanup_site( bool $clean_network_files = true ): void {
		global $wpdb;

		// Drop custom table.
		$table_name = $wpdb->prefix . 'wppo_activity_logs';
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Delete options.
		//
		// Must stay in sync with Util::UNINSTALL_OPTIONS in
		// includes/class-util.php (audit #899) — this file runs standalone
		// under WP_UNINSTALL_PLUGIN, without the plugin's classes autoloaded.
		$transient_prefix = ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_current_blog_id' ) ) ? (string) get_current_blog_id() . '_' : '';
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
			// Autoload remediation priors (Database_Cleanup::REMEDIATED_OPTION, issue #934).
			'wppo_autoload_remediated',
			// One-time large-option autoload backfill flag.
			'wppo_autoload_migrated',
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

		// Network-global filesystem artifacts (cache dirs, drop-ins +
		// siblings, redis config, .htaccess marker) are shared by every
		// subsite — delegate to the once-guarded helper so the multisite
		// loop below does not repeat them per site.
		if ( $clean_network_files ) {
			wppo_cleanup_network_files();
		}

		// Delete user meta. `delete_metadata( 'user', null, ... )` is the core
		// metadata API; the previous helper was not a WordPress core function,
		// so it fataled here and aborted uninstall (compliance audit fix).
		delete_metadata( 'user', null, 'wppo_welcome_dismissed', '', true );

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
	 * @since 2.0.0 Symlink guard (is_link) + path-containment guard (WP_CONTENT_DIR prefix + realpath).
	 */
	function wppo_delete_directory( string $dir ): void {
		// If $dir itself is a symlink, delete the link only — do not follow.
		// @since 2.0.0 — added.
		if ( is_link( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return;
		}

		// Path-containment guard: never delete outside WP_CONTENT_DIR.
		// @since 2.0.0 — added.
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
			// @since 2.0.0 — added.
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

// Clean up current site (includes the once-guarded network files).
wppo_cleanup_site();

// Clean up all sites in a multisite network.
if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) ) {
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
			if ( function_exists( 'switch_to_blog' ) ) {
				switch_to_blog( $site->blog_id );
			}
			// Per-site data only — network files were already cleaned once
			// by the initial wppo_cleanup_site() call above (once-guarded).
			wppo_cleanup_site( false );
			if ( function_exists( 'restore_current_blog' ) ) {
				restore_current_blog();
			}
		}
		++$site_page;
	} while ( $has_more_sites );
}
