<?php
/**
 * Tests for quote-aware CSS parsing in Used_CSS (find_rule_end, at-rule
 * prelude scan, and the rel token check in collect_css_asset_from_tag).
 *
 * Covers quoted braces in regular rules and @media blocks plus alternate /
 * mixed-case / whitespace-padded rel variants, so a regression to naive
 * strpos() scanning or exact-match rel comparison fails loudly.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Used_CSS;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests for quote-aware rule and asset extraction.
 *
 * @package PerformanceOptimise\Tests
 */
class UsedCssQuoteAwareParseTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Set up Brain Monkey and common stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		\PerformanceOptimise\Inc\Util::reset_runtime_caches();
		$this->register_common_function_stubs();
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
	 * Invoke a private static Used_CSS method.
	 *
	 * @param string $name Method name.
	 * @param array  $args Arguments.
	 * @return mixed
	 */
	private function invoke_used_css_static( string $name, array $args = array() ) {
		$method = new \ReflectionMethod( Used_CSS::class, $name );
		return $method->invokeArgs( null, $args );
	}

	/**
	 * Stub the remote CSS fetch used by extract_css_assets_from_html().
	 */
	private function stub_remote_css(): void {
		Functions\when( 'wp_safe_remote_get' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => 'body{color:red}',
			)
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'body{color:red}' );
	}

	/**
	 * A quoted closing brace must not truncate a regular rule.
	 */
	public function test_parse_css_keeps_quoted_braces_in_regular_rule(): void {
		$rules = ( new Used_CSS( array() ) )->parse_css( '.a{content:"}"}.b{color:red}' );

		$this->assertCount( 2, $rules );
		$this->assertSame( '.a', $rules[0]['selector'] );
		$this->assertSame( 'content:"}"', $rules[0]['declaration'] );
		$this->assertSame( '.b', $rules[1]['selector'] );
	}

	/**
	 * A quoted closing brace inside an @media block must not shift offsets.
	 */
	public function test_parse_css_keeps_quoted_braces_inside_media_block(): void {
		$rules = ( new Used_CSS( array() ) )->parse_css( '@media screen{.a{content:"}"}.b{color:red}}' );

		$this->assertCount( 1, $rules );
		$this->assertSame( 'media', $rules[0]['type'] );
		$this->assertCount( 2, $rules[0]['children'] );
		$this->assertSame( '.a', $rules[0]['children'][0]['selector'] );
		$this->assertSame( '.b', $rules[0]['children'][1]['selector'] );
	}

	/**
	 * A quoted semicolon in an @import prelude must not end the prelude early.
	 */
	public function test_parse_css_handles_quoted_semicolon_in_import_prelude(): void {
		$rules = ( new Used_CSS( array() ) )->parse_css( '@import url("a;b.css");.a{color:red}' );

		$this->assertCount( 2, $rules );
		$this->assertSame( 'at-rule', $rules[0]['type'] );
		$this->assertSame( '.a', $rules[1]['selector'] );
	}

	/**
	 * The rel token check matches alternate stylesheets and mixed case.
	 */
	public function test_extract_css_assets_matches_alternate_and_uppercase_rel(): void {
		$this->stub_remote_css();

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- HTML fixture string.
		$html = '<link rel="alternate stylesheet" href="http://example.com/wp-content/a.css">'
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- HTML fixture string.
			. '<link rel="STYLESHEET" href="http://example.com/wp-content/b.css">'
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- HTML fixture string.
			. '<link rel="preload" href="http://example.com/wp-content/skip.css">';

		$assets = $this->invoke_used_css_static( 'extract_css_assets_from_html', array( $html ) );

		$this->assertCount( 2, $assets );
		$this->assertArrayHasKey( md5( 'http://example.com/wp-content/a.css' ), $assets );
		$this->assertArrayHasKey( md5( 'http://example.com/wp-content/b.css' ), $assets );
		$this->assertArrayNotHasKey( md5( 'http://example.com/wp-content/skip.css' ), $assets );
	}

	/**
	 * Extra whitespace around rel tokens must still match.
	 */
	public function test_extract_css_assets_matches_whitespace_padded_rel(): void {
		$this->stub_remote_css();

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- HTML fixture string.
		$html = '<link rel="  stylesheet   alternate " href="http://example.com/wp-content/a.css">';

		$assets = $this->invoke_used_css_static( 'extract_css_assets_from_html', array( $html ) );

		$this->assertCount( 1, $assets );
		$this->assertArrayHasKey( md5( 'http://example.com/wp-content/a.css' ), $assets );
	}
}
