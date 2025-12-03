<?php
/**
 * Image Service
 *
 * @package PerformanceOptimisation\Services
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Services;

use PerformanceOptimisation\Interfaces\ImageServiceInterface;
use PerformanceOptimisation\Optimizers\ImageProcessor;
use PerformanceOptimisation\Utils\ConversionQueue;
use PerformanceOptimisation\Utils\FileSystemUtil;
use PerformanceOptimisation\Utils\ImageUtil;
use PerformanceOptimisation\Utils\LoggingUtil;
use PerformanceOptimisation\Utils\PerformanceUtil;
use PerformanceOptimisation\Utils\ValidationUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ImageService
 *
 * @package PerformanceOptimisation\Services
 */
class ImageService implements ImageServiceInterface {

	private ImageProcessor $imageProcessor;
	private ConversionQueue $conversionQueue;
	private ImagePreloader $imagePreloader;
	private ImageLazyLoader $imageLazyLoader;
	private array $settings;

	public function __construct(
		ImageProcessor $imageProcessor,
		ConversionQueue $conversionQueue,
		ImagePreloader $imagePreloader,
		ImageLazyLoader $imageLazyLoader,
		array $settings
	) {
		$this->imageProcessor  = $imageProcessor;
		$this->conversionQueue = $conversionQueue;
		$this->imagePreloader  = $imagePreloader;
		$this->imageLazyLoader = $imageLazyLoader;
		$this->settings        = $settings;

		// Debug: Image service instantiated successfully

		// Hook into WordPress image upload
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'convert_on_upload' ), 10, 2 );

		LoggingUtil::info( 'ImageService: wp_generate_attachment_metadata hook registered' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function convert_image( string $source_image_path, string $target_format = 'webp' ): string {
		// Validate image format using ImageUtil
		if ( ! ImageUtil::isImageFormat( $source_image_path ) ) {
			LoggingUtil::warning( 'Invalid image format for conversion', array( 'path' => $source_image_path ) );
			return '';
		}

		if ( ! ValidationUtil::validateImageFormat( $target_format ) ) {
			LoggingUtil::warning( 'Invalid target format for conversion', array( 'format' => $target_format ) );
			return '';
		}

		// Use ImageUtil for optimized path generation
		$target_image_path = ImageUtil::optimizeImagePath( $source_image_path, $target_format );
		$quality           = $this->settings['images']['compression_quality']
			?? $this->settings['image_optimization']['quality']
			?? 85;

		// Check if already converted
		if ( FileSystemUtil::fileExists( $target_image_path ) ) {
			$this->conversionQueue->update_status( $source_image_path, $target_format, 'completed' );
			return $target_image_path;
		}

		// Performance tracking
		PerformanceUtil::startTimer( 'image_conversion_' . $target_format );

		try {
			$success = $this->imageProcessor->convert( $source_image_path, $target_image_path, $target_format, $quality );

			$duration = PerformanceUtil::endTimer( 'image_conversion_' . $target_format );

			if ( $success ) {
				$this->conversionQueue->update_status( $source_image_path, $target_format, 'completed' );

				// Log conversion success with compression ratio
				$compression_ratio = ImageUtil::getImageCompressionRatio( $source_image_path, $target_image_path );
				LoggingUtil::info(
					'Image conversion successful',
					array(
						'source'            => $source_image_path,
						'target'            => $target_image_path,
						'format'            => $target_format,
						'compression_ratio' => $compression_ratio,
						'duration'          => $duration,
					)
				);

				return $target_image_path;
			} else {
				$this->conversionQueue->update_status( $source_image_path, $target_format, 'failed' );
				LoggingUtil::error(
					'Image conversion failed',
					array(
						'source'        => $source_image_path,
						'target_format' => $target_format,
						'duration'      => $duration,
					)
				);
				return '';
			}
		} catch ( \Exception $e ) {
			PerformanceUtil::endTimer( 'image_conversion_' . $target_format );
			$this->conversionQueue->update_status( $source_image_path, $target_format, 'failed' );
			LoggingUtil::error(
				'Image conversion exception: ' . $e->getMessage(),
				array(
					'source'        => $source_image_path,
					'target_format' => $target_format,
				)
			);
			return '';
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function process_uploaded_image( int $attachment_id ): void {
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! FileSystemUtil::fileExists( $file_path ) ) {
			LoggingUtil::warning( 'Uploaded image file not found', array( 'attachment_id' => $attachment_id ) );
			return;
		}

		// Validate image format using ImageUtil
		if ( ! ImageUtil::isImageFormat( $file_path ) ) {
			LoggingUtil::info(
				'Skipping non-image attachment',
				array(
					'attachment_id' => $attachment_id,
					'path'          => $file_path,
				)
			);
			return;
		}

		// Check if image needs optimization
		if ( ! ImageUtil::needsOptimization( $file_path, $this->getOptimizationCriteria() ) ) {
			LoggingUtil::info( 'Image does not need optimization', array( 'attachment_id' => $attachment_id ) );
			return;
		}

		$formats        = $this->get_target_formats();
		$added_to_queue = 0;

		foreach ( $formats as $format ) {
			// Check if conversion already exists
			$optimized_path = ImageUtil::optimizeImagePath( $file_path, $format );
			if ( ! FileSystemUtil::fileExists( $optimized_path ) ) {
				$this->conversionQueue->add( $file_path, $format );
				++$added_to_queue;
			}
		}

		if ( $added_to_queue > 0 ) {
			$this->conversionQueue->save();
			LoggingUtil::info(
				'Image added to conversion queue',
				array(
					'attachment_id'      => $attachment_id,
					'formats'            => $formats,
					'queued_conversions' => $added_to_queue,
				)
			);
		}
	}

	/**
	 * Convert images on upload (WordPress filter hook).
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array Unmodified metadata.
	 */
	public function convert_on_upload( $metadata, $attachment_id ): array {
		LoggingUtil::debug( 'WPPO ImageService: convert_on_upload called', array( 'attachment_id' => $attachment_id ) );

		// Default to true if not set, check both new and old structure
		$auto_convert = $this->settings['images']['auto_convert_on_upload']
			?? $this->settings['image_optimization']['auto_convert_on_upload']
			?? true;

		LoggingUtil::debug( 'WPPO ImageService: auto_convert check', array( 'auto_convert' => $auto_convert ) );

		if ( ! $auto_convert ) {
			LoggingUtil::info( 'WPPO ImageService: Auto-convert disabled, skipping' );
			return $metadata;
		}

		$file_path = get_attached_file( $attachment_id );
		LoggingUtil::debug( 'WPPO ImageService: file_path check', array( 'file_path' => $file_path ) );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			LoggingUtil::warning( 'WPPO ImageService: File path invalid or does not exist', array( 'file_path' => $file_path ) );
			return $metadata;
		}

		// Get target formats
		$formats = $this->get_target_formats();
		LoggingUtil::debug( 'WPPO ImageService: Queuing for formats', array( 'formats' => $formats ) );

		if ( empty( $formats ) ) {
			LoggingUtil::info( 'WPPO ImageService: No formats enabled, skipping' );
			return $metadata;
		}

		// Queue main image for conversion (async)
		foreach ( $formats as $format ) {
			$this->conversionQueue->add( $file_path, $format );
			LoggingUtil::debug( 'WPPO ImageService: Queued main image', array( 'format' => $format ) );
		}

		// Queue image sizes for conversion (async)
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$upload_dir = wp_upload_dir();
			foreach ( $metadata['sizes'] as $size => $size_data ) {
				$size_path = wp_normalize_path( $upload_dir['path'] . '/' . $size_data['file'] );
				if ( file_exists( $size_path ) ) {
					foreach ( $formats as $format ) {
						$this->conversionQueue->add( $size_path, $format );
						LoggingUtil::debug( 'WPPO ImageService: Queued size', array( 'size' => $size, 'format' => $format ) );
					}
				}
			}
		}

		// Save queue to database
		$this->conversionQueue->save();
		LoggingUtil::info( 'WPPO ImageService: Queue saved, upload complete' );

		return $metadata;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_conversion_stats(): array {
		$queue_stats = $this->conversionQueue->get_stats();

		// Enhance stats with ImageUtil calculations
		$all_images           = $this->getAllImagePaths();
		$optimization_savings = ImageUtil::calculateOptimizationSavings( $all_images, $this->get_target_formats() );

		return array_merge(
			$queue_stats,
			array(
				'optimization_savings'   => $optimization_savings,
				'total_images_on_site'   => count( $all_images ),
				'optimized_images'       => $optimization_savings['processed_images'],
				'space_saved'            => $optimization_savings['formatted_savings'],
				'compression_percentage' => $optimization_savings['savings_percentage'],
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function enable_lazy_loading( string $content ): string {
		return $this->imageLazyLoader->enable_lazy_loading( $content );
	}

	private function get_target_formats(): array {
		$formats = array();

		// Try new structure first, fallback to old structure
		$webp_enabled = ! empty( $this->settings['images']['convert_to_webp'] )
			|| ! empty( $this->settings['image_optimization']['webp_conversion'] );
		$avif_enabled = ! empty( $this->settings['images']['convert_to_avif'] )
			|| ! empty( $this->settings['image_optimization']['avif_conversion'] );

		LoggingUtil::debug(
			'WPPO ImageService: get_target_formats check',
			array(
				'webp_enabled' => $webp_enabled,
				'avif_enabled' => $avif_enabled,
			)
		);

		if ( $webp_enabled ) {
			$formats[] = 'webp';
		}
		if ( $avif_enabled ) {
			$formats[] = 'avif';
		}
		return $formats;
	}

	private function get_img_path( string $source_image_local_path, string $target_format = 'webp' ): string {
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

		FileSystemUtil::createDirectory( $new_image_dir_absolute );

		return trailingslashit( $new_image_dir_absolute ) . $new_filename;
	}

	public function preload_images(): void {
		$this->imagePreloader->preload_images();
	}

	/**
	 * Process batch of images.
	 *
	 * @param int  $batch_size Number of images to process.
	 * @param bool $force Force processing.
	 * @return array
	 */
	public function processBatch( int $batch_size = 10, bool $force = false ): array {
		try {
			$queue_items = $this->conversionQueue->get_pending_items( $batch_size );
			$processed   = 0;
			$total       = count( $queue_items );

			foreach ( $queue_items as $item ) {
				$result = $this->convert_image( $item['source_path'], $item['target_format'] );
				if ( ! empty( $result ) ) {
					++$processed;
				}
			}

			return array(
				'success'   => true,
				'processed' => $processed,
				'total'     => $total,
				'message'   => sprintf( 'Processed %d of %d images', $processed, $total ),
			);
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Image batch processing failed: ' . $e->getMessage() );
			return array(
				'success'   => false,
				'processed' => 0,
				'total'     => 0,
				'message'   => $e->getMessage(),
			);
		}
	}

	/**
	 * Reset conversion data.
	 *
	 * @return bool
	 */
	public function resetConversionData(): bool {
		try {
			$this->conversionQueue->clear();

			// Also remove converted images directory
			$wppo_dir = wp_normalize_path( WP_CONTENT_DIR . '/wppo' );
			if ( FileSystemUtil::isDirectory( $wppo_dir ) ) {
				FileSystemUtil::deleteDirectory( $wppo_dir, true );
			}

			LoggingUtil::info( 'Image conversion data reset successfully' );
			return true;
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Failed to reset image conversion data: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Get image MIME type from URL.
	 *
	 * @param string $url Image URL.
	 * @return string
	 */
	public function getImageMimeType( string $url ): string {
		// Use ImageUtil for MIME type detection
		return ImageUtil::getImageMimeType( $url );
	}

	/**
	 * Get optimization criteria for images.
	 *
	 * @return array Optimization criteria.
	 */
	private function getOptimizationCriteria(): array {
		return array(
			'max_file_size'       => $this->settings['images']['max_file_size'] ?? 500000, // 500KB
			'max_width'           => $this->settings['images']['max_image_width'] ?? 1920,
			'max_height'          => $this->settings['images']['max_image_height'] ?? 1080,
			'modern_formats_only' => false,
		);
	}

	/**
	 * Get all image paths from WordPress media library.
	 *
	 * @return array Array of image file paths.
	 */
	private function getAllImagePaths(): array {
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$attachment_ids = get_posts( $args );
		$image_paths    = array();

		foreach ( $attachment_ids as $attachment_id ) {
			$file_path = get_attached_file( $attachment_id );
			if ( $file_path && FileSystemUtil::fileExists( $file_path ) ) {
				$image_paths[] = $file_path;
			}
		}

		return $image_paths;
	}

	/**
	 * Bulk optimize images with progress tracking.
	 *
	 * @param array $image_paths Array of image paths to optimize.
	 * @param array $options Optimization options.
	 * @return array Optimization results.
	 */
	public function bulkOptimizeImages( array $image_paths, array $options = array() ): array {
		$defaults = array(
			'batch_size'     => 10,
			'target_formats' => $this->get_target_formats(),
			'force'          => false,
		);

		$options = array_merge( $defaults, $options );
		$results = array(
			'total_images' => count( $image_paths ),
			'processed'    => 0,
			'successful'   => 0,
			'failed'       => 0,
			'skipped'      => 0,
			'errors'       => array(),
		);

		PerformanceUtil::startTimer( 'bulk_image_optimization' );

		foreach ( array_chunk( $image_paths, $options['batch_size'] ) as $batch ) {
			foreach ( $batch as $image_path ) {
				if ( ! ImageUtil::isImageFormat( $image_path ) ) {
					++$results['skipped'];
					continue;
				}

				if ( ! $options['force'] && ImageUtil::isImageOptimized( $image_path ) ) {
					++$results['skipped'];
					continue;
				}

				$batch_successful = true;
				foreach ( $options['target_formats'] as $format ) {
					$converted_path = $this->convert_image( $image_path, $format );
					if ( empty( $converted_path ) ) {
						$batch_successful    = false;
						$results['errors'][] = "Failed to convert {$image_path} to {$format}";
					}
				}

				if ( $batch_successful ) {
					++$results['successful'];
				} else {
					++$results['failed'];
				}

				++$results['processed'];
			}

			// Small delay between batches to prevent server overload
			usleep( 100000 ); // 0.1 second
		}

		$duration                     = PerformanceUtil::endTimer( 'bulk_image_optimization' );
		$results['duration']          = $duration;
		$results['images_per_second'] = $duration > 0 ? $results['processed'] / $duration : 0;

		LoggingUtil::info( 'Bulk image optimization completed', $results );

		return $results;
	}

	/**
	 * Generate responsive images for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array Generated responsive image data.
	 */
	public function generateResponsiveImages( int $attachment_id ): array {
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! ImageUtil::isImageFormat( $file_path ) ) {
			return array();
		}

		// Get WordPress image sizes
		$image_sizes   = wp_get_additional_image_sizes();
		$default_sizes = array(
			'thumbnail' => array(
				'width'  => 150,
				'height' => 150,
				'crop'   => true,
			),
			'medium'    => array(
				'width'  => 300,
				'height' => 300,
				'crop'   => false,
			),
			'large'     => array(
				'width'  => 1024,
				'height' => 1024,
				'crop'   => false,
			),
		);

		$all_sizes = array_merge( $default_sizes, $image_sizes );

		// Generate variants using ImageUtil
		$variants = ImageUtil::generateImageVariants( $file_path, $all_sizes );

		// Generate srcset
		$srcset = ImageUtil::generateResponsiveSrcset( $file_path, $all_sizes );

		return array(
			'variants'     => $variants,
			'srcset'       => $srcset,
			'aspect_ratio' => ImageUtil::getImageAspectRatio( $file_path ),
		);
	}

	/**
	 * Clean up orphaned optimized images.
	 *
	 * @return array Cleanup results.
	 */
	public function cleanupOrphanedImages(): array {
		$wppo_dir = wp_normalize_path( WP_CONTENT_DIR . '/wppo' );
		if ( ! FileSystemUtil::fileExists( $wppo_dir ) ) {
			return array(
				'cleaned'     => 0,
				'space_freed' => 0,
			);
		}

		$optimized_files = FileSystemUtil::getFilesInDirectory( $wppo_dir, true, array( 'webp', 'avif' ) );
		$cleaned_count   = 0;
		$space_freed     = 0;

		foreach ( $optimized_files as $optimized_file ) {
			// Check if original file still exists
			$relative_path = str_replace( $wppo_dir, '', $optimized_file );
			$original_path = wp_normalize_path( WP_CONTENT_DIR . $relative_path );

			// Remove extension and add original extensions to check
			$path_without_ext = FileSystemUtil::getDirectoryName( $original_path ) . '/' . FileSystemUtil::getFileNameWithoutExtension( $original_path );
			$original_exists  = false;

			foreach ( array( 'jpg', 'jpeg', 'png', 'gif' ) as $ext ) {
				if ( FileSystemUtil::fileExists( $path_without_ext . '.' . $ext ) ) {
					$original_exists = true;
					break;
				}
			}

			if ( ! $original_exists ) {
				$file_size = FileSystemUtil::getFileSize( $optimized_file );
				if ( FileSystemUtil::deleteFile( $optimized_file ) ) {
					++$cleaned_count;
					$space_freed += $file_size;
				}
			}
		}

		LoggingUtil::info(
			'Orphaned image cleanup completed',
			array(
				'files_cleaned' => $cleaned_count,
				'space_freed'   => FileSystemUtil::formatFileSize( $space_freed ),
			)
		);

		return array(
			'cleaned'               => $cleaned_count,
			'space_freed'           => $space_freed,
			'formatted_space_freed' => FileSystemUtil::formatFileSize( $space_freed ),
		);
	}
}
