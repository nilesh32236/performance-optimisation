<?php
/**
 * Tests for the Telemetry class redirect hardening (SSRF).
 *
 * Ensures scan URLs can never bounce the server into internal endpoints via
 * redirects: every hop is resolved and validated before the next request is
 * issued, with a maximum of two hops and timings taken from the final hop.
 *
 * @package PerformanceOptimise\Tests\PHP
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Telemetry;
use PHPUnit\Framework\Attributes\DataProvider;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Test fixtures are co-located by convention.
// phpcs:disable WordPress.Files.FileName -- Declares a minimal WP_Error stand-in required for instanceof checks in error-path tests.

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stand-in for tests that exercise plugin error paths.
	 *
	 * Mirrors the core API surface used by the plugin: code, message, data.
	 *
	 * @package PerformanceOptimise\Tests
	 */
	class WP_Error {

		/**
		 * Error codes mapped to messages.
		 *
		 * @var array<string, string>
		 */
		public array $errors = array();

		/**
		 * Error code => arbitrary data map.
		 *
		 * @var array<string, mixed>
		 */
		public array $error_data = array();

		/**
		 * Constructor.
		 *
		 * @param string|int $code    Error code.
		 * @param string     $message Error message.
		 * @param mixed      $data    Optional error data.
		 */
		public function __construct( $code = '', $message = '', $data = null ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
			if ( '' !== $code ) {
				$this->errors[ (string) $code ] = $message;
			}
			if ( null !== $data ) {
				$this->error_data[ (string) $code ] = $data;
			}
		}

		/**
		 * First error code, or empty string.
		 *
		 * @return string|int
		 */
		public function get_error_code() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
			return '' === key( $this->errors ) ? '' : (string) key( $this->errors );
		}

		/**
		 * First error message, or empty string.
		 *
		 * @return string
		 */
		public function get_error_message() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
			return (string) reset( $this->errors );
		}
	}
}

/**
 * Scriptable Telemetry subclass replacing real cURL execution with fixtures.
 *
 * Real cURL functions cannot be stubbed by Brain Monkey, so the transport
 * seam (Telemetry::execute_curl()) is overridden instead.
 */
class WPPO_Scriptable_Telemetry extends Telemetry {

	/**
	 * Scripted execute_curl() results keyed by request URL.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public static array $responses = array();

	/**
	 * URLs received by execute_curl(), in request order.
	 *
	 * @var string[]
	 */
	public static array $requests = array();

	/**
	 * Scripted wp_remote_get() responses keyed by request URL.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public static array $remote_responses = array();

	/**
	 * Reset fixture state between tests.
	 */
	public static function reset_fixtures(): void {
		self::$responses        = array();
		self::$requests         = array();
		self::$remote_responses = array();
	}

	/**
	 * Script a canned cURL response for a URL.
	 *
	 * @param string               $url     Request URL.
	 * @param int                  $status  HTTP status code.
	 * @param array<string,string> $headers Response headers.
	 * @param string               $body    Response body.
	 * @param array<string,float>  $timings Overrides for curl_getinfo() timing keys.
	 */
	public static function script_response( string $url, int $status, array $headers = array(), string $body = '', array $timings = array() ): void {
		$head = "HTTP/1.1 {$status} Test\r\n";
		foreach ( $headers as $name => $value ) {
			$head .= "{$name}: {$value}\r\n";
		}
		$head .= "\r\n";

		self::$responses[ $url ] = array(
			'raw_response' => $head . $body,
			'info'         => array_merge(
				array(
					'http_code'          => $status,
					'header_size'        => strlen( $head ),
					'namelookup_time'    => 0.01,
					'connect_time'       => 0.02,
					'appconnect_time'    => 0.0,
					'pretransfer_time'   => 0.03,
					'starttransfer_time' => 0.05,
					'total_time'         => 0.1,
				),
				$timings
			),
			'error'        => 0,
		);
	}

