<?php
/**
 * Queue Controller
 *
 * Handles queue-related API endpoints for statistics and management
 *
 * @package PerformanceOptimisation\Core\API\Controllers
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Core\API\Controllers;

use PerformanceOptimisation\Utils\ConversionQueue;
use PerformanceOptimisation\Services\QueueProcessorService;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queue Controller Class
 */
class QueueController {

	private ConversionQueue $queue;
	private $queueProcessor;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->queue = new ConversionQueue();
	}

	/**
	 * Register routes
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'performance-optimisation/v1',
			'/queue/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			'performance-optimisation/v1',
			'/queue/process',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'process_queue' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			'performance-optimisation/v1',
			'/queue/clear-completed',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'clear_completed' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);
	}

	/**
	 * Get queue statistics
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_stats( WP_REST_Request $request ) {
		try {
			$stats = $this->queue->get_stats();

			// Calculate totals
			$totals = array(
				'total_pending'   => 0,
				'total_completed' => 0,
				'total_failed'    => 0,
				'disk_space_saved' => 0,
			);

			foreach ( $stats as $format => $counts ) {
				$totals['total_pending']   += $counts['pending'] ?? 0;
				$totals['total_completed'] += $counts['completed'] ?? 0;
				$totals['total_failed']    += $counts['failed'] ?? 0;
			}

			// Calculate disk space saved
			$deletions = get_option( 'wppo_deleted_originals', array() );
			foreach ( $deletions as $deletion ) {
				if ( ! empty( $deletion['original_size'] ) ) {
					$totals['disk_space_saved'] += (int) $deletion['original_size'];
				}
			}

			return new WP_REST_Response(
				array(
					'stats'  => $stats,
					'totals' => $totals,
				),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_Error(
				'queue_stats_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Manually process queue
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function process_queue( WP_REST_Request $request ) {
		try {
			// Trigger the queue processing action
			do_action( 'wppo_process_conversion_queue' );

			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Queue processing triggered successfully', 'performance-optimisation' ),
				),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_Error(
				'queue_process_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Clear completed items from queue
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function clear_completed( WP_REST_Request $request ) {
		try {
			// Get current queue data
			$queue_data = get_option( 'wppo_img_info', array(
				'pending'   => array(),
				'completed' => array(),
				'failed'    => array(),
				'skipped'   => array(),
			) );

			// Clear completed items
			$queue_data['completed'] = array();

			// Save updated queue
			update_option( 'wppo_img_info', $queue_data );

			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Completed items cleared successfully', 'performance-optimisation' ),
				),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_Error(
				'queue_clear_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Check permissions
	 *
	 * @return bool
	 */
	public function check_permissions(): bool {
		return current_user_can( 'manage_options' );
	}
}
