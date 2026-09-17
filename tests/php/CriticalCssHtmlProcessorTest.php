<?php
/**
 * Tests for the Critical-CSS HTML API streaming extractor (issue #1430).
 *
 * Covers Critical_CSS::extract_css_sources_with_processor(): parity with the
 * DOMDocument discovery block in generate() on a malformed-HTML fixture
 * suite (missing closers, SVG, nested tables), mid-walk deadline polls,
 * byte-cap handling, skip-handle filtering, rel exact-match parity,
 * entity-decoded style bodies, and the Util capability probe.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Critical_CSS;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests for the Critical-CSS streaming CSS-source extractor.
 *
 * @package PerformanceOptimise\Tests
 */
class CriticalCssHtmlProcessorTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Set up Brain Monkey and the HTML API stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/stubs/wp-html-api.php';
		Util::reset_html_processor_memo();
		Functions\when( 'is_wp_error' )->justReturn( false );
	}

	/**
	 * Invoke the private streaming extractor.
	 *
	 * @param string     $html     Page HTML.
	 * @param float|null $deadline Absolute deadline, or null when uncapped.
	 * @return array|null Extractor result.
	 */
	private function extract( string $html, ?float $deadline = null ): ?array {
		$method = new \ReflectionMethod( Critical_CSS::class, 'extract_css_sources_with_processor' );

		return $method->invoke( null, $html, $deadline );
	}

	/**
	 * Replicate the DOMDocument discovery block from generate().
	 *
	 * Serves as the parity oracle: style bodies trimmed and concatenated,
	 * exact `rel="stylesheet"` links in document order with skip handles
	 * removed.
	 *
	 * @param string $html Page HTML.
	 * @return array{inline_css: string, source_urls: string[]} DOM-path result.
	 */
	private function extract_via_dom( string $html ): array {
		$prev = libxml_use_internal_errors( true );
		try {
			$dom = new \DOMDocument();
			$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $prev );
		}
		$xpath = new \DOMXPath( $dom );

		$inline = '';
		$styles = $xpath->query( '//style' );
		if ( $styles ) {
			foreach ( $styles as $tag ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode property.
				$content = trim( $tag->textContent );
				if ( '' !== $content ) {
					$inline .= $content . "\n";
				}
			}
		}

		$urls  = array();
		$links = $xpath->query( '//link[@rel="stylesheet"]' );
		if ( $links ) {
			foreach ( $links as $tag ) {
				$href = $tag->getAttribute( 'href' );
				if ( '' === $href ) {
					continue;
				}
				foreach ( array( 'wppo-combine-css', 'dashicons', 'admin-bar', 'wp-block-library', 'wc-block-style' ) as $handle ) {
					if ( false !== strpos( $href, $handle ) ) {
						continue 2;
					}
				}
				$urls[] = $href;
			}
		}

		return array(
			'inline_css'  => $inline,
			'source_urls' => $urls,
		);
	}

	/**
	 * Fixture suite shared by the parity test.
	 *
	 * @return array<string, string> Fixture name => HTML.
	 */
	private function parity_fixtures(): array {
		return array(
			'well-formed'     => '<html><head><style>body{color:red}</style>'
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- HTML fixture string.
				. '<link rel="stylesheet" href="http://example.com/a.css">'
				. '<style>h1{color:blue}</style>'
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- HTML fixture string.
				. '</head><body><p>hi</p></body></html>',
			'missing-closers' => '<div><table><tr><td><style>.cell{color:red}</style>'
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- HTML fixture string.
				. '<link rel="stylesheet" href="http://example.com/cell.css"><p>unclosed',
			'svg'             => '<div><svg viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg>'
				. '<style>.shape{fill:red}</style>'
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- HTML fixture string.
				. '<link rel="stylesheet" href="http://example.com/shape.css"></div>',
			'nested-tables'   => '<table><tr><td><table><tr><td><style>.nested{margin:0}</style></td></tr></table>'
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- HTML fixture string.
				. '<link rel="stylesheet" href="http://example.com/one.css">'
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- HTML fixture string.
				. '<link rel="stylesheet" href="http://example.com/two.css">',
		);
	}

	/**
	 * The streaming walk matches the DOM path on every parity fixture.
	 *
	 * @return void
	 */
	public function test_extraction_matches_dom_path_on_fixture_suite(): void {
		foreach ( $this->parity_fixtures() as $name => $html ) {
			$expected = $this->extract_via_dom( $html );
			$actual   = $this->extract( $html, microtime( true ) + 30 );

			$this->assertNotNull( $actual, "Extractor fell back to DOM on fixture: {$name}" );
			$this->assertSame( $expected['inline_css'], $actual['inline_css'], "Inline CSS mismatch on fixture: {$name}" );
			$this->assertSame( $expected['source_urls'], $actual['source_urls'], "Source URL mismatch on fixture: {$name}" );
		}
	}

	/**
	 * Non-stylesheet links and skip handles never enter the source set.
	 *
	 * @return void
	 */
	public function test_extraction_skips_preload_and_skip_handles(): void {
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- HTML fixture string.
		$html = '<link rel="preload" as="style" href="http://example.com/skip-preload.css">'
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- HTML fixture string.
			. '<link rel="stylesheet" href="http://example.com/dashicons.min.css">'
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- HTML fixture string.
			. '<link rel="stylesheet" href="http://example.com/admin-bar.css">'
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- HTML fixture string.
			. '<link rel="stylesheet" href="http://example.com/theme.css">';

		$actual = $this->extract( $html, microtime( true ) + 30 );

		$this->assertNotNull( $actual );
		$this->assertSame( array( 'http://example.com/theme.css' ), $actual['source_urls'] );
		$this->assertSame( '', $actual['inline_css'] );
	}

	/**
	 * An unterminated style block still yields its body (DOM auto-closes).
	 *
	 * @return void
	 */
	public function test_extraction_flushes_unterminated_style_block(): void {
		$actual = $this->extract( '<div><style>body{color:red}', microtime( true ) + 30 );

		$this->assertNotNull( $actual );
		$this->assertSame( "body{color:red}\n", $actual['inline_css'] );
	}

	/**
	 * An expired deadline aborts the walk fail-open (null = DOM path returns false).
	 *
	 * @return void
	 */
	public function test_extraction_returns_null_on_expired_deadline(): void {
		$html    = $this->parity_fixtures()['well-formed'];
		$expired = microtime( true ) - 5;

		$this->assertNull( $this->extract( $html, $expired ) );
	}

	/**
	 * The inline buffer respects the 2MB source cap.
	 *
	 * @return void
	 */
	public function test_extraction_respects_byte_cap(): void {
		$big    = str_repeat( 'a', 2097152 + 100 );
		$actual = $this->extract( '<style>' . $big . '</style>', microtime( true ) + 30 );

		$this->assertNotNull( $actual );
		// The HTML-level cap fires first and truncates the closing tag, so
		// the trailing-buffer flush yields MAX - open-tag bytes + newline.
		$this->assertSame( 2097152 - strlen( '<style>' ) + 1, strlen( $actual['inline_css'] ) );
		$this->assertLessThanOrEqual( 2097152, strlen( $actual['inline_css'] ) );
	}

	/**
	 * The canonical processor probe is available with the HTML API stub.
	 *
	 * @return void
	 */
	public function test_html_processor_probe_is_available_with_stub(): void {
		$this->assertTrue( Util::should_use_html_processor() );
	}

	/**
	 * Multi-token rel values are excluded on both paths (exact-match parity).
	 *
	 * Guards the token-contains divergence: `alternate stylesheet` and
	 * `stylesheet preload` must not enter the source set on either path.
	 *
	 * @return void
	 */
	public function test_extraction_excludes_multi_token_rel_values(): void {
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- HTML fixture string.
		$html = '<link rel="alternate stylesheet" href="http://example.com/alt.css">'
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- HTML fixture string.
			. '<link rel="stylesheet preload" href="http://example.com/both.css">'
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- HTML fixture string.
			. '<link rel="stylesheet" href="http://example.com/exact.css">';

		$expected = $this->extract_via_dom( $html );
		$actual   = $this->extract( $html, microtime( true ) + 30 );

		$this->assertNotNull( $actual );
		$this->assertSame( array( 'http://example.com/exact.css' ), $actual['source_urls'] );
		$this->assertSame( $expected['source_urls'], $actual['source_urls'] );
	}

	/**
	 * Uppercase rel is collected by the stream (spec-correct widening).
	 *
	 * HTML `rel` is ASCII case-insensitive so browsers treat
	 * `REL="STYLESHEET"` as a stylesheet; the case-sensitive DOM XPath
	 * misses it. This single case-fold is the only intentional stream/DOM
	 * divergence and is documented on the extractor.
	 *
	 * @return void
	 */
	public function test_extraction_collects_uppercase_rel_case_insensitively(): void {
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- HTML fixture string.
		$actual = $this->extract( '<link rel="STYLESHEET" href="http://example.com/upper.css">', microtime( true ) + 30 );

		$this->assertNotNull( $actual );
		$this->assertSame( array( 'http://example.com/upper.css' ), $actual['source_urls'] );
	}

	/**
	 * Rawtext style bodies match DOM (neither side decodes entities).
	 *
	 * `<style>` is rawtext: entities and comment markers stay literal in
	 * both `serialize_token()` output and DOM `textContent`, so the stream
	 * keeps the raw buffer with no decode/strip step.
	 *
	 * @return void
	 */
	public function test_extraction_decodes_entities_like_dom(): void {
		$html     = '<style>p::after{content:"&gt; &amp;"}/* &lt;tag&gt; */</style>';
		$expected = $this->extract_via_dom( $html );
		$actual   = $this->extract( $html, microtime( true ) + 30 );

		$this->assertNotNull( $actual );
		$this->assertSame( $expected['inline_css'], $actual['inline_css'] );
	}
}
