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

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
	 * @var \WP_Filesystem_Base|null $filesystem The object responsible for file read/write operations.
	 * @since 1.0.0
	 */
	private ?\WP_Filesystem_Base $filesystem;

	/**
	 * JS constructor to initialize file path, cache directory, and filesystem.
	 *
	 * @param string $file_path The path to the JavaScript file to minify.
	 * @param string $cache_dir The directory to store the minified file.
	 *
	 * @since 1.0.0
	 */
	public function __construct( string $file_path, string $cache_dir ) {
		$this->file_path  = $file_path;
		$this->cache_dir  = $cache_dir;
		$this->filesystem = Util::init_filesystem();
	}

	/**
	 * Minifies the JavaScript file and saves it to the cache directory.
	 * If the minified file exists and is up-to-date, it returns its URL.
	 *
	 * @return string|null The URL of the minified JavaScript file or null if minification fails or is not needed.
	 *
	 * @since 1.0.0
	 */
	public function minify(): ?string {
		if ( ! $this->filesystem ) {
			return null;
		}

		$cache_file = $this->get_cache_file_path();
		$min_dir    = dirname( $cache_file );

		if ( ! Util::prepare_cache_dir( $min_dir ) ) {
			return null;
		}

		if ( ! $this->filesystem->exists( $cache_file ) || $this->filesystem->mtime( $cache_file ) < $this->filesystem->mtime( $this->file_path ) ) {
			try {
				if ( ! $this->filesystem->is_readable( $this->file_path ) ) {
					return null;
				}
				$js_content = $this->filesystem->get_contents( $this->file_path );
				if ( false === $js_content ) {
					return null;
				}

				$js_minifier = new Minify\JS( $js_content );
				$minified_js = $js_minifier->minify();

				if ( empty( trim( $minified_js ) ) || $minified_js === $js_content ) {
					return null;
				}

				$this->save_min_file( $minified_js, $cache_file );
			} catch ( \Exception $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'JS Minification Error: ' . $e->getMessage() );
				}
				return null;
			}
		}

		if ( $this->filesystem->exists( $cache_file ) ) {
			return content_url( trailingslashit( str_replace( wp_normalize_path( WP_CONTENT_DIR ), '', $this->cache_dir ) ) . basename( $cache_file ) );
		}
		return null;
	}

	/**
	 * Generates the cache file path based on the original file's MD5 hash and modification time.
	 *
	 * @return string The path to the cache file.
	 *
	 * @since 1.0.0
	 */
	private function get_cache_file_path(): string {
		// Include file modification time in hash for cache busting when file content changes.
		$file_hash = md5( $this->file_path . ( file_exists( $this->file_path ) ? (string) filemtime( $this->file_path ) : '' ) );
		$filename  = $file_hash . '.js';
		return wp_normalize_path( trailingslashit( $this->cache_dir ) . $filename );
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
	private function save_min_file( string $js, string $file_path ): void {
		if ( ! $this->filesystem ) {
			return;
		}

		$this->filesystem->put_contents( $file_path, $js, FS_CHMOD_FILE );

		if ( function_exists( 'gzencode' ) ) {
			$gzip_output = gzencode( $js, 9 );
			if ( false !== $gzip_output ) {
				$this->filesystem->put_contents( $file_path . '.gz', $gzip_output, FS_CHMOD_FILE );
			}
		}
	}
}
