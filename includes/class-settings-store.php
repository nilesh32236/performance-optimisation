<?php
/**
 * Settings store boundary — wppo_settings memo, writes, hook coherence, snapshots.
 *
 * Focused extraction (REF-002) of the settings-state responsibility cluster
 * previously owned by the god utility `Util`. Owns the per-request
 * `get_option('wppo_settings')` memo (blog-keyed for multisite correctness
 * under `switch_to_blog()`), the `save_settings()` write-through, the
 * `update/add/delete_option_wppo_settings` hook coherence callbacks, and the
 * one-click-undo settings snapshots. `Util::get_settings()` and friends remain
 * as thin facade proxies so all existing callers keep working untouched.
 *
 * Minimal WordPress APIs only: `get_option()`/`update_option()` and
 * `add_action()`, plus `get_current_blog_id()` for multisite memo keying.
 * Depends on nothing else in the plugin.
 *
 * Deliberately OUT (later REF items): `Util::ALLOWED_SETTINGS_KEYS`, the
 * sanitizer map, and `Main`'s defaults/lazy options.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Settings_Store' ) ) {
	/**
	 * Class Settings_Store
	 *
	 * Static settings-state owner. Depends only on the minimal WordPress
	 * APIs required to preserve the existing implementation verbatim:
	 * `get_option()`/`update_option()`, `add_action()`, and
	 * `get_current_blog_id()`. Depends on nothing else in the plugin.
	 *
	 * @since NEXT
	 */
	final class Settings_Store {

		/**
		 * Option name storing the single prior copy of `wppo_settings` for one-click undo.
		 *
		 * Written by {@see self::take_settings_snapshot()} before every settings
		 * save (REST `update_settings`/`import_settings`, WP-CLI
		 * `settings update`/`import`) and consumed by
		 * {@see self::restore_settings_snapshot()}. Multisite-safe: stored via
		 * plain per-site `get_option()`/`update_option()`, never network-wide.
		 *
		 * Mirrored as `Util::SETTINGS_SNAPSHOT_OPTION` for backward
		 * compatibility; the two values must stay identical.
		 *
		 * @since NEXT
		 * @var string
		 */
		public const SETTINGS_SNAPSHOT_OPTION = 'wppo_settings_snapshot';

		/**
		 * Per-request memo for wppo_settings to avoid repeated get_option deserialization.
		 *
		 * Keyed by blog ID for multisite correctness under switch_to_blog().
		 *
		 * @var array<int, array>
		 * @since NEXT
		 */
		private static array $settings_cache = array();

		/**
		 * Whether the settings cache has been populated this request, keyed by blog ID.
		 *
		 * @var array<int, bool>
		 * @since NEXT
		 */
		private static array $settings_cache_loaded = array();

		/**
		 * Resolve current blog ID safely (handles Brain Monkey stub mis-configuration in tests).
		 *
		 * @since NEXT
		 * @return int Blog ID.
		 */
		private static function current_blog_id(): int {
			if ( ! function_exists( 'get_current_blog_id' ) ) {
				return 0;
			}
			try {
				return (int) get_current_blog_id();
			} catch ( \Throwable $e ) {
				return 0;
			}
		}

		/**
		 * Get wppo_settings with per-request memoization.
		 *
		 * Wraps get_option('wppo_settings') with a static cache so up to 6
		 * deserializations per frontend render (Main, Cache, Cron, Used_CSS, etc.)
		 * collapse to a single DB-backed fetch per request. Invalidated automatically
		 * on update/add/delete of the option. Blog-keyed to avoid cross-site
		 * leakage under switch_to_blog() (see F-COMPAT-03).
		 *
		 * @since NEXT
		 * @return array The plugin settings.
		 */
		public static function get_settings(): array {
			$bid = self::current_blog_id();
			if ( ! empty( self::$settings_cache_loaded[ $bid ] ) ) {
				return self::$settings_cache[ $bid ] ?? array();
			}
			self::ensure_settings_cache_hook();
			$raw = get_option( 'wppo_settings', array() );
			if ( ! is_array( $raw ) ) {
				$raw = array();
			}
			self::$settings_cache[ $bid ]        = $raw;
			self::$settings_cache_loaded[ $bid ] = true;
			return $raw;
		}

		/**
		 * Set the settings cache to a known value (e.g. after update_option in same request).
		 *
		 * @since NEXT
		 * @param array $settings The settings to cache.
		 * @return void
		 */
		public static function set_settings_cache( array $settings ): void {
			$bid                                 = self::current_blog_id();
			self::$settings_cache[ $bid ]        = $settings;
			self::$settings_cache_loaded[ $bid ] = true;
			self::ensure_settings_cache_hook();
		}

		/**
		 * Persist wppo_settings with autoload disabled (audit #1325).
		 *
		 * Single owner for settings writes: the multi-tab array must never
		 * sit in alloptions. Refreshes the per-request memo on success so
		 * same-request reads observe the write.
		 *
		 * @since NEXT
		 * @param array $settings Settings array to store.
		 * @return bool True on success (mirrors update_option()).
		 */
		public static function save_settings( array $settings ): bool {
			try {
				$updated = update_option( 'wppo_settings', $settings, false );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
			if ( $updated ) {
				try {
					self::set_settings_cache( $settings );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			return (bool) $updated;
		}

		/**
		 * Clear the settings memo (e.g. in tests or on delete).
		 *
		 * When called without args clears all blog entries (test isolation).
		 * When called with a blog ID clears that blog only. The WP
		 * delete_option_wppo_settings action passes no blog ID, so the full
		 * clear path is taken. switch_blog is handled by on_switch_blog().
		 *
		 * Note: the purge-fallback gate memo lives in `Util` (not settings
		 * state); `Util::clear_settings_cache()` clears it alongside this
		 * delegate call.
		 *
		 * @since NEXT
		 * @param int|null $blog_id Optional blog ID to clear. Null clears all.
		 * @return void
		 */
		public static function clear_settings_cache( $blog_id = null ): void {
			if ( null !== $blog_id && is_int( $blog_id ) ) {
				$bid = (int) $blog_id;
				unset( self::$settings_cache[ $bid ], self::$settings_cache_loaded[ $bid ] );
				return;
			}
			// Action callbacks (update/delete) pass $old/$new or $option/$value
			// which are not int blog IDs; treat non-int as "clear all" for
			// backwards-compat with the pre-blog-keyed API.
			if ( null !== $blog_id && ! is_int( $blog_id ) ) {
				self::$settings_cache        = array();
				self::$settings_cache_loaded = array();
				return;
			}
			self::$settings_cache        = array();
			self::$settings_cache_loaded = array();
		}

		/**
		 * Handler for switch_blog — settings side.
		 *
		 * The memo is blog-keyed and needs no destructive clear (keys already
		 * isolate sites); this endpoint exists so `Util::on_switch_blog()`
		 * can delegate the settings part here while keeping the permalink and
		 * callback-secret clears local to `Util` (dependency direction:
		 * features → Settings_Store → WP core; this class references nothing
		 * else in the plugin).
		 *
		 * Kept separate from clear_settings_cache for hook arity clarity.
		 *
		 * @since NEXT
		 * @param int $new_blog_id New blog ID.
		 * @param int $prev_blog_id Previous blog ID.
		 * @return void
		 */
		public static function on_switch_blog( $new_blog_id, $prev_blog_id ): void {
			// The hook params are unused (keys already isolate) — consumed
			// explicitly to satisfy the unused-parameter sniff.
			unset( $new_blog_id, $prev_blog_id );
		}

		/**
		 * Register the settings-cache invalidation hooks eagerly.
		 *
		 * The invalidation hooks only need to exist before the first
		 * update/add/delete of `wppo_settings` in the request. Registering
		 * them at plugin boot (in {@see Main::__construct()}) makes
		 * invalidation deterministic instead of relying on the lazy
		 * first-call registration inside get_settings(); the lazy path
		 * remains as a backstop for entry points that bypass Main. Safe to
		 * call multiple times — a static guard makes the registration
		 * idempotent.
		 *
		 * Note: the `switch_blog` hook stays owned by `Util`
		 * (`Util::on_switch_blog()`, which delegates here) because its
		 * callback also clears the permalink and callback-secret memos that
		 * live in `Util`.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function register_settings_cache_hooks(): void {
			self::ensure_settings_cache_hook();
		}

		/**
		 * Ensure the invalidation hooks for wppo_settings are registered once per request.
		 *
		 * @since NEXT
		 * @return void
		 */
		private static function ensure_settings_cache_hook(): void {
			static $hooked = false;
			if ( $hooked ) {
				return;
			}
			$hooked = true;
			add_action( 'update_option_wppo_settings', array( self::class, 'on_settings_update' ), 10, 2 );
			add_action( 'add_option_wppo_settings', array( self::class, 'on_settings_add' ), 10, 2 );
			add_action( 'delete_option_wppo_settings', array( self::class, 'clear_settings_cache' ) );
		}

		/**
		 * Invalidate/update the memo when wppo_settings is updated.
		 *
		 * @since NEXT
		 * @param mixed $old_value Previous value.
		 * @param mixed $value New value.
		 * @return void
		 */
		public static function on_settings_update( $old_value, $value ): void {
			$bid                                 = self::current_blog_id();
			self::$settings_cache[ $bid ]        = is_array( $value ) ? $value : array();
			self::$settings_cache_loaded[ $bid ] = true;
		}

		/**
		 * Populate the memo when wppo_settings is added.
		 *
		 * @since NEXT
		 * @param string $option Option name.
		 * @param mixed  $value Option value.
		 * @return void
		 */
		public static function on_settings_add( $option, $value ): void {
			if ( 'wppo_settings' === $option ) {
				$bid                                 = self::current_blog_id();
				self::$settings_cache[ $bid ]        = is_array( $value ) ? $value : array();
				self::$settings_cache_loaded[ $bid ] = true;
			}
		}

		/**
		 * Read the stored prior-settings snapshot.
		 *
		 * @since NEXT
		 * @return array|null Snapshot array with `settings` + `taken_at` keys, or null when absent/malformed.
		 */
		public static function get_settings_snapshot(): ?array {
			try {
				if ( ! function_exists( 'get_option' ) ) {
					return null;
				}
				$snapshot = get_option( self::SETTINGS_SNAPSHOT_OPTION, null );
				if ( ! is_array( $snapshot ) || ! isset( $snapshot['settings'] ) || ! is_array( $snapshot['settings'] ) ) {
					return null;
				}
				return $snapshot;
			} catch ( \Throwable $e ) {
				unset( $e );
				return null;
			}
		}

		/**
		 * Store the given settings as the one-click-undo snapshot.
		 *
		 * Fail-open: any failure returns false and must never block the
		 * settings save that triggered it.
		 *
		 * @since NEXT
		 * @param array|null $settings Settings to snapshot (defaults to the current stored settings).
		 * @return bool True when the snapshot was written.
		 */
		public static function take_settings_snapshot( ?array $settings = null ): bool {
			try {
				if ( ! function_exists( 'update_option' ) ) {
					return false;
				}
				if ( null === $settings ) {
					$settings = self::get_settings();
				}
				$payload = array(
					'settings' => $settings,
					'taken_at' => function_exists( 'time' ) ? time() : 0,
				);
				return (bool) update_option( self::SETTINGS_SNAPSHOT_OPTION, $payload, false );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Restore `wppo_settings` from the stored snapshot.
		 *
		 * Fail-open: snapshot-restore failure leaves the current settings
		 * intact and returns null; never fatal.
		 *
		 * @since NEXT
		 * @return array|null The restored settings array, or null when no valid snapshot exists or the write failed.
		 */
		public static function restore_settings_snapshot(): ?array {
			try {
				$snapshot = self::get_settings_snapshot();
				if ( null === $snapshot ) {
					return null;
				}
				if ( ! function_exists( 'update_option' ) ) {
					return null;
				}
				$restored = $snapshot['settings'];
				// The memo holds the pre-restore DB state (kept fresh for
				// same-request writes by the update_option_wppo_settings
				// hook), so update_option()'s ambiguous false return can be
				// disambiguated without a direct get_option() read: false
				// with identical values means "unchanged" (success), false
				// with differing values means the write failed.
				$before  = self::get_settings();
				$updated = self::save_settings( $restored );
				self::set_settings_cache( $restored );
				if ( ! $updated && $before !== $restored ) {
					// Write failed: roll the memo back so it keeps
					// describing the intact current settings (fail-open).
					self::set_settings_cache( $before );
					return null;
				}
				return $restored;
			} catch ( \Throwable $e ) {
				unset( $e );
				return null;
			}
		}
	}
}
