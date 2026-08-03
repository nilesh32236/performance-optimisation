<?php
/**
 * Image Optimisation class for handling image conversion, preloading, and serving optimized images.
 *
 * This class is responsible for converting images to optimized formats (such as WebP or AVIF),
 * managing image preloading, and serving the optimized images based on the plugin settings.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Image_Optimisation' ) ) {

	/**
	 * Image Optimisation class.
	 *
	 * Handles image conversion, preloading, and serving optimized images.
	 *
	 * @since 1.0.0
	 */
	class Image_Optimisation {

		/**
		 * Default maximum width for preloading images.
		 *
		 * @since 1.5.1
		 */
		private const MAX_PRELOAD_WIDTH = 1478;

		/**
		 * Configuration options for image optimization.
		 *
		 * @var array
		 * @since 1.0.0
		 */
		private array $options;

		/**
		 * Array of image URLs to exclude from conversion.
		 *
		 * @var array
		 * @since 1.5.1
		 */
		private array $exclude_convert_imgs = array();

		/**
		 * Array of image URLs to preload on the front page.
		 *
		 * @var array
		 * @since 1.5.1
		 */
		private array $preload_front_page_urls = array();

		/**
		 * Array of image URLs to exclude from post type preloading.
		 *
		 * @var array
		 * @since 1.5.1
		 */
		private array $exclude_post_type_imgs = array();

		/**
		 * Array of image sizes to exclude.
		 *
		 * @var array
		 * @since 1.5.1
		 */
		private array $exclude_sizes = array();

		/**
		 * Array of image URLs to exclude from lazy loading.
		 *
		 * @var array
		 * @since 1.5.1
		 */
		private array $exclude_lazy_imgs = array();

		/**
		 * Array of video URLs to exclude from lazy loading.
		 *
		 * @var array
		 * @since 1.5.1
		 */
		private array $exclude_lazy_videos = array();

		/**
		 * Cached instance of Img_Converter to avoid repeated parsing of settings.
		 *
		 * @var Img_Converter|null
		 * @since 1.1.2
		 */
		private ?Img_Converter $img_converter = null;

		/**
		 * Constructor.
		 *
		 * @since 1.0.0
		 *
		 * @param array $options Configuration options for image optimization.
		 */
		public function __construct( $options ) {
			$this->options = $options;

			// Backward compat: migrate replacePlaceholderWithSVG to placeholderType.
			if ( ! isset( $this->options['image_optimisation']['placeholderType'] ) ) {
				if ( isset( $this->options['image_optimisation']['replacePlaceholderWithSVG'] ) ) {
					$this->options['image_optimisation']['placeholderType'] = (bool) $this->options['image_optimisation']['replacePlaceholderWithSVG'] ? 'svg' : 'none';
				} else {
					$this->options['image_optimisation']['placeholderType'] = 'none';
				}
			}

			$this->exclude_convert_imgs    = Util::process_urls( $this->options['image_optimisation']['excludeConvertImages'] ?? array() );
			$this->preload_front_page_urls = Util::process_urls( $this->options['image_optimisation']['preloadFrontPageImagesUrls'] ?? array() );
			$this->exclude_post_type_imgs  = Util::process_urls( $this->options['image_optimisation']['excludePostTypeImgUrl'] ?? array() );
			$this->exclude_sizes           = array_map( 'absint', array_map( 'trim', explode( ',', ( $this->options['image_optimisation']['excludeSize'] ?? '' ) ) ) );
			$this->exclude_lazy_imgs       = Util::process_urls( $this->options['image_optimisation']['excludeImages'] ?? array() );
			$this->exclude_lazy_videos     = Util::process_urls( $this->options['image_optimisation']['excludeVideos'] ?? array() );

			$this->setup_hooks();
		}

		/**
		 * Sets up hooks for image optimization features.
		 *
		 * @since 1.0.0
		 */
		private function setup_hooks() {
			if ( ! empty( $this->options['image_optimisation']['convertImg'] ) ) {
				$img_converter = $this->get_img_converter();

				// Skip the conversion hook when core handles all formats (get_format() returns 'none').
				$should_hook_conversion = 'none' !== $img_converter->get_format();

				// Still register the metadata filter so placeholder data (dominant
				// color/LQIP) is extracted for new uploads whenever server-side
				// conversion is skipped by design:
				// - WP 7.1+ client-side media processing (get_format() -> 'none'), or
				// - WP 6.7+ core-native next-gen generation (get_format() -> 'none').
				$core_handles_next_gen = $img_converter::core_handles_next_gen();

				if ( $should_hook_conversion || $core_handles_next_gen ) {
					add_filter( 'wp_generate_attachment_metadata', array( $img_converter, 'convert_image_to_next_gen_format' ), 10, 2 );
				}
				add_filter( 'wp_get_attachment_image_src', array( $img_converter, 'maybe_serve_next_gen_image' ) );
			}

			// Clean up placeholder data when images are deleted.
			add_action( 'delete_attachment', array( 'PerformanceOptimise\Inc\Img_Converter', 'clean_placeholder_on_delete' ) );
		}

		/**
		 * Preloads images for optimization.
		 *
		 * @since 1.0.0
		 */
		public function preload_images() {
			$preload_data = $this->get_all_preload_data();

			foreach ( $preload_data as $data ) {
				Util::generate_preload_link(
					$data['url'],
					'preload',
					'image',
					false,
					Util::get_image_mime_type( $data['url'] ),
					$data['media'] ?? '',
					$data['priority'] ?? 'high'
				);
			}
		}

		/**
		 * Post-processes the serialized buffer to inject placeholders into lazy-loaded images
		 * that have data-src but no src attribute. Called after the WP_HTML_Tag_Processor pass.
		 *
		 * @since 2.5.0
		 *
		 * @param string $buffer                  The HTML buffer after WP_HTML_Tag_Processor serialization.
		 * @param bool   $enable_placeholder      Whether placeholders are enabled.
		 * @return string The modified buffer.
		 */
		private function post_process_placeholders( string $buffer, bool $enable_placeholder ): string {
			if ( ! $enable_placeholder ) {
				return $buffer;
			}

			$result = preg_replace_callback(
				'#<img\b[^>]*\sdata-src=["\']([^"\']+)["\'][^>]*>#i',
				function ( $matches ) {
					$img_tag = $matches[0];
					if ( preg_match( '#\ssrc=#i', $img_tag ) ) {
						return $img_tag;
					}
					$data_src    = $matches[1];
					$placeholder = $this->get_placeholder_src_for_image( $img_tag, $data_src );
					if ( ! empty( $placeholder['src'] ) ) {
						$extra_attrs = '';
						foreach ( $placeholder['attrs'] as $attr_name => $attr_value ) {
							$extra_attrs .= ' ' . $attr_name . '="' . esc_attr( $attr_value ) . '"';
						}
						$replaced = preg_replace( '#<img\b#i', '<img src="' . esc_attr( $placeholder['src'] ) . '"' . $extra_attrs, $img_tag, 1 );
						return null !== $replaced ? $replaced : $img_tag;
					}
					return $img_tag;
				},
				$buffer
			);
			return null !== $result ? $result : $buffer;
		}

		/**
		 * Post-processes the serialized buffer to add missing width/height attributes to lazy-loaded images.
		 *
		 * @since 2.5.0
		 *
		 * @param string $buffer The HTML buffer after WP_HTML_Tag_Processor serialization.
		 * @return string The modified buffer.
		 */
		private function post_process_img_dimensions( string $buffer ): string {
			return preg_replace_callback(
				'#<img\b[^>]*\sdata-src=["\']([^"\']+)["\'][^>]*>#i',
				function ( $matches ) {
					$img_tag    = $matches[0];
					$data_src   = $matches[1];
					$has_width  = (bool) preg_match( '/\bwidth=["\']\d+["\']/i', $img_tag );
					$has_height = (bool) preg_match( '/\bheight=["\']\d+["\']/i', $img_tag );

					if ( ! $has_width || ! $has_height ) {
						$local_path = Util::get_local_path( $data_src );
						if ( ! empty( $local_path ) && file_exists( $local_path ) && is_readable( $local_path ) && is_file( $local_path ) ) {
							static $img_size_cache = array();
							if ( ! isset( $img_size_cache[ $local_path ] ) ) {
								if ( count( $img_size_cache ) >= 100 ) {
									array_shift( $img_size_cache );
								}
								$img_size_cache[ $local_path ] = getimagesize( $local_path );
							}
							$size = $img_size_cache[ $local_path ];
							if ( is_array( $size ) ) {
								if ( ! $has_width ) {
									$img_tag = preg_replace( '/<img\b/i', '<img width="' . (int) $size[0] . '"', $img_tag, 1 );
								}
								if ( ! $has_height ) {
									$img_tag = preg_replace( '/<img\b/i', '<img height="' . (int) $size[1] . '"', $img_tag, 1 );
								}
							}
						}
					}

					return $img_tag;
				},
				$buffer
			);
		}

		/**
		 * Processes <picture> blocks using WP_HTML_Processor for reliable block extraction with depth tracking.
		 *
		 * @since 2.5.0
		 *
		 * @param string $buffer           The HTML buffer.
		 * @param int    $img_counter      Current image counter.
		 * @param int    $exclude_img_count Number of first images to exclude.
		 * @param array  $exclude_imgs     List of image URLs to exclude.
		 * @return string The modified buffer.
		 */
		private function process_picture_blocks_processor( string $buffer, int $img_counter, int $exclude_img_count, array $exclude_imgs ): string {
			return preg_replace_callback(
				'#<picture\b[^>]*>.*?</picture>#is',
				function ( $matches ) use ( $img_counter, $exclude_img_count, $exclude_imgs ) {
					preg_match( '#<img\b[^>]*?(?:data-)?src=["\']([^"\']+)["\'][^>]*>#i', $matches[0], $img_matches );
					if ( ! empty( $img_matches ) ) {
						++$img_counter;
						if ( $exclude_img_count >= $img_counter ) {
							$exclude_imgs[] = $img_matches[1];
						}
						return $this->process_picture_tag( $matches, $img_matches[0], $img_matches[1], $exclude_imgs );
					}
					return $matches[0];
				},
				$buffer
			);
		}

		/**
		 * Processes <picture> blocks using regex fallback when WP_HTML_Processor is unavailable.
		 *
		 * @since 2.5.0
		 *
		 * @param string $buffer           The HTML buffer.
		 * @param int    $img_counter      Current image counter.
		 * @param int    $exclude_img_count Number of first images to exclude.
		 * @param array  $exclude_imgs     List of image URLs to exclude.
		 * @return string The modified buffer.
		 */
		private function process_picture_blocks_regex( string $buffer, int $img_counter, int $exclude_img_count, array $exclude_imgs ): string {
			return preg_replace_callback(
				'#<picture\b[^>]*>.*?</picture>#is',
				function ( $matches ) use ( $img_counter, $exclude_img_count, $exclude_imgs ) {
					preg_match( '#<img\b[^>]*?(?:data-)?src=["\']([^"\']+)["\'][^>]*>#i', $matches[0], $img_matches );
					if ( ! empty( $img_matches ) ) {
						++$img_counter;
						if ( $exclude_img_count >= $img_counter ) {
							$exclude_imgs[] = $img_matches[1];
						}
						return $this->process_picture_tag( $matches, $img_matches[0], $img_matches[1], $exclude_imgs );
					}
					return $matches[0];
				},
				$buffer
			);
		}

		/**
		 * Serves next-generation images if supported by the browser.
		 *
		 * @since 1.0.0
		 *
		 * @param string $buffer The HTML content buffer.
		 *
		 * @return string Modified HTML content buffer.
		 */
		public function maybe_serve_next_gen_images( $buffer ) {
			if ( ! empty( $this->options['image_optimisation']['convertImg'] ) ) {
				$conversion_format = $this->options['image_optimisation']['conversionFormat'] ?? 'webp';

				$exclude_imgs = $this->exclude_convert_imgs;

				$http_accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';

				$supports_avif = strpos( $http_accept, 'image/avif' ) !== false;
				$supports_webp = strpos( $http_accept, 'image/webp' ) !== false;

				if ( ! $supports_avif && ! $supports_webp ) {
					return $buffer;
				}

				if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
					$tags = new \WP_HTML_Tag_Processor( $buffer );

					while ( $tags->next_tag() ) {
						$tag_name = $tags->get_tag();

						if ( 'IMG' === $tag_name ) {
							$src = $tags->get_attribute( 'src' );
							if ( $src ) {
								$normalized_src = $this->normalize_url( $src );
								if ( $this->is_valid_url( $normalized_src ) ) {
									$new_src = $this->replace_image_with_next_gen( $normalized_src, $exclude_imgs, $supports_avif, $supports_webp );
									// Only write back if the URL actually changed (i.e. conversion occurred).
									if ( $new_src !== $normalized_src ) {
										$tags->set_attribute( 'src', $new_src );
									}
								}
							}
						}

						if ( 'IMG' === $tag_name || 'SOURCE' === $tag_name ) {
							$srcset = $tags->get_attribute( 'srcset' );
							if ( $srcset ) {
								$new_srcset_parts = array();
								$srcset_items     = explode( ',', $srcset );

								foreach ( $srcset_items as $srcset_item ) {
									$parts          = array_pad( preg_split( '/\s+/', trim( $srcset_item ), 2 ), 2, '' );
									$original_token = $parts[0];
									$normalized_url = $this->normalize_url( $original_token );
									$descriptor     = $parts[1];

									if ( $this->is_valid_url( $normalized_url ) ) {
										$new_url = $this->replace_image_with_next_gen( $normalized_url, $exclude_imgs, $supports_avif, $supports_webp );
										// Use the optimized URL if conversion happened, otherwise keep the original token.
										$final_url          = ( $new_url !== $normalized_url ) ? $new_url : $original_token;
										$new_srcset_parts[] = $final_url . ( $descriptor ? " $descriptor" : '' );
									} else {
										$new_srcset_parts[] = $original_token . ( $descriptor ? " $descriptor" : '' );
									}
								}

								$new_srcset = implode( ', ', $new_srcset_parts );
								if ( $new_srcset !== $srcset ) {
									$tags->set_attribute( 'srcset', $new_srcset );
								}
							}
						} elseif ( 'VIDEO' === $tag_name ) {
							$poster = $tags->get_attribute( 'poster' );
							if ( $poster ) {
								$normalized_poster = $this->normalize_url( $poster );
								if ( $this->is_valid_url( $normalized_poster ) ) {
									$new_poster = $this->replace_image_with_next_gen( $normalized_poster, $exclude_imgs, $supports_avif, $supports_webp );
									if ( $new_poster !== $normalized_poster ) {
										$tags->set_attribute( 'poster', $new_poster );
									}
								}
							}
						}
					}

					return $tags->get_updated_html();
				} else {
					// Regex Fallback (Original logic restored from git history).
					return preg_replace_callback(
						'#<img\b[^>]*((?:src|srcset)=["\'][^"\']+["\'])[^>]*>#i',
						function ( $matches ) use ( $exclude_imgs, $supports_avif, $supports_webp ) {
							$img_tag = $matches[0];

							$updated_img_tag = preg_replace_callback(
								'#src=["\']([^"\']+)["\']#i',
								function ( $src_match ) use ( $exclude_imgs, $supports_avif, $supports_webp ) {
									$url = $src_match[1];
									if ( $this->is_valid_url( $url ) ) {
										return 'src="' . $this->replace_image_with_next_gen( $src_match[1], $exclude_imgs, $supports_avif, $supports_webp ) . '"';
									}
									return $src_match[0];
								},
								$img_tag
							);

							$updated_img_tag = preg_replace_callback(
								'#srcset=["\']([^"\']+)["\']#i',
								function ( $srcset_match ) use ( $exclude_imgs, $supports_avif, $supports_webp ) {
									$srcset = $srcset_match[1];

									$new_srcset = implode(
										', ',
										array_map(
											function ( $srcset_item ) use ( $exclude_imgs, $supports_avif, $supports_webp ) {
												list($url, $descriptor) = array_pad( preg_split( '/\s+/', trim( $srcset_item ), 2 ), 2, '' );
												$new_url                = $this->replace_image_with_next_gen( $url, $exclude_imgs, $supports_avif, $supports_webp );
												return $new_url . ( $descriptor ? " $descriptor" : '' );
											},
											explode( ',', $srcset )
										)
									);

									return 'srcset="' . $new_srcset . '"';
								},
								$updated_img_tag
							);

							return $updated_img_tag;
						},
						$buffer
					);
				}
			}

			return $buffer;
		}

		/**
		 * Gets a cached instance of Img_Converter.
		 *
		 * @since 1.1.2
		 *
		 * @return Img_Converter The Img_Converter instance.
		 */
		private function get_img_converter() {
			if ( null === $this->img_converter ) {
				$this->img_converter = new Img_Converter( $this->options );
			}
			return $this->img_converter;
		}

		/**
		 * Replaces image URLs with next-generation formats.
		 *
		 * @since 1.0.0
		 *
		 * @param string  $img_url        The image URL.
		 * @param array   $exclude_imgs   Images to exclude.
		 * @param boolean $supports_avif  Whether AVIF is supported.
		 * @param boolean $supports_webp  Whether WebP is supported.
		 *
		 * @return string Updated image URL.
		 */
		private function replace_image_with_next_gen( $img_url, $exclude_imgs, $supports_avif, $supports_webp ) {
			$img_extension = pathinfo( $img_url, PATHINFO_EXTENSION );

			$img_converter     = $this->get_img_converter();
			$conversion_format = $img_converter->get_format();
			if ( 'avif' === $img_extension ) {
				return $img_url;
			}

			if ( ! empty( $exclude_imgs ) ) {
				foreach ( $exclude_imgs as $exclude_img ) {
					if ( false !== strpos( $img_url, $exclude_img ) ) {
						return $img_url;
					}
				}
			}

			$avif_img_path = $img_converter->get_img_path( $img_url, 'avif' );
			$webp_img_path = $img_converter->get_img_path( $img_url, 'webp' );

			// Cache local path lookups to avoid redundant expensive file system checks.
			$source_image_path = null;

			if ( 'avif' === $conversion_format || 'both' === $conversion_format ) {
				// Convert to AVIF if supported and not already converted.
				if ( ! file_exists( $avif_img_path ) ) {
					$source_image_path = Util::get_local_path( $img_url );

					if ( file_exists( $source_image_path ) ) {
						$img_converter->add_img_into_queue( $source_image_path, 'avif' );
					}
				}
			}

			if ( 'webp' === $conversion_format || 'both' === $conversion_format ) {
				// Convert to WebP if supported and not already converted.
				if ( ! file_exists( $webp_img_path ) ) {
					if ( null === $source_image_path ) {
						$source_image_path = Util::get_local_path( $img_url );
					}

					if ( file_exists( $source_image_path ) ) {
						$img_converter->add_img_into_queue( $source_image_path );
					}
				}
			}

			if ( ( 'avif' === $conversion_format || 'both' === $conversion_format ) && $supports_avif && file_exists( $avif_img_path ) ) {
				return $img_converter->get_img_url( $img_url, 'avif' );
			}

			if ( ( 'webp' === $conversion_format || 'both' === $conversion_format ) && $supports_webp && file_exists( $webp_img_path ) ) {
				return $img_converter->get_img_url( $img_url );
			}

			// Fallback to original image URL.
			return $img_url;
		}

		/**
		 * Determine whether a string is a syntactically valid URL.
		 *
		 * @param string $url The URL to validate.
		 * @return bool `true` if the URL is a valid URL string, `false` otherwise.
		 */
		private function is_valid_url( $url ) {
			return filter_var( $url, FILTER_VALIDATE_URL ) !== false;
		}

		/**
		 * Convert various URL forms into an absolute URL.
		 *
		 * Leaves empty strings and `data:` URLs unchanged. Handles protocol-relative (`//...`), root-relative (`/...`) and relative paths (e.g., `images/foo.jpg`, `../img.jpg`) by resolving them against the site's home URL and the current request path. Returns the original value unchanged when it is already an absolute `http...` URL.
		 *
		 * @since 1.4.0
		 * @param string $url The input URL to normalize.
		 * @return string The normalized absolute URL, or the original value for empty/data URLs.
		 */
		private function normalize_url( string $url ): string {
			if ( empty( $url ) || strpos( $url, 'data:' ) === 0 ) {
				return $url;
			}

			// Cache home_url() once per blog for the request.
			static $home_base = array();

			// Protocol-relative URLs (e.g., //example.com/image.jpg).
			if ( strpos( $url, '//' ) === 0 ) {
				static $scheme = array();
				$blog_id       = get_current_blog_id();

				if ( ! isset( $scheme[ $blog_id ] ) ) {
					$scheme[ $blog_id ] = wp_parse_url( home_url(), PHP_URL_SCHEME );
					if ( empty( $scheme[ $blog_id ] ) ) {
						$scheme[ $blog_id ] = is_ssl() ? 'https' : 'http';
					}
				}
				return $scheme[ $blog_id ] . ':' . $url;
			}

			// Root-relative paths (e.g., /wp-content/uploads/image.jpg).
			if ( strpos( $url, '/' ) === 0 ) {
				$blog_id = get_current_blog_id();

				if ( ! isset( $home_base[ $blog_id ] ) ) {
					$home_base[ $blog_id ] = home_url();
				}
				return rtrim( $home_base[ $blog_id ], '/' ) . '/' . ltrim( $url, '/' );
			}

			// True relative paths (e.g., images/photo.jpg or ../uploads/img.jpg).
			if ( strpos( $url, 'http' ) !== 0 ) {
				// Get the current URL path to resolve relative paths like ../.
				static $current_url_path = array();
				$blog_id                 = get_current_blog_id();

				if ( ! isset( $current_url_path[ $blog_id ] ) ) {
					$current_url_path[ $blog_id ] = wp_parse_url( add_query_arg( array() ), PHP_URL_PATH );
					if ( empty( $current_url_path[ $blog_id ] ) ) {
						$current_url_path[ $blog_id ] = '/';
					}
				}
				$absolute_path = $this->resolve_relative_path( $current_url_path[ $blog_id ], $url );
				if ( ! isset( $home_base[ $blog_id ] ) ) {
					$home_base[ $blog_id ] = home_url();
				}
				return rtrim( $home_base[ $blog_id ], '/' ) . '/' . ltrim( $absolute_path, '/' );
			}

			return $url;
		}

		/**
		 * Resolve a relative path against a base path and return an absolute path starting with '/'.
		 *
		 * The function treats $base_path as a file (removing its final segment) when it has no
		 * trailing slash and the last segment contains a dot. It preserves an absolute input
		 * $relative_path (one that starts with '/') and resolves '.' and '..' segments.
		 *
		 * @since 1.4.0
		 * @param string $base_path Base path to resolve against; may represent a directory (trailing slash) or a file.
		 * @param string $relative_path Relative path to resolve; if it starts with '/' it will be returned unchanged.
		 * @return string The resolved absolute path beginning with '/'.
		 */
		private function resolve_relative_path( string $base_path, string $relative_path ): string {
			if ( strpos( $relative_path, '/' ) === 0 ) {
				return $relative_path;
			}

			$has_trailing_slash = substr( $base_path, -1 ) === '/';
			$base_parts         = array_filter( explode( '/', $base_path ), 'strlen' );
			$relative_parts     = explode( '/', $relative_path );

			// If the base path is a file (no trailing slash), remove the filename.
			if ( ! $has_trailing_slash && ! empty( $base_parts ) && strpos( end( $base_parts ), '.' ) !== false ) {
				array_pop( $base_parts );
			}

			foreach ( $relative_parts as $part ) {
				if ( '.' === $part || '' === $part ) {
					continue;
				}
				if ( '..' === $part ) {
					array_pop( $base_parts );
				} else {
					$base_parts[] = $part;
				}
			}

			return '/' . implode( '/', $base_parts );
		}

		/**
		 * Retrieves all preloading data from front-page, post meta, and post types.
		 *
		 * @since 1.5.1
		 * @return array List of preload data items.
		 */
		private function get_all_preload_data(): array {
			$image_optimisation = $this->options['image_optimisation'] ?? array();

			return array_merge(
				$this->get_front_page_preload_data( $image_optimisation ),
				$this->get_auto_lcp_preload_data(),
				$this->get_meta_preload_data(),
				$this->get_post_type_preload_data( $image_optimisation )
			);
		}

		/**
		 * Retrieves auto-detected LCP image preload data from PageSpeed scan results.
		 *
		 * Checks the current page's stored LCP image URL (from PageSpeed scan) and
		 * returns a preload item if found. The toggle autoPreloadLCP must be enabled.
		 *
		 * @since 2.13.0
		 * @return array List of preload items (zero or one item).
		 */
		private function get_auto_lcp_preload_data(): array {
			$image_optimisation = $this->options['image_optimisation'] ?? array();
			if ( empty( $image_optimisation['autoPreloadLCP'] ) ) {
				return array();
			}

			$lcp_url = $this->get_current_lcp_url();
			if ( empty( $lcp_url ) ) {
				return array();
			}

			return array( $this->prepare_preload_item( $lcp_url ) );
		}

		/**
		 * Resolves the currently-detected LCP image URL for the current page.
		 *
		 * Checks mobile strategy first, then desktop, to support responsive sites
		 * that serve different images per viewport. Data sources (in order):
		 *
		 * 1. Singular post meta (`_wppo_lcp_image_url_{strategy}`).
		 * 2. Front-page option (`wppo_front_page_lcp_{strategy}`).
		 * 3. Transient keyed by strategy + current URL hash (`wppo_lcp_url_{strategy}_{md5}`).
		 *
		 * @since 2.15.0
		 * @return string The LCP image URL, or empty string when none is stored.
		 */
		private function get_current_lcp_url(): string {
			$strategies = array( 'mobile', 'desktop' );

			// Priority 1: Singular post — check post meta (mobile first, then desktop).
			if ( is_singular() ) {
				$post_id = get_the_ID();
				foreach ( $strategies as $strategy ) {
					$meta_lcp = get_post_meta( $post_id, '_wppo_lcp_image_url_' . $strategy, true );
					if ( ! empty( $meta_lcp ) && is_string( $meta_lcp ) ) {
						return $meta_lcp;
					}
				}
			}

			// Priority 2: Front page — check option (mobile first, then desktop).
			if ( is_front_page() ) {
				foreach ( $strategies as $strategy ) {
					$front_lcp = get_option( 'wppo_front_page_lcp_' . $strategy, '' );
					if ( ! empty( $front_lcp ) && is_string( $front_lcp ) ) {
						return $front_lcp;
					}
				}
			}

			// Priority 3: Check transient keyed by strategy + current URL hash.
			$current_url = untrailingslashit( esc_url_raw( Util::get_current_url() ) );
			foreach ( $strategies as $strategy ) {
				$transient = get_transient( Util::transient_key( 'wppo_lcp_url_' . $strategy . '_' . md5( $current_url ) ) );
				if ( ! empty( $transient ) && is_string( $transient ) ) {
					return $transient;
				}
			}

			return '';
		}

		/**
		 * Retrieves front page preload data if enabled.
		 *
		 * @since 1.5.1
		 * @param array $image_optimisation Image optimization configuration.
		 * @return array List of preload items for the front page.
		 */
		private function get_front_page_preload_data( array $image_optimisation ): array {
			if ( empty( $image_optimisation['preloadFrontPageImages'] ) || ! is_front_page() ) {
				return array();
			}

			$urls = $this->preload_front_page_urls;
			return array_map( array( $this, 'prepare_preload_item' ), $urls );
		}

		/**
		 * Retrieves preload data from post meta.
		 *
		 * @since 1.5.1
		 * @return array List of preload items from meta.
		 */
		private function get_meta_preload_data(): array {
			$page_img_urls = get_post_meta( get_the_ID(), '_wppo_preload_image_url', true );

			if ( empty( $page_img_urls ) ) {
				return array();
			}

			$urls = Util::process_urls( $page_img_urls );
			return array_map( array( $this, 'prepare_preload_item' ), $urls );
		}

		/**
		 * Retrieves preload data for specific post types.
		 *
		 * @since 1.5.1
		 * @param array $image_optimisation Image optimization configuration.
		 * @return array List of preload items for the post type.
		 */
		private function get_post_type_preload_data( array $image_optimisation ): array {
			if ( empty( $image_optimisation['preloadPostTypeImage'] ) ) {
				return array();
			}

			$selected_post_types = (array) ( $image_optimisation['selectedPostType'] ?? array() );

			// P1 Fix: Only proceed if post types are explicitly selected.
			if ( empty( $selected_post_types ) || ! is_singular( $selected_post_types ) || ! has_post_thumbnail() ) {
				return array();
			}

			$thumbnail_id = get_post_thumbnail_id();
			if ( ! $thumbnail_id ) {
				return array();
			}

			$exclude_img_urls = $this->exclude_post_type_imgs;
			$image_url        = $this->get_image_url_by_post_type( $thumbnail_id );

			if ( $this->should_exclude_image( $image_url, $exclude_img_urls ) ) {
				return array();
			}

			$srcset = wp_get_attachment_image_srcset( $thumbnail_id );
			return $this->get_srcset_preload_items( $srcset, $image_url, $image_optimisation );
		}

		/**
		 * Retrieves the URL of the featured image for the current post type.
		 *
		 * @since 1.0.0
		 *
		 * @param int $thumbnail_id The ID of the thumbnail image.
		 * @return string The URL of the image.
		 */
		private function get_image_url_by_post_type( int $thumbnail_id ): string {
			if ( 'product' === get_post_type() && class_exists( 'WooCommerce' ) ) {
				$image_size = apply_filters( 'woocommerce_gallery_image_size', 'woocommerce_single' );
				return wp_get_attachment_image_url( $thumbnail_id, $image_size ) ?? '';
			}

			return wp_get_attachment_image_url( $thumbnail_id, 'blog-single-image' ) ?? '';
		}

		/**
		 * Check if an image should be excluded from preloading or optimization.
		 *
		 * @since 1.0.0
		 *
		 * @param string $image_url The URL of the image.
		 * @param array  $exclude_img_urls Array of URLs to exclude.
		 * @return bool True if the image should be excluded, false otherwise.
		 */
		private function should_exclude_image( string $image_url, array $exclude_img_urls ): bool {
			foreach ( $exclude_img_urls as $url ) {
				if ( str_contains( $image_url, $url ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Parse srcset data from an image tag.
		 *
		 * @since 1.5.1
		 * @param string $srcset             The srcset string from the image tag.
		 * @param array  $image_optimisation Image optimization configuration array.
		 * @return array Array of parsed sources: array( 'url' => string, 'width' => int ).
		 */
		private function parse_srcset_data( $srcset, $image_optimisation ): array {
			if ( ! $srcset ) {
				return array();
			}

			$sources       = array_map( 'trim', explode( ',', $srcset ) );
			$max_width     = (int) ( $image_optimisation['maxWidthImgSize'] ?? self::MAX_PRELOAD_WIDTH );
			$exclude_sizes = $this->exclude_sizes;

			$parsed_sources = array();

			foreach ( $sources as $source ) {
				list( $url, $descriptor ) = array_pad( preg_split( '/\s+/', trim( $source ), 2 ), 2, '' );
				$width                    = (int) rtrim( $descriptor, 'w' );

				if ( in_array( $width, $exclude_sizes, true ) || $width > $max_width ) {
					continue;
				}

				$parsed_sources[] = array(
					'url'   => $url,
					'width' => $width,
				);
			}

			usort( $parsed_sources, fn( $a, $b ) => $a['width'] - $b['width'] );
			return $parsed_sources;
		}

		/**
		 * Retrieves preload data items from an image's srcset.
		 *
		 * @since 1.5.1
		 * @param string $srcset             The srcset string from the image tag.
		 * @param string $default_image      The fallback image URL.
		 * @param array  $image_optimisation Image optimization configuration array.
		 * @return array List of preload items.
		 */
		private function get_srcset_preload_items( $srcset, $default_image, $image_optimisation ): array {
			if ( ! $srcset ) {
				return array( $this->prepare_preload_item( $default_image ) );
			}

			$parsed_sources = $this->parse_srcset_data( $srcset, $image_optimisation );
			if ( empty( $parsed_sources ) ) {
				return array( $this->prepare_preload_item( $default_image ) );
			}

			$max_width      = (int) ( $image_optimisation['maxWidthImgSize'] ?? self::MAX_PRELOAD_WIDTH );
			$items          = array();
			$previous_width = 0;

			foreach ( $parsed_sources as $index => $source ) {
				$current_width = $source['width'];
				$next_width    = $parsed_sources[ $index + 1 ]['width'] ?? null;

				$media = "(min-width: {$previous_width}px)";
				if ( $next_width && $next_width <= $max_width ) {
					$media .= " and (max-width: {$current_width}px)";
				}

				$items[]        = array(
					'url'      => $source['url'],
					'media'    => $media,
					'priority' => 'high',
				);
				$previous_width = $current_width + 1;
			}

			return $items;
		}

		/**
		 * Prepares a URL for preloading, handling specific prefixes and resolving relative paths.
		 *
		 * @since 1.5.1
		 * @param string $img_url The original URL to prepare.
		 * @return array Structured preload item.
		 */
		private function prepare_preload_item( string $img_url ): array {
			$img_url = trim( $img_url );
			$media   = '';

			if ( 0 === strpos( $img_url, 'mobile:' ) ) {
				$img_url = trim( str_replace( 'mobile:', '', $img_url ) );
				if ( 0 !== strpos( $img_url, 'http' ) ) {
					$img_url = content_url( $img_url );
				}
				$media = '(max-width: 768px)';
			} elseif ( 0 === strpos( $img_url, 'desktop:' ) ) {
				$img_url = trim( str_replace( 'desktop:', '', $img_url ) );
				if ( 0 !== strpos( $img_url, 'http' ) ) {
					$img_url = content_url( $img_url );
				}
				$media = '(min-width: 768px)';
			} elseif ( 0 !== strpos( $img_url, 'http' ) ) {
					$img_url = content_url( $img_url );
			}

			return array(
				'url'      => $img_url,
				'media'    => $media,
				'priority' => 'high',
			);
		}

		/**
		 * Generates a preload link for a given image URL.
		 *
		 * @since 1.0.0
		 *
		 * @param string $img_url The URL of the image to preload.
		 * @return void
		 */
		public function generate_img_preload( $img_url ) {
			$data = $this->prepare_preload_item( $img_url );
			Util::generate_preload_link(
				$data['url'],
				'preload',
				'image',
				false,
				Util::get_image_mime_type( $data['url'] ),
				$data['media'] ?? '',
				$data['priority'] ?? 'high'
			);
		}

		/**
		 * Sets loading optimization attributes (fetchpriority, decoding) on a tag processor.
		 *
		 * Uses wp_get_loading_optimization_attributes() (WP 6.7+) when available,
		 * falling back to manual attribute assignment.
		 *
		 * @since 2.1.0
		 *
		 * @param \WP_HTML_Tag_Processor $tags    The tag processor instance.
		 * @param array                  $defaults Default attributes to set if core function is unavailable.
		 * @return void
		 */
		private function set_loading_optimization_attributes( $tags, array $defaults = array() ): void {
			if ( function_exists( 'wp_get_loading_optimization_attributes' ) ) {
				$tag_attr = array();
				$src      = $tags->get_attribute( 'src' );
				if ( null !== $src ) {
					$tag_attr['src'] = $src;
				}
				$width = $tags->get_attribute( 'width' );
				if ( null !== $width ) {
					$tag_attr['width'] = (int) $width;
				}
				$height = $tags->get_attribute( 'height' );
				if ( null !== $height ) {
					$tag_attr['height'] = (int) $height;
				}
				$loading = $tags->get_attribute( 'loading' );
				if ( null !== $loading ) {
					$tag_attr['loading'] = $loading;
				}
				$decoding = $tags->get_attribute( 'decoding' );
				if ( null !== $decoding ) {
					$tag_attr['decoding'] = $decoding;
				}
				$fetchpriority = $tags->get_attribute( 'fetchpriority' );
				if ( null !== $fetchpriority ) {
					$tag_attr['fetchpriority'] = $fetchpriority;
				}
				$loading_attrs = wp_get_loading_optimization_attributes(
					'img',
					$tag_attr,
					array(
						'context' => 'wp-html-tag-processor',
					)
				);
				if ( isset( $loading_attrs['loading'] ) && null === $tags->get_attribute( 'loading' ) ) {
					$tags->set_attribute( 'loading', $loading_attrs['loading'] );
				}
				if ( isset( $loading_attrs['fetchpriority'] ) && null === $tags->get_attribute( 'fetchpriority' ) ) {
					$tags->set_attribute( 'fetchpriority', $loading_attrs['fetchpriority'] );
				}
				if ( isset( $loading_attrs['decoding'] ) && null === $tags->get_attribute( 'decoding' ) ) {
					$tags->set_attribute( 'decoding', $loading_attrs['decoding'] );
				}
			}
			if ( isset( $defaults['fetchpriority'] ) && null === $tags->get_attribute( 'fetchpriority' ) ) {
				$tags->set_attribute( 'fetchpriority', $defaults['fetchpriority'] );
			}
			if ( isset( $defaults['decoding'] ) && null === $tags->get_attribute( 'decoding' ) ) {
				$tags->set_attribute( 'decoding', $defaults['decoding'] );
			}
		}

		/**
		 * Optimize an <img> tag for lazy loading, placeholders, dimensions, and performance attributes.
		 *
		 * If the image URL matches any exclusion substring, ensures the tag has `decoding="sync"` and
		 * `fetchpriority="high"` (if missing) and returns the tag unchanged otherwise. For non-excluded
		 * images, moves `src` → `data-src`, `srcset` → `data-srcset`, and `sizes` → `data-sizes`
		 * (skipping `data:image/*` sources), optionally replaces `src` with an SVG placeholder, and
		 * populates missing `width`/`height` attributes from the local file when available.
		 *
		 * @since 1.0.0
		 *
		 * @param string   $img_tag       The original <img> tag HTML.
		 * @param string   $original_src  The original value of the image `src` attribute.
		 * @param string[] $exclude_imgs Array of URL substrings; if any is found in `$original_src` the image is treated as excluded.
		 * @return string The modified <img> tag.
		 */
		public function process_img_tag( $img_tag, $original_src, $exclude_imgs ) {
			if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
				if ( ! empty( $exclude_imgs ) ) {
					foreach ( $exclude_imgs as $exclude_img ) {
						if ( false !== strpos( $original_src, $exclude_img ) ) {
							$tags = new \WP_HTML_Tag_Processor( $img_tag );
							if ( $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
								$this->set_loading_optimization_attributes(
									$tags,
									array(
										'fetchpriority' => 'high',
										'decoding'      => 'sync',
									)
								);
								return $tags->get_updated_html();
							}
							return $img_tag;
						}
					}
				}

				$use_native_lazy = ! empty( $this->options['image_optimisation']['lazyLoadNative'] );

				$tags = new \WP_HTML_Tag_Processor( $img_tag );
				if ( ! $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
					return $img_tag;
				}

				// If the image does not have 'data-src', replace 'src' with 'data-src'.
				if ( null === $tags->get_attribute( 'data-src' ) ) {
					$original_src_decoded = htmlspecialchars_decode( $original_src, ENT_QUOTES );

					// Skip base64 images to avoid rewriting them.
					if ( ! preg_match( '#^data:image/#i', $original_src_decoded ) ) {
						if ( $use_native_lazy || 'lazy' === $tags->get_attribute( 'loading' ) ) {
							// Native lazy loading or pre-existing core loading="lazy": preserve loading="lazy" and decoding="async" instead of data-src swapping.
							if ( null === $tags->get_attribute( 'loading' ) ) {
								$tags->set_attribute( 'loading', 'lazy' );
							}
							if ( null === $tags->get_attribute( 'decoding' ) ) {
								$tags->set_attribute( 'decoding', 'async' );
							}
						} else {
							$tags->set_attribute( 'data-src', $original_src_decoded );

							// WP_HTML_Tag_Processor blocks data: URIs in src for security.
							// Use regex on the serialized HTML to swap src to the placeholder.
							if ( 'none' !== $this->get_placeholder_type() ) {
								$placeholder = $this->get_placeholder_src_for_image( $img_tag, $original_src_decoded );
								if ( ! empty( $placeholder['src'] ) ) {
									$serialized = $tags->get_updated_html();
									$img_tag    = preg_replace(
										'#(?<!data-)src=(["\'])[^"\']*\1#i',
										'src="' . $placeholder['src'] . '"',
										$serialized,
										1
									);
									// Guard against null return from preg_replace (PCRE engine failure).
									if ( null === $img_tag ) {
										$img_tag = $serialized;
									}
									$tags = new \WP_HTML_Tag_Processor( $img_tag );
									$tags->next_tag( array( 'tag_name' => 'img' ) );
									// Add extra placeholder data attributes.
									foreach ( $placeholder['attrs'] as $attr_name => $attr_value ) {
										$tags->set_attribute( $attr_name, $attr_value );
									}
									$img_tag = $tags->get_updated_html();
									$tags    = new \WP_HTML_Tag_Processor( $img_tag );
									$tags->next_tag( array( 'tag_name' => 'img' ) );
								} else {
									$tags->remove_attribute( 'src' );
								}
							} else {
								$tags->remove_attribute( 'src' );
							}

							// Replace 'srcset' with 'data-srcset'.
							$srcset = $tags->get_attribute( 'srcset' );
							if ( $srcset ) {
								$tags->set_attribute( 'data-srcset', $srcset );
								$tags->remove_attribute( 'srcset' );
							}

							// Replace 'sizes' with 'data-sizes'.
							$sizes = $tags->get_attribute( 'sizes' );
							if ( $sizes ) {
								$tags->set_attribute( 'data-sizes', $sizes );
								$tags->remove_attribute( 'sizes' );
							}
						}
					}
				}

				// Add missing width and height attributes if possible.
				$has_width  = null !== $tags->get_attribute( 'width' );
				$has_height = null !== $tags->get_attribute( 'height' );

				if ( ! $has_width || ! $has_height ) {
					$local_path = Util::get_local_path( $original_src );

					if ( ! empty( $local_path ) && file_exists( $local_path ) && is_readable( $local_path ) && is_file( $local_path ) ) {
						static $img_size_cache = array();

						if ( ! isset( $img_size_cache[ $local_path ] ) ) {
							if ( count( $img_size_cache ) >= 100 ) {
								array_shift( $img_size_cache );
							}
							$img_size_cache[ $local_path ] = getimagesize( $local_path );
						}

						$size = $img_size_cache[ $local_path ];

						if ( is_array( $size ) ) {
							if ( ! $has_width ) {
								$tags->set_attribute( 'width', (int) $size[0] );
							}
							if ( ! $has_height ) {
								$tags->set_attribute( 'height', (int) $size[1] );
							}
						}
					}
				}

				return $tags->get_updated_html();
			} else {
				// Regex Fallback (Original logic restored from git history).
				if ( ! empty( $exclude_imgs ) ) {
					foreach ( $exclude_imgs as $exclude_img ) {
						if ( false !== strpos( $original_src, $exclude_img ) ) {
							if ( function_exists( 'wp_get_loading_optimization_attributes' ) ) {
								$tag_attr = array( 'src' => $original_src );
								if ( preg_match( '/\bwidth=(["\'])(\d+)\1/i', $img_tag, $m ) ) {
									$tag_attr['width'] = (int) $m[2];
								}
								if ( preg_match( '/\bheight=(["\'])(\d+)\1/i', $img_tag, $m ) ) {
									$tag_attr['height'] = (int) $m[2];
								}
								if ( preg_match( '/\bloading=(["\'])([^"\']+)\1/i', $img_tag, $m ) ) {
									$tag_attr['loading'] = $m[2];
								}
								if ( preg_match( '/\bdecoding=(["\'])([^"\']+)\1/i', $img_tag, $m ) ) {
									$tag_attr['decoding'] = $m[2];
								}
								if ( preg_match( '/\bfetchpriority=(["\'])([^"\']+)\1/i', $img_tag, $m ) ) {
									$tag_attr['fetchpriority'] = $m[2];
								}
								$loading_attrs = wp_get_loading_optimization_attributes( 'img', $tag_attr, array( 'context' => 'regex-fallback' ) );
								if ( isset( $loading_attrs['loading'] ) && false === strpos( $img_tag, 'loading' ) ) {
									$img_tag = preg_replace( '#<img\b([^>]*?)#i', '<img $1 loading="' . esc_attr( $loading_attrs['loading'] ) . '"', $img_tag );
								}
								if ( isset( $loading_attrs['decoding'] ) && false === strpos( $img_tag, 'decoding' ) ) {
									$img_tag = preg_replace( '#<img\b([^>]*?)#i', '<img $1 decoding="' . esc_attr( $loading_attrs['decoding'] ) . '"', $img_tag );
								}
								if ( isset( $loading_attrs['fetchpriority'] ) && false === strpos( $img_tag, 'fetchpriority' ) ) {
									$img_tag = preg_replace( '#<img\b([^>]*?)#i', '<img $1 fetchpriority="' . esc_attr( $loading_attrs['fetchpriority'] ) . '"', $img_tag );
								}
							} else {
								if ( strpos( $img_tag, 'decoding' ) === false ) {
									$img_tag = preg_replace( '#<img\b([^>]*?)#i', '<img $1 decoding="sync"', $img_tag );
								}

								if ( false === strpos( $img_tag, 'fetchpriority' ) ) {
									$img_tag = preg_replace( '#<img\b([^>]*?)#i', '<img $1 fetchpriority="high"', $img_tag );
								}
							}

							return $img_tag;
						}
					}
				}

				$use_native_lazy = ! empty( $this->options['image_optimisation']['lazyLoadNative'] );

				// If the image does not have 'data-src', replace 'src' with 'data-src'.
				if ( strpos( $img_tag, 'data-src' ) === false ) {
					$original_src_decoded = htmlspecialchars_decode( $original_src, ENT_QUOTES );

					// Skip base64 images to avoid rewriting them.
					if ( preg_match( '#^data:image/#i', $original_src_decoded ) ) {
						return $img_tag;
					}

					if ( $use_native_lazy || 1 === preg_match( '/\bloading=["\']lazy["\']/i', $img_tag ) ) {
						if ( false === stripos( $img_tag, 'loading=' ) ) {
							$img_tag = preg_replace( '#<img\b#i', '<img loading="lazy"', $img_tag );
						}
						if ( false === stripos( $img_tag, 'decoding=' ) ) {
							$img_tag = preg_replace( '#<img\b#i', '<img decoding="async"', $img_tag );
						}
					} else {
						$replaced_tag = preg_replace_callback(
							'#src=["\']([^"\']+)["\']#i',
							function () use ( $original_src_decoded ) {
								return 'data-src="' . esc_attr( $original_src_decoded ) . '"';
							},
							$img_tag
						);
						// Guard against null return from preg_replace_callback (PCRE engine failure).
						if ( null !== $replaced_tag ) {
							$img_tag = $replaced_tag;
						}

						// Replace with placeholder if the option is enabled.
						if ( 'none' !== $this->get_placeholder_type() ) {
							$placeholder = $this->get_placeholder_src_for_image( $img_tag, $original_src_decoded );
							if ( ! empty( $placeholder['src'] ) ) {
								$replaced_placeholder = preg_replace_callback(
									'#<img\b([^>]*)#i',
									function ( $matches ) use ( $placeholder ) {
										$extra_attrs = '';
										foreach ( $placeholder['attrs'] as $attr_name => $attr_value ) {
											$extra_attrs .= ' ' . $attr_name . '="' . esc_attr( $attr_value ) . '"';
										}
										return '<img src="' . esc_attr( $placeholder['src'] ) . '"' . $extra_attrs . $matches[1];
									},
									$img_tag
								);
								if ( null !== $replaced_placeholder ) {
									$img_tag = $replaced_placeholder;
								}
							}
						}

						// Replace 'srcset' with 'data-srcset' if 'srcset' is present.
						if ( preg_match( '#srcset=["\']([^"\']+)["\']#i', $img_tag, $srcset_matches ) ) {
							$img_tag = preg_replace(
								'#srcset=["\']([^"\']+)["\']#i',
								'data-srcset="' . esc_attr( $srcset_matches[1] ) . '"',
								$img_tag
							);
						}

						// Replace 'sizes' with 'data-sizes' if 'sizes' is present.
						if ( preg_match( '#\bsizes=["\']([^"\']+)["\']#i', $img_tag, $sizes_matches ) ) {
							$img_tag = preg_replace(
								'#\bsizes=["\']([^"\']+)["\']#i',
								'data-sizes="' . esc_attr( $sizes_matches[1] ) . '"',
								$img_tag
							);
						}
					}
				}

				// Add missing width and height attributes if possible.
				$has_width  = (bool) preg_match( '/\bwidth=["\']\d+["\']/i', $img_tag );
				$has_height = (bool) preg_match( '/\bheight=["\']\d+["\']/i', $img_tag );

				if ( ! $has_width || ! $has_height ) {
					$local_path = Util::get_local_path( $original_src );

					if ( ! empty( $local_path ) && file_exists( $local_path ) && is_readable( $local_path ) && is_file( $local_path ) ) {
						$size = getimagesize( $local_path );

						if ( is_array( $size ) ) {
							if ( ! $has_width ) {
								$img_tag = preg_replace( '/<img\b/i', '<img width="' . (int) $size[0] . '"', $img_tag );
							}
							if ( ! $has_height ) {
								$img_tag = preg_replace( '/<img\b/i', '<img height="' . (int) $size[1] . '"', $img_tag );
							}
						}
					}
				}

				return $img_tag;
			}
		}

		/**
		 * Extract the YouTube video ID from an iframe src URL.
		 *
		 * @since 2.5.0
		 *
		 * @param string $src The iframe src URL.
		 * @return string The video ID, or empty string if not a YouTube embed.
		 */
		private function get_youtube_video_id( string $src ): string {
			if ( preg_match( '#(?:youtube(?:-nocookie)?\.com/embed/|youtu\.be/)([a-zA-Z0-9_-]{11})#i', $src, $m ) ) {
				return $m[1];
			}
			return '';
		}

		/**
		 * Generate a lightweight video placeholder HTML for a YouTube iframe.
		 *
		 * Replaces the YouTube embed iframe with a static thumbnail and play button.
		 * The actual iframe is loaded only on user click via JavaScript.
		 *
		 * @since 2.5.0
		 *
		 * @param string $iframe_tag   The original <iframe> tag HTML.
		 * @param string $original_src The original src attribute value.
		 * @param string $video_id     Optional pre-extracted YouTube video ID.
		 * @return string The placeholder HTML or the original iframe tag if excluded.
		 */
		private function generate_video_placeholder( string $iframe_tag, string $original_src, string $video_id = '' ): string {
			if ( ! empty( $this->exclude_lazy_videos ) ) {
				foreach ( $this->exclude_lazy_videos as $exclude_video ) {
					if ( false !== strpos( $original_src, $exclude_video ) ) {
						return $iframe_tag;
					}
				}
			}

			$allowed = apply_filters( 'wppo_video_placeholder_allowed', true, $original_src, $iframe_tag );
			if ( ! $allowed ) {
				return $iframe_tag;
			}

			if ( empty( $video_id ) ) {
				$video_id = $this->get_youtube_video_id( $original_src );
			}

			if ( empty( $video_id ) ) {
				return $iframe_tag;
			}

			$video_type             = false !== strpos( $original_src, 'youtube-nocookie.com' ) ? 'youtube-nocookie' : 'youtube';
			$thumbnail_url          = 'https://img.youtube.com/vi/' . $video_id . '/maxresdefault.jpg';
			$fallback_thumbnail_url = 'https://img.youtube.com/vi/' . $video_id . '/hqdefault.jpg';
			$noscript_iframe        = '<noscript>' . $iframe_tag . '</noscript>';

			$play_button = '<button type="button" class="wppo-video-play-btn" aria-label="' . esc_attr__( 'Play video', 'performance-optimisation' ) . '">
				<svg aria-hidden="true" focusable="false" width="68" height="48" viewBox="0 0 68 48">
					<path class="wppo-play-btn-bg" d="M66.52,7.74c-0.78-2.93-2.49-5.41-5.42-6.19C55.79,.13,34,0,34,0S12.21,.13,6.9,1.55 C3.97,2.33,2.27,4.81,1.48,7.74C0.06,13.05,0,24,0,24s0.06,10.95,1.48,16.26c0.78,2.93,2.49,5.41,5.42,6.19 C12.21,47.87,34,48,34,48s21.79-.13,27.1-1.55c2.93-.78,4.64-3.26,5.42-6.19C67.94,34.95,68,24,68,24S67.94,13.05,66.52,7.74z" fill="#f00"></path>
					<path d="M 45,24 27,14 27,34" fill="#fff"></path>
				</svg>
			</button>';

			$play_button = apply_filters( 'wppo_video_play_button_html', $play_button, $video_id, $video_type );

			$attrs_to_store = array( 'id', 'class', 'width', 'height', 'sandbox', 'referrerpolicy', 'title', 'style', 'name', 'frameborder', 'allow', 'allowfullscreen' );
			$stored_attrs   = array();

			if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
				$tags = new \WP_HTML_Tag_Processor( $iframe_tag );
				if ( $tags->next_tag( array( 'tag_name' => 'iframe' ) ) ) {
					foreach ( $attrs_to_store as $attr ) {
						$val = $tags->get_attribute( $attr );
						if ( null !== $val ) {
							$stored_attrs[ $attr ] = $val;
						}
					}
				}
			}
			$attrs_json = ! empty( $stored_attrs ) ? wp_json_encode( $stored_attrs ) : '';

			$placeholder_html = '<div class="wppo-video-placeholder" data-wppo-video-src="' . esc_url( $original_src ) . '" data-wppo-video-type="' . esc_attr( $video_type ) . '"' . ( $attrs_json ? ' data-wppo-iframe-attrs="' . esc_attr( $attrs_json ) . '"' : '' ) . '>
				' . $noscript_iframe . '
				<picture>
					<img src="' . esc_url( $thumbnail_url ) . '" alt="' . esc_attr__( 'Video thumbnail', 'performance-optimisation' ) . '" loading="lazy" data-wppo-fallback="' . esc_url( $fallback_thumbnail_url ) . '">
				</picture>
				' . $play_button . '
			</div>';

			return apply_filters( 'wppo_video_placeholder_html', $placeholder_html, $video_id, $video_type, $thumbnail_url );
		}

		/**
		 * Prepare an <iframe> tag for lazy loading and exclusion-aware optimization.
		 *
		 * If the iframe's source matches any exclusion substring, the tag is returned unchanged.
		 * Otherwise the function moves `src` to `data-src`, removes the `src` attribute, and ensures
		 * the `wppo-lazyload` class is present. Uses WP_HTML_Tag_Processor when available and
		 * falls back to regex-based attribute manipulation.
		 *
		 * @since 1.0.0
		 *
		 * @param string   $iframe_tag   The original `<iframe>` tag HTML.
		 * @param string   $original_src The original `src` attribute value (absolute or relative URL).
		 * @param string[] $exclude_imgs List of substrings; if any appear in `$original_src` the tag is left unchanged.
		 * @return string The modified `<iframe>` tag HTML.
		 */
		public function process_iframe_tag( $iframe_tag, $original_src, $exclude_imgs ) {
			if ( ! empty( $exclude_imgs ) ) {
				foreach ( $exclude_imgs as $exclude_img ) {
					if ( false !== strpos( $original_src, $exclude_img ) ) {
						return $iframe_tag;
					}
				}
			}

			$allowed = apply_filters( 'wppo_lazyload_iframe_allowed', true, $original_src, $iframe_tag );
			if ( ! $allowed ) {
				return $iframe_tag;
			}
			if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
				$tags = new \WP_HTML_Tag_Processor( $iframe_tag );
				if ( $tags->next_tag( array( 'tag_name' => 'iframe' ) ) ) {
					$tags->set_attribute( 'data-src', $original_src );
					$tags->remove_attribute( 'src' );
					$tags->add_class( 'wppo-lazyload' );
					$iframe_tag = $tags->get_updated_html();
				}
			} else {
				// Regex fallback.
				// Replace src with data-src using regex to handle cases where src might be the first attribute or have different spacing.
				$iframe_tag = preg_replace( '/\bsrc=["\']([^"\']+)["\']/i', 'data-src="$1"', $iframe_tag );

				if ( preg_match( '/class=["\']([^"\']+)["\']/', $iframe_tag, $class_matches ) ) {
					$iframe_tag = str_replace( $class_matches[0], 'class="' . $class_matches[1] . ' wppo-lazyload"', $iframe_tag );
				} else {
					$iframe_tag = preg_replace( '/<iframe\b/i', '<iframe class="wppo-lazyload"', $iframe_tag );
				}
			}

			return $iframe_tag;
		}

		/**
		 * Wraps an image in a <picture> element or updates an existing <picture> by adding appropriate <source>
		 * attributes for optimized delivery and lazy-loading based on current options and exclusions.
		 *
		 * Processes the provided image tag (or the <img> inside an existing <picture>) and returns the resulting
		 * HTML fragment. Honors the configured wrapInPicture option and skips adding <source> descriptors when
		 * the image URL matches any entry in the exclusion list.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $matches      Regex match array containing the matched <img> or <picture> fragment.
		 * @param string $img_tag      The original <img> tag to process.
		 * @param string $original_src The original src attribute value of the image.
		 * @param array  $exclude_imgs List of URL substrings; if any is present in the image URL, source descriptors are not added.
		 * @return string The processed <picture> or <img> HTML fragment (or the original fragment if unchanged).
		 */
		public function process_picture_tag( $matches, $img_tag, $original_src, $exclude_imgs ) {
			$should_exclude = false;
			foreach ( $exclude_imgs as $exclude_img ) {
				if ( false !== strpos( $original_src, $exclude_img ) ) {
					$should_exclude = true;
					break;
				}
			}

			if ( class_exists( 'WP_HTML_Processor' ) ) {
				$wpp = new \WP_HTML_Processor( $matches[0] );
				if ( null === $wpp->get_last_error() && $wpp->next_tag( array( 'tag_name' => 'picture' ) ) ) {
					$depth = $wpp->get_current_depth();

					// First pass: collect srcset/sizes from inner <img>.
					$inner_img_srcset = null;
					$inner_img_sizes  = null;
					$inner_img_lazy   = false;

					while ( $wpp->next_tag() ) {
						if ( $wpp->get_current_depth() <= $depth ) {
							break;
						}
						if ( 'IMG' === $wpp->get_tag() && ! $wpp->is_tag_closer() ) {
							$inner_img_srcset = $wpp->get_attribute( 'data-srcset' ) ?? $wpp->get_attribute( 'srcset' );
							$inner_img_sizes  = $wpp->get_attribute( 'data-sizes' ) ?? $wpp->get_attribute( 'sizes' );
							$inner_img_lazy   = null !== $wpp->get_attribute( 'data-src' );
						}
					}

					// Second pass: modify <source> attributes.
					$wpp = new \WP_HTML_Processor( $matches[0] );
					$wpp->next_tag( array( 'tag_name' => 'picture' ) );
					$depth = $wpp->get_current_depth();

					while ( $wpp->next_tag() ) {
						if ( $wpp->get_current_depth() <= $depth ) {
							break;
						}
						if ( 'SOURCE' === $wpp->get_tag() && ! $wpp->is_tag_closer() ) {
							$wpp->set_attribute( 'type', Util::get_image_mime_type( $original_src ) );
							if ( ! $should_exclude ) {
								if ( $inner_img_srcset ) {
									$wpp->set_attribute( $inner_img_lazy ? 'data-srcset' : 'srcset', $inner_img_srcset );
								}
								if ( $inner_img_sizes ) {
									$wpp->set_attribute( $inner_img_lazy ? 'data-sizes' : 'sizes', $inner_img_sizes );
								}
							}
						}
					}

					// Process the <img> inside the picture.
					$updated_html = $wpp->get_updated_html();

					if ( preg_match( '#<img\b[^>]*>#i', $matches[0], $img_matches ) ) {
						$img_tag    = $img_matches[0];
						$tags_check = new \WP_HTML_Tag_Processor( $img_tag );

						if ( $tags_check->next_tag( array( 'tag_name' => 'img' ) ) && null !== $tags_check->get_attribute( 'data-src' ) ) {
							if ( 'none' !== $this->get_placeholder_type() ) {
								$original_src = $tags_check->get_attribute( 'data-src' ) ?? '';
								$placeholder  = $this->get_placeholder_src_for_image( $img_tag, htmlspecialchars_decode( $original_src, ENT_QUOTES ) );
								if ( ! empty( $placeholder['src'] ) ) {
									$updated_tags = new \WP_HTML_Tag_Processor( $updated_html );
									if ( $updated_tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
										$updated_tags->set_attribute( 'src', $placeholder['src'] );
										foreach ( $placeholder['attrs'] as $attr_name => $attr_value ) {
											$updated_tags->set_attribute( $attr_name, $attr_value );
										}
										return $updated_tags->get_updated_html();
									}
								}
							}
							return $updated_html;
						}

						$tags_src = new \WP_HTML_Tag_Processor( $img_tag );
						if ( $tags_src->next_tag( array( 'tag_name' => 'img' ) ) ) {
							$src_val = $tags_src->get_attribute( 'src' );
							if ( $src_val ) {
								$original_src = $src_val;
							}
						}
						$processed_img = $this->process_img_tag( $img_tag, $original_src, $exclude_imgs );
						return preg_replace_callback(
							'#<img\b[^>]*>#i',
							function () use ( $processed_img ) {
								return $processed_img;
							},
							$updated_html,
							1
						);
					}
					return $updated_html;
				}
				// Fall through to WP_HTML_Tag_Processor on bail or non-picture case.
			}
			if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
				if ( ! preg_match( '#<picture\b[^>]*>.*?</picture>#is', $matches[0] ) ) {

					$img_tag = $this->process_img_tag( $img_tag, $original_src, $exclude_imgs );

					if ( ! isset( $this->options['image_optimisation']['wrapInPicture'] ) || (bool) $this->options['image_optimisation']['wrapInPicture'] ) {
						$tags = new \WP_HTML_Tag_Processor( $img_tag );
						if ( $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
							$srcset = $tags->get_attribute( 'data-srcset' ) ?? $tags->get_attribute( 'srcset' );
							$sizes  = $tags->get_attribute( 'data-sizes' ) ?? $tags->get_attribute( 'sizes' );

							$is_lazy     = null !== $tags->get_attribute( 'data-src' );
							$srcset_attr = $is_lazy ? 'data-srcset' : 'srcset';
							$sizes_attr  = $is_lazy ? 'data-sizes' : 'sizes';
							$source_tag  = '<source type="' . Util::get_image_mime_type( $original_src ) . '"';

							if ( ! $should_exclude ) {
								if ( ! empty( $srcset ) || ! empty( $sizes ) ) {
									if ( ! empty( $srcset ) ) {
										$source_tag .= ' ' . $srcset_attr . '="' . esc_attr( $srcset ) . '"';
									}

									if ( ! empty( $sizes ) ) {
										$source_tag .= ' ' . $sizes_attr . '="' . esc_attr( $sizes ) . '"';
									}
									$source_tag .= '>';
								} else {
									$source_tag .= ' ' . $srcset_attr . '="' . esc_attr( $original_src ) . '">';
								}
							} else {
								$source_tag .= '>';
							}

							// Hybrid wrapping: Processed <img> tag is wrapped inside <picture>.
							$img_tag = '<picture>' . $source_tag . $img_tag . '</picture>';
						}
					}
					return $img_tag;
				} elseif ( preg_match( '#<img\b[^>]*>#i', $matches[0], $img_matches ) ) {
					// Existing <picture> tag: find the <img> inside and process it.
					$img_tag    = $img_matches[0];
					$tags_check = new \WP_HTML_Tag_Processor( $img_tag );

					if ( $tags_check->next_tag( array( 'tag_name' => 'img' ) ) && null !== $tags_check->get_attribute( 'data-src' ) ) {
						// Already lazy-loaded — only inject placeholder if src is missing.
						if ( 'none' !== $this->get_placeholder_type() ) {
							$original_src = $tags_check->get_attribute( 'data-src' ) ?? '';
							$placeholder  = $this->get_placeholder_src_for_image( $img_tag, htmlspecialchars_decode( $original_src, ENT_QUOTES ) );
							if ( ! empty( $placeholder['src'] ) ) {
								$tags_write = new \WP_HTML_Tag_Processor( $img_tag );
								if ( $tags_write->next_tag( array( 'tag_name' => 'img' ) ) && null === $tags_write->get_attribute( 'src' ) ) {
									$tags_write->set_attribute( 'src', $placeholder['src'] );
									foreach ( $placeholder['attrs'] as $attr_name => $attr_value ) {
										$tags_write->set_attribute( $attr_name, $attr_value );
									}
									return str_replace( $img_tag, $tags_write->get_updated_html(), $matches[0] );
								}
							}
						}
						return $matches[0];
					}

					// img still has src — extract it and run full processing.
					$tags_src = new \WP_HTML_Tag_Processor( $img_tag );
					if ( $tags_src->next_tag( array( 'tag_name' => 'img' ) ) ) {
						$src_val = $tags_src->get_attribute( 'src' );
						if ( $src_val ) {
							$original_src = $src_val;
						}
					}
					$processed_img = $this->process_img_tag( $img_tag, $original_src, $exclude_imgs );

					return str_replace( $img_tag, $processed_img, $matches[0] );
				}

				return $matches[0];
			} else {
				// Regex Fallback (Original logic restored from git history).
				if ( ! preg_match( '#<picture\b[^>]*>.*?</picture>#is', $matches[0] ) ) {

					$img_tag = $this->process_img_tag( $img_tag, $original_src, $exclude_imgs );

					if ( ! isset( $this->options['image_optimisation']['wrapInPicture'] ) || (bool) $this->options['image_optimisation']['wrapInPicture'] ) {
						$srcset = '';
						if ( preg_match( '#\b(?:data-)?srcset=["\']([^"\']+)["\']#i', $img_tag, $srcset_matches ) ) {
							$srcset = $srcset_matches[1];
						}

						$sizes = '';
						if ( preg_match( '#\b(?:data-)?sizes=["\']([^"\']+)["\']#i', $img_tag, $sizes_matches ) ) {
							$sizes = $sizes_matches[1];
						}

						$is_lazy     = (bool) strpos( $img_tag, 'data-src' );
						$srcset_attr = $is_lazy ? 'data-srcset' : 'srcset';
						$sizes_attr  = $is_lazy ? 'data-sizes' : 'sizes';
						$source_tag  = '<source type="' . Util::get_image_mime_type( $original_src ) . '"';

						if ( ! $should_exclude ) {
							if ( ! empty( $srcset ) || ! empty( $sizes ) ) {
								if ( ! empty( $srcset ) ) {
									$source_tag .= ' ' . $srcset_attr . '="' . esc_attr( $srcset ) . '"';
								}

								if ( ! empty( $sizes ) ) {
									$source_tag .= ' ' . $sizes_attr . '="' . esc_attr( $sizes ) . '"';
								}
								$source_tag .= '>';
							} else {
								$source_tag .= ' ' . $srcset_attr . '="' . esc_attr( $original_src ) . '">';
							}
						} else {
							$source_tag .= '>';
						}

						// Wrap <img> tag inside <picture>.
						$img_tag = '<picture>' . $source_tag . $img_tag . '</picture>';
					}
					return $img_tag;
				} else {
					preg_match( '#<img\b([^>]*?)src=["\']([^"\']+)["\'][^>]*>#i', $matches[0], $img_matches );
					if ( ! empty( $img_matches ) ) {
						$img_tag      = $img_matches[0];
						$original_src = $img_matches[2];
						$img_tag      = $this->process_img_tag( $img_tag, $original_src, $exclude_imgs );

						return preg_replace( '#<img\b[^>]*?>#i', $img_tag, $matches[0] );
					}
				}

				return $matches[0];
			}
		}

		/**
		 * Post-render LCP image prioritization (optional enhancement).
		 *
		 * When the "prioritizeLCPImages" toggle is enabled, this filter callback
		 * runs on the finalized HTML (WP 6.9+ template-enhancement output buffer,
		 * or the legacy outermost output buffer on older WP) and:
		 *
		 * 1. Removes `loading="lazy"` from the first N images (matching the
		 *    `excludeFirstImages` heuristic) so above-the-fold images load eagerly.
		 * 2. Sets `fetchpriority="high"` on the detected LCP <img> unless the
		 *    attribute already exists, preserving core's own loading-optimization
		 *    decisions and the plugin's existing excludeFirstImages handling.
		 *
		 * Uses `WP_HTML_Processor::serialize_token()` (public since WP 6.9) when
		 * available, falling back to `WP_HTML_Tag_Processor` on older versions.
		 *
		 * @since 2.15.0
		 *
		 * @param string $filtered_output The filtered output from previous callbacks.
		 * @param string $output          The raw output buffer content (unused; present
		 *                                for parity with the 6.9 filter signature and
		 *                                safe when used as an ob_start callback).
		 * @return string The processed buffer.
		 */
		public function prioritize_lcp_in_buffer( $filtered_output, $output = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			$image_optimisation = $this->options['image_optimisation'] ?? array();
			if ( empty( $image_optimisation['prioritizeLCPImages'] ) ) {
				return $filtered_output;
			}
			if ( is_admin() || empty( $filtered_output ) || ! is_string( $filtered_output ) ) {
				return $filtered_output;
			}
			// Same logged-in eligibility guard as the legacy buffer path so both
			// pipelines behave identically for the current user.
			if ( ! Util::is_cache_eligible_for_current_user( $this->options['cache_settings'] ?? array() ) ) {
				return $filtered_output;
			}
			if ( ! class_exists( 'WP_HTML_Tag_Processor' ) || false === strpos( $filtered_output, '<img' ) ) {
				return $filtered_output;
			}

			// Pass A: un-lazy-load the first N above-the-fold images.
			$buffer = $this->unlazyload_first_images( $filtered_output, $image_optimisation );

			// Pass B: stamp fetchpriority="high" on the detected LCP image.
			$buffer = $this->prioritize_lcp_image( $buffer );

			return $buffer;
		}

		/**
		 * Remove loading="lazy" from the first N images in the buffer.
		 *
		 * Mirrors the excludeFirstImages heuristic used by add_delay_load_img() so
		 * the same count semantics apply to the finalized HTML. Only <img> tags
		 * carrying a `src` are counted (matching add_delay_load_img()); only the
		 * `loading` attribute is stripped; JS-lazy images keep their data-* attributes.
		 *
		 * @since 2.15.0
		 *
		 * @param string $buffer             The HTML buffer.
		 * @param array  $image_optimisation Image optimization settings.
		 * @return string The buffer with loading="lazy" removed from the first N images.
		 */
		private function unlazyload_first_images( string $buffer, array $image_optimisation ): string {
			$exclude_img_count = (int) ( $image_optimisation['excludeFirstImages'] ?? 0 );
			if ( $exclude_img_count <= 0 ) {
				return $buffer;
			}

			$tags        = new \WP_HTML_Tag_Processor( $buffer );
			$img_counter = 0;
			$changed     = false;

			while ( $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
				$src = $tags->get_attribute( 'src' );
				if ( null === $src ) {
					continue;
				}

				++$img_counter;
				if ( $exclude_img_count >= $img_counter && 'lazy' === $tags->get_attribute( 'loading' ) ) {
					$tags->remove_attribute( 'loading' );
					$changed = true;
				}
			}

			return $changed ? $tags->get_updated_html() : $buffer;
		}

		/**
		 * Set fetchpriority="high" on the detected LCP image.
		 *
		 * Resolves the current LCP URL via get_current_lcp_url() and stamps
		 * `fetchpriority="high"` on the matching <img> only when no fetchpriority
		 * attribute already exists, so core's wp_get_loading_optimization_attributes()
		 * output and the plugin's existing excludeFirstImages high-priority assignment
		 * are never double-applied. The matched LCP image is also un-lazy-loaded so an
		 * in-viewport LCP image is actually fetched eagerly at high priority.
		 *
		 * @since 2.15.0
		 *
		 * @param string $buffer The HTML buffer.
		 * @return string The buffer with fetchpriority="high" on the LCP image.
		 */
		private function prioritize_lcp_image( string $buffer ): string {
			$lcp_url = $this->get_current_lcp_url();
			if ( empty( $lcp_url ) || false === strpos( $buffer, '<img' ) ) {
				return $buffer;
			}

			// WP 6.9+: stream tokens with WP_HTML_Processor and rebuild via serialize_token().
			if ( method_exists( 'WP_HTML_Processor', 'serialize_token' ) && version_compare( get_bloginfo( 'version' ), '6.9', '>=' ) ) {
				$processor = \WP_HTML_Processor::create_full_parser( $buffer );
				if ( null !== $processor ) {
					$new_html = '';
					$stamped  = false;
					while ( $processor->next_token() ) {
						if (
							'#tag' === $processor->get_token_type() &&
							'IMG' === $processor->get_tag() &&
							! $processor->is_tag_closer() &&
							! $stamped &&
							$this->tag_matches_lcp_url( $processor, $lcp_url )
						) {
							if ( null === $processor->get_attribute( 'fetchpriority' ) ) {
								$processor->set_attribute( 'fetchpriority', 'high' );
							}
							if ( 'lazy' === $processor->get_attribute( 'loading' ) ) {
								$processor->remove_attribute( 'loading' );
							}
							$stamped = true;
						}
						$new_html .= $processor->serialize_token();
					}

					// Parser bailed on unsupported markup; leave the buffer unchanged.
					if ( null === $processor->get_last_error() ) {
						return $stamped ? $new_html : $buffer;
					}
				}
			}

			// Fallback: WP_HTML_Tag_Processor on all supported versions.
			$tags    = new \WP_HTML_Tag_Processor( $buffer );
			$stamped = false;
			while ( $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
				if ( $this->tag_matches_lcp_url( $tags, $lcp_url ) ) {
					if ( null === $tags->get_attribute( 'fetchpriority' ) ) {
						$tags->set_attribute( 'fetchpriority', 'high' );
					}
					if ( 'lazy' === $tags->get_attribute( 'loading' ) ) {
						$tags->remove_attribute( 'loading' );
					}
					$stamped = true;
					break;
				}
			}

			return $stamped ? $tags->get_updated_html() : $buffer;
		}

		/**
		 * Whether the matched image tag references the given LCP URL.
		 *
		 * Checks src, data-src (JS-lazy placeholder), and srcset attributes. Both
		 * sides are normalized (scheme-relative/relative URLs resolved against
		 * home_url(), query strings and WordPress size suffixes stripped) so that
		 * absolute-vs-relative matches work and derived assets cannot false-positive.
		 *
		 * @since 2.15.0
		 *
		 * @param \WP_HTML_Tag_Processor $tags    The tag processor matched on an <img>.
		 * @param string                 $lcp_url The detected LCP image URL.
		 * @return bool True if the image references the LCP URL.
		 */
		private function tag_matches_lcp_url( $tags, string $lcp_url ): bool {
			$normalized_lcp = $this->normalize_image_url( $lcp_url );
			if ( '' === $normalized_lcp ) {
				return false;
			}

			foreach ( array( 'src', 'data-src', 'srcset' ) as $attribute ) {
				$value = $tags->get_attribute( $attribute );
				if ( ! is_string( $value ) || '' === $value ) {
					continue;
				}

				if ( 'srcset' === $attribute ) {
					foreach ( preg_split( '/\s*,\s*/', trim( $value ) ) as $candidate ) {
						$candidate_url = preg_split( '/\s+/', trim( $candidate ), 2 )[0];
						if ( '' !== $candidate_url && $this->normalize_image_url( $candidate_url ) === $normalized_lcp ) {
							return true;
						}
					}
					continue;
				}

				if ( $this->normalize_image_url( $value ) === $normalized_lcp ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Normalize an image URL for LCP matching.
		 *
		 * Resolves protocol-relative and root-relative URLs against home_url(),
		 * drops the scheme and any query string, and strips WordPress generated
		 * size suffixes (-NNNxNNN, -scaled, -eNNN) so derived assets are treated
		 * as the same image as their full-size original.
		 *
		 * @since 2.15.0
		 *
		 * @param string $url The raw URL to normalize.
		 * @return string Normalized host + path, or an empty string when unparseable.
		 */
		private function normalize_image_url( string $url ): string {
			$url = trim( $url );

			if ( '' === $url ) {
				return '';
			}

			// Protocol-relative URL.
			if ( 0 === strpos( $url, '//' ) ) {
				$url = 'https:' . $url;
			}

			// Root-relative or bare path — resolve against the site URL.
			if ( 0 === strpos( $url, '/' ) || false === strpos( $url, '://' ) ) {
				$url = untrailingslashit( home_url() ) . '/' . ltrim( $url, '/' );
			}

			$parts = wp_parse_url( $url );
			if ( empty( $parts['path'] ) ) {
				return '';
			}

			$host = strtolower( $parts['host'] ?? '' );
			$path = $parts['path'];

			// Strip WordPress size suffixes, e.g. -1024x1024, -scaled, -e1234567890123.
			$path = (string) preg_replace( '#-(?:\d+x\d+|scaled|e\d+)(?=\.[A-Za-z0-9]+)$#', '', $path );

			return $host . $path;
		}

		/**
		 * Transforms <picture>, <img>, and <iframe> elements in the provided HTML to enable lazy loading and delayed loading based on the image_optimisation options.
		 *
		 * Applies exclusions derived from the options (including preload-selected images and the first N images specified by `excludeFirstImages`) and rewrites matched tags to use data-* attributes and lazy classes when appropriate.
		 * YouTube embed iframes are replaced with lightweight video placeholders when the feature is enabled.
		 *
		 * @since 1.0.0
		 *
		 * @param string $buffer The HTML buffer to process.
		 * @return string The modified HTML buffer with lazy-load and delay-load attributes applied.
		 */
		public function add_delay_load_img( $buffer ) {
			$image_optimisation = $this->options['image_optimisation'] ?? array();
			$exclude_img_count  = $image_optimisation['excludeFirstImages'] ?? 0;
			$exclude_imgs       = array();

			$enable_video_placeholder = ! empty( $image_optimisation['enableVideoPlaceholder'] );
			$lazy_load_videos_active  = ! empty( $image_optimisation['lazyLoadVideos'] );

			if ( $enable_video_placeholder && $lazy_load_videos_active ) {
				$buffer = preg_replace_callback(
					'#<iframe\b([^>]*?)src=["\']([^"\']+)["\'][^>]*>\s*</iframe>#is',
					function ( $matches ) {
						$video_id = $this->get_youtube_video_id( $matches[2] );
						if ( $video_id ) {
							return $this->generate_video_placeholder( $matches[0], $matches[2], $video_id );
						}
						return $matches[0];
					},
					$buffer
				);
			}

			$noscript_tokens = array();
			$buffer          = preg_replace_callback(
				'#<noscript>.*?</noscript>#is',
				function ( $m ) use ( &$noscript_tokens ) {
					$token                     = "\x00WPPO_NOSCRIPT_" . count( $noscript_tokens ) . "\x00";
					$noscript_tokens[ $token ] = $m[0];
					return $token;
				},
				$buffer
			);

			if ( ! empty( $image_optimisation['lazyLoadImages'] ) ) {
				$exclude_imgs = $this->exclude_lazy_imgs;

				$preload_img_urls = $this->get_preload_images_urls();
				$exclude_imgs     = array_unique( array_merge( $exclude_imgs, $preload_img_urls ) );

				$img_counter = 0;

				$use_native_lazy    = ! empty( $image_optimisation['lazyLoadNative'] );
				$enable_placeholder = 'none' !== ( $image_optimisation['placeholderType'] ?? 'none' );

				if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
					$wppo_tags = new \WP_HTML_Tag_Processor( $buffer );

					while ( $wppo_tags->next_tag() ) {
						$tag_name = $wppo_tags->get_tag();

						if ( 'IMG' === $tag_name ) {
							$src = $wppo_tags->get_attribute( 'src' );
							if ( null === $src ) {
								continue;
							}

							++$img_counter;
							if ( $exclude_img_count >= $img_counter ) {
								$exclude_imgs[] = $src;
							}

							$should_exclude = false;
							foreach ( $exclude_imgs as $exclude_img ) {
								if ( false !== strpos( $src, $exclude_img ) ) {
									$should_exclude = true;
									break;
								}
							}

							if ( $should_exclude ) {
								$this->set_loading_optimization_attributes(
									$wppo_tags,
									array(
										'fetchpriority' => 'high',
										'decoding'      => 'sync',
									)
								);
								continue;
							}

							if ( null !== $wppo_tags->get_attribute( 'data-src' ) ) {
								continue;
							}

							$original_src_decoded = htmlspecialchars_decode( $src, ENT_QUOTES );

							if ( preg_match( '#^data:image/#i', $original_src_decoded ) ) {
								continue;
							}

							if ( $use_native_lazy || 'lazy' === $wppo_tags->get_attribute( 'loading' ) ) {
								if ( null === $wppo_tags->get_attribute( 'loading' ) ) {
									$wppo_tags->set_attribute( 'loading', 'lazy' );
								}
								if ( null === $wppo_tags->get_attribute( 'decoding' ) ) {
									$wppo_tags->set_attribute( 'decoding', 'async' );
								}
							} else {
								$wppo_tags->set_attribute( 'data-src', $original_src_decoded );
								$wppo_tags->remove_attribute( 'src' );

								$srcset = $wppo_tags->get_attribute( 'srcset' );
								if ( $srcset ) {
									$wppo_tags->set_attribute( 'data-srcset', $srcset );
									$wppo_tags->remove_attribute( 'srcset' );
								}

								$sizes = $wppo_tags->get_attribute( 'sizes' );
								if ( $sizes ) {
									$wppo_tags->set_attribute( 'data-sizes', $sizes );
									$wppo_tags->remove_attribute( 'sizes' );
								}
							}
						} elseif ( 'IFRAME' === $tag_name ) {
							$src = $wppo_tags->get_attribute( 'src' );
							if ( null === $src ) {
								continue;
							}

							$should_exclude = false;
							foreach ( $exclude_imgs as $exclude_img ) {
								if ( false !== strpos( $src, $exclude_img ) ) {
									$should_exclude = true;
									break;
								}
							}

							if ( $should_exclude ) {
								continue;
							}

							$allowed = apply_filters( 'wppo_lazyload_iframe_allowed', true, $src, '' );
							if ( ! $allowed ) {
								continue;
							}

							$wppo_tags->set_attribute( 'data-src', $src );
							$wppo_tags->remove_attribute( 'src' );
							$wppo_tags->add_class( 'wppo-lazyload' );
						}
					}

					$buffer = $wppo_tags->get_updated_html();

					$buffer = $this->post_process_placeholders( $buffer, $enable_placeholder );
					$buffer = $this->post_process_img_dimensions( $buffer );

					if ( class_exists( 'WP_HTML_Processor' ) ) {
						$buffer = $this->process_picture_blocks_processor( $buffer, $img_counter, $exclude_img_count, $exclude_imgs );
					} else {
						$buffer = $this->process_picture_blocks_regex( $buffer, $img_counter, $exclude_img_count, $exclude_imgs );
					}
				} else {
					$buffer = preg_replace_callback(
						'#<picture\b[^>]*>.*?</picture>|<img\b([^>]*?)src=["\']([^"\']+)["\'][^>]*>|<iframe\b([^>]*?)src=["\']([^"\']+)["\'][^>]*>#is',
						function ( $matches ) use ( &$img_counter, $exclude_img_count, &$exclude_imgs ) {
							if ( 5 === count( $matches ) ) {
								return $this->process_iframe_tag( $matches[0], $matches[4], $exclude_imgs );
							}

							++$img_counter;

							if ( $exclude_img_count >= $img_counter ) {
								$exclude_imgs[] = $matches[2];
							}

							if ( isset( $matches[0] ) ) {
								if ( preg_match( '#<picture\b[^>]*>.*?</picture>#is', $matches[0] ) ) {
									return $this->process_picture_tag( $matches, $matches[0], $matches[2], $exclude_imgs );
								} else {
									$img_tag      = $matches[0];
									$original_src = $matches[2];
									return $this->process_picture_tag( $matches, $img_tag, $original_src, $exclude_imgs );
								}
							}

							return $matches[0];
						},
						$buffer
					);
				}
			}

			$buffer = strtr( $buffer, $noscript_tokens );
			return $buffer;
		}

		/**
		 * Retrieves URLs of images to preload for lazy-load exclusion.
		 *
		 * @since 1.0.0
		 * @return array List of preload image URLs.
		 */
		private function get_preload_images_urls(): array {
			$preload_data = $this->get_all_preload_data();
			return array_unique( array_column( $preload_data, 'url' ) );
		}

		/**
		 * Generates a base64-encoded SVG image with the given width and height.
		 *
		 * @since 1.0.0
		 *
		 * @param string $img_attributes The image's attributes (including width and height).
		 * @param string $color          Optional hex fill color. Default '#cfd4db'.
		 * @return string The base64-encoded SVG.
		 */
		private function generate_svg_base64( $img_attributes, $color = '#cfd4db' ) {
			// Match both quoted (width="59") and unquoted (width=59) attribute formats.
			preg_match( '/\bwidth=["\']?(\d+)["\']?/i', $img_attributes, $width_matches );
			preg_match( '/\bheight=["\']?(\d+)["\']?/i', $img_attributes, $height_matches );

			$width  = isset( $width_matches[1] ) ? absint( $width_matches[1] ) : 100;
			$height = isset( $height_matches[1] ) ? absint( $height_matches[1] ) : 100;

			$svg_content = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '"><rect width="100%" height="100%" fill="' . esc_attr( $color ) . '" /></svg>';

			// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			return 'data:image/svg+xml;base64,' . base64_encode( $svg_content );
			// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		/**
		 * Get the current placeholder type.
		 *
		 * @since 3.0.0
		 *
		 * @return string One of 'none', 'svg', 'dominant_color', 'lqip'.
		 */
		private function get_placeholder_type(): string {
			$type    = $this->options['image_optimisation']['placeholderType'] ?? 'none';
			$allowed = array( 'none', 'svg', 'dominant_color', 'lqip' );
			return in_array( $type, $allowed, true ) ? $type : 'none';
		}

		/**
		 * Get the appropriate placeholder src and extra attributes for a lazy-loaded image.
		 *
		 * Looks up stored placeholder data (dominant color, LQIP) from Img_Converter's
		 * image info by resolving the data-src URL to a local path.
		 *
		 * @since 3.0.0
		 *
		 * @param string $img_tag  The <img> tag HTML.
		 * @param string $data_src The data-src URL of the image.
		 * @return array{src: string, attrs: array<string, string>} Placeholder src and extra attributes.
		 */
		private function get_placeholder_src_for_image( string $img_tag, string $data_src ): array {
			static $placeholder_cache = null;
			static $path_cache        = array();

			$result = array(
				'src'   => '',
				'attrs' => array(),
			);

			$placeholder_type = $this->get_placeholder_type();

			if ( 'none' === $placeholder_type ) {
				return $result;
			}

			// Resolve data-src to a local path key for looking up placeholder data.
			$rel_path = '';
			if ( ! isset( $path_cache[ $data_src ] ) ) {
				$local_path = Util::get_local_path( $data_src );
				if ( ! empty( $local_path ) ) {
					$path_cache[ $data_src ] = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $local_path ) );
				} else {
					$path_cache[ $data_src ] = '';
				}
			}
			$rel_path = $path_cache[ $data_src ];

			// Load placeholder data from the shared wppo_img_info option.
			if ( null === $placeholder_cache ) {
				$placeholder_cache = Img_Converter::get_placeholder_info();
			}

			if ( 'svg' === $placeholder_type ) {
				$result['src'] = $this->generate_svg_base64( $img_tag );
				return $result;
			}

			if ( 'dominant_color' === $placeholder_type ) {
				$dominant_color = $placeholder_cache['dominant_color'][ $rel_path ] ?? '';
				if ( ! empty( $dominant_color ) && preg_match( '/^#[a-f0-9]{6}$/i', $dominant_color ) ) {
					$result['src']                               = 'data:image/svg+xml;charset=UTF-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%221%22%20height%3D%221%22%2F%3E';
					$result['attrs']['data-wppo-dominant-color'] = $dominant_color;
				} else {
					$result['src'] = $this->generate_svg_base64( $img_tag );
				}
				return $result;
			}

			if ( 'lqip' === $placeholder_type ) {
				$lqip = $placeholder_cache['lqip'][ $rel_path ] ?? '';
				if ( ! empty( $lqip ) ) {
					$result['src']                     = $lqip;
					$result['attrs']['data-wppo-lqip'] = '1';
				} else {
					$result['src'] = $this->generate_svg_base64( $img_tag );
				}
				return $result;
			}

			return $result;
		}

		/**
		 * Rewrites <video> elements so their media sources are deferred and restored later for lazy loading.
		 *
		 * Skips videos whose attributes or inner markup match configured exclusion patterns. For processed videos:
		 * - moves `src` attributes to `data-src` (on <video> and inner <source> tags),
		 * - removes `autoplay` and sets `data-wppo-autoplay="1"` when autoplay was present,
		 * - ensures `preload="none"` is set,
		 * - adds the `wppo-lazy-video` class.
		 *
		 * @since 1.2.4
		 *
		 * @param string $buffer HTML markup to process.
		 * @return string The HTML with video elements rewritten for lazy loading.
		 */
		public function lazy_load_videos( string $buffer ): string {
			$image_opts = $this->options['image_optimisation'] ?? array();

			if ( empty( $image_opts['lazyLoadVideos'] ) ) {
				return $buffer;
			}

			$exclude_videos = $this->exclude_lazy_videos;

			if ( class_exists( 'WP_HTML_Processor' ) ) {
				$all_processed = true;
				$wpp_result    = preg_replace_callback(
					'#<video\b([^>]*)>(.*?)</video>#is',
					function ( $matches ) use ( $exclude_videos, &$all_processed ) {
						$full_tag   = $matches[0];
						$attributes = $matches[1];
						$inner_html = $matches[2];

						// Check exclusions.
						foreach ( $exclude_videos as $exclude ) {
							if ( false !== strpos( $attributes, $exclude ) || false !== strpos( $inner_html, $exclude ) ) {
								return $full_tag;
							}
						}

						$p = new \WP_HTML_Processor( $full_tag );
						if ( null === $p->get_last_error() && $p->next_tag( array( 'tag_name' => 'video' ) ) ) {
							$src = $p->get_attribute( 'src' );
							if ( $src ) {
								$p->set_attribute( 'data-src', $src );
								$p->remove_attribute( 'src' );
							}
							if ( null !== $p->get_attribute( 'autoplay' ) ) {
								$p->remove_attribute( 'autoplay' );
								$p->set_attribute( 'data-wppo-autoplay', '1' );
							}
							$p->set_attribute( 'preload', 'none' );
							$p->add_class( 'wppo-lazy-video' );

							while ( $p->next_tag( array( 'tag_name' => 'source' ) ) ) {
								$src = $p->get_attribute( 'src' );
								if ( $src ) {
									$p->set_attribute( 'data-src', $src );
									$p->remove_attribute( 'src' );
								}
							}
							return $p->get_updated_html();
						}

						$all_processed = false;
						return $full_tag;
					},
					$buffer
				);

				if ( $all_processed ) {
					return $wpp_result;
				}
				// Partial bail — use partially-processed buffer as input to TagProcessor fallback.
				$buffer = $wpp_result;
			}
			if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
				return preg_replace_callback(
					'#<video\b([^>]*)>(.*?)</video>#is',
					function ( $matches ) use ( $exclude_videos ) {
						$attributes = $matches[1];
						$inner_html = $matches[2];
						$full_tag   = $matches[0];

						// Check exclusions.
						foreach ( $exclude_videos as $exclude ) {
							if ( false !== strpos( $attributes, $exclude ) || false !== strpos( $inner_html, $exclude ) ) {
								return $full_tag;
							}
						}

						$tags = new \WP_HTML_Tag_Processor( $full_tag );

						// Process <video> tag.
						if ( $tags->next_tag( array( 'tag_name' => 'video' ) ) ) {
							$src = $tags->get_attribute( 'src' );
							if ( $src ) {
								$tags->set_attribute( 'data-src', $src );
								$tags->remove_attribute( 'src' );
							}

							if ( $tags->get_attribute( 'autoplay' ) !== null ) {
								$tags->remove_attribute( 'autoplay' );
								$tags->set_attribute( 'data-wppo-autoplay', '1' );
							}

							$tags->set_attribute( 'preload', 'none' );
							$tags->add_class( 'wppo-lazy-video' );
						}

						// Process <source> tags inside.
						while ( $tags->next_tag( array( 'tag_name' => 'source' ) ) ) {
							$src = $tags->get_attribute( 'src' );
							if ( $src ) {
								$tags->set_attribute( 'data-src', $src );
								$tags->remove_attribute( 'src' );
							}
						}

						return $tags->get_updated_html();
					},
					$buffer
				);
			} else {
				// Regex Fallback (Original logic restored from git history).
				return preg_replace_callback(
					'#<video\b([^>]*)>(.*?)</video>#is',
					function ( $matches ) use ( $exclude_videos ) {
						$attributes = $matches[1];
						$inner_html = $matches[2];

						// Check exclusions against src or inner <source> tags.
						foreach ( $exclude_videos as $exclude ) {
							if ( false !== strpos( $attributes, $exclude ) || false !== strpos( $inner_html, $exclude ) ) {
								return $matches[0];
							}
						}

						// Process <video src="..."> attribute.
						if ( preg_match( '#\bsrc=["\']([^"\']+)["\']#i', $attributes ) ) {
							$attributes = preg_replace( '#\bsrc=["\']([^"\']+)["\']#i', 'data-src="$1"', $attributes );
						}

						// Process inner <source src="..."> tags.
						$inner_html = preg_replace( '#(<source\b[^>]*)\bsrc=["\']([^"\']+)["\']#i', '$1 data-src="$2"', $inner_html );

						$had_autoplay = preg_match( '#\bautoplay\b#i', $attributes );

						// Remove autoplay to prevent the browser from trying to play immediately.
						$attributes = preg_replace( '#\bautoplay(=["\'][^"\']*["\'])?#i', '', $attributes );

						if ( $had_autoplay ) {
							$attributes .= ' data-wppo-autoplay="1"';
						}

						// Add preload="none" if not already present.
						if ( false === stripos( $attributes, 'preload' ) ) {
							$attributes .= ' preload="none"';
						} else {
							$attributes = preg_replace( '#\bpreload=["\'][^"\']*["\']#i', 'preload="none"', $attributes );
						}

						// Add a marker class for the IntersectionObserver.
						if ( false === strpos( $attributes, 'wppo-lazy-video' ) ) {
							if ( preg_match( '#\bclass=["\']([^"\']*)["\']#i', $attributes, $class_matches ) ) {
								$attributes = str_replace( $class_matches[0], 'class="' . $class_matches[1] . ' wppo-lazy-video"', $attributes );
							} else {
								$attributes .= ' class="wppo-lazy-video"';
							}
						}

						return "<video $attributes>$inner_html</video>";
					},
					$buffer
				);
			}
		}
	}
}
