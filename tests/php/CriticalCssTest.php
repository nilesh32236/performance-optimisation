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
use PerformanceOptimise\Inc\Image_Optimisation;
use PerformanceOptimise\Inc\RUM;
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
	 * Extra apply_filters arguments captured per tag (audit #1357 review).
	 *
	 * @var array
	 */
	private array $filter_calls = array();

	/**
	 * In-memory option map backing the get_option stub.
	 *
	 * @var array
	 */
	private array $option_map = array();

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
		$this->filter_calls     = array();

		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_normalize_path' )->alias(
			static function ( $path ) {
				$path = str_replace( '\\', '/', (string) $path );
				return preg_replace( '|(?<=.)/+|', '/', $path );
			}
		);
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );

		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value = null ) {
				$args                         = func_get_args();
				$this->filter_calls[ $tag ][] = array_slice( $args, 2 );
				return $this->filter_overrides[ $tag ] ?? $value;
			}
		);

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'is_wp_error' )->justReturn( false );

		// Stubs for the CCSS cap / file-first delivery paths (issue #933).
		$this->option_map = array();
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->option_map ) ? $this->option_map[ $name ] : $fallback;
			}
		);
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'is_home' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_page' )->justReturn( false );
		Functions\when( 'is_archive' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'get_stylesheet' )->justReturn( 'test-theme' );

		// This suite shadows the bootstrap trait's setUp(), so repeat its
		// static-memo resets here: without them an earlier suite in the same
		// process (different home_url stub, claimed preload URLs, RUM
		// aggregates) leaks into these tests (issue #1255 review).
		\PerformanceOptimise\Inc\Util::reset_runtime_caches();
		if ( class_exists( 'PerformanceOptimise\Inc\Image_Optimisation' ) ) {
			\PerformanceOptimise\Inc\Image_Optimisation::clear_runtime_caches();
		}
		if ( class_exists( 'PerformanceOptimise\Inc\Critical_CSS' ) ) {
			\PerformanceOptimise\Inc\Critical_CSS::reset_ccss_memo();
		}
		if ( class_exists( 'PerformanceOptimise\Inc\RUM' ) && method_exists( 'PerformanceOptimise\Inc\RUM', 'clear_field_lcp_cache' ) ) {
			\PerformanceOptimise\Inc\RUM::clear_field_lcp_cache();
		}

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
	 * External URLs are refused by default even when syntactically valid
	 * (audit #1357: default-deny — wp_http_validate_url() is not safety).
	 * The host allowlist filter re-enables them explicitly.
	 *
	 * @return void
	 */
	public function test_is_safe_stylesheet_url_refuses_external_urls_by_default(): void {
		$this->assertFalse( $this->invoke_private( 'is_safe_stylesheet_url', 'https://fonts.cdn-example.net/s.css' ) );
		$this->filter_overrides['wppo_ccss_allowed_stylesheet_host'] = true;
		$this->assertTrue( $this->invoke_private( 'is_safe_stylesheet_url', 'https://fonts.cdn-example.net/s.css' ) );
		// The production filter receives the lowercased candidate host.
		$this->assertSame( 'fonts.cdn-example.net', $this->filter_calls['wppo_ccss_allowed_stylesheet_host'][0][0] ?? null );
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
	 * External stylesheets are refused before any HTTP call unless the host
	 * allowlist filter explicitly permits them (audit #1357 default-deny).
	 *
	 * @return void
	 */
	public function test_fetch_refuses_external_host_by_default(): void {
		$result = $this->invoke_private( 'fetch_stylesheet_with_imports', 'https://fonts.cdn-example.net/style.css' );

		$this->assertSame( '', $result );
		$this->assertSame( 0, $this->http_calls['safe'] );
		$this->assertSame( 0, $this->http_calls['regular'] );

		$this->filter_overrides['wppo_ccss_allowed_stylesheet_host'] = true;
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
		$this->assertSame( '', $this->invoke_private( 'resolve_import_url', 'https://cdn.ext/x.css', $base ) );
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
		$this->assertSame( '', $this->invoke_private( 'resolve_import_url', '//cdn.ext/x.css', $base ) );
		// Same-site protocol-relative imports still resolve (audit #1357 review).
		$this->assertSame( 'http://example.com/x.css', $this->invoke_private( 'resolve_import_url', '//example.com/x.css', $base ) );
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

	/**
	 * Payload matrix (issue #967): comment, expression, and URL vectors are
	 * neutralized and no raw angle bracket survives.
	 *
	 * @return void
	 */
	public function test_sanitize_inline_css_neutralizes_extended_vectors(): void {
		$vectors = array(
			'.x{background:url(x)}<!--injected-->',
			'.x{width:expression(alert(1))}',
			'.x{width:EXPRESSION (alert(1))}',
			'.x{background:url(javascript:alert(1))}',
			'.x{background:url(JAVASCRIPT :alert(1))}',
			'.x{background:url(vbscript:msgbox(1))}',
			'.x{behavior:url(x.htc)}',
			'.x{behaviour:url(x.htc)}',
			'.x{-moz-binding:url(x.xml)}',
		);

		foreach ( $vectors as $input ) {
			$sanitized = $this->invoke_private( 'sanitize_inline_css', $input );

			$this->assertDoesNotMatchRegularExpression( '/<\/style/i', $sanitized, "Unsafe token survives for input: {$input}" );
			$this->assertDoesNotMatchRegularExpression( '/<script/i', $sanitized, "Unsafe token survives for input: {$input}" );
			$this->assertStringNotContainsString( '<!--', $sanitized, "Unsafe token survives for input: {$input}" );
			$this->assertStringNotContainsString( '-->', $sanitized, "Unsafe token survives for input: {$input}" );
			$this->assertDoesNotMatchRegularExpression( '/expression\s*\(/i', $sanitized, "Unsafe token survives for input: {$input}" );
			$this->assertDoesNotMatchRegularExpression( '/javascript\s*:/i', $sanitized, "Unsafe token survives for input: {$input}" );
			$this->assertDoesNotMatchRegularExpression( '/vbscript\s*:/i', $sanitized, "Unsafe token survives for input: {$input}" );
			$this->assertDoesNotMatchRegularExpression( '/behaviou?r/i', $sanitized, "Unsafe token survives for input: {$input}" );
			$this->assertDoesNotMatchRegularExpression( '/-moz-binding/i', $sanitized, "Unsafe token survives for input: {$input}" );
			$this->assertDoesNotMatchRegularExpression( '/</', $sanitized, "Raw angle bracket survives for input: {$input}" );
		}
	}

	/**
	 * Numeric/hex-entity payloads (issue #967 regression test): encoded
	 * angle brackets are decoded first, then neutralized — no entity
	 * remnant or executable token survives.
	 *
	 * @return void
	 */
	public function test_sanitize_inline_css_neutralizes_numeric_entity_payloads(): void {
		$vectors = array(
			'.x{content:"a"}/*&#60;script&#62;alert(1)&#60;/script&#62;*/',
			'.x{content:"a"}/*&#x3c;script&#x3e;alert(1)&#x3c;/script&#x3e;*/',
			'.x{content:"a"}/*&#X3C;SCRIPT&#X3E;alert(1)*/',
			'.x{content:"a"}/*&lt;script&gt;alert(1)&lt;/script&gt;*/',
			'.x{content:"a"}/*&amp;lt;script&amp;gt;alert(1)*/',
			'.x{content:"a"}/*&amp;amp;lt;script&amp;amp;gt;alert(1)*/',
		);

		foreach ( $vectors as $input ) {
			$sanitized = $this->invoke_private( 'sanitize_inline_css', $input );

			$this->assertDoesNotMatchRegularExpression( '/<\/style/i', $sanitized, "Unsafe token survives for input: {$input}" );
			$this->assertDoesNotMatchRegularExpression( '/<script/i', $sanitized, "Unsafe token survives for input: {$input}" );
			$this->assertDoesNotMatchRegularExpression( '/</', $sanitized, "Raw angle bracket survives for input: {$input}" );
			$this->assertStringNotContainsString( '&#', $sanitized, "Entity remnant survives for input: {$input}" );
			$this->assertStringNotContainsString( '&lt', $sanitized, "Entity remnant survives for input: {$input}" );
			$this->assertStringNotContainsString( '&gt', $sanitized, "Entity remnant survives for input: {$input}" );
		}
	}

	/**
	 * The sanitizer neutralizes breakout tokens even when no
	 * wppo_ccss_sanitize_inline listener is registered (has_filter guard).
	 *
	 * @return void
	 */
	public function test_sanitize_inline_css_neutralizes_without_filter_listener(): void {
		Functions\when( 'has_filter' )->justReturn( false );

		$sanitized = $this->invoke_private( 'sanitize_inline_css', '.x{}/*</STYLE><SCRIPT>alert(1)</SCRIPT><!--*/' );

		$this->assertDoesNotMatchRegularExpression( '/<\/style/i', $sanitized );
		$this->assertDoesNotMatchRegularExpression( '/<script/i', $sanitized );
		$this->assertStringNotContainsString( '<!--', $sanitized );
	}

	/**
	 * When a wppo_ccss_sanitize_inline listener exists, its return value is
	 * re-sanitized (guarded apply_filters path): benign values pass through
	 * while hostile tokens reintroduced by hooked code are neutralized.
	 *
	 * @return void
	 */
	public function test_sanitize_inline_css_applies_filter_when_listener_exists(): void {
		Functions\when( 'has_filter' )->justReturn( true );
		$this->filter_overrides['wppo_ccss_sanitize_inline'] = 'body{color:red}';

		$sanitized = $this->invoke_private( 'sanitize_inline_css', '.x{}/*</style>*/' );

		$this->assertSame( 'body{color:red}', $sanitized );
	}

	/**
	 * A hostile wppo_ccss_sanitize_inline return value is re-sanitized so
	 * hooked code cannot reintroduce breakout tokens past the sanitizer.
	 *
	 * @return void
	 */
	public function test_sanitize_inline_css_resanitizes_hostile_filter_output(): void {
		Functions\when( 'has_filter' )->justReturn( true );
		$this->filter_overrides['wppo_ccss_sanitize_inline'] = '.x{}/*</style><script>alert(1)</script>*/';

		$sanitized = $this->invoke_private( 'sanitize_inline_css', 'body{color:red}' );

		$this->assertDoesNotMatchRegularExpression( '/<\/style/i', $sanitized );
		$this->assertDoesNotMatchRegularExpression( '/<script/i', $sanitized );
		$this->assertDoesNotMatchRegularExpression( '/</', $sanitized );
	}

	/**
	 * Benign selectors containing behavior/behaviour substrings (e.g.
	 * .behavior-badge) survive sanitization and pass the pre-cache gate:
	 * only property-position occurrences (followed by a colon) are
	 * neutralized or flagged.
	 *
	 * @return void
	 */
	public function test_behavior_substring_selectors_survive_sanitizer_and_gate(): void {
		$css = '.behavior-badge{color:red}#behaviour-list{margin:0}';

		$this->assertSame( $css, $this->invoke_private( 'sanitize_inline_css', $css ) );
		$this->assertFalse( $this->invoke_private( 'contains_unsafe_css_tokens', $css ) );
		$this->assertFalse( $this->invoke_private( 'contains_unsafe_css_tokens', '.behavior-chart{display:block}' ) );
		$this->assertTrue( $this->invoke_private( 'contains_unsafe_css_tokens', '.x{behavior:url(x.htc)}' ) );
	}

	/**
	 * Modern hyphenated properties that merely end in `behavior` are not
	 * the legacy IE vector. `scroll-behavior` previously tripped the
	 * pre-cache gate, so every template failed closed and critical CSS was
	 * silently disabled on any site using it; the sanitizer would also
	 * have rewritten it into invalid CSS.
	 *
	 * @return void
	 */
	public function test_scroll_behavior_is_not_treated_as_the_legacy_vector(): void {
		$css = 'html{scroll-behavior:smooth;scroll-padding-top:6rem}';

		// Passes the gate and survives sanitization byte-for-byte.
		$this->assertFalse( $this->invoke_private( 'contains_unsafe_css_tokens', $css ) );
		$this->assertSame( $css, $this->invoke_private( 'sanitize_inline_css', $css ) );

		// A custom property with the same suffix is equally benign.
		$custom = ':root{--scroll-behavior:smooth}';
		$this->assertFalse( $this->invoke_private( 'contains_unsafe_css_tokens', $custom ) );
		$this->assertSame( $custom, $this->invoke_private( 'sanitize_inline_css', $custom ) );

		// The real vector is still caught, in both spellings.
		$this->assertTrue( $this->invoke_private( 'contains_unsafe_css_tokens', '.x{behavior:url(x.htc)}' ) );
		$this->assertTrue( $this->invoke_private( 'contains_unsafe_css_tokens', '.x{behaviour:url(x.htc)}' ) );
		$this->assertTrue( $this->invoke_private( 'contains_unsafe_css_tokens', '.x{;behavior:url(x.htc)}' ) );
	}

	/**
	 * The pre-cache gate (issue #967) flags hostile vectors decoded from
	 * entities, and passes clean CSS through.
	 *
	 * @return void
	 */
	public function test_contains_unsafe_css_tokens_flags_hostile_vectors(): void {
		$hostile = array(
			'.x{}/*</style>*/',
			'.x{}/*<ScRiPt>alert(1)*/',
			'.x{}/*<!--*/',
			'.x{}/*-->*/',
			'.x{width:expression(alert(1))}',
			'.x{background:url(javascript:alert(1))}',
			'.x{background:url(vbscript:x)}',
			'.x{behavior:url(x.htc)}',
			'.x{-moz-binding:url(x.xml)}',
			'.x{}/*&#60;script&#62;*/',
			'.x{}/*&#x3c;script&#x3e;*/',
			'.x{}/*&lt;script&gt;*/',
		);

		foreach ( $hostile as $input ) {
			$this->assertTrue( $this->invoke_private( 'contains_unsafe_css_tokens', $input ), "Gate missed hostile input: {$input}" );
		}

		$this->assertFalse( $this->invoke_private( 'contains_unsafe_css_tokens', 'body{color:#fff;background:url(a.png)}' ) );
		$this->assertFalse( $this->invoke_private( 'contains_unsafe_css_tokens', '.a:before{content:"<b>"}' ) );
	}

	/**
	 * Under-cap CSS passes through truncate_to_cap() byte-for-byte.
	 *
	 * @return void
	 */
	public function test_truncate_to_cap_passes_under_cap_through(): void {
		$css = 'body{color:red}h1{font-size:2em}';

		$this->assertSame( $css, Critical_CSS::truncate_to_cap( $css, 20480 ) );
		$this->assertSame( $css, Critical_CSS::truncate_to_cap( $css, strlen( $css ) ) );
	}

	/**
	 * Over-cap CSS is cut at the last closing brace within the cap, never mid-rule.
	 *
	 * @return void
	 */
	public function test_truncate_to_cap_cuts_at_last_rule_boundary(): void {
		$css = 'body{color:red}h1{font-size:2em}p{margin:0}';
		$cap = strlen( 'body{color:red}' ) + 5; // Lands inside the h1 rule.

		$truncated = Critical_CSS::truncate_to_cap( $css, $cap );

		$this->assertSame( 'body{color:red}', $truncated );
		$this->assertLessThanOrEqual( $cap, strlen( $truncated ) );
	}

	/**
	 * Returns '' when no complete rule fits in the cap.
	 *
	 * Callers treat that as over-cap and serve the file variant instead.
	 *
	 * @return void
	 */
	public function test_truncate_to_cap_returns_empty_when_nothing_fits(): void {
		$this->assertSame( '', Critical_CSS::truncate_to_cap( 'body{color:red}', 5 ) );
		$this->assertSame( '', Critical_CSS::truncate_to_cap( '', 20480 ) );
	}

	/**
	 * The cap defaults to 20 KB and honours the stored setting.
	 *
	 * @return void
	 */
	public function test_get_ccss_max_size_default_and_override(): void {
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		$this->assertSame( 20480, Critical_CSS::get_ccss_max_size() );

		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'ccssMaxSize' => 1024 ),
		);
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		$this->assertSame( 1024, Critical_CSS::get_ccss_max_size() );

		// Non-positive values fall back to the default so output stays bounded.
		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'ccssMaxSize' => 0 ),
		);
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		$this->assertSame( 20480, Critical_CSS::get_ccss_max_size() );
	}

	/**
	 * Missing variants report zero size metadata (fail-open, never fatal).
	 *
	 * @return void
	 */
	public function test_get_ccss_meta_reports_zeros_for_missing_variant(): void {
		$this->assertSame(
			array(
				'size'      => 0,
				'truncated' => false,
				'mtime'     => 0,
			),
			Critical_CSS::get_ccss_meta( 'ccss-missing-variant-123' )
		);
	}

	/**
	 * A stored variant reports its size and whether it exceeds the cap.
	 *
	 * @return void
	 */
	public function test_get_ccss_meta_reports_size_and_truncation(): void {
		$hash = 'ccssmetatest1234567890abcdef';
		$dir  = wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
			mkdir( $dir, 0775, true );
		}
		$file = $dir . '/' . $hash . '.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $file, 'body{color:red}' );

		try {
			$meta = Critical_CSS::get_ccss_meta( $hash );

			$this->assertSame( strlen( 'body{color:red}' ), $meta['size'] );
			$this->assertFalse( $meta['truncated'] );
			$this->assertGreaterThan( 0, $meta['mtime'] );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			unlink( $file );
		}
	}

	/**
	 * File-first URLs carry mtime cache busting that changes on regenerate.
	 *
	 * @return void
	 */
	public function test_get_ccss_file_url_uses_mtime_cache_busting(): void {
		$hash = 'ccssmtimebust1234567890abcdef';
		$dir  = wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
			mkdir( $dir, 0775, true );
		}
		$file = $dir . '/' . $hash . '.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $file, 'body{color:red}' );

		try {
			$url_before = $this->invoke_private( 'get_ccss_file_url', $hash );

			$this->assertStringContainsString( $hash . '.css?ver=', $url_before );

			// Simulate a regenerate: new mtime must produce a new URL.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Test fixture mtime control.
			touch( $file, time() + 3600 );
			clearstatcache( true, $file );
			Critical_CSS::reset_ccss_memo();

			$url_after = $this->invoke_private( 'get_ccss_file_url', $hash );

			$this->assertNotSame( $url_before, $url_after );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			unlink( $file );
		}
	}

	/**
	 * Missing file variants yield an empty file URL.
	 *
	 * @return void
	 */
	public function test_get_ccss_file_url_empty_for_missing_variant(): void {
		$this->assertSame( '', $this->invoke_private( 'get_ccss_file_url', 'ccss-no-such-variant-123' ) );
	}

	/**
	 * A falsy wppo_inline_combined_css filter yields no inline output.
	 *
	 * @return void
	 */
	public function test_falsy_inline_filter_skips_inline_ccss(): void {
		$this->filter_overrides['wppo_inline_combined_css'] = false;

		ob_start();
		Critical_CSS::inline_ccss();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * A falsy wppo_inline_combined_css filter leaves stylesheet tags untouched.
	 *
	 * @return void
	 */
	public function test_falsy_inline_filter_skips_deferral(): void {
		$this->filter_overrides['wppo_inline_combined_css'] = false;

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Fixture tag for the deferral filter.
		$tag = '<link rel="stylesheet" id="theme-style-css" href="http://example.com/theme.css" media="all" />';

		$this->assertSame( $tag, Critical_CSS::defer_stylesheets( $tag, 'theme-style', 'http://example.com/theme.css' ) );
	}

	/**
	 * Missing template variants fail open: stylesheets load normally.
	 *
	 * @return void
	 */
	public function test_missing_variant_fallback_loads_stylesheet_normally(): void {
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Fixture tag for the deferral filter.
		$tag = '<link rel="stylesheet" id="theme-style-css" href="http://example.com/theme.css" media="all" />';

		$this->assertSame( $tag, Critical_CSS::defer_stylesheets( $tag, 'theme-style', 'http://example.com/theme.css' ) );
	}

	/**
	 * Stub file_optimisation settings for the deferJS/delayJS suspension guard.
	 *
	 * @param array $file_optimisation File optimisation settings.
	 * @return void
	 */
	private function stub_file_optimisation( array $file_optimisation ): void {
		$this->option_map['wppo_settings'] = array( 'file_optimisation' => $file_optimisation );
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
	}

	/**
	 * Deferred JS suspends deferral: tags load normally (issue #1090).
	 *
	 * @return void
	 */
	public function test_defer_js_suspends_deferral(): void {
		$this->stub_file_optimisation( array( 'deferJS' => true ) );
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Fixture tag for the deferral filter.
		$tag = '<link rel="stylesheet" id="theme-style-css" href="http://example.com/theme.css" media="all" />';

		$this->assertSame( $tag, Critical_CSS::defer_stylesheets( $tag, 'theme-style', 'http://example.com/theme.css' ) );
	}

	/**
	 * Delayed JS suspends deferral: tags load normally (issue #1090).
	 *
	 * @return void
	 */
	public function test_delay_js_suspends_deferral(): void {
		$this->stub_file_optimisation( array( 'delayJS' => true ) );
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Fixture tag for the deferral filter.
		$tag = '<link rel="stylesheet" id="theme-style-css" href="http://example.com/theme.css" media="all" />';

		$this->assertSame( $tag, Critical_CSS::defer_stylesheets( $tag, 'theme-style', 'http://example.com/theme.css' ) );
	}

	/**
	 * Deferred JS suspends emission: no output and no queueing (issue #1090).
	 *
	 * @return void
	 */
	public function test_defer_js_suspends_inline_ccss(): void {
		$this->stub_file_optimisation( array( 'deferJS' => true ) );

		ob_start();
		Critical_CSS::inline_ccss();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Delayed JS suspends emission: no output and no queueing (issue #1090).
	 *
	 * @return void
	 */
	public function test_delay_js_suspends_inline_ccss(): void {
		$this->stub_file_optimisation( array( 'delayJS' => true ) );

		ob_start();
		Critical_CSS::inline_ccss();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * The shared predicate reports ineffective CCSS under deferred JS (issue #1090).
	 *
	 * @return void
	 */
	public function test_is_ccss_effective_false_when_defer_js(): void {
		$this->stub_file_optimisation( array( 'deferJS' => true ) );

		$this->assertFalse( Critical_CSS::is_ccss_effective() );
		$this->assertTrue( Critical_CSS::is_deferral_suspended_by_js() );
	}

	/**
	 * The shared predicate stays effective without deferred/delayed JS.
	 *
	 * @return void
	 */
	public function test_is_ccss_effective_true_without_defer_or_delay_js(): void {
		$this->stub_file_optimisation( array() );

		$this->assertTrue( Critical_CSS::is_ccss_effective() );
		$this->assertFalse( Critical_CSS::is_deferral_suspended_by_js() );
	}

	/**
	 * Suspended regeneration queues nothing and preserves variants (issue #1090).
	 *
	 * @return void
	 */
	public function test_regenerate_all_suspended_returns_zero(): void {
		$this->stub_file_optimisation( array( 'delayJS' => true ) );

		$this->assertSame( 0, Critical_CSS::regenerate_all() );
	}

	/**
	 * Suspended freshness probe does no work and reports no staleness (issue #1090).
	 *
	 * @return void
	 */
	public function test_stale_probe_suspended_under_defer_js(): void {
		$this->stub_file_optimisation( array( 'deferJS' => true ) );

		$this->assertFalse( Critical_CSS::maybe_check_stale_and_requeue( 'some-template-hash' ) );
	}

	/**
	 * The generation budget defaults to 25s and honours the stored setting.
	 *
	 * @return void
	 */
	public function test_get_ccss_gen_timeout_default_and_override(): void {
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		$this->assertSame( 25, Critical_CSS::get_ccss_gen_timeout() );

		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'ccssGenTimeout' => 10 ),
		);
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		$this->assertSame( 10, Critical_CSS::get_ccss_gen_timeout() );

		// Non-positive / non-numeric values fall back to the default so
		// generation is always bounded (never uncapped).
		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'ccssGenTimeout' => 0 ),
		);
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		$this->assertSame( 25, Critical_CSS::get_ccss_gen_timeout() );

		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'ccssGenTimeout' => 'not-a-number' ),
		);
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		$this->assertSame( 25, Critical_CSS::get_ccss_gen_timeout() );

		// Oversized values clamp to the hard upper bound.
		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'ccssGenTimeout' => 500 ),
		);
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		$this->assertSame( 120, Critical_CSS::get_ccss_gen_timeout() );
	}

	/**
	 * The wppo_ccss_generation_timeout filter overrides the stored budget.
	 *
	 * @return void
	 */
	public function test_get_ccss_gen_timeout_applies_filter_when_listener_exists(): void {
		Functions\when( 'has_filter' )->alias(
			static function ( $tag ) {
				return 'wppo_ccss_generation_timeout' === $tag;
			}
		);
		$this->filter_overrides['wppo_ccss_generation_timeout'] = 7;
		\PerformanceOptimise\Inc\Util::clear_settings_cache();

		$this->assertSame( 7, Critical_CSS::get_ccss_gen_timeout() );
	}

	/**
	 * An already-expired budget aborts generation before any HTTP request.
	 *
	 * @return void
	 */
	public function test_generate_aborts_on_expired_deadline_without_http(): void {
		$source_css    = null;
		$resolved_urls = null;
		$expired       = microtime( true ) - 5;

		$result = Critical_CSS::generate( 'http://example.com/', $source_css, $resolved_urls, $expired );

		$this->assertFalse( $result );
		$this->assertSame( 0, $this->http_calls['regular'] );
		$this->assertSame( 0, $this->http_calls['safe'] );
	}

	/**
	 * An expired budget refuses stylesheet fetches without any HTTP request.
	 *
	 * @return void
	 */
	public function test_fetch_aborts_on_expired_deadline_without_http(): void {
		$expired = microtime( true ) - 5;

		$result = $this->invoke_private( 'fetch_stylesheet_with_imports', 'http://example.com/style.css', 0, $expired );

		$this->assertSame( '', $result );
		$this->assertSame( 0, $this->http_calls['regular'] );
		$this->assertSame( 0, $this->http_calls['safe'] );
	}

	/**
	 * An expired budget skips the CPU-bound parse phase entirely.
	 *
	 * @return void
	 */
	public function test_extract_aborts_on_expired_deadline(): void {
		$expired = microtime( true ) - 5;

		$this->assertSame( '', $this->invoke_private( 'extract_above_fold_css', 'body{color:red}', $expired ) );
	}

	/**
	 * Per-request timeouts clamp to the remaining budget (issue #1235).
	 *
	 * @return void
	 */
	public function test_fetch_clamps_request_timeout_to_remaining_budget(): void {
		$seen_timeout = null;
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url, $args = array() ) use ( &$seen_timeout ) {
				++$this->http_calls['regular'];
				$seen_timeout = $args['timeout'] ?? null;
				return $this->fake_response();
			}
		);

		$this->invoke_private( 'fetch_stylesheet_with_imports', 'http://example.com/style.css', 0, microtime( true ) + 3 );

		// Floor semantics: the socket timeout never exceeds the budget.
		$this->assertGreaterThanOrEqual( 1, $seen_timeout );
		$this->assertLessThanOrEqual( 3, $seen_timeout );
	}

	/**
	 * An exhausted budget returns a zero request timeout so callers skip the fetch.
	 *
	 * @return void
	 */
	public function test_request_timeout_returns_zero_when_budget_exhausted(): void {
		$expired = microtime( true ) - 5;

		$this->assertSame( 0, $this->invoke_private( 'request_timeout_for_deadline', $expired, 15 ) );
		$this->assertSame( 15, $this->invoke_private( 'request_timeout_for_deadline', null, 15 ) );
	}

	/**
	 * Oversized / non-numeric filter values clamp to the hard upper bound.
	 *
	 * @return void
	 */
	public function test_get_ccss_gen_timeout_clamps_oversized_filter_value(): void {
		Functions\when( 'has_filter' )->alias(
			static function ( $tag ) {
				return 'wppo_ccss_generation_timeout' === $tag;
			}
		);
		$this->filter_overrides['wppo_ccss_generation_timeout'] = 500;
		\PerformanceOptimise\Inc\Util::clear_settings_cache();

		$this->assertSame( 120, Critical_CSS::get_ccss_gen_timeout() );

		$this->filter_overrides['wppo_ccss_generation_timeout'] = 'not-a-number';

		$this->assertSame( 25, Critical_CSS::get_ccss_gen_timeout() );
	}

	/**
	 * Malformed scheduler hashes are rejected without touching the worker.
	 *
	 * @return void
	 */
	public function test_background_generate_rejects_malformed_hash(): void {
		Critical_CSS::background_generate( array( 'template_hash' => array( 'not', 'a', 'string' ) ) );
		Critical_CSS::background_generate( array( 'template_hash' => '../../etc' ) );

		$this->assertSame( 0, $this->http_calls['regular'] );
		$this->assertSame( 0, $this->http_calls['safe'] );
	}

	/**
	 * The guarded wrapper fails open on the live-budget missing-URL path:
	 * no sample URL means deterministic `failed` (never pending/retry) with
	 * no file and no HTTP request.
	 *
	 * @return void
	 */
	public function test_generate_guarded_no_sample_url_creates_no_file(): void {
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);

		$hash = 'ccssguardednosample1234567890a';
		$dir  = wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' );
		$file = $dir . '/' . $hash . '.css';
		if ( file_exists( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Stale fixture cleanup.
			unlink( $file );
		}
		Critical_CSS::reset_ccss_memo();

		// 'single' has no posts in this fixture, so no sample URL exists
		// and generation fails open without any HTTP request.
		$timed_out = null;
		$result    = Critical_CSS::generate_guarded( $hash, 'single', $timed_out );

		$this->assertFalse( $result );
		$this->assertFalse( $timed_out );
		$this->assertSame( 0, $this->http_calls['regular'] );
		$this->assertSame( 0, $this->http_calls['safe'] );
		$this->assertFileDoesNotExist( $file );
	}

	/**
	 * A timed-out run stores nothing and leaves a previous file untouched.
	 *
	 * @return void
	 */
	public function test_generate_and_store_timeout_stores_nothing_and_keeps_previous_file(): void {
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);

		$hash = 'ccsstimeouttest1234567890abcd';
		$dir  = wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
			mkdir( $dir, 0775, true );
		}
		$file     = $dir . '/' . $hash . '.css';
		$previous = 'body{color:blue}';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $file, $previous );

		try {
			$expired = microtime( true ) - 5;
			$result  = $this->invoke_private( 'generate_and_store', $hash, 'home', $expired );

			$this->assertFalse( $result );
			$this->assertSame( 0, $this->http_calls['regular'] );
			$this->assertSame( 0, $this->http_calls['safe'] );

			// Fail-open: the previous file is byte-identical, no partial output.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture assertion.
			$this->assertSame( $previous, file_get_contents( $file ) );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			unlink( $file );
		}
	}

	/**
	 * A timed-out run without a previous file creates none (fail-open).
	 *
	 * @return void
	 */
	public function test_generate_and_store_timeout_creates_no_file(): void {
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);

		$hash = 'ccsstimeoutnofile1234567890ab';
		$dir  = wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' );
		$file = $dir . '/' . $hash . '.css';
		if ( file_exists( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Stale fixture cleanup.
			unlink( $file );
		}
		Critical_CSS::reset_ccss_memo();

		$expired = microtime( true ) - 5;
		$result  = $this->invoke_private( 'generate_and_store', $hash, 'home', $expired );

		$this->assertFalse( $result );
		$this->assertFileDoesNotExist( $file );
	}

	/**
	 * Normal sources generate unchanged output under a live budget.
	 *
	 * @return void
	 */
	public function test_generate_happy_path_unchanged_under_live_budget(): void {
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url ) {
				++$this->http_calls['regular'];
				$body = false !== strpos( (string) $url, 's.css' )
					? 'body{color:red}h1{color:blue}'
					// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Fixture markup for the generator fetch stub.
					: '<html><head><style>body{color:red}</style><link rel="stylesheet" href="http://example.com/s.css"></head><body><p>hi</p></body></html>';
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => $body,
				);
			}
		);

		$source_css    = null;
		$resolved_urls = null;

		$result = Critical_CSS::generate( 'http://example.com/', $source_css, $resolved_urls, microtime( true ) + 30 );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'body', (string) $result );
		$this->assertGreaterThan( 0, $this->http_calls['regular'] );
	}

	/**
	 * The per-run queue cap defaults to 5 and honours the stored setting.
	 *
	 * @return void
	 */
	public function test_get_css_queue_cap_default_and_override(): void {
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		$this->assertSame( 5, Critical_CSS::get_css_queue_cap() );
		$this->assertSame( 5, Critical_CSS::get_ccss_queue_cap() );

		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'ccssQueueCap' => 3 ),
		);
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		$this->assertSame( 3, Critical_CSS::get_css_queue_cap() );

		// Non-numeric / non-positive stored values fail open to uncapped.
		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'ccssQueueCap' => 0 ),
		);
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		$this->assertSame( PHP_INT_MAX, Critical_CSS::get_css_queue_cap() );

		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'ccssQueueCap' => 'not-a-number' ),
		);
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		$this->assertSame( PHP_INT_MAX, Critical_CSS::get_css_queue_cap() );

		// Oversized values clamp to the hard upper bound.
		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'ccssQueueCap' => 500 ),
		);
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		$this->assertSame( 100, Critical_CSS::get_css_queue_cap() );
	}

	/**
	 * The wppo_ccss_queue_cap filter overrides the stored cap (issue #1255).
	 *
	 * @return void
	 */
	public function test_get_css_queue_cap_applies_filter_when_listener_exists(): void {
		Functions\when( 'has_filter' )->alias(
			static function ( $tag ) {
				return 'wppo_ccss_queue_cap' === $tag;
			}
		);
		$this->filter_overrides['wppo_ccss_queue_cap'] = 10;
		\PerformanceOptimise\Inc\Util::clear_settings_cache();

		$this->assertSame( 10, Critical_CSS::get_css_queue_cap() );
		$this->assertSame( 10, Critical_CSS::get_ccss_queue_cap() );
	}

	/**
	 * Oversized / non-numeric filter values clamp to the bound or keep stored.
	 *
	 * @return void
	 */
	public function test_get_css_queue_cap_clamps_oversized_filter_value(): void {
		Functions\when( 'has_filter' )->alias(
			static function ( $tag ) {
				return 'wppo_ccss_queue_cap' === $tag;
			}
		);
		$this->filter_overrides['wppo_ccss_queue_cap'] = 500;
		\PerformanceOptimise\Inc\Util::clear_settings_cache();

		$this->assertSame( 100, Critical_CSS::get_css_queue_cap() );

		$this->filter_overrides['wppo_ccss_queue_cap'] = 'not-a-number';

		$this->assertSame( 5, Critical_CSS::get_css_queue_cap() );
	}

	/**
	 * Seed a field-LCP aggregate fixture for one page path.
	 *
	 * @param string $path      Page path bucket (e.g. '/slow-page').
	 * @param string $url       LCP image URL.
	 * @param int    $n         Sample count.
	 * @param int    $last_seen Last-seen timestamp.
	 * @return void
	 */
	private function seed_field_lcp_fixture( string $path, string $url, int $n, int $last_seen ): void {
		$today                           = gmdate( 'Y-m-d' );
		$this->option_map[ RUM::OPTION ] = array(
			$today => array(
				$path => array(
					'lcpUrls' => array(
						'fixture-hero' => array(
							'url'      => $url,
							'n'        => $n,
							'lastSeen' => $last_seen,
						),
					),
				),
			),
		);
		RUM::clear_field_lcp_cache();
	}

	/**
	 * Fresh field data above the sample gate wins the CCSS preload target.
	 *
	 * @return void
	 */
	public function test_get_field_lcp_preload_url_returns_field_candidate(): void {
		$this->seed_field_lcp_fixture(
			'/slow-page',
			'https://example.com/wp-content/uploads/hero.jpg',
			20,
			time()
		);

		$this->assertSame(
			'https://example.com/wp-content/uploads/hero.jpg',
			Critical_CSS::get_field_lcp_preload_url( 'http://example.com/slow-page/' )
		);
	}

	/**
	 * Under-sampled or stale field data falls back (no field URL wins).
	 *
	 * @return void
	 */
	public function test_get_field_lcp_preload_url_falls_back_when_under_sampled_or_stale(): void {
		// Under the 20-sample gate: heuristic fallback (empty in this fixture).
		$this->seed_field_lcp_fixture(
			'/slow-page',
			'https://example.com/wp-content/uploads/hero.jpg',
			3,
			time()
		);

		$this->assertSame( '', Critical_CSS::get_field_lcp_preload_url( 'http://example.com/slow-page/' ) );

		// Stale (>24h): self-corrects back to the heuristic.
		$this->seed_field_lcp_fixture(
			'/slow-page',
			'https://example.com/wp-content/uploads/hero.jpg',
			25,
			time() - ( 2 * DAY_IN_SECONDS )
		);

		$this->assertSame( '', Critical_CSS::get_field_lcp_preload_url( 'http://example.com/slow-page/' ) );
	}

	/**
	 * Cross-origin candidates and empty/unknown URLs resolve to nothing.
	 *
	 * @return void
	 */
	public function test_get_field_lcp_preload_url_rejects_cross_origin_and_empty(): void {
		$this->seed_field_lcp_fixture(
			'/slow-page',
			'https://evil.example/wp-content/uploads/hero.jpg',
			25,
			time()
		);

		$this->assertSame( '', Critical_CSS::get_field_lcp_preload_url( 'http://example.com/slow-page/' ) );
		$this->assertSame( '', Critical_CSS::get_field_lcp_preload_url( '' ) );
		$this->assertSame( '', Critical_CSS::get_field_lcp_preload_url( 'http://example.com/unmeasured-path-xyz/' ) );
		RUM::clear_field_lcp_cache();
	}

	/**
	 * Write a CCSS fixture file for the current (home) template hash.
	 *
	 * @param string $content File content.
	 * @return string Template hash.
	 */
	private function write_home_ccss_fixture( string $content ): string {
		$hash = Critical_CSS::get_template_hash( 'home' );
		$dir  = wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
			mkdir( $dir, 0775, true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $dir . '/' . $hash . '.css', $content );
		Critical_CSS::reset_ccss_memo();

		return $hash;
	}

	/**
	 * Over-cap CCSS serves the file variant, never a truncated inline block.
	 *
	 * @return void
	 */
	public function test_inline_ccss_over_cap_serves_file_link_without_truncated_inline(): void {
		$hash = $this->write_home_ccss_fixture( str_repeat( 'body.a{color:red}', 2000 ) );

		try {
			ob_start();
			Critical_CSS::inline_ccss();
			$output = ob_get_clean();

			$this->assertStringContainsString( 'id="wppo-critical-css"', $output );
			$this->assertStringContainsString( $hash . '.css?ver=', $output );
			$this->assertStringNotContainsString( 'data-truncated', $output );
			$this->assertStringNotContainsString( '<style', $output );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			unlink( wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' ) . '/' . $hash . '.css' );
			Critical_CSS::reset_ccss_memo();
		}
	}

	/**
	 * Under-cap CCSS still inlines normally (emission wiring is a no-op here).
	 *
	 * @return void
	 */
	public function test_inline_ccss_under_cap_inlines_style(): void {
		$hash = $this->write_home_ccss_fixture( str_repeat( 'body.a{color:red}', 40 ) );

		try {
			ob_start();
			Critical_CSS::inline_ccss();
			$output = ob_get_clean();

			$this->assertStringContainsString( '<style id="wppo-critical-css">', $output );
			$this->assertStringNotContainsString( 'data-truncated', $output );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			unlink( wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' ) . '/' . $hash . '.css' );
			Critical_CSS::reset_ccss_memo();
		}
	}

	/**
	 * An explicit URL on another host never resolves this site's RUM bucket.
	 *
	 * Only the path component of an explicit URL is used (buckets are
	 * per-site), so a cross-host URL is rejected instead of reading the
	 * local bucket for that path (issue #1255 review).
	 *
	 * @return void
	 */
	public function test_get_field_lcp_preload_url_rejects_cross_host_explicit_url(): void {
		$this->seed_field_lcp_fixture(
			'/slow-page',
			'https://example.com/wp-content/uploads/hero.jpg',
			20,
			time()
		);

		// Same path, foreign host: must not read this site's bucket.
		$this->assertSame( '', Critical_CSS::get_field_lcp_preload_url( 'https://other.example/slow-page/' ) );
		// Same host still resolves.
		$this->assertSame(
			'https://example.com/wp-content/uploads/hero.jpg',
			Critical_CSS::get_field_lcp_preload_url( 'http://example.com/slow-page/' )
		);
	}

	/**
	 * A same-origin non-image candidate is never preloaded as an image.
	 *
	 * The stored PageSpeed tiers validate same-origin but never image-ness,
	 * so the CCSS text-LCP guard (mirroring the image pipeline) must reject
	 * e.g. a page URL recorded as the LCP element (issue #1255 review).
	 *
	 * @return void
	 */
	public function test_get_field_lcp_preload_url_rejects_non_image_candidate(): void {
		$this->seed_field_lcp_fixture(
			'/slow-page',
			'https://example.com/slow-page/',
			20,
			time()
		);

		$this->assertSame( '', Critical_CSS::get_field_lcp_preload_url( 'http://example.com/slow-page/' ) );
		RUM::clear_field_lcp_cache();
	}

	/**
	 * The shared preload dedup set round-trips per request.
	 *
	 * Both has/mark_preload_emitted() share one key space with the image
	 * pipeline's own dedup (issue #1255 review), and clear_runtime_caches()
	 * resets it for the next request.
	 *
	 * @return void
	 */
	public function test_shared_preload_dedup_roundtrip(): void {
		$url = 'https://example.com/wp-content/uploads/hero.jpg';

		$this->assertFalse( Image_Optimisation::has_emitted_preload( $url ) );

		Image_Optimisation::mark_preload_emitted( $url );

		$this->assertTrue( Image_Optimisation::has_emitted_preload( $url ) );
		// Media is part of the key: a different variant is still unclaimed.
		$this->assertFalse( Image_Optimisation::has_emitted_preload( $url, '(max-width: 768px)' ) );

		Image_Optimisation::clear_runtime_caches();

		$this->assertFalse( Image_Optimisation::has_emitted_preload( $url ) );
	}

	/**
	 * Stub the front-end URL helpers needed for current-path LCP resolution.
	 *
	 * Util::get_current_url() and Util::get_preload_link() call WP helpers
	 * this suite does not stub globally; these test-local stubs make the
	 * wp_head emission path exercisable without touching other tests.
	 *
	 * @return void
	 */
	private function stub_frontend_url_helpers(): void {
		Functions\when( 'add_query_arg' )->returnArg( 2 );
		Functions\when( 'untrailingslashit' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'wp_kses' )->returnArg();
		// generate_preload_link() echoes only in front-end HTML contexts:
		// pin the request guards so process-persisted stubs from earlier
		// suites (e.g. an AJAX-true stub) cannot silence the echo here.
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'wp_is_json_request' )->justReturn( false );
		$_SERVER['REQUEST_URI'] = '/';
	}

	/**
	 * The CCSS path emits the field hero once per request and claims it.
	 *
	 * With no image-pipeline auto-LCP toggle on, inline_ccss() prints one
	 * preload hint for the field-measured hero and a repeated call prints
	 * no duplicate; the URL is also claimed in the pipeline's shared dedup
	 * set so the later wp_head:1 run skips it (issue #1255 review).
	 *
	 * @return void
	 */
	public function test_inline_ccss_emits_field_lcp_preload_once(): void {
		$this->stub_frontend_url_helpers();
		$this->seed_field_lcp_fixture(
			'/',
			'https://example.com/wp-content/uploads/hero.jpg',
			20,
			time()
		);
		$hash = $this->write_home_ccss_fixture( str_repeat( 'body.a{color:red}', 40 ) );

		try {
			ob_start();
			Critical_CSS::inline_ccss();
			Critical_CSS::inline_ccss();
			$output = ob_get_clean();

			$this->assertSame( 1, substr_count( $output, 'rel="preload"' ) );
			$this->assertStringContainsString( 'https://example.com/wp-content/uploads/hero.jpg', $output );
			$this->assertStringContainsString( '<style id="wppo-critical-css">', $output );
			$this->assertTrue(
				Image_Optimisation::has_emitted_preload( 'https://example.com/wp-content/uploads/hero.jpg' )
			);
		} finally {
			unset( $_SERVER['REQUEST_URI'] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			unlink( wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' ) . '/' . $hash . '.css' );
			Critical_CSS::reset_ccss_memo();
			Image_Optimisation::clear_runtime_caches();
		}
	}

	/**
	 * The CCSS path yields when the image pipeline owns LCP preloading.
	 *
	 * With autoPreloadLCP on, the pipeline's wp_head:1 hint (with responsive
	 * srcset/sizes) wins and the CCSS path prints no second hint for the
	 * same hero (issue #1255 review).
	 *
	 * @return void
	 */
	public function test_inline_ccss_skips_preload_when_image_pipeline_active(): void {
		$this->stub_frontend_url_helpers();
		$this->seed_field_lcp_fixture(
			'/',
			'https://example.com/wp-content/uploads/hero.jpg',
			20,
			time()
		);
		$this->option_map['wppo_settings'] = array(
			'image_optimisation' => array( 'autoPreloadLCP' => true ),
		);
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		$hash = $this->write_home_ccss_fixture( str_repeat( 'body.a{color:red}', 40 ) );

		try {
			ob_start();
			Critical_CSS::inline_ccss();
			$output = ob_get_clean();

			$this->assertStringNotContainsString( 'rel="preload"', $output );
			$this->assertStringContainsString( '<style id="wppo-critical-css">', $output );
		} finally {
			unset( $_SERVER['REQUEST_URI'] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			unlink( wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' ) . '/' . $hash . '.css' );
			Critical_CSS::reset_ccss_memo();
			\PerformanceOptimise\Inc\Util::clear_settings_cache();
		}
	}

	/**
	 * The wppo_ccss_field_lcp_preload filter opts out of the CCSS-path hint.
	 *
	 * @return void
	 */
	public function test_inline_ccss_skips_preload_when_filter_opts_out(): void {
		$this->stub_frontend_url_helpers();
		$this->seed_field_lcp_fixture(
			'/',
			'https://example.com/wp-content/uploads/hero.jpg',
			20,
			time()
		);
		Functions\when( 'has_filter' )->alias(
			static function ( $tag ) {
				return 'wppo_ccss_field_lcp_preload' === $tag;
			}
		);
		$this->filter_overrides['wppo_ccss_field_lcp_preload'] = false;
		$hash = $this->write_home_ccss_fixture( str_repeat( 'body.a{color:red}', 40 ) );

		try {
			ob_start();
			Critical_CSS::inline_ccss();
			$output = ob_get_clean();

			$this->assertStringNotContainsString( 'rel="preload"', $output );
			$this->assertStringContainsString( '<style id="wppo-critical-css">', $output );
		} finally {
			unset( $_SERVER['REQUEST_URI'] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			unlink( wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' ) . '/' . $hash . '.css' );
			Critical_CSS::reset_ccss_memo();
		}
	}

	/**
	 * Deferral is blocked for a template whose over-cap CCSS had no file URL.
	 *
	 * The inline_ccss() method records the template when over-cap output cannot be
	 * served from a file (issue #1255 review); defer_stylesheets() must then
	 * load the full stylesheets normally instead of deferring them with zero
	 * critical CSS on the page (FOUC guard).
	 *
	 * @return void
	 */
	public function test_defer_stylesheets_skips_when_ccss_file_url_unavailable(): void {
		$hash = Critical_CSS::get_template_hash();
		$prop = new ReflectionProperty( Critical_CSS::class, 'ccss_defer_blocked' );
		$prop->setValue( null, array( $hash => true ) );

		$tag    = '<link rel="stylesheet" href="http://example.com/wp-content/themes/test-theme/style.css" media="all" />'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Fixture markup for the defer guard stub.
		$result = Critical_CSS::defer_stylesheets( $tag, 'test-theme-style', 'http://example.com/wp-content/themes/test-theme/style.css' );

		Critical_CSS::reset_ccss_memo();

		$this->assertSame( $tag, $result );
	}
}
