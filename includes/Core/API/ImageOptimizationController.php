<?php
/**
 * Image Optimization Controller Class
 *
 * Handles REST API endpoints for image optimization including
 * batch processing, format conversion, and progress tracking.
 *
 * @package PerformanceOptimisation\Core\API
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Core\API;

use PerformanceOptimisation\Interfaces\ServiceContainerInterface;
use PerformanceOptimisation\Optimizers\ModernImageProcessor;
use PerformanceOptimisation\Utils\LoggingUtil;
use PerformanceOptimisation\Utils\ValidationUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Image Optimization Controller class for image-related API endpoints.
 */
class ImageOptimizationController extends BaseController {

	/**
	 * Controller route base.
	 *
	 * @var string
	 */
	protected string $rest_base = 'images';

	/**
	 * Image processor instance.
	 *
	 * @var ModernImageProcessor
	 */
	private ModernImageProcessor $image_processor;

	/**
	 * Constructor.
	 *
	 * @param ServiceContainerInterface $container Service container.
	 */
	public function __construct( ServiceContainerInterface $container ) {
		parent::__construct( $container );
		$this->image_processor = $container->get( 'image_processor' );
	}

	/**
	 * Register routes for this controller.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Optimize single image endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/optimize',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'optimize_image' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
				'args'                => array(
					'image_id' => array(
						'required'    => false,
						'type'        => 'integer',
						'description' => 'WordPress attachment ID.',
					),
					'image_path' => array(
						'required'    => false,
						'type'        => 'string',
						'description' => 'Direct path to image file.',
					),
					'options' => array(
						'required'    => false,
						'type'        => 'object',
						'description' => 'Optimization options.',
						'properties'  => array(
							'quality' => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => 100,
								'default' => 85,
							),
							'progressive' => array(
								'type'    => 'boolean',
								'default' => true,
							),
							'auto_format' => array(
								'type'    => 'boolean',
								'default' => true,
							),
							'generate_responsive' => array(
								'type'    => 'boolean',
								'default' => true,
							),
						),
					),
				),
			)
		);

		// Batch optimize images endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/batch-optimize',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'batch_optimize_images' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
				'args'                => array(
					'image_ids' => array(
						'required'    => false,
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => 'Array of WordPress attachment IDs.',
					),
					'limit' => array(
						'required'    => false,
						'type'        => 'integer',
						'default'     => 10,
						'minimum'     => 1,
						'maximum'     => 50,
						'description' => 'Maximum number of images to process.',
					),
					'options' => array(
						'required'    => false,
						'type'        => 'object',
						'description' => 'Optimization options.',
					),
				),
			)
		);

		// Get optimization progress endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/progress/(?P<batch_id>[a-zA-Z0-9-]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_optimization_progress' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
				'args'                => array(
					'batch_id' => array(
						'required'    => true,
						'type'        => 'string',
						'description' => 'Batch processing ID.',
					),
				),
			)
		);

		// Convert image format endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/convert',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'convert_image_format' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
				'args'                => array(
					'image_id' => array(
						'required'    => true,
						'type'        => 'integer',
						'description' => 'WordPress attachment ID.',
					),
					'target_format' => array(
						'required'    => true,
						'type'        => 'string',
						'enum'        => array( 'webp', 'avif', 'jpeg', 'png' ),
						'description' => 'Target image format.',
					),
					'quality' => array(
						'required'    => false,
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 85,
						'description' => 'Image quality (1-100).',
					),
				),
			)
		);

		// Generate responsive images endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/responsive',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate_responsive_images' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
				'args'                => array(
					'image_id' => array(
						'required'    => true,
						'type'        => 'integer',
						'description' => 'WordPress attachment ID.',
					),
					'breakpoints' => array(
						'required'    => false,
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => 'Custom breakpoints for responsive images.',
					),
				),
			)
		);

		// Get image optimization stats endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/stats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_optimization_stats' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		// Get optimal format for browser endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/optimal-format',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_optimal_format' ),
				'permission_callback' => '__return_true', // Public endpoint
			)
		);
	}

	/**
	 * Optimize single image endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function optimize_image( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->log_request( $request, 'Optimize Image' );

			// Rate limiting
			$rate_limit_key = $this->get_rate_limit_key();
			if ( ! $this->check_rate_limit( $rate_limit_key . '_optimize_image', 20, 300 ) ) {
				return $this->send_error_response(
					'rate_limit_exceeded',
					'Too many image optimization requests. Please wait before trying again.',
					429
				);
			}

			// Validate request
			$validation = $this->validate_request(
				$request,
				array(
					'image_id'   => array( 'type' => 'integer' ),
					'image_path' => array( 'type' => 'string' ),
					'options'    => array( 'type' => 'object' ),
				)
			);

			if ( ! $validation['valid'] ) {
				return $this->send_validation_error_response( $validation['errors'] );
			}

			$data = $validation['data'];

			// Get image path
			$image_path = $this->get_image_path( $data );
			if ( ! $image_path ) {
				return $this->send_error_response(
					'invalid_image',
					'Could not find image file.',
					404
				);
			}

			// Optimize image
			$options = $data['options'] ?? array();
			$result = $this->image_processor->optimize( $image_path, $options );

			if ( ! $result['success'] ) {
				return $this->send_error_response(
					'optimization_failed',
					$result['error'] ?? 'Image optimization failed.',
					500
				);
			}

			// Log successful optimization
			LoggingUtil::info( 'Image optimized via API', array(
				'image_path' => $image_path,
				'compression_ratio' => $result['compression_ratio'],
				'user_id' => get_current_user_id(),
			) );

			return $this->send_success_response(
				array(
					'message' => 'Image optimized successfully.',
					'data'    => $result,
				)
			);

		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Image optimization API error: ' . $e->getMessage() );
			return $this->send_error_response(
				'internal_error',
				'An internal error occurred during image optimization.',
				500
			);
		}
	}

	/**
	 * Batch optimize images endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function batch_optimize_images( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->log_request( $request, 'Batch Optimize Images' );

			// Rate limiting for batch operations
			$rate_limit_key = $this->get_rate_limit_key();
			if ( ! $this->check_rate_limit( $rate_limit_key . '_batch_optimize', 5, 600 ) ) {
				return $this->send_error_response(
					'rate_limit_exceeded',
					'Too many batch optimization requests. Please wait before trying again.',
					429
				);
			}

			// Validate request
			$validation = $this->validate_request(
				$request,
				array(
					'image_ids' => array( 'type' => 'array' ),
					'limit'     => array( 'type' => 'integer' ),
					'options'   => array( 'type' => 'object' ),
				)
			);

			if ( ! $validation['valid'] ) {
				return $this->send_validation_error_response( $validation['errors'] );
			}

			$data = $validation['data'];
			$image_ids = $data['image_ids'] ?? array();
			$limit = min( $data['limit'] ?? 10, 50 ); // Cap at 50 images
			$options = $data['options'] ?? array();

			// If no specific IDs provided, get unoptimized images
			if ( empty( $image_ids ) ) {
				$image_ids = $this->get_unoptimized_image_ids( $limit );
			} else {
				$image_ids = array_slice( $image_ids, 0, $limit );
			}

			if ( empty( $image_ids ) ) {
				return $this->send_success_response(
					array(
						'message' => 'No images found to optimize.',
						'data'    => array(
							'processed' => 0,
							'results'   => array(),
						),
					)
				);
			}

			// Generate batch ID for progress tracking
			$batch_id = wp_generate_uuid4();

			// Initialize batch progress
			$this->init_batch_progress( $batch_id, $image_ids );

			// Process images (in background for large batches)
			if ( count( $image_ids ) > 5 ) {
				// Schedule background processing
				wp_schedule_single_event( time(), 'wppo_process_image_batch', array( $batch_id, $image_ids, $options ) );
				
				return $this->send_success_response(
					array(
						'message'  => 'Batch optimization started.',
						'batch_id' => $batch_id,
						'total'    => count( $image_ids ),
						'status'   => 'processing',
					)
				);
			} else {
				// Process immediately for small batches
				$results = $this->process_image_batch( $image_ids, $options );
				
				return $this->send_success_response(
					array(
						'message' => 'Batch optimization completed.',
						'data'    => $results,
					)
				);
			}
		} catch (\Exception $e) {
			error_log('Batch optimization error: ' . $e->getMessage());
			return new \WP_Error('optimization_failed', 'Batch optimization failed');
		}
	}

	/**
	 * Get optimization progress endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function get_optimization_progress( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$batch_id = $request->get_param( 'batch_id' );
			
			$progress = get_transient( 'wppo_batch_progress_' . $batch_id );
			
			if ( false === $progress ) {
				return $this->send_error_response(
					'batch_not_found',
					'Batch processing not found or expired.',
					404
				);
			}

			return $this->send_success_response( $progress );

		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Get optimization progress API error: ' . $e->getMessage() );
			return $this->send_error_response(
				'internal_error',
				'An internal error occurred while fetching progress.',
				500
			);
		}
	}

	/**
	 * Convert image format endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function convert_image_format( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->log_request( $request, 'Convert Image Format' );

			// Rate limiting
			$rate_limit_key = $this->get_rate_limit_key();
			if ( ! $this->check_rate_limit( $rate_limit_key . '_convert_format', 15, 300 ) ) {
				return $this->send_error_response(
					'rate_limit_exceeded',
					'Too many format conversion requests. Please wait before trying again.',
					429
				);
			}

			// Validate request
			$validation = $this->validate_request(
				$request,
				array(
					'image_id'      => array( 'type' => 'integer', 'required' => true ),
					'target_format' => array( 'type' => 'string', 'required' => true ),
					'quality'       => array( 'type' => 'integer' ),
				)
			);

			if ( ! $validation['valid'] ) {
				return $this->send_validation_error_response( $validation['errors'] );
			}

			$data = $validation['data'];
			$image_id = $data['image_id'];
			$target_format = $data['target_format'];
			$quality = $data['quality'] ?? 85;

			// Get image path
			$image_path = get_attached_file( $image_id );
			if ( ! $image_path || ! file_exists( $image_path ) ) {
				return $this->send_error_response(
					'image_not_found',
					'Image file not found.',
					404
				);
			}

			// Convert image
			$options = array( 'quality' => $quality );
			$converted_path = $this->image_processor->convertToFormat( $image_path, $target_format, $options );

			if ( ! $converted_path ) {
				return $this->send_error_response(
					'conversion_failed',
					'Image format conversion failed.',
					500
				);
			}

			return $this->send_success_response(
				array(
					'message'        => 'Image format converted successfully.',
					'converted_path' => $converted_path,
					'target_format'  => $target_format,
				)
			);

		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Image format conversion API error: ' . $e->getMessage() );
			return $this->send_error_response(
				'internal_error',
				'An internal error occurred during format conversion.',
				500
			);
		}
	}

	/**
	 * Generate responsive images endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function generate_responsive_images( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->log_request( $request, 'Generate Responsive Images' );

			// Validate request
			$validation = $this->validate_request(
				$request,
				array(
					'image_id'    => array( 'type' => 'integer', 'required' => true ),
					'breakpoints' => array( 'type' => 'array' ),
				)
			);

			if ( ! $validation['valid'] ) {
				return $this->send_validation_error_response( $validation['errors'] );
			}

			$data = $validation['data'];
			$image_id = $data['image_id'];
			$breakpoints = $data['breakpoints'] ?? array( 320, 640, 768, 1024, 1200 );

			// Get image path
			$image_path = get_attached_file( $image_id );
			if ( ! $image_path || ! file_exists( $image_path ) ) {
				return $this->send_error_response(
					'image_not_found',
					'Image file not found.',
					404
				);
			}

			// Generate responsive images
			$responsive_data = $this->image_processor->processForLazyLoading( $image_path, array(
				'generate_responsive' => true,
				'responsive_breakpoints' => $breakpoints,
			) );

			return $this->send_success_response(
				array(
					'message' => 'Responsive images generated successfully.',
					'data'    => $responsive_data,
				)
			);

		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Generate responsive images API error: ' . $e->getMessage() );
			return $this->send_error_response(
				'internal_error',
				'An internal error occurred while generating responsive images.',
				500
			);
		}
	}

	/**
	 * Get image optimization stats endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function get_optimization_stats( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$stats = array(
				'total_images' => $this->get_total_images_count(),
				'optimized_images' => $this->get_optimized_images_count(),
				'pending_images' => $this->get_pending_images_count(),
				'total_savings' => $this->get_total_savings(),
				'formats_supported' => $this->get_supported_formats(),
				'optimization_queue' => $this->get_optimization_queue_status(),
			);

			return $this->send_success_response( $stats );

		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Get optimization stats API error: ' . $e->getMessage() );
			return $this->send_error_response(
				'internal_error',
				'An internal error occurred while fetching stats.',
				500
			);
		}
	}

	/**
	 * Get optimal format for browser endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function get_optimal_format( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$user_agent = $request->get_header( 'User-Agent' ) ?? '';
			$optimal_format = $this->image_processor->getOptimalFormat( $user_agent );

			return $this->send_success_response(
				array(
					'optimal_format' => $optimal_format,
					'user_agent' => $user_agent,
				)
			);

		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Get optimal format API error: ' . $e->getMessage() );
			return $this->send_error_response(
				'internal_error',
				'An internal error occurred while determining optimal format.',
				500
			);
		}
	}

	/**
	 * Get image path from request data.
	 *
	 * @param array $data Request data.
	 * @return string|false Image path or false if not found.
	 */
	private function get_image_path( array $data ) {
		if ( isset( $data['image_id'] ) ) {
			return get_attached_file( $data['image_id'] );
		}

		if ( isset( $data['image_path'] ) ) {
			$path = ValidationUtil::sanitizePath( $data['image_path'] );
			return file_exists( $path ) ? $path : false;
		}

		return false;
	}

