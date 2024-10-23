<?php

namespace PerformanceOptimise\Inc;

use PerformanceOptimise\Inc\Minify;

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Cache' ) ) {
	class Cache {


		private const CACHE_DIR = '/cache/qtpo';

		private string $domain;
		private string $cache_root_dir;
		private string $cache_root_url;
		private string $url_path;
		private string $extracted_css;
		private $filesystem;
		private $options;
		public function __construct() {
			$this->domain         = sanitize_text_field( $_SERVER['HTTP_HOST'] );
			$this->cache_root_dir = WP_CONTENT_DIR . self::CACHE_DIR;
			$this->cache_root_url = WP_CONTENT_URL . self::CACHE_DIR;
			$this->url_path       = trim( wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
			$this->filesystem     = Util::init_filesystem();
			$this->options        = get_option( 'qtpo_settings', array() );
		}

		/**
		 * Generate dynamic static HTML.
		 *
		 * Creates a static HTML version of the page if not logged in and not a 404 page.
		 *
		 * @return void
		 */
		public function generate_dynamic_static_html(): void {
			if ( is_user_logged_in() || $this->is_not_cacheable() ) {
				return;
			}

			$file_path = $this->get_cache_file_path();

			if ( ! $this->filesystem || ! $this->prepare_cache_dir() ) {
				return;
			}

			try {
				ob_start(
					function ( $buffer ) use ( $file_path ) {
						return $this->process_buffer( $buffer, $file_path );
					}
				);

			} catch ( \Exception $e ) {
				error_log( 'Error generating static HTML: ' . $e->getMessage() );
			}
		}

		/**
		 * Process the buffer by minifying it and saving cache files.
		 *
		 * @param string $buffer
		 * @param string $file_path
		 * @return string
		 */
		private function process_buffer( $buffer, $file_path ) {
			$is_extract_css = isset( $this->options['file_optimisation']['extractInlineCSS'] ) && (bool) $this->options['file_optimisation']['extractInlineCSS'];
			$buffer         = $this->serve_webp_images( $buffer );

			if ( $is_extract_css ) {
				$buffer = $this->add_extracted_css_url( $buffer );
			}

			if ( isset( $this->options['file_optimisation']['minifyHTML'] ) && (bool) $this->options['file_optimisation']['minifyHTML'] ) {
				$buffer = $this->minify_buffer( $buffer );
			}

			if ( $is_extract_css ) {
				$this->save_extracted_css();
			}

			$this->save_cache_files( $buffer, $file_path );

			return $buffer;
		}

		private function serve_webp_images( $buffer ) {
			// Check if the browser supports WebP
			if ( strpos( $_SERVER['HTTP_ACCEPT'], 'image/webp' ) === false ) {
				return $buffer;
			}

			// Replace all image src URLs with their WebP equivalents
			return preg_replace_callback(
				'#<img\b[^>]*src=["\']([^"\']+)["\'][^>]*>#i',
				function ( $matches ) {
					$img_url       = $matches[1];
					$img_extension = pathinfo( $img_url, PATHINFO_EXTENSION );

					// Skip if the image is already a WebP
					if ( strtolower( $img_extension ) === 'webp' ) {
						return $matches[0]; // Return the original image tag
					}

					$webp_converter  = new WebP_Converter();
					$webp_img_url    = str_replace( $img_extension, 'webp', $img_url );
					$webp_image_path = Util::get_local_path( $webp_converter->get_webp_path( $img_url ) );

					if ( ! file_exists( $webp_image_path ) ) {
						$source_image_path = Util::get_local_path( $img_url );

						if ( file_exists( $source_image_path ) ) {
							$webp_converter->convert_to_webp( $source_image_path, $webp_image_path );
						}
					}

					if ( file_exists( $webp_image_path ) ) {
						// Replace the original src with the WebP version
						return str_replace( $img_url, $webp_img_url, $matches[0] );
					}

					// Return the original image tag if WebP conversion fails
					return $matches[0];
				},
				$buffer
			);
		}

		/**
		 * Minify the output buffer.
		 *
		 * @param string $buffer
		 * @return string
		 */
		private function minify_buffer( $buffer ) {
			$minifier = new Minify\HTML( $buffer, $this->options );
			$buffer   = $minifier->get_minified_html();

			$this->extracted_css = $minifier->get_extracted_css();
			return $buffer;
		}

		/**
		 * Check if the page is not cacheable.
		 *
		 * @return bool
		 */
		private function is_not_cacheable(): bool {
			$path_info = pathinfo( trim( wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' ), PATHINFO_EXTENSION );
			return is_404() || ! empty( $path_info );
		}

		/**
		 * Get the cache file path based on the URL path.
		 *
		 * @return string
		 */
		private function get_cache_file_path( $type = 'html' ): string {
			return "{$this->cache_root_dir}/{$this->domain}/" . ( '' === $this->url_path ? "index.{$type}" : "{$this->url_path}/index.{$type}" );
		}

		private function get_cache_file_url( $type = 'html' ): string {
			return "{$this->cache_root_url}/{$this->domain}/" . ( '' === $this->url_path ? "index.{$type}" : "{$this->url_path}/index.{$type}" );
		}
		/**
		 * Prepare the cache directory for storing files.
		 *
		 * @return bool
		 */
		private function prepare_cache_dir(): bool {
			return Util::prepare_cache_dir( "{$this->cache_root_dir}/{$this->domain}/" . ( '' === $this->url_path ? '' : "/{$this->url_path}" ) );
		}

		/**
		 * Save cache files.
		 *
		 * Writes the minified HTML content to both regular and gzip compressed files.
		 *
		 * @param string $buffer         The minified HTML content.
		 * @param string $file_path      Path to save the regular HTML file.
		 * @return void
		 */
		private function save_cache_files( $buffer, $file_path, $type = 'html' ): void {

			if ( ! $this->maybe_store_cache() && 'html' === $type ) {
				return;
			}

			$gzip_file_path = $file_path . '.gz';

			if ( ! $this->filesystem->put_contents( $file_path, $buffer, FS_CHMOD_FILE ) ) {
				error_log( 'Error writing static HTML file.' );
			}

			$gzip_output = gzencode( $buffer, 9 );
			if ( ! $this->filesystem->put_contents( $gzip_file_path, $gzip_output, FS_CHMOD_FILE ) ) {
				error_log( 'Error writing gzipped static HTML file.' );
			}
		}

		private function save_extracted_css() {
			if ( ! empty( $this->extracted_css ) ) {
				$file_path = $this->get_cache_file_path( 'css' );
				$this->save_cache_files( $this->extracted_css, $file_path, 'css' );
			}
		}

		private function add_extracted_css_url( $html ) {
			$css_url          = $this->get_cache_file_url( 'css' );
			$minified_css_tag = '<link rel="stylesheet" href="' . $css_url . '">';
			$html             = preg_replace( '#</head>#is', $minified_css_tag . '</head>', $html );

			return $html;
		}

		private function maybe_store_cache() {
			if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
				return false;
			}

			if ( isset( $this->options['preload_settings']['enablePreloadCache'] ) && (bool) $this->options['preload_settings']['enablePreloadCache'] ) {
				if ( isset( $this->options['preload_settings']['excludePreloadCache'] ) && ! empty( $this->options['preload_settings']['excludePreloadCache'] ) ) {
					$exclude_urls = explode( "\n", $this->options['preload_settings']['excludePreloadCache'] );
					$exclude_urls = array_map( 'trim', $exclude_urls );
					$exclude_urls = array_filter( $exclude_urls );

					$exclude_urls = array_map( 'home_url', $exclude_urls );

					$current_url = home_url( $_SERVER['REQUEST_URI'] );
					$current_url = rtrim( $current_url, '/' );

					foreach ( $exclude_urls as $exclude_url ) {
						$exclude_url = rtrim( $exclude_url, '/' );

						if ( false !== strpos( $exclude_url, '(.*)' ) ) {
							$$exclude_prefix = str_replace( '(.*)', '', $exclude_url );

							if ( 0 === strpos( $current_url, $exclude_prefix ) ) {
								return false;
							}
						}

						if ( $current_url === $exclude_url ) {
							return false;
						}
					}
				}

				return true;
			}

			return false;
		}

		/**
		 * Invalidate dynamic static HTML.
		 *
		 * Deletes the cached HTML files when a page is saved and schedules regeneration.
		 *
		 * @param int $page_id The ID of the page being saved.
		 * @return void
		 */
		public function invalidate_dynamic_static_html( $page_id ): void {
			$file_path = $this->get_file_path( $page_id );
			$this->delete_cache_files( $file_path );

			if ( ! wp_next_scheduled( 'qtpo_generate_static_page', array( $page_id ) ) ) {
				wp_schedule_single_event( time() + rand( 0, 5 ), 'qtpo_generate_static_page', array( $page_id ) );
			}
		}

		/**
		 * Get the file path for a page.
		 *
		 * @param int|null $page_id
		 * @return string
		 */
		private function get_file_path( int $page_id = null ): string {
			$url_path = trim( wp_parse_url( get_permalink( $page_id ), PHP_URL_PATH ), '/' );
			return "{$this->cache_root_dir}/{$this->domain}/" . ( '' === $url_path ? 'index.html' : "{$url_path}/index.html" );
		}

		/**
		 * Delete cache files.
		 *
		 * Removes the cached HTML and gzip files for a specific page.
		 *
		 * @param string $file_path      Path to the regular HTML file.
		 * @param string $gzip_file_path Path to the gzip compressed HTML file.
		 * @return void
		 */
		private function delete_cache_files( $file_path ): void {
			$gzip_file_path = $file_path . '.gz';
			if ( $this->filesystem ) {
				$this->filesystem->delete( $file_path );
				$this->filesystem->delete( $gzip_file_path );
			}
		}

		/**
		 * Clear the cache for a specific page or all pages.
		 *
		 * @param int|null $page_id
		 * @return void
		 */
		public static function clear_cache( $page_id = null ) {
			$instance = new self();
			if ( $page_id ) {
				$file_path = $instance->get_file_path( $page_id );
				$instance->delete_cache_files( $file_path );
			} else {
				$instance->delete_all_cache_files();
			}
		}

		/**
		 * Delete all cache files.
		 *
		 * @return void
		 */
		private function delete_all_cache_files() {
			$cache_dir = "{$this->cache_root_dir}/{$this->domain}";

			if ( $this->filesystem && $this->filesystem->is_dir( $cache_dir ) ) {
				$this->filesystem->delete( $cache_dir, true ); // 'true' ensures recursive deletion
			}

			$min_dir = "{$this->cache_root_dir}/min";

			if ( $this->filesystem && $this->filesystem->is_dir( $min_dir ) ) {
				$this->filesystem->delete( $min_dir, true ); // 'true' ensures recursive deletion
			}
		}

		/**
		 * Get the size of the cache.
		 *
		 * @return string
		 */
		public static function get_cache_size() {
			$instance = new self();

			if ( ! $instance->filesystem ) {
				return 'Unable to initialize filesystem.';
			}

			$cache_dir = "{$instance->cache_root_dir}/{$instance->domain}";

			if ( ! $instance->filesystem->is_dir( $cache_dir ) ) {
				return 'Cache directory does not exist.';
			}

			$total_size = $instance->calculate_directory_size( $cache_dir );
			return size_format( $total_size );
		}

		/**
		 * Calculate the size of a directory.
		 *
		 * @param string $directory
		 * @return int
		 */
		private function calculate_directory_size( string $directory ): int {
			$total_size = 0;
			$files      = $this->filesystem->dirlist( $directory );

			if ( ! $files ) {
				return $total_size;
			}

			foreach ( $files as $file ) {
				$file_path   = trailingslashit( $directory ) . $file['name'];
				$total_size += ( 'd' === $file['type'] )
					? $this->calculate_directory_size( $file_path )
					: $this->filesystem->size( $file_path );
			}

			return $total_size;
		}
	}
}
