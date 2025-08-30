<?php
/**
 * Cache Controller Class
 *
 * Handles REST API endpoints for cache management including
 * clearing cache, cache statistics, and cache configuration.
 *
 * @package PerformanceOptimisation\Core\API
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache Controller class for cache-related API endpoints.
 */
class CacheController extends BaseController {

	/**
	 * Controller route base.
	 *
	 * @var string
	 */
	protected string $rest_base = 'cache';

	/**
	 * Register routes for this controller.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Clear cache endpoint.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/clear',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'clear_cache' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
				'args'                => array(
					'type' => array(
						'required'    => false,
						'type'        => 'string',
						'default'     => 'all',
						'enum'        => array( 'all', 'page', 'object', 'minified' ),
						'description' => 'Type of cache to clear.',
					),
					'path' => array(
						'required'    => false,
						'type'        => 'string',
						'description' => 'Specific path to clear (for page cache).',
					),
				),
			)
		);

		// Cache statistics endpoint.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/stats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_cache_stats' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		// Cache preload endpoint.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/preload',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preload_cache' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
				'args'                => array(
					'urls'  => array(
						'required'    => false,
						'type'        => 'array',
						'description' => 'Specific URLs to preload.',
					),
					'limit' => array(
						'required'    => false,
						'type'        => 'integer',
						'default'     => 10,
						'min'         => 1,
						'max'         => 50,
						'description' => 'Maximum number of URLs to preload.',
					),
				),
			)
		);

		// Cache warmup endpoint.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/warmup',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'warmup_cache' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);
	}

	/**
	 * Clear cache endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function clear_cache( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->log_request( $request, 'Clear Cache' );

			// Rate limiting.
			$rate_limit_key = $this->get_rate_limit_key();
			if ( ! $this->check_rate_limit( $rate_limit_key . '_clear_cache', 10, 300 ) ) {
				return $this->send_error_response(
					'rate_limit_exceeded',
					'Too many cache clear requests. Please wait before trying again.',
					429
				);
			}

			// Validate request.
			$validation = $this->validate_request(
				$request,
				array(
					'type' => array(
						'type' => 'string',
						'enum' => array( 'all', 'page', 'object', 'minified' ),
					),
					'path' => array( 'type' => 'string' ),
				)
			);

			if ( ! $validation['valid'] ) {
				return $this->send_validation_error_response( $validation['errors'] );
			}

			$data       = $validation['data'];
			$cache_type = $data['type'] ?? 'all';
			$path       = $data['path'] ?? null;

			// Load cache class.
			if ( ! class_exists( 'PerformanceOptimise\Inc\Cache' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-cache.php';
			}

			$cleared_items = 0;
			$message       = '';

			switch ( $cache_type ) {
				case 'page':
					if ( $path ) {
						\PerformanceOptimise\Inc\Cache::clear_cache( $path );
						$message       = sprintf( 'Page cache cleared for: %s', esc_url( home_url( $path ) ) );
						$cleared_items = 1;
					} else {
						\PerformanceOptimise\Inc\Cache::clear_page_cache();
						$cleared_items = 0; // This method is void.
						$message       = 'Page cache cleared.';
					}
					break;

				case 'object':
					\PerformanceOptimise\Inc\Cache::clear_object_cache();
					$cleared_items = 0; // This method is void.
					$message       = 'Object cache cleared.';
					break;

				case 'minified':
					\PerformanceOptimise\Inc\Cache::clear_minified_cache();
					$cleared_items = 0; // This method is void.
					$message       = 'Minified files cache cleared.';
					break;

				case 'all':
				default:
					\PerformanceOptimise\Inc\Cache::clear_cache();
					$cleared_items = 0; // This method is void.
					$message       = 'All cache cleared.';
					break;
			}

			// Log the action.
			if ( ! class_exists( 'PerformanceOptimise\Inc\Log' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-log.php';
			}
			new \PerformanceOptimise\Inc\Log( $message . ' on ' . current_time( 'mysql' ) );

			return $this->send_success_response(
				array(
					'message'       => $message,
					'type'          => $cache_type,
					'cleared_items' => $cleared_items,
					'timestamp'     => current_time( 'mysql' ),
				)
			);

		} catch ( \Exception $e ) {
			return $this->handle_exception( $e, 'Failed to clear cache' );
		}
	}

	/**
	 * Get cache statistics endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function get_cache_stats( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->log_request( $request, 'Get Cache Stats' );

			// Load cache class.
			if ( ! class_exists( 'PerformanceOptimise\Inc\Cache' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-cache.php';
			}

			$stats = array(
				'page_cache'     => $this->get_page_cache_stats(),
				'object_cache'   => $this->get_object_cache_stats(),
				'minified_cache' => $this->get_minified_cache_stats(),
				'total_size'     => 0,
				'last_cleared'   => get_option( 'wppo_last_cache_clear', null ),
			);

			// Calculate total size.
			$stats['total_size'] = $stats['page_cache']['size'] +
									$stats['object_cache']['size'] +
									$stats['minified_cache']['size'];

			return $this->send_success_response( $stats );

		} catch ( \Exception $e ) {
			return $this->handle_exception( $e, 'Failed to get cache statistics' );
		}
	}

	/**
	 * Preload cache endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function preload_cache( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->log_request( $request, 'Preload Cache' );

			// Rate limiting for preload operations.
			$rate_limit_key = $this->get_rate_limit_key();
			if ( ! $this->check_rate_limit( $rate_limit_key . '_preload', 5, 300 ) ) {
				return $this->send_error_response(
					'rate_limit_exceeded',
					'Too many preload requests. Please wait before trying again.',
					429
				);
			}

			// Validate request.
			$validation = $this->validate_request(
				$request,
				array(
					'urls'  => array( 'type' => 'array' ),
					'limit' => array(
						'type' => 'integer',
						'min'  => 1,
						'max'  => 50,
					),
				)
			);

			if ( ! $validation['valid'] ) {
				return $this->send_validation_error_response( $validation['errors'] );
			}

			$data  = $validation['data'];
			$urls  = $data['urls'] ?? null;
			$limit = $data['limit'] ?? 10;

			// Load preload functionality.
			$cache_service = new \PerformanceOptimisation\Services\CacheService();
			$settings_service = new \PerformanceOptimisation\Services\SettingsService();
			// Skip image service for now as it requires complex dependencies
			$cron_manager = new \PerformanceOptimisation\Services\CronService(
				$cache_service,
				null, // ImageService placeholder
				$settings_service
			);

			if ( $urls ) {
				// Preload specific URLs.
				$preloaded = $cron_manager->preload_urls( array_slice( $urls, 0, $limit ) );
			} else {
				// Preload popular pages.
				$preloaded = $cron_manager->preload_popular_pages( $limit );
			}

			return $this->send_success_response(
				array(
					'message'         => sprintf( '%d pages queued for preloading', $preloaded ),
					'preloaded_count' => $preloaded,
					'limit'           => $limit,
				)
			);

		} catch ( \Exception $e ) {
			return $this->handle_exception( $e, 'Failed to preload cache' );
		}
	}

	/**
	 * Warmup cache endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function warmup_cache( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->log_request( $request, 'Warmup Cache' );

			// Rate limiting for warmup operations.
			$rate_limit_key = $this->get_rate_limit_key();
			if ( ! $this->check_rate_limit( $rate_limit_key . '_warmup', 3, 600 ) ) {
				return $this->send_error_response(
					'rate_limit_exceeded',
					'Too many warmup requests. Please wait before trying again.',
					429
				);
			}

			// Load cache warmup functionality.
			$cache_service = new \PerformanceOptimisation\Services\CacheService();
			$settings_service = new \PerformanceOptimisation\Services\SettingsService();
			// Skip image service for now as it requires complex dependencies
			$cron_manager = new \PerformanceOptimisation\Services\CronService(
				$cache_service,
				null, // ImageService placeholder
				$settings_service
			);
			$warmed_pages = $cron_manager->warmup_cache();

			return $this->send_success_response(
				array(
					'message'      => sprintf( 'Cache warmup initiated for %d pages', $warmed_pages ),
					'warmed_pages' => $warmed_pages,
				)
			);

		} catch ( \Exception $e ) {
			return $this->handle_exception( $e, 'Failed to warmup cache' );
		}
	}

	/**
	 * Get page cache statistics.
	 *
	 * @return array<string, mixed> Page cache stats.
	 */
	private function get_page_cache_stats(): array {
		$cache_dir = WP_CONTENT_DIR . '/cache/wppo/';

		if ( ! is_dir( $cache_dir ) ) {
			return array(
				'enabled'   => false,
				'files'     => 0,
				'size'      => 0,
				'hit_ratio' => 0,
			);
		}

		$files = 0;
		$size  = 0;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $cache_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				++$files;
				$size += $file->getSize();
			}
		}

		return array(
			'enabled'   => true,
			'files'     => $files,
			'size'      => $size,
			'hit_ratio' => $this->calculate_cache_hit_ratio(),
		);
	}

	/**
	 * Get object cache statistics.
	 *
	 * @return array<string, mixed> Object cache stats.
	 */
	private function get_object_cache_stats(): array {
		global $wp_object_cache;

		$stats = array(
			'enabled'   => wp_using_ext_object_cache(),
			'files'     => 0,
			'size'      => 0,
			'hit_ratio' => 0,
		);

		if ( wp_using_ext_object_cache() && isset( $wp_object_cache->cache_hits, $wp_object_cache->cache_misses ) ) {
			$total_requests     = $wp_object_cache->cache_hits + $wp_object_cache->cache_misses;
			$stats['hit_ratio'] = $total_requests > 0 ? ( $wp_object_cache->cache_hits / $total_requests ) * 100 : 0;
		}

		return $stats;
	}

	/**
	 * Get minified cache statistics.
	 *
	 * @return array<string, mixed> Minified cache stats.
	 */
	private function get_minified_cache_stats(): array {
		$minified_dir = WP_CONTENT_DIR . '/cache/wppo/minified/';

		if ( ! is_dir( $minified_dir ) ) {
			return array(
				'enabled' => false,
				'files'   => 0,
				'size'    => 0,
			);
		}

		$files = 0;
		$size  = 0;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $minified_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				++$files;
				$size += $file->getSize();
			}
		}

		return array(
			'enabled' => true,
			'files'   => $files,
			'size'    => $size,
		);
	}

	/**
	 * Calculate cache hit ratio.
	 *
	 * @return float Cache hit ratio percentage.
	 */
	private function calculate_cache_hit_ratio(): float {
		// This is a simplified calculation.
		// In a real implementation, you'd track actual cache hits/misses.
		$cache_stats = get_option( 'wppo_cache_stats', array() );

		$hits   = $cache_stats['hits'] ?? 0;
		$misses = $cache_stats['misses'] ?? 0;
		$total  = $hits + $misses;

		return $total > 0 ? ( $hits / $total ) * 100 : 0;
	}
}
