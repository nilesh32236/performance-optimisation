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

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Img_Converter Class
 *
 * Handles image format conversions to WebP and AVIF.
 *
 * @since 1.0.0
 */
class Img_Converter {

	/**
	 * Configuration options for image optimization.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.0
	 */
	private array $options;

	/**
	 * Image optimization specific settings.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.0
	 */
	private array $img_opt_settings;

	/**
	 * Filesystem object.
	 *
	 * @var \WP_Filesystem_Base|null
	 */
	private ?\WP_Filesystem_Base $filesystem;

	/**
	 * Img_Converter constructor.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $options Options for configuring image optimization.
	 */
	public function __construct( array $options ) {
		$this->options          = $options;
		$this->img_opt_settings = $this->options['image_optimisation'] ?? array();
		$this->filesystem       = Util::init_filesystem();
	}

	/**
	 * Convert an image to WebP or AVIF format.
	 *
	 * @since 1.0.0
	 * @param string $source_image_path Absolute path to the source image.
	 * @param string $target_format     The desired format ('webp' or 'avif'). Default 'webp'.
	 * @param int    $quality           Quality level of the converted image (1-100). Default -1 (uses GD default or Imagick quality).
	 * @return bool True on success, false on failure.
	 */
	public function convert_image( string $source_image_path, string $target_format = 'webp', int $quality = -1 ): bool {
		if ( ! $this->filesystem || ! $this->filesystem->exists( $source_image_path ) || ! $this->filesystem->is_readable( $source_image_path ) ) {
			$this->update_conversion_status( $source_image_path, 'failed', $target_format, 'Source file not found or not readable.' );
			return false;
		}

		// Ensure target format is valid.
		if ( ! in_array( $target_format, array( 'webp', 'avif' ), true ) ) {
			$this->update_conversion_status( $source_image_path, 'failed', $target_format, 'Invalid target format specified.' );
			return false;
		}

		if ( 'webp' === $target_format && ! function_exists( 'imagewebp' ) ) {
			$this->update_conversion_status( $source_image_path, 'failed', $target_format, 'WebP support (imagewebp) not available in GD.' );
			return false;
		}
		if ( 'avif' === $target_format && ! function_exists( 'imageavif' ) ) {
			$this->update_conversion_status( $source_image_path, 'failed', $target_format, 'AVIF support (imageavif) not available in GD.' );
			return false;
		}

		$converted_image_path = self::get_img_path( $source_image_path, $target_format );
		if ( $this->filesystem->exists( $converted_image_path ) && $this->filesystem->mtime( $converted_image_path ) >= $this->filesystem->mtime( $source_image_path ) ) {
			$this->update_conversion_status( $source_image_path, 'completed', $target_format, 'Converted file already exists and is up-to-date.' );
			return true;
		}

		if ( ! Util::prepare_cache_dir( dirname( $converted_image_path ) ) ) {
			$this->update_conversion_status( $source_image_path, 'failed', $target_format, 'Could not create cache directory for converted image.' );
			return false;
		}

		$image_info = getimagesize( $source_image_path );
		if ( false === $image_info ) {
			$this->update_conversion_status( $source_image_path, 'failed', $target_format, 'Could not get image size/type.' );
			return false;
		}
		$image_type = $image_info[2];

		if ( $quality < 1 || $quality > 100 ) {
			$quality = 'webp' === $target_format ? 82 : 50; // Common defaults.
			$quality = apply_filters( "wppo_conversion_quality_{$target_format}", $quality, $source_image_path );
		}

		if ( IMAGETYPE_GIF === $image_type ) {
			if ( 'webp' === $target_format && class_exists( '\Imagick' ) ) {
				return $this->convert_gif_to_webp_imagick( $source_image_path, $converted_image_path, $quality );
			} elseif ( 'avif' === $target_format ) {
				$this->update_conversion_status( $source_image_path, 'skipped', $target_format, 'AVIF conversion for GIF not supported well by GD.' );
				return false;
			}
		}

		$image_resource = null;
		switch ( $image_type ) {
			case IMAGETYPE_JPEG:
				$image_resource = imagecreatefromjpeg( $source_image_path );
				break;
			case IMAGETYPE_PNG:
				$image_resource = imagecreatefrompng( $source_image_path );
				if ( $image_resource ) {
					imagepalettetotruecolor( $image_resource ); // Ensure truecolor for alpha blending.
					imagealphablending( $image_resource, true );
					imagesavealpha( $image_resource, true );
				}
				break;
			case IMAGETYPE_GIF:
				$image_resource = imagecreatefromgif( $source_image_path );
				break;
			case IMAGETYPE_WEBP:
				if ( 'avif' === $target_format ) {
					$image_resource = imagecreatefromwebp( $source_image_path );
				} else {
					$this->update_conversion_status( $source_image_path, 'skipped', $target_format, 'Source is already WebP.' );
					return true;
				}
				break;
			default:
				$this->update_conversion_status( $source_image_path, 'failed', $target_format, 'Unsupported source image type for GD conversion.' );
				return false;
		}

		if ( ! $image_resource ) {
			$this->update_conversion_status( $source_image_path, 'failed', $target_format, 'Could not create image resource from source.' );
			return false;
		}

		$conversion_success = false;
		if ( 'webp' === $target_format ) {
			$conversion_success = imagewebp( $image_resource, $converted_image_path, $quality );
		} elseif ( 'avif' === $target_format ) {
			$conversion_success = imageavif( $image_resource, $converted_image_path, $quality, -1 ); // AVIF speed can be -1 for default.
		}

		imagedestroy( $image_resource );

		if ( $conversion_success ) {
			$this->update_conversion_status( $source_image_path, 'completed', $target_format );
			return true;
		} else {
			if ( $this->filesystem->exists( $converted_image_path ) ) {
				$this->filesystem->delete( $converted_image_path );
			}
			$this->update_conversion_status( $source_image_path, 'failed', $target_format, 'GD conversion function failed.' );
			return false;
		}
	}

