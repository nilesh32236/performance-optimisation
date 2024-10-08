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
		error_log( $source_image );
		$image_info = getimagesize( $source_image );

		if ( empty( $image_info ) ) {
			return false;
		}

		error_log( 'image_info: ' . print_r( $image_info, true ) );
		$image_type = $image_info[2];

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

		// Create WebP image
		if ( ! imagewebp( $image, $destination, $quality ) ) {
			return false;
		}

		imagedestroy( $image ); // Clean up memory
		return true;
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
}
