<?php
/**
 * WP version gate boundary — single home for WordPress core version checks.
 *
 * Centralizes the scattered `$GLOBALS['wp_version']` reads plus
 * `version_compare()` gates previously duplicated across Main, Cache, and
 * Util with slightly divergent spelling (REF-010, FINAL review R-11). Two
 * read semantics are preserved side by side because the historic call sites
 * disagree on the unknown-version default:
 *
 * - `is_at_least()` — canonical read: non-empty `$GLOBALS['wp_version']`,
 *   then `get_bloginfo( 'version' )`, then `$fallback` (fail-false). Used by
 *   the native-API predicates that already probed both sources.
 * - `is_global_at_least()` — `$GLOBALS` read only, no `get_bloginfo()`
 *   fallback; an absent global returns `$fallback` (assume-newest). Used by
 *   the inline-budget and block-asset gates that never consulted
 *   `get_bloginfo()` — falling back to it here would flip their
 *   unknown-version branch (the test suite pins `get_bloginfo()` to 6.8
 *   while unsetting the global to exercise the newest default).
 *
 * Pure static, no state. Depends only on WP core (`$wp_version`,
 * `get_bloginfo()`, `version_compare()`); nothing else in the plugin.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Wp_Version' ) ) {
	/**
	 * Class Wp_Version
	 *
	 * Static version-gate boundary. Depends only on the minimal WordPress
	 * APIs required to preserve the existing implementations verbatim:
	 * `$GLOBALS['wp_version']`, `get_bloginfo()`, and `version_compare()`.
	 * Depends on nothing else in the plugin; features depend on this class,
	 * never the reverse.
	 *
	 * @since NEXT
	 */
	final class Wp_Version {

		/**
		 * Current WordPress version string.
		 *
		 * A non-empty `$GLOBALS['wp_version']` wins (the value core sets in
		 * wp-includes/version.php); otherwise `get_bloginfo( 'version' )`
		 * when available; otherwise `''` (unknown). Fail-open: a throwing
		 * `get_bloginfo()` yields `''` instead of a fatal so version-gated
		 * callers degrade to their documented `$fallback`.
		 *
		 * @since NEXT
		 * @return string Version string, or '' when unknown.
		 */
		public static function current(): string {
			if ( isset( $GLOBALS['wp_version'] ) && is_string( $GLOBALS['wp_version'] ) && '' !== $GLOBALS['wp_version'] ) {
				return $GLOBALS['wp_version'];
			}
			if ( ! function_exists( 'get_bloginfo' ) ) {
				return '';
			}
			try {
				return (string) get_bloginfo( 'version' );
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Whether core is at least the given version (canonical read).
		 *
		 * Reads via {@see current()}: the `$GLOBALS['wp_version']` global,
		 * then `get_bloginfo( 'version' )`. An unknown version returns
		 * `$fallback` (fail-false), matching the native-API predicates
		 * (`supports_native_defer_strategy()`,
		 * `supports_native_script_fetchpriority()`,
		 * `should_use_core_template_buffer()`, `supports_script_strategy()`).
		 *
		 * @since NEXT
		 * @param string $version  Minimum version (e.g. '6.3-alpha').
		 * @param bool   $fallback Result when the version is unknown.
		 * @return bool True when core is at least $version.
		 */
		public static function is_at_least( string $version, bool $fallback = false ): bool {
			$current = self::current();
			if ( '' === $current ) {
				return $fallback;
			}
			return (bool) version_compare( $current, $version, '>=' );
		}

		/**
		 * Whether `$GLOBALS['wp_version']` is at least the given version (global-only read).
		 *
		 * Reads the `$GLOBALS['wp_version']` global only — deliberately no
		 * `get_bloginfo()` fallback, preserving the inline-budget and
		 * block-asset gates byte-identical (they never consulted
		 * `get_bloginfo()`, and an absent global assumes the newest core).
		 * An absent global returns `$fallback` (assume-newest); a present
		 * global — even `''` — is compared as-is, exactly like the historic
		 * `isset()`-guarded `version_compare()` spellings.
		 *
		 * @since NEXT
		 * @param string $version  Minimum version (e.g. '6.9-alpha').
		 * @param bool   $fallback Result when the global is absent.
		 * @return bool True when the global reads at least $version.
		 */
		public static function is_global_at_least( string $version, bool $fallback = true ): bool {
			if ( ! isset( $GLOBALS['wp_version'] ) ) {
				return $fallback;
			}
			return (bool) version_compare( (string) $GLOBALS['wp_version'], $version, '>=' );
		}
	}
}
