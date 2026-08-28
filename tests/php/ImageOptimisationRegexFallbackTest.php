<?php
/**
 * Regression tests for H-01 regex fallback iframe invariant.
 *
 * The fallback preg_replace_callback uses 4 capture groups:
 *  - groups 1,2 for <img> (attrs, src)
 *  - groups 3,4 for <iframe> (attrs, src)
 * Under PREG_UNMATCHED_AS_NULL (via Patchwork/BrainMonkey) count($matches) is always 5,
 * so the old guard `5 === count($matches)` always routed to process_iframe_tag,
 * corrupting <img> tags. The fix checks `isset($matches[4]) && '' !== $matches[4]`.
 *
 * These tests exercise the regex directly and the Image_Optimisation fallback path
 * without WP_HTML_Tag_Processor (to force the preg path).
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */

use PerformanceOptimise\Inc\Image_Optimisation;
use Brain\Monkey\Functions;

/**
 * Tests H-01 invariant fix.
 */
class ImageOptimisationRegexFallbackTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * The union regex used in class-image-optimisation.php fallback.
	 *
	 * @var string
	 */
	private const UNION_REGEX = '#<picture\b[^>]*>.*?</picture>|<img\b([^>]*?)src=["\']([^"\']+)["\'][^>]*>|<iframe\b([^>]*?)src=["\']([^"\']+)["\'][^>]*>#is';

	/**
	 * Test that <img> match does NOT trigger iframe branch (old count==5 would).
	 */
	public function test_img_match_does_not_route_to_iframe(): void {
		$html = '<img src="https://example.com/a.jpg" alt="x">';
		preg_match( self::UNION_REGEX, $html, $matches );
		// PREG_UNMATCHED_AS_NULL not guaranteed, but count is 5 when all groups present null.
		// The invariant: matches[4] must be absent/null for img, present for iframe.
		$this->assertArrayHasKey( 2, $matches );
		$this->assertSame( 'https://example.com/a.jpg', $matches[2] );
		// For img, group 4 should be null or empty or not set.
		$has_iframe_src = isset( $matches[4] ) && '' !== $matches[4];
		$this->assertFalse( $has_iframe_src, 'img must not be routed to iframe branch' );
		// Old buggy check would be count==5, which would be true even for img under PREG_UNMATCHED_AS_NULL.
		// Demonstrate: if PREG_UNMATCHED_AS_NULL, count is 5, so old check incorrectly true.
		// We assert new fix correctly false regardless.
	}

	/**
	 * Test that <iframe> match DOES route to iframe branch.
	 */
	public function test_iframe_match_routes_to_iframe(): void {
		$html = '<iframe src="https://example.com/embed" width="560"></iframe>';
		preg_match( self::UNION_REGEX, $html, $matches );
		$this->assertTrue( isset( $matches[4] ) && '' !== $matches[4], 'iframe must route to iframe branch' );
		$this->assertSame( 'https://example.com/embed', $matches[4] );
		// img src group 2 should be empty/null for iframe.
		$has_img_src = isset( $matches[2] ) && '' !== $matches[2];
		$this->assertFalse( $has_img_src, 'iframe must not have img src' );
	}

	/**
	 * Test that mixed content is correctly split (img then iframe).
	 */
	public function test_mixed_content_split(): void {
		$html = '<img src="https://example.com/a.jpg"><iframe src="https://example.com/b.html"></iframe><img src="https://example.com/c.jpg">';
		preg_match_all( self::UNION_REGEX, $html, $all, PREG_SET_ORDER );
		$this->assertCount( 3, $all );
		$this->assertTrue( isset( $all[0][2] ) && '' !== $all[0][2] );
		$this->assertFalse( isset( $all[0][4] ) && '' !== $all[0][4] );
		$this->assertTrue( isset( $all[1][4] ) && '' !== $all[1][4] );
		$this->assertTrue( isset( $all[2][2] ) && '' !== $all[2][2] );
		$this->assertSame( 'https://example.com/a.jpg', $all[0][2] );
		$this->assertSame( 'https://example.com/b.html', $all[1][4] );
		$this->assertSame( 'https://example.com/c.jpg', $all[2][2] );
	}

	/**
	 * Test that the fallback path correctly lazy-loads iframe via data-src without corrupting img.
	 *
	 * This forces the regex path by ensuring WP_HTML_Tag_Processor is not used.
	 * Since the stub defines the class process-wide, we instead directly invoke
	 * the private fallback logic via reflection of process_iframe_tag / process_picture_tag
	 * and simulate the preg_replace_callback dispatch.
	 */
	public function test_fallback_dispatch_preserves_img_and_defers_iframe(): void {
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'esc_attr' )->returnArg();

		$options = array(
			'image_optimisation' => array(
				'lazyLoadImages'  => true,
				'lazyLoadVideos'  => false,
				'placeholderType' => 'none',
				'lazyLoadNative'  => false,
			),
		);
		$image_opt = new Image_Optimisation( $options );

		// Directly test process_iframe_tag vs process_picture_tag invocation via simulated callback.
		$img_html    = '<img src="https://example.com/a.jpg" alt="x">';
		$iframe_html = '<iframe src="https://example.com/embed" width="560" height="315"></iframe>';

		// Simulate what the buggy code did: 5===count($matches) would treat img as iframe.
		preg_match( self::UNION_REGEX, $img_html, $m_img );
		preg_match( self::UNION_REGEX, $iframe_html, $m_iframe );

		// Buggy condition: would be true for img when PREG_UNMATCHED_AS_NULL.
		$buggy_img_is_iframe = ( 5 === count( $m_img ) );
		// Correct condition:
		$correct_img_is_iframe = isset( $m_img[4] ) && '' !== $m_img[4];
		$correct_iframe_is_iframe = isset( $m_iframe[4] ) && '' !== $m_iframe[4];

		$this->assertFalse( $correct_img_is_iframe, 'Correct guard must NOT treat img as iframe' );
		$this->assertTrue( $correct_iframe_is_iframe, 'Correct guard must treat iframe as iframe' );

		// Additionally verify via reflection that process_iframe_tag does data-src deferral.
		$ref = new \ReflectionMethod( Image_Optimisation::class, 'process_iframe_tag' );
		$ref->setAccessible( true );
		$iframe_result = $ref->invoke( $image_opt, $iframe_html, 'https://example.com/embed', array() );
		$this->assertStringContainsString( 'data-src="https://example.com/embed"', $iframe_result );
		$this->assertStringNotContainsString( ' src="https://example.com/embed"', $iframe_result );

		// And process_picture_tag (via process_img_tag) for img should defer via data-src in native-off mode.
		$ref2 = new \ReflectionMethod( Image_Optimisation::class, 'process_img_tag' );
		$ref2->setAccessible( true );
		$img_result = $ref2->invoke( $image_opt, $img_html, 'https://example.com/a.jpg', array() );
		$this->assertStringContainsString( 'data-src="https://example.com/a.jpg"', $img_result );
	}
}
