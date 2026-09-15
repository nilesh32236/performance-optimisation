<?php
/**
 * Tests for Used_CSS::strip_css_comments() (pure logic, no WordPress API).
 *
 * Covers quoted comment markers, escaped quotes, unterminated comments,
 * and normal comment removal.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Used_CSS;

/**
 * Tests for the string-aware CSS comment stripper.
 *
 * @package PerformanceOptimise\Tests
 */
class UsedCssStripCommentsTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Invoke the private strip_css_comments() helper.
	 *
	 * @param string $css Raw CSS.
	 * @return string CSS without comments.
	 */
	private function strip( string $css ): string {
		$method = new \ReflectionMethod( Used_CSS::class, 'strip_css_comments' );
		$method->setAccessible( true );
		return $method->invoke( null, $css );
	}

	/**
	 * Plain comments are removed.
	 */
	public function test_removes_plain_comment(): void {
		$this->assertSame( 'a{}b{}', $this->strip( 'a{}/* comment */b{}' ) );
	}

	/**
	 * Comment markers inside strings are preserved (content:"/* ... *\/").
	 */
	public function test_preserves_comment_markers_inside_strings(): void {
		$css = '.x{content:"/* not a comment */";}';
		$this->assertSame( $css, $this->strip( $css ) );
	}

	/**
	 * Single-quoted strings are preserved too (content:'/* ... *\/').
	 */
	public function test_preserves_comment_markers_inside_single_quoted_strings(): void {
		$css = ".x{content:'/* not a comment */';}";
		$this->assertSame( $css, $this->strip( $css ) );
	}

	/**
	 * Escaped quotes inside strings do not end the string early.
	 */
	public function test_handles_escaped_quotes(): void {
		$css = '.x{content:"a\\"/*x*/";}/*c*/';
		$this->assertSame( '.x{content:"a\\"/*x*/";}', $this->strip( $css ) );
	}

	/**
	 * Unterminated comments are dropped (fail-open, spec-correct).
	 */
	public function test_drops_unterminated_comment(): void {
		$this->assertSame( 'a{}', $this->strip( 'a{}/* never closed' ) );
	}
}
