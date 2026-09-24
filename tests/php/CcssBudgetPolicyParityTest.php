<?php
/**
 * Parity tests for the P3-018 Ccss_Budget_Policy extraction (issue #1602).
 *
 * Ccss_Budget_Policy owns the inline-budget/cooldown-gate cluster extracted
 * verbatim from Critical_CSS (size cap, truncation, full/targeted regen
 * cooldowns + throttle stamps, committed-bytes ledger, effective budget,
 * budget coordination, gzipped sizing, over-budget guard, budget label);
 * Critical_CSS keeps thin same-signature static proxies. These tests pin
 * owner behavior plus proxy parity so the split cannot drift: settings
 * reads, cooldown/mark round-trips, budget math, truncation, gzip gates,
 * memo reset, constant aliases, and facade signatures.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Ccss_Budget_Policy;
use PerformanceOptimise\Inc\Critical_CSS;
use Brain\Monkey\Functions;

/**
 * Budget-policy parity tests for issue #1602.
 */
class CcssBudgetPolicyParityTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap {
		setUp as private bootstrap_setup;
		tearDown as private bootstrap_teardown;
	}

	/**
	 * In-memory option map backing the get_option/update_option stubs.
	 *
	 * @var array<string, mixed>
	 */
	private array $option_map = array();

	/**
	 * Swap in stubs, then seed defaults.
	 *
	 * @return void
	 */
	protected function setUp(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$this->bootstrap_setup();
		$this->option_map = array();
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				if ( 'wppo_settings' === $name ) {
					return array();
				}
				if ( array_key_exists( (string) $name, $this->option_map ) ) {
					return $this->option_map[ (string) $name ];
				}
				return $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				unset( $autoload );
				$this->option_map[ (string) $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
	}

	/**
	 * Restore state.
	 *
	 * @return void
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		Critical_CSS::reset_ccss_memo();
		$this->bootstrap_teardown();
	}

	/**
	 * Seed the wppo_settings option with a file_optimisation section.
	 *
	 * @param array $file_optimisation File optimisation settings.
	 * @return void
	 */
	private function seed_settings( array $file_optimisation ): void {
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) use ( $file_optimisation ) {
				if ( 'wppo_settings' === $name ) {
					return array( 'file_optimisation' => $file_optimisation );
				}
				if ( array_key_exists( (string) $name, $this->option_map ) ) {
					return $this->option_map[ (string) $name ];
				}
				return $fallback;
			}
		);
		if ( class_exists( 'PerformanceOptimise\Inc\Settings_Store' ) ) {
			\PerformanceOptimise\Inc\Settings_Store::clear_settings_cache();
		}
		if ( class_exists( 'PerformanceOptimise\Inc\Util' ) ) {
			\PerformanceOptimise\Inc\Util::reset_runtime_caches();
		}
	}

	/**
	 * Every moved method stays callable on both owner and facade.
	 *
	 * @return void
	 */
	public function test_all_moved_methods_exist_on_both_classes(): void {
		$methods = array(
			'get_ccss_max_size',
			'truncate_to_cap',
			'get_full_regen_cooldown',
			'get_ccss_inline_budget_bytes',
			'is_full_regen_cooled_down',
			'mark_full_regen',
			'get_targeted_regen_cooldown',
			'is_targeted_regen_cooled_down',
			'mark_targeted_regen',
			'estimate_committed_inline_bytes',
			'get_effective_ccss_budget',
			'coordinate_inline_budgets',
			'gzipped_size',
			'is_over_inline_budget',
			'get_ccss_inline_budget_label',
		);
		foreach ( $methods as $method ) {
			$this->assertTrue( method_exists( 'PerformanceOptimise\Inc\Ccss_Budget_Policy', $method ), 'Owner missing ' . $method );
			$this->assertTrue( method_exists( 'PerformanceOptimise\Inc\Critical_CSS', $method ), 'Facade missing ' . $method );
			$owner  = new \ReflectionMethod( 'PerformanceOptimise\Inc\Ccss_Budget_Policy', $method );
			$facade = new \ReflectionMethod( 'PerformanceOptimise\Inc\Critical_CSS', $method );
			$this->assertTrue( $owner->isPublic(), 'Owner ' . $method . ' must stay public' );
			$this->assertTrue( $facade->isPublic(), 'Facade ' . $method . ' must stay public' );
			$this->assertTrue( $owner->isStatic(), 'Owner ' . $method . ' must stay static' );
			$this->assertTrue( $facade->isStatic(), 'Facade ' . $method . ' must stay static' );
			$this->assertSame(
				count( $owner->getParameters() ),
				count( $facade->getParameters() ),
				'Signature drift on ' . $method
			);
		}
	}

	/**
	 * Public option-key constants stay identical on both classes.
	 *
	 * @return void
	 */
	public function test_option_key_aliases_match(): void {
		$this->assertSame(
			Ccss_Budget_Policy::LAST_FULL_REGEN_OPTION,
			Critical_CSS::LAST_FULL_REGEN_OPTION
		);
		$this->assertSame(
			Ccss_Budget_Policy::TARGETED_REGEN_OPTION,
			Critical_CSS::TARGETED_REGEN_OPTION
		);
		$this->assertSame( 'wppo_ccss_last_full_regen', Ccss_Budget_Policy::LAST_FULL_REGEN_OPTION );
		$this->assertSame( 'wppo_ccss_last_targeted_regen', Ccss_Budget_Policy::TARGETED_REGEN_OPTION );
	}

	/**
	 * Size-cap reads default and honor the setting on both classes.
	 *
	 * @return void
	 */
	public function test_max_size_default_and_setting_parity(): void {
		$this->assertSame( 20480, Ccss_Budget_Policy::get_ccss_max_size() );
		$this->assertSame( Ccss_Budget_Policy::get_ccss_max_size(), Critical_CSS::get_ccss_max_size() );
		$this->seed_settings( array( 'ccssMaxSize' => 5000 ) );
		$this->assertSame( 5000, Ccss_Budget_Policy::get_ccss_max_size() );
		$this->assertSame( 5000, Critical_CSS::get_ccss_max_size() );
	}

	/**
	 * Inline-budget reads, clamps, and labels identically on both classes.
	 *
	 * @return void
	 */
	public function test_inline_budget_bytes_and_label_parity(): void {
		$this->assertSame( 14336, Ccss_Budget_Policy::get_ccss_inline_budget_bytes() );
		$this->assertSame( Ccss_Budget_Policy::get_ccss_inline_budget_bytes(), Critical_CSS::get_ccss_inline_budget_bytes() );
		$this->assertSame( '14 KB', Ccss_Budget_Policy::get_ccss_inline_budget_label() );
		$this->assertSame( '14 KB', Critical_CSS::get_ccss_inline_budget_label() );
		$this->seed_settings( array( 'ccssInlineBudgetKb' => 2 ) );
		$this->assertSame( 2048, Ccss_Budget_Policy::get_ccss_inline_budget_bytes() );
		$this->assertSame( 2048, Critical_CSS::get_ccss_inline_budget_bytes() );
		$this->assertSame( '2 KB', Critical_CSS::get_ccss_inline_budget_label() );
	}

	/**
	 * Full-regen cooldown/mark round-trips through the owner and facade.
	 *
	 * @return void
	 */
	public function test_full_regen_cooldown_round_trip(): void {
		$this->assertSame( 18000, Ccss_Budget_Policy::get_full_regen_cooldown() );
		$this->assertSame( Ccss_Budget_Policy::get_full_regen_cooldown(), Critical_CSS::get_full_regen_cooldown() );
		$this->assertFalse( Ccss_Budget_Policy::is_full_regen_cooled_down() );
		Critical_CSS::mark_full_regen();
		$this->assertTrue( Ccss_Budget_Policy::is_full_regen_cooled_down() );
		$this->assertTrue( Critical_CSS::is_full_regen_cooled_down() );
		$this->option_map[ Ccss_Budget_Policy::LAST_FULL_REGEN_OPTION ] = time() - 20000;
		$this->assertFalse( Ccss_Budget_Policy::is_full_regen_cooled_down() );
		$this->assertFalse( Critical_CSS::is_full_regen_cooled_down() );
	}

	/**
	 * Targeted-regen cooldown/mark round-trips through the owner and facade.
	 *
	 * @return void
	 */
	public function test_targeted_regen_cooldown_round_trip(): void {
		$this->assertSame( 3600, Ccss_Budget_Policy::get_targeted_regen_cooldown() );
		$this->assertSame( Ccss_Budget_Policy::get_targeted_regen_cooldown(), Critical_CSS::get_targeted_regen_cooldown() );
		$this->assertFalse( Ccss_Budget_Policy::is_targeted_regen_cooled_down() );
		Ccss_Budget_Policy::mark_targeted_regen();
		$this->assertTrue( Ccss_Budget_Policy::is_targeted_regen_cooled_down() );
		$this->assertTrue( Critical_CSS::is_targeted_regen_cooled_down() );
		$this->option_map[ Ccss_Budget_Policy::TARGETED_REGEN_OPTION ] = time() - 4000;
		$this->assertFalse( Critical_CSS::is_targeted_regen_cooled_down() );
	}

	/**
	 * Truncation honors the cap without breaking rules, identically.
	 *
	 * @return void
	 */
	public function test_truncate_to_cap_parity(): void {
		$css   = '.a{color:red}.b{color:blue}.c{color:green}';
		$short = '.a{color:red}';
		$this->assertSame( $short, Ccss_Budget_Policy::truncate_to_cap( $short, 100 ) );
		$this->assertSame( '', Ccss_Budget_Policy::truncate_to_cap( $css, 0 ) );
		$this->assertSame( '', Ccss_Budget_Policy::truncate_to_cap( $css, -5 ) );
		$cut = Ccss_Budget_Policy::truncate_to_cap( $css, 30 );
		$this->assertStringEndsWith( '}', $cut );
		$this->assertLessThanOrEqual( 30, strlen( $cut ) );
		$this->assertSame( $cut, Critical_CSS::truncate_to_cap( $css, 30 ) );
		$this->assertSame( $short, Critical_CSS::truncate_to_cap( $short, 100 ) );
	}

	/**
	 * Effective budget is the tighter cap minus commits, on both classes.
	 *
	 * @return void
	 */
	public function test_effective_budget_math_parity(): void {
		$this->seed_settings( array( 'ccssMaxSize' => 1000 ) );
		$full = Ccss_Budget_Policy::get_effective_ccss_budget( 0 );
		$this->assertSame( 1000, $full );
		$this->assertSame( $full, Critical_CSS::get_effective_ccss_budget( 0 ) );
		$this->assertSame( 600, Ccss_Budget_Policy::get_effective_ccss_budget( 400 ) );
		$this->assertSame( 600, Critical_CSS::get_effective_ccss_budget( 400 ) );
		$this->assertSame( 0, Ccss_Budget_Policy::get_effective_ccss_budget( 1000 ) );
		$this->assertSame( 0, Ccss_Budget_Policy::get_effective_ccss_budget( 5000 ) );
		$this->assertSame( 0, Critical_CSS::get_effective_ccss_budget( 5000 ) );
	}

	/**
	 * Budget coordination splits over-cap CSS identically.
	 *
	 * @return void
	 */
	public function test_coordinate_inline_budgets_parity(): void {
		$this->assertSame(
			array(
				'inline'   => '',
				'deferred' => '',
			),
			Ccss_Budget_Policy::coordinate_inline_budgets( '' )
		);
		$this->seed_settings( array( 'ccssMaxSize' => 100 ) );
		$short = '.a{color:red}';
		$fit   = Ccss_Budget_Policy::coordinate_inline_budgets( $short );
		$this->assertSame( $short, $fit['inline'] );
		$this->assertSame( '', $fit['deferred'] );
		$long  = str_repeat( '.rule{color:blue;margin:0}', 20 );
		$split = Ccss_Budget_Policy::coordinate_inline_budgets( $long );
		$this->assertNotSame( '', $split['deferred'] );
		$this->assertSame( $split, Critical_CSS::coordinate_inline_budgets( $long ) );
		$this->assertSame(
			Ccss_Budget_Policy::coordinate_inline_budgets( $short ),
			Critical_CSS::coordinate_inline_budgets( $short )
		);
	}

	/**
	 * Gzip sizing and the over-budget guard agree on both classes.
	 *
	 * @return void
	 */
	public function test_gzip_and_over_budget_parity(): void {
		$this->assertSame( 0, Ccss_Budget_Policy::gzipped_size( '' ) );
		$this->assertFalse( Ccss_Budget_Policy::is_over_inline_budget( '' ) );
		$this->seed_settings( array( 'ccssInlineBudgetKb' => 1 ) );
		$small = '.a{color:red}';
		$this->assertFalse( Ccss_Budget_Policy::is_over_inline_budget( $small ) );
		$this->assertFalse( Critical_CSS::is_over_inline_budget( $small ) );
		$big = '';
		for ( $i = 0; $i < 200; $i++ ) {
			$big .= '.cls' . $i . '-' . md5( (string) $i ) . '{color:#' . substr( md5( 'c' . $i ), 0, 6 ) . ';margin:' . $i . 'px}';
		}
		$this->assertGreaterThan( 0, Ccss_Budget_Policy::gzipped_size( $big ) );
		$this->assertSame( Ccss_Budget_Policy::gzipped_size( $big ), Critical_CSS::gzipped_size( $big ) );
		$this->assertTrue( Ccss_Budget_Policy::is_over_inline_budget( $big ) );
		$this->assertTrue( Critical_CSS::is_over_inline_budget( $big ) );
	}

	/**
	 * Committed-bytes ledger defaults to zero on both classes.
	 *
	 * @return void
	 */
	public function test_estimate_committed_inline_bytes_parity(): void {
		$this->assertSame( 0, Ccss_Budget_Policy::estimate_committed_inline_bytes() );
		$this->assertSame( 0, Critical_CSS::estimate_committed_inline_bytes() );
	}

	/**
	 * Memo reset clears the budget-policy gzip memo via the facade.
	 *
	 * @return void
	 */
	public function test_reset_memo_clears_policy_memo(): void {
		Ccss_Budget_Policy::gzipped_size( '.a{color:red}' );
		$prop = new \ReflectionProperty( 'PerformanceOptimise\Inc\Ccss_Budget_Policy', 'gzip_size_memo' );
		$this->assertNotSame( array(), $prop->getValue() );
		Critical_CSS::reset_ccss_memo();
		$this->assertSame( array(), $prop->getValue() );
	}
}
