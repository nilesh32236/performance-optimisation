<?php

namespace PerformanceOptimise\Inc\Minify;

use MatthiasMullie\Minify;
use PerformanceOptimise\Inc\Util;

class JS {
	private string $file_path;
	private string $cache_dir;
	private $filesystem;

	public function __construct( $file_path, $cache_dir ) {
		$this->file_path  = $file_path;
		$this->cache_dir  = $cache_dir;
		$this->filesystem = Util::init_filesystem();
	}

	public function minify() {
		$cache_file = $this->get_cache_file_path();
		$min_dir    = dirname( $cache_file );

		if ( ! $this->filesystem || ! Util::prepare_cache_dir( $min_dir ) ) {
			return;
		}

		if ( ! $this->filesystem->exists( $cache_file ) ) {
			try {
				$js_content  = $this->filesystem->get_contents( $this->file_path );
				$js_minifier = new Minify\JS( $js_content );
				$minified_js = $js_minifier->minify();

				if ( $minified_js === $js_content ) {
					return;
				}

				$this->save_min_file( $minified_js, $cache_file );
			} catch ( \Exception $e ) {
				return null;
			}
		}

		return content_url( 'cache/wppo/min/js/' . basename( $cache_file ) );
	}

	/**
	 * Get the cache file path for the minified JS.
	 *
	 * @return string The full path to the cache file.
	 */
	private function get_cache_file_path(): string {
		$filename = md5( $this->file_path ) . '.js';
		return "{$this->cache_dir}/{$filename}";
	}

	private function save_min_file( $js, $file_path ) {
		$gzip_file_path = $file_path . '.gz';

		$this->filesystem->put_contents( $file_path, $js, FS_CHMOD_FILE );

		$gzip_output = gzencode( $js, 9 );
		$this->filesystem->put_contents( $gzip_file_path, $gzip_output, FS_CHMOD_FILE );
	}
}
