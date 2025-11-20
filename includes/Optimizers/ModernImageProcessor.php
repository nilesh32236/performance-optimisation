<?php
/**
 * Modern Image Processor
 *
 * Advanced image processing with modern capabilities including WebP/AVIF support,
 * progressive JPEG optimization, responsive image generation, and intelligent compression.
 *
 * @package PerformanceOptimisation\Optimizers
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Optimizers;

use PerformanceOptimisation\Interfaces\ServiceContainerInterface;
use PerformanceOptimisation\Interfaces\OptimizerInterface;
use PerformanceOptimisation\Utils\LoggingUtil;
use PerformanceOptimisation\Utils\FileSystemUtil;
use PerformanceOptimisation\Utils\ValidationUtil;
use PerformanceOptimisation\Utils\PerformanceUtil;
use PerformanceOptimisation\Utils\CacheUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Modern Image Processor Class
 */
class ModernImageProcessor implements OptimizerInterface {

	/**
	 * Service container.
	 *
	 * @var ServiceContainerInterface
	 */
	private ServiceContainerInterface $container;

	/**
	 * Logger instance.
	 *
	 * @var LoggingUtil
	 */
	private LoggingUtil $logger;

	/**
	 * FileSystem utility.
	 *
	 * @var FileSystemUtil
	 */
	private FileSystemUtil $filesystem;

	/**
	 * Validator instance.
	 *
	 * @var ValidationUtil
	 */
	private ValidationUtil $validator;

	/**
	 * Performance utility.
	 *
	 * @var PerformanceUtil
	 */
	private PerformanceUtil $performance;

	/**
	 * Cache utility.
	 *
	 * @var CacheUtil
	 */
	private CacheUtil $cache;

	/**
	 * Supported image formats.
	 *
	 * @var array
	 */
	private array $supported_formats = array(
		'jpeg' => array( 'jpg', 'jpeg' ),
		'png'  => array( 'png' ),
		'gif'  => array( 'gif' ),
		'webp' => array( 'webp' ),
		'avif' => array( 'avif' ),
	);

	/**
	 * Default optimization options.
	 *
	 * @var array
	 */
	private array $default_options = array(
		'quality'               => 85,
		'progressive'           => true,
		'strip_metadata'        => true,
		'auto_format'           => true,
		'generate_responsive'   => true,
		'lazy_loading'          => true,
		'compression_level'     => 6,
		'preserve_transparency' => true,
		'optimize_for_web'      => true,
	);

	/**
	 * Responsive image breakpoints.
	 *
	 * @var array
	 */
	private array $responsive_breakpoints = array(
		320,
		480,
		640,
		768,
		1024,
		1200,
		1600,
		1920,
	);

	/**
	 * Constructor.
	 *
	 * @param ServiceContainerInterface $container Service container.
	 */
	public function __construct( ServiceContainerInterface $container ) {
		$this->container   = $container;
		$this->logger      = $container->get( 'logger' );
		$this->filesystem  = $container->get( 'filesystem' );
		$this->validator   = $container->get( 'validator' );
		$this->performance = $container->get( 'performance' );
		$this->cache       = $container->get( 'cache' );
	}

	/**
	 * Optimize image content (interface compliance).
	 *
	 * @param string $content Image file path or content.
	 * @param array  $options Optimization options.
	 * @return string Optimized image path or content.
	 */
	public function optimize( string $content, array $options = array() ): string {
		// For image processor, content is treated as file path
		// Use the existing optimizeImage method which is already public
		$result = $this->optimizeImage( $content, $options );
		return $result['success'] ? $result['optimized_path'] : $content;
	}

	/**
	 * Check if optimizer can handle the content type.
	 *
	 * @param string $content_type Content type to check.
	 * @return bool True if can optimize, false otherwise.
	 */
	public function can_optimize( string $content_type ): bool {
		return in_array( $content_type, $this->get_supported_types(), true );
	}

	/**
	 * Get optimizer name.
	 *
	 * @return string Optimizer name.
	 */
	public function get_name(): string {
		return 'Modern Image Processor';
	}

