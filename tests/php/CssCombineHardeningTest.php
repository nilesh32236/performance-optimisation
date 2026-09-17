<?php
/**
 * Tests for the CSS combine allowlist + generated-CSS sanitize hardening (issue #1409).
 *
 * Covers: traversal/off-site href refusal via Util::is_css_combine_source_allowed(),
 * script-payload neutralization via Util::sanitize_inline_css(), the Cache combine
 * allowlist/sanitize helpers, the Used_CSS generated-CSS sanitizer, and the CSS
 * mutation REST routes keeping the nonce+cap permission callback (no __return_true
 * drift, no unauthenticated posts).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\Rest;
use PerformanceOptimise\Inc\Used_CSS;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * CSS combine hardening tests.
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */
class CssCombineHardeningTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Invoke a private method on an instance or class.
	 *
	 * @param object|string $target Instance or class name.
	 * @param string        $method Method name.
	 * @param array         $args   Arguments.
	 * @return mixed
	 */
	private function invoke_private( $target, string $method, array $args = array() ) {
		$reflection = new \ReflectionMethod( $target, $method );
		$instance   = is_object( $target ) ? $target : null;
		return $reflection->invokeArgs( $instance, $args );
	}

	/**
	 * Hostile hrefs that must never map to local reads.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function hostile_href_provider(): array {
		return array(
			'empty'                 => array( '' ),
			'blank'                 => array( '   ' ),
			'non-string'            => array( array( 'x' ) ),
			'plain dotdot'          => array( '/wp-content/../wp-config.css' ),
			'encoded traversal'     => array( 'http://example.com/%2e%2e/%2e%2e/etc/passwd' ),
			'encoded traversal 2'   => array( '/wp-content/..%2f..%2fetc/passwd.css' ),
			'nul byte'              => array( "/wp-content/a\0b.css" ),
			'off-site https'        => array( 'https://evil.com/foo.css' ),
			'off-site http'         => array( 'http://evil.com/wp-content/style.css' ),
			'off-site subdomain'    => array( 'https://cdn.evil.com/style.css' ),
			'protocol-relative off' => array( '//evil.com/style.css' ),
			'php wrapper'           => array( 'php://filter/convert.base64-encode/resource=/etc/passwd' ),
			'file wrapper'          => array( 'file:///etc/passwd' ),
			'data uri'              => array( 'data:text/css,body{}' ),
			'javascript scheme'     => array( 'javascript:alert(1)' ),
		);
	}

	/**
	 * The allowlist refuses traversal/off-site/wrapper hrefs.
	 *
	 * @param mixed $href Hostile href.
	 * @dataProvider hostile_href_provider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'hostile_href_provider' )]
	public function test_combine_source_allowlist_refuses_hostile_hrefs( $href ): void {
		Functions\when( 'has_filter' )->justReturn( false );
		$this->assertFalse( Util::is_css_combine_source_allowed( $href ) );
	}

	/**
	 * The allowlist keeps legitimate local and same-site hrefs.
	 */
	public function test_combine_source_allowlist_keeps_benign(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		$this->assertTrue( Util::is_css_combine_source_allowed( '/wp-content/themes/my-theme/style.css' ) );
		$this->assertTrue( Util::is_css_combine_source_allowed( 'wp-content/themes/my-theme/style.css' ) );
		$this->assertTrue( Util::is_css_combine_source_allowed( 'http://example.com/wp-content/themes/my-theme/style.css' ) );
		$this->assertTrue( Util::is_css_combine_source_allowed( 'https://example.com/wp-content/themes/my-theme/style.css' ) );
		$this->assertTrue( Util::is_css_combine_source_allowed( '//example.com/wp-content/themes/my-theme/style.css' ) );
		// Benign filenames containing `..` (not as a path segment) stay on
		// the combine path (segment-only dot-dot check).
		$this->assertTrue( Util::is_css_combine_source_allowed( '/wp-content/themes/my-theme/app..v2.css' ) );
		$this->assertTrue( Util::is_css_combine_source_allowed( 'https://example.com/wp-content/themes/my-theme/app..v2.css' ) );
	}

	/**
	 * An explicitly allowlisted off-site host passes via the filter.
	 */
	public function test_combine_source_allowlist_filter_permits_listed_host(): void {
		Functions\when( 'has_filter' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, $host = '' ) {
				if ( 'wppo_combine_allowed_stylesheet_host' === $hook ) {
					return 'cdn.example.net' === $host;
				}
				return $value;
			}
		);
		$this->assertTrue( Util::is_css_combine_source_allowed( 'https://cdn.example.net/style.css' ) );
		$this->assertFalse( Util::is_css_combine_source_allowed( 'https://evil.com/style.css' ) );
	}

	/**
	 * Script-capable CSS payloads are neutralized, clean CSS passes through.
	 */
	public function test_sanitize_inline_css_neutralizes_script_payloads(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		foreach ( array(
			'.x{}/*</style><script>alert(1)</script>*/',
			'.x{background:expression(alert(1))}',
			'.x{background:url(javascript:alert(1))}',
			'.x{behavior:url(x.htc)}',
			'.x{background:url(data:image/svg+xml;base64,PHNjcmlwdA==)}',
			'.x{-moz-binding:url(x.xml#x)}',
			'.x{content:"&#60;script&#62;"}',
		) as $payload ) {
			$this->assertTrue( Util::contains_unsafe_css_tokens( $payload ), "Gate missed hostile input: {$payload}" );
			$sanitized = Util::sanitize_inline_css( $payload );
			$this->assertFalse( Util::contains_unsafe_css_tokens( $sanitized ), "Sanitizer left executable tokens: {$sanitized}" );
			$this->assertStringNotContainsString( '</style', strtolower( $sanitized ) );
			$this->assertStringNotContainsString( '<script', strtolower( $sanitized ) );
		}
	}

	/**
	 * Clean CSS is byte-identical through the sanitizer and gate.
	 */
	public function test_sanitize_inline_css_passes_clean_css_through(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		$clean = 'body{color:#fff;background:url(a.png)}.behavior-badge{display:block}html{scroll-behavior:smooth}';
		$this->assertFalse( Util::contains_unsafe_css_tokens( $clean ) );
		$this->assertSame( $clean, Util::sanitize_inline_css( $clean ) );
	}

	/**
	 * Cache::fetch_remote_css() never maps off-site hrefs to local reads.
	 */
	public function test_cache_fetch_remote_css_refuses_off_site(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array() );
		$cache = new Cache( array() );
		$this->assertFalse( $this->invoke_private( $cache, 'fetch_remote_css', array( 'https://evil.com/foo.css' ) ) );
		$this->assertFalse( $this->invoke_private( $cache, 'fetch_remote_css', array( 'http://example.com/%2e%2e/%2e%2e/etc/passwd' ) ) );
	}

	/**
	 * Cache::sanitize_combined_css() drops surviving hostile tokens, keeps clean CSS.
	 */
	public function test_cache_sanitize_combined_css(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array() );
		$cache = new Cache( array() );
		$clean = 'body{color:red}';
		$this->assertSame( $clean, $this->invoke_private( $cache, 'sanitize_combined_css', array( $clean ) ) );
		$sanitized = $this->invoke_private( $cache, 'sanitize_combined_css', array( '.x{}/*</style><script>alert(1)</script>*/' ) );
		$this->assertFalse( Util::contains_unsafe_css_tokens( $sanitized ) );
	}

	/**
	 * Used_CSS::sanitize_generated_css() neutralizes hostile tokens.
	 */
	public function test_used_css_sanitize_generated_css(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		$out = $this->invoke_private( 'PerformanceOptimise\Inc\Used_CSS', 'sanitize_generated_css', array( '.x{}/*</style><script>alert(1)</script>*/' ) );
		$this->assertFalse( Util::contains_unsafe_css_tokens( $out ) );
		$clean = 'body{color:red}';
		$this->assertSame( $clean, $this->invoke_private( 'PerformanceOptimise\Inc\Used_CSS', 'sanitize_generated_css', array( $clean ) ) );
	}

	/**
	 * Used_CSS::generate_used_css() never returns executable CSS for hostile assets.
	 */
	public function test_used_css_generate_never_returns_executable(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array() );
		// The no-Tag-Processor degraded path logs once via Log::add():
		// stub kses and swap a minimal $wpdb insert stub (restored after).
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		global $wpdb;
		$wpdb_backup = $wpdb;
		$wpdb        = new class() {
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';
			/**
			 * Stub insert.
			 *
			 * @return int
			 */
			public function insert() {
				return 1;
			}
		};
		try {
			$used_css = new Used_CSS( array() );
			$html     = '<html><head></head><body><div class="x">hi</div></body></html>';
			$assets   = array( 'h' => '.x{color:red}/*</style><script>alert(1)</script>*/' );
			$out      = $used_css->generate_used_css( $html, $assets );
			$this->assertFalse( Util::contains_unsafe_css_tokens( $out ) );
		} finally {
			$wpdb = $wpdb_backup;
		}
	}

	/**
	 * CSS mutation routes keep the nonce+capability permission callback.
	 *
	 * No CSS mutation callback may drift to public access (`__return_true`;
	 * the only public route is the token+rate-limited rum_collect beacon).
	 * Unauthenticated rejection itself is proven in RestTest
	 * (permission_callback + nonce tests); this test pins the wiring.
	 *
	 * Note: this test deliberately avoids stubbing `current_user_can` /
	 * `wp_verify_nonce` — declaring those process-wide would flip the
	 * `function_exists()` capability gates in later test files.
	 */
	public function test_css_mutation_routes_require_nonce_and_cap(): void {
		$rest     = new Rest();
		$captured = array();
		Functions\when( 'register_rest_route' )->alias(
			static function ( $route_ns, $route, $args ) use ( &$captured ) {
				$captured[ $route ] = $args;
				return true;
			}
		);
		$rest->register_routes();

		foreach ( array( 'used_css_regenerate', 'purge_used_css_cache', 'regenerate_ccss' ) as $route ) {
			$this->assertArrayHasKey( $route, $captured, "Route {$route} not registered" );
			$this->assertSame( array( $rest, 'permission_callback' ), $captured[ $route ]['permission_callback'], "Route {$route} must keep permission_callback" );
		}
		// No CSS mutation callback drifts to public access; the only public
		// route is the token+rate-limited rum_collect beacon.
		foreach ( $captured as $route => $args ) {
			if ( in_array( $route, array( 'used_css_regenerate', 'purge_used_css_cache', 'regenerate_ccss' ), true ) ) {
				$this->assertNotSame( '__return_true', $args['permission_callback'] );
			}
		}

		// The shared gate itself rejects missing capabilities/nonces
		// (proven in RestTest); wiring the CSS routes to it is what this
		// test pins, so unauthenticated callback posts cannot drift open.
		$this->assertTrue( method_exists( $rest, 'permission_callback' ) );
	}
}
