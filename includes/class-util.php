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
			static $home_path = array();
			$blog_id          = get_current_blog_id();

			if ( ! isset( $home_path[ $blog_id ] ) ) {
				$home_path[ $blog_id ] = wp_normalize_path( wp_parse_url( home_url(), PHP_URL_PATH ) ?? '' );
			}

			if ( $home_path[ $blog_id ] && '/' !== $home_path[ $blog_id ] ) {
				if ( 0 === strpos( $relative_path, $home_path[ $blog_id ] ) ) {
					$relative_path = substr( $relative_path, strlen( $home_path[ $blog_id ] ) );
				}
			}

			// Return the full local path.
			return wp_normalize_path( ABSPATH . ltrim( $relative_path, '/' ) );
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
			$minify_dir = wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/min' );

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
		 * Get the current front-end URL including scheme and host.
		 *
		 * Returns a normalized URL without query string, consistent with
		 * the normalization used in store_lcp_image_url(). The returned
		 * URL is untrailingslashed and passed through esc_url_raw().
		 *
		 * @since 2.13.0
		 * @return string Current URL.
		 */
		public static function get_current_url(): string {
			global $wp;
			$url = home_url( add_query_arg( array(), $wp->request ) );
			return untrailingslashit( esc_url_raw( $url ) );
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
		 * @since 2.6.0
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
		 * @since 2.8.0
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
		 * @since 2.8.0
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
	}
}
