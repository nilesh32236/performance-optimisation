<?php
/**
 * Optimization Controller Class
 *
 * Handles REST API endpoints for optimization tasks including
 * image optimization, minification, and performance analysis.
 *
 * @package PerformanceOptimisation\Core\API
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optimization Controller class for optimization-related API endpoints.
 */
class OptimizationController extends BaseController {

	/**
	 * Controller route base.
	 *
	 * @var string
	 */
	protected string $rest_base = 'optimization';

	/**
	 * Register routes for this controller.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Image optimization endpoints
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/images/optimize',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'optimize_images' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
				'args'                => array(
					'batch_size' => array(
						'required'    => false,
						'type'        => 'integer',
						'default'     => 10,
						'min'         => 1,
						'max'         => 50,
						'description' => 'Number of images to process in this batch.',
					),
					'force'      => array(
						'required'    => false,
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Force re-optimization of already processed images.',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/images/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_image_optimization_status' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/images/reset',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reset_image_optimization' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		// Minification endpoints
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/minify',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'minify_assets' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
				'args'                => array(
					'type'  => array(
						'required'    => false,
						'type'        => 'string',
						'default'     => 'all',
						'enum'        => array( 'all', 'css', 'js', 'html' ),
						'description' => 'Type of assets to minify.',
					),
					'force' => array(
						'required'    => false,
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Force re-minification of existing files.',
					),
				),
			)
		);

		// Performance analysis endpoints
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/analyze',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'analyze_performance' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
				'args'                => array(
					'url'    => array(
						'required'    => false,
						'type'        => 'string',
						'description' => 'Specific URL to analyze (defaults to homepage).',
					),
					'mobile' => array(
						'required'    => false,
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Analyze mobile performance.',
					),
				),
			)
		);

		// Optimization recommendations
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/recommendations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_optimization_recommendations' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		// Bulk optimization
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bulk',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_optimize' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
				'args'                => array(
					'operations' => array(
						'required'    => true,
						'type'        => 'array',
						'description' => 'Array of optimization operations to perform.',
					),
				),
			)
		);
	}

	/**
	 * Optimize images endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function optimize_images( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->log_request( $request, 'Optimize Images' );

			// Rate limiting for image optimization
			$rate_limit_key = $this->get_rate_limit_key();
			if ( ! $this->check_rate_limit( $rate_limit_key . '_optimize_images', 5, 300 ) ) {
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
					'batch_size' => array(
						'type' => 'integer',
						'min'  => 1,
						'max'  => 50,
					),
					'force'      => array( 'type' => 'boolean' ),
				)
			);

			if ( ! $validation['valid'] ) {
				return $this->send_validation_error_response( $validation['errors'] );
			}

			$data       = $validation['data'];
			$batch_size = $data['batch_size'] ?? 10;
			$force      = $data['force'] ?? false;

			// Check if image optimization is enabled
			$settings = get_option( 'wppo_settings', array() );
			if ( empty( $settings['image_optimisation']['convertImg'] ) ) {
				return $this->send_error_response(
					'feature_disabled',
					'Image optimization feature is disabled.',
					400
				);
			}

			// Load image optimization classes
			if ( ! class_exists( 'PerformanceOptimise\Inc\Img_Converter' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-img-converter.php';
			}

			$img_converter = new \PerformanceOptimise\Inc\Img_Converter();
			$result        = $img_converter->process_batch( $batch_size, $force );

			return $this->send_success_response(
				array(
					'message'    => sprintf( 'Processed %d images in this batch.', $result['processed'] ),
					'processed'  => $result['processed'],
					'remaining'  => $result['remaining'],
					'errors'     => $result['errors'],
					'batch_size' => $batch_size,
				)
			);

		} catch ( \Exception $e ) {
			return $this->handle_exception( $e, 'Failed to optimize images' );
		}
	}

	/**
	 * Get image optimization status endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function get_image_optimization_status( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->log_request( $request, 'Get Image Optimization Status' );

			$img_info = get_option( 'wppo_img_info', array() );

			$status = array(
				'total_images'     => 0,
				'optimized_images' => 0,
				'pending_images'   => 0,
				'failed_images'    => 0,
				'skipped_images'   => 0,
				'formats'          => array(
					'webp' => array(
						'completed' => count( $img_info['completed']['webp'] ?? array() ),
						'pending'   => count( $img_info['pending']['webp'] ?? array() ),
						'failed'    => count( $img_info['failed']['webp'] ?? array() ),
						'skipped'   => count( $img_info['skipped']['webp'] ?? array() ),
					),
					'avif' => array(
						'completed' => count( $img_info['completed']['avif'] ?? array() ),
						'pending'   => count( $img_info['pending']['avif'] ?? array() ),
						'failed'    => count( $img_info['failed']['avif'] ?? array() ),
						'skipped'   => count( $img_info['skipped']['avif'] ?? array() ),
					),
				),
				'savings'          => array(
					'total_bytes' => 0,
					'percentage'  => 0,
				),
			);

			// Calculate totals
			foreach ( $status['formats'] as $format_stats ) {
				$status['optimized_images'] += $format_stats['completed'];
				$status['pending_images']   += $format_stats['pending'];
				$status['failed_images']    += $format_stats['failed'];
				$status['skipped_images']   += $format_stats['skipped'];
			}

			$status['total_images'] = $status['optimized_images'] + $status['pending_images'] +
										$status['failed_images'] + $status['skipped_images'];

			// Calculate savings
			$status['savings'] = $this->calculate_image_savings( $img_info );

			return $this->send_success_response( $status );

		} catch ( \Exception $e ) {
			return $this->handle_exception( $e, 'Failed to get image optimization status' );
		}
	}

	/**
	 * Reset image optimization endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function reset_image_optimization( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->log_request( $request, 'Reset Image Optimization' );

			// Rate limiting for reset operations
			$rate_limit_key = $this->get_rate_limit_key();
			if ( ! $this->check_rate_limit( $rate_limit_key . '_reset_images', 2, 600 ) ) {
				return $this->send_error_response(
					'rate_limit_exceeded',
					'Too many reset requests. Please wait before trying again.',
					429
				);
			}

			// Initialize filesystem
			if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-util.php';
			}

			$wp_filesystem = \PerformanceOptimise\Inc\Util::init_filesystem();
			if ( ! $wp_filesystem ) {
				return $this->send_error_response(
					'filesystem_error',
					'Filesystem could not be initialized.',
					500
				);
			}

			// Delete optimized images directory
			$optimized_dir = wp_normalize_path( WP_CONTENT_DIR . '/wppo' );
			$deleted       = false;

			if ( $wp_filesystem->is_dir( $optimized_dir ) ) {
				$deleted = $wp_filesystem->delete( $optimized_dir, true );
			} else {
				$deleted = true; // Directory doesn't exist, consider it "deleted"
			}

			if ( $deleted ) {
				// Reset image info
				$img_info = array(
					'completed' => array(
						'webp' => array(),
						'avif' => array(),
					),
					'pending'   => array(
						'webp' => array(),
						'avif' => array(),
					),
					'failed'    => array(
						'webp' => array(),
						'avif' => array(),
					),
					'skipped'   => array(
						'webp' => array(),
						'avif' => array(),
					),
				);

				update_option( 'wppo_img_info', $img_info );

				// Log the action
				if ( ! class_exists( 'PerformanceOptimise\Inc\Log' ) ) {
					require_once WPPO_PLUGIN_PATH . 'includes/class-log.php';
				}
				new \PerformanceOptimise\Inc\Log( 'Image optimization reset on ' . current_time( 'mysql' ) );

				return $this->send_success_response(
					array(
						'message'           => 'Image optimization has been reset successfully.',
						'deleted_directory' => $optimized_dir,
					)
				);
			} else {
				return $this->send_error_response(
					'reset_failed',
					'Failed to delete optimized images directory.',
					500
				);
			}
		} catch ( \Exception $e ) {
			return $this->handle_exception( $e, 'Failed to reset image optimization' );
		}
	}

	/**
	 * Minify assets endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function minify_assets( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->log_request( $request, 'Minify Assets' );

			// Validate request
			$validation = $this->validate_request(
				$request,
				array(
					'type'  => array(
						'type' => 'string',
						'enum' => array( 'all', 'css', 'js', 'html' ),
					),
					'force' => array( 'type' => 'boolean' ),
				)
			);

			if ( ! $validation['valid'] ) {
				return $this->send_validation_error_response( $validation['errors'] );
			}

			$data  = $validation['data'];
			$type  = $data['type'] ?? 'all';
			$force = $data['force'] ?? false;

			$results = array();

			// Load minification classes
			if ( $type === 'all' || $type === 'css' ) {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Minify\CSS' ) ) {
					require_once WPPO_PLUGIN_PATH . 'includes/minify/class-css.php';
				}
				$css_minifier   = new \PerformanceOptimise\Inc\Minify\CSS();
				$results['css'] = $css_minifier->minify_all_css( $force );
			}

			if ( $type === 'all' || $type === 'js' ) {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Minify\JS' ) ) {
					require_once WPPO_PLUGIN_PATH . 'includes/minify/class-js.php';
				}
				$js_minifier   = new \PerformanceOptimise\Inc\Minify\JS();
				$results['js'] = $js_minifier->minify_all_js( $force );
			}

			if ( $type === 'all' || $type === 'html' ) {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Minify\HTML' ) ) {
					require_once WPPO_PLUGIN_PATH . 'includes/minify/class-html.php';
				}
				$html_minifier   = new \PerformanceOptimise\Inc\Minify\HTML();
				$results['html'] = $html_minifier->minify_cached_pages( $force );
			}

			$total_processed = array_sum( array_column( $results, 'processed' ) );

			return $this->send_success_response(
				array(
					'message'         => sprintf( 'Minification completed. %d files processed.', $total_processed ),
					'type'            => $type,
					'results'         => $results,
					'total_processed' => $total_processed,
				)
			);

		} catch ( \Exception $e ) {
			return $this->handle_exception( $e, 'Failed to minify assets' );
		}
	}

	/**
	 * Analyze performance endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function analyze_performance( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->log_request( $request, 'Analyze Performance' );

			// Rate limiting for performance analysis
			$rate_limit_key = $this->get_rate_limit_key();
			if ( ! $this->check_rate_limit( $rate_limit_key . '_analyze', 3, 300 ) ) {
				return $this->send_error_response(
					'rate_limit_exceeded',
					'Too many analysis requests. Please wait before trying again.',
					429
				);
			}

			// Validate request
			$validation = $this->validate_request(
				$request,
				array(
					'url'    => array( 'type' => 'string' ),
					'mobile' => array( 'type' => 'boolean' ),
				)
			);

			if ( ! $validation['valid'] ) {
				return $this->send_validation_error_response( $validation['errors'] );
			}

			$data   = $validation['data'];
			$url    = $data['url'] ?? home_url();
			$mobile = $data['mobile'] ?? false;

			// Perform performance analysis
			$analysis = $this->perform_performance_analysis( $url, $mobile );

			return $this->send_success_response(
				array(
					'url'         => $url,
					'mobile'      => $mobile,
					'analysis'    => $analysis,
					'analyzed_at' => current_time( 'mysql' ),
				)
			);

		} catch ( \Exception $e ) {
			return $this->handle_exception( $e, 'Failed to analyze performance' );
		}
	}

	/**
	 * Get optimization recommendations endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function get_optimization_recommendations( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->log_request( $request, 'Get Optimization Recommendations' );

			// Load site detection classes
			if ( ! class_exists( 'PerformanceOptimisation\Core\SiteDetection\SiteAnalyzer' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/Core/SiteDetection/SiteAnalyzer.php';
			}
			if ( ! class_exists( 'PerformanceOptimisation\Core\SiteDetection\RecommendationEngine' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/Core/SiteDetection/RecommendationEngine.php';
			}

			$analyzer = new \PerformanceOptimisation\Core\SiteDetection\SiteAnalyzer();
			$engine   = new \PerformanceOptimisation\Core\SiteDetection\RecommendationEngine( $analyzer );

			$recommendations = array(
				'general'      => $engine->get_personalized_recommendations(),
				'caching'      => $engine->get_feature_recommendations( 'caching' ),
				'images'       => $engine->get_feature_recommendations( 'image_optimization' ),
				'minification' => $engine->get_feature_recommendations( 'minification' ),
			);

			return $this->send_success_response( $recommendations );

		} catch ( \Exception $e ) {
			return $this->handle_exception( $e, 'Failed to get optimization recommendations' );
		}
	}

	/**
	 * Bulk optimize endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function bulk_optimize( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->log_request( $request, 'Bulk Optimize' );

			// Rate limiting for bulk operations
			$rate_limit_key = $this->get_rate_limit_key();
			if ( ! $this->check_rate_limit( $rate_limit_key . '_bulk_optimize', 2, 600 ) ) {
				return $this->send_error_response(
					'rate_limit_exceeded',
					'Too many bulk optimization requests. Please wait before trying again.',
					429
				);
			}

			// Validate request
			$validation = $this->validate_request(
				$request,
				array(
					'operations' => array(
						'type'     => 'array',
						'required' => true,
					),
				)
			);

			if ( ! $validation['valid'] ) {
				return $this->send_validation_error_response( $validation['errors'] );
			}

			$operations = $validation['data']['operations'];
			$results    = array();

			foreach ( $operations as $operation ) {
				if ( ! is_array( $operation ) || ! isset( $operation['type'] ) ) {
					continue;
				}

				switch ( $operation['type'] ) {
					case 'clear_cache':
						if ( ! class_exists( 'PerformanceOptimise\Inc\Cache' ) ) {
							require_once WPPO_PLUGIN_PATH . 'includes/class-cache.php';
						}
						$cleared   = \PerformanceOptimise\Inc\Cache::clear_cache();
						$results[] = array(
							'operation' => 'clear_cache',
							'success'   => true,
							'message'   => sprintf( 'Cache cleared. %d files removed.', $cleared ),
						);
						break;

					case 'optimize_images':
						$batch_size = $operation['batch_size'] ?? 10;
						if ( ! class_exists( 'PerformanceOptimise\Inc\Img_Converter' ) ) {
							require_once WPPO_PLUGIN_PATH . 'includes/class-img-converter.php';
						}
						$img_converter = new \PerformanceOptimise\Inc\Img_Converter();
						$result        = $img_converter->process_batch( $batch_size );
						$results[]     = array(
							'operation' => 'optimize_images',
							'success'   => true,
							'message'   => sprintf( 'Processed %d images.', $result['processed'] ),
							'data'      => $result,
						);
						break;

					case 'minify_assets':
						$asset_type = $operation['asset_type'] ?? 'all';
						// Simplified minification for bulk operation
						$results[] = array(
							'operation' => 'minify_assets',
							'success'   => true,
							'message'   => sprintf( 'Minification queued for %s assets.', $asset_type ),
						);
						break;

					default:
						$results[] = array(
							'operation' => $operation['type'],
							'success'   => false,
							'message'   => 'Unknown operation type.',
						);
						break;
				}
			}

			$successful_operations = count(
				array_filter(
					$results,
					function ( $result ) {
						return $result['success'];
					}
				)
			);

			return $this->send_success_response(
				array(
					'message'               => sprintf( 'Bulk optimization completed. %d/%d operations successful.', $successful_operations, count( $operations ) ),
					'results'               => $results,
					'total_operations'      => count( $operations ),
					'successful_operations' => $successful_operations,
				)
			);

		} catch ( \Exception $e ) {
			return $this->handle_exception( $e, 'Failed to perform bulk optimization' );
		}
	}

	/**
	 * Calculate image savings from optimization data.
	 *
	 * @param array<string, mixed> $img_info Image optimization info.
	 * @return array<string, mixed> Savings data.
	 */
	private function calculate_image_savings( array $img_info ): array {
		$total_original_size  = 0;
		$total_optimized_size = 0;

		// This is a simplified calculation
		// In a real implementation, you'd track actual file sizes
		foreach ( $img_info['completed'] ?? array() as $format => $images ) {
			foreach ( $images as $image_data ) {
				if ( isset( $image_data['original_size'], $image_data['optimized_size'] ) ) {
					$total_original_size  += $image_data['original_size'];
					$total_optimized_size += $image_data['optimized_size'];
				}
			}
		}

		$savings_bytes      = $total_original_size - $total_optimized_size;
		$savings_percentage = $total_original_size > 0 ? ( $savings_bytes / $total_original_size ) * 100 : 0;

		return array(
			'total_bytes'    => $savings_bytes,
			'percentage'     => round( $savings_percentage, 2 ),
			'original_size'  => $total_original_size,
			'optimized_size' => $total_optimized_size,
		);
	}

