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

		\PerformanceOptimise\Inc\Util::clear_settings_cache();

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
}
