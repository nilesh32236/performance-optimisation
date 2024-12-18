<?php

namespace PerformanceOptimise\Inc\Minify;

use MatthiasMullie\Minify;
use PerformanceOptimise\Inc\Img_Converter;
use PerformanceOptimise\Inc\Util;

class CSS {
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

		$min_dir = dirname( $cache_file );
		if ( ! $this->filesystem || ! Util::prepare_cache_dir( $min_dir ) ) {
			return;
		}

		if ( ! $this->filesystem->exists( $cache_file ) ) {
			try {
				$css_content  = $this->filesystem->get_contents( $this->file_path );
				$css_content  = self::update_image_paths( $css_content, $this->file_path );
				$css_minifier = new Minify\CSS( $css_content );
				$minified_css = $css_minifier->minify();

				if ( $minified_css === $css_content ) {
					return;
				}

				$this->save_min_file( $minified_css, $cache_file );
			} catch ( \Exception $e ) {
				return null;
			}
		}

		return content_url( 'cache/wppo/min/css/' . basename( $cache_file ) );
	}

	/**
	 * Get the cache file path for the minified CSS.
	 *
	 * @return string The full path to the cache file.
	 */
	private function get_cache_file_path(): string {
		$filename = md5( $this->file_path ) . '.css';
		return "{$this->cache_dir}/{$filename}";
	}

	private function save_min_file( $css, $file_path ) {
		$gzip_file_path = $file_path . '.gz';

		$this->filesystem->put_contents( $file_path, $css, FS_CHMOD_FILE );

		$gzip_output = gzencode( $css, 9 );
		$this->filesystem->put_contents( $gzip_file_path, $gzip_output, FS_CHMOD_FILE );
	}

	public static function update_image_paths( $css_content, $file_path ) {
		$pattern     = '/url\((\'|\"|)(.*?)(\'|\"|)\)/';
		$css_dir_url = content_url( str_replace( WP_CONTENT_DIR, '', dirname( $file_path ) ) );

		return preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $css_dir_url ) {
				$image_path = trim( $matches[2] );

				if ( preg_match( '/\.(jpg|jpeg|png|gif|webp)$/i', $image_path, $ext_matches ) ) {
					if ( false === strpos( $image_path, 'http' ) && ! preg_match( '/^data:/', $image_path ) ) {
						$image_path = $css_dir_url . '/' . ltrim( $image_path, '/' );
					}

					$local_path = Util::get_local_path( $image_path );

					// Check if corresponding .avif image exists
					if ( file_exists( Img_Converter::get_img_path( $image_path, 'avif' ) ) ) {
						return 'url("' . Img_Converter::get_img_url( $image_path, 'avif' ) . '")';
					} else {
						Img_Converter::add_img_into_queue( $local_path, 'avif' );
					}

					if ( 'webp' === $ext_matches[1] ) {
						return 'url("' . $image_path . '")';
					}

					// Check if corresponding .webp image exists
					if ( file_exists( Img_Converter::get_img_path( $image_path ) ) ) {
						return 'url("' . Img_Converter::get_img_url( $image_path ) . '")';
					} else {
						Img_Converter::add_img_into_queue( $local_path, 'webp' );
					}

					return 'url("' . $image_path . '")';
				}

				if ( false === strpos( $image_path, 'http' ) && ! preg_match( '/^data:/', $image_path ) ) {
					$image_path = $css_dir_url . '/' . ltrim( $image_path, '/' );
					return 'url("' . $image_path . '")';
				}

				return $matches[0];
			},
			$css_content
		);
	}
}
