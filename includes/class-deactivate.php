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
			//   stale Cloudflare/Bunny/Varnish copies do not outlive the plugin
			//   (both purgers no-op when their integration is not configured);
			// - Image_Optimisation::clear_runtime_caches() is a cheap local
			//   stat-cache reset with no external side effects.
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
		 * Note: wppo_litespeed_crawler_batch / wppo_crawler_warm are dual-scheduled
		 * (WP-Cron + AS) and are cleaned in both paths; wppo_generate_ccss is
		 * AS-only and therefore lives here, not in Cron::SCHEDULED_HOOKS.
		 *
		 * @since NEXT
		 * @return void
		 */
		private static function unschedule_action_scheduler_jobs(): void {
			if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
				return;
			}

			$as_hooks = array(
				'wppo_convert_image_background',
				'wppo_pagespeed_scan',
				'wppo_used_css_generate',
				'wppo_generate_ccss',
				'wppo_litespeed_crawler_batch',
				'wppo_crawler_warm',
			);

			foreach ( $as_hooks as $hook ) {
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
