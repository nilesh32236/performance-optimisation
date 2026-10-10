<?php
/**
 * Contract tests for the issue #1703 LCP hero emitter.
 *
 * Pins the `emit_lcp_preload()` single-emission contract: manual picker
 * precedence with both auto toggles off, toggle-off byte-identical empty
 * output, exactly-once emission per response, and the shared per-response
 * high-preload budget with `emit_responsive_lcp_preload()`.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Image_Optimisation;
use Brain\Monkey\Functions;

/**
 * Covers the emit_lcp_preload() entry point (issue #1703).
 */
class EmitLcpPreloadTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Pinned manual hero URL used across tests.
	 *
	 * @var string
	 */
	private const PINNED_URL = 'http://example.com/wp-content/uploads/pinned-hero.jpg';

	/**
	 * Install WP stubs for the emitter path.
	 *
	 * @param string $manual_url Manual picker value ('' for none).
	 * @return void
	 */
	private function install_stubs( string $manual_url ): void {
		Functions\when( 'is_singular' )->justReturn( '' !== $manual_url );
		Functions\when( 'get_the_ID' )->justReturn( '' !== $manual_url ? 42 : 0 );
		Functions\when( 'get_post_meta' )->alias(
			static function ( $post_id, $key, $single ) use ( $manual_url ) {
				unset( $post_id, $single );
				if ( '_wppo_lcp_preload_url' === $key ) {
					return $manual_url;
				}
				return '';
			}
		);
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
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );

		global $wp;
		$wp          = new \stdClass();
		$wp->request = 'current-page';

		$GLOBALS['od_url_metrics']  = array();
		$GLOBALS['od_metrics_stub'] = array();
		$GLOBALS['wp_version']      = '6.8';
		$_SERVER['REQUEST_URI']     = '/current-page/';

		Image_Optimisation::clear_runtime_caches();
	}

	/**
	 * Toggle-off with no manual pin emits nothing (byte-identical).
	 *
	 * @return void
	 */
	public function test_toggle_off_with_no_manual_pin_emits_empty(): void {
		$this->install_stubs( '' );

		$img = new Image_Optimisation( array() );
		$this->assertSame( '', $img->emit_lcp_preload() );
		$this->assertSame( '', $img->emit_lcp_preload( '<html><head></head><body></body></html>' ) );

		// Gate-first ordering: a buffer that already carries a high hint
		// still yields '' here (nothing to emit), without mutation.
		$with_high = '<html><head><link rel="preload" as="image" href="http://example.com/wp-content/uploads/other.jpg" fetchpriority="high"></head></html>';
		$this->assertSame( '', $img->emit_lcp_preload( $with_high ) );
	}

	/**
	 * Manual picker keeps precedence even when both auto toggles are off.
	 *
	 * @return void
	 */
	public function test_manual_pin_emits_with_toggles_off(): void {
		$this->install_stubs( self::PINNED_URL );

		$img = new Image_Optimisation( array() );
		$tag = $img->emit_lcp_preload();

		$this->assertStringContainsString( 'rel="preload"', $tag );
		$this->assertStringContainsString( 'as="image"', $tag );
		$this->assertStringContainsString( 'fetchpriority="high"', $tag );
		$this->assertStringContainsString( self::PINNED_URL, $tag );
		$this->assertSame( 1, substr_count( $tag, 'fetchpriority' ) );
	}

	/**
	 * Exactly one high preload per response; repeats degrade to empty.
	 *
	 * @return void
	 */
	public function test_emit_lcp_preload_emits_exactly_once(): void {
		$this->install_stubs( self::PINNED_URL );

		$img    = new Image_Optimisation( array() );
		$first  = $img->emit_lcp_preload();
		$second = $img->emit_lcp_preload();

		$this->assertStringContainsString( 'fetchpriority="high"', $first );
		$this->assertSame( '', $second );
		$this->assertSame( 1, substr_count( $first . $second, 'fetchpriority="high"' ) );
	}

	/**
	 * The two emitters share one per-response high-preload budget.
	 *
	 * @return void
	 */
	public function test_shares_single_high_budget_with_responsive_emitter(): void {
		$this->install_stubs( self::PINNED_URL );

		$img   = new Image_Optimisation( array() );
		$first = $img->emit_lcp_preload();
		$this->assertStringContainsString( 'fetchpriority="high"', $first );

		// Whichever emitter runs second in the same response is suppressed.
		$this->assertSame( '', $img->emit_responsive_lcp_preload() );
	}
}
