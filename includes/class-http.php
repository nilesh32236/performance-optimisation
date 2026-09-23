<?php
/**
 * Http boundary — resource teardown + last-response headers (REF-015).
 *
 * Focused extraction of the HTTP-infrastructure responsibility cluster
 * previously owned by the god utility `Util`: the PHP 8.5 resource-teardown
 * helpers (`close_curl_handle`, `close_curl_multi_handle`,
 * `destroy_gd_image`, `close_curl_share_handle`, `close_finfo_handle`,
 * `free_xml_parser`), the `is_php85_or_greater()` version gate they share,
 * and `get_last_response_headers()`. Bodies are verbatim copies of the
 * former `Util` implementations (including the PHP 8.5 `setAccessible`
 * avoidance note — never call teardown directly, route through
 * `Http::`/`Util::`, pinned by `PhpDeprecationHygieneTest`).
 * `Util::close_*()` and friends remain as one-line facade proxies so all
 * existing callers keep working untouched.
 *
 * Dependency direction: features → `Http` (or the `Util::` proxy) → PHP
 * native resources. Nothing else in the plugin. No state, no cycles — the
 * version gate moved here too so `Http` never calls back into `Util`.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Http' ) ) {
	/**
	 * Class Http
	 *
	 * Static HTTP-infrastructure boundary. Depends only on PHP native
	 * resources (cURL, GD, finfo, XML parser, engine response headers).
	 * No WordPress APIs, no options, no storage — multisite-safe by
	 * construction.
	 *
	 * @since NEXT
	 */
	final class Http {
		/**
		 * Whether the current runtime deprecates explicit handle-close calls (PHP 8.5+).
		 *
		 * PHP 8.5 deprecates the former resource-teardown no-ops `curl_close()`,
		 * `curl_share_close()`, `finfo_close()`, `xml_parser_free()` and
		 * `imagedestroy()` (see wiki.php.net/rfc/deprecations_php_8_5): on 8.5+
		 * handles are released by dropping the reference instead of calling the
		 * close function. Below 8.5 the legacy close path is kept unchanged.
		 * Note: upstream deprecates `curl_close()` + `curl_share_close()`
		 * only — not `curl_multi_close()` — but `close_curl_multi_handle()`
		 * is over-gated the same way for symmetry (fail-open either way).
		 *
		 * The optional $php_version parameter exists so PHPUnit (Brain Monkey)
		 * can exercise both sides of the gate without redefining PHP_VERSION.
		 *
		 * @since 2.0.0
		 * @since NEXT Moved from Util (REF-015); behavior unchanged.
		 * @param string|null $php_version Optional version string for testing; defaults to PHP_VERSION.
		 * @return bool True on PHP 8.5+, false below.
		 */
		public static function is_php85_or_greater( ?string $php_version = null ): bool {
			static $cached = null;
			if ( null === $php_version && null !== $cached ) {
				return $cached;
			}
			$version = $php_version ?? PHP_VERSION;
			$result  = version_compare( $version, '8.5', '>=' );
			if ( null === $php_version ) {
				$cached = $result;
			}
			return $result;
		}

		/**
		 * Release a cURL handle without triggering the PHP 8.5 deprecation.
		 *
		 * On PHP 8.5+ the handle reference is dropped (null + unset) instead of
		 * calling `curl_close()`; below 8.5 the legacy `curl_close()` path runs
		 * unchanged. Fail-open: when `curl_close()` is unavailable the reference
		 * is dropped on every runtime. Multisite-safe: no option/cache changes.
		 *
		 * Note: the handle is passed by reference and nulled (not only unset)
		 * because `unset()` of a by-reference parameter would leave the caller's
		 * variable untouched; assigning null releases the CurlHandle object in
		 * the caller scope on every supported runtime.
		 *
		 * @since 2.0.0
		 * @since NEXT Moved from Util (REF-015); behavior unchanged.
		 * @param mixed       $ch          cURL handle to release (nulled in the caller scope).
		 * @param string|null $php_version Optional version override for testing; defaults to PHP_VERSION.
		 * @return void
		 */
		public static function close_curl_handle( &$ch, ?string $php_version = null ): void {
			if ( self::is_php85_or_greater( $php_version ) ) {
				$ch = null;
				unset( $ch );
				return;
			}
			if ( function_exists( 'curl_close' ) ) {
				// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated,WordPress.WP.AlternativeFunctions.curl_curl_close -- legacy close path below PHP 8.5 only.
				curl_close( $ch );
				return;
			}
			$ch = null;
			unset( $ch );
		}

		/**
		 * Release a cURL multi handle without triggering the PHP 8.5 deprecation.
		 *
		 * On PHP 8.5+ the multi handle reference is dropped (null + unset)
		 * instead of calling `curl_multi_close()`; below 8.5 the legacy
		 * `curl_multi_close()` path runs unchanged. Fail-open: when
		 * `curl_multi_close()` is unavailable the reference is dropped on
		 * every runtime. Multisite-safe: no option/cache changes.
		 *
		 * @since 2.0.0
		 * @since NEXT Moved from Util (REF-015); behavior unchanged.
		 * @param mixed       $mh          cURL multi handle to release (nulled in the caller scope).
		 * @param string|null $php_version Optional version override for testing; defaults to PHP_VERSION.
		 * @return void
		 */
		public static function close_curl_multi_handle( &$mh, ?string $php_version = null ): void {
			if ( self::is_php85_or_greater( $php_version ) ) {
				$mh = null;
				unset( $mh );
				return;
			}
			if ( function_exists( 'curl_multi_close' ) ) {
				// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated,WordPress.WP.AlternativeFunctions.curl_curl_multi_close -- legacy close path below PHP 8.5 only.
				curl_multi_close( $mh );
				return;
			}
			$mh = null;
			unset( $mh );
		}

		/**
		 * Release a GD image without triggering the PHP 8.5 deprecation.
		 *
		 * On PHP 8.5+ the image reference is dropped (null + unset) instead of
		 * calling `imagedestroy()`; below 8.5 the legacy `imagedestroy()` path
		 * runs unchanged. Fail-open: when `imagedestroy()` is unavailable the
		 * reference is dropped on every runtime. Multisite-safe: no
		 * option/cache changes.
		 *
		 * @since 2.0.0
		 * @since NEXT Moved from Util (REF-015); behavior unchanged.
		 * @param mixed       $image       GD image to release (nulled in the caller scope).
		 * @param string|null $php_version Optional version override for testing; defaults to PHP_VERSION.
		 * @return void
		 */
		public static function destroy_gd_image( &$image, ?string $php_version = null ): void {
			if ( self::is_php85_or_greater( $php_version ) ) {
				$image = null;
				unset( $image );
				return;
			}
			if ( function_exists( 'imagedestroy' ) ) {
				// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- legacy destroy path below PHP 8.5 only.
				imagedestroy( $image );
				return;
			}
			$image = null;
			unset( $image );
		}

		/**
		 * Release a cURL share handle without triggering the PHP 8.5 deprecation.
		 *
		 * On PHP 8.5+ the share handle reference is dropped (null + unset)
		 * instead of calling `curl_share_close()`; below 8.5 the legacy
		 * `curl_share_close()` path runs unchanged. Fail-open: when
		 * `curl_share_close()` is unavailable the reference is dropped on
		 * every runtime. Multisite-safe: no option/cache changes.
		 *
		 * Note: completes the teardown-helper set promised above; no
		 * production call sites use curl_share handles yet, so this is
		 * forward-compat API for future callers.
		 *
		 * @since 2.2.0
		 * @since NEXT Moved from Util (REF-015); behavior unchanged.
		 * @param mixed       $sh          cURL share handle to release (nulled in the caller scope).
		 * @param string|null $php_version Optional version override for testing; defaults to PHP_VERSION.
		 * @return void
		 */
		public static function close_curl_share_handle( &$sh, ?string $php_version = null ): void {
			if ( self::is_php85_or_greater( $php_version ) ) {
				$sh = null;
				unset( $sh );
				return;
			}
			if ( function_exists( 'curl_share_close' ) ) {
				// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated,WordPress.WP.AlternativeFunctions.curl_curl_share_close -- legacy close path below PHP 8.5 only.
				curl_share_close( $sh );
				return;
			}
			$sh = null;
			unset( $sh );
		}

		/**
		 * Release a finfo handle without triggering the PHP 8.5 deprecation.
		 *
		 * On PHP 8.5+ the finfo instance reference is dropped (null + unset)
		 * instead of calling `finfo_close()`; below 8.5 the legacy
		 * `finfo_close()` path runs unchanged. Fail-open: when `finfo_close()`
		 * is unavailable the reference is dropped on every runtime.
		 * Multisite-safe: no option/cache changes.
		 *
		 * Note: completes the teardown-helper set promised above; no
		 * production call sites use finfo handles yet, so this is
		 * forward-compat API for future callers.
		 *
		 * @since 2.2.0
		 * @since NEXT Moved from Util (REF-015); behavior unchanged.
		 * @param mixed       $finfo       Finfo handle to release (nulled in the caller scope).
		 * @param string|null $php_version Optional version override for testing; defaults to PHP_VERSION.
		 * @return void
		 */
		public static function close_finfo_handle( &$finfo, ?string $php_version = null ): void {
			if ( self::is_php85_or_greater( $php_version ) ) {
				$finfo = null;
				unset( $finfo );
				return;
			}
			if ( function_exists( 'finfo_close' ) ) {
				// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- legacy close path below PHP 8.5 only.
				finfo_close( $finfo );
				return;
			}
			$finfo = null;
			unset( $finfo );
		}

		/**
		 * Release an XML parser without triggering the PHP 8.5 deprecation.
		 *
		 * On PHP 8.5+ the parser reference is dropped (null + unset) instead
		 * of calling `xml_parser_free()`; below 8.5 the legacy
		 * `xml_parser_free()` path runs unchanged. Fail-open: when
		 * `xml_parser_free()` is unavailable the reference is dropped on
		 * every runtime. Multisite-safe: no option/cache changes.
		 *
		 * Note: completes the teardown-helper set promised above; no
		 * production call sites use XML parsers yet, so this is
		 * forward-compat API for future callers.
		 *
		 * @since 2.2.0
		 * @since NEXT Moved from Util (REF-015); behavior unchanged.
		 * @param mixed       $parser      XML parser to release (nulled in the caller scope).
		 * @param string|null $php_version Optional version override for testing; defaults to PHP_VERSION.
		 * @return void
		 */
		public static function free_xml_parser( &$parser, ?string $php_version = null ): void {
			if ( self::is_php85_or_greater( $php_version ) ) {
				$parser = null;
				unset( $parser );
				return;
			}
			if ( function_exists( 'xml_parser_free' ) ) {
				// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- legacy free path below PHP 8.5 only.
				xml_parser_free( $parser );
				return;
			}
			$parser = null;
			unset( $parser );
		}

		/**
		 * Return the last HTTP response headers without touching the deprecated global.
		 *
		 * PHP 8.5 deprecates reading the `$http_response_header` global in
		 * favour of `http_get_last_response_headers()` (see
		 * https://www.php.net/manual/en/migration85.deprecated.php). This
		 * wrapper prefers the new engine API when available
		 * (`function_exists` guard) and falls back to the legacy global path
		 * — always initialized and guarded with `isset` — so behaviour on
		 * PHP 8.2 through 8.4 is unchanged. Fail-open: any probe failure
		 * returns an empty array. Multisite-safe: no option/cache changes.
		 *
		 * Note: the legacy fallback reads the headers via the `$GLOBALS`
		 * array with an `isset` guard (never a bare global read), which keeps
		 * the deprecation scanner clean while preserving the 8.2–8.4 path.
		 *
		 * Scope limitation (PHP 8.2–8.4 legacy path): the engine populates
		 * the header store in the local scope of the caller that made the
		 * HTTP-wrapper request, so this static helper only observes headers
		 * for requests made in global scope. Callers inside a
		 * function/method scope should pass their headers explicitly via
		 * `$legacy_source` (best-effort only).
		 *
		 * When the new engine API exists its result is authoritative: a
		 * non-array probe (null/false, i.e. no headers in this scope) is
		 * returned as an empty array instead of consulting the legacy
		 * store, which may hold stale headers from an earlier request
		 * (notably in long-lived PHP-FPM workers).
		 *
		 * @since 2.2.0
		 * @since 2.2.0 `$legacy_source` parameter for the scope-blind legacy path.
		 * @since NEXT Moved from Util (REF-015); behavior unchanged.
		 * @param array|null $legacy_source Optional explicit header lines for the
		 *                                  legacy path (string-filtered).
		 * @return string[] List of response header lines, or empty array when unavailable.
		 */
		public static function get_last_response_headers( ?array $legacy_source = null ): array {
			$headers = array();

			if ( function_exists( 'http_get_last_response_headers' ) ) {
				try {
					$probe = http_get_last_response_headers();
					if ( is_array( $probe ) ) {
						foreach ( array_values( $probe ) as $line ) {
							if ( is_string( $line ) ) {
								$headers[] = $line;
							}
						}
					}
					// Authoritative: a non-array probe means "no headers in
					// this scope" — never fall through to the legacy store,
					// which may hold stale headers from an earlier request.
					return $headers;
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			$legacy = $legacy_source;
			if ( null === $legacy && isset( $GLOBALS['http_response_header'] ) && is_array( $GLOBALS['http_response_header'] ) ) {
				$legacy = $GLOBALS['http_response_header'];
			}

			try {
				if ( is_array( $legacy ) ) {
					foreach ( array_values( $legacy ) as $line ) {
						if ( is_string( $line ) ) {
							$headers[] = $line;
						}
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}

			return $headers;
		}
	}
}
