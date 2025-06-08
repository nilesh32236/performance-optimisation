<?php
/**
 * PerformanceOptimise Utility Class
 *
 * This file contains the `Util` class, which provides various utility methods
 * for file system and resource management tasks, including cache directory creation,
 * filesystem initialization, URL processing, generating preload links, and handling image MIME types.
 *
 * @package PerformanceOptimise
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
		 * Initializes the WP_Filesystem API.
		 *
		 * @since 1.0.0
		 * @return \WP_Filesystem_Base|null WordPress Filesystem object or null on failure.
		 */
		public static function init_filesystem() {
			global $wp_filesystem;

			if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
				return $wp_filesystem;
			}

			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once wp_normalize_path( ABSPATH . 'wp-admin/includes/file.php' );
			}

			if ( WP_Filesystem() ) {
				return $wp_filesystem;
			} else {
				if ( ! class_exists( '\WP_Filesystem_Direct' ) ) {
					return null;
				}
				new \WP_Filesystem_Direct( null );
				if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
					return $wp_filesystem;
				}
			}

			return null;
		}

		/**
		 * Recursively creates cache directory if not exists.
		 *
		 * @param string $cache_dir Path to the cache directory.
		 * @return bool True if created or exists, false otherwise.
		 * @since 1.0.0
		 */
		public static function prepare_cache_dir( string $cache_dir ): bool {
			$wp_filesystem = self::init_filesystem();

			if ( ! $wp_filesystem ) {
				return false;
			}

			if ( $wp_filesystem->is_dir( $cache_dir ) ) {
				return true;
			}

			$parent_dir = dirname( $cache_dir );

			if ( '.' !== $parent_dir && ! $wp_filesystem->is_dir( $parent_dir ) ) {
				if ( ! self::prepare_cache_dir( $parent_dir ) ) {
					return false;
				}
			}

			if ( ! $wp_filesystem->mkdir( $cache_dir, FS_CHMOD_DIR ) ) {
				return false;
			}

			return true;
		}

		/**
		 * Gets the local file path from a URL.
		 *
		 * @param string $url The URL to process.
		 * @return string The local file path.
		 * @since 1.0.0
		 */
		public static function get_local_path( string $url ): string {
			$url_parts = wp_parse_url( $url );
			$path      = $url_parts['path'] ?? '';

			$home_path     = wp_parse_url( home_url(), PHP_URL_PATH );
			$relative_path = $path;
			if ( $home_path && '/' !== $home_path && 0 === strpos( $path, $home_path ) ) {
				$relative_path = substr( $path, strlen( $home_path ) );
			}

			return wp_normalize_path( ABSPATH . ltrim( $relative_path, '/' ) );
		}

		/**
		 * Gets the number of minified JS and CSS files.
		 *
		 * @return array{js: int, css: int} Associative array with counts for JS and CSS files.
		 * @since 1.0.0
		 */
		public static function get_js_css_minified_file(): array {
			$wp_filesystem = self::init_filesystem();
			$minify_dir    = wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/min' );

			$totals = array(
				'js'  => 0,
				'css' => 0,
			);

			if ( ! $wp_filesystem ) {
				return $totals;
			}

			$js_dir  = $minify_dir . '/js';
			$css_dir = $minify_dir . '/css';

			if ( $wp_filesystem->is_dir( $js_dir ) ) {
				$js_files = $wp_filesystem->dirlist( $js_dir );
				if ( ! empty( $js_files ) ) {
					foreach ( $js_files as $js_file ) {
						if ( isset( $js_file['name'] ) && 'js' === pathinfo( $js_file['name'], PATHINFO_EXTENSION ) ) {
							++$totals['js'];
						}
					}
				}
			}

			if ( $wp_filesystem->is_dir( $css_dir ) ) {
				$css_files = $wp_filesystem->dirlist( $css_dir );
				if ( ! empty( $css_files ) ) {
					foreach ( $css_files as $css_file ) {
						if ( isset( $css_file['name'] ) && 'css' === pathinfo( $css_file['name'], PATHINFO_EXTENSION ) ) {
							++$totals['css'];
						}
					}
				}
			}

			return $totals;
		}

		/**
		 * Gets MIME type based on image URL extension.
		 *
		 * @param string $url The image URL.
		 * @return string The MIME type.
		 * @since 1.0.0
		 */
		public static function get_image_mime_type( string $url ): string {
			$path      = wp_parse_url( $url, PHP_URL_PATH );
			$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

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
				default:
					return '';
			}
		}

		/**
		 * Generates a preload link tag for resources.
		 *
		 * @param string $href The resource URL.
		 * @param string $rel The relationship attribute.
		 * @param string $resource_type Optional. The type of the resource (e.g., 'style', 'script', 'image', 'font'). Default empty.
		 * @param bool   $crossorigin Optional. If the resource should be crossorigin. Default false.
		 * @param string $type Optional. The type attribute (e.g., 'text/css', 'font/woff2'). Default empty.
		 * @param string $media Optional. The media attribute. Default empty.
		 * @since 1.0.0
		 */
		public static function generate_preload_link( string $href, string $rel, string $resource_type = '', bool $crossorigin = false, string $type = '', string $media = '' ): void {
			$attributes = array(
				'rel'  => esc_attr( $rel ),
				'href' => esc_url( $href ),
			);

			if ( ! empty( $resource_type ) ) {
				$attributes['as'] = esc_attr( $resource_type );
			}
			if ( $crossorigin ) {
				$attributes['crossorigin'] = 'anonymous'; // Standard value, or simply 'crossorigin'
			}
			if ( ! empty( $type ) ) {
				$attributes['type'] = esc_attr( $type );
			}
			if ( ! empty( $media ) ) {
				$attributes['media'] = esc_attr( $media );
			}

			$link_tag_parts = array();
			foreach ( $attributes as $key => $value ) {
				$link_tag_parts[] = sprintf( '%s="%s"', $key, $value );
			}

			// Output is escaped using WordPress escaping functions.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<link ' . implode( ' ', $link_tag_parts ) . '>' . PHP_EOL;
		}

		/**
		 * Processes and cleans up a list of URLs from a string.
		 * Each URL is expected on a new line.
		 *
		 * @param string $urls_string The raw string containing URLs, one per line.
		 * @return array The cleaned-up list of unique URLs.
		 * @since 1.0.0
		 */
		public static function process_urls( string $urls_string ): array {
			if ( empty( $urls_string ) ) {
				return array();
			}
			$urls_array = explode( "\n", $urls_string );
			$urls_array = array_map( 'trim', $urls_array );
			$urls_array = array_filter( $urls_array );
			$urls_array = array_unique( $urls_array );
			return $urls_array;
		}
	}
}
