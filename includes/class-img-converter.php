<?php
/**
 * Img_Converter Class
 *
 * A class to handle image format conversions (WebP and AVIF) for performance optimization.
 * This class performs image conversion based on the configuration options provided,
 * allowing optimization of images for improved website performance.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc;

/**
 * Img_Converter Class
 *
 * A class to handle image format conversions (WebP and AVIF) for performance optimization.
 *
 * @since 1.0.0
 */
class Img_Converter {

	/**
	 * Configuration options for image optimization.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	private $options;

	/**
	 * Available formats for image conversion.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	private array $available_format = array(
		'webp',
		'avif',
		'both',
	);

	/**
	 * The format to convert images to (webp, avif, or both).
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private $format;

	/**
	 * List of images to exclude from conversion.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	private $exclude_imgs = array();

	/**
	 * Img_Converter constructor.
	 *
	 * @param array $options Options for configuring image optimization.
	 * @since 1.0.0
	 */
	public function __construct( $options ) {
		$this->options = $options;

		if ( isset( $this->options['image_optimisation']['excludeWebPImages'] ) && ! empty( $this->options['image_optimisation']['excludeWebPImages'] ) ) {
			$this->exclude_imgs = Util::process_urls( $this->options['image_optimisation']['excludeWebPImages'] );
		}

		$this->format = $this->options['image_optimisation']['conversionFormat'] ?? 'webp';
	}

