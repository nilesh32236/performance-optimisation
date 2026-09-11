<?php
/**
 * Tests for RUM/trend-prioritized critical-CSS / used-CSS queues (issue #1059).
 *
 * Worst p75 LCP URLs are ordered first; empty data falls back to FIFO.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Critical_CSS;
use PerformanceOptimise\Inc\RUM;
use PerformanceOptimise\Inc\Used_CSS;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Class CssRumPriorityTest.
 *
 * @package PerformanceOptimise\Tests
 */
class CssRumPriorityTest extends \PHPUnit\Framework\TestCase {

	use WPPO_Test_Bootstrap;

	/**
	 * In-memory option map.
	 *
	 * @var array
	 */
	private array $option_map = array();

	/**
	 * Permalink map for get_permalink stub.
	 *
	 * @var array
	 */
	private array $permalink_map = array();

	/**
	 * Stub WP functions used by the ordering paths.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		Util::reset_cached_home_urls();
		Util::clear_settings_cache();
		RUM::clear_field_lcp_cache();
		Critical_CSS::reset_ccss_memo();

		$this->option_map    = array();
		$this->permalink_map = array();

		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->option_map ) ? $this->option_map[ $name ] : $fallback;
			}
		);
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_page_templates' )->justReturn( array() );
		Functions\when( 'get_month_link' )->justReturn( 'http://example.com/archive/' );
		Functions\when( 'get_the_time' )->justReturn( '2026' );
		Functions\when( 'setup_postdata' )->justReturn( true );
		Functions\when( 'wp_reset_postdata' )->justReturn( true );
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
		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				return strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) );
			}
		);
	}

	/**
	 * Restore Brain Monkey state.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		RUM::clear_field_lcp_cache();
		Critical_CSS::reset_ccss_memo();
		Util::clear_settings_cache();
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Seed a RUM aggregate fixture: fast path vs slow path.
	 *
	 * @param float $fast_p75 Fast path p75.
	 * @param float $slow_p75 Slow path p75.
	 * @return void
	 */
	private function seed_rum_fixture( float $fast_p75 = 1200.0, float $slow_p75 = 4500.0 ): void {
		$today                           = gmdate( 'Y-m-d' );
		$this->option_map[ RUM::OPTION ] = array(
			$today => array(
				'/'          => array(
					'lcpSeg' => array(
						'mobile|home' => array(
							'device'   => 'mobile',
							'template' => 'home',
							'n'        => 20,
							'samples'  => array_fill( 0, 20, $fast_p75 ),
						),
					),
				),
				'/slow-page' => array(
					'lcpSeg' => array(
						'mobile|single' => array(
							'device'   => 'mobile',
							'template' => 'single',
							'n'        => 20,
							'samples'  => array_fill( 0, 20, $slow_p75 ),
						),
					),
				),
				'/fast-page' => array(
					'lcpSeg' => array(
						'mobile|single' => array(
							'device'   => 'mobile',
							'template' => 'single',
							'n'        => 20,
							'samples'  => array_fill( 0, 20, $fast_p75 ),
						),
					),
				),
			),
		);
		RUM::clear_field_lcp_cache();
	}

	/**
	 * Path priority map is worst-first with max p75 per path.
	 *
	 * @return void
	 */
	public function test_path_lcp_priority_is_worst_first(): void {
		$this->seed_rum_fixture();

		$priority = RUM::get_path_lcp_priority();

		$this->assertArrayHasKey( '/slow-page', $priority );
		$this->assertArrayHasKey( '/', $priority );
		$this->assertSame( 4500.0, $priority['/slow-page'] );
		$this->assertSame( 1200.0, $priority['/'] );
		$keys = array_keys( $priority );
		$this->assertLessThan( array_search( '/', $keys, true ), array_search( '/slow-page', $keys, true ) );
	}

	/**
	 * Score_url_lcp blends the latest trend LCP snapshot for the URL.
	 *
	 * @return void
	 */
	public function test_score_url_blends_trend_lcp(): void {
		$this->seed_rum_fixture();
		$slow_url                                   = 'http://example.com/slow-page/';
		$this->option_map['wppo_web_vitals_trends'] = array(
			md5( $slow_url ) . '_mobile' => array(
				array(
					'fetched_at'  => '2026-09-01 00:00:00',
					'performance' => 40,
					'lcp'         => 6000.0,
					'cls'         => 0.1,
					'tbt'         => 200.0,
				),
			),
		);

		$this->assertSame( 6000.0, RUM::score_url_lcp( $slow_url ) );
		$this->assertSame( 1200.0, RUM::score_url_lcp( 'http://example.com/fast-page/' ) );
		$this->assertSame( 0.0, RUM::score_url_lcp( 'http://example.com/unknown-path-xyz/' ) );
	}

