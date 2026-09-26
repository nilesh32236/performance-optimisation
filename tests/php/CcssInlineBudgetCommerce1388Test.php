<?php
/**
 * Tests for the RUM-weighted critical-CSS queue with inline budget guard (issue #1388).
 *
 * Pins the review findings: cart-session cookies alone never mark a request as
 * commerce, the over-budget warning is throttled (one DB write per template per
 * 12h, never per pageview), the warning interpolates the configured budget
 * label, the commerce verdict is memoized per request, and templates sharing a
 * path bucket are ordered slowest-segment-first.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Critical_CSS;
use PerformanceOptimise\Inc\RUM;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Test fixtures are co-located by convention.
// phpcs:disable WordPress.Files.FileName -- Fixture class names cannot derive the *Test.php file name.

/**
 * Minimal $wpdb stand-in recording inserts for Log::add().
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Ccss1388_Wpdb_Recorder {

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Recorded insert payloads.
	 *
	 * @var array<int, array>
	 */
	public $inserts = array();

	/**
	 * Record an insert.
	 *
	 * @param string $table  Table name.
	 * @param array  $data   Row data.
	 * @param array  $format Formats.
	 * @return int Always 1 (truthy, like a successful insert).
	 */
	public function insert( $table, $data, $format = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->inserts[] = array(
			'table' => $table,
			'data'  => $data,
		);
		return 1;
	}
}

/**
 * Class CcssInlineBudgetCommerce1388Test.
 *
 * @package PerformanceOptimise\Tests
 */
class CcssInlineBudgetCommerce1388Test extends \PHPUnit\Framework\TestCase {

	use WPPO_Test_Bootstrap;

	/**
	 * In-memory option map backing the get_option stub.
	 *
	 * @var array
	 */
	private array $option_map = array();

	/**
	 * In-memory transient map backing the transient stubs.
	 *
	 * @var array
	 */
	private array $transient_map = array();

	/**
	 * Permalink map for the get_permalink stub.
	 *
	 * @var array
	 */
	private array $permalink_map = array();

	/**
	 * Previous $wpdb instance (restored in tearDown).
	 *
	 * @var mixed
	 */
	private $wpdb_backup = null;

	/**
	 * Whether a $wpdb instance existed before the test.
	 *
	 * @var bool
	 */
	private bool $wpdb_had_instance = false;

	/**
	 * Backup of $_COOKIE values touched by commerce tests.
	 *
	 * @var array
	 */
	private array $cookie_backup = array();

	/**
	 * Stub the WP functions used by the budget/commerce/ordering paths.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		Util::reset_runtime_caches();
		RUM::clear_field_lcp_cache();
		Critical_CSS::reset_ccss_memo();

		$this->option_map    = array();
		$this->transient_map = array();
		$this->permalink_map = array();

		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->option_map ) ? $this->option_map[ $name ] : $fallback;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return array_key_exists( $key, $this->transient_map ) ? $this->transient_map[ $key ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transient_map[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transient_map[ $key ] );
				return true;
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'is_home' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_page' )->justReturn( false );
		Functions\when( 'is_archive' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'get_the_ID' )->justReturn( 0 );
		Functions\when( 'get_stylesheet' )->justReturn( 'test-theme' );
		Functions\when( 'get_posts' )->alias(
			static function () {
				return array( 55 );
			}
		);
		Functions\when( 'get_permalink' )->alias(
			function ( $post_id = null ) {
				if ( null !== $post_id && array_key_exists( (int) $post_id, $this->permalink_map ) ) {
					return $this->permalink_map[ (int) $post_id ];
				}
				return 'http://example.com/slow-page/';
			}
		);
		Functions\when( 'get_month_link' )->justReturn( 'http://example.com/archive/' );
		Functions\when( 'get_the_time' )->justReturn( '2026' );
		Functions\when( 'setup_postdata' )->justReturn( true );
		Functions\when( 'wp_reset_postdata' )->justReturn( true );

		Util::clear_settings_cache();

		$this->cookie_backup = $_COOKIE;
	}

	/**
	 * Restore $wpdb, cookies, and Brain Monkey state.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_COOKIE = $this->cookie_backup;
		if ( $this->wpdb_had_instance ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		}
		$this->wpdb_backup       = null;
		$this->wpdb_had_instance = false;
		RUM::clear_field_lcp_cache();
		Critical_CSS::reset_ccss_memo();
		Util::clear_settings_cache();
		\Brain\Monkey\tearDown();
		if ( class_exists( 'PerformanceOptimise\Inc\Main' ) ) {
			\PerformanceOptimise\Inc\Main::reset_instance();
		}
		parent::tearDown();
	}

	/**
	 * Swap global $wpdb for an insert recorder so Log::add() can run.
	 *
	 * @return WPPO_Ccss1388_Wpdb_Recorder
	 */
	private function swap_wpdb_recorder(): WPPO_Ccss1388_Wpdb_Recorder {
		$this->wpdb_had_instance = isset( $GLOBALS['wpdb'] );
		if ( $this->wpdb_had_instance ) {
			$this->wpdb_backup = $GLOBALS['wpdb'];
		}
		$recorder        = new WPPO_Ccss1388_Wpdb_Recorder();
		$GLOBALS['wpdb'] = $recorder;
		return $recorder;
	}

