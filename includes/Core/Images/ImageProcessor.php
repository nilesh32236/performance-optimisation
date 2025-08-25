<?php
/**
 * Image Processor
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\Images;

use PerformanceOptimisation\Interfaces\ImageProcessorInterface;
use PerformanceOptimisation\Interfaces\ConfigInterface;
use PerformanceOptimisation\Exceptions\ImageProcessingException;

/**
 * Image processing implementation
 *
 * @since 1.1.0
 */
class ImageProcessor implements ImageProcessorInterface {

	/**
	 * Processor name
	 *
	 * @since 1.1.0
	 * @var string
	 */
	private string $name = 'gd';

	/**
	 * Supported image types
	 *
	 * @since 1.1.0
	 * @var array
	 */
	private array $supported_types = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
	);

	/**
	 * Configuration manager
	 *
	 * @since 1.1.0
	 * @var ConfigInterface
	 */
	private ConfigInterface $config;

	/**
	 * Processing statistics
	 *
	 * @since 1.1.0
	 * @var array
	 */
	private array $stats = array(
		'images_processed' => 0,
		'bytes_saved'      => 0,
		'processing_time'  => 0,
		'conversions'      => 0,
		'resizes'          => 0,
	);

	/**
	 * Constructor
	 *
	 * @since 1.1.0
	 * @param ConfigInterface $config Configuration manager
	 * @throws ImageProcessingException If GD extension is not available
	 */
	public function __construct( ConfigInterface $config ) {
		$this->config = $config;

		if ( ! extension_loaded( 'gd' ) ) {
			throw new ImageProcessingException( 'GD extension is not available.' );
		}

		// Add AVIF support if available
		if ( function_exists( 'imageavif' ) ) {
			$this->supported_types[] = 'image/avif';
		}
	}

	/**
	 * Process an image
	 *
	 * @since 1.1.0
	 * @param string $source_path Source image path
	 * @param string $target_path Target image path
	 * @param array  $options     Processing options
	 * @return bool True on success, false on failure
	 */
	public function process( string $source_path, string $target_path, array $options = array() ): bool {
		$start_time = microtime( true );

		try {
			if ( ! file_exists( $source_path ) ) {
				throw new ImageProcessingException( "Source image not found: {$source_path}" );
			}

			$image_info = $this->get_image_info( $source_path );

			if ( empty( $image_info ) ) {
				throw new ImageProcessingException( "Invalid image: {$source_path}" );
			}

			$original_size = filesize( $source_path );

			// Determine processing type
			if ( isset( $options['resize'] ) ) {
				$result = $this->resize(
					$source_path,
					$target_path,
					$options['resize']['width'],
					$options['resize']['height'],
					$options['resize']['crop'] ?? false
				);
			} elseif ( isset( $options['convert'] ) ) {
				$result = $this->convert(
					$source_path,
					$target_path,
					$options['convert']['format'],
					$options['convert']['quality'] ?? 85
				);
			} else {
				$result = $this->compress(
					$source_path,
					$target_path,
					$options['quality'] ?? $this->config->get( 'images.compression_quality', 85 )
				);
			}

			if ( $result && file_exists( $target_path ) ) {
				$new_size = filesize( $target_path );
				++$this->stats['images_processed'];
				$this->stats['bytes_saved']     += ( $original_size - $new_size );
				$this->stats['processing_time'] += ( microtime( true ) - $start_time );
			}

			return $result;

		} catch ( ImageProcessingException $e ) {
			return false;
		}
	}

	/**
	 * Check if processor can handle the image type
	 *
	 * @since 1.1.0
	 * @param string $image_type Image MIME type
	 * @return bool True if can handle, false otherwise
	 */
	public function can_process( string $image_type ): bool {
		return in_array( $image_type, $this->supported_types, true );
	}

	/**
	 * Get processor name
	 *
	 * @since 1.1.0
	 * @return string Processor name
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Get supported image types
	 *
	 * @since 1.1.0
	 * @return array Array of supported MIME types
	 */
	public function get_supported_types(): array {
		return $this->supported_types;
	}

	/**
	 * Compress an image
	 *
	 * @since 1.1.0
	 * @param string $source_path Source image path
	 * @param string $target_path Target image path
	 * @param int    $quality     Compression quality (1-100)
	 * @return bool True on success, false on failure
	 */
	public function compress( string $source_path, string $target_path, int $quality = 85 ): bool {
		$image_info = $this->get_image_info( $source_path );

		if ( empty( $image_info ) ) {
			return false;
		}

		$source_image = $this->create_image_from_file( $source_path, $image_info['mime'] );

		if ( false === $source_image ) {
			return false;
		}

		$result = $this->save_image( $source_image, $target_path, $image_info['mime'], $quality );

		imagedestroy( $source_image );

		return $result;
	}

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
	public function resize( string $source_path, string $target_path, int $width, int $height, bool $crop = false ): bool {
		$image_info = $this->get_image_info( $source_path );

		if ( empty( $image_info ) ) {
			return false;
		}

		$source_image = $this->create_image_from_file( $source_path, $image_info['mime'] );

		if ( false === $source_image ) {
			return false;
		}

		$source_width  = $image_info['width'];
		$source_height = $image_info['height'];

		// Calculate dimensions
		if ( $crop ) {
			$dimensions = $this->calculate_crop_dimensions( $source_width, $source_height, $width, $height );
		} else {
			$dimensions = $this->calculate_resize_dimensions( $source_width, $source_height, $width, $height );
		}

		// Create new image
		$target_image = imagecreatetruecolor( $dimensions['width'], $dimensions['height'] );

		// Preserve transparency
		$this->preserve_transparency( $target_image, $source_image, $image_info['mime'] );

		// Resize image
		$result = imagecopyresampled(
			$target_image,
			$source_image,
			0,
			0,
			$dimensions['src_x'],
			$dimensions['src_y'],
			$dimensions['width'],
			$dimensions['height'],
			$dimensions['src_width'],
			$dimensions['src_height']
		);

		if ( $result ) {
			$quality = $this->config->get( 'images.compression_quality', 85 );
			$result  = $this->save_image( $target_image, $target_path, $image_info['mime'], $quality );
			++$this->stats['resizes'];
		}

		imagedestroy( $source_image );
		imagedestroy( $target_image );

		return $result;
	}

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
	public function convert( string $source_path, string $target_path, string $target_format, int $quality = 85 ): bool {
		$image_info = $this->get_image_info( $source_path );

		if ( empty( $image_info ) ) {
			return false;
		}

		$source_image = $this->create_image_from_file( $source_path, $image_info['mime'] );

		if ( false === $source_image ) {
			return false;
		}

		$target_mime = $this->format_to_mime( $target_format );

		if ( ! $this->can_process( $target_mime ) ) {
			imagedestroy( $source_image );
			return false;
		}

		$result = $this->save_image( $source_image, $target_path, $target_mime, $quality );

		if ( $result ) {
			++$this->stats['conversions'];
		}

		imagedestroy( $source_image );

		return $result;
	}

	/**
	 * Generate responsive image sizes
	 *
	 * @since 1.1.0
	 * @param string $source_path Source image path
	 * @param array  $sizes       Array of sizes to generate
	 * @param string $target_dir  Target directory
	 * @return array Array of generated image paths
	 */
	public function generate_responsive_sizes( string $source_path, array $sizes, string $target_dir ): array {
		$generated_images = array();
		$image_info       = $this->get_image_info( $source_path );

		if ( empty( $image_info ) ) {
			return $generated_images;
		}

		$filename  = pathinfo( $source_path, PATHINFO_FILENAME );
		$extension = pathinfo( $source_path, PATHINFO_EXTENSION );

		foreach ( $sizes as $size_name => $size_config ) {
			$target_filename = $filename . '-' . $size_name . '.' . $extension;
			$target_path     = trailingslashit( $target_dir ) . $target_filename;

			$success = $this->resize(
				$source_path,
				$target_path,
				$size_config['width'],
				$size_config['height'],
				$size_config['crop'] ?? false
			);

			if ( $success ) {
				$generated_images[ $size_name ] = $target_path;
			}
		}

		return $generated_images;
	}

	/**
	 * Get image information
	 *
	 * @since 1.1.0
	 * @param string $image_path Image path
	 * @return array Image information (width, height, type, size)
	 */
	public function get_image_info( string $image_path ): array {
		if ( ! file_exists( $image_path ) ) {
			return array();
		}

		$image_info = getimagesize( $image_path );

		if ( false === $image_info ) {
			return array();
		}

		return array(
			'width'  => $image_info[0],
			'height' => $image_info[1],
			'type'   => $image_info[2],
			'mime'   => $image_info['mime'],
			'size'   => filesize( $image_path ),
		);
	}

	/**
	 * Get processing statistics
	 *
	 * @since 1.1.0
	 * @return array Processing statistics
	 */
	public function get_stats(): array {
		return $this->stats;
	}

	/**
	 * Reset processing statistics
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public function reset_stats(): void {
		$this->stats = array(
			'images_processed' => 0,
			'bytes_saved'      => 0,
			'processing_time'  => 0,
			'conversions'      => 0,
			'resizes'          => 0,
		);
	}

	/**
	 * Create image resource from file
	 *
	 * @since 1.1.0
	 * @param string $file_path Image file path
	 * @param string $mime_type Image MIME type
	 * @return resource|false Image resource or false on failure
	 */
	private function create_image_from_file( string $file_path, string $mime_type ) {
		switch ( $mime_type ) {
			case 'image/jpeg':
				return imagecreatefromjpeg( $file_path );
			case 'image/png':
				return imagecreatefrompng( $file_path );
			case 'image/gif':
				return imagecreatefromgif( $file_path );
			case 'image/webp':
				return imagecreatefromwebp( $file_path );
			case 'image/avif':
				return function_exists( 'imagecreatefromavif' ) ? imagecreatefromavif( $file_path ) : false;
			default:
				return false;
		}
	}

	/**
	 * Save image to file
	 *
	 * @since 1.1.0
	 * @param resource $image     Image resource
	 * @param string   $file_path Target file path
	 * @param string   $mime_type Target MIME type
	 * @param int      $quality   Image quality
	 * @return bool True on success, false on failure
	 */
	private function save_image( $image, string $file_path, string $mime_type, int $quality = 85 ): bool {
		// Ensure target directory exists
		$target_dir = dirname( $file_path );
		if ( ! is_dir( $target_dir ) ) {
			wp_mkdir_p( $target_dir );
		}

		switch ( $mime_type ) {
			case 'image/jpeg':
				return imagejpeg( $image, $file_path, $quality );
			case 'image/png':
				// PNG quality is 0-9, convert from 0-100
				$png_quality = 9 - round( ( $quality / 100 ) * 9 );
				return imagepng( $image, $file_path, $png_quality );
			case 'image/gif':
				return imagegif( $image, $file_path );
			case 'image/webp':
				return imagewebp( $image, $file_path, $quality );
			case 'image/avif':
				return function_exists( 'imageavif' ) ? imageavif( $image, $file_path, $quality ) : false;
			default:
				return false;
		}
	}

	/**
	 * Preserve image transparency
	 *
	 * @since 1.1.0
	 * @param resource $target_image Target image resource
	 * @param resource $source_image Source image resource
	 * @param string   $mime_type    Image MIME type
	 * @return void
	 */
	private function preserve_transparency( $target_image, $source_image, string $mime_type ): void {
		if ( 'image/png' === $mime_type || 'image/gif' === $mime_type ) {
			imagealphablending( $target_image, false );
			imagesavealpha( $target_image, true );

			$transparent = imagecolorallocatealpha( $target_image, 255, 255, 255, 127 );
			imagefill( $target_image, 0, 0, $transparent );
		}
	}

	/**
	 * Calculate resize dimensions
	 *
	 * @since 1.1.0
	 * @param int $source_width  Source width
	 * @param int $source_height Source height
	 * @param int $target_width  Target width
	 * @param int $target_height Target height
	 * @return array Calculated dimensions
	 */
	private function calculate_resize_dimensions( int $source_width, int $source_height, int $target_width, int $target_height ): array {
		$ratio = min( $target_width / $source_width, $target_height / $source_height );

		return array(
			'width'      => round( $source_width * $ratio ),
			'height'     => round( $source_height * $ratio ),
			'src_x'      => 0,
			'src_y'      => 0,
			'src_width'  => $source_width,
			'src_height' => $source_height,
		);
	}

	/**
	 * Calculate crop dimensions
	 *
	 * @since 1.1.0
	 * @param int $source_width  Source width
	 * @param int $source_height Source height
	 * @param int $target_width  Target width
	 * @param int $target_height Target height
	 * @return array Calculated dimensions
	 */
	private function calculate_crop_dimensions( int $source_width, int $source_height, int $target_width, int $target_height ): array {
		$ratio = max( $target_width / $source_width, $target_height / $source_height );

		$crop_width  = round( $target_width / $ratio );
		$crop_height = round( $target_height / $ratio );

		$src_x = round( ( $source_width - $crop_width ) / 2 );
		$src_y = round( ( $source_height - $crop_height ) / 2 );

		return array(
			'width'      => $target_width,
			'height'     => $target_height,
			'src_x'      => $src_x,
			'src_y'      => $src_y,
			'src_width'  => $crop_width,
			'src_height' => $crop_height,
		);
	}

	/**
	 * Convert format name to MIME type
	 *
	 * @since 1.1.0
	 * @param string $format Format name
	 * @return string MIME type
	 */
	private function format_to_mime( string $format ): string {
		$format_map = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'avif' => 'image/avif',
		);

		return $format_map[ strtolower( $format ) ] ?? 'image/jpeg';
	}
}