	/**
	 * Convert an image to WebP or AVIF format.
	 *
	 * @param string $source_image Path to the source image.
	 * @param string $format The desired format ('webp' or 'avif').
	 * @param int    $quality Quality level of the converted image (0-100).
	 * @return bool True on success, false on failure.
	 * @since 1.0.0
	 */
	public function convert_image( string $source_image, string $format = 'webp', int $quality = -1 ): bool {

		if ( ! in_array( $format, $this->available_format, true ) ) {
			$this->update_conversion_status( $source_image, 'failed', $format );
			return false;
		}

		if ( ! function_exists( 'imagecreatefromjpeg' ) || ! function_exists( 'imagecreatefrompng' ) ) {
			$this->update_conversion_status( $source_image, 'failed', $format );
			return false;
		}

		if ( ! file_exists( $source_image ) ) {
			$this->update_conversion_status( $source_image, 'failed', $format );
			return false;
		}

		$image_info = getimagesize( $source_image );

		if ( empty( $image_info ) ) {
			$this->update_conversion_status( $source_image, 'failed', $format );
			return false;
		}

		$image_type = $image_info[2];
		$image      = null;

		try {
			switch ( $image_type ) {
				case IMAGETYPE_JPEG:
					$image = imagecreatefromjpeg( $source_image );
					break;

				case IMAGETYPE_PNG:
					$image = imagecreatefrompng( $source_image );

					if ( ! $image ) {
						$this->update_conversion_status( $source_image, 'failed', $format );
						return false;
					}

					$image = $this->convert_palette_to_truecolor( $image );
					imagealphablending( $image, true ); // For transparency.
					imagesavealpha( $image, true );
					break;

				case IMAGETYPE_WEBP:
					if ( in_array( $format, array( 'avif', 'both' ), true ) ) {
						if ( ! function_exists( 'imageavif' ) ) {
							$this->update_conversion_status( $source_image, 'failed', $format );
							return false;
						}

						if ( $this->is_animated_webp( $source_image ) ) {
							$this->update_conversion_status( $source_image, 'failed', $format );
							return false;
						}

						try {
							$image = imagecreatefromwebp( $source_image );
							if ( ! $image ) {
								$this->update_conversion_status( $source_image, 'failed', $format );
								return false;
							}

							$avif_path = $this->get_img_path( $source_image, 'avif' );
							Util::prepare_cache_dir( dirname( $avif_path ) );

							if ( imageavif( $image, $avif_path, $quality ) ) {
								$this->update_conversion_status( $source_image, 'completed', $format );
							} else {
								$this->update_conversion_status( $source_image, 'failed', $format );
								imagedestroy( $image );
								return false;
							}
							imagedestroy( $image );
						} catch ( \Exception $e ) {
							$this->update_conversion_status( $source_image, 'failed', $format );
							return false;
						}
					}
					return true;
				case IMAGETYPE_GIF:
					if ( ! extension_loaded( 'imagick' ) ) {
						$this->update_conversion_status( $source_image, 'failed', $format );
						return false;
					}

					if ( 'webp' !== $format ) {
						$this->update_conversion_status( $source_image, 'completed', $format );
						return false;
					}

					$webp_path = $this->get_img_path( $source_image, 'webp' );

					try {

						if ( file_exists( $webp_path ) ) {
							$this->update_conversion_status( $source_image, 'completed', $format );
							return true;
						}
						// Initialize Imagick and read the image file.
						$imagick = new \Imagick();
						$imagick->readImage( $source_image );

						// Check if the image has transparency (alpha channel).
						$has_transparency = $imagick->getImageAlphaChannel() === \Imagick::ALPHACHANNEL_ACTIVATE;

						// Set WebP format.
						$imagick->setImageFormat( 'webp' );

						// If transparent, use lossless compression for WebP to retain transparency.
						if ( $has_transparency ) {
							$imagick->setImageCompressionQuality( $quality );
							$imagick->setImageAlphaChannel( \Imagick::ALPHACHANNEL_KEEP );  // Keep transparency.
							$imagick->setOption( 'webp:lossless', 'true' );
						} else {
							// For non-transparent images, use lossy compression.
							$imagick->setImageCompressionQuality( $quality );
							$imagick->setOption( 'webp:lossless', 'false' );
						}

						Util::prepare_cache_dir( dirname( $webp_path ) );
						// Write the WebP file.
						if ( $imagick->writeImages( $webp_path, true ) ) {
							$this->update_conversion_status( $source_image, 'completed', 'webp' );
						} else {
							$this->update_conversion_status( $source_image, 'failed', 'webp' );
							return false;
						}

						$imagick->clear();
						return true;
					} catch ( \Exception $e ) {
						$this->update_conversion_status( $source_image, 'failed', $format );
						wp_delete_file( $webp_path );
						return false;
					}
				default:
					$this->update_conversion_status( $source_image, 'failed', $format );
					return false; // Unsupported format.
			}

			$success = true;

			if ( in_array( $format, array( 'webp', 'both' ), true ) ) {
				$webp_path = $this->get_img_path( $source_image, 'webp' );

				if ( ! file_exists( $webp_path ) ) {
					if ( ! function_exists( 'imagewebp' ) || ! Util::prepare_cache_dir( dirname( $webp_path ) ) || ! imagewebp( $image, $webp_path, $quality ) ) {
						$success = false;
						$this->update_conversion_status( $source_image, 'failed', 'webp' );
					} else {
						$this->update_conversion_status( $source_image, 'completed', 'webp' );
					}
				} else {
					$this->update_conversion_status( $source_image, 'completed', 'webp' );
				}
			}

			if ( in_array( $format, array( 'avif', 'both' ), true ) ) {
				$avif_path = $this->get_img_path( $source_image, 'avif' );

				if ( ! file_exists( $avif_path ) ) {
					Util::prepare_cache_dir( dirname( $avif_path ) );
					if ( ! function_exists( 'imageavif' ) || ! imageavif( $image, $avif_path, $quality ) ) {
						$this->update_conversion_status( $source_image, 'failed', 'avif' );
					} else {
						$this->update_conversion_status( $source_image, 'completed', 'avif' );
					}
				} else {
					$this->update_conversion_status( $source_image, 'completed', 'avif' );
				}
			}

			imagedestroy( $image ); // Clean up memory.
			return $success;
		} catch ( \Exception $e ) {

			$this->update_conversion_status( $source_image, 'failed', $format );
			// Clean up memory if image resource was created.
			if ( is_resource( $image ) ) {
				imagedestroy( $image );
			}

			return false;
		}
	}

	/**
	 * Convert an image palette to true color if it is not already in true color.
	 *
	 * @param \GdImage $image The image resource.
	 * @return \GdImage The true color image resource.
	 * @since 1.0.0
	 */
	private function convert_palette_to_truecolor( $image ) {
		if ( ! imageistruecolor( $image ) ) {
			$width     = imagesx( $image );
			$height    = imagesy( $image );
			$truecolor = imagecreatetruecolor( $width, $height );
			imagealphablending( $truecolor, false );
			imagesavealpha( $truecolor, true );
			$transparent = imagecolorallocatealpha( $truecolor, 255, 255, 255, 127 );
			imagefill( $truecolor, 0, 0, $transparent );
			imagecopy( $truecolor, $image, 0, 0, 0, 0, $width, $height );
			imagedestroy( $image );
			return $truecolor;
		}
		return $image;
	}

