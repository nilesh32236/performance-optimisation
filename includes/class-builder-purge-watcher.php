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
		 * listener is registered.
		 *
		 * Slugs verified against wordpress.org / vendor distributions at
		 * implementation time (issue #907); hosts and themes can extend or
		 * correct the map via the wppo_builder_purge_map filter.
		 *
		 * @since NEXT
		 * @var array<string,array{label:string,plugins:string[],themes:string[],upload_subdirs:string[],content_subdirs:string[],clear_hooks:string[]}>
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
				'themes'          => array( 'Divi', 'divi' ),
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
			),
			'wpbakery'  => array(
				'label'           => 'WPBakery',
				'plugins'         => array( 'js_composer/js_composer.php' ),
				'themes'          => array(),
				'upload_subdirs'  => array( 'js_composer' ),
				'content_subdirs' => array(),
				'clear_hooks'     => array(),
			),
		);

		/**
		 * Register the upgrader hook.
		 *
		 * @since NEXT
		 * @return void
		 */
		public function register(): void {
			add_action( 'upgrader_process_complete', array( $this, 'on_builder_update' ), 10, 2 );
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

			$matched = $this->match_builders( $updated_plugins, $updated_themes );
			if ( empty( $matched ) ) {
				return;
			}

			$this->purge_for_builders( $matched );
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
		 * @return string[] Matched builder keys.
		 */
		private function match_builders( array $plugins, array $themes ): array {
			$map     = self::get_builder_map();
			$matched = array();

			$plugins_lc = array_map( 'strtolower', $plugins );
			$themes_lc  = array_map( 'strtolower', $themes );

			foreach ( $map as $key => $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$entry_plugins = isset( $entry['plugins'] ) && is_array( $entry['plugins'] ) ? array_map( 'strtolower', $entry['plugins'] ) : array();
				$entry_themes  = isset( $entry['themes'] ) && is_array( $entry['themes'] ) ? array_map( 'strtolower', $entry['themes'] ) : array();

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
		 * @return void
		 */
		protected function purge_for_builders( array $matched ): void {
			$map    = self::get_builder_map();
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
		 * `..` rejection); missing directories are skipped.
		 *
		 * @since NEXT
		 * @param string[] $matched Matched builder keys.
		 * @param array    $map     Builder map.
		 * @return string[] Deleted directory paths (for tests and logging).
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
						( new Used_CSS( Util::get_settings() ) )->regenerate_all();
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Critical_CSS' ) ) {
					if ( function_exists( 'as_enqueue_async_action' ) ) {
						// Clears now and queues per-template regeneration.
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
