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
 * Pure static; the only state is the memoized `get_bloginfo()` fallback
 * (reset via `reset_memo()` in tests). Depends only on WP core (`$wp_version`,
 * `get_bloginfo()`, `version_compare()`); nothing else in the plugin.
 *
 * Chooser rule: native-API probes use `is_at_least()`; inline-budget and
 * block-asset gates use `is_global_at_least()` — do not swap the defaults.
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
		 * Memoized `get_bloginfo( 'version' )` resolution for the no-global path.
		 *
		 * The `$GLOBALS['wp_version']` read itself is never memoized (it is a
		 * plain global read, and fixtures may override it mid-request in
		 * tests), so only the `get_bloginfo()` fallback — the sole WP call on
		 * this path — is cached. Production resolves it once per request no
		 * matter how many gates run (defer + fetchpriority + template buffer
		 * + script strategy).
		 *
		 * @since NEXT
		 * @var string|null
		 */
		private static ?string $memo = null;

		/**
		 * Whether $memo holds a resolved value (it may legitimately be '').
		 *
		 * @since NEXT
		 * @var bool
		 */
		private static bool $memo_ready = false;

		/**
		 * Reset the memoized version resolution (tests only).
		 *
		 * The bootstrap resets this before every test and the gate parity
		 * tests reset it on each fixture install, so mid-suite
		 * `$GLOBALS['wp_version']` / `get_bloginfo()` fixture changes stay
		 * fresh. Production never calls this (one version per request).
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function reset_memo(): void {
			self::$memo       = null;
			self::$memo_ready = false;
		}

		/**
		 * Current WordPress version string.
		 *
		 * A non-empty `$GLOBALS['wp_version']` wins (the value core sets in
		 * wp-includes/version.php); otherwise `get_bloginfo( 'version' )`
		 * when available; otherwise `''` (unknown). Fail-open: a throwing
		 * `get_bloginfo()` yields `''` instead of a fatal so version-gated
		 * callers degrade to their documented `$fallback`.
		 *
		 * Canonicalization note (REF-010): an explicitly empty-string
		 * `$wp_version` global falls through to `get_bloginfo()`, matching
		 * the majority pre-REF-010 spelling. Only the two pre-REF-010
		 * speculation 6.8 reads used a bare `isset()` cast and compared `''`
		 * as-is (failing their gate); core never produces an empty
		 * `$wp_version` (wp-includes/version.php always sets a non-empty
		 * string), so the central gate adopts the majority spelling and the
		 * parity test pins the documented outcome.
		 *
		 * @since NEXT
		 * @return string Version string, or '' when unknown.
		 */
		public static function current(): string {
			if ( isset( $GLOBALS['wp_version'] ) && is_string( $GLOBALS['wp_version'] ) && '' !== $GLOBALS['wp_version'] ) {
				// Fresh global read every call; drop any stale bloginfo memo
				// so global/unset alternations never reuse an older fallback.
				self::$memo       = null;
				self::$memo_ready = false;
				return $GLOBALS['wp_version'];
			}
			if ( self::$memo_ready ) {
				return self::$memo ?? '';
			}
			if ( ! function_exists( 'get_bloginfo' ) ) {
				self::$memo       = '';
				self::$memo_ready = true;
				return '';
			}
			try {
				$resolved = (string) get_bloginfo( 'version' );
			} catch ( \Throwable $e ) {
				unset( $e );
				$resolved = '';
			}
			self::$memo       = $resolved;
			self::$memo_ready = true;
			return $resolved;
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
