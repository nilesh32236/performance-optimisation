<?php
/**
 * Settings write command — single orchestration seam for `wppo_settings` writes.
 *
 * P3-008: `Settings_Store` owns validation, snapshots, memoization, and the
 * canonical `save_settings()` write-through. This narrow static command owns
 * only the write orchestration that previously lived inline at each call
 * site (REST partial saves, REST import, REST safe-mode toggles, snapshot
 * restore): merge the incoming slice, snapshot-if-changed (fail-open), then
 * delegate the write to `Settings_Store::save_settings()`.
 *
 * Deliberately OUT: validation, sanitization, redaction, and snapshot policy
 * all stay on `Settings_Store` (or at the REST/CLI call site for
 * request-specific preserve/strip rules). This class performs no sanitizing
 * and no permission checks; callers sanitize before invoking and gate via
 * their existing `manage_options` + throttle paths.
 *
 * Depends on `Settings_Store` (merge targets, snapshot, canonical write)
 * only. The safe-mode enable payload builder is injected as a callable by
 * the REST call site (which already depends on `Main`) so this
 * infrastructure-layer command never reaches up to the Core orchestrator.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Settings_Command' ) ) {
	/**
	 * Class Settings_Command
	 *
	 * Static write-orchestration owner for `wppo_settings`. Every method
	 * snapshots the prior settings only when the merged result differs
	 * (fail-open: snapshot failure never blocks the save) and then writes
	 * through `Settings_Store::save_settings()` so the memo stays coherent.
	 *
	 * @since NEXT
	 */
	final class Settings_Command {

		/**
		 * Merge a sanitized tab slice over the current settings and persist it.
		 *
		 * Mirrors the `Rest_Settings::update_settings()` partial-save merge:
		 * the tab value is replaced only when no prior tab array exists,
		 * otherwise the sanitized slice merges over the prior tab so a
		 * partial POST never deletes sibling keys.
		 *
		 * The write result is returned for compatibility but REST update call
		 * sites intentionally ignore a false (same as the pre-P3-008 inline
		 * `update_option()` whose return was ignored on this path).
		 *
		 * @since NEXT
		 * @param array  $current   Current `wppo_settings` array.
		 * @param string $tab       Settings tab slug.
		 * @param array  $sanitized Sanitized tab slice (already sanitized by the caller).
		 * @return array Tuple of (merged options array, bool write result).
		 */
		public static function save_tab( array $current, string $tab, array $sanitized ): array {
			$options = $current;
			// Merge into the existing tab (issue #1216): a partial POST (e.g.
			// only autoLcpPreload from an older client) must not delete sibling
			// keys like enablePreloadCache or preloadFontsUrls. The tab value is
			// replaced only when no prior tab array exists.
			$prior_tab       = ( isset( $current[ $tab ] ) && is_array( $current[ $tab ] ) ) ? $current[ $tab ] : array();
			$options[ $tab ] = array_merge( $prior_tab, $sanitized );

			// One-click undo (issue #1144): snapshot the prior settings before
			// overwriting, but skip no-op saves so an identical write does not
			// churn the single-slot snapshot. Fail-open: a snapshot failure
			// must never block the save.
			if ( $options !== $current ) {
				try {
					Settings_Store::take_settings_snapshot( $current );
				} catch ( \Throwable $snapshot_error ) {
					unset( $snapshot_error );
				}
			}

			$saved = Settings_Store::save_settings( $options );

			return array( $options, $saved );
		}

		/**
		 * Merge sanitized import settings over the current settings and persist them.
		 *
		 * Mirrors the `Rest_Settings::import_settings()` merge: recursive
		 * replace so newer setting keys from future plugin versions are
		 * preserved. No-op imports (merged identical to existing) skip both
		 * the snapshot and the write and report success, preserving the
		 * caller's 200 no-change branch; changed imports snapshot first
		 * (fail-open) and report the canonical write result so the caller
		 * keeps its 500-failure branch.
		 *
		 * @since NEXT
		 * @param array $existing  Current `wppo_settings` array.
		 * @param array $sanitized Sanitized import slice (already sanitized by the caller).
		 * @return array Tuple of (merged settings array, bool write result).
		 */
		public static function save_merged( array $existing, array $sanitized ): array {
			// Retrieve the existing settings and merge the imported settings on top,
			// so newer setting keys from future plugin versions are preserved.
			$merged = array_replace_recursive( $existing, $sanitized );

			// Check if the settings are the same: no-op imports skip the
			// snapshot and the write (success, nothing to persist).
			if ( $existing === $merged ) {
				return array( $merged, true );
			}

			// One-click undo (issue #1144): snapshot the prior settings before
			// overwriting. Fail-open: a snapshot failure must never block the save.
			try {
				Settings_Store::take_settings_snapshot( $existing );
			} catch ( \Throwable $snapshot_error ) {
				unset( $snapshot_error );
			}

			$saved = Settings_Store::save_settings( $merged );

			return array( $merged, $saved );
		}

		/**
		 * Toggle the `file_optimisation.safeMode` flag and persist the settings.
		 *
		 * Mirrors the `Rest::handle_safe_mode()` transition semantics:
		 * snapshot only on enable while safe mode is off (snapshotting on
		 * disable would overwrite the pre-enable undo state with the
		 * safeMode=true state, so a later restore would wrongly re-enable
		 * safe mode instead of the original config), build the enable
		 * payload via `Main::build_safe_mode_enable_payload()` with a
		 * fail-open `safeMode = true` fallback, then write through
		 * `Settings_Store::save_settings()`.
		 *
		 * Telemetry invalidation, page-cache purge, and response redaction
		 * stay at the REST call site; this method only transitions + writes.
		 * The enable payload builder is caller-injected (REST passes
		 * `Main::build_safe_mode_enable_payload()` behind its existing
		 * `class_exists`/`method_exists` guards); a null builder or a
		 * builder failure falls back to setting `safeMode = true`.
		 *
		 * @since NEXT
		 * @param array         $options Current `wppo_settings` array.
		 * @param string        $action  Either 'enable' or 'disable'.
		 * @param callable|null $enable_payload_builder Optional builder receiving the `file_optimisation` slice and returning the enable payload.
		 * @return array Updated `wppo_settings` array.
		 */
		public static function toggle_safe_mode( array $options, string $action, ?callable $enable_payload_builder = null ): array {
			if ( ! isset( $options['file_optimisation'] ) || ! is_array( $options['file_optimisation'] ) ) {
				$options['file_optimisation'] = array();
			}
			// Snapshot only on enable while safe mode is off: snapshotting
			// on disable would overwrite the pre-enable undo state with
			// the safeMode=true state, so a later restore would wrongly
			// re-enable safe mode instead of the original config.
			$was_safe = ! empty( $options['file_optimisation']['safeMode'] );
			if ( 'enable' === $action && ! $was_safe ) {
				try {
					Settings_Store::take_settings_snapshot( $options );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( 'enable' === $action ) {
				$built = null;
				if ( null !== $enable_payload_builder ) {
					try {
						$built = $enable_payload_builder( $options['file_optimisation'] );
					} catch ( \Throwable $e ) {
						unset( $e );
						$built = null;
					}
				}
				if ( is_array( $built ) ) {
					$options['file_optimisation'] = $built;
				} else {
					$options['file_optimisation']['safeMode'] = true;
				}
			} else {
				$options['file_optimisation']['safeMode'] = false;
			}

			Settings_Store::save_settings( $options );

			return $options;
		}

		/**
		 * Restore `wppo_settings` from the stored one-click-undo snapshot.
		 *
		 * Thin delegate to `Settings_Store::restore_settings_snapshot()`
		 * (the snapshot policy owner). Fail-open: returns null when no valid
		 * snapshot exists or the write failed, leaving current settings intact.
		 *
		 * @since NEXT
		 * @return array|null The restored settings array, or null on failure.
		 */
		public static function restore(): ?array {
			return Settings_Store::restore_settings_snapshot();
		}
	}
}
