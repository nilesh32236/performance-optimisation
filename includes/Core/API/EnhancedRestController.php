<?php
/**
 * Enhanced REST API Controller
 *
 * @package PerformanceOptimisation\Core\API
 * @since   2.1.0
 */

namespace PerformanceOptimisation\Core\API;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use PerformanceOptimisation\Interfaces\ServiceContainerInterface;
use PerformanceOptimisation\Utils\LoggingUtil;

/**
 * Enhanced REST API Controller Class
 */
class EnhancedRestController extends WP_REST_Controller {

	protected string $namespace = 'performance-optimisation/v1';
	private ServiceContainerInterface $container;

	public function __construct( ServiceContainerInterface $container ) {
		$this->container = $container;
	}

	public function register_routes(): void {
		// Dashboard analytics
		register_rest_route(
			$this->namespace,
			'/analytics/dashboard',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_dashboard_analytics' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		// Cache management
		register_rest_route(
			$this->namespace,
			'/cache/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_cache_stats' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/cache/clear',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'clear_cache' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		// Settings management
		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_settings' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_settings' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
				'args'                => $this->get_settings_schema(),
			)
		);

		// Image optimization
		register_rest_route(
			$this->namespace,
			'/images/optimize',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'optimize_images' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		// Performance metrics
		register_rest_route(
			$this->namespace,
			'/metrics/performance',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_performance_metrics' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		// System info
		register_rest_route(
			$this->namespace,
			'/system/info',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_system_info' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);
	}

	public function get_dashboard_analytics( WP_REST_Request $request ): WP_REST_Response {
		try {
			$analytics = array(
				'performance_score' => $this->calculate_performance_score(),
				'cache_hit_rate'    => $this->get_cache_hit_rate(),
				'page_load_time'    => $this->get_average_load_time(),
				'optimized_images'  => $this->get_optimized_images_count(),
				'cache_size'        => $this->get_cache_size(),
				'last_updated'      => current_time( 'mysql' ),
			);

			return new WP_REST_Response( $analytics, 200 );
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Dashboard analytics error: ' . $e->getMessage() );
			return new WP_REST_Response( array( 'error' => 'Failed to load analytics' ), 500 );
		}
	}

	public function get_cache_stats( WP_REST_Request $request ): WP_REST_Response {
		try {
			$cache_service = $this->container->get( 'cache_service' );
			$stats         = $cache_service->getStats();

			return new WP_REST_Response( $stats, 200 );
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Cache stats error: ' . $e->getMessage() );
			return new WP_REST_Response( array( 'error' => 'Failed to load cache stats' ), 500 );
		}
	}

	public function clear_cache( WP_REST_Request $request ): WP_REST_Response {
		try {
			$cache_service = $this->container->get( 'cache_service' );
			$result        = $cache_service->clearCache();

			return new WP_REST_Response(
				array(
					'success'   => $result,
					'message'   => __( 'Cache cleared successfully', 'performance-optimisation' ),
					'timestamp' => current_time( 'mysql' ),
				),
				200
			);
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Clear cache error: ' . $e->getMessage() );
			return new WP_REST_Response( array( 'error' => 'Failed to clear cache' ), 500 );
		}
	}

	public function get_settings( WP_REST_Request $request ): WP_REST_Response {
		try {
			$settings_service = $this->container->get( 'settings_service' );
			$settings         = $settings_service->getAllSettings();

			return new WP_REST_Response( $settings, 200 );
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Get settings error: ' . $e->getMessage() );
			return new WP_REST_Response( array( 'error' => 'Failed to load settings' ), 500 );
		}
	}

	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		try {
			$settings_service = $this->container->get( 'settings_service' );
			$new_settings     = $request->get_json_params();

			$result = $settings_service->updateSettings( $new_settings );

			return new WP_REST_Response(
				array(
					'success'  => $result,
					'message'  => __( 'Settings updated successfully', 'performance-optimisation' ),
					'settings' => $settings_service->getAllSettings(),
				),
				200
			);
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Update settings error: ' . $e->getMessage() );
			return new WP_REST_Response( array( 'error' => 'Failed to update settings' ), 500 );
		}
	}

	public function optimize_images( WP_REST_Request $request ): WP_REST_Response {
		try {
			$image_service = $this->container->get( 'image_service' );
			$batch_size    = $request->get_param( 'batch_size' ) ?: 10;

			$result = $image_service->processBatch( array( 'batch_size' => $batch_size ) );

			return new WP_REST_Response(
				array(
					'success'   => true,
					'processed' => $result['processed'] ?? 0,
					'remaining' => $result['remaining'] ?? 0,
					'message'   => sprintf(
						__( 'Processed %1$d images, %2$d remaining', 'performance-optimisation' ),
						$result['processed'] ?? 0,
						$result['remaining'] ?? 0
					),
				),
				200
			);
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Image optimization error: ' . $e->getMessage() );
			return new WP_REST_Response( array( 'error' => 'Failed to optimize images' ), 500 );
		}
	}

	public function get_performance_metrics( WP_REST_Request $request ): WP_REST_Response {
		try {
			$performance = $this->container->get( 'performance' );
			$metrics     = $performance->getMetrics();

			return new WP_REST_Response( $metrics, 200 );
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Performance metrics error: ' . $e->getMessage() );
			return new WP_REST_Response( array( 'error' => 'Failed to load performance metrics' ), 500 );
		}
	}

	public function get_system_info( WP_REST_Request $request ): WP_REST_Response {
		try {
			$info = array(
				'php_version'         => PHP_VERSION,
				'wp_version'          => get_bloginfo( 'version' ),
				'memory_limit'        => ini_get( 'memory_limit' ),
				'max_execution_time'  => ini_get( 'max_execution_time' ),
				'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
				'extensions'          => array(
					'gd'        => extension_loaded( 'gd' ),
					'imagick'   => extension_loaded( 'imagick' ),
					'redis'     => extension_loaded( 'redis' ),
					'memcached' => extension_loaded( 'memcached' ),
				),
				'cache_writable'      => wp_is_writable( WP_CONTENT_DIR . '/cache/' ),
				'plugin_version'      => WPPO_VERSION,
			);

			return new WP_REST_Response( $info, 200 );
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'System info error: ' . $e->getMessage() );
			return new WP_REST_Response( array( 'error' => 'Failed to load system info' ), 500 );
		}
	}

	public function check_admin_permissions(): bool {
		return current_user_can( 'manage_options' );
	}

	private function calculate_performance_score(): int {
		$score = 100;

		if ( ! $this->is_caching_enabled() ) {
			$score -= 20;
		}
		if ( ! $this->is_minification_enabled() ) {
			$score -= 15;
		}
		if ( ! $this->is_image_optimization_enabled() ) {
			$score -= 15;
		}

		$load_time = $this->get_average_load_time();
		if ( $load_time > 3 ) {
			$score -= 20;
		} elseif ( $load_time > 2 ) {
			$score -= 10;
		}

		return max( 0, $score );
	}

	private function get_cache_hit_rate(): float {
		try {
			$cache_service = $this->container->get( 'cache_service' );
			$stats         = $cache_service->getStats();
			return $stats['hit_rate'] ?? 0;
		} catch ( \Exception $e ) {
			return 0;
		}
	}

	private function get_average_load_time(): float {
		$load_times = get_option( 'wppo_load_times', array() );
		return empty( $load_times ) ? 0 : array_sum( $load_times ) / count( $load_times );
	}

	private function get_optimized_images_count(): int {
		return (int) get_option( 'wppo_optimized_images_count', 0 );
	}

	private function get_cache_size(): string {
		$cache_dir = WP_CONTENT_DIR . '/cache/wppo/';
		if ( ! is_dir( $cache_dir ) ) {
			return '0 MB';
		}

		$size = $this->get_directory_size( $cache_dir );
		return $this->format_bytes( $size );
	}

	private function get_directory_size( string $dir ): int {
		$size = 0;
		foreach ( glob( rtrim( $dir, '/' ) . '/*', GLOB_NOSORT ) as $file ) {
			$size += is_file( $file ) ? filesize( $file ) : $this->get_directory_size( $file );
		}
		return $size;
	}

	private function format_bytes( int $bytes ): string {
		$units = array( 'B', 'KB', 'MB', 'GB' );
		for ( $i = 0; $bytes > 1024 && $i < 3; $i++ ) {
			$bytes /= 1024;
		}
		return round( $bytes, 2 ) . ' ' . $units[ $i ];
	}

	private function is_caching_enabled(): bool {
		$settings = get_option( 'wppo_settings', array() );
		return $settings['caching']['page_cache_enabled'] ?? false;
	}

	private function is_minification_enabled(): bool {
		$settings = get_option( 'wppo_settings', array() );
		return $settings['minification']['minify_css'] ?? false ||
				$settings['minification']['minify_js'] ?? false;
	}

	private function is_image_optimization_enabled(): bool {
		$settings = get_option( 'wppo_settings', array() );
		return $settings['images']['convert_to_webp'] ?? false;
	}

	private function get_settings_schema(): array {
		return array(
			'caching'      => array(
				'type'       => 'object',
				'properties' => array(
					'page_cache_enabled' => array( 'type' => 'boolean' ),
					'cache_ttl'          => array(
						'type'    => 'integer',
						'minimum' => 300,
					),
					'cache_exclusions'   => array( 'type' => 'array' ),
				),
			),
			'minification' => array(
				'type'       => 'object',
				'properties' => array(
					'minify_css'  => array( 'type' => 'boolean' ),
					'minify_js'   => array( 'type' => 'boolean' ),
					'combine_css' => array( 'type' => 'boolean' ),
					'minify_html' => array( 'type' => 'boolean' ),
				),
			),
			'images'       => array(
				'type'       => 'object',
				'properties' => array(
					'convert_to_webp'     => array( 'type' => 'boolean' ),
					'lazy_loading'        => array( 'type' => 'boolean' ),
					'compression_quality' => array(
						'type'    => 'integer',
						'minimum' => 50,
						'maximum' => 100,
					),
				),
			),
		);
	}
}
