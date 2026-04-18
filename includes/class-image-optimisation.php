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
		 * Configuration options for image optimization.
		 *
		 * @var array
		 * @since 1.0.0
		 */
		private array $options;

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

			$this->setup_hooks();
		}

		/**
		 * Sets up hooks for image optimization features.
		 *
		 * @since 1.0.0
		 */
		private function setup_hooks() {
			if ( isset( $this->options['image_optimisation']['convertImg'] ) && (bool) $this->options['image_optimisation']['convertImg'] ) {
				$img_converter = $this->get_img_converter();

				add_filter( 'wp_generate_attachment_metadata', array( $img_converter, 'convert_image_to_next_gen_format' ), 10, 2 );
				add_filter( 'wp_get_attachment_image_src', array( $img_converter, 'maybe_serve_next_gen_image' ) );
			}
		}

		/**
		 * Preloads images for optimization.
		 *
		 * @since 1.0.0
		 */
		public function preload_images() {
			$image_optimisation = $this->options['image_optimisation'] ?? array();

			$this->preload_front_page_images( $image_optimisation );
			$this->preload_meta_images();
			$this->preload_post_type_images( $image_optimisation );
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
			if ( isset( $this->options['image_optimisation']['convertImg'] ) && (bool) $this->options['image_optimisation']['convertImg'] ) {
				$conversion_format = $this->options['image_optimisation']['conversionFormat'] ?? 'webp';

				$exclude_imgs = Util::process_urls( $this->options['image_optimisation']['excludeConvertImages'] ?? array() );

				$http_accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';

				$supports_avif = strpos( $http_accept, 'image/avif' ) !== false;
				$supports_webp = strpos( $http_accept, 'image/webp' ) !== false;

				if ( ! $supports_avif && ! $supports_webp ) {
					return $buffer;
				}

				if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
					$tags = new \WP_HTML_Tag_Processor( $buffer );

					while ( $tags->next_tag() ) {
						if ( 'IMG' !== $tags->get_tag() ) {
							continue;
						}

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
				require_once WPPO_PLUGIN_PATH . 'includes/class-img-converter.php';
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

			$conversion_format = $this->options['image_optimisation']['conversionFormat'] ?? 'webp';
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

			$img_converter = $this->get_img_converter();

			$avif_img_path = $img_converter->get_img_path( $img_url, 'avif' );
			$webp_img_path = $img_converter->get_img_path( $img_url, 'webp' );

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
					$source_image_path = Util::get_local_path( $img_url );

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

			// Protocol-relative URLs (e.g., //example.com/image.jpg).
			if ( strpos( $url, '//' ) === 0 ) {
				$scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );
				if ( empty( $scheme ) ) {
					$scheme = is_ssl() ? 'https' : 'http';
				}
				return $scheme . ':' . $url;
			}

			// Root-relative paths (e.g., /wp-content/uploads/image.jpg).
			if ( strpos( $url, '/' ) === 0 ) {
				return home_url( $url );
			}

			// True relative paths (e.g., images/photo.jpg or ../uploads/img.jpg).
			if ( strpos( $url, 'http' ) !== 0 ) {
				// Get the current URL path to resolve relative paths like ../.
				$current_url_path = wp_parse_url( add_query_arg( array() ), PHP_URL_PATH );
				if ( empty( $current_url_path ) ) {
					$current_url_path = '/';
				}
				$absolute_path = $this->resolve_relative_path( $current_url_path, $url );
				return home_url( $absolute_path );
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
		 * Preloads front page images based on the provided optimization settings.
		 *
		 * @since 1.0.0
		 *
		 * @param array $image_optimisation Image optimization configuration array.
		 * @return void
		 */
		private function preload_front_page_images( $image_optimisation ) {
			if ( empty( $image_optimisation['preloadFrontPageImages'] ) || ! is_front_page() ) {
				return;
			}

			$preload_img_urls = Util::process_urls( $image_optimisation['preloadFrontPageImagesUrls'] ?? array() );

			foreach ( $preload_img_urls as $img_url ) {
				$this->generate_img_preload( $img_url );
			}
		}

		/**
		 * Preloads meta images stored in post meta.
		 *
		 * @since 1.0.0
		 *
		 * @return void
		 */
		private function preload_meta_images() {
			$page_img_urls = get_post_meta( get_the_ID(), '_wppo_preload_image_url', true );

			if ( ! empty( $page_img_urls ) ) {
				foreach ( Util::process_urls( $page_img_urls ) as $img_url ) {
					$this->generate_img_preload( $img_url );
				}
			}
		}

		/**
		 * Preloads images for specific post types if applicable.
		 *
		 * @since 1.0.0
		 *
		 * @param array $image_optimisation Image optimization configuration array.
		 * @return void
		 */
		private function preload_post_type_images( $image_optimisation ) {
			if ( empty( $image_optimisation['preloadPostTypeImage'] ) ) {
				return;
			}

			$selected_post_types = (array) ( $image_optimisation['selectedPostType'] ?? array() );

			if ( ! is_singular( $selected_post_types ) || ! has_post_thumbnail() ) {
				return;
			}

			$thumbnail_id = get_post_thumbnail_id();
			if ( ! $thumbnail_id ) {
				return;
			}

			$exclude_img_urls = Util::process_urls( $image_optimisation['excludePostTypeImgUrl'] ?? array() );
			$image_url        = $this->get_image_url_by_post_type( $thumbnail_id );

			if ( $this->should_exclude_image( $image_url, $exclude_img_urls ) ) {
				return;
			}

			$srcset = wp_get_attachment_image_srcset( $thumbnail_id );
			$this->process_srcset_for_preload( $srcset, $image_url, $image_optimisation );
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
		 * Extract candidate URLs from a srcset string and schedule them for preloading.
		 *
		 * @since 1.0.0
		 *
		 * @param string $srcset The srcset string from the image tag.
		 * @param string $default_image The fallback image URL.
		 * @param array  $image_optimisation Image optimization configuration array.
		 * @return void
		 */
		private function process_srcset_for_preload( $srcset, $default_image, $image_optimisation ) {
			if ( ! $srcset ) {
				$this->generate_img_preload( $default_image );
				return;
			}

			$sources       = array_map( 'trim', explode( ',', $srcset ) );
			$max_width     = (int) ( $image_optimisation['maxWidthImgSize'] ?? 1478 );
			$exclude_sizes = array_map( 'absint', Util::process_urls( $image_optimisation['excludeSize'] ?? array() ) );

			$parsed_sources = array();

			foreach ( $sources as $source ) {
				list($url, $descriptor) = array_map( 'trim', explode( ' ', $source ) );
				$width                  = (int) rtrim( $descriptor, 'w' );

				if ( in_array( $width, $exclude_sizes, true ) || $width > $max_width ) {
					continue;
				}

				$parsed_sources[] = array(
					'url'   => $url,
					'width' => $width,
				);
			}

			usort( $parsed_sources, fn( $a, $b ) => $a['width'] - $b['width'] );

			$this->generate_media_preloads( $parsed_sources, $max_width );
		}

		/**
		 * Generate preload links for a set of parsed image sources.
		 *
		 * @since 1.0.0
		 *
		 * @param array $parsed_sources Array of parsed image sources.
		 * @param int   $max_width Maximum allowed width for preloading.
		 * @return void
		 */
		private function generate_media_preloads( $parsed_sources, $max_width ) {
			$previous_width = 0;

			foreach ( $parsed_sources as $index => $source ) {
				$current_width = $source['width'];
				$next_width    = $parsed_sources[ $index + 1 ]['width'] ?? null;

				$media = "(min-width: {$previous_width}px)";
				if ( $next_width && $next_width <= $max_width ) {
					$media .= " and (max-width: {$current_width}px)";
				}

				Util::generate_preload_link( $source['url'], 'preload', 'image', false, Util::get_image_mime_type( $source['url'] ), $media, 'high' );
				$previous_width = $current_width + 1;
			}
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
			if ( 0 === strpos( $img_url, 'mobile:' ) ) {
				$mobile_url = trim( str_replace( 'mobile:', '', $img_url ) );

				if ( 0 !== strpos( $img_url, 'http' ) ) {
					$mobile_url = content_url( $mobile_url );
				}

				Util::generate_preload_link( $mobile_url, 'preload', 'image', false, Util::get_image_mime_type( $mobile_url ), '(max-width: 768px)', 'high' );
			} elseif ( 0 === strpos( $img_url, 'desktop:' ) ) {
				$desktop_url = trim( str_replace( 'desktop:', '', $img_url ) );

				if ( 0 !== strpos( $img_url, 'http' ) ) {
					$desktop_url = content_url( $desktop_url );
				}

				Util::generate_preload_link( $desktop_url, 'preload', 'image', false, Util::get_image_mime_type( $desktop_url ), '(min-width: 768px)', 'high' );
			} else {
				$img_url = trim( $img_url );

				if ( 0 !== strpos( $img_url, 'http' ) ) {
					$img_url = content_url( $img_url );
				}

				Util::generate_preload_link( $img_url, 'preload', 'image', false, Util::get_image_mime_type( $img_url ), '', 'high' );
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
								if ( null === $tags->get_attribute( 'decoding' ) ) {
									$tags->set_attribute( 'decoding', 'sync' );
								}
								if ( null === $tags->get_attribute( 'fetchpriority' ) ) {
									$tags->set_attribute( 'fetchpriority', 'high' );
								}
								return $tags->get_updated_html();
							}
							return $img_tag;
						}
					}
				}

				$tags = new \WP_HTML_Tag_Processor( $img_tag );
				if ( ! $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
					return $img_tag;
				}

				// If the image does not have 'data-src', replace 'src' with 'data-src'.
				if ( null === $tags->get_attribute( 'data-src' ) ) {
					$original_src_decoded = htmlspecialchars_decode( $original_src, ENT_QUOTES );

					// Skip base64 images to avoid rewriting them.
					if ( ! preg_match( '#^data:image/#i', $original_src_decoded ) ) {
						$tags->set_attribute( 'data-src', $original_src_decoded );

						// WP_HTML_Tag_Processor blocks data: URIs in src for security.
						// Use regex on the serialized HTML to swap src to the SVG placeholder.
						if ( isset( $this->options['image_optimisation']['replacePlaceholderWithSVG'] ) && (bool) $this->options['image_optimisation']['replacePlaceholderWithSVG'] ) {
							$new_placeholder_src = $this->generate_svg_base64( $img_tag );
							if ( ! empty( $new_placeholder_src ) ) {
								$img_tag = preg_replace(
									'#(?<!data-)src=(["\'])[^"\']*\1#i',
									'src="' . $new_placeholder_src . '"',
									$tags->get_updated_html(),
									1
								);
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

				// Add missing width and height attributes if possible.
				$has_width  = null !== $tags->get_attribute( 'width' );
				$has_height = null !== $tags->get_attribute( 'height' );

				if ( ! $has_width || ! $has_height ) {
					$local_path = Util::get_local_path( $original_src );

					if ( ! empty( $local_path ) && file_exists( $local_path ) && is_readable( $local_path ) && is_file( $local_path ) ) {
						static $img_size_cache = array();

						if ( ! isset( $img_size_cache[ $local_path ] ) ) {
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
							if ( strpos( $img_tag, 'decoding' ) === false ) {
								$img_tag = preg_replace( '#<img\b([^>]*?)#i', '<img $1 decoding="sync"', $img_tag );
							}

							if ( false === strpos( $img_tag, 'fetchpriority' ) ) {
								$img_tag = preg_replace( '#<img\b([^>]*?)#i', '<img $1 fetchpriority="high"', $img_tag );
							}

							return $img_tag;
						}
					}
				}

				// If the image does not have 'data-src', replace 'src' with 'data-src'.
				if ( strpos( $img_tag, 'data-src' ) === false ) {
					$original_src_decoded = htmlspecialchars_decode( $original_src, ENT_QUOTES );

					// Skip base64 images to avoid rewriting them.
					if ( preg_match( '#^data:image/#i', $original_src_decoded ) ) {
						return $img_tag;
					}

					$img_tag = preg_replace_callback(
						'#src=["\']([^"\']+)["\']#i',
						function () use ( $original_src_decoded ) {
							return 'data-src="' . esc_attr( $original_src_decoded ) . '"';
						},
						$img_tag
					);

					// Replace with SVG placeholder if the option is enabled.
					if ( isset( $this->options['image_optimisation']['replacePlaceholderWithSVG'] ) && (bool) $this->options['image_optimisation']['replacePlaceholderWithSVG'] ) {
						$new_src = $this->generate_svg_base64( $img_tag );
						if ( ! empty( $new_src ) ) {
							$img_tag = preg_replace_callback(
								'#<img\b([^>]*)#i',
								function ( $matches ) use ( $new_src ) {
									return '<img src="' . esc_attr( $new_src ) . '"' . $matches[1];
								},
								$img_tag
							);
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
			foreach ( $exclude_imgs as $exclude_img ) {
				if ( false !== strpos( $original_src, $exclude_img ) ) {
					return $iframe_tag;
				}
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
		 * @param array  $matches       Regex match array containing the matched <img> or <picture> fragment.
		 * @param string $img_tag       The original <img> tag to process.
		 * @param string $original_src  The original src attribute value of the image.
		 * @param array  $exclude_imgs  List of URL substrings; if any is present in the image URL, source descriptors are not added.
		 * @return string The processed <picture> or <img> HTML fragment (or the original fragment if unchanged).
		 */
		public function process_picture_tag( $matches, $img_tag, $original_src, $exclude_imgs ) {
			if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
				if ( ! preg_match( '#<picture\b[^>]*>.*?</picture>#is', $matches[0] ) ) {

					$img_tag = $this->process_img_tag( $img_tag, $original_src, $exclude_imgs );

					if ( ! isset( $this->options['image_optimisation']['wrapInPicture'] ) || (bool) $this->options['image_optimisation']['wrapInPicture'] ) {
						$tags = new \WP_HTML_Tag_Processor( $img_tag );
						if ( $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
							$srcset = $tags->get_attribute( 'data-srcset' ) ?? $tags->get_attribute( 'srcset' );
							$sizes  = $tags->get_attribute( 'data-sizes' ) ?? $tags->get_attribute( 'sizes' );

							$is_lazy        = null !== $tags->get_attribute( 'data-src' );
							$srcset_attr    = $is_lazy ? 'data-srcset' : 'srcset';
							$sizes_attr     = $is_lazy ? 'data-sizes' : 'sizes';
							$source_tag     = '<source type="' . Util::get_image_mime_type( $original_src ) . '"';
							$should_exclude = false;

							foreach ( $exclude_imgs as $exclude_img ) {
								if ( false !== strpos( $original_src, $exclude_img ) ) {
									$should_exclude = true;
									break;
								}
							}

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
						// Already lazy-loaded — only inject SVG placeholder if src is missing.
						if (
							isset( $this->options['image_optimisation']['replacePlaceholderWithSVG'] ) &&
							(bool) $this->options['image_optimisation']['replacePlaceholderWithSVG']
						) {
							$tags_write = new \WP_HTML_Tag_Processor( $img_tag );
							if ( $tags_write->next_tag( array( 'tag_name' => 'img' ) ) && null === $tags_write->get_attribute( 'src' ) ) {
								$svg_src = $this->generate_svg_base64( $img_tag );
								if ( ! empty( $svg_src ) ) {
									$tags_write->set_attribute( 'src', $svg_src );
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

						$is_lazy        = (bool) strpos( $img_tag, 'data-src' );
						$srcset_attr    = $is_lazy ? 'data-srcset' : 'srcset';
						$sizes_attr     = $is_lazy ? 'data-sizes' : 'sizes';
						$source_tag     = '<source type="' . Util::get_image_mime_type( $original_src ) . '"';
						$should_exclude = false;

						foreach ( $exclude_imgs as $exclude_img ) {
							if ( false !== strpos( $original_src, $exclude_img ) ) {
								$should_exclude = true;
								break;
							}
						}

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
		 * Transforms <picture>, <img>, and <iframe> elements in the provided HTML to enable lazy loading and delayed loading based on the image_optimisation options.
		 *
		 * Applies exclusions derived from the options (including preload-selected images and the first N images specified by `excludeFirstImages`) and rewrites matched tags to use data-* attributes and lazy classes when appropriate.
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

			if ( isset( $image_optimisation['lazyLoadImages'] ) && (bool) $image_optimisation['lazyLoadImages'] ) {
				$exclude_imgs = Util::process_urls( $image_optimisation['excludeImages'] ?? array() );

				$preload_img_urls = $this->get_preload_images_urls();
				$exclude_imgs     = array_unique( array_merge( $exclude_imgs, $preload_img_urls ) );

				$img_counter = 0;

				return preg_replace_callback(
					'#<picture\b[^>]*>.*?</picture>|<img\b([^>]*?)src=["\']([^"\']+)["\'][^>]*>|<iframe\b([^>]*?)src=["\']([^"\']+)["\'][^>]*>#is',
					function ( $matches ) use ( &$img_counter, $exclude_img_count, $exclude_imgs ) {
						$img_counter++;

						if ( 5 === count( $matches ) ) {
							return $this->process_iframe_tag( $matches[0], $matches[4], $exclude_imgs );
						}

						if ( $exclude_img_count > $img_counter ) {
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

			return $buffer;
		}

		/**
		 * Retrieves URLs of images to preload.
		 *
		 * @since 1.0.0
		 *
		 * @return array List of preload image URLs.
		 */
		private function get_preload_images_urls() {
			$image_optimisation = $this->options['image_optimisation'] ?? array();
			$preload_img_urls   = array();

			if ( is_front_page() && isset( $image_optimisation['preloadFrontPageImages'] ) && (bool) $image_optimisation['preloadFrontPageImages'] ) {
				$preload_img_urls = array_merge( $preload_img_urls, $this->process_preload_urls( $image_optimisation['preloadFrontPageImagesUrls'] ?? array() ) );
			}

			$page_img_urls = get_post_meta( get_the_ID(), '_wppo_preload_image_url', true );

			if ( ! empty( $page_img_urls ) ) {
				$preload_img_urls = array_merge( $preload_img_urls, $this->process_preload_urls( $page_img_urls ) );
			}

			if ( isset( $image_optimisation['preloadPostTypeImage'] ) && (bool) $image_optimisation['preloadPostTypeImage'] ) {
				if ( isset( $image_optimisation['selectedPostType'] ) && ! empty( $image_optimisation['selectedPostType'] ) ) {
					$selected_post_types = (array) $image_optimisation['selectedPostType'];

					if ( is_singular( $selected_post_types ) && has_post_thumbnail() ) {
						$thumbnail_id = get_post_thumbnail_id();

						if ( $thumbnail_id ) {
							$exclude_img_urls = array();
							if ( isset( $image_optimisation['excludePostTypeImgUrl'] ) && ! empty( $image_optimisation['excludePostTypeImgUrl'] ) ) {
								$exclude_img_urls = Util::process_urls( $image_optimisation['excludePostTypeImgUrl'] );
							}

							if ( 'product' === get_post_type() && class_exists( 'WooCommerce' ) ) {
								$image_size = apply_filters( 'woocommerce_gallery_image_size', 'woocommerce_single' );
								$img_url    = wp_get_attachment_image_url( $thumbnail_id, $image_size );

								if ( is_array( $img_url ) ) {
									$img_url = $img_url[0];
								}
							} else {
								$img_url = wp_get_attachment_image_url( $thumbnail_id, 'blog-single-image' );
							}

							$should_exclude = false;
							foreach ( $exclude_img_urls as $url ) {
								if ( false !== strpos( $img_url, $url ) ) {
									$should_exclude = true;
									break;
								}
							}

							$max_width    = $image_optimisation['maxWidthImgSize'] ? $image_optimisation['maxWidthImgSize'] : 1480;
							$exclude_size = array();

							if ( isset( $image_optimisation['excludeSize'] ) && ! empty( $image_optimisation['excludeSize'] ) ) {
								$exclude_size = Util::process_urls( $image_optimisation['excludeSize'] );
								$exclude_size = array_map( 'absint', $exclude_size );
							}

							if ( ! $should_exclude ) {
								$srcset = wp_get_attachment_image_srcset( $thumbnail_id );

								if ( $srcset ) {
									$sources = array_map( 'trim', explode( ',', $srcset ) );

									$parsed_sources = array();
									foreach ( $sources as $source ) {
										list($url, $descriptor) = array_map( 'trim', explode( ' ', $source ) );
										$width                  = (int) rtrim( $descriptor, 'w' ); // Remove 'w' to get the number.

										if ( in_array( (int) $width, $exclude_size, true ) ) {
											continue;
										}

										$parsed_sources[] = array(
											'url'   => $url,
											'width' => $width,
										);
									}

									usort(
										$parsed_sources,
										function ( $a, $b ) {
											return $a['width'] - $b['width'];
										}
									);

									$previous_width = 0;
									foreach ( $parsed_sources as $index => $source ) {
										$current_width = $source['width'];
										$next_width    = isset( $parsed_sources[ $index + 1 ] ) ? $parsed_sources[ $index + 1 ]['width'] : null;

										if ( $current_width > $max_width ) {
											continue;
										}

										$media = '(min-width: ' . $previous_width . 'px)';
										if ( $next_width && $next_width <= $max_width ) {
											$media .= ' and (max-width: ' . $current_width . 'px)';
										}

										$preload_img_urls[] = $source['url'];

										$previous_width = $current_width;
									}
								} else {
									$preload_img_urls[] = $img_url;
								}
							}
						}
					}
				}
			}

			return array_unique( $preload_img_urls );
		}

		/**
		 * Processes an URLs for preloading.
		 *
		 * @since 1.0.0
		 *
		 * @param string $urls URLs to process.
		 * @return array Processed URLs ready for preloading.
		 */
		private function process_preload_urls( $urls ) {
			$preload_urls = array();
			if ( ! empty( $urls ) ) {
				foreach ( Util::process_urls( $urls ) as $img ) {
					$preload_urls[] = $this->prepare_url_for_preload( $img );
				}
			}
			return $preload_urls;
		}

		/**
		 * Prepares a URL for preloading, handling specific prefixes like 'mobile:' or 'desktop:'.
		 *
		 * @since 1.0.0
		 *
		 * @param string $url The original URL to prepare.
		 * @return string The prepared URL.
		 */
		private function prepare_url_for_preload( $url ) {
			$url = trim( $url );

			// Handle mobile and desktop specific URLs.
			if ( 0 === strpos( $url, 'mobile:' ) ) {
				return content_url( trim( str_replace( 'mobile:', '', $url ) ) );
			} elseif ( 0 === strpos( $url, 'desktop:' ) ) {
				return content_url( trim( str_replace( 'desktop:', '', $url ) ) );
			}

			return ( 0 !== strpos( $url, 'http' ) ) ? content_url( $url ) : $url;
		}

		/**
		 * Generates a base64-encoded SVG image with the given width and height.
		 *
		 * @since 1.0.0
		 *
		 * @param string $img_attributes The image's attributes (including width and height).
		 * @return string The base64-encoded SVG.
		 */
		private function generate_svg_base64( $img_attributes ) {
			// Match both quoted (width="59") and unquoted (width=59) attribute formats.
			preg_match( '/\bwidth=["\']?(\d+)["\']?/i', $img_attributes, $width_matches );
			preg_match( '/\bheight=["\']?(\d+)["\']?/i', $img_attributes, $height_matches );

			$width  = isset( $width_matches[1] ) ? $width_matches[1] : '100';
			$height = isset( $height_matches[1] ) ? $height_matches[1] : '100';

			$svg_content = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '"><rect width="100%" height="100%" fill="#cfd4db" /></svg>';

			// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			return 'data:image/svg+xml;base64,' . base64_encode( $svg_content );
			// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
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

			$exclude_videos = Util::process_urls( $image_opts['excludeVideos'] ?? '' );

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
