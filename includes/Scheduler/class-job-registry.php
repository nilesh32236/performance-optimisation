<?php
/**
 * Canonical scheduler job ownership declarations.
 *
 * @package PerformanceOptimise\Inc
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Job_Registry' ) ) {
	/**
	 * Owns the plugin's WP-Cron and Action Scheduler hook manifest.
	 *
	 * The declarations are intentionally data-only so standalone uninstall can
	 * require this class without booting Composer, Main, or feature code.
	 *
	 * @since NEXT
	 */
	class Job_Registry {
		/**
		 * Canonical recurring and single-event WP-Cron hooks owned by the plugin.
		 *
		 * @var string[]
		 * @since NEXT
		 */
		public const CRON_HOOKS = array(
			'wppo_page_cron_hook',
			'wppo_page_cron_batch',
			'wppo_generate_static_page',
			'wppo_generate_static_url',
			'wppo_preload_url_batch',
			'wppo_img_conversion',
			'wppo_database_cleanup_cron',
			'wppo_web_vitals_rescan',
			'wppo_llms_txt_daily',
			'wppo_used_css_cron',
			'wppo_ccss_regeneration',
			'wppo_rum_flush',
			'wppo_run_upgrades',
			'wppo_litespeed_crawler_batch',
			'wppo_crawler_warm',
			'wppo_generate_ccss',
			'wppo_object_cache_probe',
		);

		/**
		 * WP-Cron single-event fallbacks owned by feature adapters.
		 *
		 * @var string[]
		 * @since NEXT
		 */
		public const CRON_FALLBACK_HOOKS = array(
			'wppo_google_fonts_download',
			'wppo_builder_drift_purge',
			'wppo_upgrade_purge',
		);

		/**
		 * Legacy hook spellings retained for backwards-compatible teardown.
		 *
		 * @var string[]
		 * @since NEXT
		 */
		public const LEGACY_CRON_HOOKS = array( 'wppo_img_conversation' );

		/**
		 * Action Scheduler hooks owned by the plugin.
		 *
		 * @var string[]
		 * @since NEXT
		 */
		public const AS_HOOKS = array(
			'wppo_convert_image_background',
			'wppo_pagespeed_scan',
			'wppo_used_css_generate',
			'wppo_generate_ccss',
			'wppo_litespeed_crawler_batch',
			'wppo_crawler_warm',
			'wppo_google_fonts_download',
			'wppo_builder_drift_purge',
			'wppo_upgrade_purge',
		);

		/**
		 * Action Scheduler group owned by the plugin.
		 *
		 * @var string
		 * @since NEXT
		 */
		public const AS_GROUP = 'performance_optimisation';

		/**
		 * Return every owned WP-Cron hook for teardown.
		 *
		 * @return string[]
		 * @since NEXT
		 */
		public static function all_cron_hooks(): array {
			return array_values(
				array_unique(
					array_merge(
						self::CRON_HOOKS,
						self::CRON_FALLBACK_HOOKS,
						self::LEGACY_CRON_HOOKS
					)
				)
			);
		}

		/**
		 * Return every owned Action Scheduler hook for teardown.
		 *
		 * @return string[]
		 * @since NEXT
		 */
		public static function all_action_scheduler_hooks(): array {
			return self::AS_HOOKS;
		}
	}
}
