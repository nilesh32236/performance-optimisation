<?php
/**
 * Tests for Util::get_last_response_headers() (issue #1309).
 *
 * The wrapper prefers the PHP 8.5 http_get_last_response_headers() engine
 * API with an isset-guarded legacy fallback, fail-open to an empty array.
 * Legacy-path tests run in-process and skip when the engine API exists;
 * engine-API tests run in isolated processes with the controllable
 * stand-in from tests/php/stubs/http-response-headers.php so the global
 * function table of the main suite process is never polluted.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Util;

/**
 * Tests for the last-response-headers wrapper.
 *
 * @package PerformanceOptimise\Tests
 */
class UtilGetLastResponseHeadersTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Legacy fallback must string-filter the global header store.
	 *
	 * @return void
	 */
	public function test_legacy_fallback_returns_filtered_strings(): void {
		if ( function_exists( 'http_get_last_response_headers' ) ) {
			$this->markTestSkipped( 'Legacy path requires the PHP 8.5 engine API to be absent.' );
		}

		$had                             = array_key_exists( 'http_response_header', $GLOBALS );
		$previous                        = $had ? $GLOBALS['http_response_header'] : null;
		$GLOBALS['http_response_header'] = array( 'HTTP/1.1 200 OK', 123, null, 'Content-Type: text/html' );

		try {
			$this->assertSame(
				array( 'HTTP/1.1 200 OK', 'Content-Type: text/html' ),
				Util::get_last_response_headers()
			);
		} finally {
			if ( $had ) {
				$GLOBALS['http_response_header'] = $previous;
			} else {
				unset( $GLOBALS['http_response_header'] );
			}
		}
	}

	/**
	 * Legacy fallback must fail open when the store is absent.
	 *
	 * @return void
	 */
	public function test_legacy_fallback_fail_open_when_absent(): void {
		if ( function_exists( 'http_get_last_response_headers' ) ) {
			$this->markTestSkipped( 'Legacy path requires the PHP 8.5 engine API to be absent.' );
		}

		$had      = array_key_exists( 'http_response_header', $GLOBALS );
		$previous = $had ? $GLOBALS['http_response_header'] : null;
		unset( $GLOBALS['http_response_header'] );

		try {
			$this->assertSame( array(), Util::get_last_response_headers() );
		} finally {
			if ( $had ) {
				$GLOBALS['http_response_header'] = $previous;
			}
		}
	}

	/**
	 * An explicit legacy source must beat a stale global store.
	 *
	 * Callers in a function/method scope cannot rely on the global store
	 * (scope-blind on PHP 8.2–8.4), so the explicit parameter — including
	 * an explicitly empty array — always wins over the global fallback.
	 *
	 * @return void
	 */
	public function test_explicit_legacy_source_beats_stale_global(): void {
		if ( function_exists( 'http_get_last_response_headers' ) ) {
			$this->markTestSkipped( 'Legacy path requires the PHP 8.5 engine API to be absent.' );
		}

		$had                             = array_key_exists( 'http_response_header', $GLOBALS );
		$previous                        = $had ? $GLOBALS['http_response_header'] : null;
		$GLOBALS['http_response_header'] = array( 'HTTP/1.1 200 STALE' );

		try {
			$this->assertSame(
				array( 'X-Explicit: 1' ),
				Util::get_last_response_headers( array( 'X-Explicit: 1', 42 ) )
			);
			$this->assertSame( array(), Util::get_last_response_headers( array() ) );
		} finally {
			if ( $had ) {
				$GLOBALS['http_response_header'] = $previous;
			} else {
				unset( $GLOBALS['http_response_header'] );
			}
		}
	}

	/**
	 * A non-array engine probe must not fall through to stale globals.
	 *
	 * Runs isolated: requires the controllable stand-in (or the native
	 * engine on PHP 8.5+, which reports no headers in a fresh process).
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_new_api_probe_is_authoritative_over_stale_global(): void {
		require_once __DIR__ . '/stubs/http-response-headers.php';

		// Null probe: "no headers in this scope" (no-op on the native engine).
		$GLOBALS['wppo_test_last_headers_probe'] = null;
		$GLOBALS['http_response_header']         = array( 'HTTP/1.1 200 STALE' );

		try {
			$this->assertSame( array(), Util::get_last_response_headers() );
		} finally {
			unset( $GLOBALS['wppo_test_last_headers_probe'], $GLOBALS['http_response_header'] );
		}
	}

	/**
	 * An array engine probe must be string-filtered.
	 *
	 * Runs isolated with the controllable stand-in; skipped when the
	 * native engine API exists because its probe cannot be driven.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_new_api_probe_filters_to_strings(): void {
		if ( function_exists( 'http_get_last_response_headers' ) ) {
			$this->markTestSkipped( 'Controllable probe requires the native engine API to be absent.' );
		}

		require_once __DIR__ . '/stubs/http-response-headers.php';

		$GLOBALS['wppo_test_last_headers_probe'] = array( 'HTTP/1.1 200 OK', 123, null, 'X-A: b' );

		try {
			$this->assertSame(
				array( 'HTTP/1.1 200 OK', 'X-A: b' ),
				Util::get_last_response_headers()
			);
		} finally {
			unset( $GLOBALS['wppo_test_last_headers_probe'] );
		}
	}
}