	/**
	 * Seed a RUM aggregate where only the template segment distinguishes the templates.
	 *
	 * Home's sample URL ("/") is fast while the single template's sample URL
	 * is unmeasured (URL score 0); only the template-segment blend can surface
	 * the slow single template first. A URL-only score would keep FIFO order.
	 *
	 * @param float $fast_p75 Fast template p75.
	 * @param float $slow_p75 Slow template p75.
	 * @return void
	 */
	private function seed_segment_blend_fixture( float $fast_p75 = 1200.0, float $slow_p75 = 4500.0 ): void {
		$today                           = gmdate( 'Y-m-d' );
		$this->option_map[ RUM::OPTION ] = array(
			$today => array(
				'/'      => array(
					'lcpSeg' => array(
						'mobile|home' => array(
							'device'   => 'mobile',
							'template' => 'home',
							'n'        => 20,
							'samples'  => array_fill( 0, 20, $fast_p75 ),
						),
					),
				),
				'/other' => array(
					'lcpSeg' => array(
						'mobile|single' => array(
							'device'   => 'mobile',
							'template' => 'single',
							'n'        => 20,
							'samples'  => array_fill( 0, 20, $slow_p75 ),
						),
					),
				),
			),
		);
		RUM::clear_field_lcp_cache();
	}

	/**
	 * Build high-entropy CSS that stays under the raw cap but over a 1 KB gzipped budget.
	 *
	 * Hex-heavy rules with randomized property names defeat gzip repetition
	 * matching, so the gzipped size stays above a 1 KB budget while the raw
	 * size stays far under the 20 KB inline cap.
	 *
	 * @return string CSS content.
	 */
	private function build_over_budget_css(): string {
		$css = '';
		for ( $i = 0; $i < 60; $i++ ) {
			$h    = md5( 'r-' . $i );
			$css .= '.' . substr( $h, 0, 8 ) . '{' . substr( $h, 8, 4 ) . ':' . substr( $h, 12, 6 ) . ';' . substr( $h, 18, 4 ) . ':' . substr( $h, 22, 6 ) . ';' . substr( $h, 28, 4 ) . ':#' . substr( md5( 'v-' . $i ), 0, 6 ) . '}' . "\n";
		}
		return $css;
	}

	/**
	 * Write a CCSS fixture file for the current (home) template hash.
	 *
	 * @param string $content File content.
	 * @return string Template hash.
	 */
	private function write_home_ccss_fixture( string $content ): string {
		$hash = Critical_CSS::get_template_hash( 'home' );
		$dir  = wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
			mkdir( $dir, 0775, true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $dir . '/' . $hash . '.css', $content );
		Critical_CSS::reset_ccss_memo();

		return $hash;
	}

