<?php
/**
 * Image Utility
 *
 * Provides comprehensive image processing utilities with format detection,
 * optimization helpers, and dimension calculations.
 *
 * @package PerformanceOptimisation\Utils
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ImageUtil Class
 *
 * Centralized image processing utilities for format detection, validation,
 * and optimization operations.
 *
 * @package PerformanceOptimisation\Utils
 * @since 2.0.0
 */
class ImageUtil {


	/**
	 * Supported image formats.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	private const SUPPORTED_FORMATS = array(
		'jpg',
		'jpeg',
		'png',
		'gif',
		'webp',
		'avif',
		'svg',
	);

	/**
	 * MIME type mappings.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	private const MIME_TYPES = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
		'avif' => 'image/avif',
		'svg'  => 'image/svg+xml',
	);

	/**
	 * Modern image formats for optimization.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	private const MODERN_FORMATS = array( 'webp', 'avif' );

	/**
	 * Get image MIME type from URL or path.
	 *
	 * @since 2.0.0
	 *
	 * @param string $url_or_path Image URL or file path.
	 * @return string MIME type.
	 */
	public static function getImageMimeType( string $url_or_path ): string {
		$extension = strtolower( pathinfo( $url_or_path, PATHINFO_EXTENSION ) );

		return self::MIME_TYPES[ $extension ] ?? 'image/jpeg';
	}

	/**
	 * Check if file is a supported image format.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path     File path.
	 * @param array  $formats  Allowed formats (optional).
	 * @return bool True if supported format, false otherwise.
	 */
	public static function isImageFormat( string $path, array $formats = array() ): bool {
		$extension       = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$allowed_formats = ! empty( $formats ) ? $formats : self::SUPPORTED_FORMATS;

		return in_array( $extension, $allowed_formats, true );
	}

	/**
	 * Get image dimensions from file path.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path Image file path.
	 * @return array Array with 'width' and 'height' keys, or empty array on failure.
	 */
	public static function getImageDimensions( string $path ): array {
		if ( ! FileSystemUtil::fileExists( $path ) || ! self::isImageFormat( $path ) ) {
			return array();
		}

		try {
			$image_info = getimagesize( $path );

			if ( false === $image_info ) {
				return array();
			}

			return array(
				'width'  => $image_info[0],
				'height' => $image_info[1],
				'type'   => $image_info[2],
				'mime'   => $image_info['mime'] ?? self::getImageMimeType( $path ),
			);
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Failed to get image dimensions: ' . $e->getMessage(), array( 'path' => $path ) );
			return array();
		}
	}

	/**
	 * Calculate image file size.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path Image file path.
	 * @return int File size in bytes, 0 on failure.
	 */
	public static function calculateImageSize( string $path ): int {
		if ( ! FileSystemUtil::fileExists( $path ) || ! self::isImageFormat( $path ) ) {
			return 0;
		}

		return FileSystemUtil::getFileSize( $path );
	}

	/**
	 * Generate image variants for different sizes.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path  Original image path.
	 * @param array  $sizes Array of size configurations.
	 * @return array Array of generated variant paths.
	 */
	public static function generateImageVariants( string $path, array $sizes ): array {
		$variants = array();

		if ( ! FileSystemUtil::fileExists( $path ) || ! self::isImageFormat( $path ) ) {
			return $variants;
		}

		$original_dimensions = self::getImageDimensions( $path );
		if ( empty( $original_dimensions ) ) {
			return $variants;
		}

		foreach ( $sizes as $size_name => $size_config ) {
			$variant_path = self::generateVariantPath( $path, $size_name, $size_config );

			if ( self::shouldGenerateVariant( $original_dimensions, $size_config ) ) {
				$variants[ $size_name ] = array(
					'path'   => $variant_path,
					'width'  => $size_config['width'] ?? null,
					'height' => $size_config['height'] ?? null,
					'crop'   => $size_config['crop'] ?? false,
				);
			}
		}

		return $variants;
	}

