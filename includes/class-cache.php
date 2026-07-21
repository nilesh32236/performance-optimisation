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
		 * @var object|null
		 * @since 1.0.0
		 */
		private $filesystem;

		/**
		 * Whether the filesystem has been initialized.
		 *
		 * @var bool
		 * @since 1.6.0
		 */
		private bool $fs_initialized = false;

		/**
		 * The sanitized request URI from $_SERVER.
		 *
		 * @var string
		 * @since 1.6.0
		 */
		private string $request_uri;

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
			$domain = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';

			// Convert internationalized domain names to ASCII (punycode) to support IDN chars.
			if ( function_exists( 'idn_to_ascii' ) ) {
				$converted = idn_to_ascii( $domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
				if ( false !== $converted ) {
					$domain = $converted;
				}
			}

			$valid_domain = ! (
				strpos( $domain, '..' ) !== false ||
				strpos( $domain, '/' ) !== false ||
				strpos( $domain, '\\' ) !== false ||
				! preg_match( '/^[a-z0-9\.\-]+$/i', $domain )
			);

			if ( ! $valid_domain ) {
				$domain = '';
			}

			$this->domain = $domain;

			// Define cache root directory and URL.
			$this->cache_root_dir = wp_normalize_path( WP_CONTENT_DIR . self::CACHE_DIR );
			$this->cache_root_url = WP_CONTENT_URL . self::CACHE_DIR;

			$this->request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$url_path          = wp_normalize_path( trim( rawurldecode( (string) wp_parse_url( $this->request_uri, PHP_URL_PATH ) ), '/' ) );

			// Reject directory traversal.
			if ( strpos( $url_path, '..' ) !== false ) {
				$url_path = '';
			}

			$this->url_path = $url_path;

			// Initialize filesystem lazily via get_filesystem().
			$this->options = get_option( 'wppo_settings', array() );

			if ( ! $valid_domain && ! empty( $this->options['debug'] ) ) {
				do_action( 'wppo_debug_log', 'Cache domain validation failed' );
			}
		}

		/**
		 * Lazily initializes and returns the WP_Filesystem object.
		 *
		 * @return object|false The filesystem object or false on failure.
		 * @since 1.6.0
		 */
		private function get_filesystem() {
			if ( ! $this->fs_initialized ) {
				$this->filesystem     = Util::init_filesystem();
				$this->fs_initialized = true;
			}
			return $this->filesystem;
		}

		/**
		 * Combines all enqueued CSS files into a single file.
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function combine_css() {
			if ( is_user_logged_in() || is_404() || $this->is_not_cacheable() ) {
				return;
			}

			global $wp_styles;
			$styles = $wp_styles->queue;

			if ( empty( $styles ) ) {
				return;
			}

			$fs = $this->get_filesystem();
			if ( ! $fs ) {
				return;
			}

			// Reuse fresh cache if available.
			$css_file_path = $this->get_cache_file_path( 'css' );

			if ( $fs->exists( $css_file_path ) ) {
				$css_url = $this->get_cache_file_url( 'css' );
				$version = filemtime( $css_file_path );
				wp_enqueue_style( 'wppo-combine-css', $css_url, array(), $version, 'all' );
				return;
			}

			$exclude_combine_css = array();
			if ( isset( $this->options['file_optimisation']['excludeCombineCSS'] ) && ! empty( $this->options['file_optimisation']['excludeCombineCSS'] ) ) {
				$exclude_combine_css = Util::process_urls( $this->options['file_optimisation']['excludeCombineCSS'] );
			}

			$combined_css = '';

			foreach ( $styles as $handle ) {
				if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
					continue;
				}
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

				$version = filemtime( $css_file_path );
				wp_enqueue_style( 'wppo-combine-css', $css_url, array(), $version, 'all' );

				$css_url_with_version = $css_url . "?ver=$version";
				echo '<link rel="preload" as="style" href="' . esc_url( $css_url_with_version ) . '">';
			}
		}

		/**
		 * Fetches CSS content from a remote URL or local path.
		 *
		 * @param string $url The URL of the CSS file.
		 * @return string|false The CSS content or false if fetching fails.
		 *
		 * @since 1.0.0
		 */
		private function fetch_remote_css( $url ) {
			if ( empty( $url ) ) {
				return '';
			}

			$css_file = Util::get_local_path( $url );
			$fs       = $this->get_filesystem();
			if ( $fs ) {
				$css_content = $fs->get_contents( $css_file );

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

			if ( ! $this->get_filesystem() || ! $this->prepare_cache_dir() ) {
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
			$buffer = $image_optimisation->lazy_load_videos( $buffer );

			$file_opts         = $this->options['file_optimisation'] ?? array();
			$needs_minify_pass = ! empty( $file_opts['minifyHTML'] )
				|| ! empty( $file_opts['delayJS'] )
				|| ! empty( $file_opts['minifyInlineCSS'] )
				|| ! empty( $file_opts['minifyInlineJS'] );

			if ( $needs_minify_pass ) {
				$buffer = $this->minify_buffer( $buffer );
			}

			// Apply CDN rewriting.
			$buffer = $this->maybe_apply_cdn( $buffer );

			$this->save_cache_files( $buffer, $file_path );

			return $buffer;
		}

		/**
		 * Rewrite local asset URLs in HTML to use the configured CDN for wp-content and wp-includes resources.
		 *
		 * Scans img, script, link, source, and video tags and replaces attribute values that start with the site URL
		 * and contain `/wp-content/` or `/wp-includes/`. The attributes handled are `src`, `href`, `data-src`,
		 * `srcset`, and `data-srcset`. If no CDN is configured or `\WP_HTML_Tag_Processor` is unavailable, the
		 * buffer is returned unchanged.
		 *
		 * @param string $buffer The HTML content to process.
		 * @return string The HTML with applicable asset URLs rewritten to the CDN, or the original HTML if no changes were made.
		 *
		 * @since 1.2.0
		 */
		public function maybe_apply_cdn( string $buffer ): string {
			$cdn_url = $this->options['file_optimisation']['cdnURL'] ?? '';

			if ( empty( $cdn_url ) ) {
				return $buffer;
			}

			$site_url = home_url();
			$cdn_url  = rtrim( $cdn_url, '/' );

			if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
				return $buffer;
			}

			$tags = new \WP_HTML_Tag_Processor( $buffer );

			while ( $tags->next_tag() ) {
				$tag_name = strtolower( $tags->get_tag() );
				if ( ! in_array( $tag_name, array( 'img', 'script', 'link', 'source', 'video' ), true ) ) {
					continue;
				}

				$attributes = array( 'src', 'href', 'data-src' );

				foreach ( $attributes as $attr ) {
					$val = $tags->get_attribute( $attr );

					if ( $val && preg_match( '#^' . preg_quote( $site_url, '#' ) . '(/|$)#', $val ) && preg_match( '#\/(?:wp-content|wp-includes)\/#', $val ) ) {
						$tags->set_attribute( $attr, str_replace( $site_url, $cdn_url, $val ) );
					}
				}

				foreach ( array( 'srcset', 'data-srcset' ) as $attr ) {
					$srcset_attr = $tags->get_attribute( $attr );
					if ( $srcset_attr ) {
						$candidates = explode( ',', $srcset_attr );
						$new_srcset = array();

						foreach ( $candidates as $candidate ) {
							$candidate = trim( $candidate );
							$parts     = preg_split( '/\s+/', $candidate, 2 );
							$url       = $parts[0];
							$suffix    = isset( $parts[1] ) ? ' ' . $parts[1] : '';

							if ( preg_match( '#^' . preg_quote( $site_url, '#' ) . '(/|$)#', $url ) && preg_match( '#\/(?:wp-content|wp-includes)\/#', $url ) ) {
								$url = str_replace( $site_url, $cdn_url, $url );
							}
							$new_srcset[] = $url . $suffix;
						}

						$tags->set_attribute( $attr, implode( ', ', $new_srcset ) );
					}
				}
			}

			return $tags->get_updated_html();
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
			if ( empty( $this->domain ) ) {
				return true;
			}

			$parsed_path    = wp_parse_url( $this->request_uri, PHP_URL_PATH );
			$local_url_path = wp_normalize_path( trim( rawurldecode( (string) $parsed_path ), '/' ) );

			if ( strpos( $local_url_path, '..' ) !== false ) {
				return true;
			}

			$path_info = pathinfo( $local_url_path, PATHINFO_EXTENSION );
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

			$fs = $this->get_filesystem();
			if ( ! $fs ) {
				return;
			}
			$fs->put_contents( $file_path, $buffer, FS_CHMOD_FILE );

			if ( function_exists( 'gzencode' ) ) {
				$gzip_output = gzencode( $buffer, 9 );
				$fs->put_contents( $gzip_file_path, $gzip_output, FS_CHMOD_FILE );
			}
		}

		/**
		 * Determine if cache storage is allowed.
		 *
		 * @return bool True if cache can be stored, false otherwise.
		 *
		 * @since 1.0.0
		 */
		private function maybe_store_cache() {
			if ( empty( $this->domain ) || strpos( $this->url_path, '..' ) !== false ) {
				// Prevent empty domain caching which could occur after traversal sanitation.
				return false;
			}

			if ( ! empty( $_SERVER['QUERY_STRING'] ) &&
				preg_match( '/(?:^|&)(s|ver|v)(?:=|&|$)/', sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) )
			) {
				return false;
			}

			if ( isset( $this->options['preload_settings']['enablePreloadCache'] ) && ! empty( $this->options['preload_settings']['enablePreloadCache'] ) ) {
				if ( isset( $this->options['preload_settings']['excludePreloadCache'] ) && ! empty( $this->options['preload_settings']['excludePreloadCache'] ) ) {
					$exclude_urls = Util::process_urls( $this->options['preload_settings']['excludePreloadCache'] );

					$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
					$home_path   = wp_parse_url( home_url(), PHP_URL_PATH ) ?? '';
					if ( $home_path && '/' !== $home_path && 0 === strpos( $request_uri, $home_path ) ) {
						$request_uri = substr( $request_uri, strlen( $home_path ) );
					}
					$current_url = home_url( $request_uri );
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
			}

			return true;
		}

		/**
		 * Invalidate dynamic static HTML cache for a specific page and global archives.
		 *
		 * @param int $page_id The page ID.
		 * @return void
		 *
		 * @since 1.0.0
		 */
		public function invalidate_dynamic_static_html( $page_id ): void {
			$path = wp_make_link_relative( get_permalink( $page_id ) );

			$html_file_path = $this->get_file_path( $path, 'html' );
			$css_file_path  = $this->get_file_path( $path, 'css' );
			$this->delete_cache_files( $html_file_path );
			$this->delete_cache_files( $css_file_path );

			// Smart Purging: Always clear the home page and blog archive.
			$home_path = wp_make_link_relative( home_url( '/' ) );
			$this->delete_cache_files( $this->get_file_path( $home_path, 'html' ) );

			if ( 'page' === get_option( 'show_on_front' ) ) {
				$posts_page_id = get_option( 'page_for_posts' );
				if ( $posts_page_id ) {
					$posts_path = str_replace( home_url(), '', get_permalink( $posts_page_id ) );
					$this->delete_cache_files( $this->get_file_path( $posts_path, 'html' ) );
				}
			}

			// Extended Smart Purging: Clear archives for public taxonomies and the current post type.
			$post_type = get_post_type( $page_id );

			if ( $post_type ) {
				$archive_link = get_post_type_archive_link( $post_type );
				if ( ! empty( $archive_link ) && ! is_wp_error( $archive_link ) ) {
					$archive_path = str_replace( home_url(), '', $archive_link );
					$this->delete_cache_files( $this->get_file_path( $archive_path, 'html' ) );
				}

				$taxonomies = get_object_taxonomies( $post_type, 'objects' );
				foreach ( $taxonomies as $taxonomy ) {
					if ( ! $taxonomy->public ) {
						continue;
					}

					$terms = get_the_terms( $page_id, $taxonomy->name );
					if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
						foreach ( $terms as $term ) {
							$term_link = get_term_link( $term );
							if ( ! empty( $term_link ) && ! is_wp_error( $term_link ) ) {
								$term_path = str_replace( home_url(), '', $term_link );
								$this->delete_cache_files( $this->get_file_path( $term_path, 'html' ) );
							}
						}
					}
				}
			}

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
		 * @since 1.1.1
		 */
		private function get_file_path( ?string $url_path = null, string $type = 'html' ): string {
			$url_path = wp_normalize_path( trim( (string) $url_path, '/' ) );

			if ( strpos( $url_path, '..' ) !== false ) {
				return ''; // Return empty string to prevent deletion or creation outside cache root.
			}

			return "{$this->cache_root_dir}/{$this->domain}/" . ( '' === $url_path ? "index.{$type}" : "{$url_path}/index.{$type}" );
		}

		/**
		 * Delete cache files for a specific file path.
		 *
		 * @param string $file_path The file path.
		 * @return bool True if successful (or not exists), false otherwise.
		 *
		 * @since 1.1.0
		 */
		private function delete_cache_files( $file_path ): bool {
			$gzip_file_path = $file_path . '.gz';

			$fs = $this->get_filesystem();
			if ( $fs ) {
				$res1 = ! $fs->exists( $file_path ) || $fs->delete( $file_path );
				$res2 = ! $fs->exists( $gzip_file_path ) || $fs->delete( $gzip_file_path );
				return $res1 && $res2;
			}

			return false;
		}

		/**
		 * Clear the cache for a specific page or all pages.
		 *
		 * @param string|null $url_path The URL path of the page for which to clear the cache. If null, all cache will be cleared.
		 * @return bool True on success, false on failure.
		 *
		 * @since 1.1.1
		 */
		public static function clear_cache( $url_path = null ): bool {
			delete_transient( 'wppo_cache_size' );
			delete_transient( 'wppo_total_js_css' );

			$instance = new self();

			if ( ! $instance->get_filesystem() ) {
				return false;
			}

			if ( $url_path ) {
				$url_path = wp_normalize_path( $url_path );

				if ( strpos( $url_path, '..' ) !== false ) {
					return false;
				}

				$html_file_path = $instance->get_file_path( $url_path, 'html' );
				$css_file_path  = $instance->get_file_path( $url_path, 'css' );

				if ( empty( $html_file_path ) || empty( $css_file_path ) ) {
					return false;
				}

				$res_html = $instance->delete_cache_files( $html_file_path );
				$res_css  = $instance->delete_cache_files( $css_file_path );
				return $res_html && $res_css;
			}

			return $instance->delete_all_cache_files();
		}

		/**
		 * Delete all cache files.
		 *
		 * @return bool True if successful, false otherwise.
		 *
		 * @since 1.0.0
		 */
		private function delete_all_cache_files(): bool {
			$cache_dir = "{$this->cache_root_dir}/{$this->domain}";
			$res1      = true;
			$res2      = true;

			$fs = $this->get_filesystem();

			if ( $fs && $fs->is_dir( $cache_dir ) ) {
				$res1 = $fs->delete( $cache_dir, true );
			}

			$min_dir = "{$this->cache_root_dir}/min";

			if ( $fs && $fs->is_dir( $min_dir ) ) {
				$res2 = $fs->delete( $min_dir, true );
			}

			return $res1 && $res2;
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

			if ( ! $instance->get_filesystem() ) {
				return __( 'Unable to initialize filesystem.', 'performance-optimisation' );
			}

			$cache_dir = "{$instance->cache_root_dir}/{$instance->domain}";

			if ( ! $instance->filesystem->is_dir( $cache_dir ) ) {
				return __( 'Cache directory does not exist.', 'performance-optimisation' );
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
			$fs         = $this->get_filesystem();
			$files      = $fs->dirlist( $directory );

			if ( ! $files ) {
				return $total_size;
			}

			foreach ( $files as $file ) {
				$file_path   = trailingslashit( $directory ) . $file['name'];
				$total_size += ( 'd' === $file['type'] )
					? $this->calculate_directory_size( $file_path )
					: $fs->size( $file_path );
			}

			return $total_size;
		}
	}
}
