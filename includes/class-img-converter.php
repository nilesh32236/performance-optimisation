<?php
namespace PerformanceOptimise\Inc;

class Img_Converter {

	private $options;

	private array $available_format = array(
		'webp',
		'avif',
		'both',
	);

	private $format;

	private $exclude_imgs = array();
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
	 * @param int $quality Quality level of the converted image (0-100).
	 * @return bool True on success, false on failure.
	 */
	public function convert_image( string $source_image, string $format = 'webp', int $quality = 80 ): bool {

		if ( ! in_array( $format, $this->available_format, true ) ) {
			error_log( 'Invalid image format specified: ' . $format );
			return false;
		}

		if ( ! function_exists( 'imagecreatefromjpeg' ) || ! function_exists( 'imagecreatefrompng' ) ) {
			error_log( 'Required image functions do not exist. Check if GD library is installed.' );
			return false;
		}

		if ( ! file_exists( $source_image ) ) {
			error_log( 'Source image is not found' );
			return false;
		}

		$image_info = getimagesize( $source_image );

		if ( empty( $image_info ) ) {
			error_log( 'Invalid image file: ' . $source_image );
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
						error_log( 'Failed to create image from PNG: ' . $source_image );
						return false;
					}

					$image = $this->convert_palette_to_truecolor( $image );
					imagealphablending( $image, true ); // For transparency
					imagesavealpha( $image, true );
					break;

				case IMAGETYPE_WEBP:
					error_log( 'Image is in WebP format: ' . $source_image );

					if ( in_array( $format, array( 'avif', 'both' ), true ) ) {
						if ( ! function_exists( 'imageavif' ) ) {
							error_log( 'AVIF conversion not supported by the server for WebP images.' );
							return false;
						}

						$image = imagecreatefromwebp( $source_image );
						if ( ! $image ) {
							error_log( 'Failed to create image resource from WebP: ' . $source_image );
							return false;
						}

						$avif_path = $this->get_img_path( $source_image, 'avif' );
						if ( imageavif( $image, $avif_path, $quality ) ) {
							error_log( 'Successfully converted WebP to AVIF: ' . $avif_path );
						} else {
							error_log( 'Failed to convert WebP to AVIF: ' . $source_image );
							imagedestroy( $image );
							return false;
						}
						imagedestroy( $image );
					}
					return true;
				default:
					error_log( 'Unsupported image type for WebP conversion: ' . $source_image );
					return false; // Unsupported format
			}

			$success = true;

			if ( in_array( $format, array( 'webp', 'both' ), true ) ) {
				$webp_path = $this->get_img_path( $source_image, 'webp' );

				if ( ! file_exists( $webp_path ) ) {
					if ( ! function_exists( 'imagewebp' ) || ! imagewebp( $image, $webp_path, $quality ) ) {
						$success = false;
						error_log( 'Failed to convert image to WebP: ' . $source_image );
					} else {
						$current_count = get_option( 'qtpo_webp_converted', 0 );
						$new_count     = $current_count + 1;
						update_option( 'qtpo_webp_converted', $new_count );
						error_log( 'WebP conversion successful: ' . $webp_path );
					}
				}
			}

			if ( in_array( $format, array( 'avif', 'both' ), true ) ) {
				$avif_path = $this->get_img_path( $source_image, 'avif' );

				if ( ! file_exists( $avif_path ) ) {
					if ( ! function_exists( 'imageavif' ) || ! imageavif( $image, $avif_path, $quality ) ) {
						error_log( 'Failed to convert image to AVIF: ' . $source_image );
					} else {
						$current_count = get_option( 'qtpo_avif_converted', 0 );
						$new_count     = $current_count + 1;
						update_option( 'qtpo_avif_converted', $new_count );
						error_log( 'AVIF conversion successful: ' . $avif_path );

					}
				}
			}