	/**
	 * Used-CSS ordering puts the worst-p75 post first.
	 *
	 * @return void
	 */
	public function test_used_css_orders_worst_first(): void {
		$this->seed_rum_fixture();
		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'usedCssRumPriority' => true ),
		);
		Util::clear_settings_cache();
		$this->permalink_map = array(
			10 => 'http://example.com/fast-page/',
			11 => 'http://example.com/slow-page/',
		);

		$this->assertSame( array( 11, 10 ), Used_CSS::order_post_ids_by_rum_priority( array( 10, 11 ) ) );
	}

	/**
	 * Used-CSS falls back to FIFO when no RUM/trend signal exists.
	 *
	 * @return void
	 */
	public function test_used_css_falls_back_to_fifo_without_signal(): void {
		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'usedCssRumPriority' => true ),
		);
		Util::clear_settings_cache();
		$this->permalink_map = array(
			10 => 'http://example.com/fast-page/',
			11 => 'http://example.com/slow-page/',
		);

		$this->assertSame( array( 10, 11 ), Used_CSS::order_post_ids_by_rum_priority( array( 10, 11 ) ) );
	}

	/**
	 * Used-CSS honours the opt-out setting (FIFO when disabled).
	 *
	 * @return void
	 */
	public function test_used_css_opt_out_keeps_fifo(): void {
		$this->seed_rum_fixture();
		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'usedCssRumPriority' => false ),
		);
		Util::clear_settings_cache();
		$this->permalink_map = array(
			10 => 'http://example.com/fast-page/',
			11 => 'http://example.com/slow-page/',
		);

		$this->assertSame( array( 10, 11 ), Used_CSS::order_post_ids_by_rum_priority( array( 10, 11 ) ) );
	}

	/**
	 * Critical-CSS ordering puts the template with the worst-p75 sample URL first.
	 *
	 * The home_url() '/' fixture is fast; single sample URL is slow.
	 *
	 * @return void
	 */
	public function test_critical_css_orders_worst_first(): void {
		$this->seed_rum_fixture();
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
	 * Critical-CSS falls back to FIFO when no RUM signal exists.
	 *
	 * @return void
	 */
	public function test_critical_css_falls_back_to_fifo_without_signal(): void {
		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'ccssRumPriority' => true ),
		);
		Util::clear_settings_cache();
		Critical_CSS::reset_ccss_memo();

		$templates = array(
			'home'   => 'Home',
			'single' => 'Single Post',
		);

		$this->assertSame( $templates, Critical_CSS::order_templates_by_rum_priority( $templates ) );
	}

	/**
	 * Used-CSS prioritizes by trend LCP alone when no RUM samples exist.
	 *
	 * @return void
	 */
	public function test_used_css_orders_by_trend_only_without_rum(): void {
		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'usedCssRumPriority' => true ),
		);
		Util::clear_settings_cache();
		RUM::clear_field_lcp_cache();
		$slow_url                                   = 'http://example.com/slow-page/';
		$this->permalink_map                        = array(
			10 => 'http://example.com/fast-page/',
			11 => $slow_url,
		);
		$this->option_map['wppo_web_vitals_trends'] = array(
			md5( $slow_url ) . '_mobile' => array(
				array(
					'fetched_at'  => '2026-09-01 00:00:00',
					'performance' => 40,
					'lcp'         => 5000.0,
					'cls'         => 0.1,
					'tbt'         => 200.0,
				),
			),
		);

		$this->assertSame( array( 11, 10 ), Used_CSS::order_post_ids_by_rum_priority( array( 10, 11 ) ) );
	}

	/**
	 * Used-CSS keeps FIFO order on tied scores (deterministic usort tie-break).
	 *
	 * @return void
	 */
	public function test_used_css_tie_keeps_fifo(): void {
		$this->seed_rum_fixture();
		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'usedCssRumPriority' => true ),
		);
		Util::clear_settings_cache();
		$slow_url            = 'http://example.com/slow-page/';
		$this->permalink_map = array(
			10 => $slow_url,
			11 => $slow_url,
		);

		$this->assertSame( array( 10, 11 ), Used_CSS::order_post_ids_by_rum_priority( array( 10, 11 ) ) );
		$this->assertSame( array( 11, 10 ), Used_CSS::order_post_ids_by_rum_priority( array( 11, 10 ) ) );
	}

	/**
	 * Critical-CSS prioritizes by trend LCP alone when no RUM samples exist.
	 *
	 * @return void
	 */
	public function test_critical_css_orders_by_trend_only_without_rum(): void {
		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'ccssRumPriority' => true ),
		);
		Util::clear_settings_cache();
		Critical_CSS::reset_ccss_memo();
		RUM::clear_field_lcp_cache();
		$slow_url                                   = 'http://example.com/slow-page/';
		$this->permalink_map                        = array(
			55 => $slow_url,
		);
		$this->option_map['wppo_web_vitals_trends'] = array(
			md5( $slow_url ) . '_mobile' => array(
				array(
					'fetched_at'  => '2026-09-01 00:00:00',
					'performance' => 40,
					'lcp'         => 5500.0,
					'cls'         => 0.1,
					'tbt'         => 200.0,
				),
			),
		);

		$ordered = Critical_CSS::order_templates_by_rum_priority(
			array(
				'home'   => 'Home',
				'single' => 'Single Post',
			)
		);

		$this->assertSame( array( 'single', 'home' ), array_keys( $ordered ) );
	}

	/**
	 * Critical-CSS keeps FIFO order on tied scores (deterministic usort tie-break).
	 *
	 * @return void
	 */
	public function test_critical_css_tie_keeps_fifo(): void {
		$this->seed_rum_fixture( 3000.0, 3000.0 );
		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'ccssRumPriority' => true ),
		);
		Util::clear_settings_cache();
		Critical_CSS::reset_ccss_memo();

		$fifo = array(
			'home'   => 'Home',
			'single' => 'Single Post',
		);
		$this->assertSame( $fifo, Critical_CSS::order_templates_by_rum_priority( $fifo ) );

		Critical_CSS::reset_ccss_memo();
		$reversed = array(
			'single' => 'Single Post',
			'home'   => 'Home',
		);
		$this->assertSame( $reversed, Critical_CSS::order_templates_by_rum_priority( $reversed ) );
	}

	/**
	 * New priority toggles default to on via Util defaults.
	 *
	 * @return void
	 */
	public function test_priority_settings_default_on(): void {
		$defaults = Util::get_default_settings();

		$this->assertTrue( $defaults['file_optimisation']['ccssRumPriority'] );
		$this->assertTrue( $defaults['file_optimisation']['usedCssRumPriority'] );
	}
}
