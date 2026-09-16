<?php
/**
 * Coverage for automatic LCP hero preload + automatic font discovery (#1216).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Image_Optimisation;
use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests extract_font_urls_from_css ordering, font URL resolution,
 * queue-only manual-wins capped discovery, srcset media gaplessness,
 * and the RUM gate for the additive auto-LCP toggle.
 *
 * @package PerformanceOptimise\Tests
 */
class AutoLcpFontDiscoveryTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Build a Main instance without invoking the constructor.
	 *
	 * @param array $preload_settings preload_settings option value.
	 * @return Main
	 */
	private function make_main( array $preload_settings ): Main {
		$reflection = new \ReflectionClass( Main::class );
		$main       = $reflection->newInstanceWithoutConstructor();
		$options    = $reflection->getProperty( 'options' );
		$options->setAccessible( true );
		$options->setValue( $main, array( 'preload_settings' => $preload_settings ) );
		return $main;
	}

	/**
	 * Install the WP function stubs shared by these tests.
	 */
	private function install_stubs(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'http://example.com' . (string) $path;
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return \json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test stub backing wp_json_encode().
			}
		);
		// Order-independent: the strict same-origin guard parses URLs, so
		// pin wp_parse_url explicitly (a leaked stub from an earlier test
		// in the same process could otherwise return null).
		// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test-only wp_parse_url stub.
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		// phpcs:enable WordPress.WP.AlternativeFunctions.parse_url_parse_url
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $url ) {
				return is_string( $url ) ? rtrim( $url, '/' ) : $url;
			}
		);
		Functions\stubs(
			array(
				'get_transient',
				'set_transient',
				'add_action',
			)
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
	}

	/**
	 * Woff2 is preferred within a block but never reordered across families.
	 */
	public function test_extract_prefers_woff2_per_block_preserving_family_order(): void {
		$css = "@font-face{font-family:'Primary';src:url('https://example.com/fonts/primary.woff') format('woff'),url('https://example.com/fonts/primary.woff2') format('woff2');}"
			. "@font-face{font-family:'Secondary';src:url('https://example.com/fonts/secondary.woff2') format('woff2');}";

		$result = Main::extract_font_urls_from_css( $css );

		$this->assertSame(
			array(
				'https://example.com/fonts/primary.woff2',
				'https://example.com/fonts/primary.woff',
				'https://example.com/fonts/secondary.woff2',
			),
			$result
		);
	}

	/**
	 * The primary family's woff must not be displaced by a secondary woff2.
	 */
	public function test_extract_keeps_primary_woff_before_secondary_woff2(): void {
		$css = "@font-face{font-family:'Primary';src:url('https://example.com/fonts/primary.woff') format('woff');}"
			. "@font-face{font-family:'Secondary';src:url('https://example.com/fonts/secondary.woff2') format('woff2');}";

		$result = Main::extract_font_urls_from_css( $css );

		$this->assertSame(
			array(
				'https://example.com/fonts/primary.woff',
				'https://example.com/fonts/secondary.woff2',
			),
			$result
		);
	}

	/**
	 * Data/blob URIs and non-font extensions are dropped.
	 */
	public function test_extract_filters_schemes_and_extensions(): void {
		$css = "@font-face{src:url('data:font/woff2;base64,AAA') format('woff2'),"
			. "url('https://example.com/fonts/ok.woff2') format('woff2'),"
			. "url('https://example.com/img/logo.png');}";

		$result = Main::extract_font_urls_from_css( $css );

		$this->assertSame( array( 'https://example.com/fonts/ok.woff2' ), $result );
	}

	/**
	 * Root-relative refs resolve against home_url, stylesheet-relative
	 * refs against the enclosing stylesheet directory.
	 */
	public function test_resolve_font_url_uses_home_and_stylesheet_base(): void {
		$this->install_stubs();
		$main   = $this->make_main( array( 'autoDiscoverFonts' => true ) );
		$method = new \ReflectionMethod( Main::class, 'resolve_font_url' );
		$method->setAccessible( true );

		$this->assertSame(
			'http://example.com/fonts/root.woff2',
			$method->invoke( $main, '/fonts/root.woff2', '' )
		);
		$this->assertSame(
			'http://example.com/wp-content/themes/t/fonts/rel.woff2',
			$method->invoke( $main, '../fonts/rel.woff2', 'http://example.com/wp-content/themes/t/css/style.css' )
		);
		$this->assertSame(
			'https://example.com/fonts/abs.woff2',
			$method->invoke( $main, 'https://example.com/fonts/abs.woff2', 'http://example.com/x.css' )
		);
	}

	/**
	 * Seed $GLOBALS['wp_styles'] with one queued and one registered-only handle.
	 */
	private function seed_wp_styles(): void {
		$queued                = new \stdClass();
		$queued->src           = '';
		$queued->ver           = '1';
		$queued->extra         = array(
			'before' => array( "@font-face{src:url('http://example.com/fonts/queued.woff2');}" ),
		);
		$orphan                = new \stdClass();
		$orphan->src           = '';
		$orphan->ver           = '1';
		$orphan->extra         = array(
			'before' => array( "@font-face{src:url('http://example.com/fonts/orphan.woff2');}" ),
		);
		$wp_styles             = new \stdClass();
		$wp_styles->queue      = array( 'queued' );
		$wp_styles->registered = array(
			'queued' => $queued,
			'orphan' => $orphan,
		);
		$GLOBALS['wp_styles']  = $wp_styles;
	}

	/**
	 * Discovery scans only queued handles (registered-only CSS is ignored).
	 */
	public function test_auto_discovery_is_queue_only(): void {
		$this->install_stubs();
		$this->seed_wp_styles();
		try {
			$main   = $this->make_main( array( 'autoDiscoverFonts' => true ) );
			$result = $main->get_auto_discovered_font_urls( array() );
			$this->assertSame( array( 'http://example.com/fonts/queued.woff2' ), $result );
		} finally {
			unset( $GLOBALS['wp_styles'] );
		}
	}

	/**
	 * Manual URLs win on conflict and the result is capped at two.
	 */
	public function test_auto_discovery_manual_wins_and_caps_at_two(): void {
		$this->install_stubs();
		$queued                = new \stdClass();
		$queued->src           = '';
		$queued->ver           = '1';
		$queued->extra         = array(
			'before' => array(
				"@font-face{src:url('http://example.com/fonts/a.woff2');}"
				. "@font-face{src:url('http://example.com/fonts/b.woff2');}"
				. "@font-face{src:url('http://example.com/fonts/c.woff2');}",
			),
		);
		$wp_styles             = new \stdClass();
		$wp_styles->queue      = array( 'queued' );
		$wp_styles->registered = array( 'queued' => $queued );
		$GLOBALS['wp_styles']  = $wp_styles;
		try {
			$main   = $this->make_main( array( 'autoDiscoverFonts' => true ) );
			$result = $main->get_auto_discovered_font_urls( array( 'http://example.com/fonts/a.woff2' ) );
			$this->assertSame(
				array(
					'http://example.com/fonts/b.woff2',
					'http://example.com/fonts/c.woff2',
				),
				$result
			);
		} finally {
			unset( $GLOBALS['wp_styles'] );
		}
	}

	/**
	 * Cross-origin @font-face URLs are dropped by discovery (issue #1216).
	 */
	public function test_auto_discovery_drops_cross_origin_fonts(): void {
		$this->install_stubs();
		$queued                = new \stdClass();
		$queued->src           = '';
		$queued->ver           = '1';
		$queued->extra         = array(
			'before' => array(
				"@font-face{src:url('http://cdn.evil/fonts/evil.woff2');}"
				. "@font-face{src:url('http://example.com/fonts/ok.woff2');}",
			),
		);
		$wp_styles             = new \stdClass();
		$wp_styles->queue      = array( 'queued' );
		$wp_styles->registered = array( 'queued' => $queued );
		$GLOBALS['wp_styles']  = $wp_styles;
		try {
			$main   = $this->make_main( array( 'autoDiscoverFonts' => true ) );
			$result = $main->get_auto_discovered_font_urls( array() );
			$this->assertSame( array( 'http://example.com/fonts/ok.woff2' ), $result );
		} finally {
			unset( $GLOBALS['wp_styles'] );
		}
	}

	/**
	 * Versioned font URLs (?v=1 vs ?v=2) are distinct dedup keys (issue #1216).
	 */
	public function test_normalize_font_url_keeps_query_distinct(): void {
		$this->install_stubs();
		$main   = $this->make_main( array( 'autoDiscoverFonts' => true ) );
		$method = new \ReflectionMethod( Main::class, 'normalize_font_url' );
		$method->setAccessible( true );

		$first  = $method->invoke( $main, 'http://example.com/fonts/a.woff2?v=1' );
		$second = $method->invoke( $main, 'http://example.com/fonts/a.woff2?v=2' );

		$this->assertNotSame( $first, $second );
		$this->assertSame( $first, $method->invoke( $main, 'http://example.com/fonts/a.woff2?v=1' ) );
	}

	/**
	 * The strict same-origin helper proves origin; unverifiable verdicts are false.
	 */
	public function test_strict_same_origin_url_proves_origin(): void {
		$this->install_stubs();
		$this->assertTrue( \PerformanceOptimise\Inc\RUM::is_same_origin_url_strict( 'http://example.com/fonts/a.woff2' ) );
		$this->assertTrue( \PerformanceOptimise\Inc\RUM::is_same_origin_url_strict( '/fonts/a.woff2' ) );
		$this->assertFalse( \PerformanceOptimise\Inc\RUM::is_same_origin_url_strict( 'http://cdn.evil/fonts/a.woff2' ) );
		$this->assertFalse( \PerformanceOptimise\Inc\RUM::is_same_origin_url_strict( 'javascript:alert(1)' ) );
	}

	/**
	 * String 'false' for the new toggles sanitizes to bool false (issue #1216).
	 */
	public function test_sanitize_normalizes_auto_toggles_to_bool(): void {
		$sanitized = Util::sanitize_settings_recursively(
			array(
				'preload_settings' => array(
					'autoLcpPreload'    => 'false',
					'autoDiscoverFonts' => 'true',
				),
			)
		);

		$this->assertFalse( $sanitized['preload_settings']['autoLcpPreload'] );
		$this->assertTrue( $sanitized['preload_settings']['autoDiscoverFonts'] );
	}

	/**
	 * The per-instance LCP memo reset clears memoized URLs (issue #1216).
	 */
	public function test_clear_instance_lcp_memo_resets_memos(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		$image_opt = new Image_Optimisation( array( 'image_optimisation' => array() ) );

		$this->assertTrue( method_exists( $image_opt, 'clear_instance_lcp_memo' ) );
		$image_opt->clear_instance_lcp_memo();

		$prop = new \ReflectionProperty( Image_Optimisation::class, 'current_lcp_url' );
		$prop->setAccessible( true );
		$this->assertNull( $prop->getValue( $image_opt ) );
	}
	public function test_srcset_slice_keeps_gapless_media(): void {
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		$image_opt = new Image_Optimisation(
			array(
				'image_optimisation' => array( 'maxWidthImgSize' => 5000 ),
			)
		);
		$method    = new \ReflectionMethod( Image_Optimisation::class, 'get_srcset_preload_items' );
		$method->setAccessible( true );
		$srcset = 'http://example.com/a-400.jpg 400w, http://example.com/a-800.jpg 800w, http://example.com/a-1200.jpg 1200w';
		$items  = $method->invoke( $image_opt, $srcset, 'http://example.com/a.jpg', array( 'maxWidthImgSize' => 5000 ) );

		$this->assertCount( 2, $items );
		$this->assertSame( '(min-width: 0px) and (max-width: 400px)', $items[0]['media'] );
		$this->assertSame( '(min-width: 401px)', $items[1]['media'] );
	}

	/**
	 * The RUM gate passes only when RUM collection is enabled.
	 */
	public function test_auto_lcp_rum_gate(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		$image_opt = new Image_Optimisation( array( 'image_optimisation' => array() ) );
		$method    = new \ReflectionMethod( Image_Optimisation::class, 'is_auto_lcp_rum_satisfied' );
		$method->setAccessible( true );

		Util::set_settings_cache( array( 'performance_audit' => array( 'rum_enabled' => false ) ) );
		$this->assertFalse( $method->invoke( $image_opt ) );

		Util::set_settings_cache( array( 'performance_audit' => array( 'rum_enabled' => true ) ) );
		$this->assertTrue( $method->invoke( $image_opt ) );
	}
}
