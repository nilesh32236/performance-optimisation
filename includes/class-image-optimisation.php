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

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Image_Optimisation' ) ) {

	/**
	 * Image Optimisation class.
	 *
	 * Handles image conversion, preloading, lazy loading, and serving optimized images.
	 *
	 * @since 1.0.0
	 */
	class Image_Optimisation {

		/**
		 * Configuration options for image optimization.
		 *
		 * @var array<string, mixed>
		 * @since 1.0.0
		 */
		private array $options;

		/**
		 * Instance of Img_Converter.
		 *
		 * @var Img_Converter|null
		 * @since 1.0.0
		 */
		private ?Img_Converter $img_converter = null;

		/**
		 * Constructor.
		 *
		 * @since 1.0.0
		 * @param array<string, mixed> $options Configuration options for image optimization.
		 */
		public function __construct( array $options ) {
			$this->options = $options;

			if ( ! empty( $this->options['image_optimisation']['convertImg'] ) && (bool) $this->options['image_optimisation']['convertImg'] ) {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Img_Converter' ) ) {
					require_once WPPO_PLUGIN_PATH . 'includes/class-img-converter.php';
				}
				$this->img_converter = new Img_Converter( $this->options );

				add_filter( 'wp_generate_attachment_metadata', array( $this->img_converter, 'convert_uploaded_image_and_sizes' ), 10, 2 );
			}
		}

		/**
		 * Preloads images based on settings.
		 * This is typically called from a wp_head action.
		 *
		 * @since 1.0.0
		 */
		public function preload_images_on_page_load(): void {
			$image_optimisation_settings = $this->options['image_optimisation'] ?? array();

			if ( is_front_page() && ! empty( $image_optimisation_settings['preloadFrontPageImages'] ) ) {
				$front_page_image_urls = Util::process_urls( (string) ( $image_optimisation_settings['preloadFrontPageImagesUrls'] ?? '' ) );
				foreach ( $front_page_image_urls as $img_url ) {
					$this->generate_preload_link_for_image_url( $img_url );
				}
			}

			if ( is_singular() ) {
				$meta_image_urls_string = get_post_meta( get_the_ID(), '_wppo_preload_image_url', true );
				if ( ! empty( $meta_image_urls_string ) ) {
					$meta_image_urls = Util::process_urls( (string) $meta_image_urls_string );
					foreach ( $meta_image_urls as $img_url ) {
						$this->generate_preload_link_for_image_url( $img_url );
					}
				}
			}

			if ( ! empty( $image_optimisation_settings['preloadPostTypeImage'] ) ) {
				$selected_post_types = (array) ( $image_optimisation_settings['selectedPostType'] ?? array() );
				if ( is_singular( $selected_post_types ) && has_post_thumbnail() ) {
					$thumbnail_id = get_post_thumbnail_id();
					if ( $thumbnail_id ) {
						$this->preload_featured_image( $thumbnail_id, $image_optimisation_settings );
					}
				}
			}
		}

		/**
		 * Generates a preload link for a given image URL, considering device-specific prefixes.
		 *
		 * @param string $image_url_config The image URL string from settings (may include mobile:/desktop: prefixes).
		 */
		private function generate_preload_link_for_image_url( string $image_url_config ): void {
			$image_url_config = trim( $image_url_config );
			$media_query      = '';
			$actual_image_url = $image_url_config;

			if ( str_starts_with( $image_url_config, 'mobile:' ) ) {
				$actual_image_url = trim( str_replace( 'mobile:', '', $image_url_config ) );
				$media_query      = '(max-width: 768px)';
			} elseif ( str_starts_with( $image_url_config, 'desktop:' ) ) {
				$actual_image_url = trim( str_replace( 'desktop:', '', $image_url_config ) );
				$media_query      = '(min-width: 769px)';
			}

			if ( ! preg_match( '/^https?:\/\//i', $actual_image_url ) ) {
				$actual_image_url = content_url( ltrim( $actual_image_url, '/' ) );
			}

			Util::generate_preload_link( $actual_image_url, 'preload', 'image', false, Util::get_image_mime_type( $actual_image_url ), $media_query );
		}

		/**
		 * Preloads a featured image, considering srcset and specified size constraints.
		 *
		 * @param int                  $thumbnail_id   Attachment ID of the featured image.
		 * @param array<string, mixed> $settings       Image optimization settings.
		 */
		private function preload_featured_image( int $thumbnail_id, array $settings ): void {
			$exclude_img_urls_patterns = Util::process_urls( (string) ( $settings['excludePostTypeImgUrl'] ?? '' ) );
			$default_image_size        = ( 'product' === get_post_type() && class_exists( 'WooCommerce' ) ) ? 'woocommerce_single' : 'large'; // Or a custom size.
			$default_image_url         = wp_get_attachment_image_url( $thumbnail_id, $default_image_size );

			if ( ! $default_image_url ) {
				return;
			}

			foreach ( $exclude_img_urls_patterns as $pattern ) {
				if ( str_contains( $default_image_url, $pattern ) ) {
					return;
				}
			}

			$srcset = wp_get_attachment_image_srcset( $thumbnail_id, $default_image_size );
			if ( ! $srcset ) {
				$this->generate_preload_link_for_image_url( $default_image_url );
				return;
			}

			$parsed_srcset = $this->parse_srcset( $srcset );
			$max_width     = isset( $settings['maxWidthImgSize'] ) ? absint( $settings['maxWidthImgSize'] ) : 1478; // Example default.
			$exclude_sizes = array_map( 'absint', Util::process_urls( (string) ( $settings['excludeSize'] ?? '' ) ) );

			$sources_for_preload = array();
			foreach ( $parsed_srcset as $source ) {
				if ( ( $max_width > 0 && $source['width'] > $max_width ) || in_array( $source['width'], $exclude_sizes, true ) ) {
					continue;
				}
				$exclude_this_source = false;
				foreach ( $exclude_img_urls_patterns as $pattern ) {
					if ( str_contains( $source['url'], $pattern ) ) {
						$exclude_this_source = true;
						break;
					}
				}
				if ( ! $exclude_this_source ) {
					$sources_for_preload[] = $source;
				}
			}

			usort(
				$sources_for_preload,
				function ( $a, $b ) {
					return $a['width'] <=> $b['width'];
				}
			);

			$previous_width = 0;
			foreach ( $sources_for_preload as $index => $source ) {
				$current_width = $source['width'];
				$media_query   = "(min-width: {$previous_width}px)";

				if ( isset( $sources_for_preload[ $index + 1 ] ) ) {
					$media_query .= " and (max-width: {$current_width}px)";
				}

				Util::generate_preload_link( $source['url'], 'preload', 'image', false, Util::get_image_mime_type( $source['url'] ), $media_query );
				$previous_width = $current_width + 1;
			}
		}

		/**
		 * Parses a srcset string into an array of sources with URLs and widths.
		 *
		 * @param string $srcset The srcset string.
		 * @return array<int, array{url: string, width: int}> Parsed sources.
		 */
		private function parse_srcset( string $srcset ): array {
			$parsed_sources = array();
			$sources        = explode( ',', $srcset );
			foreach ( $sources as $source ) {
				$parts = preg_split( '/\s+/', trim( $source ) );
				if ( count( $parts ) >= 2 && str_ends_with( $parts[1], 'w' ) ) {
					$parsed_sources[] = array(
						'url'   => $parts[0],
						'width' => absint( rtrim( $parts[1], 'w' ) ),
					);
				} elseif ( count( $parts ) === 1 ) {
					$parsed_sources[] = array(
						'url'   => $parts[0],
						'width' => 0,
					);
				}
			}
			return $parsed_sources;
		}


		/**
		 * Modifies image tags in the HTML buffer to serve next-generation formats (WebP/AVIF) if supported.
		 *
		 * @since 1.0.0
		 * @param string $buffer The HTML content buffer.
		 * @return string Modified HTML content buffer.
		 */
		public function maybe_serve_next_gen_images( string $buffer ): string {
			if ( ! $this->img_converter || ! isset( $this->options['image_optimisation']['convertImg'] ) || ! (bool) $this->options['image_optimisation']['convertImg'] ) {
				return $buffer;
			}

			$conversion_format_setting = $this->options['image_optimisation']['conversionFormat'] ?? 'webp';
			$http_accept               = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';
			$supports_avif             = str_contains( $http_accept, 'image/avif' );
			$supports_webp             = str_contains( $http_accept, 'image/webp' );

			$preferred_format = null;
			if ( $supports_avif && in_array( $conversion_format_setting, array( 'avif', 'both' ), true ) ) {
				$preferred_format = 'avif';
			} elseif ( $supports_webp && in_array( $conversion_format_setting, array( 'webp', 'both' ), true ) ) {
				$preferred_format = 'webp';
			}

			if ( ! $preferred_format ) {
				return $buffer;
			}

			$exclude_conversion_patterns = Util::process_urls( (string) ( $this->options['image_optimisation']['excludeConvertImages'] ?? '' ) );

			return preg_replace_callback(
				'#<img\s+(.*?)\s*\/?>#is',
				function ( $matches ) use ( $preferred_format, $exclude_conversion_patterns ) {
					$attributes_string = $matches[1];
					$original_img_tag  = $matches[0];

					$src_match    = array();
					$srcset_match = array();

					preg_match( '/\bsrc\s*=\s*([\'"])(.*?)\1/is', $attributes_string, $src_match );
					preg_match( '/\bsrcset\s*=\s*([\'"])(.*?)\1/is', $attributes_string, $srcset_match );

					$original_src    = $src_match[2] ?? null;
					$original_srcset = $srcset_match[2] ?? null;

					if ( $original_src ) {
						foreach ( $exclude_conversion_patterns as $pattern ) {
							if ( str_contains( $original_src, $pattern ) ) {
								return $original_img_tag; // Excluded.
							}
						}
					}

					$new_src = $original_src;
					if ( $original_src && $this->is_url_eligible_for_conversion( $original_src ) ) {
						$converted_url = $this->get_converted_image_url_if_exists( $original_src, $preferred_format );
						if ( $converted_url ) {
							$new_src = $converted_url;
						}
					}

					$new_srcset = $original_srcset;
					if ( $original_srcset ) {
						$temp_srcset_parts = array();
						$srcset_parts      = explode( ',', $original_srcset );
						foreach ( $srcset_parts as $part ) {
							$trimmed_part             = trim( $part );
							list( $url, $descriptor ) = array_pad( preg_split( '/\s+/', $trimmed_part, 2 ), 2, '' );

							$excluded = false;
							foreach ( $exclude_conversion_patterns as $pattern ) {
								if ( str_contains( $url, $pattern ) ) {
									$excluded = true;
									break;
								}
							}
							if ( $excluded ) {
								$temp_srcset_parts[] = $trimmed_part;
								continue;
							}

							if ( $this->is_url_eligible_for_conversion( $url ) ) {
								$converted_url = $this->get_converted_image_url_if_exists( $url, $preferred_format );
								if ( $converted_url ) {
									$temp_srcset_parts[] = $converted_url . ( $descriptor ? " {$descriptor}" : '' );
								} else {
									$temp_srcset_parts[] = $trimmed_part;
								}
							} else {
								$temp_srcset_parts[] = $trimmed_part;
							}
						}
						$new_srcset = implode( ', ', $temp_srcset_parts );
					}

					$modified_attributes_string = $attributes_string;
					if ( $new_src && $new_src !== $original_src ) {
						$modified_attributes_string = preg_replace( '/\bsrc\s*=\s*([\'"])(.*?)\1/is', 'src=$1' . esc_attr( $new_src ) . '$1', $modified_attributes_string, 1 );
					}
					if ( $new_srcset && $new_srcset !== $original_srcset ) {
						$modified_attributes_string = preg_replace( '/\bsrcset\s*=\s*([\'"])(.*?)\1/is', 'srcset=$1' . esc_attr( $new_srcset ) . '$1', $modified_attributes_string, 1 );
					}

					if ( str_ends_with( $original_img_tag, '/>' ) ) {
						return '<img ' . $modified_attributes_string . ' />';
					} else {
						return '<img ' . $modified_attributes_string . '>';
					}
				},
				$buffer
			);
		}


		/**
		 * Checks if a URL is eligible for image format conversion.
		 * Excludes data URIs and non-image file extensions.
		 *
		 * @param string $url The image URL.
		 * @return bool True if eligible, false otherwise.
		 */
		private function is_url_eligible_for_conversion( string $url ): bool {
			if ( str_starts_with( $url, 'data:image' ) ) {
				return false;
			}
			$extension = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			return in_array( $extension, array( 'jpg', 'jpeg', 'png', 'gif' ), true );
		}

		/**
		 * Gets the URL of a converted image (WebP/AVIF) if it exists.
		 * If it doesn't exist, it queues the original image for conversion.
		 *
		 * @param string $original_url The URL of the original image.
		 * @param string $target_format The desired format ('webp' or 'avif').
		 * @return string|null The URL of the converted image, or null if not available/applicable.
		 */
		private function get_converted_image_url_if_exists( string $original_url, string $target_format ): ?string {
			if ( ! $this->img_converter ) {
				return null;
			}

			$local_original_path = Util::get_local_path( $original_url );

			if ( ! file_exists( $local_original_path ) ) {
				return null;
			}

			$converted_local_path = Img_Converter::get_img_path( $local_original_path, $target_format );

			if ( file_exists( $converted_local_path ) ) {
				return Img_Converter::get_img_url( $original_url, $target_format );
			} else {
				Img_Converter::add_img_into_queue( $local_original_path, $target_format );
				return null;
			}
		}


		/**
		 * Adds lazy loading attributes to images and iframes/videos in the HTML buffer.
		 *
		 * @since 1.0.0
		 * @param string $buffer The HTML buffer.
		 * @return string The modified HTML buffer.
		 */
		public function add_delay_load_elements( string $buffer ): string {
			$settings = $this->options['image_optimisation'] ?? array();

			$enable_lazy_load_images = ! empty( $settings['lazyLoadImages'] ) && (bool) $settings['lazyLoadImages'];
			$enable_lazy_load_videos = ! empty( $settings['lazyLoadVideos'] ) && (bool) $settings['lazyLoadVideos']; // New setting for videos.

			if ( ! $enable_lazy_load_images && ! $enable_lazy_load_videos ) {
				return $buffer;
			}

			$exclude_lazy_load_patterns = array();
			if ( $enable_lazy_load_images && ! empty( $settings['excludeImages'] ) ) {
				$exclude_lazy_load_patterns = array_merge( $exclude_lazy_load_patterns, Util::process_urls( (string) $settings['excludeImages'] ) );
			}
			if ( $enable_lazy_load_videos && ! empty( $settings['excludeVideos'] ) ) {
				$exclude_lazy_load_patterns = array_merge( $exclude_lazy_load_patterns, Util::process_urls( (string) $settings['excludeVideos'] ) );
			}
			$preloaded_images           = $this->get_preloaded_image_urls_for_exclusion();
			$exclude_lazy_load_patterns = array_unique( array_merge( $exclude_lazy_load_patterns, $preloaded_images ) );

			$element_counter        = 0;
			$exclude_first_n_images = $enable_lazy_load_images ? ( absint( $settings['excludeFistImages'] ?? 0 ) ) : 0;

			if ( $enable_lazy_load_images ) {
				$buffer = preg_replace_callback(
					'#(<picture\b[^>]*>)(.*?)(</picture>)|<img\s+(.*?)\s*\/?>#is',
					function ( $matches ) use ( &$element_counter, $exclude_first_n_images, $exclude_lazy_load_patterns, $settings ) {
						$element_counter++;
						if ( $exclude_first_n_images >= $element_counter ) {
							return $matches[0];
						}

						if ( ! empty( $matches[1] ) ) {
							$picture_tag_open  = $matches[1];
							$picture_content   = $matches[2];
							$picture_tag_close = $matches[3];

							if ( preg_match( '/\bsrc\s*=\s*([\'"])([^"\']+)\1/is', $picture_content, $img_src_match ) ) {
								foreach ( $exclude_lazy_load_patterns as $pattern ) {
									if ( str_contains( $img_src_match[2], $pattern ) ) {
										return $matches[0];
									}
								}
							}

							$picture_content = preg_replace_callback(
								'#<source\s+(.*?)\s*\/?>#is',
								function ( $source_matches ) {
									$source_attrs = $source_matches[1];
									if ( preg_match( '/\bsrcset\s*=\s*([\'"])([^"\']+)\1/is', $source_attrs, $srcset_attr_match ) ) {
										$source_attrs = str_replace( $srcset_attr_match[0], 'data-srcset=' . $srcset_attr_match[1] . esc_attr( $srcset_attr_match[2] ) . $srcset_attr_match[1], $source_attrs );
									}
									return '<source ' . $source_attrs . ( str_ends_with( $source_matches[0], '/>' ) ? ' />' : '>' );
								},
								$picture_content
							);
							$picture_content = preg_replace_callback(
								'#<img\s+(.*?)\s*\/?>#is',
								function ( $img_matches ) use ( $settings ) {
									return $this->modify_img_attributes_for_lazy_load( $img_matches[1], $img_matches[0] );
								},
								$picture_content
							);
							return $picture_tag_open . $picture_content . $picture_tag_close;

						} elseif ( ! empty( $matches[4] ) ) { // It's an <img> element.
							$img_attributes_string = $matches[4];
							if ( preg_match( '/\bsrc\s*=\s*([\'"])([^"\']+)\1/is', $img_attributes_string, $src_attr_match ) ) {
								foreach ( $exclude_lazy_load_patterns as $pattern ) {
									if ( str_contains( $src_attr_match[2], $pattern ) ) {
										return $matches[0];
									}
								}
							}
							$modified_attributes = $this->modify_img_attributes_for_lazy_load( $img_attributes_string, $matches[0] );
							if ( str_ends_with( $matches[0], '/>' ) ) {
								return '<img ' . $modified_attributes . ' />';
							} else {
								return '<img ' . $modified_attributes . '>';
							}
						}
						return $matches[0];
					},
					$buffer
				);
			}

			if ( $enable_lazy_load_videos ) {
				$buffer = preg_replace_callback(
					'#<iframe\s+(.*?)\s*><\/iframe>|<iframe\s+(.*?)\s*\/>#is',
					function ( $matches ) use ( $exclude_lazy_load_patterns ) {
						$iframe_attributes_string = ! empty( $matches[1] ) ? $matches[1] : $matches[2];
						$original_iframe_tag      = $matches[0];

						if ( preg_match( '/\bsrc\s*=\s*([\'"])([^"\']+)\1/is', $iframe_attributes_string, $src_match ) ) {
							foreach ( $exclude_lazy_load_patterns as $pattern ) {
								if ( str_contains( $src_match[2], $pattern ) ) {
									return $original_iframe_tag; // Excluded.
								}
							}
							$new_attributes = preg_replace( '/\bsrc\s*=\s*([\'"])([^"\']+)\1/is', 'data-src=$1' . esc_attr( $src_match[2] ) . '$1', $iframe_attributes_string, 1 );
							if ( ! preg_match( '/\bclass\s*=/is', $new_attributes ) ) {
								$new_attributes .= ' class="wppo-lazy-iframe"';
							} else {
								$new_attributes = preg_replace( '/\bclass\s*=\s*([\'"])(.*?)\1/is', 'class=$1$2 wppo-lazy-iframe$1', $new_attributes, 1 );
							}
							if ( str_ends_with( $original_iframe_tag, '</iframe>' ) ) {
								return '<iframe ' . $new_attributes . '></iframe>';
							} else {
								return '<iframe ' . $new_attributes . ' />';
							}
						}
						return $original_iframe_tag;
					},
					$buffer
				);

				$buffer = preg_replace_callback(
					'#<video\s+(.*?)>(.*?)</video>#is',
					function ( $matches ) use ( $exclude_lazy_load_patterns ) {
						$video_attributes_string = $matches[1];
						$video_source_tags       = $matches[2];
						$original_video_tag      = $matches[0];

						if ( preg_match( '/\bsrc\s*=\s*([\'"])([^"\']+)\1/is', $video_attributes_string, $src_match ) ) {
							foreach ( $exclude_lazy_load_patterns as $pattern ) {
								if ( str_contains( $src_match[2], $pattern ) ) {
									return $original_video_tag;
								}
							}
						}
						if ( preg_match_all( '/<source\s+[^>]*\bsrc\s*=\s*([\'"])([^"\']+)\1[^>]*>/is', $video_source_tags, $source_src_matches ) ) {
							foreach ( $source_src_matches[2] as $source_src ) {
								foreach ( $exclude_lazy_load_patterns as $pattern ) {
									if ( str_contains( $source_src, $pattern ) ) {
										return $original_video_tag;
									}
								}
							}
						}

						$new_attributes = $video_attributes_string;
						if ( ! preg_match( '/\bclass\s*=/is', $new_attributes ) ) {
							$new_attributes .= ' class="wppo-lazy-video"';
						} else {
							$new_attributes = preg_replace( '/\bclass\s*=\s*([\'"])(.*?)\1/is', 'class=$1$2 wppo-lazy-video$1', $new_attributes, 1 );
						}
						$new_attributes = preg_replace( '/\bsrc\s*=\s*([\'"])([^"\']+)\1/is', 'data-src=$1' . esc_attr( '$2' ) . '$1', $new_attributes );
						$new_attributes = preg_replace( '/\bposter\s*=\s*([\'"])([^"\']+)\1/is', 'data-poster=$1' . esc_attr( '$2' ) . '$1', $new_attributes );
						if ( ! preg_match( '/\bpreload\s*=/is', $new_attributes ) ) {
							$new_attributes .= ' preload="none"'; // Good practice for lazy loaded videos.
						}

						$new_source_tags = preg_replace_callback(
							'#<source\s+([^>]*)\bsrc\s*=\s*([\'"])([^"\']+)\2([^>]*)>#is',
							function ( $source_matches_inner ) {
								return '<source ' . $source_matches_inner[1] . 'data-src=' . $source_matches_inner[2] . esc_attr( $source_matches_inner[3] ) . $source_matches_inner[2] . $source_matches_inner[4] . '>';
							},
							$video_source_tags
						);

						return '<video ' . $new_attributes . '>' . $new_source_tags . '</video>';
					},
					$buffer
				);
			}

			return $buffer;
		}

		/**
		 * Helper to modify <img> attributes for lazy loading.
		 *
		 * @param string $attributes_string The string of attributes from the <img> tag.
		 * @param string $original_img_tag The original full <img> tag.
		 * @return string The modified attributes string.
		 */
		private function modify_img_attributes_for_lazy_load( string $attributes_string, string $original_img_tag ): string {
			$settings       = $this->options['image_optimisation'] ?? array();
			$new_attributes = $attributes_string;

			if ( preg_match( '/\bsrc\s*=\s*([\'"])(?P<srcval>[^"\']+)\1/is', $new_attributes, $src_attr_match ) ) {
				$new_attributes = str_replace( $src_attr_match[0], 'data-src=' . $src_attr_match[1] . esc_attr( $src_attr_match['srcval'] ) . $src_attr_match[1], $new_attributes );
				if ( ! empty( $settings['replacePlaceholderWithSVG'] ) && (bool) $settings['replacePlaceholderWithSVG'] ) {
					$svg_placeholder = $this->generate_svg_placeholder_from_attributes( $attributes_string );
					$new_attributes  = 'src="' . esc_attr( $svg_placeholder ) . '" ' . $new_attributes;
				}
			}

			if ( preg_match( '/\bsrcset\s*=\s*([\'"])(?P<srcsetval>[^"\']+)\1/is', $new_attributes, $srcset_attr_match ) ) {
				$new_attributes = str_replace( $srcset_attr_match[0], 'data-srcset=' . $srcset_attr_match[1] . esc_attr( $srcset_attr_match['srcsetval'] ) . $srcset_attr_match[1], $new_attributes );
			}

			if ( ! preg_match( '/\bclass\s*=/is', $new_attributes ) ) {
				$new_attributes .= ' class="wppo-lazy-image"';
			} else {
				$new_attributes = preg_replace( '/\bclass\s*=\s*([\'"])(.*?)\1/is', 'class=$1$2 wppo-lazy-image$1', $new_attributes, 1 );
			}

			if ( stripos( $new_attributes, 'loading=' ) === false ) {
				$new_attributes .= ' loading="lazy"';
			}

			return $new_attributes;
		}

		/**
		 * Generates a base64-encoded SVG placeholder using width and height from attributes.
		 *
		 * @param string $img_attributes_string The image's attributes string.
		 * @return string The base64-encoded SVG placeholder.
		 */
		private function generate_svg_placeholder_from_attributes( string $img_attributes_string ): string {
			$width_match  = array();
			$height_match = array();
			preg_match( '/\bwidth\s*=\s*([\'"]?)(\d+)\1/i', $img_attributes_string, $width_match );
			preg_match( '/\bheight\s*=\s*([\'"]?)(\d+)\1/i', $img_attributes_string, $height_match );

			$width  = ! empty( $width_match[2] ) ? absint( $width_match[2] ) : 1; // Default to 1 if not found or invalid.
			$height = ! empty( $height_match[2] ) ? absint( $height_match[2] ) : 1; // Default to 1.
			if ( $width <= 0 ) {
				$width = 1;
			}
			if ( $height <= 0 ) {
				$height = 1;
			}

			$svg_content = sprintf(
				'<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d"><rect width="100%%" height="100%%" fill="#F0F0F0" /></svg>',
				$width,
				$height,
				$width,
				$height
			);
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			return 'data:image/svg+xml;base64,' . base64_encode( $svg_content );
		}

		/**
		 * Retrieves URLs of images that are set to be preloaded, for exclusion from lazy loading.
		 *
		 * @since 1.0.0
		 * @return array<string> List of preloaded image URLs.
		 */
		private function get_preloaded_image_urls_for_exclusion(): array {
			$preloaded_urls = array();
			$settings       = $this->options['image_optimisation'] ?? array();

			if ( is_front_page() && ! empty( $settings['preloadFrontPageImages'] ) && ! empty( $settings['preloadFrontPageImagesUrls'] ) ) {
				$urls = Util::process_urls( (string) $settings['preloadFrontPageImagesUrls'] );
				foreach ( $urls as $url_config ) {
					$preloaded_urls[] = $this->resolve_preload_url_config( $url_config );
				}
			}

			if ( is_singular() ) {
				$meta_urls_string = get_post_meta( get_the_ID(), '_wppo_preload_image_url', true );
				if ( ! empty( $meta_urls_string ) ) {
					$urls = Util::process_urls( (string) $meta_urls_string );
					foreach ( $urls as $url_config ) {
						$preloaded_urls[] = $this->resolve_preload_url_config( $url_config );
					}
				}
			}

			if ( ! empty( $settings['preloadPostTypeImage'] ) ) {
				$selected_post_types = (array) ( $settings['selectedPostType'] ?? array() );
				if ( is_singular( $selected_post_types ) && has_post_thumbnail() ) {
					$thumbnail_id = get_post_thumbnail_id();
					if ( $thumbnail_id ) {
						$default_image_size = ( 'product' === get_post_type() && class_exists( 'WooCommerce' ) ) ? 'woocommerce_single' : 'large';
						$img_url            = wp_get_attachment_image_url( $thumbnail_id, $default_image_size );
						if ( $img_url ) {
							$preloaded_urls[] = $img_url;
						}
					}
				}
			}
			return array_values( array_filter( array_unique( $preloaded_urls ) ) );
		}

		/**
		 * Resolves a URL config string (possibly with mobile:/desktop: prefix) to an actual URL.
		 *
		 * @param string $url_config The URL config string.
		 * @return string The resolved URL.
		 */
		private function resolve_preload_url_config( string $url_config ): string {
			$actual_url = trim( $url_config );
			if ( str_starts_with( $actual_url, 'mobile:' ) ) {
				$actual_url = trim( str_replace( 'mobile:', '', $actual_url ) );
			} elseif ( str_starts_with( $actual_url, 'desktop:' ) ) {
				$actual_url = trim( str_replace( 'desktop:', '', $actual_url ) );
			}

			if ( ! preg_match( '/^https?:\/\//i', $actual_url ) ) {
				return content_url( ltrim( $actual_url, '/' ) );
			}
			return $actual_url;
		}
	}
}
