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
		 * Cleans up resources by removing runtime hooks, cron jobs, static files,
		 * .htaccess modifications, and WP_CACHE constant.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public static function init(): void {
			// Remove plugin-owned runtime hooks first so teardown steps below
			// (option writes, cache clears) cannot re-trigger plugin behaviour
			// such as drop-in re-creation or CDN purge fan-out (audit #888 finding 1).
			self::unregister_runtime_hooks();

			// Unschedule every WP-Cron event the plugin may have scheduled, using
			// the canonical list in Cron::SCHEDULED_HOOKS (audit #888 findings 1+9).
			self::unschedule_crons();
			self::unschedule_action_scheduler_jobs();

			delete_option( 'wppo_preload_cron_offset' );
			delete_option( 'wppo_img_scan_cursor' );
			delete_option( 'wppo_img_scan_cursor_max' );

			// LiteSpeed purge-sync queues (audit #888 finding 5): the fallback
			// option has no natural expiry path once the plugin stops flushing.
			delete_option( LiteSpeed_Integration::get_db_queue_key() );
			delete_transient( Util::transient_key( 'wppo_lscache_tag_queue' ) );

			// Deactivation can change drop-in ownership (WPPO drop-ins removed
			// above, foreign ones may appear/disappear) — drop System Info's
			// cached verdicts immediately instead of waiting out the TTL.
			if ( is_callable( array( 'PerformanceOptimise\Inc\System_Info', 'flush_dropin_cache' ) ) ) {
				System_Info::flush_dropin_cache();
			}

			Advanced_Cache_Handler::remove();

			global $wp_filesystem;
			if ( ! $wp_filesystem ) {
				Util::init_filesystem();
			}

			// Remove Redis object cache drop-in if it belongs to this plugin.
			// Marker-verified (current + legacy with WPPO signal) — never delete foreign drop-ins.
			// Size-guarded like Object_Cache::is_own_dropin() so a large foreign
			// file is never read fully into memory during deactivation.
			$object_cache_file = wp_normalize_path( WP_CONTENT_DIR . '/object-cache.php' );
			if ( $wp_filesystem && $wp_filesystem->exists( $object_cache_file ) && is_readable( $object_cache_file ) ) {
				$dropin_size = filesize( $object_cache_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize
				if ( false !== $dropin_size && $dropin_size < 1048576 ) {
					$content = $wp_filesystem->get_contents( $object_cache_file );
					$is_own  = false;
					if ( is_string( $content ) ) {
						if ( method_exists( 'PerformanceOptimise\Inc\Object_Cache', 'is_own_dropin_content' ) ) {
							$is_own = Object_Cache::is_own_dropin_content( $content );
						} else {
							$is_own = ( false !== strpos( $content, 'Redis Object Cache Drop-in for Performance Optimisation' ) || ( false !== strpos( $content, 'Redis Object Cache Drop-in' ) && false !== strpos( $content, 'wppo-redis-config' ) ) );
						}
					}
					if ( $is_own ) {
						$wp_filesystem->delete( $object_cache_file );
					}
				}
			}

			// Remove Redis config file.
			$redis_config_file = wp_normalize_path( WP_CONTENT_DIR . '/wppo-redis-config.php' );
			if ( $wp_filesystem && $wp_filesystem->exists( $redis_config_file ) ) {
				$wp_filesystem->delete( $redis_config_file );
			}

			// Remove WP_CACHE constant from wp-config.php (fail-open: a
			// failed atomic edit keeps serving uncached via the drop-in
			// early return; the notice contract is logged, never fatal).
			$wp_cache_notice = self::remove_wp_cache_constant();
			if ( is_string( $wp_cache_notice ) && '' !== $wp_cache_notice ) {
				/* translators: %s: notice key describing the wp-config.php failure. */
				Log::add( sprintf( __( 'Failed to update wp-config.php during deactivation (%s).', 'performance-optimisation' ), $wp_cache_notice ) );
			}
			Log::add( __( 'Plugin deactivated', 'performance-optimisation' ) );
			Cache::clear_cache();
		}

		/**
		 * Remove plugin-owned runtime hooks for the remainder of this request.
		 *
		 * Scope (audit #888 finding 1):
		 * - plugin-owned `wppo_*` action/filter callbacks that could fire during
		 *   the deactivation request itself (settings writes, cache-clear side
		 *   effects);
		 * - structural-change hooks that would clear/purge caches after the
		 *   plugin's own teardown already did.
		 *
		 * Intentionally left in place:
		 * - core hook registrations (admin_menu, wp_head, template_redirect, …)
		 *   — WordPress clears all hooks at end of request and the plugin simply
		 *   does not load again after deactivation, so removing them is a no-op;
		 * - no output buffers are open during this admin-context request (they
		 *   only open on template_redirect), so there is nothing to unwind.
		 *
		 * Instance-method callbacks are removed via {@see Main::get_instance()}
		 * (null when the Main constructor never ran, e.g. in tests).
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function unregister_runtime_hooks(): void {
			$main = Main::get_instance();

			// Settings writes must not re-create the advanced-cache drop-in or
			// update .htaccess while the plugin is tearing itself down.
			remove_action( 'update_option_wppo_settings', array( Main::class, 'on_settings_update' ), 10 );

			// Cache-clear fan-out on structural changes is redundant after
			// Deactivate::init() has cleared the cache itself.
			remove_action( 'update_option_permalink_structure', array( Main::class, 'clear_all_cache' ) );
			remove_action( 'switch_theme', array( Main::class, 'clear_all_cache' ) );
			remove_action( 'update_option_home', array( Main::class, 'on_site_url_change' ), 10 );
			remove_action( 'update_option_siteurl', array( Main::class, 'on_site_url_change' ), 10 );
			remove_action( 'activated_plugin', array( Main::class, 'clear_all_cache' ) );
			remove_action( 'deactivated_plugin', array( Main::class, 'clear_all_cache' ) );

			if ( $main instanceof Main ) {
				remove_action( 'save_post', array( $main, 'on_save_post_invalidate_cache' ), 10 );
				remove_action( 'save_post', array( $main, 'on_save_post_queue_used_css' ), 10 );
			}

			// DB-cleanup counts invalidation is pointless once the plugin is gone.
			remove_action( 'save_post', array( 'PerformanceOptimise\Inc\Database_Cleanup', 'on_post_change' ), 10 );
			remove_action( 'deleted_post', array( 'PerformanceOptimise\Inc\Database_Cleanup', 'on_post_change' ), 10 );

			// Deliberately NOT removed: the wppo_after_cache_clear listeners.
			// Cache::clear_cache() below fires that action once more, and its
			// listeners are both safe and desirable during teardown:
			// - CDN_Purger/Edge_Purger purge_all() sends the final edge purge so
			// stale Cloudflare/Bunny/Varnish copies do not outlive the plugin
			// (both purgers no-op when their integration is not configured);
			// - Image_Optimisation::clear_runtime_caches() is a cheap local
			// stat-cache reset with no external side effects.
		}

		/**
		 * Unschedule cron jobs.
		 *
		 * Removes every WP-Cron event created by the plugin, derived from the
		 * canonical {@see Cron::SCHEDULED_HOOKS} list (plus the legacy
		 * `wppo_img_conversation` misspelling, cleared inside
		 * Cron::clear_cron_jobs()). Uses wp_unschedule_hook() which removes all
		 * events for a hook, recurring and single (audit #888 findings 1 + 9).
		 *
		 * @since 1.0.0
		 * @since NEXT Delegates to the canonical Cron::SCHEDULED_HOOKS list.
		 * @return void
		 */
		private static function unschedule_crons(): void {
			Cron::clear_cron_jobs();
		}

		/**
		 * Unschedule plugin-owned Action Scheduler pending jobs.
		 *
		 * Action Scheduler keeps its own queue in custom tables, so WP-Cron
		 * unscheduling does not touch it. Pending background jobs (image
		 * conversion, PageSpeed scans, used-CSS generation, critical CSS,
		 * crawler batches) are cancelled on deactivation (audit #888 finding 1).
		 * The hook list is the canonical {@see Cron::AS_HOOKS} const (single
		 * source of truth shared with the scheduling sites); the dual-scheduled
		 * crawler hooks are cleaned in both paths.
		 *
		 * @since NEXT
		 * @return void
		 */
		private static function unschedule_action_scheduler_jobs(): void {
			if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
				return;
			}

			foreach ( Cron::AS_HOOKS as $hook ) {
				as_unschedule_all_actions( $hook );
			}
		}

		/**
		 * Removes WP_CACHE constant from wp-config.php file if present.
		 *
		 * Ensures that the constant enabling WordPress caching is deleted
		 * during deactivation to prevent conflicts.
		 *
		 * @since 1.0.0
		 * @since NEXT Now atomic (tmp + verify + backup + rename with rollback) and returns a notice key on failure.
		 * @return string|null Notice key for the admin layer, or null on success / nothing to do.
		 */
		public static function remove_wp_cache_constant(): ?string {
			global $wp_filesystem;

			if ( ! $wp_filesystem && ! Util::init_filesystem() ) {
				return null;
			}

			$wp_config_path = wp_normalize_path( ABSPATH . 'wp-config.php' );

			if ( ! $wp_filesystem->exists( $wp_config_path ) ) {
				$wp_config_path = wp_normalize_path( dirname( ABSPATH ) . '/wp-config.php' );
			}

			if ( ! $wp_filesystem->is_writable( $wp_config_path ) ) {
				return null;
			}

			$wp_config_content = $wp_filesystem->get_contents( $wp_config_path );

			if ( ! is_string( $wp_config_content ) ) {
				return 'wp_config_read';
			}

			$pattern = '/\/\*\*\s*Enables WordPress Cache\s*\*\/\s*(?:\r?\n|\n)if\s*\(\s*!\s*defined\s*\(\s*[\'"]WP_CACHE[\'"]\s*\)\s*\)\s*\{\s*define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*true\s*\)\s*;\s*\}\s*/';

			$before = $wp_config_content;
			if ( preg_match( $pattern, $wp_config_content, $matches ) ) {
				$wp_config_content = preg_replace( $pattern, '', $wp_config_content );
			} else {
				$wp_config_content = preg_replace( '/\n?define\(\s*[\'"]WP_CACHE[\'"]\s*,\s*true\s*\);\s*/', '', $wp_config_content );
			}

			if ( ! is_string( $wp_config_content ) ) {
				return 'wp_config_write_failed';
			}

			if ( $wp_config_content === $before ) {
				return null;
			}

			// Atomic path: tmp write + verify + backup + rename with rollback,
			// mirroring Activate::add_wp_cache_constant(). Falls back to the
			// legacy direct write when the transport cannot support it.
			if ( method_exists( 'PerformanceOptimise\Inc\Util', 'atomic_write_php_verified' ) ) {
				$atomic = Util::atomic_write_php_verified(
					$wp_filesystem,
					$wp_config_path,
					$wp_config_content,
					static function ( $contents ): bool {
						// Only the plugin-owned marker block is asserted gone so a
						// host-managed WP_CACHE define elsewhere in the file survives
						// deactivation instead of spuriously failing verification.
						return is_string( $contents ) && false === strpos( $contents, 'Enables WordPress Cache' );
					}
				);
				if ( true === $atomic ) {
					return null;
				}
				if ( false === $atomic ) {
					return 'wp_config_write_failed';
				}
			}

			$ok = $wp_filesystem->put_contents( $wp_config_path, $wp_config_content, FS_CHMOD_FILE );

			return $ok ? null : 'wp_config_write_failed';
		}
	}
}