	/**
	 * Get image information.
	 *
	 * @param string $image_path Path to image file.
	 * @return array|false Image information or false on failure.
	 */
	private function getImageInfo( string $image_path ) {
		$image_info = getimagesize( $image_path );
		if ( ! $image_info ) {
			return false;
		}

		$file_size = $this->filesystem->getFileSize( $image_path );
		$format    = $this->getImageFormat( $image_info[2] );

		return array(
			'width'        => $image_info[0],
			'height'       => $image_info[1],
			'type'         => $image_info[2],
			'format'       => $format,
			'mime'         => $image_info['mime'],
			'size'         => $file_size,
			'aspect_ratio' => $image_info[0] / $image_info[1],
		);
	}

	/**
	 * Get image format from type constant.
	 *
	 * @param int $type Image type constant.
	 * @return string Image format.
	 */
	private function getImageFormat( int $type ): string {
		switch ( $type ) {
			case IMAGETYPE_JPEG:
				return 'jpeg';
			case IMAGETYPE_PNG:
				return 'png';
			case IMAGETYPE_GIF:
				return 'gif';
			case IMAGETYPE_WEBP:
				return 'webp';
			default:
				return 'unknown';
		}
	}

	/**
	 * Optimize image with basic compression.
	 *
	 * @param string $image_path Path to image file.
	 * @param array  $options    Optimization options.
	 * @return string|false Optimized image path or false on failure.
	 */
	private function optimizeImage( string $image_path, array $options ) {
		try {
			$image_info = $this->getImageInfo( $image_path );
			if ( ! $image_info ) {
				return false;
			}

			// Create image resource
			$image_resource = $this->createImageResource( $image_path, $image_info['type'] );
			if ( ! $image_resource ) {
				return false;
			}

			// Apply optimizations
			if ( $options['strip_metadata'] ) {
				// Metadata is automatically stripped when creating new image
			}

			// Generate optimized filename
			$optimized_path = $this->generateOptimizedPath( $image_path, 'optimized' );

			// Save optimized image
			$success = $this->saveImage( $image_resource, $optimized_path, $image_info['format'], $options );

			imagedestroy( $image_resource );

			return $success ? $optimized_path : false;

		} catch ( \Exception $e ) {
			$this->logger->error( 'Image optimization failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Generate modern format versions (WebP/AVIF).
	 *
	 * @param string $image_path Path to image file.
	 * @param array  $options    Optimization options.
	 * @return array Modern format results.
	 */
	private function generateModernFormats( string $image_path, array $options ): array {
		$results        = array();
		$modern_formats = array( 'webp', 'avif' );

		foreach ( $modern_formats as $format ) {
			if ( ! $this->isFormatSupported( $format ) ) {
				$this->logger->debug( "Format {$format} not supported, skipping" );
				continue;
			}

			try {
				$converted_path = $this->convertToFormat( $image_path, $format, $options );
				if ( $converted_path ) {
					$results[ $format ] = array(
						'path'   => $converted_path,
						'size'   => $this->filesystem->getFileSize( $converted_path ),
						'format' => $format,
					);
				}
			} catch ( \Exception $e ) {
				$this->logger->error( "Failed to convert to {$format}: " . $e->getMessage() );
			}
		}

		return $results;
	}

	/**
	 * Generate responsive image versions.
	 *
	 * @param string $image_path Path to image file.
	 * @param array  $options    Optimization options.
	 * @return array Responsive image results.
	 */
	private function generateResponsiveImages( string $image_path, array $options ): array {
		$results    = array();
		$image_info = $this->getImageInfo( $image_path );

		if ( ! $image_info ) {
			return $results;
		}

		$original_width = $image_info['width'];

		foreach ( $this->responsive_breakpoints as $width ) {
			// Skip if breakpoint is larger than original
			if ( $width >= $original_width ) {
				continue;
			}

			try {
				$resized_path = $this->resizeImage( $image_path, $width, null, $options );
				if ( $resized_path ) {
					$results[ $width ] = array(
						'path'   => $resized_path,
						'size'   => $this->filesystem->getFileSize( $resized_path ),
						'width'  => $width,
						'format' => $image_info['format'],
					);
				}
			} catch ( \Exception $e ) {
				$this->logger->error( "Failed to resize to {$width}px: " . $e->getMessage() );
			}
		}

		return $results;
	}

	/**
	 * Convert image to specified format.
	 *
	 * @param string $image_path   Path to image file.
	 * @param string $target_format Target format.
	 * @param array  $options      Conversion options.
	 * @return string|false Converted image path or false on failure.
	 */
	private function convertToFormat( string $image_path, string $target_format, array $options ) {
		$image_info = $this->getImageInfo( $image_path );
		if ( ! $image_info ) {
			return false;
		}

		// Create image resource
		$image_resource = $this->createImageResource( $image_path, $image_info['type'] );
		if ( ! $image_resource ) {
			return false;
		}

		// Generate target path
		$target_path = $this->generateOptimizedPath( $image_path, $target_format );

		// Save in target format
		$success = $this->saveImage( $image_resource, $target_path, $target_format, $options );

		imagedestroy( $image_resource );

		return $success ? $target_path : false;
	}

	/**
	 * Resize image to specified dimensions.
	 *
	 * @param string   $image_path Path to image file.
	 * @param int      $width      Target width.
	 * @param int|null $height     Target height (null to maintain aspect ratio).
	 * @param array    $options    Resize options.
	 * @return string|false Resized image path or false on failure.
	 */
	private function resizeImage( string $image_path, int $width, ?int $height = null, array $options = array() ) {
		$image_info = $this->getImageInfo( $image_path );
		if ( ! $image_info ) {
			return false;
		}

		// Calculate height if not provided
		if ( $height === null ) {
			$height = intval( $width / $image_info['aspect_ratio'] );
		}

		// Create image resource
		$source_resource = $this->createImageResource( $image_path, $image_info['type'] );
		if ( ! $source_resource ) {
			return false;
		}

		// Create new image with target dimensions
		$target_resource = imagecreatetruecolor( $width, $height );

		// Preserve transparency for PNG and GIF
		if ( $image_info['format'] === 'png' || $image_info['format'] === 'gif' ) {
			imagealphablending( $target_resource, false );
			imagesavealpha( $target_resource, true );
			$transparent = imagecolorallocatealpha( $target_resource, 255, 255, 255, 127 );
			imagefill( $target_resource, 0, 0, $transparent );
		}

		// Resize image
		$success = imagecopyresampled(
			$target_resource,
			$source_resource,
			0,
			0,
			0,
			0,
			$width,
			$height,
			$image_info['width'],
			$image_info['height']
		);

		if ( ! $success ) {
			imagedestroy( $source_resource );
			imagedestroy( $target_resource );
			return false;
		}

		// Generate resized path
		$resized_path = $this->generateOptimizedPath( $image_path, $width . 'w' );

		// Save resized image
		$save_success = $this->saveImage( $target_resource, $resized_path, $image_info['format'], $options );

		imagedestroy( $source_resource );
		imagedestroy( $target_resource );
	}

	/**
	 * Create image resource from file.
	 *
	 * @param string $image_path Path to image file.
	 * @param int    $type       Image type constant.
	 * @return resource|false Image resource or false on failure.
	 */
	private function createImageResource( string $image_path, int $type ) {
		switch ( $type ) {
			case IMAGETYPE_JPEG:
				return imagecreatefromjpeg( $image_path );
			case IMAGETYPE_PNG:
				$resource = imagecreatefrompng( $image_path );
				if ( $resource ) {
					imagepalettetotruecolor( $resource );
					imagealphablending( $resource, true );
					imagesavealpha( $resource, true );
				}
				return $resource;
			case IMAGETYPE_GIF:
				return imagecreatefromgif( $image_path );
			case IMAGETYPE_WEBP:
				return function_exists( 'imagecreatefromwebp' ) ? imagecreatefromwebp( $image_path ) : false;
			default:
				return false;
		}
	}

	/**
	 * Save image resource to file.
	 *
	 * @param resource $image_resource Image resource.
	 * @param string   $target_path    Target file path.
	 * @param string   $format         Target format.
	 * @param array    $options        Save options.
	 * @return bool True on success, false on failure.
	 */
	private function saveImage( $image_resource, string $target_path, string $format, array $options ): bool {
		// Ensure target directory exists
		$target_dir = dirname( $target_path );
		if ( ! $this->filesystem->directoryExists( $target_dir ) ) {
			$this->filesystem->createDirectory( $target_dir, true );
		}

		$quality     = $options['quality'] ?? 85;
		$progressive = $options['progressive'] ?? true;

		switch ( $format ) {
			case 'jpeg':
				if ( $progressive ) {
					imageinterlace( $image_resource, 1 );
				}
				return imagejpeg( $image_resource, $target_path, $quality );

			case 'png':
				// PNG compression level (0-9, where 9 is maximum compression)
				$compression = 9 - intval( ( $quality / 100 ) * 9 );
				return imagepng( $image_resource, $target_path, $compression );

			case 'gif':
				return imagegif( $image_resource, $target_path );

			case 'webp':
				return function_exists( 'imagewebp' ) ? imagewebp( $image_resource, $target_path, $quality ) : false;

			case 'avif':
				return function_exists( 'imageavif' ) ? imageavif( $image_resource, $target_path, $quality ) : false;

			default:
				return false;
		}
	}

	/**
	 * Check if image format is supported.
	 *
	 * @param string $format Image format.
	 * @return bool True if supported, false otherwise.
	 */
	private function isFormatSupported( string $format ): bool {
		switch ( $format ) {
			case 'jpeg':
				return function_exists( 'imagejpeg' );
			case 'png':
				return function_exists( 'imagepng' );
			case 'gif':
				return function_exists( 'imagegif' );
			case 'webp':
				return function_exists( 'imagewebp' );
			case 'avif':
				return function_exists( 'imageavif' );
			default:
				return false;
		}
	}

	/**
	 * Generate optimized file path.
	 *
	 * @param string $original_path Original file path.
	 * @param string $suffix        Suffix to add.
	 * @return string Optimized file path.
	 */
	private function generateOptimizedPath( string $original_path, string $suffix ): string {
		$path_info = pathinfo( $original_path );
		$cache_dir = wp_upload_dir()['basedir'] . '/wppo-cache/images/';

		// Ensure cache directory exists
		if ( ! $this->filesystem->directoryExists( $cache_dir ) ) {
			$this->filesystem->createDirectory( $cache_dir, true );
		}

		$filename  = $path_info['filename'] . '-' . $suffix;
		$extension = $path_info['extension'];

		return $cache_dir . $filename . '.' . $extension;
	}

	/**
	 * Get optimal format for browser.
	 *
	 * @param string $user_agent Browser user agent.
	 * @return string Optimal format.
	 */
	public function getOptimalFormat( string $user_agent ): string {
		// Check for AVIF support (Chrome 85+, Firefox 93+)
		if ( $this->isFormatSupported( 'avif' ) && $this->browserSupportsAvif( $user_agent ) ) {
			return 'avif';
		}

		// Check for WebP support (most modern browsers)
		if ( $this->isFormatSupported( 'webp' ) && $this->browserSupportsWebp( $user_agent ) ) {
			return 'webp';
		}

		// Fallback to JPEG
		return 'jpeg';
	}

	/**
	 * Check if browser supports AVIF.
	 *
	 * @param string $user_agent Browser user agent.
	 * @return bool True if supports AVIF, false otherwise.
	 */
	private function browserSupportsAvif( string $user_agent ): bool {
		// Chrome 85+
		if ( preg_match( '/Chrome\/(\d+)/', $user_agent, $matches ) ) {
			return intval( $matches[1] ) >= 85;
		}

		// Firefox 93+
		if ( preg_match( '/Firefox\/(\d+)/', $user_agent, $matches ) ) {
			return intval( $matches[1] ) >= 93;
		}

		return false;
	}

	/**
	 * Check if browser supports WebP.
	 *
	 * @param string $user_agent Browser user agent.
	 * @return bool True if supports WebP, false otherwise.
	 */
	private function browserSupportsWebp( string $user_agent ): bool {
		// Chrome 23+, Firefox 65+, Safari 14+, Edge 18+
		return preg_match( '/(Chrome|Firefox|Safari|Edge)/', $user_agent ) &&
				! preg_match( '/MSIE|Trident/', $user_agent );
	}

	/**
	 * Generate srcset attribute for responsive images.
	 *
	 * @param array $responsive_images Array of responsive image data.
	 * @return string Srcset attribute value.
	 */
	public function generateSrcset( array $responsive_images ): string {
		$srcset_parts = array();

		foreach ( $responsive_images as $width => $image_data ) {
			if ( isset( $image_data['path'] ) ) {
				$url            = $this->filesystem->pathToUrl( $image_data['path'] );
				$srcset_parts[] = $url . ' ' . $width . 'w';
			}
		}

		return implode( ', ', $srcset_parts );
	}

	/**
	 * Generate sizes attribute for responsive images.
	 *
	 * @param array $breakpoints Custom breakpoints.
	 * @return string Sizes attribute value.
	 */
	public function generateSizes( array $breakpoints = array() ): string {
		if ( empty( $breakpoints ) ) {
			$breakpoints = array(
				'(max-width: 320px)'  => '100vw',
				'(max-width: 640px)'  => '100vw',
				'(max-width: 1024px)' => '50vw',
				'default'             => '33vw',
			);
		}

		$sizes_parts = array();
		foreach ( $breakpoints as $condition => $size ) {
			if ( $condition === 'default' ) {
				$sizes_parts[] = $size;
			} else {
				$sizes_parts[] = $condition . ' ' . $size;
			}
		}

		return implode( ', ', $sizes_parts );
	}

	/**
	 * Process image for lazy loading.
	 *
	 * @param string $image_path Path to image file.
	 * @param array  $options    Processing options.
	 * @return array Lazy loading data.
	 */
	public function processForLazyLoading( string $image_path, array $options = array() ): array {
		$image_info = $this->getImageInfo( $image_path );
		if ( ! $image_info ) {
			return array();
		}

		// Generate placeholder
		$placeholder = $this->generatePlaceholder( $image_info, $options );

		// Generate modern formats
		$modern_formats = array();
		if ( $options['auto_format'] ?? true ) {
			$modern_formats = $this->generateModernFormats( $image_path, $options );
		}

		// Generate responsive images
		$responsive_images = array();
		if ( $options['generate_responsive'] ?? true ) {
			$responsive_images = $this->generateResponsiveImages( $image_path, $options );
		}

		return array(
			'placeholder'       => $placeholder,
			'modern_formats'    => $modern_formats,
			'responsive_images' => $responsive_images,
			'srcset'            => $this->generateSrcset( $responsive_images ),
			'sizes'             => $this->generateSizes(),
		);
	}

	/**
	 * Generate image placeholder.
	 *
	 * @param array $image_info Image information.
	 * @param array $options    Placeholder options.
	 * @return string Placeholder data URL.
	 */
	private function generatePlaceholder( array $image_info, array $options ): string {
		$width  = $image_info['width'];
		$height = $image_info['height'];

		// Generate SVG placeholder
		$svg  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $height . '">';
		$svg .= '<rect width="100%" height="100%" fill="#f0f0f0"/>';
		$svg .= '</svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Get supported content types.
	 *
	 * @return array Array of supported content types.
	 */
	public function get_supported_types(): array {
		return array( 'jpg', 'jpeg', 'png', 'webp', 'avif' );
	}

	/**
	 * Get optimization statistics.
	 *
	 * @return array Optimization statistics.
	 */
	public function get_stats(): array {
		return array(
			'images_optimized'  => 0,
			'bytes_saved'       => 0,
			'compression_ratio' => 0,
		);
	}

	/**
	 * Reset optimization statistics.
	 *
	 * @return void
	 */
	public function reset_stats(): void {
		// Implementation for resetting stats
	}
}