			imagedestroy( $image ); // Clean up memory
			return $success;
		} catch ( \Exception $e ) {

			error_log( 'WebP conversion error: ' . $e->getMessage() );

			// Clean up memory if image resource was created
			if ( is_resource( $image ) ) {
				imagedestroy( $image );
			}

			return false;
		}
	}

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
	 * Get the WebP file path.
	 *
	 * @param string $source_image The source image path.
	 * @return string The path where the WebP image will be saved.
	 */
	public function get_img_path( string $source_image, string $format = 'webp' ): string {
		$info = pathinfo( $source_image );
		return $info['dirname'] . '/' . $info['filename'] . '.' . $format;
	}

	/**
	 * Convert uploaded images to WebP format with error handling.
	 *
	 * @param array $metadata The attachment metadata.
	 * @param int $attachment_id The attachment ID.
	 * @return array|\WP_Error The modified attachment metadata, or WP_Error on failure.
	 */
	public function convert_images_to_next_gen( $metadata, $attachment_id ) {
		$upload_dir = wp_upload_dir();

		try {
			// Get the full file path of the original image
			$file = get_attached_file( $attachment_id );
			if ( ! file_exists( $file ) ) {
				error_log( 'Original image file not found: ' . $file );
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

			// Convert the original image to WebP
			// $webp_file = $this->get_img_path( $file );
			$converted = $this->convert_image( $file, $this->format );

			if ( ! $converted ) {
				error_log( 'Failed to convert original image to WebP: ' . $file );
				return $metadata;
			}

			// Convert additional image sizes to WebP
			if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				foreach ( $metadata['sizes'] as $size => $size_data ) {
					$image_path = $upload_dir['path'] . '/' . $size_data['file'];
					if ( ! file_exists( $image_path ) ) {
						error_log( 'Image size file not found: ' . $image_path );
						continue;
					}

					$size_converted = $this->convert_image( $image_path, $this->format );

					if ( ! $size_converted ) {
						error_log( 'Failed to convert image size to WebP: ' . $image_path );
						continue;
					}
				}
			}

			return $metadata;

		} catch ( \Exception $e ) {
			error_log( 'WebP conversion error: ' . $e->getMessage() );
			return $metadata;
		}
	}

	/**
	 * Serve WebP images if available and supported by the browser, with error handling.
	 *
	 * @param array $image The image source array.
	 * @param int $attachment_id The attachment ID.
	 * @param string|array $size The requested size.
	 * @param bool $icon Whether the image is an icon.
	 * @return array Modified image source with WebP if applicable, or original image if an error occurs.
	 */
	public function maybe_serve_next_gen_image( $image, $attachment_id, $size, $icon ) {
		if ( ! isset( $_SERVER['HTTP_ACCEPT'] ) || empty( $image[0] ) ) {
			return $image;
		}

		$img_path = Util::get_local_path( $image[0] );

		if ( in_array( $this->format, array( 'avif', 'both' ), true ) ) {
			if ( false !== strpos( $_SERVER['HTTP_ACCEPT'], 'image/avif' ) ) {
				$avif_path = $this->get_img_path( $img_path, 'avif' );

				if ( file_exists( $avif_path ) ) {
					$image[0] = str_replace( pathinfo( $image[0], PATHINFO_EXTENSION ), 'avif', $image[0] );
					return $image;
				} else {
					if ( $this->convert_image( $img_path, 'avif' ) ) {
						$image[0] = str_replace( pathinfo( $image[0], PATHINFO_EXTENSION ), 'avif', $image[0] );
						return $image;
					}
				}
			}
		}

		if ( in_array( $this->format, array( 'webp', 'both' ), true ) ) {
			if ( false !== strpos( $_SERVER['HTTP_ACCEPT'], 'image/webp' ) ) {
				$webp_path = $this->get_img_path( $img_path, 'webp' );

				if ( file_exists( $webp_path ) ) {
					$image[0] = str_replace( pathinfo( $image[0], PATHINFO_EXTENSION ), 'webp', $image[0] );
					return $image;
				} else {
					if ( $this->convert_image( $img_path, 'webp' ) ) {
						$image[0] = str_replace( pathinfo( $image[0], PATHINFO_EXTENSION ), 'webp', $image[0] );
						return $image;
					}
				}
			}
		}

		return $image;
	}
}
