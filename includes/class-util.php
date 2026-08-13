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
				$normalized_url  = preg_replace( '#^https?://#i', '', $url );
				$normalized_rule = preg_replace( '#^https?://#i', '', $exclude_url );

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
			$url = self::cached_home_url() . '/' . ltrim( add_query_arg( array(), $wp->request ), '/' );
			return untrailingslashit( esc_url_raw( $url ) );
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
		 * @return string The untrailingslashed home URL.
		 * @since NEXT
		 */
		public static function cached_home_url(): string {
			if ( false !== has_filter( 'home_url' ) ) {
				return untrailingslashit( home_url() );
			}

			static $cache = array();
			$blog_id      = get_current_blog_id();

			if ( ! isset( $cache[ $blog_id ] ) ) {
				$cache[ $blog_id ] = untrailingslashit( home_url() );
			}

			return $cache[ $blog_id ];
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
			return is_multisite() ? (string) get_current_blog_id() . '_' . $key : $key;
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
	}
}
