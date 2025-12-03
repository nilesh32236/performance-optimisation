<?php
/**
 * Cache REST API Controller
 *
 * @package PerformanceOptimisation\Core\API
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Core\API;

use PerformanceOptimisation\Services\PageCacheService;
use PerformanceOptimisation\Services\BrowserCacheService;
use PerformanceOptimisation\Utils\LoggingUtil;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache Controller Class
 */
class CacheController extends WP_REST_Controller {

	private ?PageCacheService $page_cache       = null;
	private ?BrowserCacheService $browser_cache = null;
	private ?LoggingUtil $logger                = null;

	public function __construct( ?PageCacheService $page_cache = null, ?BrowserCacheService $browser_cache = null, ?LoggingUtil $logger = null ) {
		$this->namespace     = 'performance-optimisation/v1';
		$this->rest_base     = 'cache';
		$this->page_cache    = $page_cache;
		$this->browser_cache = $browser_cache;
		$this->logger        = $logger ?? new LoggingUtil();
	}

	public function register_routes(): void {
		// Get cache statistics
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/stats',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_cache_stats' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Clear all cache
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/clear',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'clear_cache' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'type' => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => 'all',
							'enum'              => array( 'all', 'page', 'url' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'url'  => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'esc_url_raw',
						),
					),
				),
			)
		);
	}

	/**
	 * Check if user has permission
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Alias for check_permission (for compatibility)
	 */
	public function check_admin_permissions(): bool {
		return $this->check_permission();
	}

	/**
	 * Preload cache (placeholder for future implementation)
	 */
	public function preload_cache( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Cache preloading will be implemented in a future update',
			),
			200
		);
	}

	/**
	 * Get cache statistics
	 */
	public function get_cache_stats( WP_REST_Request $request ): WP_REST_Response {
		$start_time = microtime( true );
		$this->logger->debug( 'CacheController: get_cache_stats called' );

		if ( ! $this->page_cache ) {
			$this->logger->debug( 'CacheController: PageCacheService not available' );
			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => array(
						'page_cache'    => array(
							'enabled'  => false,
							'files'    => 0,
							'size'     => '0 B',
							'hit_rate' => 0,
						),
						'object_cache'  => array(
							'enabled'  => false,
							'backend'  => 'None',
							'hit_rate' => 0,
						),
						'browser_cache' => array(
							'enabled'           => false,
							'rules_count'       => 0,
							'htaccess_writable' => false,
						),
					),
				),
				200
			);
		}

		try {
			$this->logger->debug( 'CacheController: Calling PageCacheService->get_cache_stats()' );
			$stats = $this->page_cache->get_cache_stats();

			$elapsed = microtime( true ) - $start_time;
			$this->logger->debug(
				'CacheController: Stats retrieved',
				array(
					'elapsed_ms' => round( $elapsed * 1000, 2 ),
					'files'      => $stats['files'],
				)
			);

			// Get browser cache stats
			$browser_stats = $this->browser_cache ? $this->browser_cache->get_stats() : array(
				'enabled'           => false,
				'rules_count'       => 0,
				'htaccess_writable' => false,
			);

			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => array(
						'page_cache'    => array(
							'enabled'  => $stats['enabled'],
							'files'    => $stats['files'],
							'size'     => $stats['size_formatted'],
							'hit_rate' => $stats['hit_rate'],
						),
						'browser_cache' => array(
							'enabled'           => $browser_stats['enabled'],
							'rules_count'       => $browser_stats['rules_count'],
							'htaccess_writable' => $browser_stats['htaccess_writable'],
						),
						'object_cache'  => array(
							'enabled'  => false,
							'backend'  => 'None',
							'hit_rate' => 0,
						),
					),
				),
				200
			);

		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to get cache stats', array( 'error' => $e->getMessage() ) );

			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Failed to retrieve cache statistics',
				),
				500
			);
		}
	}

	/**
	 * Clear cache
	 */
	public function clear_cache( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->page_cache ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Page cache service not available',
				),
				500
			);
		}

		$type = $request->get_param( 'type' );
		$url  = $request->get_param( 'url' );
		$path = $request->get_param( 'path' );

		try {
			$result = false;

			switch ( $type ) {
				case 'url':
					$target_url = $url ?? $path;
					if ( empty( $target_url ) ) {
						return new WP_REST_Response(
							array(
								'success' => false,
								'message' => 'URL parameter is required for URL cache clearing',
							),
							400
						);
					}
					$result  = $this->page_cache->clear_url_cache( $target_url );
					$message = 'URL cache cleared successfully';
					break;

				case 'page':
				case 'all':
				default:
					$result  = $this->page_cache->clear_all_cache();
					$message = 'All cache cleared successfully';
					break;
			}

			if ( $result ) {
				return new WP_REST_Response(
					array(
						'success' => true,
						'message' => $message,
					),
					200
				);
			}

			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Failed to clear cache',
				),
				500
			);

		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to clear cache', array( 'error' => $e->getMessage() ) );

			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'An error occurred while clearing cache',
				),
				500
			);
		}
	}
}
