<?php
/**
 * Cache key boundary — key construction for transients, options, salts, and stampede guards.
 *
 * Focused extraction (REF-001) of the cache-key responsibility cluster previously
 * owned by the god utility `Util`. Key *construction* only: no storage I/O, no
 * lock acquire/release, no TTL policy. `Util::transient_key()` and friends remain
 * as one-line facade proxies so all existing callers keep working untouched.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Cache_Key' ) ) {
	/**
	 * Class Cache_Key
	 *
	 * Static key-construction boundary. Depends only on the minimal WordPress
	 * APIs required to preserve the existing implementation verbatim:
	 * `is_multisite()`, `get_current_blog_id()`, and `get_option()` (for
	 * `cache_salt()` only). Depends on nothing else in the plugin.
	 *
	 * @since 2.4.0
	 */
	final class Cache_Key {

		/**
		 * Qualify a transient key with the current blog ID on multisite.
		 *
		 * Prevents transient key collisions when a shared object cache backend
		 * (Redis, Memcached) is present. On single-site installs the key is
		 * returned unchanged. For option names use {@see option_key()} instead.
		 *
		 * @since 2.4.0
		 * @param string $key The bare transient key.
		 * @return string Blog-ID-prefixed key on multisite, or the original key.
		 */
		public static function transient_key( string $key ): string {
			if ( ! function_exists( 'is_multisite' ) ) {
				return $key;
			}
			try {
				return is_multisite() ? (string) get_current_blog_id() . '_' . $key : $key;
			} catch ( \Throwable $e ) {
				return $key;
			}
		}

		/**
		 * Qualify an option name with the current blog ID on multisite.
		 *
		 * Same blog-ID-prefixing semantics as {@see transient_key()}, but for
		 * option names (get_option/update_option/delete_option). Kept as a
		 * separate method so transient and option namespaces stay distinct
		 * and can diverge in the future. On single-site installs the key is
		 * returned unchanged.
		 *
		 * @since 2.4.0
		 * @param string $key The bare option name.
		 * @return string Blog-ID-prefixed option name on multisite, or the original name.
		 */
		public static function option_key( string $key ): string {
			if ( ! function_exists( 'is_multisite' ) ) {
				return $key;
			}
			try {
				return is_multisite() ? (string) get_current_blog_id() . '_' . $key : $key;
			} catch ( \Throwable $e ) {
				return $key;
			}
		}

		/**
		 * Derive the stale-copy transient key for a guarded value key.
		 *
		 * Callers pass an already blog-qualified `$key` (via transient_key()),
		 * so naively prefixing again would produce a double blog prefix like
		 * `3_3_wppo_audit_..._stale` on multisite. When `$key` already carries
		 * the current blog prefix only `_stale` is appended; bare keys are
		 * still qualified via {@see transient_key()} so they stay isolated.
		 *
		 * @since 2.4.0
		 * @param string $key Value cache key as passed to get_with_stampede_lock().
		 * @return string Stale-copy key.
		 */
		public static function stampede_stale_key( string $key ): string {
			try {
				if ( function_exists( 'is_multisite' ) && function_exists( 'get_current_blog_id' ) && is_multisite() ) {
					$prefix = (string) get_current_blog_id() . '_';
					if ( '' !== $prefix && str_starts_with( $key, $prefix ) ) {
						return $key . '_stale';
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			// Single-site, or a bare key on multisite: qualify normally.
			// On single-site transient_key() returns the key unchanged, so a
			// caller-passed prefixed key is never double-prefixed there.
			if ( function_exists( 'is_multisite' ) ) {
				try {
					if ( ! is_multisite() ) {
						return $key . '_stale';
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					return $key . '_stale';
				}
			}
			return self::transient_key( $key . '_stale' );
		}

		/**
		 * Current value of a salted-cache salt (option-backed).
		 *
		 * The WP 6.9+ salted cache family compares the salt VALUE passed at
		 * read/write time against the value stored alongside each entry, so
		 * callers must pass the current option value — not the option key.
		 * Passing the key name instead would make every salt bump a no-op
		 * (the comparison never changes) and reduce invalidation to the
		 * entry TTL alone.
		 *
		 * @since 2.4.0
		 * @param string $option Option key holding the salt.
		 * @return string Current salt value ('0' until the first bump).
		 */
		public static function cache_salt( string $option ): string {
			$value = get_option( $option, '0' );
			// false (unset option) maps to the '0' sentinel, never ''.
			if ( ! is_scalar( $value ) || false === $value ) {
				return '0';
			}
			return (string) $value;
		}
	}
}
