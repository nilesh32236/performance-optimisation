<?php
/**
 * Controllable stand-in for the PHP 8.5 http_get_last_response_headers() engine API.
 *
 * The unit suite runs on PHP 8.2+ where the engine function may not exist,
 * so tests that exercise the new-API branch of
 * Util::get_last_response_headers() need a controllable double. The probe
 * value is driven via `$GLOBALS['wppo_test_last_headers_probe']` (null when
 * unset, mirroring "no headers in this scope"). Only loaded inside isolated
 * test processes so the main suite process is never polluted. On runtimes
 * where the engine API already exists this file is a no-op.
 *
 * @package PerformanceOptimise\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.Files.FileName.InvalidClassFileName

if ( ! function_exists( 'http_get_last_response_headers' ) ) {
	/**
	 * Test double for the PHP 8.5 engine API (issue #1309).
	 *
	 * @return mixed Whatever `$GLOBALS['wppo_test_last_headers_probe']` holds, or null when unset.
	 */
	function http_get_last_response_headers() {
		return array_key_exists( 'wppo_test_last_headers_probe', $GLOBALS ) ? $GLOBALS['wppo_test_last_headers_probe'] : null;
	}
}