	/**
	 * Converts GIF to animated WebP using Imagick.
	 *
	 * @param string $source_gif_path Path to the source GIF.
	 * @param string $target_webp_path Path to save the converted WebP.
	 * @param int    $quality Conversion quality.
	 * @return bool True on success, false on failure.
	 */
	private function convert_gif_to_webp_imagick( string $source_gif_path, string $target_webp_path, int $quality ): bool {
		try {
			$imagick = new \Imagick();
			$imagick->readImage( $source_gif_path );
			$imagick = $imagick->coalesceImages(); // Important for animations.

			$imagick->setImageFormat( 'webp' );
			$imagick->setImageCompressionQuality( $quality ); // General quality.
			$imagick->setOption( 'webp:lossless', 'false' ); // Or true based on needs.
			if ( $imagick->getImageAlphaChannel() ) {
				$imagick->setOption( 'webp:alpha-compression', '1' ); // Enable alpha compression.
				$imagick->setOption( 'webp:alpha-quality', '100' ); // Lossless alpha.
			}

			if ( $imagick->writeImages( $target_webp_path, true ) ) {
				$this->update_conversion_status( $source_gif_path, 'completed', 'webp', 'Converted GIF to WebP using Imagick.' );
				$imagick->clear();
				return true;
			} else {
				$this->update_conversion_status( $source_gif_path, 'failed', 'webp', 'Imagick writeImages failed for GIF to WebP.' );
			}
			$imagick->clear();
		} catch ( \Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'Imagick GIF to WebP conversion error: ' . $e->getMessage() );
			}
			$this->update_conversion_status( $source_gif_path, 'failed', 'webp', 'Exception during Imagick GIF to WebP conversion: ' . $e->getMessage() );
		}
		return false;
	}


	/**
	 * Get the local filesystem path for the converted image.
	 * The converted images are stored in `wp-content/wppo/uploads/...`
	 * mirroring the original structure from `wp-content/uploads/...`.
	 *
	 * @since 1.0.0
	 * @param string $source_image_local_path Absolute local path to the source image.
	 * @param string $target_format           The desired format ('webp' or 'avif'). Default 'webp'.
	 * @return string The absolute local path where the converted image will be saved.
	 */
	public static function get_img_path( string $source_image_local_path, string $target_format = 'webp' ): string {
		$normalized_source_path = wp_normalize_path( $source_image_local_path );
		$wp_content_dir         = wp_normalize_path( WP_CONTENT_DIR );

		$filename_no_ext = pathinfo( $normalized_source_path, PATHINFO_FILENAME );
		$new_filename    = $filename_no_ext . '.' . $target_format;

		if ( str_starts_with( $normalized_source_path, $wp_content_dir ) ) {
			$relative_path_from_content = ltrim( str_replace( $wp_content_dir, '', $normalized_source_path ), '/\\' );
			$original_dirname_relative  = dirname( $relative_path_from_content );

			$new_image_dir_absolute = wp_normalize_path( $wp_content_dir . '/wppo/' . $original_dirname_relative );
		} else {
			$abspath                    = wp_normalize_path( ABSPATH );
			$relative_path_from_abspath = ltrim( str_replace( $abspath, '', $normalized_source_path ), '/\\' );
			$original_dirname_relative  = dirname( $relative_path_from_abspath );

			$new_image_dir_absolute = wp_normalize_path( $wp_content_dir . '/wppo/' . $original_dirname_relative );
		}

		return trailingslashit( $new_image_dir_absolute ) . $new_filename;
	}


	/**
	 * Get the URL of the converted image.
	 *
	 * @since 1.0.0
	 * @param string $source_image_url URL of the source image.
	 * @param string $target_format    The desired format ('webp' or 'avif'). Default 'webp'.
	 * @return string The URL of the converted image.
	 */
	public static function get_img_url( string $source_image_url, string $target_format = 'webp' ): string {
		$source_url_path = wp_parse_url( $source_image_url, PHP_URL_PATH );
		if ( empty( $source_url_path ) ) {
			return $source_image_url;
		}

		$filename_no_ext = pathinfo( $source_url_path, PATHINFO_FILENAME );
		$new_filename    = $filename_no_ext . '.' . $target_format;

		$source_image_dir_path = dirname( $source_url_path );

		$content_url            = content_url();
		$site_url               = site_url();
		$converted_path_segment = '';

		if ( str_starts_with( $source_image_url, $content_url ) ) {
			$relative_dir_from_content = ltrim( str_replace( $content_url, '', $source_image_dir_path ), '/' );
			$relative_dir_from_content = ltrim( str_replace( 'wp-content', '', $relative_dir_from_content ), '/' );
			$converted_path_segment    = 'wppo/' . $relative_dir_from_content;
		} elseif ( str_starts_with( $source_image_url, $site_url ) ) {
			$relative_dir_from_site = ltrim( str_replace( $site_url, '', $source_image_dir_path ), '/' );
			$converted_path_segment = 'wppo/' . $relative_dir_from_site;
		} else {
			return $source_image_url;
		}
		$converted_path_segment = preg_replace( '#/+#', '/', $converted_path_segment ); // Remove double slashes.
		$converted_path_segment = rtrim( $converted_path_segment, '/' );

		return trailingslashit( $content_url ) . trailingslashit( $converted_path_segment ) . $new_filename;
	}


	/**
	 * Convert uploaded images (and their generated sizes) to WebP or AVIF format upon attachment metadata generation.
	 * This method queues images for conversion.
	 *
	 * @since 1.0.0
	 * @param array<string,mixed> $metadata      The attachment metadata.
	 * @param int                 $attachment_id The attachment ID.
	 * @return array<string,mixed> The (unmodified) attachment metadata.
	 */
	public function convert_uploaded_image_and_sizes( array $metadata, int $attachment_id ): array {
		$original_file_path = get_attached_file( $attachment_id ); // Absolute path.
		if ( ! $original_file_path || ! file_exists( $original_file_path ) ) {
			return $metadata;
		}

		$original_image_url = wp_get_attachment_url( $attachment_id );
		$exclude_patterns   = Util::process_urls( (string) ( $this->img_opt_settings['excludeConvertImages'] ?? '' ) );
		foreach ( $exclude_patterns as $pattern ) {
			if ( str_contains( $original_image_url, $pattern ) ) {
				return $metadata;
			}
		}

		$target_formats_to_queue   = array();
		$conversion_format_setting = $this->img_opt_settings['conversionFormat'] ?? 'webp';
		if ( in_array( $conversion_format_setting, array( 'webp', 'both' ), true ) ) {
			$target_formats_to_queue[] = 'webp';
		}
		if ( in_array( $conversion_format_setting, array( 'avif', 'both' ), true ) ) {
			$target_formats_to_queue[] = 'avif';
		}

		if ( empty( $target_formats_to_queue ) ) {
			return $metadata;
		}

		foreach ( $target_formats_to_queue as $format ) {
			self::add_img_into_queue( $original_file_path, $format );
		}

		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$upload_dir_info = wp_upload_dir();
			foreach ( $metadata['sizes'] as $size_data ) {
				if ( isset( $size_data['file'] ) ) {
					$size_file_path = wp_normalize_path( trailingslashit( $upload_dir_info['path'] ) . $size_data['file'] );
					if ( file_exists( $size_file_path ) ) {
						foreach ( $target_formats_to_queue as $format ) {
							self::add_img_into_queue( $size_file_path, $format );
						}
					}
				}
			}
		}

		return $metadata;
	}


	/**
	 * Update the conversion status of an image.
	 *
	 * @since 1.0.0
	 * @param string $source_image_path Absolute path of the source image.
	 * @param string $status            The status ('completed', 'failed', 'skipped').
	 * @param string $target_format     The image format type ('webp', 'avif').
	 * @param string $message           Optional message regarding the status.
	 */
	public function update_conversion_status( string $source_image_path, string $status, string $target_format, string $message = '' ): void {
		$relative_img_path = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $source_image_path ) );
		$relative_img_path = ltrim( $relative_img_path, '/' );

		$img_info = get_option( 'wppo_img_info', array() );

		$img_info['pending'][ $target_format ]   = $img_info['pending'][ $target_format ] ?? array();
		$img_info['completed'][ $target_format ] = $img_info['completed'][ $target_format ] ?? array();
		$img_info['failed'][ $target_format ]    = $img_info['failed'][ $target_format ] ?? array();
		$img_info['skipped'][ $target_format ]   = $img_info['skipped'][ $target_format ] ?? array();

		$pending_key = array_search( $relative_img_path, $img_info['pending'][ $target_format ], true );
		if ( false !== $pending_key ) {
			unset( $img_info['pending'][ $target_format ][ $pending_key ] );
			$img_info['pending'][ $target_format ] = array_values( $img_info['pending'][ $target_format ] );
		}

		foreach ( array( 'completed', 'failed', 'skipped' ) as $list_type ) {
			if ( $list_type === $status ) {
				continue;
			}
			$key_in_other_list = array_search( $relative_img_path, $img_info[ $list_type ][ $target_format ], true );
			if ( false !== $key_in_other_list ) {
				unset( $img_info[ $list_type ][ $target_format ][ $key_in_other_list ] );
				$img_info[ $list_type ][ $target_format ] = array_values( $img_info[ $list_type ][ $target_format ] );
			}
		}

		if ( ! in_array( $relative_img_path, $img_info[ $status ][ $target_format ], true ) ) {
			$img_info[ $status ][ $target_format ][] = $relative_img_path;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! empty( $message ) && ( 'failed' === $status || 'skipped' === $status ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "WPPO Img Convert [{$target_format}][{$status}]: {$relative_img_path} - {$message}" );
		}

		update_option( 'wppo_img_info', $img_info );
	}

	/**
	 * Add an image to the conversion queue.
	 *
	 * @since 1.0.0
	 * @param string $source_image_path Absolute path of the source image.
	 * @param string $target_format     The image format type ('webp', 'avif'). Default 'webp'.
	 */
	public static function add_img_into_queue( string $source_image_path, string $target_format = 'webp' ): void {
		$source_extension = strtolower( pathinfo( $source_image_path, PATHINFO_EXTENSION ) );
		if ( $source_extension === $target_format ) {
			return;
		}

		$relative_img_path = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $source_image_path ) );
		$relative_img_path = ltrim( $relative_img_path, '/' ); // Ensure it's relative from ABSPATH root.

		$img_info = get_option( 'wppo_img_info', array() );

		$img_info['pending'][ $target_format ]   = $img_info['pending'][ $target_format ] ?? array();
		$img_info['completed'][ $target_format ] = $img_info['completed'][ $target_format ] ?? array();
		$img_info['failed'][ $target_format ]    = $img_info['failed'][ $target_format ] ?? array();
		$img_info['skipped'][ $target_format ]   = $img_info['skipped'][ $target_format ] ?? array();

		if ( in_array( $relative_img_path, $img_info['completed'][ $target_format ], true ) ||
			in_array( $relative_img_path, $img_info['failed'][ $target_format ], true ) ||
			in_array( $relative_img_path, $img_info['skipped'][ $target_format ], true ) ) {
			return;
		}

		if ( ! in_array( $relative_img_path, $img_info['pending'][ $target_format ], true ) ) {
			$img_info['pending'][ $target_format ][] = $relative_img_path;
			update_option( 'wppo_img_info', $img_info );
		}
	}
}