	/**
	 * The inline budget defaults to 14 KB with a matching label.
	 *
	 * @return void
	 */
	public function test_budget_defaults_to_14kb(): void {
		Util::clear_settings_cache();

		$this->assertSame( 14 * 1024, Critical_CSS::get_ccss_inline_budget_bytes() );
		$this->assertSame( '14 KB', Critical_CSS::get_ccss_inline_budget_label() );
	}

	/**
	 * The budget follows the setting, clamps the filter output, and the label follows.
	 *
	 * Out-of-range setting values fail open to the 14 KB default (never
	 * unbounded); the human-readable label always reflects the effective
	 * budget so warnings never hardcode "14 KB".
	 *
	 * @return void
	 */
	public function test_budget_clamps_and_label_follows_setting(): void {
		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'ccssInlineBudgetKb' => 20 ),
		);
		Util::clear_settings_cache();

		$this->assertSame( 20 * 1024, Critical_CSS::get_ccss_inline_budget_bytes() );
		$this->assertSame( '20 KB', Critical_CSS::get_ccss_inline_budget_label() );

		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'ccssInlineBudgetKb' => 1 ),
		);
		Util::clear_settings_cache();

		$this->assertSame( 1 * 1024, Critical_CSS::get_ccss_inline_budget_bytes() );
		$this->assertSame( '1 KB', Critical_CSS::get_ccss_inline_budget_label() );

		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'ccssInlineBudgetKb' => 500 ),
		);
		Util::clear_settings_cache();

		$this->assertSame( 14 * 1024, Critical_CSS::get_ccss_inline_budget_bytes() );
		$this->assertSame( '14 KB', Critical_CSS::get_ccss_inline_budget_label() );

		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'ccssInlineBudgetKb' => 0 ),
		);
		Util::clear_settings_cache();

		$this->assertSame( 14 * 1024, Critical_CSS::get_ccss_inline_budget_bytes() );
		$this->assertSame( '14 KB', Critical_CSS::get_ccss_inline_budget_label() );
	}

	/**
	 * The gzipped transfer size is stable across calls (per-request memo).
	 *
	 * @return void
	 */
	public function test_gzipped_size_is_stable_across_calls(): void {
		$css = str_repeat( '.a{color:red}', 100 );

		$this->assertSame( Critical_CSS::gzipped_size( $css ), Critical_CSS::gzipped_size( $css ) );
		$this->assertSame( 0, Critical_CSS::gzipped_size( '' ) );

		Critical_CSS::reset_ccss_memo();

		$this->assertSame( Critical_CSS::gzipped_size( $css ), Critical_CSS::gzipped_size( $css ) );
	}

	/**
	 * A cart-session cookie alone never marks the request as commerce.
	 *
	 * Cookies are present on every page for shoppers with items in the cart;
	 * only cart/checkout pages are commerce contexts.
	 *
	 * @return void
	 */
	public function test_cart_cookie_alone_is_not_commerce(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test fixture.
		$_COOKIE['woocommerce_items_in_cart'] = '1';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test fixture.
		$_COOKIE['woocommerce_cart_hash'] = 'abc123';
		Critical_CSS::reset_ccss_memo();

		$this->assertFalse( Critical_CSS::is_commerce_context() );

		// A real cart page still counts once the memo is reset.
		Functions\when( 'is_cart' )->justReturn( true );
		Critical_CSS::reset_ccss_memo();

		$this->assertTrue( Critical_CSS::is_commerce_context() );
	}

	/**
	 * The WooCommerce cart/checkout page-ID fallback still marks commerce.
	 *
	 * @return void
	 */
	public function test_commerce_page_id_fallback(): void {
		$this->option_map['woocommerce_cart_page_id']     = 42;
		$this->option_map['woocommerce_checkout_page_id'] = 43;
		Functions\when( 'get_the_ID' )->justReturn( 42 );
		Critical_CSS::reset_ccss_memo();

		$this->assertTrue( Critical_CSS::is_commerce_context() );

		Functions\when( 'get_the_ID' )->justReturn( 7 );
		Critical_CSS::reset_ccss_memo();

		$this->assertFalse( Critical_CSS::is_commerce_context() );
	}

	/**
	 * Commerce pages skip both inline output and stylesheet deferral.
	 *
	 * @return void
	 */
	public function test_commerce_page_skips_inline_and_deferral(): void {
		Functions\when( 'is_checkout' )->justReturn( true );
		Critical_CSS::reset_ccss_memo();

		ob_start();
		Critical_CSS::inline_ccss();
		$output = ob_get_clean();

		$this->assertSame( '', $output );

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Fixture tag for the deferral filter.
		$tag = '<link rel="stylesheet" id="theme-style-css" href="http://example.com/theme.css" media="all" />';
		$this->assertSame( $tag, Critical_CSS::defer_stylesheets( $tag, 'theme-style', 'http://example.com/theme.css' ) );
	}

	/**
	 * The slow template dequeues first via the template-segment blend.
	 *
	 * Home's sample URL ("/") is fast while the single template's sample URL
	 * is unmeasured (URL score 0); the template-segment weight must still
	 * dequeue the slowest template first. A URL-only score would keep FIFO.
	 *
	 * @return void
	 */
	public function test_segment_blend_orders_slowest_first(): void {
		$this->seed_segment_blend_fixture();
		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'ccssRumPriority' => true ),
		);
		Util::clear_settings_cache();
		Critical_CSS::reset_ccss_memo();

		$ordered = Critical_CSS::order_templates_by_rum_priority(
			array(
				'home'   => 'Home',
				'single' => 'Single Post',
			)
		);

		$this->assertSame( array( 'single', 'home' ), array_keys( $ordered ) );
	}

	/**
	 * Over-budget output emits no inline CSS and logs once per 12h, not per view.
	 *
	 * The 1 KB budget keeps the fixture reachable (high-entropy CSS cannot
	 * exceed the 14 KB default gzipped while staying under the 20 KB raw cap)
	 * and proves the warning interpolates the configured budget label.
	 *
	 * @return void
	 */
	public function test_over_budget_falls_back_without_inline_and_throttles_log(): void {
		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'ccssInlineBudgetKb' => 1 ),
		);
		Util::clear_settings_cache();
		Critical_CSS::reset_ccss_memo();

		$css = $this->build_over_budget_css();

		$this->assertLessThanOrEqual( Critical_CSS::get_ccss_max_size(), strlen( $css ), 'Fixture must fit the raw cap to reach the budget guard.' );
		$this->assertTrue( Critical_CSS::is_over_inline_budget( $css ), 'Fixture must exceed the gzipped budget.' );

		$recorder = $this->swap_wpdb_recorder();
		$hash     = $this->write_home_ccss_fixture( $css );

		try {
			ob_start();
			Critical_CSS::inline_ccss();
			$first_output = ob_get_clean();

			$this->assertStringNotContainsString( '<style', (string) $first_output );
			$this->assertCount( 1, $recorder->inserts, 'First over-budget view logs once.' );
			$this->assertStringContainsString( '1 KB', (string) $recorder->inserts[0]['data']['activity'] );

			// A second view in a later request (memo reset, transient kept)
			// emits no inline CSS and writes no second DB row.
			Critical_CSS::reset_ccss_memo();
			ob_start();
			Critical_CSS::inline_ccss();
			$second_output = ob_get_clean();

			$this->assertStringNotContainsString( '<style', (string) $second_output );
			$this->assertCount( 1, $recorder->inserts, 'Throttled warning must not log per pageview.' );

			// The deferral guard is set: full stylesheets load normally.
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Fixture tag for the deferral filter.
			$tag = '<link rel="stylesheet" id="theme-style-css" href="http://example.com/theme.css" media="all" />';
			$this->assertSame( $tag, Critical_CSS::defer_stylesheets( $tag, 'theme-style', 'http://example.com/theme.css' ) );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			unlink( wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' ) . '/' . $hash . '.css' );
			Critical_CSS::reset_ccss_memo();
		}
	}
}
