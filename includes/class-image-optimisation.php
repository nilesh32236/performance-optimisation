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
			list( $actual_image_url, $media_query ) = $this->parse_image_url_config( $image_url_config );

			if ( ! preg_match( '/^https?:\/\//i', $actual_image_url ) ) {
				$actual_image_url = content_url( ltrim( $actual_image_url, '/' ) );
			}

			Util::generate_preload_link( $actual_image_url, 'preload', 'image', false, Util::get_image_mime_type( $actual_image_url ), $media_query );
		}

		/**
		 * Parses an image URL config string into an actual URL and a media query.
		 *
		 * @param string $image_url_config The image URL string from settings (may include mobile:/desktop: prefixes).
		 * @return array{string, string} An array containing the actual image URL and the media query.
		 */
		private function parse_image_url_config( string $image_url_config ): array {
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

			return array( $actual_image_url, $media_query );
		}

		/**
		 * Preloads a featured image.
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

			$this->generate_preload_link_for_image_url( $default_image_url );
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

			$dom = new \DOMDocument();
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			@$dom->loadHTML( $buffer, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

			$images = $dom->getElementsByTagName( 'img' );

			foreach ( $images as $image ) {
				$original_src = $image->getAttribute( 'src' );
				if ( $original_src ) {
					foreach ( $exclude_conversion_patterns as $pattern ) {
						if ( str_contains( $original_src, $pattern ) ) {
							continue 2; // Excluded.
						}
					}

					if ( $this->is_url_eligible_for_conversion( $original_src ) ) {
						$converted_url = $this->get_converted_image_url_if_exists( $original_src, $preferred_format );
						if ( $converted_url ) {
							$image->setAttribute( 'src', $converted_url );
						}
					}
				}

				$original_srcset = $image->getAttribute( 'srcset' );
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
					$image->setAttribute( 'srcset', $new_srcset );
				}
			}

			return $dom->saveHTML();
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

			$dom = new \DOMDocument();
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			@$dom->loadHTML( $buffer, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

			if ( $enable_lazy_load_images ) {
				$images = $dom->getElementsByTagName( 'img' );
				foreach ( $images as $image ) {
					$element_counter++;
					if ( $exclude_first_n_images >= $element_counter ) {
						continue;
					}

					$original_src = $image->getAttribute( 'src' );
					if ( $original_src ) {
						foreach ( $exclude_lazy_load_patterns as $pattern ) {
							if ( str_contains( $original_src, $pattern ) ) {
								continue 2;
							}
						}
					}

					$this->modify_img_attributes_for_lazy_load_dom( $image );
				}

				$pictures = $dom->getElementsByTagName( 'picture' );
				foreach ( $pictures as $picture ) {
					$element_counter++;
					if ( $exclude_first_n_images >= $element_counter ) {
						continue;
					}

					$img = $picture->getElementsByTagName( 'img' )->item( 0 );
					if ( $img ) {
						$original_src = $img->getAttribute( 'src' );
						if ( $original_src ) {
							foreach ( $exclude_lazy_load_patterns as $pattern ) {
								if ( str_contains( $original_src, $pattern ) ) {
									continue 2;
								}
							}
						}
					}

					$sources = $picture->getElementsByTagName( 'source' );
					foreach ( $sources as $source ) {
						$original_srcset = $source->getAttribute( 'srcset' );
						if ( $original_srcset ) {
							$source->setAttribute( 'data-srcset', $original_srcset );
							$source->removeAttribute( 'srcset' );
						}
					}

					if ( $img ) {
						$this->modify_img_attributes_for_lazy_load_dom( $img );
					}
				}
			}

			if ( $enable_lazy_load_videos ) {
				$iframes = $dom->getElementsByTagName( 'iframe' );
				foreach ( $iframes as $iframe ) {
					$original_src = $iframe->getAttribute( 'src' );
					if ( $original_src ) {
						foreach ( $exclude_lazy_load_patterns as $pattern ) {
							if ( str_contains( $original_src, $pattern ) ) {
								continue 2;
							}
						}

						$iframe->setAttribute( 'data-src', $original_src );
						$iframe->removeAttribute( 'src' );
						$iframe->setAttribute( 'class', $iframe->getAttribute( 'class' ) . ' wppo-lazy-iframe' );
					}
				}

				$videos = $dom->getElementsByTagName( 'video' );
				foreach ( $videos as $video ) {
					$original_src = $video->getAttribute( 'src' );
					if ( $original_src ) {
						foreach ( $exclude_lazy_load_patterns as $pattern ) {
							if ( str_contains( $original_src, $pattern ) ) {
								continue 2;
							}
						}
					}

					$sources = $video->getElementsByTagName( 'source' );
					foreach ( $sources as $source ) {
						$original_src = $source->getAttribute( 'src' );
						if ( $original_src ) {
							foreach ( $exclude_lazy_load_patterns as $pattern ) {
								if ( str_contains( $original_src, $pattern ) ) {
									continue 3;
								}
							}
						}
					}

					$video->setAttribute( 'class', $video->getAttribute( 'class' ) . ' wppo-lazy-video' );
					if ( $original_src ) {
						$video->setAttribute( 'data-src', $original_src );
						$video->removeAttribute( 'src' );
					}

					$poster = $video->getAttribute( 'poster' );
					if ( $poster ) {
						$video->setAttribute( 'data-poster', $poster );
						$video->removeAttribute( 'poster' );
					}

					$video->setAttribute( 'preload', 'none' );

					foreach ( $sources as $source ) {
						$original_src = $source->getAttribute( 'src' );
						if ( $original_src ) {
							$source->setAttribute( 'data-src', $original_src );
							$source->removeAttribute( 'src' );
						}
					}
				}
			}

			return $dom->saveHTML();
		}

		/**
		 * Helper to modify <img> attributes for lazy loading using DOMElement.
		 *
		 * @param \DOMElement $image The image element.
		 */
		private function modify_img_attributes_for_lazy_load_dom( \DOMElement $image ): void {
			$settings = $this->options['image_optimisation'] ?? array();

			$original_src = $image->getAttribute( 'src' );
			if ( $original_src ) {
				$image->setAttribute( 'data-src', $original_src );
				if ( ! empty( $settings['replacePlaceholderWithSVG'] ) && (bool) $settings['replacePlaceholderWithSVG'] ) {
					$svg_placeholder = $this->generate_svg_placeholder_from_attributes( $image->ownerDocument->saveHTML( $image ) );
					$image->setAttribute( 'src', $svg_placeholder );
				} else {
					$image->removeAttribute( 'src' );
				}
			}

			$original_srcset = $image->getAttribute( 'srcset' );
			if ( $original_srcset ) {
				$image->setAttribute( 'data-srcset', $original_srcset );
				$image->removeAttribute( 'srcset' );
			}

			$image->setAttribute( 'class', $image->getAttribute( 'class' ) . ' wppo-lazy-image' );
			if ( ! $image->hasAttribute( 'loading' ) ) {
				$image->setAttribute( 'loading', 'lazy' );
			}
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
