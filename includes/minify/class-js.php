<?php
/**
 * Minify JavaScript File Class
 *
 * This class is responsible for minifying a JavaScript file and saving it to a cache directory.
 * It uses the MatthiasMullie Minify library for minification. The minified file is saved in a cache
 * directory with a gzipped version. If the minified file already exists, it returns its URL.
 *
 * @package PerformanceOptimise\Inc\Minify
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc\Minify;

use MatthiasMullie\Minify;
use PerformanceOptimise\Inc\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Minify\JS' ) ) {
	/**
	 * Class JS
	 *
	 * Handles the minification of JavaScript files and caching of the results.
	 * The minified JavaScript file is stored in a cache directory and can be retrieved via a URL.
	 *
	 * @package PerformanceOptimise\Inc\Minify
	 * @since 1.0.0
	 */
	class JS {
		/**
		 * The file path of the original JavaScript file.
		 *
		 * @var string $file_path The path to the JavaScript file to minify.
		 * @since 1.0.0
		 */
		private string $file_path;

		/**
		 * The directory where minified files will be cached.
		 *
		 * @var string $cache_dir The directory to store the minified file.
		 * @since 1.0.0
		 */
		private string $cache_dir;

		/**
		 * The filesystem object used for file operations.
		 *
		 * @var object $filesystem The object responsible for file read/write operations.
		 * @since 1.0.0
		 */
		private $filesystem;

		/**
		 * URL base for cache files, derived from $cache_dir.
		 *
		 * @var string
		 */
		private string $cache_url;

		/**
		 * JS constructor to initialize file path, cache directory, and filesystem.
		 *
		 * @param string $file_path The path to the JavaScript file to minify.
		 * @param string $cache_dir The directory to store the minified file.
		 *
		 * @since 1.0.0
		 */
		public function __construct( string $file_path, string $cache_dir ) {
			// Audit #1434: typed like Minify\CSS.
			// Traversal-safe by construction (issue #1179): the shared
			// Util::validate_minify_path() gate rejects ../, NUL bytes,
			// stream wrappers, and .php targets, resolves symlinks via
			// realpath(), and requires containment in an allow-listed root
			// (ABSPATH, WP_CONTENT_DIR, uploads dir) after resolution.
			// Fail-closed to '' so minify() degrades to uncombined output.
			if ( method_exists( 'PerformanceOptimise\Inc\Util', 'validate_minify_path' ) ) {
				$this->file_path = Util::validate_minify_path( $file_path );
			} else {
				$real_path   = function_exists( 'realpath' ) ? realpath( $file_path ) : $file_path;
				$content_dir = wp_normalize_path( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '' );
				if ( false === $real_path || ! is_string( $real_path ) ) {
					$this->file_path = '';
				} else {
					$real_path_normalized = wp_normalize_path( $real_path );
					$is_inside            = ( '' !== $content_dir && 0 === strpos( $real_path_normalized, $content_dir ) && ( strlen( $real_path_normalized ) === strlen( $content_dir ) || '/' === substr( $real_path_normalized, strlen( $content_dir ), 1 ) ) );
					if ( ! $is_inside ) {
						$this->file_path = '';
					} else {
						$this->file_path = $real_path;
					}
				}
			}
			$this->cache_dir        = $cache_dir;
			$cache_dir_normalized   = wp_normalize_path( $cache_dir );
			$content_dir_normalized = wp_normalize_path( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '' );
			$cache_inside           = ( '' !== $content_dir_normalized && 0 === strpos( $cache_dir_normalized, $content_dir_normalized ) && ( strlen( $cache_dir_normalized ) === strlen( $content_dir_normalized ) || '/' === substr( $cache_dir_normalized, strlen( $content_dir_normalized ), 1 ) ) );

			if ( ! $cache_inside ) {
				$this->cache_url = Util::cached_content_url( '/' );
			} else {
				$path            = str_replace( $content_dir_normalized, '', $cache_dir_normalized );
				$this->cache_url = Util::cached_content_url( $path );
			}
			$this->filesystem = Util::init_filesystem();
		}

		/**
		 * Minifies the JavaScript file and saves it to the cache directory.
		 * If the minified file exists, it returns its URL.
		 *
		 * @return string The URL of the minified JavaScript file or empty string if minification fails.
		 *
		 * @since 1.0.0
		 */
		public function minify() {
			// Audit #1434: readability pre-gate before WP_Filesystem boots (mirrors Minify\CSS).
			if ( empty( $this->file_path ) || ! is_readable( $this->file_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_readable -- Filesystem object not yet initialized at this pre-gate.
				return '';
			}
			$cache_file = $this->get_cache_file_path();
			$min_dir    = dirname( $cache_file );

			if ( ! $this->filesystem || ! Util::prepare_cache_dir( $min_dir ) ) {
				return '';
			}

			if ( ! $this->filesystem->exists( $cache_file ) ) {
				try {
					// Cap unbounded file-to-memory reads: skip minification above
					// the threshold (filterable, default 1MB) before buffering the
					// full file plus the Minify parse (audit #1469).
					/**
					 * Filters the maximum source size minified in one pass.
					 *
					 * @since NEXT
					 * @param int $bytes Maximum bytes. Default 1048576 (1MB).
					 */
					$max_bytes = function_exists( 'apply_filters' ) ? (int) apply_filters( 'wppo_minify_max_bytes', 1048576 ) : 1048576;
					if ( $max_bytes > 0 ) {
						$source_size = filesize( $this->file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- size probe before WP_Filesystem buffers the file.
						if ( false !== $source_size && $source_size > $max_bytes ) {
							return '';
						}
					}
					$js_content = $this->filesystem->get_contents( $this->file_path );
					if ( false === $js_content ) {
						return '';
					}
					$js_minifier = new Minify\JS( $js_content );
					$minified_js = $js_minifier->minify();

					$this->save_min_file( $minified_js, $cache_file );
				} catch ( \Throwable $e ) { // Audit #1362: catch \Error/\TypeError on PHP 8.
					return '';
				}
			}

			return $this->cache_url . '/' . basename( $cache_file );
		}

		/**
		 * Gets the cache file path for the minified JS.
		 *
		 * Public so callers (e.g. Main::minify_js) can access the
		 * on-disk path of the minified file after {@see Minify\JS::minify()}.
		 *
		 * @return string The full path to the cache file.
		 * @since 1.0.0
		 */
		public function get_cache_file_path(): string {
			$filename = md5( $this->file_path ) . '.js';
			return "{$this->cache_dir}/{$filename}";
		}

		/**
		 * Saves the minified JavaScript content to the specified file path,
		 * including a gzipped version of the file.
		 *
		 * @param string $js The minified JavaScript content.
		 * @param string $file_path The path where the file will be saved.
		 *
		 * @since 1.0.0
		 */
		private function save_min_file( $js, $file_path ) {
			$gzip_file_path = $file_path . '.gz';

			$this->filesystem->put_contents( $file_path, $js, FS_CHMOD_FILE );

			$gzip_output = gzencode( $js, 9 );
			if ( false !== $gzip_output ) {
				$this->filesystem->put_contents( $gzip_file_path, $gzip_output, FS_CHMOD_FILE );
			}
		}
	}
}