	/**
	 * Optimize image path for web delivery.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path     Original image path.
	 * @param string $format   Target format (webp, avif, etc.).
	 * @return string Optimized image path.
	 */
	public static function optimizeImagePath( string $path, string $format = 'webp' ): string {
		if ( ! self::isImageFormat( $path ) ) {
			return $path;
		}

		$path           = wp_normalize_path( $path );
		$wp_content_dir = wp_normalize_path( WP_CONTENT_DIR );
		$path_info      = pathinfo( $path );

		$relative_path = dirname( str_replace( $wp_content_dir, '', $path ) );
		$optimized_dir = wp_normalize_path( $wp_content_dir . '/wppo/' . $relative_path );

		// Ensure directory exists
		if ( ! FileSystemUtil::fileExists( $optimized_dir ) ) {
			FileSystemUtil::createDirectory( $optimized_dir );
		}

		return wp_normalize_path( $optimized_dir . '/' . $path_info['filename'] . '.' . $format );
	}

	/**
	 * Check if image is already optimized.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path Image path.
	 * @return bool True if optimized, false otherwise.
	 */
	public static function isImageOptimized( string $path ): bool {
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		// Check if it's already in a modern format
		if ( in_array( $extension, self::MODERN_FORMATS, true ) ) {
			return true;
		}

		// Check if optimized version exists
		$webp_path = self::optimizeImagePath( $path, 'webp' );
		$avif_path = self::optimizeImagePath( $path, 'avif' );

		return FileSystemUtil::fileExists( $webp_path ) || FileSystemUtil::fileExists( $avif_path );
	}

	/**
	 * Get image compression ratio between original and optimized.
	 *
	 * @since 2.0.0
	 *
	 * @param string $original_path   Original image path.
	 * @param string $optimized_path  Optimized image path.
	 * @return float Compression ratio (0.0 to 1.0).
	 */
	public static function getImageCompressionRatio( string $original_path, string $optimized_path ): float {
		$original_size  = self::calculateImageSize( $original_path );
		$optimized_size = self::calculateImageSize( $optimized_path );

		if ( 0 === $original_size || 0 === $optimized_size ) {
			return 0.0;
		}

		return round( 1 - ( $optimized_size / $original_size ), 3 );
	}

	/**
	 * Get responsive image srcset for an image.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path   Original image path.
	 * @param array  $sizes  Array of responsive sizes.
	 * @return string Srcset string.
	 */
	public static function generateResponsiveSrcset( string $path, array $sizes ): string {
		$srcset_parts = array();
		$variants     = self::generateImageVariants( $path, $sizes );

		foreach ( $variants as $size_name => $variant ) {
			if ( isset( $variant['width'] ) ) {
				$url            = FileSystemUtil::pathToUrl( $variant['path'] );
				$srcset_parts[] = $url . ' ' . $variant['width'] . 'w';
			}
		}

		return implode( ', ', $srcset_parts );
	}

	/**
	 * Get image aspect ratio.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path Image path.
	 * @return float Aspect ratio (width/height).
	 */
	public static function getImageAspectRatio( string $path ): float {
		$dimensions = self::getImageDimensions( $path );

		if ( empty( $dimensions ) || 0 === $dimensions['height'] ) {
			return 0.0;
		}

		return round( $dimensions['width'] / $dimensions['height'], 3 );
	}

