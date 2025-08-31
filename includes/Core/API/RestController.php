<?php
/**
 * Rest Controller
 *
 * @package PerformanceOptimisation\Core\API
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Core\API;

use PerformanceOptimisation\Interfaces\ServiceContainerInterface;
use PerformanceOptimisation\Services\SettingsService;
use PerformanceOptimisation\Services\CacheService;
use PerformanceOptimisation\Services\ImageService;
use PerformanceOptimisation\Services\OptimizationService;
use PerformanceOptimisation\Utils\LoggingUtil;
use PerformanceOptimisation\Utils\ValidationUtil;
use PerformanceOptimisation\Utils\PerformanceUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RestController
 *
 * @package PerformanceOptimisation\Core\API
 */
class RestController {

	private ServiceContainerInterface $container;
	private SettingsService $settingsService;
	private CacheService $cacheService;
	private ImageService $imageService;
	private OptimizationService $optimizationService;
	private LoggingUtil $logger;
	private ValidationUtil $validator;
	private PerformanceUtil $performance;

	public function __construct( ServiceContainerInterface $container ) {
		$this->container = $container;
		$this->settingsService = $container->get( 'settings_service' );
		$this->cacheService = $container->get( 'cache_service' );
		$this->imageService = $container->get( 'image_service' );
		$this->optimizationService = $container->get( 'optimization_service' );
		$this->logger = $container->get( 'logger' );
		$this->validator = $container->get( 'validator' );
		$this->performance = $container->get( 'performance' );
	}

	public function register_routes(): void {
		$this->logger->debug( 'Registering REST API routes' );

		// Settings endpoints
		register_rest_route(
			'wppo/v1',
			'/settings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_settings' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			'wppo/v1',
			'/settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_settings' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $this->get_settings_schema(),
			)
		);

		// Cache endpoints
		register_rest_route(
			'wppo/v1',
			'/cache/clear',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'clear_cache' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			'wppo/v1',
			'/cache/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_cache_stats' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		// Image optimization endpoints
		register_rest_route(
			'wppo/v1',
			'/images/optimize',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'optimize_images' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			'wppo/v1',
			'/images/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_image_stats' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		// Performance endpoints
		register_rest_route(
			'wppo/v1',
			'/performance/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_performance_stats' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		$this->logger->debug( 'REST API routes registered successfully' );
	}

	public function permissions_check(): bool {
		return current_user_can( 'manage_options' );
	}

	public function get_settings(): \WP_REST_Response {
		try {
			$this->performance->startTimer( 'api_get_settings' );
			$settings = $this->settingsService->get_settings();
			$duration = $this->performance->endTimer( 'api_get_settings' );

			$this->logger->debug( 'Settings retrieved via API', array( 'duration' => $duration ) );

			return new \WP_REST_Response( array(
				'success' => true,
				'data' => $settings,
				'meta' => array(
					'duration' => $duration,
					'timestamp' => time(),
				),
			), 200 );

		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to get settings via API: ' . $e->getMessage() );
			return new \WP_REST_Response( array(
				'success' => false,
				'error' => $e->getMessage(),
			), 500 );
		}
	}

	public function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->performance->startTimer( 'api_save_settings' );
			$settings = $request->get_json_params();

			// Validate settings
			$validation_result = $this->validator->validateSettings( $settings );
			if ( ! $validation_result['valid'] ) {
				return new \WP_REST_Response( array(
					'success' => false,
					'error' => 'Invalid settings provided',
					'validation_errors' => $validation_result['errors'],
				), 400 );
			}

			$result = $this->settingsService->update_settings( $settings );
			$duration = $this->performance->endTimer( 'api_save_settings' );

			$this->logger->info( 'Settings updated via API', array(
				'duration' => $duration,
				'settings_count' => count( $settings ),
			) );

			return new \WP_REST_Response( array(
				'success' => true,
				'data' => $result,
				'meta' => array(
					'duration' => $duration,
					'timestamp' => time(),
				),
			), 200 );

		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to save settings via API: ' . $e->getMessage() );
			return new \WP_REST_Response( array(
				'success' => false,
				'error' => $e->getMessage(),
			), 500 );
		}
	}

