<?php
/**
 * Tests for the audit #874 performance fixes.
 *
 * - Google_Fonts failure-sentinel backoff (finding 3)
 * - Cache::bump_stats_cache() transient invalidation (finding 6)
 * - CDN wildcard2regex per-request memo (finding 5)
 * - Util::memoized_permalink per-request memo (finding 1)
 * - Crawler/Server_Rules settings-memo reads (findings 2, 4)
 * - CCSS existence memo plus reset paths (finding 7)
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Google_Fonts;
use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\CDN;
use PerformanceOptimise\Inc\Critical_CSS;
use PerformanceOptimise\Inc\LiteSpeed_Crawler;
use PerformanceOptimise\Inc\Server_Rules;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Performance audit #874 tests.
 *
 * @package PerformanceOptimise\Tests
 */
class Perf874Test extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory transients shared by the stubs.
	 *
	 * @var array<string, mixed>
	 */
	private $transients = array();

	/**
	 * In-memory options map backing the get_option alias.
	 *
	 * @var array<string, mixed>
	 */
	private $options = array();

	/**
	 * Remote-fetch call log (URL => count).
	 *
	 * @var array<string, int>
	 */
	private $remote_calls = array();

	/**
	 * Install shared stubs.
	 */
	private function install_stubs(): void {
		Functions\stubs(
			array(
				'get_option',
				'update_option',
				'wp_normalize_path',
				'content_url',
				'is_admin',
				'get_transient',
				'set_transient',
				'delete_transient',
				'wp_parse_url',
				'is_wp_error',
				'wp_remote_retrieve_response_code',
				'wp_remote_retrieve_body',
				'esc_url_raw',
				'wp_salt',
				'wp_create_nonce',
				'sanitize_text_field',
				'wp_json_encode',
				'wp_print_inline_script_tag',
				'get_current_blog_id',
				'is_multisite',
				'apply_filters',
				'wp_make_link_relative',
				'sanitize_key',
				'wp_rand',
				'WP_Filesystem',
			)
		);
		// Some earlier tests leave a WP_Filesystem mock behind; pin the stub so
		// Util::init_filesystem() deterministically returns false here and the
		// direct-write fallback is exercised.
		Functions\when( 'WP_Filesystem' )->justReturn( false );
		unset( $GLOBALS['wp_filesystem'] );
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'content_url' )->justReturn( 'http://example.com/wp-content' );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_make_link_relative' )->returnArg();
		Functions\when( 'wp_rand' )->justReturn( 42 );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );

		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return array_key_exists( $key, $this->transients ) ? $this->transients[ $key ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Mirrors the WP signature.
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
	}

	/**
	 * Install a wp_remote_get stub that counts calls and returns a canned result.
	 *
	 * @param callable|array $responder Return value or callable receiving the URL.
	 * @return void
	 */
	private function stub_remote( $responder ): void {
		$this->remote_calls = array();
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url, $args = array() ) use ( $responder ) {
				$this->remote_calls[ (string) $url ] = ( $this->remote_calls[ (string) $url ] ?? 0 ) + 1;
				return is_callable( $responder ) ? $responder( $url, $args ) : $responder;
			}
		);
	}

	/**
	 * Test that a failed CSS fetch sets the failure sentinel and the next
	 * call skips the remote fetch entirely (audit #874 finding 3).
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_google_fonts_failure_sets_backoff_sentinel(): void {
		// The separate process has no WP_Error stand-in from other tests —
		// require the TelemetryTest declaration directly (no eval).
		if ( ! class_exists( 'WP_Error' ) ) {
			// TelemetryTest declares the class at file scope (phpcs ignore at top).
			require_once __DIR__ . '/TelemetryTest.php';
		}
		$this->install_stubs();
		$this->stub_remote(
			static function () {
				return new \WP_Error( 'http_request_failed', 'connection refused' );
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);

		$url      = 'https://fonts.googleapis.com/css2?family=Inter';
		$fonts    = new Google_Fonts( array() );
		$fail_key = Util::transient_key( 'wppo_gf_fail_' . md5( $url ) );

		$this->assertSame( '', $fonts->download_and_rewrite( $url ) );
		$this->assertSame( 1, $this->remote_calls[ $url ] ?? 0 );
		$this->assertSame( 1, $this->transients[ $fail_key ] ?? null );

		// Second call within the backoff window must not re-issue the request.
		$this->assertSame( '', $fonts->download_and_rewrite( $url ) );
		$this->assertSame( 1, $this->remote_calls[ $url ] ?? 0 );
	}

	/**
	 * Test that a successful CSS + font-file fetch clears the failure
	 * sentinel (audit #874 finding 3, download_font_file path).
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_google_fonts_success_clears_sentinel(): void {
		$this->install_stubs();

		$css_url  = 'https://fonts.googleapis.com/css2?family=Inter';
		$font_url = 'https://fonts.gstatic.com/s/inter/v1/sentinel-test.woff2';
		$css_key  = Util::transient_key( 'wppo_gf_fail_' . md5( $css_url ) );
		$font_key = Util::transient_key( 'wppo_gf_fail_' . md5( $font_url ) );

		// Ensure the destination directories exist (WP_Filesystem is stubbed
		// off in unit tests, so the direct-write fallback needs them).
		$dest_dir = wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/fonts/files' );
		if ( ! is_dir( $dest_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
			mkdir( $dest_dir, 0775, true );
		}
		$css_dir = wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/fonts/css' );
		if ( ! is_dir( $css_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
			mkdir( $css_dir, 0775, true );
		}
		$font_hash = md5( $font_url );
		$dest      = $dest_dir . '/' . $font_hash . '.woff2';
		$css_file  = wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/fonts/css/' . md5( $css_url ) . '.css' );
		if ( file_exists( $dest ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			unlink( $dest );
		}
		if ( file_exists( $css_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			unlink( $css_file );
		}
		$this->stub_remote(
			static function ( $url, $args ) use ( $font_url ) {
				if ( $font_url === $url && ! empty( $args['filename'] ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture streams the body like WP would.
					file_put_contents( $args['filename'], 'WOFF2DATA' );
				}
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '@font-face { font-family: Inter; src: url(' . $font_url . ') format("woff2"); }',
				);
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return $response['response']['code'] ?? 0;
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return $response['body'] ?? '';
			}
		);

		$fonts = new Google_Fonts( array() );
		$this->assertNotSame( '', $fonts->download_and_rewrite( $css_url ) );
		// A successful run must not leave failure sentinels behind (the
		// download_font_file() success branch deletes its sentinel defensively).
		$this->assertArrayNotHasKey( $css_key, $this->transients );
		$this->assertArrayNotHasKey( $font_key, $this->transients );
		$this->assertFileExists( $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
		unlink( $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
		unlink( $css_file );
	}

	/**
	 * Test that bump_stats_cache() deletes all stats transients (audit #874
	 * finding 6).
	 */
	public function test_bump_stats_cache_deletes_transients(): void {
		$this->install_stubs();

		$keys = array(
			Util::transient_key( 'wppo_cache_stats' ),
			Util::transient_key( 'wppo_cache_size' ),
			Util::transient_key( 'wppo_cache_count' ),
			Util::transient_key( 'wppo_total_js_css' ),
		);
		foreach ( $keys as $k ) {
			$this->transients[ $k ] = 1;
		}

		Cache::bump_stats_cache();

		foreach ( $keys as $k ) {
			$this->assertArrayNotHasKey( $k, $this->transients, "Expected $k to be deleted" );
		}
	}

	/**
	 * Test that wildcard2regex is memoized per pattern and returns stable,
	 * identical results (audit #874 finding 5).
	 */
	public function test_wildcard2regex_is_memoized(): void {
		$this->install_stubs();

		CDN::reset_cache();

		$first  = CDN::wildcard2regex( 'wp-content|wp-includes' );
		$second = CDN::wildcard2regex( 'wp-content|wp-includes' );
		$other  = CDN::wildcard2regex( 'uploads' );

		$this->assertSame( $first, $second );
		$this->assertNotSame( $first, $other );
		// Wildcards still expand.
		$this->assertSame( 'wp\-content.*', CDN::wildcard2regex( 'wp-content*' ) );
	}

	/**
	 * Test that a crawler getter honours re-stubbed settings after
	 * LiteSpeed_Crawler::reset_cache() (review round 1, finding 5).
	 */
	public function test_crawler_getter_respects_restubbed_settings_after_reset(): void {
		$this->install_stubs();

		$this->options['wppo_settings'] = array(
			'litespeed_integration' => array(
				'crawler' => array(
					// Neutralise the load-adaptive throttle so the settings-derived
					// value is deterministic on busy CI/dev machines.
					'concurrency' => 4,
					'loadLimit'   => 1.0E9,
				),
			),
		);
		$this->assertSame( 4, LiteSpeed_Crawler::get_concurrency() );

		// Re-stub mid-test; only a reset may expose the new value.
		$this->options['wppo_settings'] = array(
			'litespeed_integration' => array(
				'crawler' => array(
					'concurrency' => 2,
					'loadLimit'   => 1.0E9,
				),
			),
		);
		LiteSpeed_Crawler::reset_cache();
		$this->assertSame( 2, LiteSpeed_Crawler::get_concurrency() );
	}

	/**
	 * Test that Server_Rules reads through the Util settings memo: re-stubbed
	 * settings apply after Util::clear_settings_cache() (audit #874 finding 4).
	 */
	public function test_server_rules_reads_via_memoized_settings(): void {
		$this->install_stubs();

		$this->options['wppo_settings'] = array(
			'file_optimisation' => array( 'minifyJS' => true ),
		);
		$this->assertStringContainsString( 'gzip on;', Server_Rules::get_nginx_rules() );

		$this->options['wppo_settings'] = array();
		Util::clear_settings_cache();
		$this->assertStringNotContainsString( 'gzip on;', Server_Rules::get_nginx_rules() );
	}

	/**
	 * Test the CCSS existence memo: stable within a request, invalidated by
	 * reset_ccss_memo() so deletion/generation in the same request is visible
	 * (audit #874 finding 7 / review round 1, finding 10).
	 */
	public function test_ccss_existence_memo_and_reset(): void {
		$this->install_stubs();

		$hash = 'perf874memo';
		$dir  = wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
			mkdir( $dir, 0775, true );
		}
		$file = $dir . '/' . $hash . '.css';
		if ( file_exists( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			unlink( $file );
		}

		$this->assertFalse( Critical_CSS::ccss_exists( $hash ) );
		// Memoize the miss...
		$this->assertFalse( Critical_CSS::ccss_exists( $hash ) );
		// ...then create the file: still false until the memo resets.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $file, 'body{}' );
		$this->assertFalse( Critical_CSS::ccss_exists( $hash ) );

		Critical_CSS::reset_ccss_memo();
		$this->assertTrue( Critical_CSS::ccss_exists( $hash ) );
		// Stable on repeat.
		$this->assertTrue( Critical_CSS::ccss_exists( $hash ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
		unlink( $file );
	}

	/**
	 * Test that memoized_permalink() only resolves a given ID once per
	 * request (audit #874 finding 1).
	 */
	public function test_memoized_permalink_resolves_once(): void {
		$this->install_stubs();

		$calls = array();
		Functions\when( 'get_permalink' )->alias(
			static function ( $post_id ) use ( &$calls ) {
				$calls[] = (int) $post_id;
				return 'http://example.com/post-' . (int) $post_id;
			}
		);

		$this->assertSame( 'http://example.com/post-7', Util::memoized_permalink( 7 ) );
		$this->assertSame( 'http://example.com/post-7', Util::memoized_permalink( 7 ) );
		$this->assertSame( 'http://example.com/post-8', Util::memoized_permalink( 8 ) );

		$this->assertSame( array( 7, 8 ), $calls );

		// Clearing the memo re-resolves.
		Util::clear_permalink_cache();
		Util::memoized_permalink( 7 );
		$this->assertSame( array( 7, 8, 7 ), $calls );
	}
}
