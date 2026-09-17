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
	 * Style vectors, mixed srcset, data: payloads, entity obfuscation,
	 * srcdoc, and slash-separated handlers are all neutralized.
	 */
	public function test_extended_vectors_neutralized(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->returnArg( 2 );
		$_SERVER['HTTP_ACCEPT'] = 'image/webp';

		$image_opt = $this->make_image_optimisation();
		$cases     = array(
			'<img src="http://example.com/a.jpg" style="width:expression(alert(1))" alt="x">',
			'<img src="http://example.com/a.jpg" style="background:url(javascript:alert(1))" alt="x">',
			'<img src="http://example.com/a.jpg" style="behavior:url(x.htc)" alt="x">',
			'<img src="data:text/html,<svg onload=alert(1)>" alt="x">',
			'<img src="data:image/svg+xml,<svg onload=alert(1)>" alt="x">',
			'<img src="&#106;avascript:alert(1)" alt="x">',
			'<img src="javascript&colon;alert(1)" alt="x">',
			'<img src="java&#9;script:alert(1)" alt="x">',
			'<img/onerror="alert(1)" src="http://example.com/a.jpg" alt="x">',
			'<img src="http://example.com/a.jpg" srcset="javascript:alert(1) 1x, http://example.com/a.jpg 2x" alt="x">',
			'<img src="http://example.com/a.jpg" srcset=javascript:alert(1) alt="x">',
			'<img src="http://example.com/a.jpg" style=expression(alert(1)) alt="x">',
			'<svg onload="alert(1)"><circle cx="5" cy="5" r="4"/></svg>',
			'<audio src="http://example.com/a.mp3" onplay="alert(1)"></audio>',
			'<iframe src="javascript:alert(1)"></iframe>',
			'<iframe srcdoc="<svg onload=alert(1)>"></iframe>',
			'<img src="http://example.com/a.jpg" alt="a>b" onerror="alert(1)">',
		);
		foreach ( $cases as $html ) {
			$out = $image_opt->maybe_serve_next_gen_images( '<div>' . $html . '</div>' );
			$this->assertStringNotContainsStringIgnoringCase( 'onerror', $out, "Failed for: $html" );
			$this->assertStringNotContainsStringIgnoringCase( 'onload', $out, "Failed for: $html" );
			$this->assertStringNotContainsStringIgnoringCase( 'onplay', $out, "Failed for: $html" );
			$this->assertStringNotContainsString( 'javascript:alert', $out, "Failed for: $html" );
			$this->assertStringNotContainsString( 'expression(', $out, "Failed for: $html" );
			$this->assertStringNotContainsString( 'behavior:', $out, "Failed for: $html" );
			$this->assertStringNotContainsString( 'behaviour:', $out, "Failed for: $html" );
			$this->assertStringNotContainsString( 'srcdoc', $out, "Failed for: $html" );
			$this->assertStringNotContainsString( 'data:text/html', $out, "Failed for: $html" );
			$this->assertStringNotContainsString( 'data:image/svg', $out, "Failed for: $html" );
		}

		unset( $_SERVER['HTTP_ACCEPT'] );
	}

	/**
	 * Mixed hostile/safe srcset keeps the safe candidate, drops the hostile one.
	 */
	public function test_mixed_srcset_keeps_safe_candidate(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->returnArg( 2 );
		$_SERVER['HTTP_ACCEPT'] = 'image/webp';

		$image_opt = $this->make_image_optimisation();
		$html      = '<img src="http://example.com/a.jpg" srcset="javascript:alert(1) 1x, http://example.com/a.jpg 2x" alt="x">';
		$out       = $image_opt->maybe_serve_next_gen_images( '<div>' . $html . '</div>' );

		$this->assertStringNotContainsString( 'javascript:', $out );
		$this->assertStringContainsString( 'http://example.com/a.jpg 2x', $out );

		unset( $_SERVER['HTTP_ACCEPT'] );
	}

	/**
	 * Data-URI srcset commas survive the split (no corruption).
	 */
	public function test_data_uri_srcset_not_corrupted(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->returnArg( 2 );
		$_SERVER['HTTP_ACCEPT'] = 'image/webp';

		$image_opt = $this->make_image_optimisation();
		$html      = '<img src="http://example.com/a.jpg" srcset="data:image/png;base64,iVBORw0KGgo= 1x, http://example.com/a.jpg 2x" alt="x">';
		$out       = $image_opt->maybe_serve_next_gen_images( '<div>' . $html . '</div>' );

		$this->assertStringContainsString( 'data:image/png;base64,iVBORw0KGgo=', $out );

		unset( $_SERVER['HTTP_ACCEPT'] );
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
