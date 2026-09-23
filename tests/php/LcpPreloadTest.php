<?php
/**
 * Regression tests for the ARCH-008 Lcp_Preload extraction (issue #1542).
 *
 * Proves the LCP/hero-preload cluster moved from `Image_Optimisation` to
 * `PerformanceOptimise\Inc\Lcp_Preload` without behavior change:
 *
 * - Preload link-tag output parity (manual/field/responsive heroes) and
 *   exactly-once dedup across repeated emissions.
 * - Hero slot claim/release semantics (single-high invariant).
 * - LCP resolution precedence (manual > RUM-field > OD > auto > heuristic).
 * - Exclusion-list handling.
 * - Multisite isolation for the same-path-different-blog case (blog-scoped
 *   heuristic memo key + `switch_blog` reset wiring).
 * - Facade parity: every moved entry keeps its owner signature/visibility
 *   so `Critical_CSS`, `Main`, `Deactivate`, `OD_Bridge`, and `Hook_Registry`
 *   stay byte-identical with zero caller migration.
 *
 * Instance state stays on `Image_Optimisation` (Option A); the suite talks
 * to the owner facade (proxies) plus the public static dedup API, mirroring
 * how production callers reach the cluster.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Image_Optimisation;
use PerformanceOptimise\Inc\Lcp_Preload;
use PerformanceOptimise\Inc\Loader_Map;
use PerformanceOptimise\Inc\OD_Bridge;
use PerformanceOptimise\Inc\RUM;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * LCP preload boundary tests (ARCH-008).
 */
class LcpPreloadTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Default options for testing.
	 *
	 * @var array
	 */
	private array $default_options = array();

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		Image_Optimisation::clear_runtime_caches();
		if ( class_exists( 'PerformanceOptimise\Inc\RUM' ) && method_exists( 'PerformanceOptimise\Inc\RUM', 'clear_stored_lcp_memo' ) ) {
			\PerformanceOptimise\Inc\RUM::clear_stored_lcp_memo();
		}
		$this->default_options = array(
			'image_optimisation' => array(
				'lazyLoadImages'  => true,
				'autoPreloadLCP'  => false,
				'convertToWebp'   => true,
				'convertToAvif'   => false,
				'placeholderType' => 'svg',
			),
		);
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Unit stub mirroring the bootstrap alias.
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	}

	/**
	 * Stub the field-LCP (RUM aggregate + stored transient) environment.
	 *
	 * Mirrors the ImageOptimisationTest recipe: plugin settings for
	 * 'wppo_settings', the RUM aggregate under the RUM option, and the
	 * heuristic URL via the stored-LCP transient.
	 *
	 * @param array  $wppo_settings  Plugin settings.
	 * @param array  $rum_aggregate  Aggregate stored under the RUM option.
	 * @param string $heuristic_url  Stored-LCP transient value.
	 * @return void
	 */
	private function stub_field_lcp_environment( array $wppo_settings, array $rum_aggregate, string $heuristic_url ): void {
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'untrailingslashit' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'add_query_arg' )->justReturn( '/hero-page/' );
		Functions\when( 'get_transient' )->justReturn( $heuristic_url );
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) use ( $wppo_settings, $rum_aggregate ) {
				if ( 'wppo_settings' === $name ) {
					return $wppo_settings;
				}
				if ( RUM::OPTION === $name ) {
					return $rum_aggregate;
				}
				return $fallback;
			}
		);

		global $wp;
		$wp          = new \stdClass();
		$wp->request = 'hero-page';

		// OD state from earlier tests in the same process must not leak
		// into the resolution chain (the OD tier precedes field/stored).
		$GLOBALS['od_metrics_stub'] = array();
		$GLOBALS['od_url_metrics']  = array();
		if ( class_exists( 'PerformanceOptimise\Inc\OD_Bridge' ) && method_exists( 'PerformanceOptimise\Inc\OD_Bridge', 'clear_request_memo' ) ) {
			OD_Bridge::clear_request_memo();
		}

		Util::clear_settings_cache();
		RUM::clear_field_lcp_cache();
	}

	/**
	 * Build a RUM aggregate with a single LCP URL entry for a path.
	 *
	 * @param string $path      Page path.
	 * @param string $url       Raw LCP element URL.
	 * @param int    $samples   Observation count.
	 * @param int    $last_seen Last-seen timestamp.
	 * @return array Aggregate option value.
	 */
	private function make_rum_aggregate( string $path, string $url, int $samples, int $last_seen ): array {
		return array(
			'2026-09-09' => array(
				$path => array(
					'lcpUrls' => array(
						'entry' => array(
							'url'      => $url,
							'n'        => $samples,
							'lastSeen' => $last_seen,
						),
					),
				),
			),
		);
	}

	/**
	 * Define OD stubs once per process.
	 *
	 * @return void
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
	 * The loader map resolves the extracted service (ARCH-003 gate).
	 *
	 * @return void
	 */
	public function test_loader_map_resolves_lcp_preload(): void {
		require_once WPPO_PLUGIN_PATH . 'includes/class-loader-map.php';
		$this->assertSame( 'class-lcp-preload.php', Loader_Map::fallback_map()['Lcp_Preload'] );
		$path = Loader_Map::path_for( 'Lcp_Preload' );
		$this->assertNotNull( $path );
		$this->assertFileExists( (string) $path );
		$this->assertStringEndsWith( 'includes/class-lcp-preload.php', (string) $path );
	}

	/**
	 * Every moved entry keeps its owner signature and visibility (facade rule).
	 *
	 * Guards the zero-caller-migration contract: public/static entries used
	 * by Critical_CSS/Main/Deactivate/OD_Bridge plus the private entries
	 * used by the staying lazy/media pipeline and reflection tests.
	 *
	 * @return void
	 */
	public function test_facade_proxy_signatures_match_service(): void {
		$cases = array(
			// method => array(owner visibility, is static).
			'has_emitted_preload'             => array( 'public', true ),
			'mark_preload_emitted'            => array( 'public', true ),
			'get_lcp_responsive_data_for_url' => array( 'public', true ),
			'clear_runtime_caches'            => array( 'public', true ),
			'preload_images'                  => array( 'public', false ),
			'generate_img_preload'            => array( 'public', false ),
			'emit_responsive_lcp_preload'     => array( 'public', false ),
			'get_current_lcp_url'             => array( 'private', false ),
			'maybe_preload_hero_image'        => array( 'private', false ),
			'claim_hero_preload_slot'         => array( 'private', false ),
			'release_hero_preload_slot'       => array( 'private', true ),
			'get_all_preload_data'            => array( 'private', false ),
			'resolve_auto_lcp_url'            => array( 'private', false ),
			'get_heuristic_lcp_url'           => array( 'private', false ),
		);
		foreach ( $cases as $method => $expect ) {
			list( $visibility, $is_static ) = $expect;
			$owner                          = new \ReflectionMethod( Image_Optimisation::class, $method );
			$this->assertSame( $visibility, $owner->isPublic() ? 'public' : 'private', "Owner visibility for {$method}" );
			$this->assertSame( $is_static, $owner->isStatic(), "Owner staticness for {$method}" );
			// clear_runtime_caches stays on the owner by design (it
			// coordinates alt/placeholder/OD resets); the service owns the
			// LCP/preload slice via clear_lcp_preload_caches().
			$service_method = 'clear_runtime_caches' === $method ? 'clear_lcp_preload_caches' : $method;
			$this->assertTrue( method_exists( Lcp_Preload::class, $service_method ), "Service must own {$service_method}" );
			$service = new \ReflectionMethod( Lcp_Preload::class, $service_method );
			$this->assertTrue( $service->isPublic(), "Service entry {$method} must be public (ARCH-007 widening precedent)" );
			$this->assertSame( $is_static, $service->isStatic(), "Service staticness for {$method}" );
			$owner_params   = array_map( static fn( $p ) => $p->getName(), $owner->getParameters() );
			$service_params = array_map( static fn( $p ) => $p->getName(), $service->getParameters() );
			$this->assertSame( $owner_params, $service_params, "Parameter parity for {$method}" );
		}
	}

	/**
	 * The dedup set is shared between the owner facade and the service.
	 *
	 * @return void
	 */
	public function test_dedup_shared_between_owner_and_service(): void {
		$url = 'https://example.com/wp-content/uploads/hero.jpg';
		$this->assertFalse( Image_Optimisation::has_emitted_preload( $url ) );
		Image_Optimisation::mark_preload_emitted( $url );
		$this->assertTrue( Image_Optimisation::has_emitted_preload( $url ) );
		$this->assertTrue( Lcp_Preload::has_emitted_preload( $url ) );
		Lcp_Preload::mark_preload_emitted( $url, '(min-width: 800px)' );
		$this->assertTrue( Image_Optimisation::has_emitted_preload( $url, '(min-width: 800px)' ) );
		// Media variants are distinct keys.
		$this->assertFalse( Image_Optimisation::has_emitted_preload( $url, '(min-width: 100px)' ) );
	}

	/**
	 * Owner reset clears the service dedup set (reset wiring follows the state).
	 *
	 * @return void
	 */
	public function test_owner_reset_clears_service_dedup(): void {
		$url = 'https://example.com/wp-content/uploads/reset.jpg';
		Lcp_Preload::mark_preload_emitted( $url );
		$this->assertTrue( Lcp_Preload::has_emitted_preload( $url ) );
		Image_Optimisation::clear_runtime_caches();
		$this->assertFalse( Lcp_Preload::has_emitted_preload( $url ) );
		$this->assertFalse( Image_Optimisation::has_emitted_preload( $url ) );
	}

	/**
	 * Field-measured LCP emits exactly one preload; repeats emit nothing.
	 *
	 * @return void
	 */
	public function test_field_lcp_preload_emits_exactly_once(): void {
		require_once __DIR__ . '/stubs/wp-html-api.php';
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'wp_is_json_request' )->justReturn( false );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses' )->returnArg( 1 );
		Functions\when( 'get_the_ID' )->justReturn( 0 );
		$this->stub_field_lcp_environment(
			array( 'image_optimisation' => array( 'fieldLcpMinSamples' => 20 ) ),
			$this->make_rum_aggregate( '/hero-page/', 'https://example.com/wp-content/uploads/field.jpg', 20, time() ),
			''
		);
		$_SERVER['REQUEST_URI'] = '/hero-page/';

		$options = $this->default_options;
		$options['image_optimisation']['autoPreloadLCP']   = true;
		$options['image_optimisation']['fieldLcpOverride'] = true;
		$image_opt = new Image_Optimisation( $options );

		ob_start();
		$image_opt->preload_images();
		$first = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $first, 'rel="preload"' ) );
		$this->assertStringContainsString( 'as="image"', $first );
		$this->assertStringContainsString( 'fetchpriority="high"', $first );
		$this->assertStringContainsString( 'https://example.com/wp-content/uploads/field.jpg', $first );

		ob_start();
		$image_opt->preload_images();
		$second = (string) ob_get_clean();

		$this->assertSame( 0, substr_count( $second, 'rel="preload"' ) );
	}

	/**
	 * Install the OD-resolution stub environment (mirrors ResponsiveLcpPreloadTest).
	 *
	 * @return void
	 */
	private function install_od_stubs(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_ID' )->justReturn( 0 );
		Functions\when( 'is_front_page' )->justReturn( false );
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
		Functions\when( 'wp_kses' )->alias(
			static function ( $html ) {
				return (string) $html;
			}
		);
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				unset( $hook );
				return $value;
			}
		);
		$this->ensure_od_stubs();
		$GLOBALS['od_url_metrics']  = array();
		$GLOBALS['od_metrics_stub'] = array();
		$GLOBALS['wp_version']      = '6.8';
		$_SERVER['REQUEST_URI']     = '/current-page/';
		global $wp;
		$wp          = new \stdClass();
		$wp->request = 'current-page';
	}

	/**
	 * The responsive emitter honors the single-high invariant across calls.
	 *
	 * @return void
	 */
	public function test_responsive_emitter_single_high_invariant(): void {
		$this->install_od_stubs();

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
		OD_Bridge::clear_request_memo();
		Image_Optimisation::clear_runtime_caches();

		$image_opt = new Image_Optimisation( array() );
		$first     = $image_opt->emit_responsive_lcp_preload();
		$this->assertStringContainsString( 'rel="preload"', $first );
		$this->assertStringContainsString( 'fetchpriority="high"', $first );
		$this->assertSame( 1, substr_count( $first, 'fetchpriority' ) );

		// A second emission in the same response must degrade to empty.
		$second = $image_opt->emit_responsive_lcp_preload();
		$this->assertSame( '', $second );
	}

	/**
	 * Manual picker beats the RUM-field candidate and the OD candidate.
	 *
	 * @return void
	 */
	public function test_manual_lcp_beats_field_candidate(): void {
		$this->stub_field_lcp_environment(
			array( 'image_optimisation' => array( 'fieldLcpMinSamples' => 20 ) ),
			$this->make_rum_aggregate( '/hero-page/', 'https://example.com/wp-content/uploads/field.jpg', 20, time() ),
			'https://example.com/wp-content/uploads/heuristic.jpg'
		);
		$_SERVER['REQUEST_URI'] = '/hero-page/';
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 42 );
		Functions\when( 'get_post_meta' )->alias(
			static function ( $post_id, $key, $single ) {
				unset( $post_id, $single );
				return '_wppo_lcp_preload_url' === $key ? 'https://example.com/wp-content/uploads/pinned.jpg' : '';
			}
		);
		// An OD-confirmed hero for the same page must lose to the pin.
		$this->ensure_od_stubs();
		$GLOBALS['od_metrics_stub'] = array(
			array(
				'elements' => array(
					array(
						'isLCP'  => true,
						'src'    => 'https://example.com/wp-content/uploads/od.jpg',
						'srcset' => '',
						'sizes'  => '',
					),
				),
			),
			array(
				'elements' => array(
					array(
						'isLCP'  => true,
						'src'    => 'https://example.com/wp-content/uploads/od.jpg',
						'srcset' => '',
						'sizes'  => '',
					),
				),
			),
		);
		OD_Bridge::clear_request_memo();

		$options = $this->default_options;
		$options['image_optimisation']['fieldLcpOverride'] = true;
		$image_opt = new Image_Optimisation( $options );

		$manual = new \ReflectionMethod( Image_Optimisation::class, 'get_manual_lcp_url' );
		$this->assertSame( 'https://example.com/wp-content/uploads/pinned.jpg', $manual->invoke( $image_opt ) );

		$od_only = new \ReflectionMethod( Image_Optimisation::class, 'resolve_od_only_lcp_url' );
		$this->assertSame( 'https://example.com/wp-content/uploads/pinned.jpg', $od_only->invoke( $image_opt ) );

		$auto = new \ReflectionMethod( Image_Optimisation::class, 'resolve_auto_lcp_url' );
		$this->assertSame( 'https://example.com/wp-content/uploads/pinned.jpg', $auto->invoke( $image_opt ) );
	}

	/**
	 * RUM-field candidate beats the stored heuristic when samples suffice.
	 *
	 * @return void
	 */
	public function test_field_candidate_beats_heuristic(): void {
		$this->stub_field_lcp_environment(
			array( 'image_optimisation' => array( 'fieldLcpMinSamples' => 20 ) ),
			$this->make_rum_aggregate( '/hero-page/', 'https://example.com/wp-content/uploads/field.jpg', 20, time() ),
			'https://example.com/wp-content/uploads/heuristic.jpg'
		);
		$_SERVER['REQUEST_URI'] = '/hero-page/';

		$options = $this->default_options;
		$options['image_optimisation']['fieldLcpOverride'] = true;
		$image_opt = new Image_Optimisation( $options );

		$reflection = new \ReflectionMethod( Image_Optimisation::class, 'get_current_lcp_url' );
		$this->assertSame( 'https://example.com/wp-content/uploads/field.jpg', $reflection->invoke( $image_opt ) );
	}

	/**
	 * The OD tier resolves the OD-confirmed URL when no manual pick exists.
	 *
	 * @return void
	 */
	public function test_od_tier_resolves_od_url_without_manual(): void {
		$this->install_od_stubs();
		OD_Bridge::clear_request_memo();

		$url                        = 'http://example.com/wp-content/uploads/od-hero.jpg';
		$GLOBALS['od_metrics_stub'] = array(
			array(
				'elements' => array(
					array(
						'isLCP'  => true,
						'src'    => $url,
						'srcset' => '',
						'sizes'  => '',
					),
				),
			),
			array(
				'elements' => array(
					array(
						'isLCP'  => true,
						'src'    => $url,
						'srcset' => '',
						'sizes'  => '',
					),
				),
			),
		);

		$image_opt  = new Image_Optimisation( $this->default_options );
		$reflection = new \ReflectionMethod( Image_Optimisation::class, 'resolve_od_only_lcp_url' );
		$this->assertSame( $url, $reflection->invoke( $image_opt ) );
	}

	/**
	 * The heuristic tier resolves the first image from a buffer.
	 *
	 * @return void
	 */
	public function test_heuristic_tier_resolves_first_buffer_image(): void {
		require_once __DIR__ . '/stubs/wp-html-api.php';
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$image_opt  = new Image_Optimisation( $this->default_options );
		$reflection = new \ReflectionMethod( Image_Optimisation::class, 'get_heuristic_lcp_url' );
		$buffer     = '<html><body><img src="http://example.com/wp-content/uploads/first.jpg"><img src="http://example.com/wp-content/uploads/second.jpg"></body></html>';
		$this->assertSame( 'http://example.com/wp-content/uploads/first.jpg', $reflection->invoke( $image_opt, $buffer ) );
		$this->assertSame( '', $reflection->invoke( $image_opt, '' ) );
	}

	/**
	 * Exclusion lists drop matching URLs from preload candidacy.
	 *
	 * @return void
	 */
	public function test_exclusion_lists_drop_matching_urls(): void {
		$image_opt  = new Image_Optimisation( $this->default_options );
		$reflection = new \ReflectionMethod( Image_Optimisation::class, 'should_exclude_image' );
		$excluded   = array( 'http://example.com/wp-content/uploads/skip.jpg' );
		$this->assertTrue( $reflection->invoke( $image_opt, 'http://example.com/wp-content/uploads/skip.jpg', $excluded ) );
		$this->assertTrue( $reflection->invoke( $image_opt, 'http://example.com/wp-content/uploads/skip.jpg?ver=123', $excluded ) );
		$this->assertFalse( $reflection->invoke( $image_opt, 'http://example.com/wp-content/uploads/keep.jpg', $excluded ) );

		$count = new \ReflectionMethod( Image_Optimisation::class, 'get_effective_exclude_first_images_count' );
		$this->assertIsInt( $count->invoke( $image_opt, array() ) );
	}

	/**
	 * Same-path-different-blog buffers never share a heuristic verdict.
	 *
	 * The heuristic memo key is blog-scoped, and the per-request dedup set
	 * is flushed by the owner reset (the `switch_blog` wiring), so site B
	 * can never reuse site A's memo entries.
	 *
	 * @return void
	 */
	public function test_multisite_same_path_different_blog_isolation(): void {
		$buffer = '<html><body><img src="http://example.com/site-a/uploads/first.jpg"></body></html>';

		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		$key_method = new \ReflectionMethod( Lcp_Preload::class, 'heuristic_memo_key' );
		$key_blog_1 = $key_method->invoke( null, $buffer );

		Functions\when( 'get_current_blog_id' )->justReturn( 2 );
		$key_blog_2 = $key_method->invoke( null, $buffer );

		$this->assertNotSame( $key_blog_1, $key_blog_2 );
		$this->assertStringStartsWith( '1:', $key_blog_1 );
		$this->assertStringStartsWith( '2:', $key_blog_2 );

		// Dedup entries are per-request: the owner reset (wired to
		// switch_blog) flushes them so a hero emitted on blog 1 is not
		// treated as already emitted on blog 2.
		$url = 'https://example.com/shared-path/hero.jpg';
		Image_Optimisation::mark_preload_emitted( $url );
		$this->assertTrue( Image_Optimisation::has_emitted_preload( $url ) );
		Image_Optimisation::clear_runtime_caches();
		$this->assertFalse( Image_Optimisation::has_emitted_preload( $url ) );
	}

	/**
	 * Responsive attachment data helper stays reachable through the facade.
	 *
	 * @return void
	 */
	public function test_responsive_data_helper_reachable_via_facade(): void {
		Functions\when( 'attachment_url_to_postid' )->justReturn( 0 );
		$this->assertSame(
			array(
				'srcset' => '',
				'sizes'  => '',
			),
			Image_Optimisation::get_lcp_responsive_data_for_url( 'http://example.com/wp-content/uploads/hero.jpg' )
		);
		$this->assertSame(
			array(
				'srcset' => '',
				'sizes'  => '',
			),
			Image_Optimisation::get_lcp_responsive_data_for_url( '' )
		);
	}
}
