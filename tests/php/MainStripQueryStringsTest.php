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
}
