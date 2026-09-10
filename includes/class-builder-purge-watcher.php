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
	 * @since NEXT
	 */
	class Builder_Purge_Watcher {

		/**
		 * Transient key for the one-time admin notice after a purge.
		 *
		 * @since NEXT
		 * @var string
		 */
		public const NOTICE_TRANSIENT = 'wppo_builder_purge_notice';

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
		 * @since NEXT
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
		 * Register the upgrader hook plus builder-drift hooks.
		 *
		 * Drift hooks (issue #1023) listen to Elementor asset-regen signals
		 * so editing in a builder requeues used-CSS regeneration instead of
		 * leaving stale used CSS until manual regen/cron.
		 *
		 * @since NEXT
		 * @return void
		 */
		public function register(): void {
			add_action( 'upgrader_process_complete', array( $this, 'on_builder_update' ), 10, 2 );
			add_action( 'elementor/core/files/clear_cache', array( $this, 'on_builder_drift' ), 10, 0 );
			add_action( 'elementor/editor/after_save', array( $this, 'on_builder_drift_save' ), 10, 2 );
		}

		/**
		 * Handle Elementor asset-regen signals: purge derived caches + requeue used CSS.
		 *
		 * Fires on elementor/core/files/clear_cache (CSS regen). Guarded so a
		 * builder failure can never break the request; fail-open keeps full CSS.
		 *
		 * @since NEXT
		 * @return void
		 */
		public function on_builder_drift(): void {
			try {
				$this->purge_wppo_derived_caches();
				if ( function_exists( 'do_action' ) ) {
					/**
					 * Fires after builder-drift requeue (issue #1023).
					 *
					 * @since NEXT
					 */
					do_action( 'wppo_builder_drift_requeue' );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Handle Elementor editor saves: drift-check the saved post's used CSS.
		 *
		 * @since NEXT
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
				if ( class_exists( 'PerformanceOptimise\Inc\Used_CSS' ) ) {
					$drifted = Used_CSS::maybe_requeue_on_builder_drift( $post_id );
					if ( ! $drifted ) {
						Used_CSS::requeue_for_post( $post_id );
					}
				}
				if ( function_exists( 'do_action' ) ) {
					/**
					 * Fires after builder-drift requeue for a saved post (issue #1023).
					 *
					 * @since NEXT
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
		 * @since NEXT
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
				 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
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
			 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
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
		 *
		 * @since NEXT
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
							do_action( $hook );
						}
					} catch ( \Throwable $e ) {
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
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
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
