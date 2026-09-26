<?php
/**
 * Central loader map — single owner for "which file provides which class".
 *
 * ARCH-003: all load paths previously hardcoded inline in `Main::includes()`
 * (eager `require_once` list, `spl_autoload_register` fallback map, WP-CLI
 * file path) live here as data + path building on
 * `WPPO_PLUGIN_PATH . 'includes/'`. `Main` keeps orchestration decisions
 * (the LiteSpeed-conditional gate, hook registration); this class owns path
 * data only. ARCH-013: paths are canonical-subdirectory-relative
 * (`includes/<Domain>/<same-file>`, `Util` stays at root); class names,
 * load order, and behavior are unchanged.
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
	 * are built on `WPPO_PLUGIN_PATH . 'includes/'` (ARCH-013 canonical
	 * subdirectories; `Util` stays at root).
	 *
	 * Multisite-agnostic: pure path data, no options or transients touched.
	 *
	 * @since 2.4.0
	 */
	final class Loader_Map {

		/**
		 * Base directory for plugin class files (with trailing slash).
		 *
		 * @since 2.4.0
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
		 * @since 2.4.0
		 * @return string[] Relative paths under `includes/`, in load order.
		 */
		public static function eager_files(): array {
			return array(
				'Core/class-wp-version.php',
				'Edge/class-server-rules.php',
				'Edge/class-header-emitter.php',
				'Integrations/class-litespeed-integration.php',
				'Compatibility/class-llms.php',
				'Insight/class-od-bridge.php',
				'Cache/class-bfcache.php',
				'Admin/class-perf-translations.php',
				'Insight/class-ai-adaptive.php',
				'Insight/class-ai-anomaly.php',
				'Edge/class-edge-cache.php',
				'Support/trait-purge-logger.php',
				'Edge/class-edge-purger.php',
				'Edge/class-cdn.php',
				'Integrations/class-builder-purge-watcher.php',
				'Core/class-hook-registry.php',
			);
		}

		/**
		 * LiteSpeed-only crawler + ESI stack files (conditionally eager-loaded).
		 *
		 * The load/no-load *decision* stays in
		 * `Main::should_load_litespeed_stack()`; this method owns only the
		 * file list for the true branch.
		 *
		 * @since 2.4.0
		 * @return string[] Relative paths under `includes/`, in load order.
		 */
		public static function litespeed_stack_files(): array {
			return array(
				'Integrations/class-litespeed-crawler.php',
				'Integrations/class-litespeed-esi.php',
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
		 * @since 2.4.0
		 * @return array<string,string> Short class name => relative path under `includes/`.
		 */
		public static function fallback_map(): array {
			return array(
				'Abilities'                  => 'Admin/class-abilities.php',
				'Admin_Auth'                 => 'Admin/class-admin-auth.php',
				'Activate'                   => 'Core/class-activate.php',
				'Admin_Notices'              => 'Admin/class-admin-notices.php',
				'Advanced_Cache_Handler'     => 'Cache/class-advanced-cache-handler.php',
				'Asset_Manager'              => 'Assets/class-asset-manager.php',
				'Cache'                      => 'Cache/class-cache.php',
				'Cache_Capacity'             => 'Cache/class-cache-capacity.php',
				'Cache_Coordinator'          => 'Cache/class-cache-coordinator.php',
				'Cache_Invalidator'          => 'Cache/class-cache-invalidator.php',
				'Cache_Key'                  => 'Cache/class-cache-key.php',
				'CDN'                        => 'Edge/class-cdn.php',
				'CDN_Purger'                 => 'Edge/class-cdn-purger.php',
				'Cloudflare_Purger'          => 'Edge/class-cloudflare-purger.php',
				'Core_Tweaks'                => 'Compatibility/class-core-tweaks.php',
				'Critical_CSS'               => 'CSS/class-critical-css.php',
				'Ccss_Generator'             => 'CSS/class-ccss-generator.php',
				'Ccss_Store'                 => 'CSS/class-ccss-store.php',
				'Css_Combine'                => 'Assets/class-css-combine.php',
				'Css_Safelist'               => 'Assets/class-css-safelist.php',
				'Cron'                       => 'Scheduler/class-cron.php',
				'Job_Registry'               => 'Scheduler/class-job-registry.php',
				'Preload_Buffer_Coordinator' => 'Core/class-preload-buffer-coordinator.php',
				'Preload_Transport'          => 'Scheduler/class-preload-transport.php',
				'Database_Cleanup'           => 'Database/class-database-cleanup.php',
				'Database_Cleanup_Runner'    => 'Database/class-database-cleanup-runner.php',
				'Deactivate'                 => 'Core/class-deactivate.php',
				'Dropin_Registry'            => 'Cache/class-dropin-registry.php',
				'Edge_Cache'                 => 'Edge/class-edge-cache.php',
				'Edge_Purge_Coordinator'     => 'Edge/class-edge-purge-coordinator.php',
				'Edge_Purger'                => 'Edge/class-edge-purger.php',
				'Filesystem'                 => 'Support/class-filesystem.php',
				'Google_Fonts'               => 'Assets/class-google-fonts.php',
				'Header_Emitter'             => 'Edge/class-header-emitter.php',
				'Hook_Registry'              => 'Core/class-hook-registry.php',
				'Htaccess_Handler'           => 'Edge/class-htaccess-handler.php',
				'Http'                       => 'Support/class-http.php',
				'Image_Optimisation'         => 'Images/class-image-optimisation.php',
				'Insight_Query'              => 'Insight/class-insight-query.php',
				'Img_Converter'              => 'Images/class-img-converter.php',
				'Lcp_Preload'                => 'Images/class-lcp-preload.php',
				'LiteSpeed_Crawler'          => 'Integrations/class-litespeed-crawler.php',
				'LiteSpeed_ESI'              => 'Integrations/class-litespeed-esi.php',
				'LiteSpeed_Integration'      => 'Integrations/class-litespeed-integration.php',
				'Minify_Policy'              => 'minify/class-minify-policy.php',
				'Llms'                       => 'Compatibility/class-llms.php',
				'Loader_Map'                 => 'Core/class-loader-map.php',
				'Log'                        => 'Support/class-log.php',
				'Main'                       => 'Core/class-main.php',
				'Metabox'                    => 'Admin/class-metabox.php',
				'Object_Cache'               => 'Cache/class-object-cache.php',
				'Redis_Config_Policy'        => 'Cache/class-redis-config-policy.php',
				'OD_Bridge'                  => 'Insight/class-od-bridge.php',
				'Pagespeed'                  => 'Insight/class-pagespeed.php',
				'Perf_Translations'          => 'Admin/class-perf-translations.php',
				'Purge_Logger'               => 'Support/trait-purge-logger.php',
				'Rest'                       => 'Admin/class-rest.php',
				'Runtime_State'              => 'Core/class-runtime-state.php',
				'Rest_Cache'                 => 'Admin/class-rest-cache.php',
				'Rest_Settings'              => 'Admin/class-rest-settings.php',
				'RUM'                        => 'Insight/class-rum.php',
				'Sandbox_Preview'            => 'Settings/class-sandbox-preview.php',
				'Scheduler'                  => 'Scheduler/class-scheduler.php',
				'Script_Strategy'            => 'Assets/class-script-strategy.php',
				'Server_Rules'               => 'Edge/class-server-rules.php',
				'Settings_Migrations'        => 'Settings/class-settings-migrations.php',
				'Settings_Command'           => 'Settings/class-settings-command.php',
				'Settings_Store'             => 'Settings/class-settings-store.php',
				'Suggestion_Engine'          => 'Insight/class-suggestion-engine.php',
				'System_Info'                => 'Insight/class-system-info.php',
				'Telemetry'                  => 'Insight/class-telemetry.php',
				'Used_CSS'                   => 'CSS/class-used-css.php',
				'Url'                        => 'Support/class-url.php',
				'Util'                       => 'class-util.php',
				'Woo_Detect'                 => 'Integrations/class-woo-detect.php',
				'Wp_Version'                 => 'Core/class-wp-version.php',
				'WPPO_CLI_Command'           => 'Admin/class-wppo-cli-command.php',
				'Ai_Anomaly'                 => 'Insight/class-ai-anomaly.php',
				'AI_Adaptive'                => 'Insight/class-ai-adaptive.php',
				'Bfcache'                    => 'Cache/class-bfcache.php',
				'Builder_Purge_Watcher'      => 'Integrations/class-builder-purge-watcher.php',
			);
		}

		/**
		 * Canonical single-level subdirectories under `includes/`.
		 *
		 * ARCH-013: `file_path()` allowlists exactly these directories (plus
		 * the `includes/` root for `Util`, which stays until ARCH-014).
		 *
		 * @since 2.4.0
		 * @return string[]
		 */
		public static function allowed_dirs(): array {
			return array(
				'Admin',
				'Assets',
				'Cache',
				'Compatibility',
				'Core',
				'CSS',
				'Database',
				'Edge',
				'Images',
				'Insight',
				'Integrations',
				'minify',
				'Scheduler',
				'Settings',
				'Support',
			);
		}

		/**
		 * Absolute path for a relative file under `includes/`.
		 *
		 * Accepts either a root-level filename (`class-util.php`, kept at
		 * root until ARCH-014) or a single-level canonical path
		 * (`Cache/class-cache.php`) whose directory is in `allowed_dirs()`.
		 * Anything else (absolute paths, `..`, deeper nesting) returns ''
		 * so callers cannot traverse outside `includes/` via untrusted input.
		 *
		 * @since 2.4.0
		 * @param string $relative_file Relative path (e.g. `Cache/class-cache.php`).
		 * @return string Absolute path, or '' when `WPPO_PLUGIN_PATH` is undefined or input is invalid.
		 */
		public static function file_path( string $relative_file ): string {
			if ( '' === $relative_file || '..' === $relative_file ) {
				return '';
			}
			if ( false !== strpos( $relative_file, '\\' ) ) {
				return '';
			}
			if ( 0 === strpos( $relative_file, '/' ) ) {
				return '';
			}
			if ( false !== strpos( $relative_file, '..' ) ) {
				return '';
			}
			$parts = explode( '/', $relative_file );
			if ( count( $parts ) > 2 ) {
				return '';
			}
			if ( 2 === count( $parts ) ) {
				if ( '' === $parts[0] || '' === $parts[1] ) {
					return '';
				}
				if ( ! in_array( $parts[0], self::allowed_dirs(), true ) ) {
					return '';
				}
				$filename = $parts[1];
			} else {
				$filename = $parts[0];
			}
			if ( basename( $filename ) !== $filename || '' === $filename ) {
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
		 * @since 2.4.0
		 * @param string $class_name Short, single-namespace, or fully-qualified class name.
		 * @return string|null Absolute file path, or null when unmapped/undefined base.
		 */
		public static function path_for( string $class_name ): ?string {
			$class_name = ltrim( $class_name, '\\' );
			$prefix     = 'PerformanceOptimise\\Inc\\';
			$short      = $class_name;
			if ( 0 === strpos( $class_name, $prefix ) ) {
				$short = substr( $class_name, strlen( $prefix ) );
			} elseif ( false !== strpos( $class_name, '\\' ) ) {
				// A single nested namespace (for example Minify\\Minify_Policy)
				// is a valid map key; deeper namespaces remain unmapped below.
				$short = $class_name;
			}
			static $map = null;
			if ( null === $map ) {
				$map = self::fallback_map();
			}
			$map_key = $short;
			if ( ! isset( $map[ $map_key ] ) && 0 === strpos( $map_key, 'Minify\\' ) ) {
				$parts   = explode( '\\', $map_key );
				$map_key = (string) end( $parts );
			}
			if ( ! isset( $map[ $map_key ] ) ) {
				return null;
			}
			$path = self::file_path( $map[ $map_key ] );
			if ( '' === $path ) {
				return null;
			}
			return $path;
		}

		/**
		 * Absolute path of the WP-CLI command file.
		 *
		 * @since 2.4.0
		 * @return string Absolute path, or '' when `WPPO_PLUGIN_PATH` is undefined.
		 */
		public static function cli_file(): string {
			return self::file_path( 'Admin/class-wppo-cli-command.php' );
		}
	}
}
