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
		public function __construct( $file_path, $cache_dir ) {
			$real_path   = realpath( $file_path );
			$content_dir = wp_normalize_path( WP_CONTENT_DIR );
			if ( false === $real_path ) {
				$this->file_path = '';
			} else {
				$real_path_normalized = wp_normalize_path( $real_path );
				$is_inside            = ( 0 === strpos( $real_path_normalized, $content_dir ) && ( strlen( $real_path_normalized ) === strlen( $content_dir ) || '/' === substr( $real_path_normalized, strlen( $content_dir ), 1 ) ) );
				if ( ! $is_inside ) {
					$this->file_path = '';
				} else {
					$this->file_path = $real_path;
				}
			}
			$this->cache_dir        = $cache_dir;
			$cache_dir_normalized   = wp_normalize_path( $cache_dir );
			$content_dir_normalized = wp_normalize_path( WP_CONTENT_DIR );
			$cache_inside           = ( 0 === strpos( $cache_dir_normalized, $content_dir_normalized ) && ( strlen( $cache_dir_normalized ) === strlen( $content_dir_normalized ) || '/' === substr( $cache_dir_normalized, strlen( $content_dir_normalized ), 1 ) ) );
			if ( ! $cache_inside ) {
				$this->cache_url = content_url( '/' );
			} else {
				$this->cache_url = content_url( str_replace( $content_dir_normalized, '', $cache_dir_normalized ) );
			}
			$this->filesystem = Util::init_filesystem();
		}

		/**
		 * Minifies the JavaScript file and saves it to the cache directory.
		 * If the minified file exists, it returns its URL.
		 *
		 * @return string|null The URL of the minified JavaScript file or null if minification fails.
		 *
		 * @since 1.0.0
		 */
		public function minify() {
			$cache_file = $this->get_cache_file_path();
			$min_dir    = dirname( $cache_file );

			if ( ! $this->filesystem || ! Util::prepare_cache_dir( $min_dir ) ) {
				return;
			}

			if ( ! $this->filesystem->exists( $cache_file ) ) {
				try {
					$js_content = $this->filesystem->get_contents( $this->file_path );
					if ( false === $js_content ) {
						return null;
					}
					$js_minifier = new Minify\JS( $js_content );
					$minified_js = $js_minifier->minify();

					$this->save_min_file( $minified_js, $cache_file );
				} catch ( \Exception $e ) {
					return null;
				}
			}

			return $this->cache_url . '/' . basename( $cache_file );
		}

		/**
		 * Generates the cache file path based on the original file's MD5 hash.
		 *
		 * @return string The path to the cache file.
		 *
		 * @since 1.0.0
		 */
		private function get_cache_file_path(): string {
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
