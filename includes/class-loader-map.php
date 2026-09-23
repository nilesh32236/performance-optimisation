<?php
/**
 * Central loader map — single owner for "which file provides which class".
 *
 * ARCH-003: all load paths previously hardcoded inline in `Main::includes()`
 * (eager `require_once` list, `spl_autoload_register` fallback map, WP-CLI
 * file path) live here as data + path building on
 * `WPPO_PLUGIN_PATH . 'includes/'`. `Main` keeps orchestration decisions
 * (the LiteSpeed-conditional gate, hook registration); this class owns path
 * data only. No directory changes — every path stays `includes/<same-file>`
 * so the extraction is behavior-neutral by construction.
 *
 * No PSR-4 migration (watchdog-forbidden); manual loading only.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Loader_Map' ) ) {
	/**
	 * Class Loader_Map
	 *
	 * Static data-only map: eager-load file list, lazy fallback
	 * short-name-to-file map, and the WP-CLI command file path. All paths
	 * are built on `WPPO_PLUGIN_PATH . 'includes/'` so a future directory
	 * move (ARCH-013) touches exactly this file.
	 *
	 * Multisite-agnostic: pure path data, no options or transients touched.
	 *
	 * @since NEXT
	 */
	final class Loader_Map {

		/**
		 * Base directory for plugin class files (with trailing slash).
		 *
		 * @since NEXT
		 * @return string `WPPO_PLUGIN_PATH . 'includes/'`, or '' when the constant is undefined.
		 */
		public static function base_dir(): string {
			if ( ! defined( 'WPPO_PLUGIN_PATH' ) ) {
				return '';
			}
			return WPPO_PLUGIN_PATH . 'includes/';
		}

		/**
		 * Files always eager-loaded by `Main::includes()`, in load order.
		 *
		 * Mirrors the pre-ARCH-003 inline list exactly, minus the Composer
		 * vendor Action Scheduler lib (not under `includes/`, still wired
		 * directly in `Main`) and minus the LiteSpeed-conditional crawler +
		 * ESI stack (see `litespeed_stack_files()`).
		 *
		 * @since NEXT
		 * @return string[] Relative filenames under `includes/`, in load order.
		 */
		public static function eager_files(): array {
			return array(
				'class-wp-version.php',
				'class-server-rules.php',
				'class-header-emitter.php',
				'class-litespeed-integration.php',
				'class-llms.php',
				'class-od-bridge.php',
				'class-bfcache.php',
				'class-perf-translations.php',
				'class-ai-adaptive.php',
				'class-ai-anomaly.php',
				'class-edge-cache.php',
				'trait-purge-logger.php',
				'class-edge-purger.php',
				'class-cdn.php',
				'class-builder-purge-watcher.php',
				'class-hook-registry.php',
			);
		}

		/**
		 * LiteSpeed-only crawler + ESI stack files (conditionally eager-loaded).
		 *
		 * The load/no-load *decision* stays in
		 * `Main::should_load_litespeed_stack()`; this method owns only the
		 * file list for the true branch.
		 *
		 * @since NEXT
		 * @return string[] Relative filenames under `includes/`, in load order.
		 */
		public static function litespeed_stack_files(): array {
			return array(
				'class-litespeed-crawler.php',
				'class-litespeed-esi.php',
			);
		}

		/**
		 * Lazy fallback short-name-to-file map for `spl_autoload_register`.
		 *
		 * Same entries as the pre-ARCH-003 inline `$fallback_map`, plus the
		 * loader itself (`Loader_Map`), the orchestrator (`Main`), and the
		 * WP-CLI command (`WPPO_CLI_Command`) so every
		 * `PerformanceOptimise\Inc\*` class in the inventory resolves through
		 * one map. Classes already eager-loaded above are intentionally
		 * omitted here (except where the pre-ARCH-003 map already listed
		 * them: `Hook_Registry`, `Purge_Logger`, `Wp_Version`,
		 * `LiteSpeed_Crawler`, `LiteSpeed_ESI` — kept so late callers still
		 * resolve them via autoload when the eager load was skipped).
		 *
		 * @since NEXT
		 * @return array<string,string> Short class name => relative filename under `includes/`.
		 */
		public static function fallback_map(): array {
			return array(
				'Abilities'              => 'class-abilities.php',
				'Activate'               => 'class-activate.php',
				'Admin_Notices'          => 'class-admin-notices.php',
				'Advanced_Cache_Handler' => 'class-advanced-cache-handler.php',
				'Asset_Manager'          => 'class-asset-manager.php',
				'Cache'                  => 'class-cache.php',
				'Cache_Invalidator'      => 'class-cache-invalidator.php',
				'Cache_Key'              => 'class-cache-key.php',
				'CDN_Purger'             => 'class-cdn-purger.php',
				'Cloudflare_Purger'      => 'class-cloudflare-purger.php',
				'Core_Tweaks'            => 'class-core-tweaks.php',
				'Critical_CSS'           => 'class-critical-css.php',
				'Css_Combine'            => 'class-css-combine.php',
				'Css_Safelist'           => 'class-css-safelist.php',
				'Cron'                   => 'class-cron.php',
				'Database_Cleanup'       => 'class-database-cleanup.php',
				'Deactivate'             => 'class-deactivate.php',
				'Filesystem'             => 'class-filesystem.php',
				'Google_Fonts'           => 'class-google-fonts.php',
				'Hook_Registry'          => 'class-hook-registry.php',
				'Htaccess_Handler'       => 'class-htaccess-handler.php',
				'Http'                   => 'class-http.php',
				'Image_Optimisation'     => 'class-image-optimisation.php',
				'Img_Converter'          => 'class-img-converter.php',
				'Lcp_Preload'            => 'class-lcp-preload.php',
				'LiteSpeed_Crawler'      => 'class-litespeed-crawler.php',
				'LiteSpeed_ESI'          => 'class-litespeed-esi.php',
				'Loader_Map'             => 'class-loader-map.php',
				'Log'                    => 'class-log.php',
				'Main'                   => 'class-main.php',
				'Metabox'                => 'class-metabox.php',
				'Object_Cache'           => 'class-object-cache.php',
				'Pagespeed'              => 'class-pagespeed.php',
				'Purge_Logger'           => 'trait-purge-logger.php',
				'Rest'                   => 'class-rest.php',
				'RUM'                    => 'class-rum.php',
				'Sandbox_Preview'        => 'class-sandbox-preview.php',
				'Scheduler'              => 'class-scheduler.php',
				'Script_Strategy'        => 'class-script-strategy.php',
				'Settings_Migrations'    => 'class-settings-migrations.php',
				'Settings_Store'         => 'class-settings-store.php',
				'Suggestion_Engine'      => 'class-suggestion-engine.php',
				'System_Info'            => 'class-system-info.php',
				'Telemetry'              => 'class-telemetry.php',
				'Used_CSS'               => 'class-used-css.php',
				'Url'                    => 'class-url.php',
				'Util'                   => 'class-util.php',
				'Woo_Detect'             => 'class-woo-detect.php',
				'Wp_Version'             => 'class-wp-version.php',
				'WPPO_CLI_Command'       => 'class-wppo-cli-command.php',
			);
		}

		/**
		 * Absolute path for a relative file under `includes/`.
		 *
		 * Only allowlisted bare filenames are accepted (no directory
		 * separators); anything else returns '' so future callers cannot
		 * traverse outside `includes/` via untrusted input.
		 *
		 * @since NEXT
		 * @param string $relative_file Relative filename (e.g. `class-cache.php`).
		 * @return string Absolute path, or '' when `WPPO_PLUGIN_PATH` is undefined or input is invalid.
		 */
		public static function file_path( string $relative_file ): string {
			if ( '' === $relative_file || basename( $relative_file ) !== $relative_file ) {
				return '';
			}
			$base = self::base_dir();
			if ( '' === $base ) {
				return '';
			}
			return $base . $relative_file;
		}

		/**
		 * Absolute path for a plugin short class name, or null when unknown.
		 *
		 * Accepts either the bare short name (`Cache`) or the fully-qualified
		 * name (`PerformanceOptimise\Inc\Cache`); anything outside the plugin
		 * namespace returns null.
		 *
		 * @since NEXT
		 * @param string $class_name Short or fully-qualified class name.
		 * @return string|null Absolute file path, or null when unmapped/undefined base.
		 */
		public static function path_for( string $class_name ): ?string {
			$class_name = ltrim( $class_name, '\\' );
			$prefix     = 'PerformanceOptimise\\Inc\\';
			$short      = $class_name;
			if ( 0 === strpos( $class_name, $prefix ) ) {
				$short = substr( $class_name, strlen( $prefix ) );
			} elseif ( false !== strpos( $class_name, '\\' ) ) {
				return null;
			}
			static $map = null;
			if ( null === $map ) {
				$map = self::fallback_map();
			}
			if ( ! isset( $map[ $short ] ) ) {
				return null;
			}
			$path = self::file_path( $map[ $short ] );
			if ( '' === $path ) {
				return null;
			}
			return $path;
		}

		/**
		 * Absolute path of the WP-CLI command file.
		 *
		 * @since NEXT
		 * @return string Absolute path, or '' when `WPPO_PLUGIN_PATH` is undefined.
		 */
		public static function cli_file(): string {
			return self::file_path( 'class-wppo-cli-command.php' );
		}
	}
}
