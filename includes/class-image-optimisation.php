<?php

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Image_Optimisation' ) ) {
	class Image_Optimisation {

		private array $options;

		public function __construct( $options ) {
			$this->options = $options;

			$this->setup_hooks();
		}

		private function setup_hooks() {
			if ( isset( $this->options['image_optimisation']['convertImg'] ) && (bool) $this->options['image_optimisation']['convertImg'] ) {
				require_once QTPO_PLUGIN_PATH . 'includes/class-img-converter.php';
				$img_converter = new Img_Converter( $this->options );

				add_filter( 'wp_generate_attachment_metadata', array( $img_converter, 'convert_images_to_next_gen' ), 10, 2 );
				add_filter( 'wp_get_attachment_image_src', array( $img_converter, 'maybe_serve_next_gen_image' ), 10, 4 );
			}
		}

		public function preload_images() {
			$image_optimisation = $this->options['image_optimisation'] ?? array();

			$this->preload_front_page_images( $image_optimisation );
			$this->preload_meta_images();
			$this->preload_post_type_images( $image_optimisation );
		}

		public function maybe_serve_next_gen_images( $buffer ) {
			if ( isset( $this->options['image_optimisation']['convertImg'] ) && (bool) $this->options['image_optimisation']['convertImg'] ) {
				$conversion_format = $this->options['image_optimisation']['conversionFormat'] ?? 'webp';

				// Check if the browser supports WebP
				$supports_avif = strpos( $_SERVER['HTTP_ACCEPT'], 'image/avif' ) !== false;
				$supports_webp = strpos( $_SERVER['HTTP_ACCEPT'], 'image/webp' ) !== false;

				if ( 'avif' === $conversion_format && ! $supports_avif ) {
					return $buffer; // AVIF is selected but not supported
				}

				if ( 'webp' === $conversion_format && ! $supports_webp ) {
					return $buffer; // WebP is selected but not supported
				}

				$exclude_imgs = Util::process_urls( $this->options['image_optimisation']['excludeConvertImages'] ?? array() );

				return preg_replace_callback(
					'#<img\b[^>]*((?:src|srcset)=["\'][^"\']+["\'])[^>]*>#i',
					function ( $matches ) use ( $exclude_imgs, $supports_avif, $supports_webp ) {
						$img_tag = $matches[0];

						$updated_img_tag = preg_replace_callback(
							'#src=["\']([^"\']+)["\']#i',
							function ( $src_match ) use ( $exclude_imgs, $supports_avif, $supports_webp ) {
								return 'src="' . $this->replace_image_with_next_gen( $src_match[1], $exclude_imgs, $supports_avif, $supports_webp ) . '"';
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
											list( $url, $descriptor ) = array_pad( explode( ' ', trim( $srcset_item ), 2 ), 2, '' );
											$new_url                  = $this->replace_image_with_next_gen( $url, $exclude_imgs, $supports_avif, $supports_webp );
											return $new_url . ( $descriptor ? " $descriptor" : '' );
										},
										explode( ',', $srcset )
									)
								);

								return 'srcset="' . esc_attr( $new_srcset ) . '"';
							},
							$updated_img_tag
						);

						return $updated_img_tag;
					},
					$buffer
				);
			}

			return $buffer;
		}

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

			$img_converter = new Img_Converter( $this->options );

			$avif_img_path = $img_converter->get_img_path( $img_url, 'avif' );
			$webp_img_path = $img_converter->get_img_path( $img_url, 'webp' );

			if ( 'avif' === $conversion_format || 'both' === $conversion_format ) {
				// Convert to AVIF if supported and not already converted
				if ( $supports_avif && ! file_exists( $avif_img_path ) ) {
					$source_image_path = Util::get_local_path( $img_url );

					if ( file_exists( $source_image_path ) ) {
						$img_converter->add_img_into_queue( $source_image_path, 'avif' );
					}
				}
			}

			if ( 'webp' === $conversion_format || 'both' === $conversion_format ) {
				// Convert to WebP if supported and not already converted
				if ( $supports_webp && ! file_exists( $webp_img_path ) ) {
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

			// Fallback to original image URL
			return $img_url;
		}

		private function preload_front_page_images( $image_optimisation ) {
			if ( empty( $image_optimisation['preloadFrontPageImages'] ) || ! is_front_page() ) {
				return;
			}

			$preload_img_urls = Util::process_urls( $image_optimisation['preloadFrontPageImagesUrls'] ?? array() );

			foreach ( $preload_img_urls as $img_url ) {
				$this->generate_img_preload( $img_url );
			}
		}

		private function preload_meta_images() {
			$page_img_urls = get_post_meta( get_the_ID(), '_qtpo_preload_image_url', true );

			if ( ! empty( $page_img_urls ) ) {
				foreach ( Util::process_urls( $page_img_urls ) as $img_url ) {
					$this->generate_img_preload( $img_url );
				}
			}
		}

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
				error_log( "Image excluded: $image_url" );
				return;
			}

			$srcset = wp_get_attachment_image_srcset( $thumbnail_id );
			$this->process_srcset_for_preload( $srcset, $image_url, $image_optimisation );
		}

		private function get_image_url_by_post_type( int $thumbnail_id ): string {
			if ( 'product' === get_post_type() && class_exists( 'WooCommerce' ) ) {
				$image_size = apply_filters( 'woocommerce_gallery_image_size', 'woocommerce_single' );
				return wp_get_attachment_image_url( $thumbnail_id, $image_size ) ?? '';
			}

			return wp_get_attachment_image_url( $thumbnail_id, 'blog-single-image' ) ?? '';
		}

		private function should_exclude_image( string $image_url, array $exclude_img_urls ): bool {
			foreach ( $exclude_img_urls as $url ) {
				if ( str_contains( $image_url, $url ) ) {
					return true;
				}
			}
			return false;
		}

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
				list( $url, $descriptor ) = array_map( 'trim', explode( ' ', $source ) );
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

			$this->generate_media_preloads( $parsed_sources, $max_width );
		}

		private function generate_media_preloads( $parsed_sources, $max_width ) {
			$previous_width = 0;

			foreach ( $parsed_sources as $index => $source ) {
				$current_width = $source['width'];
				$next_width    = $parsed_sources[ $index + 1 ]['width'] ?? null;

				$media = "(min-width: {$previous_width}px)";
				if ( $next_width && $next_width <= $max_width ) {
					$media .= " and (max-width: {$current_width}px)";
				}

				Util::generate_preload_link( $source['url'], 'preload', 'image', false, Util::get_image_mime_type( $source['url'] ), $media );
				$previous_width = $current_width + 1;
			}
		}

		public function generate_img_preload( $img_url ) {
			if ( 0 === strpos( $img_url, 'mobile:' ) ) {
				$mobile_url = trim( str_replace( 'mobile:', '', $img_url ) );

				if ( 0 !== strpos( $img_url, 'http' ) ) {
					$mobile_url = content_url( $mobile_url );
				}

				Util::generate_preload_link( $mobile_url, 'preload', 'image', false, Util::get_image_mime_type( $mobile_url ), '(max-width: 768px)' );
			} elseif ( 0 === strpos( $img_url, 'desktop:' ) ) {
				$desktop_url = trim( str_replace( 'desktop:', '', $img_url ) );

				if ( 0 !== strpos( $img_url, 'http' ) ) {
					$desktop_url = content_url( $desktop_url );
				}

				Util::generate_preload_link( $desktop_url, 'preload', 'image', false, Util::get_image_mime_type( $desktop_url ), '(min-width: 768px)' );
			} else {
				$img_url = trim( $img_url );

				if ( 0 !== strpos( $img_url, 'http' ) ) {
					$img_url = content_url( $img_url );
				}

				Util::generate_preload_link( $img_url, 'preload', 'image', false, Util::get_image_mime_type( $img_url ) );
			}
		}

		public function add_delay_load_img( $buffer ) {
			$image_optimisation = $this->options['image_optimisation'] ?? array();
			$exclude_img_count  = $image_optimisation['excludeFistImages'] ?? 0;
			$exclude_imgs       = array();

			if ( isset( $image_optimisation['lazyLoadImages'] ) && (bool) $image_optimisation['lazyLoadImages'] ) {
				$exclude_imgs = Util::process_urls( $image_optimisation['excludeImages'] ?? array() );

				$preload_img_urls = $this->get_preload_images_urls();

				$exclude_imgs = array_unique( array_merge( $exclude_imgs, $preload_img_urls ) );

				$img_counter = 0;

				return preg_replace_callback(
					'#<img\b([^>]*?)src=["\']([^"\']+)["\'][^>]*>#i',
					function ( $matches ) use ( &$img_counter, $exclude_img_count, $exclude_imgs ) {
						$img_counter++;

						$img_tag      = $matches[0];
						$original_src = $matches[2];

						if ( ! empty( $exclude_imgs ) ) {
							foreach ( $exclude_imgs as $exclude_img ) {
								if ( false !== strpos( $original_src, $exclude_img ) ) {
									return preg_replace( '/\sfetchpriority=["\']high["\']/i', '', $matches[0] );
								}
							}
						}

						if ( $exclude_img_count > $img_counter ) {
							return $matches[0];
						}

						// Check if the img tag already has 'data-src'
						if ( strpos( $img_tag, 'data-src' ) === false ) {
							$img_tag = preg_replace(
								'#src=["\']([^"\']+)["\']#i',
								'data-src="' . $original_src . '"',
								$img_tag
							);

							if ( isset( $this->options['image_optimisation']['replacePlaceholderWithSVG'] ) && (bool) $this->options['image_optimisation']['replacePlaceholderWithSVG'] ) {
								$new_src = $this->generate_svg_base64( $matches[0] ); // Pass the img attributes to generate SVG

								if ( ! empty( $new_src ) ) {
									$img_tag = preg_replace(
										'#<img\b([^>]*)#i',
										'<img $1 src="' . $new_src . '"',
										$img_tag
									);
								}
							}

							if ( preg_match( '#srcset=["\']([^"\']+)["\']#i', $img_tag, $srcset_matches ) ) {
								$img_tag = preg_replace(
									'#srcset=["\']([^"\']+)["\']#i',
									'data-srcset="' . $srcset_matches[1] . '"',
									$img_tag
								);
							}

							if ( preg_match( '#^data:image/#i', $original_src ) ) {
								return $matches[0];
							}
						}

						return $img_tag;
					},
					$buffer
				);
			}

			return $buffer;
		}

		private function get_preload_images_urls() {
			$image_optimisation = $this->options['image_optimisation'] ?? array();
			$preload_img_urls   = array();

			if ( is_front_page() && isset( $image_optimisation['preloadFrontPageImages'] ) && (bool) $image_optimisation['preloadFrontPageImages'] ) {
				$preload_img_urls = array_merge( $preload_img_urls, $this->process_preload_urls( $image_optimisation['preloadFrontPageImagesUrls'] ?? array() ) );
			}

			$page_img_urls = get_post_meta( get_the_ID(), '_qtpo_preload_image_url', true );

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
										list( $url, $descriptor ) = array_map( 'trim', explode( ' ', $source ) );
										$width                    = (int) rtrim( $descriptor, 'w' ); // Remove 'w' to get the number.

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
							} else {
								error_log( "Image excluded: $img_url" );
							}
						}
					}
				}
			}

			return array_unique( $preload_img_urls );
		}

		private function process_preload_urls( $urls ) {
			$preload_urls = array();
			if ( ! empty( $urls ) ) {
				foreach ( Util::process_urls( $urls ) as $img ) {
					$preload_urls[] = $this->prepare_url_for_preload( $img );
				}
			}
			return $preload_urls;
		}

		private function prepare_url_for_preload( $url ) {
			$url = trim( $url );

			// Handle mobile and desktop specific URLs
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
		 * @param string $img_attributes The image's attributes (including width and height).
		 * @return string The base64-encoded SVG.
		 */
		private function generate_svg_base64( $img_attributes ) {
			preg_match( '/width=["\'](\d+)["\']/', $img_attributes, $width_matches );
			preg_match( '/height=["\'](\d+)["\']/', $img_attributes, $height_matches );

			$width  = isset( $width_matches[1] ) ? $width_matches[1] : '100';
			$height = isset( $height_matches[1] ) ? $height_matches[1] : '100';

			$svg_content = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '"><rect width="100%" height="100%" fill="#cfd4db" /></svg>';

			return 'data:image/svg+xml;base64,' . base64_encode( $svg_content );
		}
	}
}
