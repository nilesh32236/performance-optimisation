<?php
/**
 * Cache class for handling caching functionalities in PerformanceOptimise plugin.
 *
 * This class is responsible for caching tasks such as combining CSS files,
 * generating static HTML files, and managing cache files in the WordPress
 * content directory. It also provides mechanisms to clear the cache and
 * retrieve cached files when necessary.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc;

use PerformanceOptimise\Inc\Minify;
use PerformanceOptimise\Inc\Minify\CSS;
use MatthiasMullie\Minify\CSS as CSSMinifier;

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Cache' ) ) {
	/**
	 * Class Cache
	 *
	 * Handles caching functionalities such as combining CSS and generating static HTML.
	 *
	 * @since 1.0.0
	 */
	class Cache {
		/**
		 * The directory where cache files are stored.
		 *
		 * @var string
		 * @since 1.0.0
		 */
		private const CACHE_DIR = '/cache/wppo';

		/**
		 * The domain name of the site.
		 *
		 * @var string
		 * @since 1.0.0
		 */
		private string $domain;

		/**
		 * The root directory for cache files.
		 *
		 * @var string
		 * @since 1.0.0
		 */
		private string $cache_root_dir;

		/**
		 * The URL to access cache files.
		 *
		 * @var string
		 * @since 1.0.0
		 */
		private string $cache_root_url;

		/**
		 * The URL path for the current request.
		 *
		 * @var string
		 * @since 1.0.0
		 */
		private string $url_path;

		/**
		 * The filesystem object used for file operations.
		 *
		 * @var object
		 * @since 1.0.0
		 */
		private $filesystem;

		/**
		 * The options/settings for the cache system.
		 *
		 * @var array
		 * @since 1.0.0
		 */
		private $options;

		/**
		 * Constructor to initialize cache settings and configurations.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			$this->domain = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';

			// Define cache root directory and URL.
			$this->cache_root_dir = WP_CONTENT_DIR . self::CACHE_DIR;
			$this->cache_root_url = WP_CONTENT_URL . self::CACHE_DIR;

			$request_uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$this->url_path = trim( wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );

			// Initialize filesystem and options.
			$this->filesystem = Util::init_filesystem();
			$this->options    = get_option( 'wppo_settings', array() );
		}

		/**
		 * Combines all enqueued CSS files into a single file.
		 *
		 * @since 1.0.0
		 */
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

				wp_dequeue_style( $handle ); // Remove individual style.
			}

			if ( ! empty( $combined_css ) ) {
				$combined_css = preg_replace( '/font-display\s*:\s*block\s*;?/', 'font-display: swap;', $combined_css );

				$combined_css = preg_replace_callback(
					'/@font-face\s*{[^}]*}/',
					function ( $matches ) {
						$font_face = $matches[0];
						if ( strpos( $font_face, 'font-display' ) === false ) {
							// Add 'font-display: swap;' if it's not already there.
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
				wp_enqueue_style( 'wppo-combine-css', $css_url, array(), $version, 'all' );

				$css_url_with_version = $css_url . "?ver=$version";
				echo '<link rel="preload" as="style" href="' . esc_url( $css_url_with_version ) . '">';
			}
		}

		/**
		 * Fetches CSS content from a remote URL or local path.
		 *
		 * @param string $url The URL of the CSS file.
		 * @return string The CSS content or an empty string if fetching fails.
		 *
		 * @since 1.0.0
		 */
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
		 *
		 * @since 1.0.0
		 */
		public function generate_dynamic_static_html(): void {
			if ( is_user_logged_in() || $this->is_not_cacheable() ) {
				return;
			}

			$file_path = $this->get_cache_file_path();

			if ( ! $this->filesystem || ! $this->prepare_cache_dir() ) {
				return;
			}

			ob_start(
				function ( $buffer ) use ( $file_path ) {
					return $this->process_buffer( $buffer, $file_path );
				}
			);
		}

		/**
		 * Process the buffer by minifying it and saving cache files.
		 *
		 * @param string $buffer The content to be processed, potentially containing HTML.
		 * @param string $file_path The path to the file being processed.
		 * @return string The processed and minified buffer content.
		 *
		 * @since 1.0.0
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
		 * @param string $buffer The HTML content to be minified.
		 * @return string The minified HTML content.
		 *
		 * @since 1.0.0
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
		 *
		 * @since 1.0.0
		 */
		private function is_not_cacheable(): bool {
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$parsed_path = wp_parse_url( $request_uri, PHP_URL_PATH );
			$path_info   = pathinfo( trim( $parsed_path, '/' ), PATHINFO_EXTENSION );
			return is_404() || ! empty( $path_info );
		}

		/**
		 * Get the cache file path based on the URL path.
		 *
		 * @param string $type The file type (default: 'html').
		 * @return string The cache file path.
		 *
		 * @since 1.0.0
		 */
		private function get_cache_file_path( $type = 'html' ): string {
			return "{$this->cache_root_dir}/{$this->domain}/" . ( '' === $this->url_path ? "index.{$type}" : "{$this->url_path}/index.{$type}" );
		}

		/**
		 * Get the cache file URL based on the URL path.
		 *
		 * @param string $type The file type (default: 'html').
		 * @return string The cache file URL.
		 *
		 * @since 1.0.0
		 */
		public function get_cache_file_url( $type = 'html' ): string {
			return "{$this->cache_root_url}/{$this->domain}/" . ( '' === $this->url_path ? "index.{$type}" : "{$this->url_path}/index.{$type}" );
		}

		/**
		 * Prepare the cache directory for storing files.
		 *
		 * @return bool True if successful, false otherwise.
		 *
		 * @since 1.0.0
		 */
		private function prepare_cache_dir(): bool {
			return Util::prepare_cache_dir( "{$this->cache_root_dir}/{$this->domain}/" . ( '' === $this->url_path ? '' : "/{$this->url_path}" ) );
		}

		/**
		 * Save cache files with optional gzip compression.
		 *
		 * @param string $buffer The content to save.
		 * @param string $file_path The file path for saving.
		 * @param string $type The file type (default: 'html').
		 * @return void
		 *
		 * @since 1.0.0
		 */
		private function save_cache_files( $buffer, $file_path, $type = 'html' ): void {

			if ( ! $this->maybe_store_cache() && 'html' === $type ) {
				return;
			}

			$this->prepare_cache_dir();
			$gzip_file_path = $file_path . '.gz';

			$this->filesystem->put_contents( $file_path, $buffer, FS_CHMOD_FILE );

			$gzip_output = gzencode( $buffer, 9 );
			$this->filesystem->put_contents( $gzip_file_path, $gzip_output, FS_CHMOD_FILE );
		}

		/**
		 * Determine if cache storage is allowed.
		 *
		 * @return bool True if cache can be stored, false otherwise.
		 *
		 * @since 1.0.0
		 */
		private function maybe_store_cache() {
			if ( ! empty( $_SERVER['QUERY_STRING'] ) &&
				preg_match( '/(?:^|&)(s|ver|v)(?:=|&|$)/', sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) )
			) {
				return false;
			}

			if ( isset( $this->options['preload_settings']['enablePreloadCache'] ) && (bool) $this->options['preload_settings']['enablePreloadCache'] ) {
				if ( isset( $this->options['preload_settings']['excludePreloadCache'] ) && ! empty( $this->options['preload_settings']['excludePreloadCache'] ) ) {
					$exclude_urls = Util::process_urls( $this->options['preload_settings']['excludePreloadCache'] );

					$current_url = home_url( str_replace( wp_parse_url( home_url(), PHP_URL_PATH ) ?? '', '', sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ) );
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
		 * Invalidate dynamic static HTML cache for a specific page.
		 *
		 * @param int $page_id The page ID.
		 * @return void
		 *
		 * @since 1.0.0
		 */
		public function invalidate_dynamic_static_html( $page_id ): void {
			$path = str_replace( home_url(), '', get_permalink( $page_id ) );

			$html_file_path = $this->get_file_path( $path, 'html' );
			$css_file_path  = $this->get_file_path( $path, 'css' );
			$this->delete_cache_files( $html_file_path );
			$this->delete_cache_files( $css_file_path );

			if ( ! wp_next_scheduled( 'wppo_generate_static_page', array( $page_id ) ) ) {
				wp_schedule_single_event( time() + \wp_rand( 0, 5 ), 'wppo_generate_static_page', array( $page_id ) );
			}
		}

		/**
		 * Get the file path for a specific page.
		 *
		 * @param string|null $url_path The URL path (optional).
		 * @param string      $type The file type (default: 'html').
		 * @return string The file path.
		 *
		 * @since 1.0.0
		 */
		private function get_file_path( string $url_path = null, string $type = 'html' ): string {
			$url_path = trim( $url_path, '/' );
			return "{$this->cache_root_dir}/{$this->domain}/" . ( '' === $url_path ? "index.{$type}" : "{$url_path}/index.{$type}" );
		}

		/**
		 * Delete cache files for a specific file path.
		 *
		 * @param string $file_path The file path.
		 * @return void
		 *
		 * @since 1.0.0
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
		 * @param string|null $url_path The URL path of the page for which to clear the cache. If null, all cache will be cleared.
		 * @return void
		 *
		 * @since 1.0.0
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
		 *
		 * @since 1.0.0
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
		 *
		 * @since 1.0.0
		 */
		public static function get_cache_size(): string {
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
		 * @param string $directory The path to the directory whose size is to be calculated.
		 * @return int The total size of the directory in bytes.
		 *
		 * @since 1.0.0
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
