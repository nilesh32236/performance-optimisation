<?php
/**
 * Minify CSS class for the PerformanceOptimise plugin.
 *
 * This class is responsible for minifying CSS files, caching them, and updating
 * image URLs within the CSS to point to the appropriate optimized versions.
 * It uses the MatthiasMullie\Minify library to minify the CSS content and handles
 * the conversion of image formats to WebP and AVIF where applicable.
 *
 * @package PerformanceOptimise\Inc\Minify
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc\Minify;

use MatthiasMullie\Minify;
use PerformanceOptimise\Inc\Img_Converter;
use PerformanceOptimise\Inc\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSS Minification and Image Optimization.
 *
 * @since 1.0.0
 */
class CSS {
	/**
	 * Path to the original CSS file.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $file_path;

	/**
	 * Directory for caching minified files.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $cache_dir;

	/**
	 * Filesystem handler instance.
	 *
	 * @since 1.0.0
	 * @var \WP_Filesystem_Base|null
	 */
	private ?\WP_Filesystem_Base $filesystem;

	/**
	 * Constructor for the CSS class.
	 *
	 * @since 1.0.0
	 * @param string $file_path  Path to the CSS file to be minified.
	 * @param string $cache_dir  Directory where the minified file will be cached.
	 */
	public function __construct( string $file_path, string $cache_dir ) {
		$this->file_path  = $file_path;
		$this->cache_dir  = $cache_dir;
		$this->filesystem = Util::init_filesystem();
	}

	/**
	 * Minifies the CSS file and stores it in the cache directory.
	 *
	 * @since 1.0.0
	 * @return string|null The URL of the minified CSS file, or null on failure.
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
				$css_content = $this->filesystem->get_contents( $this->file_path );
				if ( false === $css_content ) {
					return null;
				}

				$css_content  = self::update_image_paths( $css_content, $this->file_path );
				$css_minifier = new Minify\CSS( $css_content );
				$minified_css = $css_minifier->minify();

				if ( empty( trim( $minified_css ) ) || $minified_css === $css_content ) {
					return null;
				}

				$this->save_min_file( $minified_css, $cache_file );
			} catch ( \Exception $e ) {
				return null;
			}
		}

		if ( $this->filesystem->exists( $cache_file ) ) {
			return content_url( trailingslashit( str_replace( wp_normalize_path( WP_CONTENT_DIR ), '', $this->cache_dir ) ) . basename( $cache_file ) );
		}

		return null;
	}

	/**
	 * Gets the cache file path for the minified CSS.
	 *
	 * @since 1.0.0
	 * @return string The full path to the cache file.
	 */
	private function get_cache_file_path(): string {
		// Use filemtime for more robust cache busting if content changes but path doesn't.
		$file_hash = md5( $this->file_path . ( file_exists( $this->file_path ) ? (string) filemtime( $this->file_path ) : '' ) );
		$filename  = $file_hash . '.css';
		return wp_normalize_path( trailingslashit( $this->cache_dir ) . $filename );
	}

	/**
	 * Saves the minified CSS and its gzip version to the cache.
	 *
	 * @since 1.0.0
	 * @param string $css       The minified CSS content.
	 * @param string $file_path The file path to save the minified CSS.
	 */
	private function save_min_file( string $css, string $file_path ): void {
		if ( ! $this->filesystem ) {
			return;
		}

		$this->filesystem->put_contents( $file_path, $css, FS_CHMOD_FILE );

		if ( function_exists( 'gzencode' ) ) {
			$gzip_output = gzencode( $css, 9 );
			if ( false !== $gzip_output ) {
				$this->filesystem->put_contents( $file_path . '.gz', $gzip_output, FS_CHMOD_FILE );
			}
		}
	}

	/**
	 * Updates image paths in the CSS content.
	 * Converts relative URLs to absolute and attempts to use optimized image formats.
	 *
	 * @since 1.0.0
	 * @param string $css_content The CSS content to modify.
	 * @param string $css_file_path The file path of the original CSS file.
	 * @return string The updated CSS content with modified image paths.
	 */
	public static function update_image_paths( string $css_content, string $css_file_path ): string {
		$normalized_css_file_path = wp_normalize_path( $css_file_path );
		$css_dir_relative         = str_replace( wp_normalize_path( WP_CONTENT_DIR ), '', dirname( $normalized_css_file_path ) );
		$css_dir_url              = content_url( $css_dir_relative );

		return preg_replace_callback(
			'/url\s*\(\s*([\'"]?)(?!data:)(.*?)\1\s*\)/i',
			function ( $matches ) use ( $css_dir_url, $normalized_css_file_path ) {
				$original_image_path = trim( $matches[2] );

				if ( empty( $original_image_path ) || preg_match( '/^(?:https?:)?\/\//i', $original_image_path ) ) {
					$local_path = Util::get_local_path( $original_image_path );
					if ( file_exists( $local_path ) ) {
						$avif_converted_path = Img_Converter::get_img_path( $local_path, 'avif' );
						if ( file_exists( $avif_converted_path ) ) {
							return 'url("' . esc_url( Img_Converter::get_img_url( $original_image_path, 'avif' ) ) . '")';
						} else {
							Img_Converter::add_img_into_queue( $local_path, 'avif' );
						}

						$webp_converted_path = Img_Converter::get_img_path( $local_path, 'webp' );
						if ( file_exists( $webp_converted_path ) ) {
							return 'url("' . esc_url( Img_Converter::get_img_url( $original_image_path, 'webp' ) ) . '")';
						} else {
							Img_Converter::add_img_into_queue( $local_path, 'webp' );
						}
					}
					return $matches[0];
				}

				if ( '/' === $original_image_path[0] ) {
					$absolute_image_url = home_url( $original_image_path );
				} else {
					$absolute_image_url = $css_dir_url . '/' . ltrim( $original_image_path, '/' );
					$absolute_image_url = preg_replace( '/\/\.\//', '/', $absolute_image_url );
					while ( preg_match( '/\/[^\/]+\/\.\.\//', $absolute_image_url ) ) {
						$absolute_image_url = preg_replace( '/\/[^\/]+\/\.\.\//', '/', $absolute_image_url );
					}
				}
				$absolute_image_url = esc_url( $absolute_image_url );
				$local_image_path   = Util::get_local_path( $absolute_image_url );

				if ( ! file_exists( $local_image_path ) || ! preg_match( '/\.(jpg|jpeg|png|gif|svg)$/i', $original_image_path ) ) {
					return 'url("' . $absolute_image_url . '")';
				}

				$avif_path = Img_Converter::get_img_path( $local_image_path, 'avif' );
				if ( file_exists( $avif_path ) ) {
					return 'url("' . esc_url( Img_Converter::get_img_url( $absolute_image_url, 'avif' ) ) . '")';
				} else {
					Img_Converter::add_img_into_queue( $local_image_path, 'avif' );
				}

				if ( ! preg_match( '/\.webp$/i', $original_image_path ) ) {
					$webp_path = Img_Converter::get_img_path( $local_image_path, 'webp' );
					if ( file_exists( $webp_path ) ) {
						return 'url("' . esc_url( Img_Converter::get_img_url( $absolute_image_url, 'webp' ) ) . '")';
					} else {
						Img_Converter::add_img_into_queue( $local_image_path, 'webp' );
					}
				}

				return 'url("' . $absolute_image_url . '")';
			},
			$css_content
		);
	}
}
