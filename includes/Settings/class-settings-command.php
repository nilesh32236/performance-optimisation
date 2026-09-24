<?php
/**
 * Settings write command — bounded orchestration for wppo_settings writes.
 *
 * Routes callers through the canonical Settings_Store without moving schema,
 * validation, snapshot, or memo policy out of that owner.
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
	 * Narrow command seam for settings writes.
	 *
	 * The command may request one fail-open snapshot before a changed write,
	 * then delegates persistence and memo coherence to Settings_Store.
	 *
	 * @since NEXT
	 */
	final class Settings_Command {

		/**
		 * Save a settings array, optionally snapshotting changed prior settings.
		 *
		 * A null prior value means the caller explicitly owns no snapshot.
		 * Equal prior/current values never churn the one-click undo snapshot.
		 * Snapshot failures remain fail-open and do not block the canonical write.
		 *
		 * @since NEXT
		 * @param array      $settings Settings to persist.
		 * @param array|null $prior_settings Prior settings to snapshot when changed.
		 * @return bool Settings_Store write result.
		 */
		public static function save( array $settings, ?array $prior_settings = null ): bool {
			if ( null !== $prior_settings && $prior_settings !== $settings ) {
				try {
					Settings_Store::take_settings_snapshot( $prior_settings );
				} catch ( \Throwable $snapshot_error ) {
					unset( $snapshot_error );
				}
			}
			return Settings_Store::save_settings( $settings );
		}
		/**
		 * Restore the prior settings snapshot through Settings_Store.
		 *
		 * @since NEXT
		 * @return array|null Restored settings, or null when no valid restore occurred.
		 */
		public static function restore_snapshot(): ?array {
			return Settings_Store::restore_settings_snapshot();
		}
	}
}
