<?php
/**
 * Direct contract tests for the REF-015 Http boundary (issue #1526).
 *
 * Pins byte-identical behavior for the teardown + last-response-headers
 * cluster moved from `Util` to `PerformanceOptimise\Inc\Http`:
 * idempotency vectors (null input, double-close, explicit 8.5/8.4 gates),
 * facade-proxy equivalence (`Util::x` vs `Http::x`), and the
 * `get_last_response_headers()` legacy-source filtering + empty fallback.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Http;
use PerformanceOptimise\Inc\Util;

/**
 * Http boundary tests.
 *
 * @package PerformanceOptimise\Tests
 */
class HttpBoundaryTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * The 8.5 gate is null-safe on the drop-reference path.
	 */
	public function test_close_curl_handle_null_input_on_85_stays_null(): void {
		$ch = null;

		Http::close_curl_handle( $ch, '8.5.0' );

		$this->assertNull( $ch );
	}

	/**
	 * The 8.5 path releases a real cURL handle and double-close is idempotent.
	 */
	public function test_close_curl_handle_double_close_is_idempotent(): void {
		if ( ! function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'cURL extension is required.' );
		}

		$ch = curl_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- test requires a real handle for the teardown helper.

		Http::close_curl_handle( $ch, '8.5.0' );
		$this->assertNull( $ch );

		Http::close_curl_handle( $ch, '8.5.0' );
		$this->assertNull( $ch );
	}

	/**
	 * Below 8.5 the legacy curl_close() path runs without error.
	 */
	public function test_close_curl_handle_keeps_legacy_path_below_85(): void {
		if ( Http::is_php85_or_greater() ) {
			$this->markTestSkipped( 'Legacy curl_close() path cannot run notice-free on PHP >= 8.5.' );
		}
		if ( ! function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'cURL extension is required.' );
		}

		$ch = curl_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- test requires a real handle for the teardown helper.

		Http::close_curl_handle( $ch, '8.4.0' );

		// Legacy curl_close() closed the handle; null the local so no
		// second close is attempted during cleanup.
		$ch = null;
		$this->assertNull( $ch );
	}

	/**
	 * The 8.5 path releases a real multi handle and double-close is idempotent.
	 */
	public function test_close_curl_multi_handle_double_close_is_idempotent(): void {
		if ( ! function_exists( 'curl_multi_init' ) ) {
			$this->markTestSkipped( 'cURL extension is required.' );
		}

		$mh = curl_multi_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_init -- test requires a real multi handle for the teardown helper.

		Http::close_curl_multi_handle( $mh, '8.5.0' );
		$this->assertNull( $mh );

		Http::close_curl_multi_handle( $mh, '8.5.0' );
		$this->assertNull( $mh );
	}

	/**
	 * The 8.5 path releases a GD image and double-destroy is idempotent.
	 */
	public function test_destroy_gd_image_double_destroy_is_idempotent(): void {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD extension is required.' );
		}

		$image = imagecreatetruecolor( 10, 10 );

		Http::destroy_gd_image( $image, '8.5.0' );
		$this->assertNull( $image );

		Http::destroy_gd_image( $image, '8.5.0' );
		$this->assertNull( $image );
	}

	/**
	 * The 8.5 path releases a cURL share handle and stays null-safe.
	 */
	public function test_close_curl_share_handle_unsets_on_85(): void {
		if ( ! function_exists( 'curl_share_init' ) ) {
			$this->markTestSkipped( 'cURL extension is required.' );
		}

		$sh = curl_share_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_share_init -- test requires a real share handle for the teardown helper.

		Http::close_curl_share_handle( $sh, '8.5.0' );
		$this->assertNull( $sh );

		Http::close_curl_share_handle( $sh, '8.5.0' );
		$this->assertNull( $sh );
	}

	/**
	 * The 8.5 path releases a finfo handle and stays null-safe.
	 */
	public function test_close_finfo_handle_unsets_on_85(): void {
		if ( ! function_exists( 'finfo_open' ) ) {
			$this->markTestSkipped( 'fileinfo extension is required.' );
		}

		$finfo = finfo_open( FILEINFO_MIME_TYPE );

		Http::close_finfo_handle( $finfo, '8.5.0' );
		$this->assertNull( $finfo );

		Http::close_finfo_handle( $finfo, '8.5.0' );
		$this->assertNull( $finfo );
	}

	/**
	 * The 8.5 path releases an XML parser and stays null-safe.
	 */
	public function test_free_xml_parser_unsets_on_85(): void {
		if ( ! function_exists( 'xml_parser_create' ) ) {
			$this->markTestSkipped( 'xml extension is required.' );
		}

		$parser = xml_parser_create(); // phpcs:ignore WordPress.WP.AlternativeFunctions.xml_xml_parser_create -- test requires a real parser for the teardown helper.

		Http::free_xml_parser( $parser, '8.5.0' );
		$this->assertNull( $parser );

		Http::free_xml_parser( $parser, '8.5.0' );
		$this->assertNull( $parser );
	}

	/**
	 * Every Util:: proxy delegates to Http:: with the same outcome.
	 */
	public function test_util_proxies_match_http_outcome(): void {
		$this->assertSame(
			Http::is_php85_or_greater( '8.5.0' ),
			Util::is_php85_or_greater( '8.5.0' )
		);
		$this->assertSame(
			Http::is_php85_or_greater( '8.4.0' ),
			Util::is_php85_or_greater( '8.4.0' )
		);

		$via_http = null;
		$via_util = null;
		Http::close_curl_handle( $via_http, '8.5.0' );
		Util::close_curl_handle( $via_util, '8.5.0' );
		$this->assertSame( $via_http, $via_util );

		$via_http = null;
		$via_util = null;
		Http::destroy_gd_image( $via_http, '8.5.0' );
		Util::destroy_gd_image( $via_util, '8.5.0' );
		$this->assertSame( $via_http, $via_util );

		$via_http = null;
		$via_util = null;
		Http::close_curl_multi_handle( $via_http, '8.5.0' );
		Util::close_curl_multi_handle( $via_util, '8.5.0' );
		$this->assertSame( $via_http, $via_util );

		$via_http = null;
		$via_util = null;
		Http::close_curl_share_handle( $via_http, '8.5.0' );
		Util::close_curl_share_handle( $via_util, '8.5.0' );
		$this->assertSame( $via_http, $via_util );

		$via_http = null;
		$via_util = null;
		Http::close_finfo_handle( $via_http, '8.5.0' );
		Util::close_finfo_handle( $via_util, '8.5.0' );
		$this->assertSame( $via_http, $via_util );

		$via_http = null;
		$via_util = null;
		Http::free_xml_parser( $via_http, '8.5.0' );
		Util::free_xml_parser( $via_util, '8.5.0' );
		$this->assertSame( $via_http, $via_util );

		$this->assertSame(
			Http::get_last_response_headers( array( 'HTTP/1.1 200 OK' ) ),
			Util::get_last_response_headers( array( 'HTTP/1.1 200 OK' ) )
		);
	}

	/**
	 * Explicit legacy sources are string-filtered and re-indexed.
	 */
	public function test_get_last_response_headers_filters_legacy_source(): void {
		$headers = Http::get_last_response_headers(
			array( 'HTTP/1.1 200 OK', 123, 'Content-Type: text/html', null )
		);

		$this->assertIsArray( $headers );
		foreach ( $headers as $line ) {
			$this->assertIsString( $line );
		}

		if ( function_exists( 'http_get_last_response_headers' ) ) {
			// The engine API is authoritative when present; the legacy
			// source is never consulted.
			return;
		}

		$this->assertSame(
			array( 'HTTP/1.1 200 OK', 'Content-Type: text/html' ),
			$headers
		);
	}

	/**
	 * No headers anywhere yields an empty array (fail-open).
	 */
	public function test_get_last_response_headers_empty_fallback(): void {
		unset( $GLOBALS['http_response_header'] );

		$this->assertSame( array(), Http::get_last_response_headers() );
		$this->assertSame( array(), Util::get_last_response_headers() );
	}
}