	/**
	 * Get unoptimized image IDs.
	 *
	 * @param int $limit Maximum number of IDs to return.
	 * @return array Array of image IDs.
	 */
	private function get_unoptimized_image_ids( int $limit ): array {
		global $wpdb;

		$query = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} 
			WHERE post_type = 'attachment' 
			AND post_mime_type LIKE 'image/%'
			AND ID NOT IN (
				SELECT post_id FROM {$wpdb->postmeta} 
				WHERE meta_key = '_wppo_optimized' 
				AND meta_value = '1'
			)
			ORDER BY post_date DESC 
			LIMIT %d",
			$limit
		);

		$results = $wpdb->get_col( $query );
		return array_map( 'intval', $results );
	}

	/**
	 * Initialize batch progress tracking.
	 *
	 * @param string $batch_id Batch ID.
	 * @param array  $image_ids Array of image IDs.
	 * @return void
	 */
	private function init_batch_progress( string $batch_id, array $image_ids ): void {
		$progress = array(
			'batch_id' => $batch_id,
			'total' => count( $image_ids ),
			'processed' => 0,
			'successful' => 0,
			'failed' => 0,
			'status' => 'pending',
			'started_at' => current_time( 'mysql' ),
			'results' => array(),
		);

		set_transient( 'wppo_batch_progress_' . $batch_id, $progress, 3600 ); // 1 hour
	}

	/**
	 * Process batch of images.
	 *
	 * @param array $image_ids Array of image IDs.
	 * @param array $options   Optimization options.
	 * @return array Processing results.
	 */
	private function process_image_batch( array $image_ids, array $options ): array {
		$results = array(
			'processed' => 0,
			'successful' => 0,
			'failed' => 0,
			'results' => array(),
		);

		foreach ( $image_ids as $image_id ) {
			$image_path = get_attached_file( $image_id );
			if ( ! $image_path || ! file_exists( $image_path ) ) {
				$results['failed']++;
				$results['results'][ $image_id ] = array(
					'success' => false,
					'error' => 'Image file not found.',
				);
				continue;
			}

			$result = $this->image_processor->optimize( $image_path, $options );
			$results['processed']++;

			if ( $result['success'] ) {
				$results['successful']++;
				// Mark as optimized
				update_post_meta( $image_id, '_wppo_optimized', '1' );
				update_post_meta( $image_id, '_wppo_optimization_data', $result );
			} else {
				$results['failed']++;
			}

			$results['results'][ $image_id ] = $result;
		}

		return $results;
	}

	/**
	 * Get total images count.
	 *
	 * @return int Total images count.
	 */
	private function get_total_images_count(): int {
		global $wpdb;
		
		$count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} 
			WHERE post_type = 'attachment' 
			AND post_mime_type LIKE 'image/%'"
		);

		return intval( $count );
	}

	/**
	 * Get optimized images count.
	 *
	 * @return int Optimized images count.
	 */
	private function get_optimized_images_count(): int {
		global $wpdb;
		
		$count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} 
			WHERE meta_key = '_wppo_optimized' 
			AND meta_value = '1'"
		);

		return intval( $count );
	}

	/**
	 * Get pending images count.
	 *
	 * @return int Pending images count.
	 */
	private function get_pending_images_count(): int {
		return $this->get_total_images_count() - $this->get_optimized_images_count();
	}

	/**
	 * Get total savings from optimization.
	 *
	 * @return array Total savings data.
	 */
	private function get_total_savings(): array {
		global $wpdb;
		
		$results = $wpdb->get_results(
			"SELECT meta_value FROM {$wpdb->postmeta} 
			WHERE meta_key = '_wppo_optimization_data'"
		);

		$total_original = 0;
		$total_optimized = 0;

		foreach ( $results as $result ) {
			$data = maybe_unserialize( $result->meta_value );
			if ( is_array( $data ) && isset( $data['original_size'], $data['optimized_size'] ) ) {
				$total_original += $data['original_size'];
				$total_optimized += $data['optimized_size'];
			}
		}

		$savings_bytes = $total_original - $total_optimized;
		$savings_percentage = $total_original > 0 ? ( $savings_bytes / $total_original ) * 100 : 0;

		return array(
			'total_original_bytes' => $total_original,
			'total_optimized_bytes' => $total_optimized,
			'savings_bytes' => $savings_bytes,
			'savings_percentage' => round( $savings_percentage, 2 ),
		);
	}

	/**
	 * Get supported image formats.
	 *
	 * @return array Supported formats.
	 */
	private function get_supported_formats(): array {
		return array(
			'jpeg' => function_exists( 'imagejpeg' ),
			'png' => function_exists( 'imagepng' ),
			'gif' => function_exists( 'imagegif' ),
			'webp' => function_exists( 'imagewebp' ),
			'avif' => function_exists( 'imageavif' ),
		);
	}

	/**
	 * Get optimization queue status.
	 *
	 * @return array Queue status.
	 */
	private function get_optimization_queue_status(): array {
		// This would integrate with a background processing queue
		return array(
			'active_batches' => 0,
			'queued_images' => 0,
			'processing' => false,
		);
	}
}
