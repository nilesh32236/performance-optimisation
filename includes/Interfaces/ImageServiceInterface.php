<?php
/**
 * Image Service Interface
 *
 * @package PerformanceOptimisation\Interfaces
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Interfaces;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface ImageServiceInterface
 *
 * @package PerformanceOptimisation\Interfaces
 */
interface ImageServiceInterface {

	/**
	 * Convert image to WebP or AVIF.
	 *
	 * @param string $image_path The path to the image.
	 * @param string $format The format to convert to.
	 * @return string The path to the converted image.
	 */
	public function convert_image( string $image_path, string $format ): string;

	/**
	 * Process uploaded image.
	 *
	 * @param int $attachment_id The ID of the attachment.
	 * @return void
	 */
	public function process_uploaded_image( int $attachment_id ): void;

	/**
	 * Get conversion stats.
	 *
	 * @return array
	 */
	public function get_conversion_stats(): array;

	/**
	 * Enable lazy loading.
	 *
	 * @param string $content The content to process.
	 * @return string The processed content.
	 */
	public function enable_lazy_loading( string $content ): string;
}
