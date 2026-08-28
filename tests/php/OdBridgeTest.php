<?php
/**
 * Tests for OD_Bridge class.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\OD_Bridge;
use PerformanceOptimise\Inc\Image_Optimisation;
use Brain\Monkey\Functions;

/**
 * Tests for Optimization Detective bridge.
 *
 * @package PerformanceOptimise\Tests
 */
class OdBridgeTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options for Util::get_settings().
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * OD metrics stub storage.
	 *
	 * @var array
	 */
	private $od_metrics = array();

	/**
	 * Install stubs for WP functions used by OD_Bridge.
	 */
	private function install_common_stubs(): void {
		Functions\stubs(
			array(
				'get_option',
				'update_option',
				'apply_filters',
				'get_current_blog_id',
				'is_multisite',
				'home_url',
				'untrailingslashit',
				'esc_url_raw',
				'add_query_arg',
				'wp_parse_url',
				'get_transient',
				'is_singular',
				'is_front_page',
				'get_post_meta',
				'get_the_ID',
				'wp_normalize_path',
			)
		);

		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				if ( 'wppo_settings' === $name ) {
					return $this->options;
				}
				return $fallback;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
				// Default: return value unchanged. Tests override with expectation.
				$args = func_get_args();
				return $args[1];
			}
		);
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'http://example.com' . (string) $path;
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $v ) {
				return rtrim( (string) $v, '/' );
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'add_query_arg' )->alias(
			static function () {
				return 'http://example.com/current-page/';
			}
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_ID' )->justReturn( 0 );
		Functions\when( 'wp_normalize_path' )->alias(
			static function ( $p ) {
				return str_replace( '\\', '/', (string) $p );
			}
		);

		global $wp;
		$wp          = new \stdClass();
		$wp->request = 'current-page';

		// Ensure global metrics cleared.
		$GLOBALS['od_url_metrics'] = array();
		if ( ! isset( $GLOBALS['od_metrics_stub'] ) ) {
			$GLOBALS['od_metrics_stub'] = array();
		}
		$GLOBALS['od_metrics_stub'] = $this->od_metrics;
	}

	/**
	 * Define OD stub class if not already defined.
	 */
	private function ensure_od_class(): void {
		if ( ! class_exists( 'OD_URL_Metric' ) ) {
			eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged
				'
                class OD_URL_Metric {
                    private $data;
                    public function __construct( $data = array() ) { $this->data = $data; }
                    public function get_lcp_element() { return $this->data["lcp"] ?? null; }
                    public function get_elements() { return $this->data["elements"] ?? array(); }
                    public function get_viewport_width() { return $this->data["viewportWidth"] ?? 0; }
                    public function get_url() { return $this->data["url"] ?? ""; }
                    public function is_lcp() { return !empty($this->data["isLCP"]); }
                    public function get_src() { return $this->data["src"] ?? ""; }
                }
                '
			);
		}

		if ( ! function_exists( 'od_get_url_metrics' ) ) {
			eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged
				'
                function od_get_url_metrics( $url = "" ) {
                    return $GLOBALS["od_metrics_stub"] ?? array();
                }
                '
			);
		}
	}

	/**
	 * Test is_od_available false when no OD class or function exists (initial state).
	 * This must run before any stub definition.
	 */
	public function test_is_od_available_false_initially(): void {
		$this->install_common_stubs();
		// Ensure no OD class/function at this point — if a previous test defined it,
		// this test would see true, so we check the actual state and skip if polluted.
		if ( class_exists( 'OD_URL_Metric' ) || function_exists( 'od_get_url_metrics' ) ) {
			$this->markTestSkipped( 'OD stub already defined in this process — skipping initial absent check.' );
		}

		$this->assertFalse( OD_Bridge::is_od_available() );
		$this->assertFalse( OD_Bridge::is_enabled() );
		$this->assertSame( '', OD_Bridge::get_lcp_url() );
	}

	/**
	 * Test is_od_available true when OD class exists.
	 */
	public function test_is_od_available_true_when_class_exists(): void {
		$this->install_common_stubs();
		$this->ensure_od_class();

		$this->assertTrue( OD_Bridge::is_od_available() );
	}

	/**
	 * Test is_enabled auto true when OD active and no stored setting.
	 */
	public function test_is_enabled_auto_true_when_od_active(): void {
		$this->install_common_stubs();
		$this->ensure_od_class();
		$this->options = array(); // No stored od_integration.enabled.

		$this->assertTrue( OD_Bridge::is_enabled() );
	}

	/**
	 * Test is_enabled respects stored setting false.
	 */
	public function test_is_enabled_respects_stored_false(): void {
		$this->install_common_stubs();
		$this->ensure_od_class();
		$this->options = array(
			'od_integration' => array( 'enabled' => false ),
		);
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		// Re-stub get_option to reflect updated $this->options via Util cache clear.
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return isset( $this->options[ $name ] ) ? $this->options[ $name ] : ( 'wppo_settings' === $name ? $this->options : $fallback );
			}
		);
		// Direct check: Util::get_settings will read from overridden get_option.
		$this->assertFalse( OD_Bridge::is_enabled() );
	}

	/**
	 * Test filter wppo_od_should_optimize can disable optimization.
	 */
	public function test_filter_can_disable_optimization(): void {
		$this->install_common_stubs();
		$this->ensure_od_class();
		$this->options = array(
			'od_integration' => array( 'enabled' => true ),
		);

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wppo_od_should_optimize' === $hook ) {
					return false;
				}
				return $value;
			}
		);

		$this->assertFalse( OD_Bridge::is_enabled() );
		$this->assertSame( '', OD_Bridge::get_lcp_url() );
	}

	/**
	 * Test get_lcp_url returns empty when OD not available.
	 */
	public function test_get_lcp_url_empty_when_od_not_available(): void {
		$this->install_common_stubs();
		// If OD already defined, skip this absent-path check.
		if ( class_exists( 'OD_URL_Metric' ) || function_exists( 'od_get_url_metrics' ) ) {
			$this->markTestSkipped( 'OD stub already defined — skipping absent metric test.' );
		}
		$this->assertSame( '', OD_Bridge::get_lcp_url() );
	}

	/**
	 * Test get_lcp_url returns LCP URL from measured OD metrics (single viewport).
	 */
	public function test_get_lcp_url_from_single_viewport(): void {
		$this->install_common_stubs();
		$this->ensure_od_class();
		$this->options = array(
			'od_integration' => array( 'enabled' => true ),
		);

		$hero_url                   = 'https://example.com/wp-content/uploads/hero.jpg';
		$this->od_metrics           = array(
			new \OD_URL_Metric(
				array(
					'viewportWidth' => 400,
					'lcp'           => array(
						'src'   => $hero_url,
						'isLCP' => true,
					),
					'url'           => $hero_url,
				)
			),
		);
		$GLOBALS['od_metrics_stub'] = $this->od_metrics;

		$this->assertSame( $hero_url, OD_Bridge::get_lcp_url() );
	}

	/**
	 * Test get_lcp_url with viewport groups mobile/desktop distinct LCP tags.
	 */
	public function test_get_lcp_url_with_viewport_groups(): void {
		$this->install_common_stubs();
		$this->ensure_od_class();
		$this->options = array(
			'od_integration' => array( 'enabled' => true ),
		);

		$mobile_url                 = 'https://example.com/wp-content/uploads/hero-mobile.jpg';
		$desktop_url                = 'https://example.com/wp-content/uploads/hero-desktop.jpg';
		$this->od_metrics           = array(
			new \OD_URL_Metric(
				array(
					'viewportWidth' => 360,
					'lcp'           => array(
						'src'   => $mobile_url,
						'isLCP' => true,
					),
					'url'           => $mobile_url,
				)
			),
			new \OD_URL_Metric(
				array(
					'viewportWidth' => 1200,
					'lcp'           => array(
						'src'   => $desktop_url,
						'isLCP' => true,
					),
					'url'           => $desktop_url,
				)
			),
		);
		$GLOBALS['od_metrics_stub'] = $this->od_metrics;

		$lcp = OD_Bridge::get_lcp_url();
		// Should return mobile-first LCP.
		$this->assertSame( $mobile_url, $lcp );
	}

	/**
	 * Test get_exclude_first_images_count returns measured count for viewport groups.
	 */
	public function test_get_exclude_count_measured_from_viewport_groups(): void {
		$this->install_common_stubs();
		$this->ensure_od_class();
		$this->options = array(
			'od_integration' => array( 'enabled' => true ),
		);

		$mobile_url                 = 'https://example.com/wp-content/uploads/hero-mobile.jpg';
		$desktop_url                = 'https://example.com/wp-content/uploads/hero-desktop.jpg';
		$this->od_metrics           = array(
			new \OD_URL_Metric(
				array(
					'viewportWidth' => 360,
					'lcp'           => array(
						'src'   => $mobile_url,
						'isLCP' => true,
					),
				)
			),
			new \OD_URL_Metric(
				array(
					'viewportWidth' => 1200,
					'lcp'           => array(
						'src'   => $desktop_url,
						'isLCP' => true,
					),
				)
			),
		);
		$GLOBALS['od_metrics_stub'] = $this->od_metrics;

		// Distinct LCPs = 2 => threshold 2.
		$this->assertSame( 2, OD_Bridge::get_exclude_first_images_count() );
	}

	/**
	 * Test get_exclude_first_images_count single LCP returns 1.
	 */
	public function test_get_exclude_count_single_lcp_returns_one(): void {
		$this->install_common_stubs();
		$this->ensure_od_class();
		$this->options = array(
			'od_integration' => array( 'enabled' => true ),
		);

		$hero                       = 'https://example.com/wp-content/uploads/hero.jpg';
		$this->od_metrics           = array(
			new \OD_URL_Metric(
				array(
					'viewportWidth' => 400,
					'lcp'           => array(
						'src'   => $hero,
						'isLCP' => true,
					),
				)
			),
			new \OD_URL_Metric(
				array(
					'viewportWidth' => 800,
					'lcp'           => array(
						'src'   => $hero,
						'isLCP' => true,
					),
				)
			),
		);
		$GLOBALS['od_metrics_stub'] = $this->od_metrics;

		$this->assertSame( 1, OD_Bridge::get_exclude_first_images_count() );
	}

	/**
	 * Test degrade to heuristic 1-3 when OD enabled but no metrics.
	 */
	public function test_get_exclude_count_degrades_to_heuristic_when_no_metrics(): void {
		$this->install_common_stubs();
		$this->ensure_od_class();
		$this->options = array(
			'od_integration'     => array( 'enabled' => true ),
			'image_optimisation' => array( 'excludeFirstImages' => 3 ),
		);

		$this->od_metrics           = array();
		$GLOBALS['od_metrics_stub'] = $this->od_metrics;

		// OD enabled but no metrics — should still return measured via group count fallback or heuristic.
		// With no metrics, group count 0 => heuristic 3.
		$this->assertSame( 3, OD_Bridge::get_exclude_first_images_count() );
	}

	/**
	 * Test heuristic fallback 1-3 when OD not available.
	 */
	public function test_get_exclude_count_heuristic_fallback(): void {
		$this->install_common_stubs();
		// Skip if OD already defined.
		if ( class_exists( 'OD_URL_Metric' ) || function_exists( 'od_get_url_metrics' ) ) {
			// Force OD disabled via filter to test heuristic path.
			Functions\when( 'apply_filters' )->alias(
				static function ( $hook, $value ) {
					if ( 'wppo_od_should_optimize' === $hook ) {
						return false;
					}
					return $value;
				}
			);
		}

		$this->options = array(
			'image_optimisation' => array( 'excludeFirstImages' => 2 ),
		);

		// When OD disabled via filter, heuristic should be used.
		$this->assertSame( 2, OD_Bridge::get_exclude_first_images_count() );
	}

	/**
	 * Test heuristic clamping 0 => 1 and >3 => 3.
	 */
	public function test_heuristic_clamping(): void {
		$this->install_common_stubs();
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wppo_od_should_optimize' === $hook ) {
					return false;
				}
				return $value;
			}
		);

		$this->options = array(
			'image_optimisation' => array( 'excludeFirstImages' => 0 ),
		);
		$this->assertSame( 1, OD_Bridge::get_exclude_first_images_count() );

		$this->options = array(
			'image_optimisation' => array( 'excludeFirstImages' => 10 ),
		);
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return 'wppo_settings' === $name ? $this->options : $fallback;
			}
		);
		$this->assertSame( 3, OD_Bridge::get_exclude_first_images_count() );
	}

	/**
	 * Test no stored excludeFirstImages defaults to heuristic 2.
	 */
	public function test_heuristic_default_two(): void {
		$this->install_common_stubs();
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wppo_od_should_optimize' === $hook ) {
					return false;
				}
				return $value;
			}
		);
		$this->options = array();
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return 'wppo_settings' === $name ? $this->options : $fallback;
			}
		);
		$this->assertSame( 2, OD_Bridge::get_exclude_first_images_count() );
	}

	/**
	 * Test Image_Optimisation integrates OD LCP for fetchpriority.
	 */
	public function test_image_optimisation_uses_od_lcp(): void {
		require_once __DIR__ . '/stubs/wp-html-api.php';
		$this->install_common_stubs();
		$this->ensure_od_class();

		$hero                       = 'https://example.com/wp-content/uploads/od-hero.jpg';
		$this->od_metrics           = array(
			new \OD_URL_Metric(
				array(
					'viewportWidth' => 400,
					'lcp'           => array(
						'src'   => $hero,
						'isLCP' => true,
					),
				)
			),
		);
		$GLOBALS['od_metrics_stub'] = $this->od_metrics;
		$this->options              = array(
			'od_integration'     => array( 'enabled' => true ),
			'image_optimisation' => array(
				'prioritizeLCPImages' => true,
				'excludeFirstImages'  => 0,
				'lazyLoadNative'      => true,
			),
		);

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );

		$image_opt = new Image_Optimisation( $this->options );

		$html   = '<img src="' . $hero . '" loading="lazy" />';
		$result = $image_opt->prioritize_lcp_in_buffer( $html, $html );

		$this->assertStringContainsString( 'fetchpriority="high"', $result );
		$this->assertStringNotContainsString( 'loading="lazy"', $result );
	}

	/**
	 * Test that non-LCP elements are not added to LCP URL list (H-03 regression).
	 *
	 * A metric with get_lcp_element returning a non-LCP element or elements array
	 * where is_lcp false must not pollute the LCP URL.
	 */
	public function test_non_lcp_elements_not_added_to_lcp_url(): void {
		$this->install_common_stubs();
		$this->ensure_od_class();
		$this->options = array(
			'od_integration' => array( 'enabled' => true ),
		);

		// Create a stub metric where get_lcp_element returns null and get_elements returns non-LCP.
		$non_lcp_metric = new class() {
			public function get_lcp_element() { return null; }
			public function get_elements() {
				return array(
					array( 'src' => 'https://example.com/not-lcp.jpg', 'isLCP' => false ),
					array( 'src' => 'https://example.com/also-not-lcp.jpg', 'isLCP' => false ),
				);
			}
			public function get_viewport_width() { return 400; }
		};
		$this->od_metrics           = array( $non_lcp_metric );
		$GLOBALS['od_metrics_stub'] = $this->od_metrics;

		$this->assertSame( '', OD_Bridge::get_lcp_url(), 'Non-LCP elements must not yield an LCP URL' );

		// Same via array shape: metric array with elements not LCP.
		$this->od_metrics = array(
			array(
				'elements' => array(
					array( 'src' => 'https://example.com/bogus.jpg', 'isLCP' => false ),
				),
			),
		);
		$GLOBALS['od_metrics_stub'] = $this->od_metrics;
		$this->assertSame( '', OD_Bridge::get_lcp_url(), 'Array-shaped non-LCP elements must not yield LCP URL' );
	}

	/**
	 * Test that mixed LCP + non-LCP metrics only returns the LCP URL.
	 */
	public function test_mixed_lcp_and_non_lcp_returns_only_lcp(): void {
		$this->install_common_stubs();
		$this->ensure_od_class();
		$this->options = array(
			'od_integration' => array( 'enabled' => true ),
		);

		$lcp_url     = 'https://example.com/real-lcp.jpg';
		$non_lcp_url = 'https://example.com/not-lcp.jpg';

		$metric_with_both = new class( $lcp_url, $non_lcp_url ) {
			private $lcp;
			private $non_lcp;
			public function __construct( $lcp, $non_lcp ) {
				$this->lcp     = $lcp;
				$this->non_lcp = $non_lcp;
			}
			public function get_lcp_element() {
				return array( 'src' => $this->lcp, 'isLCP' => true );
			}
			public function get_elements() {
				return array(
					array( 'src' => $this->lcp, 'isLCP' => true ),
					array( 'src' => $this->non_lcp, 'isLCP' => false ),
				);
			}
			public function get_viewport_width() { return 360; }
		};

		$this->od_metrics           = array( $metric_with_both );
		$GLOBALS['od_metrics_stub'] = $this->od_metrics;

		$this->assertSame( $lcp_url, OD_Bridge::get_lcp_url() );
		$this->assertStringNotContainsString( $non_lcp_url, OD_Bridge::get_lcp_url() );

		// For exclude count, non-LCP should not inflate distinct count.
		$this->assertSame( 1, OD_Bridge::get_exclude_first_images_count() );
	}

	/**
	 * Data provider for OD present/absent threshold.
	 *
	 * @return array
	 */
	public static function od_threshold_provider(): array {
		return array(
			'od_present_mobile_desktop' => array( true, 2 ),
			'od_present_single'         => array( true, 1 ),
			'od_absent_heuristic'       => array( false, 2 ),
		);
	}

	/**
	 * Test threshold via data provider.
	 *
	 * @dataProvider od_threshold_provider
	 * @param bool $od_present Whether OD is present.
	 * @param int  $expected_threshold Expected threshold.
	 */
	public function test_threshold_data_provider( bool $od_present, int $expected_threshold ): void {
		$this->install_common_stubs();

		if ( $od_present ) {
			$this->ensure_od_class();
			$this->options = array(
				'od_integration' => array( 'enabled' => true ),
			);

			// Provide 2 distinct LCPs when expected is 2, else 1.
			if ( 2 === $expected_threshold ) {
				$this->od_metrics = array(
					new \OD_URL_Metric(
						array(
							'viewportWidth' => 360,
							'lcp'           => array(
								'src'   => 'https://example.com/a.jpg',
								'isLCP' => true,
							),
						)
					),
					new \OD_URL_Metric(
						array(
							'viewportWidth' => 1200,
							'lcp'           => array(
								'src'   => 'https://example.com/b.jpg',
								'isLCP' => true,
							),
						)
					),
				);
			} else {
				$this->od_metrics = array(
					new \OD_URL_Metric(
						array(
							'viewportWidth' => 360,
							'lcp'           => array(
								'src'   => 'https://example.com/a.jpg',
								'isLCP' => true,
							),
						)
					),
				);
			}
			$GLOBALS['od_metrics_stub'] = $this->od_metrics;
			$this->assertSame( $expected_threshold, OD_Bridge::get_exclude_first_images_count() );
		} else {
			Functions\when( 'apply_filters' )->alias(
				static function ( $hook, $value ) {
					if ( 'wppo_od_should_optimize' === $hook ) {
						return false;
					}
					return $value;
				}
			);
			$this->options = array(
				'image_optimisation' => array( 'excludeFirstImages' => $expected_threshold ),
			);
			\PerformanceOptimise\Inc\Util::clear_settings_cache();
			Functions\when( 'get_option' )->alias(
				function ( $name, $fallback = false ) {
					return 'wppo_settings' === $name ? $this->options : $fallback;
				}
			);
			$this->assertSame( $expected_threshold, OD_Bridge::get_exclude_first_images_count() );
		}
	}
}