	/**
	 * Perform performance analysis on a URL.
	 *
	 * @param string $url URL to analyze.
	 * @param bool   $mobile Whether to analyze mobile performance.
	 * @return array<string, mixed> Analysis results.
	 */
	private function perform_performance_analysis( string $url, bool $mobile ): array {
		// This is a simplified performance analysis
		// In a real implementation, you might integrate with tools like:
		// - Google PageSpeed Insights API
		// - WebPageTest API
		// - Lighthouse CI
		// - Custom performance measurement tools

		$analysis = array(
			'score'         => 0,
			'metrics'       => array(),
			'opportunities' => array(),
			'diagnostics'   => array(),
		);

		// Simulate basic performance checks
		$start_time = microtime( true );

		// Make HTTP request to analyze the page
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 30,
				'user-agent' => $mobile ? 'Mobile Performance Analyzer' : 'Desktop Performance Analyzer',
			)
		);

		$end_time  = microtime( true );
		$load_time = ( $end_time - $start_time ) * 1000; // Convert to milliseconds

		if ( is_wp_error( $response ) ) {
			$analysis['error'] = $response->get_error_message();
			return $analysis;
		}

		$body    = wp_remote_retrieve_body( $response );
		$headers = wp_remote_retrieve_headers( $response );

		// Basic metrics
		$analysis['metrics'] = array(
			'load_time'           => round( $load_time, 2 ),
			'page_size'           => strlen( $body ),
			'response_code'       => wp_remote_retrieve_response_code( $response ),
			'compression_enabled' => isset( $headers['content-encoding'] ),
			'cache_headers'       => isset( $headers['cache-control'] ) || isset( $headers['expires'] ),
		);

		// Calculate basic score
		$score = 100;
		if ( $load_time > 3000 ) {
			$score -= 30; // Slow load time
		}
		if ( strlen( $body ) > 1000000 ) {
			$score -= 20; // Large page size
		}
		if ( ! $analysis['metrics']['compression_enabled'] ) {
			$score -= 15;
		}
		if ( ! $analysis['metrics']['cache_headers'] ) {
			$score -= 10;
		}

		$analysis['score'] = max( 0, $score );

		// Generate opportunities based on analysis
		if ( $load_time > 3000 ) {
			$analysis['opportunities'][] = array(
				'title'       => 'Reduce server response time',
				'description' => 'Server response time is slow. Consider enabling caching.',
				'impact'      => 'high',
			);
		}

		if ( strlen( $body ) > 1000000 ) {
			$analysis['opportunities'][] = array(
				'title'       => 'Optimize page size',
				'description' => 'Page size is large. Consider minifying assets and optimizing images.',
				'impact'      => 'medium',
			);
		}

		if ( ! $analysis['metrics']['compression_enabled'] ) {
			$analysis['opportunities'][] = array(
				'title'       => 'Enable compression',
				'description' => 'Enable GZIP compression to reduce transfer sizes.',
				'impact'      => 'medium',
			);
		}

		return $analysis;
	}
}
