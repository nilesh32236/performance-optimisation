<?php
/**
 * Image Optimizer Manager
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\Images;

use PerformanceOptimisation\Interfaces\ImageProcessorInterface;
use PerformanceOptimisation\Interfaces\ConfigInterface;
use PerformanceOptimisation\Exceptions\ImageProcessingException;

/**
 * Image optimization management class
 *
 * @since 1.1.0
 */
class ImageOptimizer {

	/**
	 * Image processors
	 *
	 * @since 1.1.0
	 * @var array
	 */
	private array $processors = array();

	/**
	 * Configuration manager
	 *
	 * @since 1.1.0
	 * @var ConfigInterface
	 */
	private ConfigInterface $config;

	/**
	 * Optimization queue
	 *
	 * @since 1.1.0
	 * @var array
	 */
	private array $optimization_queue = array();

	/**
	 * Processing statistics
	 *
	 * @since 1.1.0
	 * @var array
	 */
	private array $stats = array(
		'images_optimized'  => 0,
		'total_bytes_saved' => 0,
		'processing_time'   => 0,
		'queue_size'        => 0,
	);

	/**
	 * Constructor
	 *
	 * @since 1.1.0
	 * @param ConfigInterface $config Configuration manager
	 */
	public function __construct( ConfigInterface $config ) {
		$this->config = $config;
		$this->register_default_processors();
	}

	/**
	 * Register an image processor
	 *
	 * @since 1.1.0
	 * @param ImageProcessorInterface $processor Processor instance
	 * @return void
	 */
	public function register_processor( ImageProcessorInterface $processor ): void {
		$this->processors[ $processor->get_name() ] = $processor;
	}

	/**
	 * Get an image processor
	 *
	 * @since 1.1.0
	 * @param string $name Processor name
	 * @return ImageProcessorInterface Processor instance
	 * @throws ImageProcessingException If processor not found
	 */
	public function get_processor( string $name ): ImageProcessorInterface {
		if ( ! isset( $this->processors[ $name ] ) ) {
			throw new ImageProcessingException( "Image processor '{$name}' not found." );
		}

		return $this->processors[ $name ];
	}

	/**
	 * Optimize a single image
	 *
	 * @since 1.1.0
	 * @param string $source_path Source image path
	 * @param string $target_path Target image path (optional)
	 * @param array  $options     Optimization options
	 * @return bool True on success, false on failure
	 */
	public function optimize_image( string $source_path, string $target_path = '', array $options = array() ): bool {
		if ( empty( $target_path ) ) {
			$target_path = $source_path;
		}

		$processor = $this->get_best_processor_for_image( $source_path );

		if ( ! $processor ) {
			return false;
		}

		$start_time    = microtime( true );
		$original_size = file_exists( $source_path ) ? filesize( $source_path ) : 0;

		$result = $processor->process( $source_path, $target_path, $options );

		if ( $result ) {
			$new_size = file_exists( $target_path ) ? filesize( $target_path ) : 0;
			++$this->stats['images_optimized'];
			$this->stats['total_bytes_saved'] += ( $original_size - $new_size );
			$this->stats['processing_time']   += ( microtime( true ) - $start_time );
		}

		return $result;
	}

	/**
	 * Convert image to WebP format
	 *
	 * @since 1.1.0
	 * @param string $source_path Source image path
	 * @param string $target_path Target WebP path (optional)
	 * @param int    $quality     Conversion quality
	 * @return bool True on success, false on failure
	 */
	public function convert_to_webp( string $source_path, string $target_path = '', int $quality = 85 ): bool {
		if ( ! $this->config->get( 'images.convert_to_webp', true ) ) {
			return false;
		}

		if ( empty( $target_path ) ) {
			$target_path = $this->get_webp_path( $source_path );
		}

		$processor = $this->get_best_processor_for_image( $source_path );

		if ( ! $processor || ! $processor->can_process( 'image/webp' ) ) {
			return false;
		}

		return $processor->convert( $source_path, $target_path, 'webp', $quality );
	}

	/**
	 * Convert image to AVIF format
	 *
	 * @since 1.1.0
	 * @param string $source_path Source image path
	 * @param string $target_path Target AVIF path (optional)
	 * @param int    $quality     Conversion quality
	 * @return bool True on success, false on failure
	 */
	public function convert_to_avif( string $source_path, string $target_path = '', int $quality = 85 ): bool {
		if ( ! $this->config->get( 'images.convert_to_avif', false ) ) {
			return false;
		}

		if ( empty( $target_path ) ) {
			$target_path = $this->get_avif_path( $source_path );
		}

		$processor = $this->get_best_processor_for_image( $source_path );

		if ( ! $processor || ! $processor->can_process( 'image/avif' ) ) {
			return false;
		}

		return $processor->convert( $source_path, $target_path, 'avif', $quality );
	}

	/**
	 * Resize image if it exceeds maximum dimensions
	 *
	 * @since 1.1.0
	 * @param string $source_path Source image path
	 * @param string $target_path Target image path (optional)
	 * @return bool True on success, false on failure
	 */
	public function resize_large_image( string $source_path, string $target_path = '' ): bool {
		if ( ! $this->config->get( 'images.resize_large_images', true ) ) {
			return false;
		}

		$max_width  = $this->config->get( 'images.max_image_width', 1920 );
		$max_height = $this->config->get( 'images.max_image_height', 1080 );

		$processor = $this->get_best_processor_for_image( $source_path );

		if ( ! $processor ) {
			return false;
		}

		$image_info = $processor->get_image_info( $source_path );

		if ( empty( $image_info ) ) {
			return false;
		}

		// Check if image needs resizing
		if ( $image_info['width'] <= $max_width && $image_info['height'] <= $max_height ) {
			return true; // No resizing needed
		}

		if ( empty( $target_path ) ) {
			$target_path = $source_path;
		}

		return $processor->resize( $source_path, $target_path, $max_width, $max_height, false );
	}

