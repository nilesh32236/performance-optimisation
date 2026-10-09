<?php
/**
 * Tests for the split Critical-CSS inline gate (issue #1706).
 *
 * `Critical_CSS::is_critical_css_inline_allowed()` owns the dedicated
 * `wppo_inline_critical_css` filter: when the new filter has callbacks it
 * wins, otherwise the legacy `wppo_inline_combined_css` filter applies as
 * a backward-compatible fallback. A CDN opt-out of the combined file
 * therefore no longer silently disables Critical CSS, while existing
 * single-filter setups keep their behaviour verbatim.
 *
 * Also pins `get_ccss_effective_state()` — the fail-open gate snapshot
 * surfaced via `get_ccss_status` so the SPA can warn when defer/delay
 * suspends emission.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Critical_CSS;
use PerformanceOptimise\Inc\Settings_Store;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Class CriticalCssGateTest.
 *
 * @package PerformanceOptimise\Tests
 */
class CriticalCssGateTest extends \PHPUnit\Framework\TestCase {

	use WPPO_Test_Bootstrap;

	/**
	 * Filter tag overrides consumed by the apply_filters stub.
	 *
	 * @var array
	 */
	private array $filter_overrides = array();

	/**
	 * Filter tags treated as having callbacks (has_filter stub).
	 *
	 * @var string[]
	 */
	private array $filters_present = array();

	/**
	 * In-memory option map backing the get_option stub.
	 *
	 * @var array
	 */
	private array $option_map = array();

	/**
	 * Stub the WP functions used by the gate methods.
	 *
	 * This setUp() shadows the trait setUp(), so the shared resets are
	 * replicated explicitly (per the trait docblock convention): Brain
	 * Monkey session, common stubs, settings memo, and CCSS memos.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		Util::reset_runtime_caches();
		Settings_Store::clear_settings_cache();
		Critical_CSS::reset_ccss_memo();

		$this->filter_overrides = array();
		$this->filters_present  = array();
		$this->option_map       = array();

		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value = null ) {
				return $this->filter_overrides[ $tag ] ?? $value;
			}
		);
		Functions\when( 'has_filter' )->alias(
			function ( $tag ) {
				return in_array( $tag, $this->filters_present, true );
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->option_map ) ? $this->option_map[ $name ] : $fallback;
			}
		);
		Functions\when( 'add_action' )->justReturn( true );
	}

	/**
	 * Stub file_optimisation settings for the defer/delay suspension guard.
	 *
	 * @param array $file_optimisation File optimisation settings.
	 * @return void
	 */
	private function stub_file_optimisation( array $file_optimisation ): void {
		$this->option_map['wppo_settings'] = array( 'file_optimisation' => $file_optimisation );
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
	}

	/**
	 * CDN opt-out no longer disables Critical CSS when the new gate allows it.
	 *
	 * @return void
	 */
	public function test_legacy_false_with_new_true_allows_ccss(): void {
		$this->filters_present                              = array( 'wppo_inline_critical_css' );
		$this->filter_overrides['wppo_inline_critical_css'] = true;
		$this->filter_overrides['wppo_inline_combined_css'] = false;

		$this->assertTrue( Critical_CSS::is_critical_css_inline_allowed() );
		$this->assertTrue( Critical_CSS::is_ccss_effective() );
	}

	/**
	 * The new gate wins when present: false blocks even with legacy true.
	 *
	 * @return void
	 */
	public function test_new_false_blocks_ccss_despite_legacy_true(): void {
		$this->filters_present                              = array( 'wppo_inline_critical_css' );
		$this->filter_overrides['wppo_inline_critical_css'] = false;
		$this->filter_overrides['wppo_inline_combined_css'] = true;

		$this->assertFalse( Critical_CSS::is_critical_css_inline_allowed() );
		$this->assertFalse( Critical_CSS::is_ccss_effective() );
	}

	/**
	 * Without the new filter the legacy value still applies (backward compat).
	 *
	 * @return void
	 */
	public function test_legacy_fallback_without_new_filter(): void {
		$this->filter_overrides['wppo_inline_combined_css'] = false;
		$this->assertFalse( Critical_CSS::is_critical_css_inline_allowed() );

		$this->filter_overrides['wppo_inline_combined_css'] = true;
		$this->assertTrue( Critical_CSS::is_critical_css_inline_allowed() );
	}

	/**
	 * Deferred JS suspends effectiveness even when both gates allow inlining.
	 *
	 * @return void
	 */
	public function test_defer_js_suspends_effective(): void {
		$this->stub_file_optimisation( array( 'deferJS' => true ) );

		$this->assertTrue( Critical_CSS::is_critical_css_inline_allowed() );
		$this->assertTrue( Critical_CSS::is_deferral_suspended_by_js() );
		$this->assertFalse( Critical_CSS::is_ccss_effective() );
	}

	/**
	 * Delayed JS suspends effectiveness even when both gates allow inlining.
	 *
	 * @return void
	 */
	public function test_delay_js_suspends_effective(): void {
		$this->stub_file_optimisation( array( 'delayJS' => true ) );

		$this->assertFalse( Critical_CSS::is_ccss_effective() );
	}

	/**
	 * The state snapshot splits the gates and surfaces suspension.
	 *
	 * CDN opt-out (legacy false) with the new gate on: the combined gate
	 * reads false while Critical CSS stays effective and unsuspended.
	 *
	 * @return void
	 */
	public function test_effective_state_splits_gates(): void {
		$this->filters_present                              = array( 'wppo_inline_critical_css' );
		$this->filter_overrides['wppo_inline_critical_css'] = true;
		$this->filter_overrides['wppo_inline_combined_css'] = false;

		$state = Critical_CSS::get_ccss_effective_state();

		$this->assertFalse( $state['inline_allowed'] );
		$this->assertTrue( $state['ccss_inline_allowed'] );
		$this->assertFalse( $state['suspended'] );
		$this->assertTrue( $state['effective'] );
	}

	/**
	 * The state snapshot reports suspension when deferJS is active.
	 *
	 * @return void
	 */
	public function test_effective_state_reports_suspension(): void {
		$this->stub_file_optimisation( array( 'deferJS' => true ) );

		$state = Critical_CSS::get_ccss_effective_state();

		$this->assertTrue( $state['inline_allowed'] );
		$this->assertTrue( $state['ccss_inline_allowed'] );
		$this->assertTrue( $state['suspended'] );
		$this->assertFalse( $state['effective'] );
	}
}