	/**
	 * Script a canned wp_remote_get() response for a URL.
	 *
	 * @param string               $url     Request URL.
	 * @param int                  $status  HTTP status code.
	 * @param array<string,string> $headers Response headers (lowercase names).
	 * @param string               $body    Response body.
	 */
	public static function script_remote_response( string $url, int $status, array $headers = array(), string $body = '' ): void {
		self::$remote_responses[ $url ] = array(
			'response' => array(
				'code'    => $status,
				'message' => 'Test',
			),
			'headers'  => $headers,
			'body'     => $body,
		);
	}

	/**
	 * Scripted stand-in for the real cURL execution.
	 *
	 * URLs without a scripted response fail with a transport error so tests
	 * can force the wp_remote_get() fallback path deterministically.
	 *
	 * @param string $url URL to request.
	 * @return array{raw_response: string|false, info: array<string, mixed>, error: int}
	 */
	protected static function execute_curl( string $url ): array {
		self::$requests[] = $url;

		if ( isset( self::$responses[ $url ] ) ) {
			return self::$responses[ $url ];
		}

		return array(
			'raw_response' => false,
			'info'         => array(),
			'error'        => 6, // CURLE_COULDNT_RESOLVE_HOST.
		);
	}
}

/**
 * Class TelemetryTest
 */
class TelemetryTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Calls recorded from the wp_remote_get() stub.
	 *
	 * @var array<int, array{url: string, args: array<string, mixed>}>
	 */
	private array $remote_calls = array();

	/**
	 * Setup.
	 *
	 * Declaring setUp() here overrides the trait's flattened setUp(), so the
	 * trait's stubs never register — parent::setUp() resolves to TestCase's
	 * no-op. Everything the scan path needs is registered explicitly below.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		\PerformanceOptimise\Inc\Util::reset_cached_home_urls();

		WPPO_Scriptable_Telemetry::reset_fixtures();
		$this->remote_calls = array();

		$this->stub_common_functions();
	}

	/**
	 * Teardown.
	 */
	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Register WP function stubs shared by all tests in this class.
	 */
	private function stub_common_functions(): void {
		$remote_calls = &$this->remote_calls;

		// Stubs the trait would normally provide (its setUp is overridden here).
		// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test alias for core wrapper.
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		// phpcs:enable WordPress.WP.AlternativeFunctions.parse_url_parse_url
		Functions\when( 'wp_normalize_path' )->alias(
			static fn( $path ) => '/' . ltrim( str_replace( '\\', '/', (string) $path ), '/' )
		);
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg();

		Functions\when( 'untrailingslashit' )->alias(
			static fn( $url ) => rtrim( (string) $url, '/' )
		);
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ) => $thing instanceof \WP_Error
		);

		// Minimal stand-in for core's wp_http_validate_url(): accepts
		// well-formed http/https URLs with a host, rejects everything else.
		// Internal/private hosts are deliberately NOT rejected here — that is
		// resolve_redirect()'s same-host rule under test.
		Functions\when( 'wp_http_validate_url' )->alias(
			static function ( $url ) {
				if ( ! is_string( $url ) || '' === $url || false === strpos( $url, '://' ) ) {
					return false;
				}
				$parsed = wp_parse_url( $url ); // Test double for core validation.
				if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
					return false;
				}
				if ( ! in_array( $parsed['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
					return false;
				}
				return $url;
			}
		);

		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url, $args = array() ) use ( &$remote_calls ) {
				$remote_calls[] = array(
					'url'  => $url,
					'args' => $args,
				);

				// robots.txt lookups always miss so scans stay hermetic.
				if ( false !== strpos( (string) $url, 'robots.txt' ) ) {
					return array(
						'response' => array(
							'code'    => 404,
							'message' => 'Not Found',
						),
						'headers'  => array(),
						'body'     => '',
					);
				}

				return WPPO_Scriptable_Telemetry::$remote_responses[ $url ] ?? array(
					'response' => array(
						'code'    => 404,
						'message' => 'Not Found',
					),
					'headers'  => array(),
					'body'     => '',
				);
			}
		);

		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $response ) => (int) ( $response['response']['code'] ?? 0 )
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			static fn( $response, $name ) => (string) ( $response['headers'][ strtolower( (string) $name ) ] ?? '' )
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn( $response ) => (string) ( $response['body'] ?? '' )
		);
		Functions\when( 'wp_remote_retrieve_headers' )->alias(
			static fn( $response ) => $response['headers'] ?? array()
		);
	}

	/**
	 * Filter recorded wp_remote_get() calls down to page fetches (no robots.txt).
	 *
	 * @return array<int, array{url: string, args: array<string, mixed>}>
	 */
	private function get_page_remote_calls(): array {
		return array_values(
			array_filter(
				$this->remote_calls,
				static fn( $call ) => false === strpos( $call['url'], 'robots.txt' )
			)
		);
	}

	/**
	 * Initial URL validation must behave exactly as before the change.
	 *
	 * @param string $url Invalid scan URL.
	 */
	#[DataProvider( 'provider_invalid_scan_urls' )]
	public function test_initial_url_validation_unchanged( string $url ): void {
		$result = WPPO_Scriptable_Telemetry::scan( $url );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
		$this->assertSame( array(), WPPO_Scriptable_Telemetry::$requests, 'No request may be issued for an invalid scan URL.' );
		$this->assertSame( array(), $this->remote_calls );
	}

	/**
	 * Data provider: URLs rejected by the unchanged initial validation.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provider_invalid_scan_urls(): array {
		return array(
			'non-http scheme' => array( 'ftp://example.com/page' ),
			'different host'  => array( 'http://other.example.net/page' ),
			'malformed'       => array( 'not-a-url' ),
		);
	}

	/**
	 * A same-host URL redirecting to an internal endpoint or another host must
	 * be rejected BEFORE any second request is issued.
	 *
	 * @param string $target Redirect target from the Location header.
	 */
	#[DataProvider( 'provider_unsafe_redirect_targets' )]
	public function test_redirect_to_internal_or_external_host_is_rejected_before_following( string $target ): void {
		WPPO_Scriptable_Telemetry::script_response(
			'http://example.com/start',
			302,
			array( 'Location' => $target )
		);

		$result = WPPO_Scriptable_Telemetry::scan( 'http://example.com/start' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'unsafe_redirect', $result->get_error_code() );
		$this->assertCount(
			1,
			WPPO_Scriptable_Telemetry::$requests,
			'No second request may be issued towards an unsafe redirect target.'
		);
	}

	/**
	 * Data provider: redirect targets that must never be followed.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provider_unsafe_redirect_targets(): array {
		return array(
			'link-local metadata endpoint' => array( 'http://169.254.169.254/latest/meta-data/' ),
			'loopback address'             => array( 'http://127.0.0.1/wp-admin/' ),
			'different host'               => array( 'http://evil.example.net/steal' ),
		);
	}

	/**
	 * A same-host redirect is followed once and all timings come from the
	 * final hop, not the redirecting first hop.
	 */
	public function test_same_host_relative_redirect_followed_once_with_final_hop_timing(): void {
		WPPO_Scriptable_Telemetry::script_response(
			'http://example.com/redirect-relative',
			302,
			array( 'Location' => '/final-page' )
		);
		WPPO_Scriptable_Telemetry::script_response(
			'http://example.com/final-page',
			200,
			array( 'Content-Type' => 'text/html' ),
			'<html><body><h1>Final</h1></body></html>',
			array(
				'namelookup_time'    => 0.03,
				'connect_time'       => 0.06,
				'appconnect_time'    => 0.0,
				'pretransfer_time'   => 0.07,
				'starttransfer_time' => 0.1234,
				'total_time'         => 0.25,
			)
		);

		$result = WPPO_Scriptable_Telemetry::scan( 'http://example.com/redirect-relative' );

		$this->assertIsArray( $result );
		$this->assertSame(
			array( 'http://example.com/redirect-relative', 'http://example.com/final-page' ),
			WPPO_Scriptable_Telemetry::$requests,
			'The relative Location must resolve against the current URL and be followed once.'
		);

		// Timings must come from the FINAL hop's info struct.
		$this->assertEqualsWithDelta( 123.4, $result['ttfb'], 0.01 );
		$this->assertEqualsWithDelta( 30.0, $result['dns_lookup_time'], 0.01 );
		$this->assertEqualsWithDelta( 30.0, $result['connect_time'], 0.01 );
		$this->assertEqualsWithDelta( 53.4, $result['server_wait_time'], 0.01 );
		$this->assertEqualsWithDelta( 0.0, $result['ssl_time'], 0.01 );
		$this->assertEqualsWithDelta( 0.25, $result['load_time'], 0.01 );

		// Result identity stays anchored to the original scan URL.
		$this->assertSame( 'http://example.com/redirect-relative', $result['page_url'] );

		// No page fetch may go through wp_remote_get() when cURL succeeded.
		$this->assertSame( array(), $this->get_page_remote_calls() );
	}

	/**
	 * Absolute and protocol-relative same-host Locations are followed too.
	 *
	 * @param string $start_url Unique scan URL (audit cache is per-URL).
	 * @param string $location  Raw Location header value.
	 * @param string $expected  Resolved absolute URL of the second hop.
	 */
	#[DataProvider( 'provider_same_host_redirect_locations' )]
	public function test_absolute_and_protocol_relative_same_host_redirects_are_followed( string $start_url, string $location, string $expected ): void {
		WPPO_Scriptable_Telemetry::script_response(
			$start_url,
			301,
			array( 'Location' => $location )
		);
		WPPO_Scriptable_Telemetry::script_response(
			$expected,
			200,
			array(),
			'<html><body><p>Moved</p></body></html>'
		);

		$result = WPPO_Scriptable_Telemetry::scan( $start_url );

		$this->assertIsArray( $result );
		$this->assertSame(
			array( $start_url, $expected ),
			WPPO_Scriptable_Telemetry::$requests
		);
		$this->assertEqualsWithDelta( 50.0, $result['ttfb'], 0.01 );
	}

	/**
	 * Data provider: valid same-host Location forms.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function provider_same_host_redirect_locations(): array {
		return array(
			'absolute URL'       => array( 'http://example.com/abs-start', 'http://example.com/moved', 'http://example.com/moved' ),
			'protocol-relative'  => array( 'http://example.com/pr-start', '//example.com/pr-target', 'http://example.com/pr-target' ),
			'root-relative path' => array( 'http://example.com/root-start', '/root-target', 'http://example.com/root-target' ),
		);
	}

	/**
	 * Exceeding the two-hop limit returns a WP_Error instead of following on.
	 */
	public function test_redirect_hop_limit_returns_error(): void {
		WPPO_Scriptable_Telemetry::script_response( 'http://example.com/hop0', 302, array( 'Location' => '/hop1' ) );
		WPPO_Scriptable_Telemetry::script_response( 'http://example.com/hop1', 302, array( 'Location' => '/hop2' ) );
		WPPO_Scriptable_Telemetry::script_response( 'http://example.com/hop2', 302, array( 'Location' => '/hop3' ) );

		$result = WPPO_Scriptable_Telemetry::scan( 'http://example.com/hop0' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'redirect_limit_exceeded', $result->get_error_code() );
		$this->assertCount(
			3,
			WPPO_Scriptable_Telemetry::$requests,
			'Initial request plus at most two validated hops; the third redirect must not be requested.'
		);
	}

	/**
	 * A plain 200 response behaves exactly as before the change.
	 */
	public function test_non_redirect_happy_path_unchanged(): void {
		WPPO_Scriptable_Telemetry::script_response(
			'http://example.com/plain',
			200,
			array( 'Content-Type' => 'text/html' ),
			'<html><body><h1>Hello</h1></body></html>'
		);

		$result = WPPO_Scriptable_Telemetry::scan( 'http://example.com/plain' );

		$this->assertIsArray( $result );
		$this->assertCount( 1, WPPO_Scriptable_Telemetry::$requests );

		$this->assertSame( 'http://example.com/plain', $result['page_url'] );
		$this->assertEqualsWithDelta( 50.0, $result['ttfb'], 0.01 );
		$this->assertEqualsWithDelta( 10.0, $result['dns_lookup_time'], 0.01 );
		$this->assertEqualsWithDelta( 10.0, $result['connect_time'], 0.01 );
		$this->assertEqualsWithDelta( 20.0, $result['server_wait_time'], 0.01 );
		$this->assertEqualsWithDelta( 0.1, $result['load_time'], 0.01 );
		$this->assertSame( 0, $result['css_count'] );
		$this->assertSame( 0, $result['js_count'] );
		$this->assertSame( 0, $result['media_count'] );
		$this->assertGreaterThan( 0, $result['dom_size'] );
		$this->assertFalse( $result['uses_https'] );
		$this->assertFalse( $result['gzip_brotli_compression'] );
		$this->assertSame( 'none', $result['compression_value'] );
		$this->assertFalse( $result['robots_txt_exists'] );
		$this->assertFalse( $result['is_cached'] );

		$this->assertSame( array(), $this->get_page_remote_calls() );
	}

	/**
	 * The wp_remote_get() fallback must send redirection=0 and follow only
	 * validated same-host redirects manually.
	 */
	public function test_fallback_uses_redirection_zero_and_follows_validated_redirect(): void {
		// No scripted cURL responses → transport error → fallback path.
		WPPO_Scriptable_Telemetry::script_remote_response(
			'http://example.com/fb-start',
			302,
			array( 'location' => '/fb-moved' )
		);
		WPPO_Scriptable_Telemetry::script_remote_response(
			'http://example.com/fb-moved',
			200,
			array(),
			'<html><body><p>Moved</p></body></html>'
		);

		$result = WPPO_Scriptable_Telemetry::scan( 'http://example.com/fb-start' );

		$page_calls = $this->get_page_remote_calls();

		$this->assertIsArray( $result );
		$this->assertCount( 2, $page_calls, 'The fallback must re-issue the request itself instead of auto-following.' );

		$this->assertSame( 'http://example.com/fb-start', $page_calls[0]['url'] );
		$this->assertSame( 0, $page_calls[0]['args']['redirection'], 'WordPress must never auto-follow redirects.' );

		$this->assertSame( 'http://example.com/fb-moved', $page_calls[1]['url'] );
		$this->assertSame( 0, $page_calls[1]['args']['redirection'] );

		$this->assertSame( 'http://example.com/fb-start', $result['page_url'] );
		$this->assertArrayHasKey( 'ttfb', $result );
		$this->assertGreaterThanOrEqual( 0.0, $result['ttfb'] );
		$this->assertGreaterThanOrEqual( 0, $result['load_time'] );
	}

	/**
	 * The fallback rejects unsafe redirect targets before issuing a second call.
	 */
	public function test_fallback_rejects_unsafe_redirect_before_following(): void {
		WPPO_Scriptable_Telemetry::script_remote_response(
			'http://example.com/fb-int',
			302,
			array( 'location' => 'http://127.0.0.1/admin' )
		);

		$result = WPPO_Scriptable_Telemetry::scan( 'http://example.com/fb-int' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'unsafe_redirect', $result->get_error_code() );
		$this->assertCount( 1, $this->get_page_remote_calls(), 'No second request may be issued towards an unsafe redirect target.' );
	}
}
