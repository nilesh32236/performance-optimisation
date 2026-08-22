<?php
/**
 * Tests for Critical_CSS SSRF guards and inline CSS sanitization.
 *
 * Covers the stylesheet-fetch SSRF guard (`is_safe_stylesheet_url`),
 * safe/regular HTTP routing for own-site vs external hosts, @import URL
 * gating, and the inline-CSS breakout sanitization applied before output.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Critical_CSS;
use Brain\Monkey\Functions;

/**
 * Class CriticalCssTest.
 *
 * @package PerformanceOptimise\Tests
 */
class CriticalCssTest extends \PHPUnit\Framework\TestCase {

	use WPPO_Test_Bootstrap;

	/**
	 * Tracks which HTTP API was used by fetch stubs.
	 *
	 * @var array
	 */
	private array $http_calls = array(
		'regular' => 0,
		'safe'    => 0,
	);

	/**
	 * Filter tag overrides consumed by the apply_filters stub.
	 *
	 * @var array
	 */
	private array $filter_overrides = array();

	/**
	 * Stub the WP functions used by the Critical_CSS helpers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->http_calls       = array(
			'regular' => 0,
			'safe'    => 0,
		);
		$this->filter_overrides = array();

		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );

		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value = null ) {
				return $this->filter_overrides[ $tag ] ?? $value;
			}
		);

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'is_wp_error' )->justReturn( false );

		// Deterministic stand-in for core's private/loopback rejection.
		Functions\when( 'wp_http_validate_url' )->alias(
			static function ( $url ) {
				$parts = wp_parse_url( (string) $url );
				if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
					return false;
				}
				if ( ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
					return false;
				}
				$private = array( '127.0.0.1', '169.254.169.254', '192.168.1.10', '10.0.0.5', 'localhost', '[::1]' );

				return in_array( strtolower( (string) $parts['host'] ), $private, true ) ? false : $url;
			}
		);

		$self = $this;

		Functions\when( 'wp_remote_get' )->alias(
			function () use ( $self ) {
				++$self->http_calls['regular'];

				return $self->fake_response();
			}
		);

		Functions\when( 'wp_safe_remote_get' )->alias(
			function () use ( $self ) {
				++$self->http_calls['safe'];

				return $self->fake_response();
			}
		);

		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return $response['response']['code'] ?? 200;
			}
		);

		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return $response['body'] ?? '';
			}
		);
	}

	/**
	 * Fake successful HTTP response consumed by the remote-get stubs.
	 *
	 * @return array
	 */
	public function fake_response(): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => 'a{color:red}',
		);
	}

	/**
	 * Invoke a private static method via reflection.
	 *
	 * @param string $method Method name.
	 * @param mixed  ...$args Arguments.
	 * @return mixed
	 */
	private function invoke_private( string $method, ...$args ) {
		$reflection = new ReflectionMethod( Critical_CSS::class, $method );
		$reflection->setAccessible( true );

		return $reflection->invoke( null, ...$args );
	}

	/**
	 * Internal/cloud-metadata/non-http targets must be rejected outright.
	 *
	 * @return void
	 */
	public function test_is_safe_stylesheet_url_rejects_internal_and_non_http_targets(): void {
		$this->assertFalse( $this->invoke_private( 'is_safe_stylesheet_url', 'http://127.0.0.1/x.css' ) );
		$this->assertFalse( $this->invoke_private( 'is_safe_stylesheet_url', 'http://169.254.169.254/latest/meta-data/' ) );
		$this->assertFalse( $this->invoke_private( 'is_safe_stylesheet_url', 'http://192.168.1.10/s.css' ) );
		$this->assertFalse( $this->invoke_private( 'is_safe_stylesheet_url', 'http://10.0.0.5/a.css' ) );
		$this->assertFalse( $this->invoke_private( 'is_safe_stylesheet_url', 'ftp://example.com/x.css' ) );
		$this->assertFalse( $this->invoke_private( 'is_safe_stylesheet_url', '//example.com/x.css' ) );
		$this->assertFalse( $this->invoke_private( 'is_safe_stylesheet_url', '/relative/path.css' ) );
	}

	/**
	 * The site's own host is always allowed (localhost dev sites included).
	 *
	 * @return void
	 */
	public function test_is_safe_stylesheet_url_allows_own_site_host(): void {
		$this->assertTrue( $this->invoke_private( 'is_safe_stylesheet_url', 'http://example.com/wp-includes/css/style.css' ) );
	}

	/**
	 * Public URLs passing core validation are allowed.
	 *
	 * @return void
	 */
	public function test_is_safe_stylesheet_url_allows_public_urls_passing_validation(): void {
		$this->assertTrue( $this->invoke_private( 'is_safe_stylesheet_url', 'https://fonts.cdn-example.net/s.css' ) );
	}

	/**
	 * The host allowlist filter wins even when validation rejects the target.
	 *
	 * @return void
	 */
	public function test_filter_allowlists_external_host_even_when_validation_fails(): void {
		$this->filter_overrides['wppo_ccss_allowed_stylesheet_host'] = true;

		$this->assertTrue( $this->invoke_private( 'is_safe_stylesheet_url', 'http://192.168.1.10/internal-theme.css' ) );
	}

	/**
	 * Unsafe URLs are refused before any HTTP request is made.
	 *
	 * @return void
	 */
	public function test_fetch_refuses_unsafe_url_before_any_http_call(): void {
		$result = $this->invoke_private( 'fetch_stylesheet_with_imports', 'http://127.0.0.1/x.css' );

		$this->assertSame( '', $result );
		$this->assertSame( 0, $this->http_calls['regular'] );
		$this->assertSame( 0, $this->http_calls['safe'] );
	}

	/**
	 * Own-host stylesheets go through wp_remote_get().
	 *
	 * @return void
	 */
	public function test_fetch_routes_own_host_through_regular_api(): void {
		$result = $this->invoke_private( 'fetch_stylesheet_with_imports', 'http://example.com/style.css' );

		$this->assertSame( 'a{color:red}', $result );
		$this->assertSame( 1, $this->http_calls['regular'] );
		$this->assertSame( 0, $this->http_calls['safe'] );
	}

	/**
	 * External stylesheets go through wp_safe_remote_get() so redirect hops
	 * are re-validated against private ranges.
	 *
	 * @return void
	 */
	public function test_fetch_routes_external_host_through_safe_api(): void {
		$result = $this->invoke_private( 'fetch_stylesheet_with_imports', 'https://fonts.cdn-example.net/style.css' );

		$this->assertSame( 'a{color:red}', $result );
		$this->assertSame( 1, $this->http_calls['safe'] );
		$this->assertSame( 0, $this->http_calls['regular'] );
	}

	/**
	 * Absolute @import URLs are gated on the safety check.
	 *
	 * @return void
	 */
	public function test_resolve_import_url_gates_absolute_urls(): void {
		$base = 'http://example.com/wp-content/themes/a/b.css';

		$this->assertSame( '', $this->invoke_private( 'resolve_import_url', 'http://169.254.169.254/x.css', $base ) );
		$this->assertSame( 'https://cdn.ext/x.css', $this->invoke_private( 'resolve_import_url', 'https://cdn.ext/x.css', $base ) );
	}

	/**
	 * Relative / root-relative / protocol-relative imports resolve as before.
	 *
	 * @return void
	 */
	public function test_resolve_import_url_still_resolves_relative_forms(): void {
		$base = 'http://example.com/wp-content/themes/a/b.css';

		$this->assertSame( 'http://example.com/wp-content/themes/a/../c.css', $this->invoke_private( 'resolve_import_url', '../c.css', $base ) );
		$this->assertSame( 'http://example.com/c.css', $this->invoke_private( 'resolve_import_url', '/c.css', $base ) );
		$this->assertSame( 'http://cdn.ext/x.css', $this->invoke_private( 'resolve_import_url', '//cdn.ext/x.css', $base ) );
	}

	/**
	 * Style/script breakout tokens never survive sanitization.
	 *
	 * @return void
	 */
	public function test_sanitize_inline_css_neutralizes_breakout_tokens(): void {
		$input     = '.x{content:"a"}/*</STYLE><SCRIPT>alert(1)</SCRIPT>*/';
		$sanitized = $this->invoke_private( 'sanitize_inline_css', $input );

		$this->assertDoesNotMatchRegularExpression( '/<\/style/i', $sanitized );
		$this->assertDoesNotMatchRegularExpression( '/<script/i', $sanitized );
	}

	/**
	 * Clean CSS passes through byte-for-byte.
	 *
	 * @return void
	 */
	public function test_sanitize_inline_css_passes_clean_css_through(): void {
		$css = 'body{color:#fff;background:url(a.png)}';

		$this->assertSame( $css, $this->invoke_private( 'sanitize_inline_css', $css ) );
	}

	/**
	 * Any remaining raw '<' is encoded as the equivalent CSS escape.
	 *
	 * @return void
	 */
	public function test_sanitize_inline_css_encodes_remaining_angle_brackets(): void {
		$out = $this->invoke_private( 'sanitize_inline_css', '.a:before{content:"<b>"}' );

		$this->assertStringContainsString( '\3c ', $out );
		$this->assertDoesNotMatchRegularExpression( '/</', $out );
	}
}