	/**
	 * Generate responsive image sizes
	 *
	 * @since 1.1.0
	 * @param string $source_path Source image path
	 * @param string $target_dir  Target directory
	 * @return array Array of generated image paths
	 */
	public function generate_responsive_images( string $source_path, string $target_dir = '' ): array {
		$processor = $this->get_best_processor_for_image( $source_path );

		if ( ! $processor ) {
			return array();
		}

		if ( empty( $target_dir ) ) {
			$target_dir = dirname( $source_path );
		}

		$responsive_sizes = array(
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
			'mobile'    => array(
				'width'  => 480,
				'height' => 480,
				'crop'   => false,
			),
			'tablet'    => array(
				'width'  => 768,
				'height' => 768,
				'crop'   => false,
			),
		);

		return $processor->generate_responsive_sizes( $source_path, $responsive_sizes, $target_dir );
	}

	/**
	 * Add image to optimization queue
	 *
	 * @since 1.1.0
	 * @param string $image_path Image path
	 * @param array  $options    Optimization options
	 * @return void
	 */
	public function add_to_queue( string $image_path, array $options = array() ): void {
		$this->optimization_queue[] = array(
			'path'    => $image_path,
			'options' => $options,
			'added'   => time(),
		);

		$this->stats['queue_size'] = count( $this->optimization_queue );
	}

	/**
	 * Process optimization queue
	 *
	 * @since 1.1.0
	 * @param int $batch_size Number of images to process in this batch
	 * @return array Processing results
	 */
	public function process_queue( int $batch_size = 10 ): array {
		$results   = array();
		$processed = 0;

		while ( $processed < $batch_size && ! empty( $this->optimization_queue ) ) {
			$item = array_shift( $this->optimization_queue );

			$success = $this->optimize_image( $item['path'], '', $item['options'] );

			$results[] = array(
				'path'    => $item['path'],
				'success' => $success,
			);

			++$processed;
		}

		$this->stats['queue_size'] = count( $this->optimization_queue );

		return $results;
	}

	/**
	 * Get optimization statistics
	 *
	 * @since 1.1.0
	 * @return array Optimization statistics
	 */
	public function get_stats(): array {
		$processor_stats = array();

		foreach ( $this->processors as $name => $processor ) {
			$processor_stats[ $name ] = $processor->get_stats();
		}

		return array(
			'global'     => $this->stats,
			'processors' => $processor_stats,
		);
	}

	/**
	 * Reset optimization statistics
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public function reset_stats(): void {
		$this->stats = array(
			'images_optimized'  => 0,
			'total_bytes_saved' => 0,
			'processing_time'   => 0,
			'queue_size'        => count( $this->optimization_queue ),
		);

		foreach ( $this->processors as $processor ) {
			$processor->reset_stats();
		}
	}

	/**
	 * Get available processors
	 *
	 * @since 1.1.0
	 * @return array Array of processor names
	 */
	public function get_available_processors(): array {
		return array_keys( $this->processors );
	}

	/**
	 * Check if image optimization is enabled
	 *
	 * @since 1.1.0
	 * @return bool True if enabled, false otherwise
	 */
	public function is_optimization_enabled(): bool {
		return $this->config->get( 'images.convert_to_webp', true ) ||
				$this->config->get( 'images.convert_to_avif', false ) ||
				$this->config->get( 'images.resize_large_images', true );
	}

	/**
	 * Register default image processors
	 *
	 * @since 1.1.0
	 * @return void
	 */
	private function register_default_processors(): void {
		try {
			$this->register_processor( new ImageProcessor( $this->config ) );
		} catch ( ImageProcessingException $e ) {
			// GD extension not available, processor not registered
		}
	}

	/**
	 * Get the best processor for an image
	 *
	 * @since 1.1.0
	 * @param string $image_path Image path
	 * @return ImageProcessorInterface|null Best processor or null if none found
	 */
	private function get_best_processor_for_image( string $image_path ): ?ImageProcessorInterface {
		if ( ! file_exists( $image_path ) ) {
			return null;
		}

		$image_info = getimagesize( $image_path );

		if ( false === $image_info ) {
			return null;
		}

		$mime_type = $image_info['mime'];

		foreach ( $this->processors as $processor ) {
			if ( $processor->can_process( $mime_type ) ) {
				return $processor;
			}
		}

		return null;
	}

	/**
	 * Get WebP path for an image
	 *
	 * @since 1.1.0
	 * @param string $image_path Original image path
	 * @return string WebP image path
	 */
	private function get_webp_path( string $image_path ): string {
		$path_info = pathinfo( $image_path );
		return $path_info['dirname'] . '/' . $path_info['filename'] . '.webp';
	}

	/**
	 * Get AVIF path for an image
	 *
	 * @since 1.1.0
	 * @param string $image_path Original image path
	 * @return string AVIF image path
	 */
	private function get_avif_path( string $image_path ): string {
		$path_info = pathinfo( $image_path );
		return $path_info['dirname'] . '/' . $path_info['filename'] . '.avif';
	}
}
