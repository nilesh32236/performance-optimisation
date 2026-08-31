<?php
/**
 * PerformanceOptimise Utility Class
 *
 * This file contains the `Util` class, which provides various utility methods
 * for file system and resource management tasks, including cache directory creation,
 * filesystem initialization, URL processing, generating preload links, and handling image MIME types.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) ) {
	/**
	 * Utility class for performing various file system and resource management tasks.
	 *
	 * This class provides helper methods for managing cache directories, interacting
	 * with the WordPress filesystem API, processing URLs, generating preload links,
	 * and handling image MIME types.
	 *
	 * @since 1.0.0
	 */
	class Util {

		/**
		 * Top-level settings keys allowlisted for import and update operations.
		 *
		 * Single source of truth for REST (`update_settings`, `import_settings`),
		 * WP-CLI (`settings` subcommand) and the JS `ALLOWED_IMPORT_KEYS` guard.
		 * Changing this list requires a single edit; the JS copy in
		 * `src/components/PluginSetting.js` is kept in sync via `wppoSettings.allowedSettingsKeys`
		 * (see Main::enqueue_admin_scripts()) and a build-time comment.
		 *
		 * @since NEXT
		 * @var string[]
		 */
		public const ALLOWED_SETTINGS_KEYS = array(
			'file_optimisation',
			'preload_settings',
			'image_optimisation',
			'database_cleanup',
			'object_cache',
			'performance_audit',
			'cache_settings',
			'litespeed_integration',
			'llms_txt',
			'od_integration',
			'bfcache',
			'perf_translations',
			'ai_adaptive',
			'edge_cache',
		);

		/**
		 * Allowed tab slugs for `update_settings`.
		 *
		 * Identical to ALLOWED_SETTINGS_KEYS — kept as an alias for semantic
		 * clarity at call-sites that validate a single tab.
		 *
		 * @since NEXT
		 * @var string[]
		 */
		public const ALLOWED_SETTINGS_TABS = self::ALLOWED_SETTINGS_KEYS;

		/**
		 * Get the allowlisted top-level settings keys.
		 *
		 * @since NEXT
		 * @return string[]
		 */
		public static function get_allowed_settings_keys(): array {
			return self::ALLOWED_SETTINGS_KEYS;
		}

		/**
		 * Get default settings structure for fresh installs.
		 *
		 * Single source of truth for Main::__construct() defaults and
		 * WPPO_CLI_Command::get_default_settings() to fix 7-tab drift
		 * (CLI:451 vs Main:240). Covers all allowed tabs; database_cleanup
		 * and object_cache are empty (no defaults) for BC.
		 *
		 * @since NEXT
		 * @return array<string, array<string, mixed>> Default settings keyed by tab.
		 */
		public static function get_default_settings(): array {
			return array(
				'cache_settings'        => array(
					'enableLoggedInCache' => false,
					'loggedInCacheRoles'  => array(),
				),
				'file_optimisation'     => array(
					'enableServerRules'       => false,
					'cdnURL'                  => '',
					'removeUnusedCSS'         => false,
					'excludeUnusedCSS'        => '',
					'criticalCSS'             => false,
					'hostGoogleFontsLocally'  => false,
					'blockAssetsOnDemand'     => function_exists( 'wp_load_classic_theme_block_styles_on_demand' ),
					'loadAllCoreBlockAssets'  => false,
					'delayJSDefaultStrategy'  => 'interaction',
					'delayJSIdleList'         => '',
					'delayJSViewportList'     => '',
					'delayJSPriority'         => '',
					'delayJSIdleTimeout'      => 3000,
					'minifyHTML'              => false,
					'minifyJS'                => false,
					'minifyCSS'               => false,
					'deferJS'                 => false,
					'delayJS'                 => false,
					'combineCSS'              => false,
					'excludeJS'               => '',
					'excludeCSS'              => '',
					'excludeDeferJS'          => '',
					'excludeDelayJS'          => '',
					'excludeCombineCSS'       => '',
					'minifyInlineCSS'         => false,
					'minifyInlineJS'          => false,
					'removeHTMLComments'      => true,
					'removeQueryStrings'      => false,
					'disableRestApiLinks'     => false,
					'disableRssFeeds'         => false,
					'disableShortlinks'       => false,
					'disableGeneratorTag'     => false,
					'disableJQueryMigrate'    => false,
					'disablePasswordStrength' => false,
					'disableSelfPingbacks'    => false,
				),
				'preload_settings'      => array(
					'enableSpeculationRules' => false,
					'speculationMode'        => 'prefetch',
					'speculationEagerness'   => 'conservative',
					'speculationExcludeUrls' => '',
					'preloadSitemap'         => false,
				),
				'image_optimisation'    => array(
					'lazyLoadImages'             => false,
					'lazyLoadNative'             => true,
					'placeholderType'            => 'svg',
					'autoPreloadLCP'             => false,
					'prioritizeLCPImages'        => false,
					'clientSideMimeTypeOverride' => false,
					'clientSideMimeTypes'        => array(),
					'lazyLoadBackgroundImages'   => false,
				),
				'performance_audit'     => array(
					'pagespeed_api_key'     => '',
					'high_value_urls'       => array(),
					'auto_fix_enabled'      => false,
					'server_timing_enabled' => false,
					'auto_rescan'           => '',
					'rum_enabled'           => false,
				),
				'database_cleanup'      => array(),
				'object_cache'          => array(),
				'litespeed_integration' => array(
					'mode'                 => 'auto',
					'enableNextGenRewrite' => false,
					'enableBrotli'         => false,
					'purgeSync'            => true,
				),
				'llms_txt'              => array(
					'enabled' => false,
					'source'  => 'both',
				),
				'od_integration'        => array(
					'enabled' => class_exists( 'OD_URL_Metric' ) || function_exists( 'od_get_url_metrics' ),
				),
				'bfcache'               => array(
					'enabled' => false,
				),
				'perf_translations'     => array(
					'enabled' => false,
				),
				'ai_adaptive'           => array(
					'enabled' => false,
				),
				'edge_cache'            => array(
					'enabled' => false,
				),
			);
		}

		/**
		 * Static cache for resolved home URLs, keyed by blog ID.
		 *
		 * @var array<int, string>
		 * @since NEXT
		 */
		private static array $home_url_cache = array();

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
		 * Resets the home_url static cache for testing isolation.
		 *
		 * @since NEXT
		 */
		public static function reset_cached_home_urls(): void {
			self::$home_url_cache = array();
		}

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
		 * Clear the settings memo (e.g. in tests or on delete).
		 *
		 * When called without args clears all blog entries (test isolation).
		 * When called with a blog ID clears that blog only. The WP
		 * delete_option_wppo_settings action passes no blog ID, so the full
		 * clear path is taken. switch_blog is handled by on_switch_blog().
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
		 * Handler for switch_blog — clears stale memo association.
		 *
		 * Kept separate from clear_settings_cache for hook arity clarity.
		 *
		 * @since NEXT
		 * @param int $new_blog_id New blog ID.
		 * @param int $prev_blog_id Previous blog ID.
		 * @return void
		 */
		public static function on_switch_blog( $new_blog_id, $prev_blog_id ): void {
			// No destructive clear needed because get_settings() is blog-keyed;
			// this hook exists as a safety net and to satisfy F-COMPAT-03 audit.
			// Intentionally no-op: per-blog keying already isolates.
			unset( $new_blog_id, $prev_blog_id );
		}

		/**
		 * Reset all Util static caches (home_url + settings) for testing isolation.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function reset_all_caches(): void {
			self::$home_url_cache        = array();
			self::$settings_cache        = array();
			self::$settings_cache_loaded = array();
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
			add_action( 'switch_blog', array( self::class, 'on_switch_blog' ), 10, 2 );
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
		 * Recursively creates cache directory if not exists.
		 *
		 * @param string $cache_dir Path to the cache directory.
		 * @return bool True if created or exists, false otherwise.
		 * @since 1.0.0
		 */
		public static function prepare_cache_dir( $cache_dir ): bool {
			$fs = self::init_filesystem();

			if ( ! $fs ) {
				return false;
			}

			if ( $fs->is_dir( $cache_dir ) ) {
				return true;
			}

			// Build parent directories iteratively to avoid deep recursion.
			$path  = wp_normalize_path( $cache_dir );
			$parts = explode( '/', trim( $path, '/' ) );
			$build = '';

			foreach ( $parts as $part ) {
				$build = $build ? $build . '/' . $part : '/' . $part;
				if ( ! $fs->is_dir( $build ) ) {
					if ( ! $fs->mkdir( $build, FS_CHMOD_DIR ) ) {
						return false;
					}
				}
			}

			return true;
		}

		/**
		 * Initializes the WP_Filesystem API.
		 *
		 * @return mixed WP_Filesystem_Base|false The filesystem object or false on failure.
		 * @since 1.0.0
		 */
		public static function init_filesystem() {
			global $wp_filesystem;

			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once wp_normalize_path( ABSPATH . 'wp-admin/includes/file.php' );
			}

			if ( WP_Filesystem() ) {
				return $wp_filesystem;
			} else {
				return false;
			}
		}

		/**
		 * Gets the local file path from a URL.
		 *
		 * @param string $url The URL to process.
		 * @return string The local file path.
		 * @since 1.0.0
		 */
		public static function get_local_path( string $url ): string {
			// Parse the URL to get the path.
			$parsed_url = wp_parse_url( $url );
			if ( false === $parsed_url ) {
				return '';
			}

			// Get the path from the parsed URL.
			$relative_path = wp_normalize_path( $parsed_url['path'] ?? '' );

			if ( strpos( $relative_path, '..' ) !== false ) {
				return '';
			}

			// If home_url has a subdirectory path, remove it only from the start.
			$home_path = wp_normalize_path( wp_parse_url( self::cached_home_url(), PHP_URL_PATH ) ?? '' );

			if ( $home_path && '/' !== $home_path ) {
				if ( 0 === strpos( $relative_path, $home_path ) ) {
					$relative_path = substr( $relative_path, strlen( $home_path ) );
				}
			}

			// Build the full local path and verify it stays within ABSPATH.
			$normalized_abspath = wp_normalize_path( ABSPATH );
			$full_path          = wp_normalize_path( ABSPATH . ltrim( $relative_path, '/' ) );

			// Enforce ABSPATH prefix bounds. normalized_abspath ends with a '/', so a
			// 0-offset prefix match guarantees the path is a descendant of ABSPATH
			// (a sibling directory such as "/path/abspath2" cannot prefix-match).
			if ( 0 !== strpos( $full_path, $normalized_abspath ) ) {
				return '';
			}

			return $full_path;
		}

		/**
		 * Gets the number of minified JS and CSS files.
		 *
		 * @return array Associative array with counts for JS and CSS files.
		 * @since 1.0.0
		 */
		public static function get_js_css_minified_file() {
			$filesystem = self::init_filesystem();
			if ( ! $filesystem ) {
				return array(
					'js'  => 0,
					'css' => 0,
				);
			}
			$minify_dir = self::min_cache_dir();

			$total_js  = 0;
			$total_css = 0;

			$js_files = $filesystem->dirlist( $minify_dir . '/js' );

			if ( ! empty( $js_files ) ) {
				foreach ( $js_files as $js_file ) {
					if ( isset( $js_file['name'] ) && pathinfo( $js_file['name'], PATHINFO_EXTENSION ) === 'js' ) {
						++$total_js;
					}
				}
			}

			$css_files = $filesystem->dirlist( $minify_dir . '/css' );

			if ( ! empty( $css_files ) ) {
				foreach ( $css_files as $css_file ) {
					if ( isset( $css_file['name'] ) && pathinfo( $css_file['name'], PATHINFO_EXTENSION ) === 'css' ) {
						++$total_css;
					}
				}
			}

			return array(
				'js'  => $total_js,
				'css' => $total_css,
			);
		}

		/**
		 * Gets MIME type based on image URL extension.
		 *
		 * @param string $url The image URL.
		 * @return string The MIME type.
		 * @since 1.0.0
		 */
		public static function get_image_mime_type( $url ) {
			// Infer MIME type from URL extension.
			$extension = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );

			switch ( $extension ) {
				case 'jpg':
				case 'jpeg':
					return 'image/jpeg';
				case 'png':
					return 'image/png';
				case 'webp':
					return 'image/webp';
				case 'gif':
					return 'image/gif';
				case 'svg':
					return 'image/svg+xml';
				case 'avif':
					return 'image/avif';
				case 'heic':
					return 'image/heic';
				case 'heif':
					return 'image/heif';
				default:
					return '';
			}
		}

		/**
		 * Generates a preload link tag for resources.
		 *
		 * Preconnect and dns-prefetch links now flow through core's
		 * `wp_resource_hints()` (see Main::add_resource_hints()); this helper
		 * remains for `rel="preload"` links that need `as`/`type`/`media`
		 * control.
		 *
		 * @param string $href The resource URL.
		 * @param string $rel The relationship attribute.
		 * @param string $resource_type The type of the resource (optional).
		 * @param bool   $crossorigin If the resource should be crossorigin (optional).
		 * @param string $type The type attribute (optional).
		 * @param string $media The media attribute (optional).
		 * @param string $fetchpriority The fetchpriority attribute (optional).
		 * @since 1.0.0
		 */
		public static function generate_preload_link( $href, $rel, $resource_type = '', $crossorigin = false, $type = '', $media = '', $fetchpriority = '' ) {
			$attributes = array(
				'rel'  => esc_attr( $rel ),
				'href' => esc_url( $href ),
			);

			if ( $resource_type ) {
				$attributes['as'] = esc_attr( $resource_type );
			}
			if ( $crossorigin ) {
				$attributes['crossorigin'] = 'anonymous';
			}
			if ( $type ) {
				$attributes['type'] = esc_attr( $type );
			}
			if ( $media ) {
				$attributes['media'] = esc_attr( $media );
			}
			if ( $fetchpriority ) {
				$attributes['fetchpriority'] = esc_attr( $fetchpriority );
			}

			$link_tag = '<link ' . implode( ' ', array_map( fn ( $k, $v ) => $k . '="' . $v . '"', array_keys( $attributes ), $attributes ) ) . '>';

			$allowed_html = array(
				'link' => array(
					'rel'           => array(),
					'href'          => array(),
					'as'            => array(),
					'crossorigin'   => array(),
					'type'          => array(),
					'media'         => array(),
					'fetchpriority' => array(),
				),
			);

			// Output the sanitized link tag.
			echo wp_kses( $link_tag, $allowed_html ) . PHP_EOL;
		}

		/**
		 * Normalize and deduplicate a list of URLs.
		 *
		 * If given an array, each element is trimmed, duplicates and empty values are removed, and the result is reindexed.
		 * If given a non-array, the value is cast to string, split on newline characters, then trimmed, deduplicated, filtered and reindexed.
		 *
		 * @param string|array $urls Raw URLs as a newline-delimited string or an array of strings.
		 * @return array Cleaned list of unique, trimmed URLs with empty values removed and numeric keys reindexed.
		 * @since 1.0.0
		 */
		public static function process_urls( $urls ) {
			if ( is_array( $urls ) ) {
				return array_values( array_filter( array_unique( array_map( 'trim', $urls ) ) ) );
			}
			return array_values( array_filter( array_unique( array_map( 'trim', explode( "\n", (string) $urls ) ) ) ) );
		}

		/**
		 * Check whether a URL matches any of the exclusion rules.
		 *
		 * Both the URL being checked and each exclusion rule are normalized with
		 * a trailing-slash trim before matching. Schemes are normalized so that
		 * `http://` and `https://` rules match interchangeably. Root-relative
		 * rules are resolved against {@see home_url()}. Rules containing a
		 * "(.*)" placeholder act as prefix patterns; all other rules must match
		 * exactly. Empty and whitespace-only rules are ignored.
		 *
		 * @param string $url         The URL to check.
		 * @param array  $exclude_urls List of exclusion rules.
		 * @return bool True when the URL matches any exclusion rule, false otherwise.
		 * @since NEXT
		 */
		public static function is_url_excluded( string $url, array $exclude_urls ): bool {
			$url = rtrim( $url, '/' );

			// Resolve the home base once per request.
			$home_base = self::cached_home_url();

			// Replace regex scheme removal with fast string operations and evaluate the base URL once.
			$strip_scheme   = static function ( $u ) {
				if ( 0 === stripos( $u, 'https://' ) ) {
					return substr( $u, 8 );
				}
				if ( 0 === stripos( $u, 'http://' ) ) {
					return substr( $u, 7 );
				}
				return $u;
			};
			$normalized_url = $strip_scheme( $url );

			foreach ( $exclude_urls as $exclude_url ) {
				$exclude_url = rtrim( $exclude_url, '/' );

				// Skip empty and whitespace-only rules.
				if ( '' === $exclude_url ) {
					continue;
				}

				if ( 0 !== strpos( $exclude_url, 'http' ) ) {
					$exclude_url = $home_base . '/' . ltrim( $exclude_url, '/' );
				}

				// Normalize schemes so http and https rules match interchangeably.
				$normalized_rule = $strip_scheme( $exclude_url );

				if ( false !== strpos( $normalized_rule, '(.*)' ) ) {
					// Normalize the prefix with a trailing slash so the base path
					// itself (no trailing slash) and all descendants match, while
					// similar-but-distinct paths (e.g. /cartoon) do not.
					$exclude_prefix = rtrim( str_replace( '(.*)', '', $normalized_rule ), '/' ) . '/';

					if ( 0 === strpos( $normalized_url . '/', $exclude_prefix ) ) {
						return true;
					}
				}

				if ( $normalized_url === $normalized_rule ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Get the current front-end URL including scheme and host.
		 *
		 * Returns a normalized URL without query string, consistent with
		 * the normalization used in store_lcp_image_url(). The returned
		 * URL is untrailingslashed and passed through esc_url_raw().
		 *
		 * @since NEXT
		 * @return string Current URL.
		 */
		public static function get_current_url(): string {
			global $wp;
			$url = self::cached_home_url( (string) add_query_arg( array(), $wp->request ?? '' ) );
			return untrailingslashit( esc_url_raw( $url ) );
		}

		/**
		 * Normalize a URL for LCP matching.
		 *
		 * Resolves protocol-relative and root-relative URLs against home_url(),
		 * drops the scheme and any query string, and strips WordPress generated
		 * size suffixes (-NNNxNNN, -scaled, -eNNN) so derived assets are treated
		 * as the same image as their full-size original. Returns host + path
		 * lowercased for host, or empty string when unparseable.
		 *
		 * @since NEXT
		 * @param string $url The raw URL to normalize.
		 * @return string Normalized host + path, or empty string when unparseable.
		 */
		public static function normalize_url( string $url ): string {
			$url = trim( $url );
			if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
				return '';
			}

			if ( 0 === strpos( $url, '//' ) ) {
				$url = 'https:' . $url;
			}

			if ( 0 === strpos( $url, '/' ) || false === strpos( $url, '://' ) ) {
				$url = self::cached_home_url() . '/' . ltrim( $url, '/' );
			}

			$parts = wp_parse_url( $url );
			if ( empty( $parts['path'] ) ) {
				return '';
			}

			$host = strtolower( $parts['host'] ?? '' );
			$path = $parts['path'];
			$path = (string) preg_replace( '#-(?:\d+x\d+|scaled|e\d+)(?=\.[A-Za-z0-9]+)$#', '', $path );

			return $host . $path;
		}

		/**
		 * Base (shared) minify cache directory.
		 *
		 * Minified JS/CSS files are namespaced per site (see {@see min_cache_dir()}),
		 * so this returns the shared root. Used for the plugin-owned path-data
		 * prefix check and one-time cleanup of pre-namespacing directories.
		 *
		 * @return string Normalized absolute path to the shared min cache root.
		 * @since NEXT
		 */
		public static function min_cache_base_dir(): string {
			return wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/min' );
		}

		/**
		 * Get the current site's blog-scoped minify cache directory.
		 *
		 * Mirrors the blog-ID isolation conventions used by {@see transient_key()}
		 * and {@see cached_content_url()}: on multisite each site's minified
		 * JS/CSS files live under `cache/wppo/min/{blog_id}/{css,js}` so a cache
		 * clear scoped to one site cannot invalidate another site's assets (whose
		 * min files may embed site-specific `content_url()` URLs).
		 *
		 * @param string $subdir Optional 'css' or 'js' subdirectory.
		 * @return string Normalized absolute path to the site-scoped min cache dir.
		 * @since NEXT
		 */
		public static function min_cache_dir( string $subdir = '' ): string {
			$dir = self::min_cache_base_dir() . '/' . get_current_blog_id();
			if ( '' !== $subdir ) {
				$dir .= '/' . $subdir;
			}
			return wp_normalize_path( $dir );
		}

		/**
		 * Get the content URL for a file in the current site's min cache dir.
		 *
		 * @param string $subdir   Optional 'css' or 'js' subdirectory.
		 * @param string $filename Optional file name appended to the URL.
		 * @return string The blog-scoped content URL.
		 * @since NEXT
		 */
		public static function min_cache_url( string $subdir = '', string $filename = '' ): string {
			$path = 'cache/wppo/min/' . get_current_blog_id();
			if ( '' !== $subdir ) {
				$path .= '/' . $subdir;
			}
			if ( '' !== $filename ) {
				$path .= '/' . $filename;
			}
			return self::cached_content_url( $path );
		}

		/**
		 * Get a content URL, cached per site per request.
		 *
		 * Centralizes the blog-ID-keyed static caching pattern used by the asset
		 * minifiers. Mirrors the convention in `class-main.php`: when a
		 * `content_url` filter is registered the result is not cached (the filter
		 * may return context-dependent output), otherwise the base URL is resolved
		 * once per site per request and reused across all call sites.
		 *
		 * @param string $path Path relative to the content directory.
		 * @return string The content URL for the given path.
		 * @since NEXT
		 */
		public static function cached_content_url( $path ) {
			if ( false !== has_filter( 'content_url' ) ) {
				return content_url( $path );
			}

			static $cache = array();
			$blog_id      = get_current_blog_id();

			if ( ! isset( $cache[ $blog_id ] ) ) {
				$cache[ $blog_id ] = array();
			}
			if ( ! isset( $cache[ $blog_id ][ $path ] ) ) {
				$cache[ $blog_id ][ $path ] = content_url( $path );
			}

			return $cache[ $blog_id ][ $path ];
		}

		/**
		 * Get the home URL, cached per site per request.
		 *
		 * Centralizes the blog-ID-keyed static caching pattern used across the plugin.
		 * When a `home_url` filter is registered the result is not cached (the filter
		 * may return context-dependent output), otherwise the base URL is resolved
		 * once per site per request and reused across all call sites.
		 *
		 * @param string $path Optional. Path relative to the home URL. Default empty.
		 * @return string The untrailingslashed home URL, with path appended if provided.
		 * @since NEXT
		 */
		public static function cached_home_url( string $path = '' ): string {
			if ( false !== has_filter( 'home_url' ) ) {
				return '' === $path ? untrailingslashit( home_url() ) : home_url( $path );
			}

			$blog_id = get_current_blog_id();

			if ( ! isset( self::$home_url_cache[ $blog_id ] ) ) {
				self::$home_url_cache[ $blog_id ] = untrailingslashit( home_url() );
			}

			if ( '' === $path ) {
				return self::$home_url_cache[ $blog_id ];
			}

			return self::$home_url_cache[ $blog_id ] . '/' . ltrim( $path, '/' );
		}

		/**
		 * Qualify a transient key with the current blog ID on multisite.
		 *
		 * Prevents transient key collisions when a shared object cache backend
		 * (Redis, Memcached) is present. On single-site installs the key is
		 * returned unchanged.
		 *
		 * @param string $key The bare transient key.
		 * @return string Blog-ID-prefixed key on multisite, or the original key.
		 * @since NEXT
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
		 * Compute a stable 12-char hex hash of a user's sorted roles, salted
		 * with the site's secret to prevent cookie forgery.
		 *
		 * Used by the caching layer to generate role-specific cache variants for
		 * logged-in users. The same salt is embedded in the advanced-cache.php
		 * drop-in at generation time so the early-boot serving code can compute
		 * an identical hash.
		 *
		 * @since NEXT
		 * @param \WP_User $user The user whose roles to hash.
		 * @return string 12-char hex hash, or empty string if the user has no roles.
		 */
		public static function get_role_hash( \WP_User $user ): string {
			if ( empty( $user->roles ) ) {
				return '';
			}
			$roles = $user->roles;
			sort( $roles );
			return substr( md5( implode( ',', $roles ) . wp_salt() ), 0, 12 );
		}

		/**
		 * Whether the current user is eligible for logged-in caching based on
		 * the cache settings (enableLoggedInCache + loggedInCacheRoles).
		 *
		 * Non-logged-in visitors always return true (they always get cached).
		 *
		 * @since NEXT
		 * @param array $cache_settings The cache_settings sub-array from wppo_settings.
		 * @return bool True if the current user may receive cached pages / optimisations.
		 */
		public static function is_cache_eligible_for_current_user( array $cache_settings ): bool {
			if ( ! is_user_logged_in() ) {
				return true;
			}

			$enable = ! empty( $cache_settings['enableLoggedInCache'] ?? false );
			if ( ! $enable ) {
				return false;
			}

			$user = wp_get_current_user();
			if ( empty( $user->roles ) ) {
				return false;
			}

			$allowed_roles = $cache_settings['loggedInCacheRoles'] ?? array();
			if ( ! is_array( $allowed_roles ) || empty( $allowed_roles ) ) {
				return true;
			}

			foreach ( $user->roles as $role ) {
				if ( in_array( $role, $allowed_roles, true ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Whether the current WordPress version supports auto-sizes for lazy-loaded images.
		 *
		 * Enhanced Responsive Images (Core ticket #61847) shipped in WordPress 6.7 and is
		 * gated on the presence of wp_img_tag_add_auto_sizes() / wp_sizes_attribute_includes_valid_auto().
		 *
		 * @since 1.9.0
		 * @return bool True when auto-sizes is available.
		 */
		public static function is_auto_sizes_available(): bool {
			return function_exists( 'wp_sizes_attribute_includes_valid_auto' )
				|| function_exists( 'wp_img_tag_add_auto_sizes' );
		}

		/**
		 * Whether safe mode is active via ?wppo_safe=1 kill-switch.
		 *
		 * Checked in Main::setup_hooks() to bypass Buffer::process_buffer_only
		 * (see class-cache.php). When `?wppo_safe=1` is present on the request,
		 * a `wppo_safe_mode=1` cookie is set for 10 minutes (600s) so the
		 * bypass persists without keeping the query string. Subsequent requests
		 * without the query string are still considered safe when the cookie
		 * value is `1`. Uses COOKIEPATH/COOKIE_DOMAIN when available and mirrors
		 * WordPress cookie conventions (secure + httponly).
		 *
		 * @since NEXT
		 * @return bool True when safe mode should bypass optimisations.
		 */
		public static function is_safe_mode(): bool {
			// Kill-switch via query string `?wppo_safe=1` — also arms the 10-minute cookie.
			if ( isset( $_GET['wppo_safe'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$wppo_safe = sanitize_text_field( wp_unslash( $_GET['wppo_safe'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( '1' === $wppo_safe ) {
					if ( ! headers_sent() ) {
						$expire = time() + 600;
						$path   = defined( 'COOKIEPATH' ) ? COOKIEPATH : '/';
						$domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
						$secure = function_exists( 'is_ssl' ) ? is_ssl() : false;
						// SameSite=Lax for CSRF resilience while preserving normal navigation (MUST per Security Agent F).
						$opts = array(
							'expires'  => $expire,
							'path'     => $path,
							'domain'   => $domain,
							'secure'   => $secure,
							'httponly' => true,
							'samesite' => 'Lax',
						);
						if ( PHP_VERSION_ID >= 70300 ) {
							// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
							setcookie( 'wppo_safe_mode', '1', $opts );
						} else {
							// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
							setcookie( 'wppo_safe_mode', '1', $expire, $path . '; SameSite=Lax', $domain, $secure, true );
						}
					}
					$_COOKIE['wppo_safe_mode'] = '1';
					return true;
				}
			}
			if ( isset( $_COOKIE['wppo_safe_mode'] ) ) {
				$cookie = sanitize_text_field( wp_unslash( $_COOKIE['wppo_safe_mode'] ) );
				return '1' === $cookie;
			}
			return false;
		}

		/**
		 * Sanitizes the settings array recursively.
		 *
		 * Shared by every settings entry point (REST API, WP-CLI import/update)
		 * so that values written to `wppo_settings` are always sanitized and
		 * type-normalized regardless of how they arrive.
		 *
		 * @param array $settings The settings array.
		 * @return array The sanitized settings array.
		 * @since NEXT
		 */
		public static function sanitize_settings_recursively( $settings ) {
			$sanitized = array();
			foreach ( $settings as $key => $value ) {
				$safe_key = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key );

				// Skip keys that become empty after sanitization so that
				// settings are never stored under an empty-string key.
				if ( '' === $safe_key ) {
					continue;
				}

				// LiteSpeed integration — allowlist mode values.
				if ( 'mode' === $safe_key && ! is_array( $value ) ) {
					$raw                    = sanitize_text_field( (string) $value );
					$mode                   = in_array( $raw, array( 'auto', 'wppo', 'litespeed', 'standalone' ), true ) ? $raw : 'auto';
					$sanitized[ $safe_key ] = $mode;
					continue;
				}

				if ( is_array( $value ) ) {
					$sanitized[ $safe_key ] = self::sanitize_settings_recursively( $value );
				} elseif ( is_bool( $value ) ) {
					$sanitized[ $safe_key ] = (bool) $value;
				} elseif ( is_numeric( $value ) ) {
					$sanitized[ $safe_key ] = (int) $value;
				} elseif ( in_array( $safe_key, array( 'pagespeed_api_key', 'password' ), true ) ) {
					$sanitized[ $safe_key ] = sanitize_text_field( $value );
				} elseif ( stripos( $safe_key, 'exclude' ) !== false || stripos( $safe_key, 'preload' ) !== false || stripos( $safe_key, 'delay' ) !== false || stripos( $safe_key, 'list' ) !== false ) {
					$sanitized[ $safe_key ] = sanitize_textarea_field( $value );
				} elseif ( stripos( $safe_key, 'url' ) !== false || stripos( $safe_key, 'cdn' ) !== false || stripos( $safe_key, 'origin' ) !== false ) {
					$sanitized[ $safe_key ] = esc_url_raw( $value );
				} else {
					$sanitized[ $safe_key ] = sanitize_text_field( $value );
				}
			}
			return $sanitized;
		}
	}
}
