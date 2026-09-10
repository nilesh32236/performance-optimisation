<?php
/**
 * Tests for safe-by-default used CSS (issue #1023).
 *
 * Covers safelist presets (Elementor + popup), the filterable
 * wppo_used_css_safelist hook, the coupled page-cache + used-CSS purge,
 * single-post requeue, and builder-drift hook registration.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Builder_Purge_Watcher;
use PerformanceOptimise\Inc\Used_CSS;
use Brain\Monkey\Functions;

/**
 * Tests for safe-by-default used CSS.
 *
 * @package PerformanceOptimise\Tests
 */
class UsedCssSafeDefaultsTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Presets ship Elementor + popup selectors.
	 */
	public function test_safelist_presets_cover_elementor_and_popups(): void {
		$presets = Used_CSS::get_safelist_presets();
		$this->assertContains( '.elementor-', $presets );
		$this->assertContains( '.elementor-popup-', $presets );
		$this->assertContains( '.e-popup-', $presets );
		$this->assertContains( '.dialog-', $presets );
		$this->assertContains( '.popup-', $presets );
		$this->assertContains( '.modal-', $presets );
	}

	/**
	 * Popup selectors are kept even when absent from the DOM walk.
	 */
	public function test_popup_selector_is_kept_by_default(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array() );
		$used_css = new Used_CSS( array( 'file_optimisation' => array() ) );
		$this->assertTrue(
			$used_css->is_selector_used(
				'.elementor-popup-modal .dialog-widget-content',
				array(
					'tags'    => array(),
					'classes' => array(),
					'ids'     => array(),
					'attrs'   => array(),
				)
			)
		);
		$this->assertTrue(
			$used_css->is_selector_used(
				'.mfp-content',
				array(
					'tags'    => array(),
					'classes' => array(),
					'ids'     => array(),
					'attrs'   => array(),
				)
			)
		);
	}

	/**
	 * The wppo_used_css_safelist filter can extend the safelist.
	 */
	public function test_safelist_filter_extends_kept_selectors(): void {
		Functions\when( 'has_filter' )->alias(
			static function ( $hook ) {
				return 'wppo_used_css_safelist' === $hook ? 10 : false;
			}
		);
		add_filter(
			'wppo_used_css_safelist',
			static function ( $safelist ) {
				$safelist[] = '.my-keep-';
				return $safelist;
			}
		);

		$used_css = new Used_CSS( array( 'file_optimisation' => array() ) );
		$this->assertTrue(
			$used_css->is_selector_used(
				'.my-keep-widget',
				array(
					'tags'    => array(),
					'classes' => array(),
					'ids'     => array(),
					'attrs'   => array(),
				)
			)
		);
	}

	/**
	 * Coupled purge returns per-store results without fatal when stores are unavailable.
	 */
	public function test_purge_coupled_returns_per_store_results(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array() );
		$result = Used_CSS::purge_coupled();
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'page_cache', $result );
		$this->assertArrayHasKey( 'used_css', $result );
	}

	/**
	 * Requeue is a no-op when removeUnusedCSS is off.
	 */
	public function test_requeue_for_post_noop_when_feature_off(): void {
		Functions\when( 'get_option' )->justReturn( array( 'file_optimisation' => array( 'removeUnusedCSS' => false ) ) );
		$this->assertFalse( Used_CSS::requeue_for_post( 123 ) );
	}

	/**
	 * Requeue enqueues a job when the feature is on.
	 */
	public function test_requeue_for_post_enqueues_when_feature_on(): void {
		Functions\when( 'get_option' )->justReturn( array( 'file_optimisation' => array( 'removeUnusedCSS' => true ) ) );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		$enqueued = array();
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( $hook, $args = array(), $group = '' ) use ( &$enqueued ) {
				$enqueued[] = array( $hook, $args, $group );
				return 1;
			}
		);
		$this->assertTrue( Used_CSS::requeue_for_post( 42 ) );
		$this->assertSame( 'wppo_used_css_generate', $enqueued[0][0] );
		$this->assertSame( array( 'post_id' => 42 ), $enqueued[0][1] );
	}

	/**
	 * Drift check fails open (false) when no used-CSS sidecar exists.
	 */
	public function test_builder_drift_fails_open_without_sidecar(): void {
		Functions\when( 'get_option' )->justReturn( array( 'file_optimisation' => array( 'removeUnusedCSS' => true ) ) );
		Functions\when( 'get_permalink' )->justReturn( 'http://example.com/sample-page/' );
		$this->assertFalse( Used_CSS::maybe_requeue_on_builder_drift( 99 ) );
	}

	/**
	 * The watcher registers builder-drift hooks alongside the upgrader hook.
	 */
	public function test_watcher_registers_drift_hooks(): void {
		$calls = array();
		Functions\when( 'add_action' )->alias(
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature must match add_action().
			static function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$calls ) {
				$calls[] = $hook;
			}
		);
		( new Builder_Purge_Watcher() )->register();
		$this->assertContains( 'upgrader_process_complete', $calls );
		$this->assertContains( 'elementor/core/files/clear_cache', $calls );
		$this->assertContains( 'elementor/editor/after_save', $calls );
	}
}