	/**
	 * Check if image needs optimization based on size and format.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path Image path.
	 * @param array  $criteria Optimization criteria.
	 * @return bool True if needs optimization, false otherwise.
	 */
	public static function needsOptimization( string $path, array $criteria = array() ): bool {
		if ( ! self::isImageFormat( $path ) ) {
			return false;
		}

		$extension  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$file_size  = self::calculateImageSize( $path );
		$dimensions = self::getImageDimensions( $path );

		// Default criteria
		$max_file_size       = $criteria['max_file_size'] ?? 500000; // 500KB
		$max_width           = $criteria['max_width'] ?? 1920;
		$max_height          = $criteria['max_height'] ?? 1080;
		$modern_formats_only = $criteria['modern_formats_only'] ?? false;

		// Check if already in modern format
		if ( $modern_formats_only && in_array( $extension, self::MODERN_FORMATS, true ) ) {
			return false;
		}

		// Check file size
		if ( $file_size > $max_file_size ) {
			return true;
		}

		// Check dimensions
		if ( ! empty( $dimensions ) ) {
			if ( $dimensions['width'] > $max_width || $dimensions['height'] > $max_height ) {
				return true;
			}
		}

		// Check if not in modern format
		if ( ! in_array( $extension, self::MODERN_FORMATS, true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get optimal image format based on browser support and image characteristics.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path           Original image path.
	 * @param array  $browser_support Browser support information.
	 * @return string Optimal format.
	 */
	public static function getOptimalFormat( string $path, array $browser_support = array() ): string {
		$original_extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		// Default browser support (can be overridden)
		$default_support = array(
			'avif' => false,
			'webp' => true,
			'jpeg' => true,
			'png'  => true,
		);

		$support = array_merge( $default_support, $browser_support );

		// Prefer AVIF if supported
		if ( $support['avif'] ) {
			return 'avif';
		}

		// Fallback to WebP if supported
		if ( $support['webp'] ) {
			return 'webp';
		}

		// Keep original format if modern formats not supported
		return $original_extension;
	}

	/**
	 * Calculate potential space savings from optimization.
	 *
	 * @since 2.0.0
	 *
	 * @param array $images Array of image paths.
	 * @param array $formats Target formats to calculate savings for.
	 * @return array Savings information.
	 */
	public static function calculateOptimizationSavings( array $images, array $formats = array( 'webp' ) ): array {
		$total_original_size  = 0;
		$total_optimized_size = 0;
		$processed_count      = 0;

		foreach ( $images as $image_path ) {
			if ( ! self::isImageFormat( $image_path ) ) {
				continue;
			}

			$original_size        = self::calculateImageSize( $image_path );
			$total_original_size += $original_size;

			foreach ( $formats as $format ) {
				$optimized_path = self::optimizeImagePath( $image_path, $format );

				if ( FileSystemUtil::fileExists( $optimized_path ) ) {
					$optimized_size        = self::calculateImageSize( $optimized_path );
					$total_optimized_size += $optimized_size;
					++$processed_count;
					break; // Use first available optimized format
				}
			}
		}

		$savings_bytes      = $total_original_size - $total_optimized_size;
		$savings_percentage = $total_original_size > 0 ? ( $savings_bytes / $total_original_size ) * 100 : 0;

		return array(
			'total_images'        => count( $images ),
			'processed_images'    => $processed_count,
			'original_size'       => $total_original_size,
			'optimized_size'      => $total_optimized_size,
			'savings_bytes'       => $savings_bytes,
			'savings_percentage'  => round( $savings_percentage, 2 ),
			'formatted_original'  => FileSystemUtil::formatFileSize( $total_original_size ),
			'formatted_optimized' => FileSystemUtil::formatFileSize( $total_optimized_size ),
			'formatted_savings'   => FileSystemUtil::formatFileSize( $savings_bytes ),
		);
	}

	/**
	 * Generate variant path for image size.
	 *
	 * @since 2.0.0
	 *
	 * @param string $original_path Original image path.
	 * @param string $size_name     Size name.
	 * @param array  $size_config   Size configuration.
	 * @return string Variant path.
	 */
	private static function generateVariantPath( string $original_path, string $size_name, array $size_config ): string {
		$path_info   = pathinfo( $original_path );
		$variant_dir = wp_normalize_path( WP_CONTENT_DIR . '/wppo/variants/' . dirname( str_replace( WP_CONTENT_DIR, '', $original_path ) ) );

		$width  = $size_config['width'] ?? 'auto';
		$height = $size_config['height'] ?? 'auto';
		$suffix = $size_name . '-' . $width . 'x' . $height;

		return wp_normalize_path( $variant_dir . '/' . $path_info['filename'] . '-' . $suffix . '.' . $path_info['extension'] );
	}

	/**
	 * Check if variant should be generated based on original dimensions.
	 *
	 * @since 2.0.0
	 *
	 * @param array $original_dimensions Original image dimensions.
	 * @param array $size_config         Size configuration.
	 * @return bool True if variant should be generated, false otherwise.
	 */
	private static function shouldGenerateVariant( array $original_dimensions, array $size_config ): bool {
		$original_width  = $original_dimensions['width'];
		$original_height = $original_dimensions['height'];

		$target_width  = $size_config['width'] ?? null;
		$target_height = $size_config['height'] ?? null;

		// Don't generate if target is larger than original
		if ( $target_width && $target_width > $original_width ) {
			return false;
		}

		if ( $target_height && $target_height > $original_height ) {
			return false;
		}

		return true;
	}

	/**
	 * Convert file path to URL.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path File path.
	 * @return string URL.
	 */
	private static function pathToUrl( string $path ): string {
		$content_dir = wp_normalize_path( WP_CONTENT_DIR );
		$content_url = content_url();

		return str_replace( $content_dir, $content_url, wp_normalize_path( $path ) );
	}
}
