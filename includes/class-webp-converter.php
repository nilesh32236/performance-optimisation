<?php
namespace PerformanceOptimise\Inc;

class WebP_Converter {

	/**
	 * Convert an image to WebP format.
	 *
	 * @param string $source_image Path to the source image.
	 * @param string $destination Path where the WebP image should be saved.
	 * @param int $quality Quality level of the WebP image (0-100).
	 * @return bool True on success, false on failure.
	 */
	public function convert_to_webp( string $source_image, string $destination, int $quality = 80 ): bool {

		if ( ! function_exists( 'imagecreatefromjpeg' ) || ! function_exists( 'imagecreatefrompng' ) || ! function_exists( 'imagewebp' ) ) {
			error_log( 'Required image functions do not exist. Check if GD library is installed.' );
			return false;
		}

		$image_info = getimagesize( $source_image );

		if ( empty( $image_info ) ) {
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
					imagealphablending( $image, true ); // For transparency
					imagesavealpha( $image, true );
					break;

				default:
					return false; // Unsupported format
			}

			if ( ! imagewebp( $image, $destination, $quality ) ) {
				error_log( 'Failed to convert image to WebP: ' . $source_image );
				return false;
			}

			error_log( 'WebP conversion successful: ' . $destination );
			imagedestroy( $image ); // Clean up memory
			return true;
		} catch ( \Exception $e ) {

			error_log( 'WebP conversion error: ' . $e->getMessage() );

			// Clean up memory if image resource was created
			if ( is_resource( $image ) ) {
				imagedestroy( $image );
			}

			return false;
		}

	}

	/**
	 * Get the WebP file path.
	 *
	 * @param string $source_image The source image path.
	 * @return string The path where the WebP image will be saved.
	 */
	public function get_webp_path( string $source_image ): string {
		$info = pathinfo( $source_image );
		return $info['dirname'] . '/' . $info['filename'] . '.webp';
	}

	/**
	 * Convert uploaded images to WebP format with error handling.
	 *
	 * @param array $metadata The attachment metadata.
	 * @param int $attachment_id The attachment ID.
	 * @return array|\WP_Error The modified attachment metadata, or WP_Error on failure.
	 */
	public function convert_images_to_webp( $metadata, $attachment_id ) {
		$upload_dir = wp_upload_dir();

		try {
			// Get the full file path of the original image
			$file = get_attached_file( $attachment_id );
			if ( ! file_exists( $file ) ) {
				error_log( 'Original image file not found: ' . $file );
				return $metadata;
			}

			// Convert the original image to WebP
			$webp_file = $this->get_webp_path( $file );
			$converted = $this->convert_to_webp( $file, $webp_file );

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

					$webp_path      = $this->get_webp_path( $image_path );
					$size_converted = $this->convert_to_webp( $image_path, $webp_path );

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
	public function maybe_serve_webp_image( $image, $attachment_id, $size, $icon ) {
		try {
			if ( ! isset( $_SERVER['HTTP_ACCEPT'] ) || empty( $image[0] ) || strpos( $_SERVER['HTTP_ACCEPT'], 'image/webp' ) === false ) {
				return $image;
			}

			// Check if the image is already in WebP format
			$image_extension = pathinfo( $image[0], PATHINFO_EXTENSION );
			if ( 'webp' === strtolower( $image_extension ) ) {
				return $image;
			}

			$webp_image_path = $this->get_webp_path( $image[0] );

			if ( ! file_exists( $webp_image_path ) ) {
				error_log( 'WebP image file not found: ' . $webp_image_path );
				return $image;
			}

			// Replace the original image URL with the WebP version
			$image[0] = str_replace( pathinfo( $image[0], PATHINFO_EXTENSION ), 'webp', $image[0] );

			return $image;

		} catch ( \Exception $e ) {
			error_log( 'Error serving WebP image: ' . $e->getMessage() );
			return $image; // Return original image if there's an error
		}
	}
}
