<?php
/**
 * Tests for lazy-load re-emission hardening (issue #1699).
 *
 * Verifies that attribute re-emission in the lazy-load rewriter neutralizes
 * stored-XSS payloads (event handlers, `javascript:` srcset) before they can
 * be baked into the static cache artefact, that parse failures return the
 * original tag unchanged (fail-open), and that the `wppo_lazyload_allow_attr`
 * filter can opt exotic markup back in.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Image_Optimisation;
use Brain\Monkey\Functions;

/**
 * Lazy-load re-emission hardening tests.
 */
class LazyReemissionHardeningTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Build an Image_Optimisation instance with JS-lazy rewriting on.
	 *
	 * @param array $overrides Option overrides for the image_optimisation group.
	 * @return Image_Optimisation
	 */
	private function make_image_optimisation( array $overrides = array() ): Image_Optimisation {
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'esc_attr' )->alias(
			static function ( $value ) {
				return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
			}
		);
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_attr__' )->returnArg( 1 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		return new Image_Optimisation(
			array(
				'image_optimisation' => array_merge(
					array(
						'lazyLoadImages'           => true,
						'lazyLoadNative'           => false,
						'lazyLoadVideos'           => true,
						'lazyLoadBackgroundImages' => true,
						'placeholderType'          => 'none',
						'hardenCommentImages'      => true,
					),
					$overrides
				),
			)
		);
	}

	/**
	 * Hostile img markup (onerror + javascript: srcset) renders neutralized
	 * through the full lazy buffer pass and is never moved into data-*.
	 */
	public function test_add_delay_load_img_neutralizes_onerror_and_javascript_srcset(): void {
		require_once __DIR__ . '/stubs/wp-html-api.php';

		$image_opt = $this->make_image_optimisation();
		$html      = '<div><img src="http://example.com/a.jpg" srcset="javascript:alert(1) 1x, http://example.com/a.jpg 2x" onerror="alert(1)" alt="x"></div>';
		$out       = $image_opt->add_delay_load_img( $html );

		$this->assertStringNotContainsStringIgnoringCase( 'onerror', $out );
		$this->assertStringNotContainsStringIgnoringCase( 'javascript:', $out );
		$this->assertStringNotContainsString( 'alert(1)', $out );
		$this->assertStringContainsString( 'alt="x"', $out );
	}

	/**
	 * A scriptable img src is stripped instead of laundered into data-src.
	 */
	public function test_process_img_tag_drops_scriptable_src_instead_of_moving(): void {
		require_once __DIR__ . '/stubs/wp-html-api.php';

		$image_opt = $this->make_image_optimisation();
		$tag       = '<img src="javascript:alert(1)" alt="x">';
		$out       = $image_opt->process_img_tag( $tag, 'javascript:alert(1)', array() );

		$this->assertStringNotContainsStringIgnoringCase( 'javascript:', $out );
		$this->assertStringNotContainsString( 'data-src="javascript', $out );
		$this->assertStringContainsString( 'alt="x"', $out );
	}

	/**
	 * A fully-hostile srcset is stripped instead of moved into data-srcset.
	 */
	public function test_process_img_tag_strips_fully_hostile_srcset(): void {
		require_once __DIR__ . '/stubs/wp-html-api.php';

		$image_opt = $this->make_image_optimisation();
		$tag       = '<img src="http://example.com/a.jpg" srcset="javascript:alert(1) 1x" alt="x">';
		$out       = $image_opt->process_img_tag( $tag, 'http://example.com/a.jpg', array() );

		$this->assertStringNotContainsStringIgnoringCase( 'javascript:', $out );
		$this->assertStringContainsString( 'alt="x"', $out );
	}

	/**
	 * A poisoned iframe src is neutralized instead of deferred into data-src.
	 */
	public function test_process_iframe_tag_neutralizes_scriptable_src(): void {
		require_once __DIR__ . '/stubs/wp-html-api.php';

		$image_opt = $this->make_image_optimisation();
		$tag       = '<iframe src="javascript:alert(1)" width="560"></iframe>';
		$out       = $image_opt->process_iframe_tag( $tag, 'javascript:alert(1)', array() );

		$this->assertStringNotContainsStringIgnoringCase( 'javascript:', $out );
		$this->assertStringNotContainsString( 'data-src="javascript', $out );
	}

	/**
	 * A scriptable video placeholder src never reaches data-wppo-video-src.
	 */
	public function test_generate_video_placeholder_rejects_scriptable_src(): void {
		require_once __DIR__ . '/stubs/wp-html-api.php';

		$image_opt = $this->make_image_optimisation();
		$method    = new ReflectionMethod( Image_Optimisation::class, 'generate_video_placeholder' );
		$out       = $method->invoke( $image_opt, '<iframe src="javascript:alert(1)"></iframe>', 'javascript:alert(1)', '' );

		$this->assertStringNotContainsString( 'data-wppo-video-src', $out );
		$this->assertStringNotContainsStringIgnoringCase( 'javascript:', $out );
	}

	/**
	 * A hostile background value is never deferred into data-wppo-bg,
	 * while a safe one still is.
	 *
	 * Fail-open by design: the hostile node keeps its original inline
	 * style untouched (author markup, not attacker re-emission — a
	 * `url(javascript:…)` in a style attribute does not execute script)
	 * and is simply skipped for deferral so the poisoned value can never
	 * be restored from the cached artefact.
	 */
	public function test_add_delay_load_backgrounds_skips_hostile_value(): void {
		require_once __DIR__ . '/stubs/wp-html-api.php';

		$image_opt = $this->make_image_optimisation();
		$hostile   = '<div style="background-image: url(javascript:alert(1))"><p>x</p></div>';
		$out       = $image_opt->add_delay_load_backgrounds( $hostile );

		$this->assertStringNotContainsString( 'data-wppo-bg', $out );
		$this->assertStringNotContainsString( 'wppo-lazy-bg', $out );

		$safe     = '<div style="background-image: url(http://example.com/bg.jpg); color: red"><p>x</p></div>';
		$safe_out = $image_opt->add_delay_load_backgrounds( $safe );

		$this->assertStringContainsString( 'data-wppo-bg', $safe_out );
	}

	/**
	 * A hostile video src is stripped instead of deferred into data-src.
	 */
	public function test_lazy_load_videos_neutralizes_scriptable_src(): void {
		require_once __DIR__ . '/stubs/wp-html-api.php';

		$image_opt = $this->make_image_optimisation();
		$out       = $image_opt->lazy_load_videos( '<video src="javascript:alert(1)"><source src="javascript:alert(2)"></video>' );

		$this->assertStringNotContainsStringIgnoringCase( 'javascript:', $out );
		$this->assertStringNotContainsString( 'data-src="javascript', $out );
	}

	/**
	 * Malformed input returns the original tag unchanged (fail-open).
	 */
	public function test_process_img_tag_returns_malformed_tag_unchanged(): void {
		require_once __DIR__ . '/stubs/wp-html-api.php';

		$image_opt = $this->make_image_optimisation();
		$this->assertSame( '<img', $image_opt->process_img_tag( '<img', 'x', array() ) );
	}

	/**
	 * The wppo_lazyload_allow_attr filter can opt exotic markup back in;
	 * without it the attribute stays stripped.
	 */
	public function test_allow_attr_filter_opts_exotic_handler_back_in(): void {
		require_once __DIR__ . '/stubs/wp-html-api.php';

		$image_opt = $this->make_image_optimisation();
		$html      = '<div><img src="http://example.com/a.jpg" onload="doThing()" alt="x"></div>';

		$denied = $image_opt->add_delay_load_img( $html );
		$this->assertStringNotContainsStringIgnoringCase( 'onload', $denied );

		// Opt-in: a filter returning true re-admits the attribute.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'wppo_lazyload_allow_attr' === $hook ) {
					return true;
				}
				return $value;
			}
		);
		Functions\when( 'has_filter' )->alias(
			static function ( $hook ) {
				return 'wppo_lazyload_allow_attr' === $hook;
			}
		);

		$allowed = $image_opt->add_delay_load_img( $html );
		$this->assertStringContainsString( 'onload="doThing()"', $allowed );
	}
}