	/**
	 * Check if a WebP image is animated.
	 *
	 * @param string $file Path to the WebP file.
	 * @return bool True if the WebP image is animated, false otherwise.
	 * @since 1.0.0
	 */
	private function is_animated_webp( $file ) {
		global $wp_filesystem;

		if ( ! Util::init_filesystem() ) {
			return false;
		}

		if ( ! $wp_filesystem->exists( $file ) || ! $wp_filesystem->is_readable( $file ) ) {
			return false;
		}

		$header = $wp_filesystem->get_contents( $file );
		if ( false === $header ) {
			return false;
		}

		$header = substr( $header, 0, 40 );

		if ( 'RIFF' !== substr( $header, 0, 4 ) || 'WEBP' !== substr( $header, 8, 4 ) ) {
			return false;
		}

		return false !== strpos( $header, 'ANIM' );
	}


	/**
	 * Get the WebP file path.
	 *
	 * @param string $source_image The source image path.
	 * @param string $format The desired format ('webp' or 'avif').
	 * @return string The path where the converted image will be saved.
	 * @since 1.0.0
	 */
	public static function get_img_path( string $source_image, string $format = 'webp' ): string {
		$info = pathinfo( $source_image );

		$new_file_name = $info['filename'] . '.' . $format;

		$new_image_path = wp_normalize_path( $info['dirname'] . '/' . $new_file_name );

		// If home_url is present, remove it from the path.
		if ( 0 === strpos( $new_image_path, wp_normalize_path( ABSPATH ) ) ) {
			$local_path = str_replace(
				wp_normalize_path( WP_CONTENT_DIR ),
				wp_normalize_path( WP_CONTENT_DIR . '/wppo' ),
				$new_image_path
			);
			return $local_path;
		}

		$relative_path = wp_normalize_path( str_replace( wp_parse_url( home_url(), PHP_URL_PATH ) ?? '', '', $new_image_path ) );

		$local_path = str_replace(
			wp_normalize_path( WP_CONTENT_DIR ),
			wp_normalize_path( WP_CONTENT_DIR . '/wppo' ),
			wp_normalize_path( ABSPATH . ltrim( $relative_path, '/' ) )
		);

		return $local_path;
	}

	/**
	 * Get the URL of the converted image.
	 *
	 * @param string $source_image The source image URL.
	 * @param string $format The desired format ('webp' or 'avif').
	 * @return string The URL of the converted image.
	 * @since 1.0.0
	 */
	public static function get_img_url( string $source_image, string $format = 'webp' ): string {

		if ( 0 === strpos( $source_image, home_url() ) ) {
			// Replace the extension only at the end of the file name.
			$path_info     = pathinfo( $source_image );
			$converted_img = $path_info['dirname'] . '/' . $path_info['filename'] . '.' . $format;

			// Adjust for the wppo directory.
			$converted_img = str_replace( WP_CONTENT_URL, WP_CONTENT_URL . '/wppo', $converted_img );

			return $converted_img;
		}

		return $source_image;
	}


