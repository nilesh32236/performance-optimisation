<?php
/**
 * Image Processor Interface
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Interfaces;

/**
 * Interface for image processors
 *
 * @since 1.1.0
 */
interface ImageProcessorInterface {

	/**
	 * Process an image
	 *
	 * @since 1.1.0
	 * @param string $source_path Source image path
	 * @param string $target_path Target image path
	 * @param array  $options     Processing options
	 * @return bool True on success, false on failure
	 */
	public function process( string $source_path, string $target_path, array $options = array() ): bool;

	/**
	 * Check if processor can handle the image type
	 *
	 * @since 1.1.0
	 * @param string $image_type Image MIME type
	 * @return bool True if can handle, false otherwise
	 */
	public function can_process( string $image_type ): bool;

	/**
	 * Get processor name
	 *
	 * @since 1.1.0
	 * @return string Processor name
	 */
	public function get_name(): string;

	/**
	 * Get supported image types
	 *
	 * @since 1.1.0
	 * @return array Array of supported MIME types
	 */
	public function get_supported_types(): array;

	/**
	 * Compress an image
	 *
	 * @since 1.1.0
	 * @param string $source_path Source image path
	 * @param string $target_path Target image path
	 * @param int    $quality     Compression quality (1-100)
	 * @return bool True on success, false on failure
	 */
	public function compress( string $source_path, string $target_path, int $quality = 85 ): bool;

	/**
	 * Resize an image
	 *
	 * @since 1.1.0
	 * @param string $source_path Source image path
	 * @param string $target_path Target image path
	 * @param int    $width       Target width
	 * @param int    $height      Target height
	 * @param bool   $crop        Whether to crop the image
	 * @return bool True on success, false on failure
	 */
	public function resize( string $source_path, string $target_path, int $width, int $height, bool $crop = false ): bool;

	/**
	 * Convert image to different format
	 *
	 * @since 1.1.0
	 * @param string $source_path   Source image path
	 * @param string $target_path   Target image path
	 * @param string $target_format Target format (webp, avif, etc.)
	 * @param int    $quality       Conversion quality (1-100)
	 * @return bool True on success, false on failure
	 */
	public function convert( string $source_path, string $target_path, string $target_format, int $quality = 85 ): bool;

	/**
	 * Generate responsive image sizes
	 *
	 * @since 1.1.0
	 * @param string $source_path Source image path
	 * @param array  $sizes       Array of sizes to generate
	 * @param string $target_dir  Target directory
	 * @return array Array of generated image paths
	 */
	public function generate_responsive_sizes( string $source_path, array $sizes, string $target_dir ): array;

	/**
	 * Get image information
	 *
	 * @since 1.1.0
	 * @param string $image_path Image path
	 * @return array Image information (width, height, type, size)
	 */
	public function get_image_info( string $image_path ): array;

	/**
	 * Get processing statistics
	 *
	 * @since 1.1.0
	 * @return array Processing statistics
	 */
	public function get_stats(): array;

	/**
	 * Reset processing statistics
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public function reset_stats(): void;
}
