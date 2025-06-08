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

use PerformanceOptimise\Inc\Minify\CSS as MinifyCss;
use PerformanceOptimise\Inc\Minify\HTML as MinifyHtml;
use MatthiasMullie\Minify\CSS as MatthiasCssMinifier;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
		 * The directory where cache files are stored, relative to WP_CONTENT_DIR.
		 *
		 * @since 1.0.0
		 * @var string
		 */
		private const CACHE_DIR_RELATIVE = '/cache/wppo';

		/**
		 * The domain name of the site.
		 *
		 * @since 1.0.0
		 * @var string
		 */
		private string $domain;

		/**
		 * The root directory for cache files.
		 *
		 * @since 1.0.0
		 * @var string
		 */
		private string $cache_root_dir;

		/**
		 * The URL to access cache files.
		 *
		 * @since 1.0.0
		 * @var string
		 */
		private string $cache_root_url;

		/**
		 * The URL path for the current request, normalized.
		 *
		 * @since 1.0.0
		 * @var string
		 */
		private string $url_path_normalized;

		/**
		 * The filesystem object used for file operations.
		 *
		 * @since 1.0.0
		 * @var \WP_Filesystem_Base|null
		 */
		private ?\WP_Filesystem_Base $filesystem;

		/**
		 * The options/settings for the cache system.
		 *
		 * @since 1.0.0
		 * @var array<string, mixed>
		 */
		private array $options;

		/**
		 * Constructor to initialize cache settings and configurations.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			$this->domain = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
			$this->domain = preg_replace( '/:\d+$/', '', $this->domain );

			$this->cache_root_dir = wp_normalize_path( WP_CONTENT_DIR . self::CACHE_DIR_RELATIVE );
			$this->cache_root_url = content_url( self::CACHE_DIR_RELATIVE );

			$request_uri               = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
			$parsed_url_path           = wp_parse_url( $request_uri, PHP_URL_PATH );
			$parsed_url_path           = empty( $parsed_url_path ) ? '/' : $parsed_url_path;
			$this->url_path_normalized = trim( $parsed_url_path, '/' ); // example: 'path/to/page' or empty for homepage.

			$this->filesystem = Util::init_filesystem();
			$this->options    = get_option( 'wppo_settings', array() );
		}

		/**
		 * Combines all enqueued CSS files into a single file.
		 *
		 * @since 1.0.0
		 */
		public function combine_css(): void {
			if ( is_user_logged_in() || is_admin() ) {
				return;
			}

			global $wp_styles;
			if ( ! ( $wp_styles instanceof \WP_Styles ) || empty( $wp_styles->queue ) ) {
				return;
			}

			$exclude_combine_css_handles_or_srcs = array();
			if ( ! empty( $this->options['file_optimisation']['excludeCombineCSS'] ) ) {
				$exclude_combine_css_handles_or_srcs = Util::process_urls( (string) $this->options['file_optimisation']['excludeCombineCSS'] );
			}

			$combined_css_content = '';
			$processed_handles    = array();

			foreach ( $wp_styles->queue as $handle ) {
				if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
					continue;
				}

				$style_data = $wp_styles->registered[ $handle ];
				$src        = $style_data->src;

				$should_exclude = false;
				foreach ( $exclude_combine_css_handles_or_srcs as $exclude_item ) {
					if ( $handle === $exclude_item || ( $src && strpos( $src, $exclude_item ) !== false ) ) {
						$should_exclude = true;
						break;
					}
				}
				if ( $should_exclude ) {
					continue;
				}

				if ( isset( $style_data->args ) && 'all' !== $style_data->args && ! empty( $style_data->args ) ) {
					continue;
				}

				if ( empty( $src ) || ( 0 !== strpos( $src, home_url() ) && 0 !== strpos( $src, content_url() ) && '/' !== $src[0] ) ) {
					continue;
				}

				$css_file_content = $this->fetch_css_content_for_combine( $src );

				if ( false === $css_file_content ) {
					continue;
				}

				if ( ! empty( $style_data->extra['before'] ) ) {
					$combined_css_content .= implode( "\n", $style_data->extra['before'] ) . "\n";
				}
				$combined_css_content .= $css_file_content . "\n";
				if ( ! empty( $style_data->extra['after'] ) ) {
					$combined_css_content .= implode( "\n", $style_data->extra['after'] ) . "\n";
				}

				wp_dequeue_style( $handle );
				$processed_handles[] = $handle;
			}

			if ( ! empty( $combined_css_content ) ) {
				$combined_css_content = preg_replace( '/font-display\s*:\s*(block|auto|fallback|optional)\s*;?/i', 'font-display: swap;', $combined_css_content );
				$combined_css_content = preg_replace_callback(
					'/@font-face\s*{([^}]*)}/is',
					function ( $matches ) {
						$font_face_block = $matches[1];
						if ( stripos( $font_face_block, 'font-display' ) === false ) {
							return '@font-face {' . $font_face_block . ' font-display: swap;}';
						}
						return $matches[0];
					},
					$combined_css_content
				);

				try {
					$css_minifier         = new MatthiasCssMinifier( $combined_css_content );
					$combined_css_content = $css_minifier->minify();
				} catch ( \Exception $e ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log( 'Combined CSS Minification Error: ' . $e->getMessage() );
					}
				}

				$cache_filename_hash = md5( $combined_css_content . implode( ',', $processed_handles ) );
				$css_file_path       = $this->get_cache_file_path_for_combined( $cache_filename_hash, 'css' );

				$this->save_cache_files( $combined_css_content, $css_file_path );
				$css_url = $this->get_cache_file_url_for_combined( $cache_filename_hash, 'css' );

				if ( $this->filesystem && $this->filesystem->exists( $css_file_path ) ) {
					$version = (string) $this->filesystem->mtime( $css_file_path );
					wp_enqueue_style( 'wppo-combined-css', $css_url, array(), $version, 'all' );

					Util::generate_preload_link( esc_url_raw( add_query_arg( 'ver', $version, $css_url ) ), 'preload', 'style' );
				}
			}
		}

		/**
		 * Fetches CSS content for combining.
		 *
		 * @param string $url The URL or path of the CSS file.
		 * @return string|false The CSS content or false if fetching fails.
		 * @since 1.0.0
		 */
		private function fetch_css_content_for_combine( string $url ) {
			if ( empty( $url ) || ! $this->filesystem ) {
				return false;
			}

			$css_local_path = Util::get_local_path( $url );

			if ( $this->filesystem->exists( $css_local_path ) && $this->filesystem->is_readable( $css_local_path ) ) {
				$css_content = $this->filesystem->get_contents( $css_local_path );
				if ( false !== $css_content ) {
					return MinifyCss::update_image_paths( $css_content, $css_local_path );
				}
			} elseif ( 0 === strpos( $url, '//' ) ) { // Protocol-relative URL.
				$scheme      = is_ssl() ? 'https:' : 'http:';
				$url         = $scheme . $url;
				$css_content = $this->fetch_remote_css_content( $url );
				if ( false !== $css_content ) {
					return MinifyCss::update_image_paths( $css_content, $url ); // Pass URL as if it were a path for context.
				}
			} elseif ( filter_var( $url, FILTER_VALIDATE_URL ) ) { // Full remote URL.
				$css_content = $this->fetch_remote_css_content( $url );
				if ( false !== $css_content ) {
					return MinifyCss::update_image_paths( $css_content, $url );
				}
			}

			return false;
		}

		/**
		 * Fetches content from a remote URL.
		 *
		 * @param string $url The URL to fetch.
		 * @return string|false Content or false on failure.
		 */
		private function fetch_remote_css_content( string $url ) {
			$response = wp_remote_get( $url, array( 'timeout' => 10 ) );
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return false;
			}
			return wp_remote_retrieve_body( $response );
		}


		/**
		 * Generate dynamic static HTML.
		 *
		 * Creates a static HTML version of the page if not logged in and not a 404 page.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function generate_dynamic_static_html(): void {
			if ( is_user_logged_in() || $this->is_not_cacheable_page() || is_admin() ) {
				return;
			}

			if ( ! class_exists( 'PerformanceOptimise\Inc\Image_Optimisation' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-image-optimisation.php';
			}

			$file_path = $this->get_cache_file_path_for_page( 'html' );

			if ( ! $this->filesystem || ! $this->prepare_cache_dir_for_page() ) {
				return;
			}

			ob_start(
				function ( $buffer ) use ( $file_path ) {
					return $this->process_html_buffer( $buffer, $file_path );
				}
			);
		}

		/**
		 * Process the HTML buffer by optimizing images, minifying it, and saving cache files.
		 *
		 * @since 1.0.0
		 * @param string $buffer    The content to be processed, potentially containing HTML.
		 * @param string $file_path The path where the cached file will be saved.
		 * @return string The processed and minified buffer content.
		 */
		private function process_html_buffer( string $buffer, string $file_path ): string {
			$image_optimisation = new Image_Optimisation( $this->options );

			$buffer = $image_optimisation->maybe_serve_next_gen_images( $buffer ); // Convert img src to webp/avif.
			$buffer = $image_optimisation->add_delay_load_elements( $buffer );   // Add lazy load for images/videos.

			if ( ! empty( $this->options['file_optimisation']['minifyHTML'] ) && (bool) $this->options['file_optimisation']['minifyHTML'] ) {
				$minifier = new MinifyHtml( $buffer, $this->options );
				$buffer   = $minifier->get_minified_html();
			}

			$this->save_cache_files( $buffer, $file_path );

			return $buffer;
		}

		/**
		 * Check if the page is not cacheable.
		 *
		 * @since 1.0.0
		 * @return bool True if the page should not be cached, false otherwise.
		 */
		private function is_not_cacheable_page(): bool {
			// Do not cache 404 pages.
			if ( is_404() ) {
				return true;
			}

			if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
				$allowed_query_params = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', '_ga' );
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				parse_str( $_SERVER['QUERY_STRING'], $query_vars );
				foreach ( array_keys( $query_vars ) as $key ) {
					if ( ! in_array( strtolower( $key ), $allowed_query_params, true ) ) {
						return true;
					}
				}
			}

			if ( is_search() || is_feed() || is_trackback() || is_robots() || is_preview() ) {
				return true;
			}

			if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
				return true;
			}

			if ( class_exists( 'WooCommerce' ) ) {
				if ( \is_cart() || \is_checkout() || \is_account_page() ) {
					return true;
				}
			}

			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$path_info   = pathinfo( wp_parse_url( $request_uri, PHP_URL_PATH ), PATHINFO_EXTENSION );
			if ( ! empty( $path_info ) && 'php' !== $path_info ) { // Allow .php implicitly, but not other extensions.
				return true;
			}

			return false;
		}

		/**
		 * Get the cache file path for the current page.
		 *
		 * @since 1.0.0
		 * @param string $type The file type (default: 'html').
		 * @return string The cache file path.
		 */
		private function get_cache_file_path_for_page( string $type = 'html' ): string {
			$path_suffix = empty( $this->url_path_normalized ) ? 'index.' . $type : trailingslashit( $this->url_path_normalized ) . 'index.' . $type;
			return wp_normalize_path( trailingslashit( $this->cache_root_dir ) . trailingslashit( $this->domain ) . $path_suffix );
		}

		/**
		 * Get the cache file path for combined assets.
		 *
		 * @since 1.0.0
		 * @param string $filename The unique filename (e.g., hash).
		 * @param string $type     The file type ('css', 'js').
		 * @return string The cache file path.
		 */
		private function get_cache_file_path_for_combined( string $filename, string $type ): string {
			$min_dir = wp_normalize_path( trailingslashit( $this->cache_root_dir ) . 'min/' . $type . '/' );
			return $min_dir . $filename . '.' . $type;
		}

		/**
		 * Get the cache file URL for combined assets.
		 *
		 * @since 1.0.0
		 * @param string $filename The unique filename (e.g., hash).
		 * @param string $type     The file type ('css', 'js').
		 * @return string The cache file URL.
		 */
		public function get_cache_file_url_for_combined( string $filename, string $type ): string {
			$min_url_path = trailingslashit( $this->cache_root_url ) . 'min/' . $type . '/';
			return $min_url_path . $filename . '.' . $type;
		}


		/**
		 * Prepare the cache directory for storing files for the current page.
		 *
		 * @since 1.0.0
		 * @return bool True if successful, false otherwise.
		 */
		private function prepare_cache_dir_for_page(): bool {
			$page_cache_dir = trailingslashit( $this->cache_root_dir ) . trailingslashit( $this->domain );
			if ( ! empty( $this->url_path_normalized ) ) {
				$page_cache_dir .= trailingslashit( $this->url_path_normalized );
			}
			return Util::prepare_cache_dir( wp_normalize_path( $page_cache_dir ) );
		}

		/**
		 * Save cache files (HTML, CSS, JS) with optional gzip compression.
		 *
		 * @since 1.0.0
		 * @param string $buffer    The content to save.
		 * @param string $file_path The file path for saving.
		 * @return void
		 */
		private function save_cache_files( string $buffer, string $file_path ): void {
			if ( ! $this->filesystem ) {
				return;
			}

			// Determine if this is an HTML page cache save.
			$is_html_page_cache = ( strpos( $file_path, trailingslashit( $this->domain ) ) !== false && preg_match( '/index\.html$/', $file_path ) );

			if ( $is_html_page_cache && ! $this->should_store_page_cache() ) {
				return;
			}

			// Ensure directory exists. dirname() is safe for paths generated by get_cache_file_path_*.
			Util::prepare_cache_dir( dirname( $file_path ) );

			$this->filesystem->put_contents( $file_path, $buffer, FS_CHMOD_FILE );

			if ( function_exists( 'gzencode' ) ) {
				$gzip_output = gzencode( $buffer, 9 );
				if ( false !== $gzip_output ) {
					$this->filesystem->put_contents( $file_path . '.gz', $gzip_output, FS_CHMOD_FILE );
				}
			}
		}

		/**
		 * Determine if page cache storage is allowed based on settings.
		 *
		 * @since 1.0.0
		 * @return bool True if cache can be stored, false otherwise.
		 */
		private function should_store_page_cache(): bool {
			$preload_settings = $this->options['preload_settings'] ?? array();

			if ( empty( $preload_settings['enablePreloadCache'] ) ) {
				return false;
			}

			if ( ! empty( $preload_settings['excludePreloadCache'] ) ) {
				$exclude_urls_patterns = Util::process_urls( (string) $preload_settings['excludePreloadCache'] );
				$current_page_url      = home_url( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' );
				$current_page_url      = rtrim( $current_page_url, '/' );

				foreach ( $exclude_urls_patterns as $pattern ) {
					$pattern = rtrim( $pattern, '/' );
					if ( 0 !== strpos( $pattern, 'http' ) ) {
						$pattern = home_url( $pattern );
						$pattern = rtrim( $pattern, '/' );
					}

					if ( str_ends_with( $pattern, '(.*)' ) ) {
						$base_pattern = rtrim( str_replace( '(.*)', '', $pattern ), '/' );
						if ( 0 === strpos( $current_page_url, $base_pattern ) ) {
							return false;
						}
					} elseif ( $current_page_url === $pattern ) {
						return false;
					}
				}
			}

			return true;
		}

		/**
		 * Invalidate dynamic static HTML cache for a specific post.
		 *
		 * @since 1.0.0
		 * @param int $post_id The post ID.
		 * @return void
		 */
		public function invalidate_dynamic_static_html( int $post_id ): void {
			$permalink = get_permalink( $post_id );
			if ( ! $permalink || is_wp_error( $permalink ) ) {
				return;
			}

			$url_path = trim( wp_parse_url( $permalink, PHP_URL_PATH ), '/' );

			// Invalidate HTML cache for this specific page.
			$html_file_path = $this->get_cache_file_path_for_post_url( $url_path, 'html' );
			$this->delete_single_cache_file_pair( $html_file_path );

			// Re-schedule preloading for this specific page if preloading is enabled.
			$preload_settings = $this->options['preload_settings'] ?? array();
			if ( ! empty( $preload_settings['enablePreloadCache'] ) ) {
				if ( ! wp_next_scheduled( 'wppo_generate_static_page', array( $post_id ) ) ) {
					// Schedule with a slight random delay to distribute load.
					wp_schedule_single_event( time() + wp_rand( 60, 300 ), 'wppo_generate_static_page', array( $post_id ) );
				}
			}
		}

		/**
		 * Get the cache file path for a specific post URL.
		 *
		 * @since 1.0.0
		 * @param string $url_path The URL path (e.g., 'sample-page' or 'category/post-name').
		 * @param string $type     The file type (default: 'html').
		 * @return string The file path.
		 */
		private function get_cache_file_path_for_post_url( string $url_path, string $type = 'html' ): string {
			$path_suffix = empty( $url_path ) ? 'index.' . $type : trailingslashit( $url_path ) . 'index.' . $type;
			return wp_normalize_path( trailingslashit( $this->cache_root_dir ) . trailingslashit( $this->domain ) . $path_suffix );
		}

		/**
		 * Delete a single cache file and its .gz version.
		 *
		 * @since 1.0.0
		 * @param string $file_path The base path to the cache file (without .gz).
		 * @return void
		 */
		private function delete_single_cache_file_pair( string $file_path ): void {
			if ( ! $this->filesystem ) {
				return;
			}
			if ( $this->filesystem->exists( $file_path ) ) {
				$this->filesystem->delete( $file_path );
			}
			$gzip_file_path = $file_path . '.gz';
			if ( $this->filesystem->exists( $gzip_file_path ) ) {
				$this->filesystem->delete( $gzip_file_path );
			}
		}

		/**
		 * Clear the cache for a specific page URL or all pages and assets.
		 *
		 * @since 1.0.0
		 * @param string|null $page_url_path Optional. The URL path of the page for which to clear the cache.
		 *                                   If null, all cache (HTML pages and minified assets) will be cleared.
		 * @return void
		 */
		public static function clear_cache( ?string $page_url_path = null ): void {
			$instance = new self(); // Needed to access instance properties like $filesystem, $domain.
			if ( ! $instance->filesystem ) {
				return;
			}

			if ( null !== $page_url_path ) {
				$normalized_url_path = trim( (string) $page_url_path, '/' );
				$html_file_path      = $instance->get_cache_file_path_for_post_url( $normalized_url_path, 'html' );
				$instance->delete_single_cache_file_pair( $html_file_path );
			} else {
				$domain_cache_dir = wp_normalize_path( trailingslashit( $instance->cache_root_dir ) . $instance->domain );
				if ( $instance->filesystem->is_dir( $domain_cache_dir ) ) {
					$instance->filesystem->delete( $domain_cache_dir, true ); // Recursive delete.
				}

				$min_assets_dir = wp_normalize_path( trailingslashit( $instance->cache_root_dir ) . 'min' );
				if ( $instance->filesystem->is_dir( $min_assets_dir ) ) {
					$instance->filesystem->delete( $min_assets_dir, true ); // Recursive delete.
				}
			}
		}


		/**
		 * Get the size of the cache for the current domain.
		 *
		 * @since 1.0.0
		 * @return string Formatted cache size or an error/status message.
		 */
		public static function get_cache_size(): string {
			$instance = new self();
			if ( ! $instance->filesystem ) {
				return __( 'Filesystem not initialized.', 'performance-optimisation' );
			}

			$domain_cache_dir = wp_normalize_path( trailingslashit( $instance->cache_root_dir ) . $instance->domain );
			$min_assets_dir   = wp_normalize_path( trailingslashit( $instance->cache_root_dir ) . 'min' );

			$total_size = 0;
			if ( $instance->filesystem->is_dir( $domain_cache_dir ) ) {
				$total_size += $instance->calculate_directory_size_recursive( $domain_cache_dir );
			}
			if ( $instance->filesystem->is_dir( $min_assets_dir ) ) {
				$total_size += $instance->calculate_directory_size_recursive( $min_assets_dir );
			}

			if ( 0 === $total_size && ! $instance->filesystem->is_dir( $domain_cache_dir ) && ! $instance->filesystem->is_dir( $min_assets_dir ) ) {
				return __( 'Cache directory does not exist.', 'performance-optimisation' );
			}

			return size_format( $total_size );
		}

		/**
		 * Calculate the size of a directory recursively.
		 *
		 * @since 1.0.0
		 * @param string $directory The path to the directory.
		 * @return int The total size of the directory in bytes.
		 */
		private function calculate_directory_size_recursive( string $directory ): int {
			$total_size = 0;
			if ( ! $this->filesystem ) {
				return 0;
			}

			$contents = $this->filesystem->dirlist( $directory, false, true );

			if ( empty( $contents ) ) {
				return 0;
			}

			foreach ( $contents as $item ) {
				if ( 'f' === $item['type'] ) { // It's a file.
					$total_size += (int) $item['size'];
				}
			}

			$total_size = 0;
			$stack      = array( $directory );
			while ( ! empty( $stack ) ) {
				$current_dir = array_pop( $stack );
				$items       = $this->filesystem->dirlist( $current_dir );
				if ( $items ) {
					foreach ( $items as $item_name => $item_details ) {
						$full_path = trailingslashit( $current_dir ) . $item_name;
						if ( 'f' === $item_details['type'] ) {
							$total_size += (int) $item_details['size'];
						} elseif ( 'd' === $item_details['type'] ) {
							$stack[] = $full_path; // Add subdirectory to stack for processing.
						}
					}
				}
			}

			return $total_size;
		}
	}
}
