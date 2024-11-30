<?php

namespace PerformanceOptimise\Inc;

use PerformanceOptimise\Inc\Minify;
use PerformanceOptimise\Inc\Minify\CSS;
use MatthiasMullie\Minify\CSS as CSSMinifier;

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

		public function combine_css() {
			if ( is_user_logged_in() ) {
				return;
			}

			global $wp_styles;
			$styles = $wp_styles->queue;

			if ( empty( $styles ) ) {
				return;
			}

			$exclude_combine_css = array();
			if ( isset( $this->options['file_optimisation']['excludeCombineCSS'] ) && ! empty( $this->options['file_optimisation']['excludeCombineCSS'] ) ) {
				$exclude_combine_css = Util::process_urls( $this->options['file_optimisation']['excludeCombineCSS'] );
			}

			$combined_css = '';

			foreach ( $styles as $handle ) {
				$style_data = $wp_styles->registered[ $handle ];

				if ( ! empty( $exclude_combine_css ) ) {
					if ( in_array( $handle, $exclude_combine_css, true ) ) {
						continue;
					}

					$should_exclude = false;
					foreach ( $exclude_combine_css as $exclude_css ) {
						if ( false !== strpos( $style_data->src, $exclude_css ) ) {
							$should_exclude = true;
						}
					}

					if ( $should_exclude ) {
						continue;
					}
				}

				if ( ! isset( $style_data->args ) || 'all' !== $style_data->args ) {
					continue;
				}

				$src = $wp_styles->registered[ $handle ]->src;

				$css_content = $this->fetch_remote_css( $src );

				if ( false === $css_content ) {
					continue;
				}

				if ( ! empty( $style_data->extra['before'] ) ) {
					$combined_css .= implode( "\n", $style_data->extra['before'] ) . "\n";
				}

				if ( ! empty( $css_content ) ) {
					$combined_css .= $css_content . "\n";
				}

				if ( ! empty( $style_data->extra['after'] ) ) {
					$combined_css .= implode( "\n", $style_data->extra['after'] ) . "\n";
				}

				wp_dequeue_style( $handle ); // Remove individual style
			}

			if ( ! empty( $combined_css ) ) {
				$combined_css = preg_replace( '/font-display\s*:\s*block\s*;?/', 'font-display: swap;', $combined_css );

				$combined_css = preg_replace_callback(
					'/@font-face\s*{[^}]*}/',
					function ( $matches ) {
						$font_face = $matches[0];
						if ( strpos( $font_face, 'font-display' ) === false ) {
							// Add 'font-display: swap;' if it's not already there
							$font_face = preg_replace( '/(})$/', 'font-display: swap;$1', $font_face );
						}
						return $font_face;
					},
					$combined_css
				);

				$css_minifier  = new CSSMinifier( $combined_css );
				$combined_css  = $css_minifier->minify();
				$css_file_path = $this->get_cache_file_path( 'css' );

				$this->save_cache_files( $combined_css, $css_file_path, 'css' );

				$css_url = $this->get_cache_file_url( 'css' );

				$version = fileatime( $css_file_path );
				wp_enqueue_style( 'qtpo-combine-css', $css_url, array(), $version, 'all' );

				$css_url_with_version = $css_url . "?ver=$version";
				echo '<link rel="preload" as="style" href="' . esc_url( $css_url_with_version ) . '">';
			}
		}

		private function fetch_remote_css( $url ) {
			if ( empty( $url ) ) {
				return '';
			}

			$css_file = Util::get_local_path( $url );
			if ( $this->filesystem ) {
				$css_content = $this->filesystem->get_contents( $css_file );

				if ( false !== $css_content ) {
					$css_content = CSS::update_image_paths( $css_content, $css_file );
					return $css_content;
				}
			}

			return false;
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

			$image_optimisation = new Image_Optimisation( $this->options );

			$buffer = $image_optimisation->maybe_serve_next_gen_images( $buffer );
			$buffer = $image_optimisation->add_delay_load_img( $buffer );

			if ( isset( $this->options['file_optimisation']['minifyHTML'] ) && (bool) $this->options['file_optimisation']['minifyHTML'] ) {
				$buffer = $this->minify_buffer( $buffer );
			}

			$this->save_cache_files( $buffer, $file_path );

			return $buffer;
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

		public function get_cache_file_url( $type = 'html' ): string {
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

			$this->prepare_cache_dir();
			$gzip_file_path = $file_path . '.gz';

			if ( ! $this->filesystem->put_contents( $file_path, $buffer, FS_CHMOD_FILE ) ) {
				error_log( 'Error writing static ' . $type . ' file.' );
			}

			$gzip_output = gzencode( $buffer, 9 );
			if ( ! $this->filesystem->put_contents( $gzip_file_path, $gzip_output, FS_CHMOD_FILE ) ) {
				error_log( 'Error writing gzipped static ' . $type . ' file.' );
			}
		}

		private function maybe_store_cache() {
			if ( ! empty( $_SERVER['QUERY_STRING'] ) &&
				preg_match( '/(?:^|&)(s|ver)(?:=|&|$)/', $_SERVER['QUERY_STRING'] )
			) {
                error_log( 'return false' );
				return false;
			}

			if ( isset( $this->options['preload_settings']['enablePreloadCache'] ) && (bool) $this->options['preload_settings']['enablePreloadCache'] ) {
				if ( isset( $this->options['preload_settings']['excludePreloadCache'] ) && ! empty( $this->options['preload_settings']['excludePreloadCache'] ) ) {
					$exclude_urls = Util::process_urls( $this->options['preload_settings']['excludePreloadCache'] );

					$current_url = home_url( str_replace( wp_parse_url( home_url(), PHP_URL_PATH ) ?? '', '', $_SERVER['REQUEST_URI'] ) );
					$current_url = rtrim( $current_url, '/' );

					foreach ( $exclude_urls as $exclude_url ) {
						$exclude_url = rtrim( $exclude_url, '/' );

						if ( 0 !== strpos( $exclude_url, 'http' ) ) {
							$exclude_url = home_url( $exclude_url );
						}

						if ( false !== strpos( $exclude_url, '(.*)' ) ) {
							$exclude_prefix = str_replace( '(.*)', '', $exclude_url );

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
			$html_file_path = $this->get_file_path( $page_id, 'html' );
			$css_file_path  = $this->get_file_path( $page_id, 'css' );
			$this->delete_cache_files( $html_file_path );
			$this->delete_cache_files( $css_file_path );

			if ( ! wp_next_scheduled( 'qtpo_generate_static_page', array( $page_id ) ) ) {
				wp_schedule_single_event( time() + rand( 0, 5 ), 'qtpo_generate_static_page', array( $page_id ) );
			}
		}

		/**
		 * Get the file path for a page.
		 *
		 * @param string|null $url_path
		 * @return string
		 */
		private function get_file_path( string $url_path = null, string $type = 'html' ): string {
			$url_path = trim( $url_path, '/' );
			return "{$this->cache_root_dir}/{$this->domain}/" . ( '' === $url_path ? "index.{$type}" : "{$url_path}/index.{$type}" );
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
		 * @param string|null $url_path
		 * @return void
		 */
		public static function clear_cache( $url_path = null ) {
			$instance = new self();
			if ( $url_path ) {
				$html_file_path = $instance->get_file_path( $url_path, 'html' );
				$css_file_path  = $instance->get_file_path( $url_path, 'css' );
				$instance->delete_cache_files( $html_file_path );
				$instance->delete_cache_files( $css_file_path );
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
