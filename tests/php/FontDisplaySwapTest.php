<?php
/**
 * Tests for font-display swap default, woff2-first ordering, and opt-in subsetting (issue #1145).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Google_Fonts;
use PerformanceOptimise\Inc\Minify\CSS;
use Brain\Monkey\Functions;

/**
 * Font-display / woff2-first / subsetting tests.
 *
 * @package PerformanceOptimise\Tests
 */
class FontDisplaySwapTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Build a Google_Fonts instance without constructor, with stubbed options.
	 *
	 * @param array $file_optimisation File optimisation settings.
	 * @return Google_Fonts
	 */
	private function make_fonts( array $file_optimisation = array() ): Google_Fonts {
		$instance = ( new \ReflectionClass( Google_Fonts::class ) )->newInstanceWithoutConstructor();
		$prop     = new \ReflectionProperty( Google_Fonts::class, 'options' );
		$prop->setAccessible( true );
		$prop->setValue( $instance, array( 'file_optimisation' => $file_optimisation ) );
		return $instance;
	}

	/**
	 * Swap is injected by default when the block has no font-display.
	 */
	public function test_inject_adds_swap_by_default(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$css    = "@font-face{font-family:'Inter';src:url('inter.woff2') format('woff2');}";
		$result = CSS::inject_font_display_swap( $css );
		$this->assertStringContainsString( 'font-display: swap', $result );
	}

	/**
	 * Falsy display disables injection (filter opt-out).
	 */
	public function test_inject_opt_out_returns_css_unchanged(): void {
		$css = "@font-face{font-family:'Inter';src:url('inter.woff2') format('woff2');}";
		$this->assertSame( $css, CSS::inject_font_display_swap( $css, false ) );
		$this->assertSame( $css, CSS::inject_font_display_swap( $css, '' ) );
	}

	/**
	 * Existing font-display: block is normalized to the configured value.
	 */
	public function test_inject_normalizes_block_to_configured_value(): void {
		$css     = "@font-face{font-family:'Inter';src:url('x.woff2');font-display: block;}";
		$swapped = CSS::inject_font_display_swap( $css );
		$this->assertStringContainsString( 'font-display: swap', $swapped );
		$this->assertStringNotContainsString( 'block', $swapped );

		$optional = CSS::inject_font_display_swap( $css, 'optional' );
		$this->assertStringContainsString( 'font-display: optional', $optional );
	}

	/**
	 * The display resolver defaults to swap and fails open on unknown values.
	 */
	public function test_get_font_display_defaults_and_validates(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$this->assertSame( 'swap', Google_Fonts::get_font_display() );

		Functions\when( 'apply_filters' )->justReturn( 'bogus-value' );
		$this->assertSame( 'swap', Google_Fonts::get_font_display() );

		Functions\when( 'apply_filters' )->justReturn( 'optional' );
		$this->assertSame( 'optional', Google_Fonts::get_font_display() );
	}

	/**
	 * The display resolver returns '' when the filter opts out (falsy).
	 */
	public function test_get_font_display_opt_out(): void {
		Functions\when( 'apply_filters' )->justReturn( false );
		$this->assertSame( '', Google_Fonts::get_font_display() );
	}

	/**
	 * WOFF2 sources are reordered first without dropping entries.
	 */
	public function test_order_font_sources_woff2_first(): void {
		$css       = "@font-face{font-family:'Inter';src:url('inter.woff') format('woff'),url('inter.woff2') format('woff2');}";
		$reordered = Google_Fonts::order_font_sources( $css );
		$woff2_pos = strpos( $reordered, '.woff2' );
		$woff_pos  = strpos( $reordered, ".woff'" );
		$this->assertNotFalse( $woff2_pos );
		$this->assertNotFalse( $woff_pos );
		$this->assertLessThan( $woff_pos, $woff2_pos );
		$this->assertStringContainsString( "format('woff')", $reordered );

		// Woff-only blocks are untouched.
		$single = "@font-face{font-family:'Inter';src:url('inter.woff') format('woff');}";
		$this->assertSame( $single, Google_Fonts::order_font_sources( $single ) );
	}

	/**
	 * Subsetting is off by default (fail-open, CSS unchanged).
	 */
	public function test_subset_off_by_default(): void {
		$css = "/* latin */@font-face{font-family:'Inter';src:url('a.woff2');}/* latin-ext */@font-face{font-family:'Inter';src:url('b.woff2');}";
		$this->assertSame( $css, $this->make_fonts()->maybe_subset_css( $css ) );
		$this->assertSame( $css, $this->make_fonts( array( 'fontSubset' => false ) )->maybe_subset_css( $css ) );
	}

	/**
	 * Subsetting keeps only the requested subsets when enabled.
	 */
	public function test_subset_keeps_requested_subsets(): void {
		$css      = "/* latin */@font-face{font-family:'Inter';src:url('a.woff2');}/* latin-ext */@font-face{font-family:'Inter';src:url('b.woff2');}";
		$filtered = $this->make_fonts(
			array(
				'fontSubset'        => true,
				'fontSubsetSubsets' => 'latin',
			)
		)->maybe_subset_css( $css );
		$this->assertStringContainsString( "url('a.woff2')", $filtered );
		$this->assertStringNotContainsString( "url('b.woff2')", $filtered );
	}
}
