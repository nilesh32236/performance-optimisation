<?php
/**
 * Tests for the coordinated used-CSS / critical-CSS inline budget (issue #1462).
 *
 * Covers the shared Util budget-split helpers, the effective CCSS budget
 * (min of ccssMaxSize and core styles_inline_size_limit minus committed
 * used-CSS bytes), the coordinate_inline_budgets() split, the 18000s
 * full-regen cooldown on Critical_CSS::regenerate_all(), and the cap-20
 * targeted regen used by builder/theme updates.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Critical_CSS;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Budget-coordination tests for issue #1462.
 *
 * @package PerformanceOptimise\Tests
 */
class CssInlineBudget1462Test extends \PHPUnit\Framework\TestCase {

	// The class defines its own setUp() below (shadowing the trait's), so
	// the trait bootstrap is aliased to run it explicitly. Without a Brain
	// Monkey session, Functions\when() cannot Patchwork-redefine scheduler
	// functions eval-declared by earlier test files in the same process
	// (e.g. ActionSchedulerUniquePurge1310Test) and those foreign stubs
	// stay live for these tests.
	use WPPO_Test_Bootstrap {
		WPPO_Test_Bootstrap::setUp as private bootstrapSetUp;
	}

	/**
	 * In-memory option map backing the get_option stub.
	 *
	 * @var array
	 */
	private array $option_map = array();

	/**
	 * Stub the WP functions used by the budget helpers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->bootstrapSetUp();
		$this->option_map = array();
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				return $value;
			}
		);
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'http://example.com' . $path;
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->option_map ) ? $this->option_map[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfour' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		$GLOBALS['wp_version'] = '6.9';
		Util::reset_runtime_caches();
		Util::reset_action_scheduler_unique_cache();
		if ( class_exists( Critical_CSS::class ) ) {
			Critical_CSS::reset_ccss_memo();
		}
	}

	/**
	 * Restore globals between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Util::clear_settings_cache();
		Util::reset_runtime_caches();
		Util::reset_action_scheduler_unique_cache();
		if ( class_exists( Critical_CSS::class ) ) {
			Critical_CSS::reset_ccss_memo();
		}
		unset( $GLOBALS['wp_version'] );
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * CSS below the budget inlines whole with no deferred remainder.
	 *
	 * @return void
	 */
	public function test_split_below_budget_inlines_whole(): void {
		$css   = 'body{color:red}h1{font-size:2em}';
		$split = Util::split_css_for_inline_budget( $css, 40000 );

		$this->assertSame( $css, $split['inline'] );
		$this->assertSame( '', $split['deferred'] );
	}

	/**
	 * CSS above the 40KB budget splits at a rule boundary without loss.
	 *
	 * @return void
	 */
	public function test_split_above_40k_budget_splits_at_rule_boundary(): void {
		$rule = 'body.a{color:red;background:#fff}';
		$css  = str_repeat( $rule, 2000 );
		$this->assertGreaterThan( 40000, strlen( $css ) );

		$split = Util::split_css_for_inline_budget( $css, 40000 );

		$this->assertLessThanOrEqual( 40000, strlen( $split['inline'] ) );
		$this->assertStringEndsWith( '}', $split['inline'] );
		$this->assertNotSame( '', $split['deferred'] );
		$this->assertSame( $css, $split['inline'] . $split['deferred'] );
	}

	/**
	 * No complete rule fitting the budget defers everything (fail-open).
	 *
	 * @return void
	 */
	public function test_split_with_no_fitting_rule_defers_everything(): void {
		$split = Util::split_css_for_inline_budget( 'body{color:red}', 5 );

		$this->assertSame( '', $split['inline'] );
		$this->assertSame( 'body{color:red}', $split['deferred'] );
	}

