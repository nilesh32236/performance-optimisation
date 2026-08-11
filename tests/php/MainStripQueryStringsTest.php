<?php
/**
 * Tests for Main::strip_static_query_strings().
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;
use Brain\Monkey\Functions;

/**
 * Tests the removeQueryStrings static-asset URL rewriting.
 *
 * @package PerformanceOptimise\Tests
 */
class MainStripQueryStringsTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Build a Main instance without invoking the constructor.
	 *
	 * @return Main
	 */
	private function make_main(): Main {
		$reflection = new \ReflectionClass( Main::class );
		$main       = $reflection->newInstanceWithoutConstructor();

		$options = $reflection->getProperty( 'options' );
		$options->setAccessible( true );
		$options->setValue(
			$main,
			array(
				'file_optimisation' => array( 'removeQueryStrings' => true ),
			)
		);

		return $main;
	}

	/**
	 * Test that a URL with only a ver arg loses the query string entirely.
	 */
	public function test_strips_ver_only_query_string(): void {
		$main = $this->make_main();

		$result = $main->strip_static_query_strings(
			'https://example.com/wp-content/themes/x/style.css?ver=6.8.1',
			'theme-style'
		);

		$this->assertSame(
			'https://example.com/wp-content/themes/x/style.css',
			$result
		);
	}

	/**
	 * Test that ver is removed while other args are preserved.
	 */
	public function test_preserves_other_query_args(): void {
		$main = $this->make_main();

		$result = $main->strip_static_query_strings(
			'https://example.com/app.js?ver=1.2&locale=en',
			'app'
		);

		$this->assertStringNotContainsString( 'ver=', $result );
		$this->assertStringContainsString( 'locale=en', $result );
	}

	/**
	 * Test that URLs without a query string are returned unchanged.
	 */
	public function test_returns_url_without_query_unchanged(): void {
		$main = $this->make_main();

		$url = 'https://example.com/asset.js';

		$this->assertSame( $url, $main->strip_static_query_strings( $url, 'asset' ) );
	}

	/**
	 * Test that an empty source is returned as-is.
	 */
	public function test_returns_empty_src_unchanged(): void {
		$main = $this->make_main();

		$this->assertSame( '', $main->strip_static_query_strings( '', 'asset' ) );
	}

	/**
	 * Test that a relative asset URL keeps its relative form while ver is removed.
	 */
	public function test_preserves_relative_url(): void {
		$main = $this->make_main();

		$result = $main->strip_static_query_strings(
			'../js/app.js?ver=1.2',
			'app'
		);

		$this->assertSame( '../js/app.js', $result );
	}

	/**
	 * Test that a protocol-relative URL keeps its scheme-relative form.
	 */
	public function test_preserves_protocol_relative_url(): void {
		$main = $this->make_main();

		$result = $main->strip_static_query_strings(
			'//cdn.example.com/app.js?ver=1.2',
			'app'
		);

		$this->assertSame( '//cdn.example.com/app.js', $result );
	}

	/**
	 * Test that a port and fragment survive while ver is removed.
	 */
	public function test_preserves_port_and_fragment(): void {
		$main = $this->make_main();

		$result = $main->strip_static_query_strings(
			'https://example.com:8443/app.js?ver=1.2&locale=en#top',
			'app'
		);

		$this->assertSame(
			'https://example.com:8443/app.js?locale=en#top',
			$result
		);
	}

	/**
	 * Test that a fragment is preserved when the ver arg is the only arg.
	 */
	public function test_preserves_fragment_when_only_ver_removed(): void {
		$main = $this->make_main();

		$result = $main->strip_static_query_strings(
			'https://example.com/app.js?ver=1.2#section',
			'app'
		);

		$this->assertSame( 'https://example.com/app.js#section', $result );
	}

	/**
	 * Test that the ver arg on a minified cache URL is preserved.
	 *
	 * The queued-styles minify path versions its min-cache files with the file
	 * mtime, so stripping it would let browsers keep serving stale minified
	 * CSS/JS after regeneration.
	 */
	public function test_preserves_ver_on_min_cache_url(): void {
		$main = $this->make_main();

		$src = 'http://example.com/wp-content/cache/wppo/min/1/css/ab12cd34.css?ver=1234567890';

		$this->assertSame( $src, $main->strip_static_query_strings( $src, 'wppo-css' ) );
	}

	/**
	 * Test that the ver arg on a combined stylesheet URL is preserved.
	 *
	 * The combine path enqueues its cache file with the file mtime as the
	 * version, and the printed href goes through the style_loader_src filter
	 * just like any other style.
	 */
	public function test_preserves_ver_on_combined_cache_url(): void {
		$main = $this->make_main();

		$src = 'http://example.com/wp-content/cache/wppo/example.com/css/combined.css?ver=9876543210';

		$this->assertSame( $src, $main->strip_static_query_strings( $src, 'wppo-combine-css' ) );
	}

	/**
	 * Test that a third-party URL that merely references a wppo-cache-like path
	 * is still stripped when the file is not served by the plugin.
	 */
	public function test_strips_ver_on_minified_theme_asset(): void {
		$main = $this->make_main();

		$result = $main->strip_static_query_strings(
			'https://example.com/wp-content/themes/x/style.min.css?ver=6.8.1',
			'theme-style'
		);

		$this->assertSame(
			'https://example.com/wp-content/themes/x/style.min.css',
			$result
		);
	}
}
