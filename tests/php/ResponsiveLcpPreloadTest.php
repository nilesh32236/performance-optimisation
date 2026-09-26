<?php
/**
 * Tests for breakpoint-specific responsive LCP preload (issue #1429).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Image_Optimisation;
use PerformanceOptimise\Inc\OD_Bridge;
use Brain\Monkey\Functions;

/**
 * Covers OD-confirmed responsive preload, per-type variants,
 * art-directed skip, no-signal fallback, and the single-high invariant.
 */
class ResponsiveLcpPreloadTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory wppo_settings.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Install common WP stubs.
	 */
	private function install_stubs(): void {
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
				'esc_attr',
				'esc_url',
				'add_query_arg',
				'wp_parse_url',
				'get_transient',
				'is_singular',
				'is_front_page',
				'get_post_meta',
				'get_the_ID',
				'wp_normalize_path',
				'wp_kses',
				'has_filter',
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
				$args = func_get_args();
				return $args[1];
			}
		);
		Functions\when( 'has_filter' )->justReturn( false );
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
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
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
		Functions\when( 'wp_kses' )->alias(
			static function ( $html ) {
				return (string) $html;
			}
		);

		global $wp;
		$wp          = new \stdClass();
		$wp->request = 'current-page';

		$GLOBALS['od_url_metrics']  = array();
		$GLOBALS['od_metrics_stub'] = array();
		$GLOBALS['wp_version']      = '6.8';
		$_SERVER['REQUEST_URI']     = '/current-page/';
	}

	/**
	 * Define OD stubs once per process.
	 */
	private function ensure_od_stubs(): void {
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
	 * Build an Image_Optimisation instance.
	 *
	 * @return Image_Optimisation
	 */
	private function make_image_opt(): Image_Optimisation {
		return new Image_Optimisation( $this->options );
	}

	/**
	 * OD-confirmed LCP emits one preload with matching srcset/sizes.
	 */
	public function test_od_confirmed_emits_single_preload_with_srcset(): void {
		$this->install_stubs();
		$this->ensure_od_stubs();
		$this->options = array();
		OD_Bridge::clear_request_memo();
		Image_Optimisation::clear_runtime_caches();

		$url                        = 'http://example.com/wp-content/uploads/hero.jpg';
		$srcset                     = $url . ' 480w, ' . $url . ' 800w';
		$sizes                      = '(max-width: 600px) 480px, 800px';
		$GLOBALS['od_metrics_stub'] = array(
			array(
				'elements' => array(
					array(
						'isLCP'  => true,
						'src'    => $url,
						'srcset' => $srcset,
						'sizes'  => $sizes,
					),
				),
			),
			array(
				'elements' => array(
					array(
						'isLCP'  => true,
						'src'    => $url,
						'srcset' => $srcset,
						'sizes'  => $sizes,
					),
				),
			),
		);

		$elements = OD_Bridge::get_breakpoint_lcp_elements();
		$this->assertNotEmpty( $elements );
		$this->assertSame( $url, $elements[0]['url'] );

		$img = $this->make_image_opt();
		$tag = $img->emit_responsive_lcp_preload();
		$this->assertStringContainsString( 'rel="preload"', $tag );
		$this->assertStringContainsString( 'fetchpriority="high"', $tag );
		$this->assertStringContainsString( 'imagesrcset=', $tag );
		$this->assertStringContainsString( 'imagesizes=', $tag );
		$this->assertSame( 1, substr_count( $tag, 'fetchpriority' ) );
	}

	/**
	 * Picture, background, and video-poster variants are covered per type.
	 */
	public function test_per_type_variants_covered(): void {
		$this->install_stubs();
		$this->ensure_od_stubs();

		foreach ( array( 'picture', 'background', 'video-poster' ) as $type ) {
			$this->options = array();
			OD_Bridge::clear_request_memo();
			Image_Optimisation::clear_runtime_caches();
			$url = 'http://example.com/wp-content/uploads/hero-' . $type . '.jpg';
			$el  = array(
				'isLCP' => true,
				'src'   => $url,
				'type'  => $type,
			);
			if ( 'picture' === $type ) {
				$el['srcset'] = $url . ' 480w, ' . $url . ' 800w';
				$el['sizes']  = '(max-width: 600px) 480px, 800px';
			}
			if ( 'video-poster' === $type ) {
				$el['poster'] = $url;
			}
			$GLOBALS['od_metrics_stub'] = array( array( 'elements' => array( $el ) ) );

			$elements = OD_Bridge::get_breakpoint_lcp_elements();
			$this->assertNotEmpty( $elements );
			$this->assertSame( $type, $elements[0]['type'] );

			$img = $this->make_image_opt();
			$tag = $img->emit_responsive_lcp_preload();
			$this->assertStringContainsString( 'fetchpriority="high"', $tag );
			$this->assertStringContainsString( $url, $tag );
		}
	}

	/**
	 * Art-directed picture media without support is skipped.
	 */
	public function test_art_directed_picture_skipped(): void {
		$this->install_stubs();
		$this->ensure_od_stubs();
		$this->options = array();
		OD_Bridge::clear_request_memo();
		Image_Optimisation::clear_runtime_caches();

		$GLOBALS['od_metrics_stub'] = array(
			array(
				'elements' => array(
					array(
						'isLCP'  => true,
						'src'    => 'http://example.com/wp-content/uploads/hero-mobile.jpg',
						'type'   => 'picture',
						'media'  => '(max-width: 768px)',
						'srcset' => 'http://example.com/wp-content/uploads/hero-mobile.jpg 480w',
						'sizes'  => '(max-width: 768px) 480px',
					),
				),
			),
			array(
				'elements' => array(
					array(
						'isLCP'  => true,
						'src'    => 'http://example.com/wp-content/uploads/hero-desktop.jpg',
						'type'   => 'picture',
						'media'  => '(min-width: 769px)',
						'srcset' => 'http://example.com/wp-content/uploads/hero-desktop.jpg 1200w',
						'sizes'  => '1200px',
					),
				),
			),
		);

		$img = $this->make_image_opt();
		$tag = $img->emit_responsive_lcp_preload();
		$this->assertSame( '', $tag );
	}

	/**
	 * No OD/RUM signal falls back to empty (legacy hero path owns emission).
	 */
	public function test_no_signal_falls_back_to_empty(): void {
		$this->install_stubs();
		$this->ensure_od_stubs();
		$this->options = array();
		OD_Bridge::clear_request_memo();
		Image_Optimisation::clear_runtime_caches();
		$GLOBALS['od_metrics_stub'] = array();

		$img = $this->make_image_opt();
		$tag = $img->emit_responsive_lcp_preload();
		$this->assertSame( '', $tag );
	}

	/**
	 * Never more than one fetchpriority high per response.
	 */
	public function test_never_more_than_one_high(): void {
		$this->install_stubs();
		$this->ensure_od_stubs();
		$this->options = array();
		OD_Bridge::clear_request_memo();
		Image_Optimisation::clear_runtime_caches();

		$url                        = 'http://example.com/wp-content/uploads/hero.jpg';
		$GLOBALS['od_metrics_stub'] = array(
			array(
				'elements' => array(
					array(
						'isLCP'  => true,
						'src'    => $url,
						'srcset' => $url . ' 480w, ' . $url . ' 800w',
						'sizes'  => '(max-width: 600px) 480px, 800px',
					),
				),
			),
		);

		$img   = $this->make_image_opt();
		$first = $img->emit_responsive_lcp_preload();
		$this->assertStringContainsString( 'fetchpriority="high"', $first );
		$second = $img->emit_responsive_lcp_preload();
		$this->assertSame( '', $second );
		$this->assertSame( 1, substr_count( $first . $second, 'fetchpriority="high"' ) );
	}
}
