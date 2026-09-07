<?php
/**
 * Tests for the WP 6.9+ WP_HTML_Processor buffer rewrites (issue #883).
 *
 * Covers CDN::rewrite_buffer() (tag attrs, srcset, inline style url()) and
 * Used_CSS selector/CSS-asset extraction through the processor path, plus
 * preservation of malformed-markup structure vs the Tag Processor fallback.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\CDN;
use PerformanceOptimise\Inc\Used_CSS;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests for the processor-based buffer rewrites.
 *
 * @package PerformanceOptimise\Tests
 */
class CdnUsedCssProcessorTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * A CDN mapping allowing every default attribute.
	 *
	 * @return array
	 */
	private function full_mapping(): array {
		return array(
			'cdn_url'           => 'https://cdn.example.com',
			'ori'               => '',
			'ori_dir'           => '',
			'include_dirs'      => 'wp-content|wp-includes',
			'include_filetypes' => 'jpg,png,css,js',
			'cdn_urls'          => array( 'https://cdn.example.com' ),
		);
	}

	/**
	 * Set up Brain Monkey and common stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		\PerformanceOptimise\Inc\Util::reset_cached_home_urls();
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		$this->register_common_function_stubs();
		// Minimal functional WP_HTML_* stand-ins (same pattern as
		// ImageOptimisationTest) so the processor path engages, then re-probe
		// the memo so this suite is order-independent (issue #883 review).
		require_once __DIR__ . '/stubs/wp-html-api.php';
		Util::reset_html_processor_memo();
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_rand' )->justReturn( 1 );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
	}

	/**
	 * Tear down Brain Monkey.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Invoke a private static CDN method.
	 *
	 * @param string $name Method name.
	 * @param array  $args Arguments.
	 * @return mixed
	 */
	private function invoke_cdn_static( string $name, array $args = array() ) {
		$method = new \ReflectionMethod( CDN::class, $name );
		$method->setAccessible( true );
		return $method->invokeArgs( null, $args );
	}

	/**
	 * Invoke a private static Used_CSS method.
	 *
	 * @param string $name Method name.
	 * @param array  $args Arguments.
	 * @return mixed
	 */
	private function invoke_used_css_static( string $name, array $args = array() ) {
		$method = new \ReflectionMethod( Used_CSS::class, $name );
		$method->setAccessible( true );
		return $method->invokeArgs( null, $args );
	}

	/**
	 * Test the processor-based CDN rewrite of img src, srcset and inline style url().
	 */
	public function test_rewrite_buffer_with_processor_rewrites_assets(): void {
		$buffer = '<img src="http://example.com/wp-content/uploads/pic.jpg" '
			. 'srcset="http://example.com/wp-content/uploads/pic.jpg 1x, http://example.com/wp-content/uploads/pic@2x.jpg 2x" '
			. 'style=\'background:url("http://example.com/wp-content/uploads/bg.png")\'>'
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- HTML fixture string.
			. '<link rel="stylesheet" href="http://example.com/wp-content/theme.css">';

		$processed = $this->invoke_cdn_static(
			'rewrite_buffer_with_processor',
			array(
				$buffer,
				array( $this->full_mapping() ),
				'http://example.com',
				'#^http://example\.com(/|$)#',
				array( 'img', 'script', 'link', 'source', 'video', 'audio', 'track', 'embed', 'object', 'iframe', 'picture', 'meta' ),
			)
		);

		$this->assertNotNull( $processed );
		$this->assertStringContainsString( 'src="https://cdn.example.com/wp-content/uploads/pic.jpg"', $processed );
		$this->assertStringContainsString( 'https://cdn.example.com/wp-content/uploads/pic@2x.jpg 2x', $processed );
		$this->assertStringContainsString( 'https://cdn.example.com/wp-content/uploads/bg.png', $processed );
		$this->assertStringContainsString( 'href="https://cdn.example.com/wp-content/theme.css"', $processed );
		$this->assertStringNotContainsString( 'http://example.com/wp-content/uploads', $processed );
	}

	/**
	 * Test that the processor path honours per-mapping cdn_attr restrictions:
	 * a src-restricted mapping must not rewrite href.
	 */
	public function test_rewrite_buffer_with_processor_respects_cdn_attr(): void {
		$mapping             = $this->full_mapping();
		$mapping['cdn_attr'] = 'src';

		$buffer    = '<a href="http://example.com/wp-content/page.css">x</a><img src="http://example.com/wp-content/pic.jpg">';
		$processed = $this->invoke_cdn_static(
			'rewrite_buffer_with_processor',
			array( $buffer, array( $mapping ), 'http://example.com', '#^http://example\.com(/|$)#', array( 'img', 'a', 'link' ) )
		);

		$this->assertNotNull( $processed );
		$this->assertStringContainsString( 'href="http://example.com/wp-content/page.css"', $processed );
		$this->assertStringContainsString( 'src="https://cdn.example.com/wp-content/pic.jpg"', $processed );
	}

	/**
	 * Test that malformed markup (SVG, nested table, missing closers) keeps its
	 * structure and asset URLs through the processor path.
	 */
	public function test_rewrite_buffer_with_processor_preserves_malformed_markup(): void {
		$buffer = '<div><table><tr><td>'
			. '<img src="http://example.com/wp-content/uploads/cell.jpg">'
			. '</table><svg><circle/></svg><img src="http://example.com/wp-content/uploads/tail.jpg">';

		$processed = $this->invoke_cdn_static(
			'rewrite_buffer_with_processor',
			array(
				$buffer,
				array( $this->full_mapping() ),
				'http://example.com',
				'#^http://example\.com(/|$)#',
				array( 'img', 'table', 'tr', 'td', 'div', 'svg', 'circle' ),
			)
		);

		$this->assertNotNull( $processed );
		$this->assertStringContainsString( 'https://cdn.example.com/wp-content/uploads/cell.jpg', $processed );
		$this->assertStringContainsString( 'https://cdn.example.com/wp-content/uploads/tail.jpg', $processed );
		// Structure preserved: both images, the table row/cell and the SVG shape.
		$this->assertSame( 2, substr_count( $processed, '<img' ) );
		$this->assertStringContainsString( '<td>', $processed );
		$this->assertStringContainsString( '<circle', $processed );
	}

	/**
	 * Test the full rewrite_buffer() wiring: the processor path engages when
	 * the serializer is available (mapping injected via settings).
	 */
	public function test_rewrite_buffer_uses_processor_path_when_available(): void {
		Util::set_settings_cache(
			array(
				'file_optimisation' => array(
					'cdnMapping' => array(
						array(
							'cdn_url'           => 'https://cdn.example.com',
							'include_dirs'      => 'wp-content',
							'include_filetypes' => 'jpg',
						),
					),
				),
			)
		);

		$this->assertTrue( Util::should_use_html_processor() );

		$buffer    = '<img src="http://example.com/wp-content/uploads/wired.jpg">';
		$processed = CDN::rewrite_buffer( $buffer );

		$this->assertStringContainsString( 'https://cdn.example.com/wp-content/uploads/wired.jpg', $processed );
	}

	/**
	 * Test that extract_selectors() collects tags, classes, ids and attributes
	 * through the processor path.
	 */
	public function test_extract_selectors_via_processor(): void {
		$html = '<div id="main" class="wrap wide" data-foo="1" href="http://example.com/a.css">'
			. '<p class="lead">text</p><img src="http://example.com/pic.png"></div>';

		$used = ( new Used_CSS( array() ) )->extract_selectors( $html );

		$this->assertArrayHasKey( 'div', $used['tags'] );
		$this->assertArrayHasKey( 'p', $used['tags'] );
		$this->assertArrayHasKey( 'img', $used['tags'] );
		$this->assertArrayHasKey( 'wrap', $used['classes'] );
		$this->assertArrayHasKey( 'wide', $used['classes'] );
		$this->assertArrayHasKey( 'lead', $used['classes'] );
		$this->assertArrayHasKey( 'main', $used['ids'] );
		$this->assertArrayHasKey( 'href', $used['attrs'] );
		$this->assertArrayHasKey( 'src', $used['attrs'] );
		$this->assertArrayHasKey( '.css', $used['attrs'] );
		$this->assertArrayHasKey( '.png', $used['attrs'] );
	}

	/**
	 * Test that extract_selectors() survives malformed markup (SVG, tables,
	 * unclosed tags) through the processor path.
	 */
	public function test_extract_selectors_survives_malformed_markup(): void {
		$html = '<table><tr><td class="cell"><svg><circle class="shape"/></svg>'
			. '<b>unclosed';

		$used = ( new Used_CSS( array() ) )->extract_selectors( $html );

		$this->assertArrayHasKey( 'table', $used['tags'] );
		$this->assertArrayHasKey( 'svg', $used['tags'] );
		$this->assertArrayHasKey( 'cell', $used['classes'] );
		$this->assertArrayHasKey( 'shape', $used['classes'] );
	}

	/**
	 * Test the CSS-asset extraction through the processor path with a stubbed
	 * remote fetch.
	 */
	public function test_extract_css_assets_via_processor(): void {
		Functions\when( 'wp_safe_remote_get' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => 'body{color:red}',
			)
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'body{color:red}' );

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- HTML fixture string.
		$html = '<link rel="stylesheet" href="http://example.com/wp-content/a.css">'
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- HTML fixture string.
			. '<link rel="preload" href="http://example.com/wp-content/skip.css">'
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- HTML fixture string.
			. '<link rel="stylesheet" href="http://example.com/wp-content/b.css">';

		$assets = $this->invoke_used_css_static( 'extract_css_assets_from_html', array( $html ) );

		$this->assertCount( 2, $assets );
		$this->assertSame( 'body{color:red}', $assets[ md5( 'http://example.com/wp-content/a.css' ) ] );
		$this->assertSame( 'body{color:red}', $assets[ md5( 'http://example.com/wp-content/b.css' ) ] );
		$this->assertArrayNotHasKey( md5( 'http://example.com/wp-content/skip.css' ), $assets );
	}
}