	/**
	 * Convert uploaded images to WebP or AVIF format upon attachment upload.
	 *
	 * @param array $metadata The attachment metadata.
	 * @param int   $attachment_id The attachment ID.
	 * @return array|\WP_Error The modified attachment metadata, or WP_Error on failure.
	 * @since 1.0.0
	 */
	public function convert_image_to_next_gen_format( $metadata, $attachment_id ) {
		$upload_dir = wp_upload_dir();

		try {
			// Get the full file path of the original image.
			$file = get_attached_file( $attachment_id );
			if ( ! file_exists( $file ) ) {
				return $metadata;
			}

			$img_url = wp_get_attachment_url( $attachment_id );
			if ( ! empty( $this->exclude_imgs ) ) {
				foreach ( $this->exclude_imgs as $exclude_img ) {
					if ( false !== strpos( $img_url, $exclude_img ) ) {
						return $metadata;
					}
				}
			}

			if ( in_array( $this->format, array( 'webp', 'both' ), true ) ) {
				$this->add_img_into_queue( $file );
			}

			if ( in_array( $this->format, array( 'avif', 'both' ), true ) ) {
				$this->add_img_into_queue( $file, 'avif' );
			}

			// Queue additional image sizes for conversion.
			if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				foreach ( $metadata['sizes'] as $size => $size_data ) {
					$image_path = wp_normalize_path( $upload_dir['path'] . '/' . $size_data['file'] );
					if ( file_exists( $image_path ) ) {
						if ( in_array( $this->format, array( 'webp', 'both' ), true ) ) {
							$this->add_img_into_queue( $image_path );
						}

						if ( in_array( $this->format, array( 'avif', 'both' ), true ) ) {
							$this->add_img_into_queue( $image_path, 'avif' );
						}
					}
				}
			}

			return $metadata;

		} catch ( \Exception $e ) {
			return $metadata;
		}
	}

	/**
	 * Serve WebP or AVIF images if supported by the browser.
	 *
	 * @param array $image The image source array.
	 * @return array Modified image source with WebP/AVIF if applicable, or original image if not.
	 * @since 1.0.0
	 */
	public function maybe_serve_next_gen_image( $image ) {
		if ( ! isset( $_SERVER['HTTP_ACCEPT'] ) || empty( $image[0] ) ) {
			return $image;
		}

		$http_accept = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) );

		// Check if the browser supports WebP.
		$supports_avif = strpos( $http_accept, 'image/avif' ) !== false;
		$supports_webp = strpos( $http_accept, 'image/webp' ) !== false;

		$img_path   = Util::get_local_path( $image[0] );
		$to_convert = false;

		if ( in_array( $this->format, array( 'avif', 'both' ), true ) ) {
			if ( $supports_avif || ( defined( 'DOING_CRON' ) && \DOING_CRON ) ) {
				$avif_path = $this->get_img_path( $img_path, 'avif' );

				if ( file_exists( $avif_path ) ) {
					$image[0] = $this->get_img_url( $image[0], 'avif' );
					return $image;
				} else {
					$this->add_img_into_queue( $img_path, 'avif' );
				}
			}
		}

		if ( in_array( $this->format, array( 'webp', 'both' ), true ) ) {
			if ( $supports_webp || ( defined( 'DOING_CRON' ) && \DOING_CRON ) ) {
				$webp_path = $this->get_img_path( $img_path, 'webp' );

				if ( file_exists( $webp_path ) ) {
					$image[0] = $this->get_img_url( $image[0] );
					return $image;
				} else {
					$this->add_img_into_queue( $img_path );
				}
			}
		}

		return $image;
	}

	/**
	 * Update the conversion status of an image.
	 *
	 * @param string $img_path The image path.
	 * @param string $status The status to update ('completed', 'failed', etc.).
	 * @param string $type The image format type ('webp', 'avif').
	 * @since 1.0.0
	 */
	public function update_conversion_status( $img_path, $status = 'completed', $type = 'webp' ) {
		$img_path = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $img_path ) );

		$img_info = get_option( 'wppo_img_info', array() );

		if ( 'completed' === $status ) {
			// Check and remove from 'pending' list.
			if ( isset( $img_info['pending'][ $type ] ) ) {
				$key = array_search( $img_path, $img_info['pending'][ $type ], true );
				if ( false !== $key ) {
					unset( $img_info['pending'][ $type ][ $key ] );
				}
			}

			// Check and remove from 'failed' list.
			if ( isset( $img_info['failed'][ $type ] ) ) {
				$key = array_search( $img_path, $img_info['failed'][ $type ], true );
				if ( false !== $key ) {
					unset( $img_info['failed'][ $type ][ $key ] );
				}
			}
		}

		if ( 'failed' === $status ) {
			if ( isset( $img_info['pending'][ $type ] ) ) {
				$key = array_search( $img_path, $img_info['pending'][ $type ], true );
				if ( false !== $key ) {
					unset( $img_info['pending'][ $type ][ $key ] );
				}
			}
		}

		if ( ! in_array( $img_path, $img_info[ $status ][ $type ] ?? array(), true ) ) {
			$img_info[ $status ][ $type ][] = $img_path;
		}

		update_option( 'wppo_img_info', $img_info );
	}

	/**
	 * Add an image to the conversion queue.
	 *
	 * @param string $img_path The image path.
	 * @param string $type The image format type ('webp', 'avif').
	 * @since 1.0.0
	 */
	public static function add_img_into_queue( $img_path, $type = 'webp' ) {
		if ( pathinfo( $img_path, PATHINFO_EXTENSION ) === $type ) {
			return;
		}

		$img_path = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $img_path ) );

		$img_info = get_option( 'wppo_img_info', array() );

		if ( ! in_array( $img_path, $img_info['pending'][ $type ] ?? array(), true ) ) {
			$img_info['pending'][ $type ][] = $img_path;

			update_option( 'wppo_img_info', $img_info );
		}
	}
}
