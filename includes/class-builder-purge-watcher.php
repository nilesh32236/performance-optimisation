<?php
/**
 * Builder-purge watcher — purge stale page-builder CSS on builder updates.
 *
 * Elementor/Divi/Bricks/WPBakery updates can rename or delete generated CSS
 * files (e.g. Elementor post-css in uploads/elementor/css/) while WPPO's
 * static HTML cache still references them, producing 404 stylesheets and
 * broken layouts. This watcher hooks upgrader_process_complete, gated to
 * known builder plugin/theme slugs via a data-driven, filter-extensible map,
 * and purges the affected builder cache directories plus WPPO's derived
 * caches (page cache, used-CSS, critical-CSS).
 *
 * No settings UI and no REST route: the watcher is always on and self-gates
 * to builder updates only (early return otherwise, including WPPO's own
 * update path, whose plugin slug is not in the map).
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Builder_Purge_Watcher' ) ) {
	/**
	 * Purge builder + WPPO caches after a page-builder update.
	 *
	 * @since 2.0.0
	 */
	class Builder_Purge_Watcher {

		/**
		 * Transient key for the one-time admin notice after a purge.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		public const NOTICE_TRANSIENT = 'wppo_builder_purge_notice';

		/**
		 * Hook + group for the deferred builder-drift purge.
		 *
		 * The post-less `elementor/core/files/clear_cache` signal is fired
		 * inside ordinary editor/front-end requests, where the heavy
		 * full-site purge (page cache + all used-CSS + queued regeneration)
		 * must not run synchronously (issue #1023 follow-up, audit #6). The
		 * signal only schedules this single background event; the callback
		 * runs the heavy path later.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		public const DRIFT_PURGE_HOOK = 'wppo_builder_drift_purge';

		/**
		 * Transient key for the drift-purge enqueue lock.
		 *
		 * Prevents a burst of builder drift signals (or two concurrent
		 * requests) from enqueueing duplicate background purges. Keyed per
		 * site via Util::transient_key() for multisite safety.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		public const DRIFT_PURGE_LOCK = 'wppo_builder_drift_purge_lock';

		/**
		 * Known page builders, keyed by builder slug.
		 *
		 * Each entry holds a human label, plugin-file slugs, theme directory
		 * slugs (Divi/Bricks ship as themes), regenerable cache directories
		 * relative to the uploads basedir (upload_subdirs) or to
		 * WP_CONTENT_DIR (content_subdirs, e.g. Divi's et-cache), and
		 * builder-native regeneration actions fired best-effort when a
		 * listener is registered. Entries whose directory layout is not
		 * documented as fully regenerable set css_only so only *.css files
		 * (never the whole directory tree) are removed.
		 *
		 * Slugs verified against wordpress.org / vendor distributions at
		 * implementation time (issue #907); hosts and themes can extend or
		 * correct the map via the wppo_builder_purge_map filter.
		 *
		 * @since 2.0.0
		 * @var array<string,array{label:string,plugins:string[],themes:string[],upload_subdirs:string[],content_subdirs:string[],clear_hooks:string[],css_only?:bool}>
		 */
		private const BUILDER_MAP = array(
			'elementor' => array(
				'label'           => 'Elementor',
				'plugins'         => array( 'elementor/elementor.php', 'elementor-pro/elementor-pro.php' ),
				'themes'          => array(),
				'upload_subdirs'  => array( 'elementor/css' ),
				'content_subdirs' => array(),
				'clear_hooks'     => array( 'elementor/core/files/clear_cache' ),
			),
			'divi'      => array(
				'label'           => 'Divi',
				'plugins'         => array( 'divi-builder/divi-builder.php' ),
				'themes'          => array( 'Divi' ),
				'upload_subdirs'  => array(),
				'content_subdirs' => array( 'et-cache' ),
				'clear_hooks'     => array( 'et_core_cache_clear' ),
			),
			'bricks'    => array(
				'label'           => 'Bricks',
				'plugins'         => array(),
				'themes'          => array( 'bricks' ),
				'upload_subdirs'  => array( 'bricks' ),
				'content_subdirs' => array(),
				'clear_hooks'     => array(),
				'css_only'        => true,
			),
			'wpbakery'  => array(
				'label'           => 'WPBakery',
				'plugins'         => array( 'js_composer/js_composer.php' ),
				'themes'          => array(),
				'upload_subdirs'  => array( 'js_composer' ),
				'content_subdirs' => array(),
				'clear_hooks'     => array(),
				'css_only'        => true,
			),
		);

		/**
		 * Re-entrancy guard for the drift fan-out (issue #1023).
		 *
		 * The regeneration fan-out fires elementor/core/files/clear_cache,
		 * which this watcher also listens to via on_builder_drift(). The flag is
		 * set around the fan-out do_action() so the upgrader path cannot re-enter
		 * on_builder_drift() and purge/queue twice.
		 *
		 * @since 2.0.0
		 * @var bool
		 */
		private static bool $drift_suspended = false;

		/**
		 * Per-request dedupe for the drift signal (issue #1023).
		 *
		 * Elementor/core/files/clear_cache can fire several times per request
		 * during editing; the full derived-cache purge below runs at most once
		 * per request so a hot signal cannot stampede the cache.
		 *
		 * @since 2.0.0
		 * @var bool
		 */
		private static bool $drift_handled_this_request = false;

		/**
		 * Register the upgrader hook plus builder-drift hooks.
		 *
		 * Drift hooks (issue #1023) listen to Elementor asset-regen signals
		 * so editing in a builder requeues used-CSS regeneration instead of
		 * leaving stale used CSS until manual regen/cron.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public function register(): void {
			add_action( 'upgrader_process_complete', array( $this, 'on_builder_update' ), 10, 2 );
			add_action( 'elementor/core/files/clear_cache', array( $this, 'on_builder_drift' ), 10, 0 );
			add_action( 'elementor/editor/after_save', array( $this, 'on_builder_drift_save' ), 10, 2 );
			add_action( self::DRIFT_PURGE_HOOK, array( $this, 'run_deferred_drift_purge' ), 10, 0 );
		}

		/**
		 * Handle Elementor asset-regen signals: defer the derived-cache purge.
		 *
		 * Fires on elementor/core/files/clear_cache (CSS regen). The signal
		 * carries no post context, so the only safe action is a full-site
		 * purge — but this signal can fire inside an ordinary editor /
		 * front-end request, where the heavy path must not run inline.
		 * Instead this schedules a single background purge
		 * (DRIFT_PURGE_HOOK) and returns immediately:
		 *
		 *  - Action Scheduler is used when available (the plugin bundles it),
		 *    deduped via as_has_scheduled_action().
		 *  - Otherwise `wp_schedule_single_event()` is used, deduped via
		 *    `wp_next_scheduled()`.
		 *
		 * A short-lived transient lock throttles the enqueue across
		 * concurrent requests, and a per-request flag collapses repeated
		 * signals in one request. Skipped while the upgrader fan-out is
		 * firing (re-entrancy guard) — the upgrader path already purges via
		 * purge_for_builders(). Guarded so a builder failure can never break
		 * the request; fail-open keeps full CSS serving. Post-scoped drift is
		 * handled by on_builder_drift_save() (the fast path).
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public function on_builder_drift(): void {
			if ( self::$drift_suspended || self::$drift_handled_this_request ) {
				return;
			}
			if ( function_exists( 'doing_action' ) && doing_action( 'upgrader_process_complete' ) ) {
				return;
			}
			self::$drift_handled_this_request = true;
			try {
				if ( ! $this->schedule_deferred_drift_purge() ) {
					return;
				}
				if ( function_exists( 'do_action' ) ) {
					/**
					 * Fires after builder-drift requeue (issue #1023).
					 *
					 * @since 2.0.0
					 */
					do_action( 'wppo_builder_drift_requeue' );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Schedule the heavy drift purge as a single background event.
		 *
		 * De-duplicates with a short-lived transient lock plus the scheduler's
		 * own pending check so repeated signals cannot enqueue duplicates.
		 * Fail-open: any error reports false and the derived caches simply
		 * stay as they are (full CSS keeps serving).
		 *
		 * @since 2.0.0
		 * @return bool True when an event was enqueued or already pending.
		 */
		protected function schedule_deferred_drift_purge(): bool {
			try {
				if ( function_exists( 'get_transient' ) && get_transient( Util::transient_key( self::DRIFT_PURGE_LOCK ) ) ) {
					return false;
				}

				$scheduled = false;
				if ( function_exists( 'as_enqueue_async_action' ) ) {
					if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( self::DRIFT_PURGE_HOOK, array(), 'performance_optimisation' ) ) {
						as_enqueue_async_action( self::DRIFT_PURGE_HOOK, array(), 'performance_optimisation' );
					}
					$scheduled = true;
				} elseif ( function_exists( 'wp_schedule_single_event' ) ) {
					if ( ! function_exists( 'wp_next_scheduled' ) || ! wp_next_scheduled( self::DRIFT_PURGE_HOOK ) ) {
						wp_schedule_single_event( time() + ( defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60 ), self::DRIFT_PURGE_HOOK );
					}
					$scheduled = true;
				}

				if ( $scheduled && function_exists( 'set_transient' ) && defined( 'MINUTE_IN_SECONDS' ) ) {
					set_transient( Util::transient_key( self::DRIFT_PURGE_LOCK ), 1, 5 * MINUTE_IN_SECONDS );
				}

				return $scheduled;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Background callback: run the heavy derived-cache purge.
		 *
		 * Registered on DRIFT_PURGE_HOOK and executed by Action Scheduler or
		 * WP-Cron, never inline in the originating request. Guarded and
		 * fail-open like the other purge seams.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public function run_deferred_drift_purge(): void {
			try {
				$this->purge_wppo_derived_caches();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Handle Elementor editor saves: requeue the saved post's used CSS.
		 *
		 * An explicit editor save always requeues (no mtime drift check): the
		 * save itself proves the markup changed and the sidecar may not exist
		 * yet for new posts, so a per-post regeneration job is queued directly
		 * via Used_CSS::requeue_for_post(). This is the post-scoped fast path.
		 * The post-less elementor/core/files/clear_cache signal handled by
		 * on_builder_drift() has no post context and schedules a background
		 * full-site purge instead. The wppo_builder_drift_requeue action fires
		 * only when a job was actually queued (or already scheduled).
		 *
		 * @since 2.0.0
		 * @param int   $post_id Post ID saved in the editor.
		 * @param mixed $editor_data Editor data (unused).
		 * @return void
		 */
		public function on_builder_drift_save( $post_id, $editor_data ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature must match the elementor/editor/after_save action.
			unset( $editor_data );
			try {
				$post_id = (int) $post_id;
				if ( $post_id <= 0 ) {
					return;
				}
				$queued = false;
				if ( class_exists( 'PerformanceOptimise\Inc\Used_CSS' ) ) {
					$queued = Used_CSS::requeue_for_post( $post_id );
				}
				if ( ! $queued ) {
					return;
				}
				if ( function_exists( 'do_action' ) ) {
					/**
					 * Fires after builder-drift requeue for a saved post (issue #1023).
					 *
					 * @since 2.0.0
					 *
					 * @param int $post_id Post ID saved in the builder.
					 */
					do_action( 'wppo_builder_drift_requeue', $post_id );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Get the builder map, filterable by hosts and themes.
		 *
		 * @since 2.0.0
		 * @return array<string,array> Builder map keyed by builder slug.
		 */
		public static function get_builder_map(): array {
			$map = self::BUILDER_MAP;
			if ( function_exists( 'apply_filters' ) ) {
				/**
				 * Filter the builder-update purge map.
				 *
				 * Lets hosts and themes add builders or correct slugs and
				 * cache directories without editing the plugin.
				 *
				 * @since 2.0.0
				 *
				 * @param array<string,array> $map Builder map keyed by builder slug.
				 */
				$filtered = apply_filters( 'wppo_builder_purge_map', $map );
				if ( is_array( $filtered ) ) {
					$map = $filtered;
				}
			}
			return $map;
		}

		/**
		 * Handle upgrader_process_complete: purge when a builder was updated.
		 *
		 * Returns early for non-update actions, non-plugin/theme types, and
		 * updates whose slugs intersect nothing in the builder map (this
		 * keeps the blast radius to builder updates only — WPPO's own update
		 * path included, its slug is not in the map).
		 *
		 * @since 2.0.0
		 * @param mixed $upgrader   Upgrader instance (unused).
		 * @param mixed $hook_extra Update context (action/type/plugin/plugins/theme/themes).
		 * @return void
		 */
		public function on_builder_update( $upgrader, $hook_extra ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature must match the upgrader_process_complete action.
			unset( $upgrader );
			if ( ! is_array( $hook_extra ) ) {
				return;
			}
			if ( 'update' !== ( $hook_extra['action'] ?? '' ) ) {
				return;
			}
			if ( ! in_array( ( $hook_extra['type'] ?? '' ), array( 'plugin', 'theme' ), true ) ) {
				return;
			}

			$updated_plugins = array();
			if ( ! empty( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
				$updated_plugins[] = $hook_extra['plugin'];
			}
			if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
				foreach ( $hook_extra['plugins'] as $plugin_file ) {
					if ( is_string( $plugin_file ) && '' !== $plugin_file ) {
						$updated_plugins[] = $plugin_file;
					}
				}
			}

			$updated_themes = array();
			if ( ! empty( $hook_extra['theme'] ) && is_string( $hook_extra['theme'] ) ) {
				$updated_themes[] = $hook_extra['theme'];
			}
			if ( ! empty( $hook_extra['themes'] ) && is_array( $hook_extra['themes'] ) ) {
				foreach ( $hook_extra['themes'] as $theme_slug ) {
					if ( is_string( $theme_slug ) && '' !== $theme_slug ) {
						$updated_themes[] = $theme_slug;
					}
				}
			}

			// Resolve the map once so routing and purging use the same map even
			// if the wppo_builder_purge_map filter is non-deterministic.
			$map     = self::get_builder_map();
			$matched = $this->match_builders( $updated_plugins, $updated_themes, $map );
			if ( empty( $matched ) ) {
				return;
			}

			$this->purge_for_builders( $matched, $map );
		}

		/**
		 * Intersect updated plugin/theme slugs with the builder map.
		 *
		 * Comparisons are case-insensitive (theme directory slugs such as
		 * Divi vary in case across installs).
		 *
		 * @since 2.0.0
		 * @param string[] $plugins Updated plugin files.
		 * @param string[] $themes  Updated theme slugs.
		 * @param array    $map     Builder map (resolved once per update).
		 * @return string[] Matched builder keys.
		 */
		private function match_builders( array $plugins, array $themes, array $map ): array {
			$matched = array();

			$plugins_lc = array_map( 'strtolower', $plugins );
			$themes_lc  = array_map( 'strtolower', $themes );

			foreach ( $map as $key => $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$entry_plugins = array();
				if ( isset( $entry['plugins'] ) && is_array( $entry['plugins'] ) ) {
					$entry_plugins = array_map( 'strtolower', array_filter( $entry['plugins'], 'is_string' ) );
				}
				$entry_themes = array();
				if ( isset( $entry['themes'] ) && is_array( $entry['themes'] ) ) {
					$entry_themes = array_map( 'strtolower', array_filter( $entry['themes'], 'is_string' ) );
				}

				if ( ! empty( array_intersect( $entry_plugins, $plugins_lc ) ) || ! empty( array_intersect( $entry_themes, $themes_lc ) ) ) {
					$matched[] = (string) $key;
				}
			}

			return array_values( array_unique( $matched ) );
		}

		/**
		 * Run the purge chain for the matched builders.
		 *
		 * Protected (not private) so unit tests can subclass and observe the
		 * routing without touching the filesystem.
		 *
		 * @since 2.0.0
		 * @param string[] $matched Matched builder keys.
		 * @param array    $map     Builder map (resolved once per update).
		 * @return void
		 */
		protected function purge_for_builders( array $matched, array $map ): void {
			$labels = array();
			foreach ( $matched as $key ) {
				$labels[] = isset( $map[ $key ]['label'] ) && is_string( $map[ $key ]['label'] ) ? $map[ $key ]['label'] : (string) $key;
			}

			$this->purge_builder_directories( $matched, $map );
			$this->fire_builder_regeneration_hooks( $matched, $map );
			$this->purge_wppo_derived_caches();
			$this->write_purge_log( $labels );
			$this->store_admin_notice( $labels );

			/**
			 * Fires after WPPO purges builder + page caches for a builder update.
			 *
			 * @since 2.0.0
			 *
			 * @param string[] $matched Matched builder keys (e.g. array( 'elementor' )).
			 */
			if ( function_exists( 'do_action' ) ) {
				do_action( 'wppo_after_builder_purge', $matched );
			}
		}

		/**
		 * Delete the matched builders' regenerable cache directories.
		 *
		 * Only the listed subdirectories are removed — never the uploads or
		 * content base directories themselves. Every candidate is normalized
		 * and verified to stay inside its base (containment prefix check plus
		 * dot-segment rejection); missing directories are skipped. Entries
		 * flagged css_only (Bricks/WPBakery, whose directory layout is not
		 * documented as fully regenerable) delete only top-level *.css files
		 * so templates, assets, or custom files in the same root survive.
		 *
		 * @since 2.0.0
		 * @param string[] $matched Matched builder keys.
		 * @param array    $map     Builder map.
		 * @return string[] Deleted paths (for tests and logging).
		 */
		protected function purge_builder_directories( array $matched, array $map ): array {
			$deleted = array();

			$fs = Util::init_filesystem();
			if ( ! $fs ) {
				return $deleted;
			}

			$bases = array();
			if ( function_exists( 'wp_upload_dir' ) ) {
				try {
					$upload = wp_upload_dir();
					if ( is_array( $upload ) && isset( $upload['basedir'] ) && is_string( $upload['basedir'] ) && '' !== $upload['basedir'] ) {
						$bases['uploads'] = wp_normalize_path( $upload['basedir'] );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( defined( 'WP_CONTENT_DIR' ) ) {
				$bases['content'] = wp_normalize_path( WP_CONTENT_DIR );
			}

			foreach ( $matched as $key ) {
				if ( ! isset( $map[ $key ] ) || ! is_array( $map[ $key ] ) ) {
					continue;
				}
				$entry = $map[ $key ];
				$jobs  = array();
				if ( isset( $bases['uploads'], $entry['upload_subdirs'] ) && is_array( $entry['upload_subdirs'] ) ) {
					foreach ( $entry['upload_subdirs'] as $sub ) {
						$jobs[] = array( $bases['uploads'], $sub );
					}
				}
				if ( isset( $bases['content'], $entry['content_subdirs'] ) && is_array( $entry['content_subdirs'] ) ) {
					foreach ( $entry['content_subdirs'] as $sub ) {
						$jobs[] = array( $bases['content'], $sub );
					}
				}

				foreach ( $jobs as $job ) {
					list( $base, $sub ) = $job;
					$dir                = $this->scoped_cache_dir( $base, $sub );
					if ( '' === $dir ) {
						continue;
					}
					try {
						if ( method_exists( $fs, 'is_dir' ) && ! $fs->is_dir( $dir ) ) {
							continue;
						}
						if ( ! empty( $entry['css_only'] ) ) {
							$this->delete_css_files_only( $fs, $dir, $deleted );
							continue;
						}
						if ( $fs->delete( $dir, true ) ) {
							$deleted[] = $dir;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
			}

			return $deleted;
		}

		/**
		 * Resolve a builder cache subdirectory strictly inside its base dir.
		 *
		 * @since 2.0.0
		 * @param string $base Base directory (normalized).
		 * @param mixed  $sub  Relative subdirectory from the map.
		 * @return string Normalized absolute path, or '' when unsafe.
		 */
		private function scoped_cache_dir( string $base, $sub ): string {
			if ( ! is_string( $sub ) ) {
				return '';
			}
			$sub = trim( str_replace( '\\', '/', $sub ), '/' );
			if ( '' === $sub || false !== strpos( $sub, '..' ) ) {
				return '';
			}
			// Reject dot-only segments ('.', '...') so a crafted map entry
			// can never resolve delete() against the base directory itself.
			foreach ( explode( '/', $sub ) as $segment ) {
				if ( '' === trim( (string) $segment, '.' ) ) {
					return '';
				}
			}
			$base = rtrim( wp_normalize_path( $base ), '/' );
			if ( '' === $base ) {
				return '';
			}
			$dir = wp_normalize_path( $base . '/' . $sub );
			if ( $dir === $base || 0 !== strpos( $dir, $base . '/' ) ) {
				return '';
			}
			return $dir;
		}

		/**
		 * Delete only top-level *.css files inside a builder directory.
		 *
		 * Used for css_only map entries (Bricks/WPBakery): non-CSS files and
		 * subdirectories are left untouched so anything non-regenerable in
		 * the same root survives the purge. Top-level-only is sufficient
		 * because the same purge chain also clears the full page cache plus
		 * used-CSS and critical-CSS, so any stale nested reference is
		 * dropped with the HTML that pointed at it and rebuilt on next visit.
		 *
		 * @since 2.0.0
		 * @param object   $fs      WP_Filesystem instance.
		 * @param string   $dir     Scoped directory (already containment-checked).
		 * @param string[] $deleted Deleted paths accumulator.
		 * @return void
		 */
		private function delete_css_files_only( $fs, string $dir, array &$deleted ): void {
			try {
				if ( ! method_exists( $fs, 'dirlist' ) || ! method_exists( $fs, 'delete' ) ) {
					return;
				}
				$entries = $fs->dirlist( $dir );
				if ( ! is_array( $entries ) ) {
					return;
				}
				foreach ( $entries as $name => $entry ) {
					if ( ! is_string( $name ) || '.css' !== strtolower( substr( $name, -4 ) ) ) {
						continue;
					}
					if ( isset( $entry['type'] ) && 'f' !== $entry['type'] ) {
						continue;
					}
					$file = wp_normalize_path( rtrim( $dir, '/' ) . '/' . $name );
					if ( 0 !== strpos( $file, rtrim( $dir, '/' ) . '/' ) ) {
						continue;
					}
					try {
						if ( $fs->delete( $file ) ) {
							$deleted[] = $file;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Fire builder-native cache-regeneration hooks (best-effort).
		 *
		 * Each hook fires only when a listener is registered (has_action
		 * guard) so unknown or removed hooks are strict no-ops; everything
		 * runs guarded so a builder failure can never break the upgrader.
		 * The drift fan-out is suspended around do_action() (see
		 * self::$drift_suspended) so firing elementor/core/files/clear_cache
		 * here cannot re-enter on_builder_drift() and double-purge.
		 *
		 * @since 2.0.0
		 * @param string[] $matched Matched builder keys.
		 * @param array    $map     Builder map.
		 * @return void
		 */
		protected function fire_builder_regeneration_hooks( array $matched, array $map ): void {
			if ( ! function_exists( 'has_action' ) || ! function_exists( 'do_action' ) ) {
				return;
			}
			foreach ( $matched as $key ) {
				if ( ! isset( $map[ $key ]['clear_hooks'] ) || ! is_array( $map[ $key ]['clear_hooks'] ) ) {
					continue;
				}
				foreach ( $map[ $key ]['clear_hooks'] as $hook ) {
					if ( ! is_string( $hook ) || '' === $hook ) {
						continue;
					}
					try {
						if ( has_action( $hook ) ) {
							self::$drift_suspended = true;
							try {
								do_action( $hook );
							} finally {
								self::$drift_suspended = false;
							}
						}
					} catch ( \Throwable $e ) {
						self::$drift_suspended = false;
						unset( $e );
					}
				}
			}
		}

		/**
		 * Purge WPPO's derived caches (page cache, used-CSS, critical-CSS).
		 *
		 * Critical-CSS and used-CSS regeneration is queued via Action
		 * Scheduler (async) instead of running synchronously so the upgrader
		 * request is never blocked; without Action Scheduler the caches are
		 * simply cleared and rebuilt lazily on the next visits.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		protected function purge_wppo_derived_caches(): void {
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Cache' ) ) {
					Cache::clear_cache();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Used_CSS' ) ) {
					Used_CSS::delete_all_used_css();
					if ( function_exists( 'as_enqueue_async_action' ) ) {
						// Note: regenerate_all() writes its own 'N jobs queued'
						// audit entry; write_purge_log() below adds the
						// builder-purge summary. Two entries per update is
						// intentional — distinct facts on a rare event.
						( new Used_CSS( Util::get_settings() ) )->regenerate_all();
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Critical_CSS' ) ) {
					if ( function_exists( 'as_enqueue_async_action' ) ) {
						// Clears now and queues per-template regeneration (see
						// the audit-entry note above).
						Critical_CSS::regenerate_all();
					} else {
						Critical_CSS::clear_all();
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Write the audit-trail entry for a builder purge.
		 *
		 * @since 2.0.0
		 * @param string[] $labels Human-readable builder names.
		 * @return void
		 */
		protected function write_purge_log( array $labels ): void {
			if ( ! class_exists( 'PerformanceOptimise\Inc\Log' ) ) {
				return;
			}
			try {
				Log::add(
					sprintf(
						/* translators: %s: comma-separated builder names */
						__( 'Builder update detected (%s): builder CSS, page cache, used-CSS and critical-CSS purged.', 'performance-optimisation' ),
						implode( ', ', $labels )
					)
				);
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Stage the one-time admin success notice for the purge.
		 *
		 * Multisite-safe via Util::transient_key(). The notice renderer reads
		 * and deletes the transient, so it shows once; the TTL is only a
		 * backstop for auto-expiry when no admin page loads.
		 *
		 * @since 2.0.0
		 * @param string[] $labels Human-readable builder names.
		 * @return void
		 */
		protected function store_admin_notice( array $labels ): void {
			if ( ! function_exists( 'set_transient' ) ) {
				return;
			}
			try {
				set_transient(
					Util::transient_key( self::NOTICE_TRANSIENT ),
					array(
						'builders' => array_values( $labels ),
						'time'     => time(),
					),
					DAY_IN_SECONDS
				);
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}
}