	public function clear_cache( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->performance->startTimer( 'api_clear_cache' );
			$cache_type = $request->get_param( 'type' ) ?? 'all';

			$result = $this->cacheService->clearCache( $cache_type );
			$duration = $this->performance->endTimer( 'api_clear_cache' );

			$this->logger->info( 'Cache cleared via API', array(
				'type' => $cache_type,
				'duration' => $duration,
				'result' => $result,
			) );

			return new \WP_REST_Response( array(
				'success' => true,
				'data' => $result,
				'meta' => array(
					'cache_type' => $cache_type,
					'duration' => $duration,
					'timestamp' => time(),
				),
			), 200 );

		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to clear cache via API: ' . $e->getMessage() );
			return new \WP_REST_Response( array(
				'success' => false,
				'error' => $e->getMessage(),
			), 500 );
		}
	}

	public function get_cache_stats(): \WP_REST_Response {
		try {
			$this->performance->startTimer( 'api_cache_stats' );
			$stats = $this->cacheService->getStats();
			$duration = $this->performance->endTimer( 'api_cache_stats' );

			return new \WP_REST_Response( array(
				'success' => true,
				'data' => $stats,
				'meta' => array(
					'duration' => $duration,
					'timestamp' => time(),
				),
			), 200 );

		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to get cache stats via API: ' . $e->getMessage() );
			return new \WP_REST_Response( array(
				'success' => false,
				'error' => $e->getMessage(),
			), 500 );
		}
	}

	public function optimize_images( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->performance->startTimer( 'api_optimize_images' );
			$batch_size = $request->get_param( 'batch_size' ) ?? 5;

			$result = $this->imageService->processBatch( array( 'batch_size' => $batch_size ) );
			$duration = $this->performance->endTimer( 'api_optimize_images' );

			$this->logger->info( 'Images optimized via API', array(
				'batch_size' => $batch_size,
				'duration' => $duration,
				'result' => $result,
			) );

			return new \WP_REST_Response( array(
				'success' => true,
				'data' => $result,
				'meta' => array(
					'batch_size' => $batch_size,
					'duration' => $duration,
					'timestamp' => time(),
				),
			), 200 );

		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to optimize images via API: ' . $e->getMessage() );
			return new \WP_REST_Response( array(
				'success' => false,
				'error' => $e->getMessage(),
			), 500 );
		}
	}

	public function get_image_stats(): \WP_REST_Response {
		try {
			$this->performance->startTimer( 'api_image_stats' );
			$stats = $this->imageService->getStats();
			$duration = $this->performance->endTimer( 'api_image_stats' );

			return new \WP_REST_Response( array(
				'success' => true,
				'data' => $stats,
				'meta' => array(
					'duration' => $duration,
					'timestamp' => time(),
				),
			), 200 );

		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to get image stats via API: ' . $e->getMessage() );
			return new \WP_REST_Response( array(
				'success' => false,
				'error' => $e->getMessage(),
			), 500 );
		}
	}

	public function get_performance_stats(): \WP_REST_Response {
		try {
			$this->performance->startTimer( 'api_performance_stats' );
			$stats = $this->performance->getStats();
			$duration = $this->performance->endTimer( 'api_performance_stats' );

			return new \WP_REST_Response( array(
				'success' => true,
				'data' => $stats,
				'meta' => array(
					'duration' => $duration,
					'timestamp' => time(),
				),
			), 200 );

		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to get performance stats via API: ' . $e->getMessage() );
			return new \WP_REST_Response( array(
				'success' => false,
				'error' => $e->getMessage(),
			), 500 );
		}
	}

	private function get_settings_schema(): array {
		return array(
			'caching' => array(
				'type' => 'object',
				'properties' => array(
					'page_cache_enabled' => array( 'type' => 'boolean' ),
					'cache_ttl' => array( 'type' => 'integer', 'minimum' => 300 ),
					'cache_exclusions' => array( 'type' => 'array' ),
				),
			),
			'minification' => array(
				'type' => 'object',
				'properties' => array(
					'minify_css' => array( 'type' => 'boolean' ),
					'minify_js' => array( 'type' => 'boolean' ),
					'combine_css' => array( 'type' => 'boolean' ),
					'minify_html' => array( 'type' => 'boolean' ),
				),
			),
			'images' => array(
				'type' => 'object',
				'properties' => array(
					'convert_to_webp' => array( 'type' => 'boolean' ),
					'lazy_loading' => array( 'type' => 'boolean' ),
					'compression_quality' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
				),
			),
		);
	}
}
