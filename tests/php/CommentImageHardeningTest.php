<?php
/**
 * Tests for comment-image hardening in next-gen + lazy rewriting (issue #1271).
 *
 * Verifies hostile comment-authored markup (img onerror, picture source,
 * inline on* handlers, scriptable URLs) renders inert with WebP/AVIF + lazy
 * on, and that gallery/picture fixtures suffer no layout regression.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Image_Optimisation;
use Brain\Monkey\Functions;

/**
 * Comment image hardening tests.
 */
class CommentImageHardeningTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Build an Image_Optimisation instance with next-gen + JS-lazy on.
	 *
	 * @return Image_Optimisation
	 */
	private function make_image_optimisation(): Image_Optimisation {
		Functions\when( 'path_is_absolute' )->justReturn( false );
		Functions\when( 'esc_attr' )->alias(
			static function ( $value ) {
				return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
			}
		);
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => '/tmp/wordpress/wp-content/uploads',
				'baseurl' => 'http://example.com/wp-content/uploads',
			)
		);
		Functions\when( 'wp_get_upload_dir' )->justReturn(
			array(
				'basedir' => '/tmp/wordpress/wp-content/uploads',
				'baseurl' => 'http://example.com/wp-content/uploads',
			)
		);
		return new Image_Optimisation(
			array(
				'image_optimisation' => array(
					'convertImg'          => true,
					'conversionFormat'    => 'webp',
					'lazyLoadImages'      => true,
					'lazyLoadNative'      => false,
					'placeholderType'     => 'none',
					'hardenCommentImages' => true,
				),
			)
		);
	}

	/**
	 * Hostile img onerror payload renders inert through next-gen rewriting.
	 */
	public function test_next_gen_strips_onerror_handler(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$_SERVER['HTTP_ACCEPT'] = 'image/webp';

		$image_opt = $this->make_image_optimisation();
		$html      = '<div class="comment-content"><img src="http://example.com/a.jpg" onerror="alert(1)" onload="alert(2)" alt="x"></div>';
		$out       = $image_opt->maybe_serve_next_gen_images( $html );

		$this->assertStringNotContainsStringIgnoringCase( 'onerror', $out );
		$this->assertStringNotContainsStringIgnoringCase( 'onload', $out );
		$this->assertStringNotContainsString( 'alert(1)', $out );
		$this->assertStringContainsString( 'alt="x"', $out );

		unset( $_SERVER['HTTP_ACCEPT'] );
	}

	/**
	 * Hostile picture/source payload renders inert through lazy rewriting.
	 */
	public function test_lazy_strips_handlers_from_picture_source(): void {
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'esc_attr' )->alias(
			static function ( $value ) {
				return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
			}
		);
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);

		$image_opt = $this->make_image_optimisation();
		$html      = '<div class="comment-content"><picture><source srcset="http://example.com/a.jpg 1x" onerror="alert(1)"><img src="http://example.com/a.jpg" onclick="alert(2)" alt="pic"></picture></div>';
		$out       = $image_opt->add_delay_load_img( $html );

		$this->assertStringNotContainsStringIgnoringCase( 'onerror', $out );
		$this->assertStringNotContainsStringIgnoringCase( 'onclick', $out );
		$this->assertStringNotContainsString( 'alert(', $out );
		$this->assertStringContainsString( '<picture>', $out );
		$this->assertStringContainsString( 'alt="pic"', $out );
	}

	/**
	 * Scriptable src URLs are neutralized, never rewritten to next-gen.
	 */
	public function test_scriptable_src_never_rewritten(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$_SERVER['HTTP_ACCEPT'] = 'image/webp';

		$image_opt = $this->make_image_optimisation();
		$html      = '<div class="comment-content"><img src="javascript:alert(1)" alt="x"></div>';
		$out       = $image_opt->maybe_serve_next_gen_images( $html );

		$this->assertStringNotContainsString( 'javascript:alert', $out );
		$this->assertStringNotContainsString( '.webp', $out );

		unset( $_SERVER['HTTP_ACCEPT'] );
	}

	/**
	 * Gallery + picture fixtures keep layout attributes (no regression).
	 */
	public function test_gallery_fixture_no_regression(): void {
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);

		$image_opt = $this->make_image_optimisation();
		$html      = '<figure class="wp-block-gallery"><picture><source srcset="http://example.com/g-300.jpg 300w, http://example.com/g-1024.jpg 1024w" type="image/jpeg"><img src="http://example.com/g-1024.jpg" srcset="http://example.com/g-300.jpg 300w, http://example.com/g-1024.jpg 1024w" sizes="(max-width: 1024px) 100vw, 1024px" width="1024" height="768" alt="gallery"></picture></figure>';
		$out       = $image_opt->add_delay_load_img( $html );

		$this->assertStringContainsString( '<picture>', $out );
		$this->assertStringContainsString( 'width="1024"', $out );
		$this->assertStringContainsString( 'height="768"', $out );
		$this->assertStringContainsString( 'alt="gallery"', $out );
		$this->assertStringContainsString( 'data-src', $out );
	}

	/**
	 * Explicit opt-out restores the legacy byte-identical path.
	 */
	public function test_hardening_opt_out_preserves_handlers(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'path_is_absolute' )->justReturn( false );
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => '/tmp/wordpress/wp-content/uploads',
				'baseurl' => 'http://example.com/wp-content/uploads',
			)
		);
		$_SERVER['HTTP_ACCEPT'] = 'image/webp';

		$image_opt = new Image_Optimisation(
			array(
				'image_optimisation' => array(
					'convertImg'          => true,
					'conversionFormat'    => 'webp',
					'hardenCommentImages' => false,
				),
			)
		);
		$html      = '<div class="comment-content"><img src="http://example.com/a.jpg" onerror="alert(1)" alt="x"></div>';
		$out       = $image_opt->maybe_serve_next_gen_images( $html );

		// Opt-out: hardening gate off, legacy rewrite preserves input markup.
		$this->assertStringContainsString( 'onerror', $out );

		unset( $_SERVER['HTTP_ACCEPT'] );
	}
}