	/**
	 * Remaining budget subtracts committed bytes and clamps at zero.
	 *
	 * @return void
	 */
	public function test_remaining_budget_subtracts_committed_and_clamps(): void {
		$this->assertSame( 40000, Util::get_remaining_inline_budget( 0, 40000 ) );
		$this->assertSame( 15000, Util::get_remaining_inline_budget( 25000, 40000 ) );
		$this->assertSame( 0, Util::get_remaining_inline_budget( 50000, 40000 ) );
		// A non-positive limit is invalid: falls back to the full core limit.
		$this->assertSame( 40000, Util::get_remaining_inline_budget( -10, 0 ) );
	}

	/**
	 * Effective CCSS budget is the tighter of cap and core limit, minus commits.
	 *
	 * @return void
	 */
	public function test_effective_ccss_budget_is_min_of_cap_and_limit(): void {
		Util::clear_settings_cache();
		// Default cap 20480 is tighter than the 6.9 40KB core limit.
		$this->assertSame( 20480, Critical_CSS::get_effective_ccss_budget( 0 ) );
		$this->assertSame( 10240, Critical_CSS::get_effective_ccss_budget( 10240 ) );
		$this->assertSame( 0, Critical_CSS::get_effective_ccss_budget( 99999 ) );

		// A raised cap still cannot exceed the core inline limit.
		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'ccssMaxSize' => 80000 ),
		);
		Util::clear_settings_cache();
		$this->assertSame( 40000, Critical_CSS::get_effective_ccss_budget( 0 ) );
	}

	/**
	 * Coordination keeps combined inline output within the core budget.
	 *
	 * @return void
	 */
	public function test_coordinate_inline_budgets_split_stays_within_limit(): void {
		Util::clear_settings_cache();
		$used = str_repeat( 'a{color:red}', 400 ); // 4800 bytes committed.
		$ccss = str_repeat( 'b{color:blue}', 2000 ); // Over the remainder.
		$this->assertGreaterThan( 20480, strlen( $ccss ) );

		$split = Critical_CSS::coordinate_inline_budgets( $ccss, $used );

		$this->assertLessThanOrEqual( 20480 - strlen( $used ), strlen( $split['inline'] ) );
		$this->assertNotSame( '', $split['deferred'] );
		$this->assertSame( $ccss, $split['inline'] . $split['deferred'] );

		// Fitting CCSS passes through whole.
		$small = 'b{color:blue}';
		$fit   = Critical_CSS::coordinate_inline_budgets( $small, $used );
		$this->assertSame( $small, $fit['inline'] );
		$this->assertSame( '', $fit['deferred'] );
	}

	/**
	 * A repeat full regen inside the 18000s window queues nothing.
	 *
	 * @return void
	 */
	public function test_regenerate_all_respects_18000s_cooldown(): void {
		$this->option_map[ Critical_CSS::LAST_FULL_REGEN_OPTION ] = time();
		$this->option_map['wppo_settings']                        = array( 'file_optimisation' => array() );
		Util::clear_settings_cache();
		$enqueued = 0;
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function () use ( &$enqueued ) {
				++$enqueued;
				return $enqueued;
			}
		);

		$this->assertSame( 0, Critical_CSS::regenerate_all() );
		$this->assertSame( 0, $enqueued );
		$this->assertTrue( Critical_CSS::is_full_regen_cooled_down() );
	}

	/**
	 * The cooldown reports the shared 18000s default.
	 *
	 * @return void
	 */
	public function test_full_regen_cooldown_defaults_to_18000s(): void {
		$this->assertSame( 18000, Critical_CSS::get_full_regen_cooldown() );
	}

	/**
	 * Targeted regen caps the queue at the requested bound (default 20).
	 *
	 * Seeds renderable sample URLs for all five base templates so the
	 * capped run provably queues work (a 0-job run would also satisfy a
	 * bare upper-bound assertion and hide a broken cap).
	 *
	 * @return void
	 */
	public function test_request_targeted_regen_caps_queue(): void {
		$this->option_map['wppo_settings'] = array( 'file_optimisation' => array() );
		Util::clear_settings_cache();
		Functions\when( 'get_page_templates' )->justReturn( array() );
		Functions\when( 'get_posts' )->justReturn( array( 7, 8, 9 ) );
		Functions\when( 'get_permalink' )->alias(
			static function ( $post_id ) {
				return 'http://example.com/?p=' . (int) $post_id;
			}
		);
		Functions\when( 'setup_postdata' )->justReturn( true );
		Functions\when( 'get_the_time' )->alias(
			static function ( $format ) {
				return 'Y' === $format ? '2026' : '01';
			}
		);
		Functions\when( 'get_month_link' )->alias(
			static function () {
				return 'http://example.com/2026/01/';
			}
		);
		Functions\when( 'wp_reset_postdata' )->justReturn( true );
		$enqueued = 0;
		$next_id  = static function () use ( &$enqueued ) {
			++$enqueued;
			return $enqueued;
		};
		Functions\when( 'as_enqueue_async_action' )->alias( $next_id );
		// The unique-scheduler path prefers as_schedule_single_action();
		// stub it on the same counter so both enqueue routes queue work.
		Functions\when( 'as_schedule_single_action' )->alias( $next_id );

		// Five renderable templates (index/home/single/page/archive) with
		// a cap of 2 queue exactly 2 — proving the cap binds real work.
		$queued = Critical_CSS::request_targeted_regen( 'builder-update', 2 );

		$this->assertGreaterThan( 0, $queued );
		$this->assertSame( 2, $queued );

		// Default cap path queues all five templates: non-empty and bounded at 20.
		Critical_CSS::reset_ccss_memo();
		$queued_default = Critical_CSS::request_targeted_regen( 'theme-update' );
		$this->assertGreaterThan( 0, $queued_default );
		$this->assertSame( 5, $queued_default );
		$this->assertLessThanOrEqual( 20, $queued_default );
	}

	/**
	 * A repeat targeted regen inside the burst-throttle window queues nothing.
	 *
	 * @return void
	 */
	public function test_request_targeted_regen_burst_throttle(): void {
		$this->option_map['wppo_settings'] = array( 'file_optimisation' => array() );
		Util::clear_settings_cache();
		Functions\when( 'get_page_templates' )->justReturn( array() );
		Functions\when( 'get_posts' )->justReturn( array( 7 ) );
		Functions\when( 'get_permalink' )->alias(
			static function ( $post_id ) {
				return 'http://example.com/?p=' . (int) $post_id;
			}
		);
		Functions\when( 'setup_postdata' )->justReturn( true );
		Functions\when( 'get_the_time' )->justReturn( '2026' );
		Functions\when( 'get_month_link' )->alias(
			static function () {
				return 'http://example.com/2026/01/';
			}
		);
		Functions\when( 'wp_reset_postdata' )->justReturn( true );
		Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );

		// Simulate a previous pass inside the 3600s window: the burst
		// collapses instead of stacking another pass.
		$this->option_map[ Critical_CSS::TARGETED_REGEN_OPTION ] = time();
		Util::clear_settings_cache();

		$this->assertSame( 0, Critical_CSS::request_targeted_regen( 'builder-update', 2 ) );
		$this->assertTrue( Critical_CSS::is_targeted_regen_cooled_down() );
	}

	/**
	 * Committed-bytes ledger feeds the effective budget (issue #1462 wiring).
	 *
	 * @return void
	 */
	public function test_committed_inline_bytes_ledger_reduces_budget(): void {
		Util::clear_settings_cache();
		Util::reset_committed_inline_bytes();

		$this->assertSame( 0, Critical_CSS::estimate_committed_inline_bytes() );

		Util::add_committed_inline_bytes( 4800 );
		$this->assertSame( 4800, Critical_CSS::estimate_committed_inline_bytes() );
		// Default cap 20480 is tighter than the 6.9 40KB core limit.
		$this->assertSame( 20480 - 4800, Critical_CSS::get_effective_ccss_budget( Critical_CSS::estimate_committed_inline_bytes() ) );

		Util::reset_committed_inline_bytes();
		$this->assertSame( 0, Critical_CSS::estimate_committed_inline_bytes() );
	}
}
