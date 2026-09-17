<?php
/**
 * Tests for Util::strip_css_comments() (pure logic, no WordPress API).
 *
 * Covers quoted comment markers, escaped quotes, unterminated comments,
 * and normal comment removal. The scanner lives in Util as the single
 * shared home (issue #1347 review); the Used_CSS duplicate was removed so
 * comment handling can never drift from the sanitizer.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Util;

/**
 * Tests for the string-aware CSS comment stripper.
 *
 * @package PerformanceOptimise\Tests
 */
class UsedCssStripCommentsTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Invoke the shared strip_css_comments() helper.
	 *
	 * @param string $css Raw CSS.
	 * @return string CSS without comments.
	 */
	private function strip( string $css ): string {
		return Util::strip_css_comments( $css );
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

	/**
	 * Adjacent and multiple comments are all removed (scanner loop reset).
	 */
	public function test_removes_adjacent_comments(): void {
		$this->assertSame( 'a{}', $this->strip( '/*a*/a{}/*b*//*c*/' ) );
	}

	/**
	 * Empty input stays empty.
	 */
	public function test_empty_input_returns_empty(): void {
		$this->assertSame( '', $this->strip( '' ) );
	}
}
