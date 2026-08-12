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
	 * Test that the ver arg on a plugin cache URL is preserved even while a
	 * content_url filter is registered (the filter-bypass uncached branch).
	 *
	 * A registered content_url filter may return context-dependent output, so
	 * is_plugin_cache_url() resolves content_url() on every call instead of
	 * reusing cached host/path parts. The exemption must still hold there, and
	 * the dynamically filtered base must be honored per invocation.
	 */
	public function test_preserves_ver_on_min_cache_url_with_content_url_filter(): void {
		$base = 'http://example.com/wp-content';
		Functions\when( 'has_filter' )->justReturn( true );
		Functions\when( 'content_url' )->alias(
			static function ( $path = '' ) use ( &$base ) {
				return $base . (string) $path;
			}
		);
		$main = $this->make_main();

		$src = 'http://example.com/wp-content/cache/wppo/min/1/css/ab12cd34.css?ver=1234567890';

		$this->assertSame( $src, $main->strip_static_query_strings( $src, 'wppo-css' ) );

		// The filtered base changes (e.g. a CDN base): the previously-plugin
		// cache URL is now a foreign host, so its ver arg must be stripped.
		$base = 'http://cdn.example.net/wp-content';

		$this->assertSame(
			'http://example.com/wp-content/cache/wppo/min/1/css/ab12cd34.css',
			$main->strip_static_query_strings( $src, 'wppo-css' )
		);

		// A URL on the new filtered base is the plugin cache again and is
		// preserved, proving the uncached branch never froze the old parts.
		$cdn_src = 'http://cdn.example.net/wp-content/cache/wppo/min/1/css/ab12cd34.css?ver=1234567890';

		$this->assertSame( $cdn_src, $main->strip_static_query_strings( $cdn_src, 'wppo-css' ) );
	}

	/**
	 * Test that a foreign-host URL is still stripped while a content_url filter
	 * is registered (the plugin-cache exemption stays origin-aware in the
	 * filter-bypass uncached branch).
	 */
	public function test_strips_foreign_host_with_content_url_filter(): void {
		$base = 'http://example.com/wp-content';
		Functions\when( 'has_filter' )->justReturn( true );
		Functions\when( 'content_url' )->alias(
			static function ( $path = '' ) use ( &$base ) {
				return $base . (string) $path;
			}
		);
		$main = $this->make_main();

		$result = $main->strip_static_query_strings(
			'https://evil.example.net/assets/cache/wppo/thing.css?ver=1.0',
			'third-party'
		);

		$this->assertSame(
			'https://evil.example.net/assets/cache/wppo/thing.css',
			$result
		);
	}

	/**
	 * Test that the cached branch resolves content_url() only once per blog
	 * across many strip_static_query_strings() calls.
	 *
	 * Caching is the entire point of the is_plugin_cache_url() optimization, so
	 * this guards against a regression back to per-call resolution. A unique
	 * blog id isolates the static cache from other tests sharing the process.
	 */
	public function test_cached_branch_resolves_content_url_once(): void {
		$calls = 0;
		Functions\when( 'get_current_blog_id' )->justReturn( 9001 );
		Functions\when( 'content_url' )->alias(
			static function ( $path = '' ) use ( &$calls ) {
				++$calls;
				return 'http://example.com/wp-content' . (string) $path;
			}
		);
		$main = $this->make_main();

		$src = 'http://example.com/wp-content/cache/wppo/min/1/css/ab12cd34.css?ver=1234567890';

		$main->strip_static_query_strings( $src, 'wppo-css' );
		$main->strip_static_query_strings( $src, 'wppo-css' );
		$main->strip_static_query_strings( $src, 'wppo-css' );

		$this->assertSame( 1, $calls );
	}

	/**
	 * Test that the parsed-parts cache is keyed per blog id so a multisite
	 * switch_to_blog() cannot reuse another site's host/path metadata.
	 *
	 * Uses blog ids 10 and 20 (never used elsewhere in the suite) so the
	 * blog-keyed static caches in Util::cached_content_url() and
	 * is_plugin_cache_url() start cold for both sites.
	 */
	public function test_cache_is_keyed_per_blog(): void {
		$blog_id = 10;
		Functions\when( 'get_current_blog_id' )->alias(
			static function () use ( &$blog_id ) {
				return $blog_id;
			}
		);
		Functions\when( 'content_url' )->alias(
			static function ( $path = '' ) use ( &$blog_id ) {
				return 'http://site-' . $blog_id . '.example/wp-content' . (string) $path;
			}
		);
		$main = $this->make_main();

		$src10 = 'http://site-10.example/wp-content/cache/wppo/min/1/css/a.css?ver=1';

		// Blog 10: site-10.example is the plugin cache origin, so ver is kept.
		$this->assertSame( $src10, $main->strip_static_query_strings( $src10, 'wppo-css' ) );

		// Switch to blog 20: site-10.example is now a foreign host, so ver is
		// stripped; blog 20's own cache URL is preserved.
		$blog_id = 20;

		$this->assertSame(
			'http://site-10.example/wp-content/cache/wppo/min/1/css/a.css',
			$main->strip_static_query_strings( $src10, 'wppo-css' )
		);

		$src20 = 'http://site-20.example/wp-content/cache/wppo/min/1/css/a.css?ver=1';

		$this->assertSame( $src20, $main->strip_static_query_strings( $src20, 'wppo-css' ) );
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

	/**
	 * Test that a foreign host whose path merely contains /cache/wppo/ is still
	 * stripped (the plugin-cache exemption must be origin-aware).
	 */
	public function test_strips_ver_on_foreign_host_with_cache_path(): void {
		$main = $this->make_main();

		$result = $main->strip_static_query_strings(
			'https://evil.example.net/assets/cache/wppo/thing.css?ver=1.0',
			'third-party'
		);

		$this->assertSame(
			'https://evil.example.net/assets/cache/wppo/thing.css',
			$result
		);
	}

	/**
	 * Test that duplicate query keys survive while ver is removed.
	 */
	public function test_preserves_duplicate_query_keys(): void {
		$main = $this->make_main();

		$result = $main->strip_static_query_strings(
			'https://example.com/app.js?a=1&a=2&ver=3',
			'app'
		);

		$this->assertSame( 'https://example.com/app.js?a=1&a=2', $result );
	}

	/**
	 * Test that the original percent-encoding of remaining args is preserved.
	 */
	public function test_preserves_query_percent_encoding(): void {
		$main = $this->make_main();

		$result = $main->strip_static_query_strings(
			'https://example.com/app.js?token=a%20b&ver=1',
			'app'
		);

		$this->assertSame( 'https://example.com/app.js?token=a%20b', $result );
	}
}
