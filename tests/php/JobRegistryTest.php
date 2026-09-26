<?php
/**
 * Public contract tests for the Phase 3 scheduler job registry.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cron;
use PerformanceOptimise\Inc\Job_Registry;

/**
 * Scheduler job registry contract tests.
 *
 * @package PerformanceOptimise\Tests
 */
class JobRegistryTest extends \PHPUnit\Framework\TestCase {
	/**
	 * The registry exposes the complete owned scheduling surface exactly once.
	 *
	 * @return void
	 */
	public function test_registry_exposes_complete_owned_hook_sets(): void {
		$this->assertTrue( class_exists( Job_Registry::class ), 'Job_Registry must be autoloadable.' );
		if ( ! class_exists( Job_Registry::class, false ) ) {
			return;
		}

		$this->assertSame(
			array(
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
			),
			Job_Registry::CRON_HOOKS
		);
		$this->assertSame(
			array(
				'wppo_google_fonts_download',
				'wppo_builder_drift_purge',
				'wppo_upgrade_purge',
			),
			Job_Registry::CRON_FALLBACK_HOOKS
		);
		$this->assertSame( array( 'wppo_img_conversation' ), Job_Registry::LEGACY_CRON_HOOKS );
		$this->assertSame(
			array(
				'wppo_convert_image_background',
				'wppo_pagespeed_scan',
				'wppo_used_css_generate',
				'wppo_generate_ccss',
				'wppo_litespeed_crawler_batch',
				'wppo_crawler_warm',
				'wppo_google_fonts_download',
				'wppo_builder_drift_purge',
				'wppo_upgrade_purge',
			),
			Job_Registry::AS_HOOKS
		);
		$this->assertSame( 'performance_optimisation', Job_Registry::AS_GROUP );
	}

	/**
	 * Teardown unions canonical, fallback, and legacy WP-Cron hooks without duplicates.
	 *
	 * @return void
	 */
	public function test_cron_teardown_union_is_complete_and_unique(): void {
		$this->assertTrue( class_exists( Job_Registry::class ), 'Job_Registry must be autoloadable.' );
		if ( ! class_exists( Job_Registry::class, false ) ) {
			return;
		}

		$expected = array_merge(
			Job_Registry::CRON_HOOKS,
			Job_Registry::CRON_FALLBACK_HOOKS,
			Job_Registry::LEGACY_CRON_HOOKS
		);
		$this->assertSame( $expected, Job_Registry::all_cron_hooks() );
		$this->assertSame( count( $expected ), count( array_unique( $expected ) ) );
		$this->assertContains( 'wppo_builder_drift_purge', Job_Registry::all_cron_hooks() );
		$this->assertContains( 'wppo_upgrade_purge', Job_Registry::all_cron_hooks() );
	}

	/**
	 * Existing Cron constants remain public compatibility aliases.
	 *
	 * @return void
	 */
	public function test_cron_constants_delegate_to_registry(): void {
		$this->assertSame( Job_Registry::CRON_HOOKS, Cron::SCHEDULED_HOOKS );
		$this->assertSame( Job_Registry::AS_HOOKS, Cron::AS_HOOKS );
	}

	/**
	 * Deactivation teardown delegates to the registry's complete AS set.
	 *
	 * @return void
	 */
	public function test_deactivation_uses_registry_action_scheduler_set(): void {
		$source = file_get_contents( WPPO_PLUGIN_PATH . 'includes/Core/class-deactivate.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static deactivation source seam.
		$this->assertIsString( $source );
		$this->assertStringContainsString( 'Job_Registry::all_action_scheduler_hooks()', (string) $source );
		$this->assertStringNotContainsString( 'foreach ( Cron::AS_HOOKS', (string) $source );
	}
}
