<?php
/**
 * Utility class for the PerformanceOptimise plugin.
 *
 * Provides methods for preparing cache directories and initializing the WP_Filesystem API.
 *
 * @package PerformanceOptimise\Inc
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) ) {
	class Util {

		/**
		 * Recursively prepares the cache directory by creating it if it does not exist.
		 *
		 * This method ensures that the specified directory and all its parent directories
		 * are created if they do not already exist.
		 *
		 * @param string $cache_dir Path to the cache directory.
		 * @return bool True if the directory was created successfully or already exists, false otherwise.
		 */
		public static function prepare_cache_dir( $cache_dir ): bool {
			global $wp_filesystem;

			// Check if the directory already exists
			if ( ! $wp_filesystem->is_dir( $cache_dir ) ) {

				// Recursively create parent directories first
				$parent_dir = dirname( $cache_dir );

				if ( ! $wp_filesystem->is_dir( $parent_dir ) ) {
					self::prepare_cache_dir( $parent_dir );
				}

				// Create the final directory
				if ( ! $wp_filesystem->mkdir( $cache_dir, FS_CHMOD_DIR ) ) {
					// error_log( "Failed to create directory using WP_Filesystem: $cache_dir" );
					return false;
				}
			}

			return true;
		}

		/**
		 * Initializes the WP_Filesystem API.
		 *
		 * This method ensures that the WP_Filesystem API is available and initializes it.
		 *
		 */
		public static function init_filesystem() {
			global $wp_filesystem;

			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			if ( WP_Filesystem() ) {
				return $wp_filesystem;
			} else {
				new \WP_Filesystem_Direct( null );
				return $wp_filesystem;
			}
		}

		public static function get_local_path( string $url ): string {
			// Parse the URL to get the path
			$parsed_url = wp_parse_url( $url );

			// Get the path from the parsed URL
			$relative_path = $parsed_url['path'] ?? '';

			// If home_url is present, remove it from the path
			$relative_path = str_replace( wp_parse_url( home_url(), PHP_URL_PATH ) ?? '', '', $relative_path );

			// Return the full local path
			return ABSPATH . ltrim( $relative_path, '/' );
		}

		public static function get_js_css_minified_file() {
			$filesystem = self::init_filesystem();
			$minify_dir = WP_CONTENT_DIR . '/cache/wppo/min';

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
				default:
					return '';
			}
		}

		public static function generate_preload_link( $href, $rel, $resource_type = '', $crossorigin = false, $type = '', $media = '' ) {
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

			// Attributes are sanitized earlier in the code; output is safe.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<link ' . implode( ' ', array_map( fn ( $k, $v ) => $k . '="' . $v . '"', array_keys( $attributes ), $attributes ) ) . '>' . PHP_EOL;
		}

		public static function process_urls( $urls ) {
			return array_filter( array_unique( array_map( 'trim', explode( "\n", $urls ) ) ) );
		}
	}
}
