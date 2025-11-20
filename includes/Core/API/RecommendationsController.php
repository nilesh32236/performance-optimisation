<?php
/**
 * Recommendations Controller Class
 *
 * Handles REST API endpoints for automated recommendations and optimization suggestions.
 *
 * @package PerformanceOptimisation\Core\API
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recommendations Controller class for REST API endpoints.
 */
class RecommendationsController extends BaseController {

	/**
	 * Recommendation engine instance.
	 *
	 * @var \PerformanceOptimisation\Core\Analytics\RecommendationEngine
	 */
	private \PerformanceOptimisation\Core\Analytics\RecommendationEngine $recommendation_engine;

	/**
	 * Constructor.
	 *
	 * @param \PerformanceOptimisation\Core\Analytics\MetricsCollector    $metrics_collector Metrics collector instance.
	 * @param \PerformanceOptimisation\Core\Analytics\PerformanceAnalyzer $performance_analyzer Performance analyzer instance.
	 */
	public function __construct( \PerformanceOptimisation\Core\Analytics\MetricsCollector $metrics_collector, \PerformanceOptimisation\Core\Analytics\PerformanceAnalyzer $performance_analyzer ) {
		$this->recommendation_engine = new \PerformanceOptimisation\Core\Analytics\RecommendationEngine( $metrics_collector, $performance_analyzer );
	}

	/**
	 * Register the routes for this controller.
	 */
	public function register_routes(): void {
		// No routes to register for this controller.
	}

	/**
	 * Get automated recommendations.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response REST response object.
	 */
	public function get_recommendations( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$start_date = $request->get_param( 'start_date' ) ?: date( 'Y-m-d', strtotime( '-7 days' ) );
			$end_date   = $request->get_param( 'end_date' ) ?: current_time( 'Y-m-d' );

			$recommendations = $this->recommendation_engine->generate_recommendations( $start_date, $end_date );

			return $this->send_success_response( $recommendations );

		} catch ( \Exception $e ) {
			return $this->send_error_response(
				'recommendations_failed',
				__( 'Failed to generate recommendations.', 'performance-optimisation' ),
				500
			);
		}
	}

	/**
	 * Apply a specific recommendation.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response REST response object.
	 */
	public function apply_recommendation( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$recommendation_id = $request->get_param( 'recommendation_id' );

			if ( empty( $recommendation_id ) ) {
				return $this->send_error_response(
					'missing_recommendation_id',
					__( 'Recommendation ID is required.', 'performance-optimisation' ),
					400
				);
			}

			$result = $this->apply_automated_fix( $recommendation_id );

			if ( $result['success'] ) {
				return $this->send_success_response(
					array(
						'message'          => $result['message'],
						'applied_settings' => $result['settings'] ?? array(),
					)
				);
			} else {
				return $this->send_error_response(
					'recommendation_apply_failed',
					$result['message'],
					400
				);
			}
		} catch ( \Exception $e ) {
			return $this->send_error_response(
				'recommendation_apply_error',
				__( 'Failed to apply recommendation.', 'performance-optimisation' ),
				500
			);
		}
	}

	/**
	 * Get optimization suggestions.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response REST response object.
	 */
	public function get_optimization_suggestions( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$suggestions = $this->recommendation_engine->generate_optimization_suggestions();

			return $this->send_success_response(
				array(
					'suggestions'  => $suggestions,
					'generated_at' => current_time( 'mysql' ),
				)
			);

		} catch ( \Exception $e ) {
			return $this->send_error_response(
				'suggestions_failed',
				__( 'Failed to generate optimization suggestions.', 'performance-optimisation' ),
				500
			);
		}
	}

	/**
	 * Get optimization progress tracking.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response REST response object.
	 */
	public function get_optimization_progress( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$start_date = $request->get_param( 'start_date' ) ?: date( 'Y-m-d', strtotime( '-30 days' ) );
			$end_date   = $request->get_param( 'end_date' ) ?: current_time( 'Y-m-d' );

			$progress = $this->recommendation_engine->track_optimization_progress( $start_date, $end_date );

			return $this->send_success_response( $progress );

		} catch ( \Exception $e ) {
			return $this->send_error_response(
				'progress_tracking_failed',
				__( 'Failed to track optimization progress.', 'performance-optimisation' ),
				500
			);
		}
	}

	/**
	 * Apply automated fix for a recommendation.
	 *
	 * @param string $recommendation_id Recommendation ID.
	 * @return array<string, mixed> Application result.
	 */
	private function apply_automated_fix( string $recommendation_id ): array {
		$current_settings = get_option( 'wppo_settings', array() );
		$updated_settings = $current_settings;
		$applied_changes  = array();

		switch ( $recommendation_id ) {
			case 'enable_page_caching':
				$updated_settings['cache_settings']['enablePageCaching'] = true;
				$updated_settings['cache_settings']['cacheExpiration']   = 3600;
				$applied_changes[]                                       = 'Enabled page caching';
				$applied_changes[]                                       = 'Set cache expiration to 1 hour';
				break;

			case 'enable_basic_optimizations':
				$updated_settings['cache_settings']['enablePageCaching']  = true;
				$updated_settings['file_optimisation']['minifyCSS']       = true;
				$updated_settings['file_optimisation']['minifyJS']        = true;
				$updated_settings['image_optimisation']['lazyLoadImages'] = true;
				$applied_changes[]                                        = 'Enabled page caching';
				$applied_changes[]                                        = 'Enabled CSS minification';
				$applied_changes[]                                        = 'Enabled JavaScript minification';
				$applied_changes[]                                        = 'Enabled lazy loading for images';
				break;

			case 'enable_advanced_optimizations':
				$updated_settings['file_optimisation']['combineCSS'] = true;
				$updated_settings['file_optimisation']['combineJS']  = true;
				$updated_settings['file_optimisation']['deferJS']    = true;
				$applied_changes[]                                   = 'Enabled CSS combination';
				$applied_changes[]                                   = 'Enabled JavaScript combination';
				$applied_changes[]                                   = 'Enabled JavaScript deferring';
				break;

			case 'enable_minification':
				$updated_settings['file_optimisation']['minifyCSS']  = true;
				$updated_settings['file_optimisation']['minifyJS']   = true;
				$updated_settings['file_optimisation']['minifyHTML'] = true;
				$applied_changes[]                                   = 'Enabled CSS minification';
				$applied_changes[]                                   = 'Enabled JavaScript minification';
				$applied_changes[]                                   = 'Enabled HTML minification';
				break;

			case 'enable_lazy_loading':
				$updated_settings['image_optimisation']['lazyLoadImages'] = true;
				$applied_changes[]                                        = 'Enabled lazy loading for images';
				break;

			case 'optimize_images':
				$updated_settings['image_optimisation']['convertImg'] = true;
				$updated_settings['image_optimisation']['format']     = 'webp';
				$applied_changes[]                                    = 'Enabled image optimization';
				$applied_changes[]                                    = 'Set image format to WebP';

				// Trigger image optimization.
				$this->trigger_image_optimization();
				$applied_changes[] = 'Started bulk image optimization';
				break;

			case 'optimize_cache_settings':
				$updated_settings['cache_settings']['cacheExpiration']      = 7200;
				$updated_settings['preload_settings']['enablePreloadCache'] = true;
				$applied_changes[] = 'Increased cache expiration to 2 hours';
				$applied_changes[] = 'Enabled cache preloading';
				break;

			default:
				return array(
					'success' => false,
					'message' => __( 'Unknown recommendation ID.', 'performance-optimisation' ),
				);
		}

		// Save updated settings.
		$save_result = update_option( 'wppo_settings', $updated_settings );

		if ( $save_result ) {
			// Clear cache after applying changes.
			$this->clear_all_caches();

			// Log the changes.
			$this->log_recommendation_application( $recommendation_id, $applied_changes );

			return array(
				'success'  => true,
				'message'  => sprintf(
					__( 'Successfully applied recommendation. Changes: %s', 'performance-optimisation' ),
					implode( ', ', $applied_changes )
				),
				'settings' => $updated_settings,
				'changes'  => $applied_changes,
			);
		} else {
			return array(
				'success' => false,
				'message' => __( 'Failed to save settings changes.', 'performance-optimisation' ),
			);
		}
	}

	/**
	 * Trigger image optimization process.
	 *
	 * @return void
	 */
	private function trigger_image_optimization(): void {
		try {
			$cache_service    = new \PerformanceOptimisation\Services\CacheService();
			$settings_service = new \PerformanceOptimisation\Services\SettingsService();
			// Skip image service for now as it requires complex dependencies
			$cron_manager = new \PerformanceOptimisation\Services\CronService(
				$cache_service,
				null, // ImageService placeholder
				$settings_service
			);
			$cron_manager->run_image_conversion_tasks();

		} catch ( \Exception $e ) {
			error_log( 'Failed to trigger image optimization: ' . $e->getMessage() );
		}
	}

	/**
	 * Clear all caches after applying recommendations.
	 *
	 * @return void
	 */
	private function clear_all_caches(): void {
		try {
			if ( ! class_exists( 'PerformanceOptimise\Inc\Cache' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-cache.php';
			}

			\PerformanceOptimise\Inc\Cache::clear_cache();

		} catch ( \Exception $e ) {
			error_log( 'Failed to clear cache after recommendation application: ' . $e->getMessage() );
		}
	}

	/**
	 * Log recommendation application.
	 *
	 * @param string        $recommendation_id Recommendation ID.
	 * @param array<string> $changes Applied changes.
	 * @return void
	 */
	private function log_recommendation_application( string $recommendation_id, array $changes ): void {
		try {
			if ( ! class_exists( 'PerformanceOptimise\Inc\Log' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-log.php';
			}

			$message = sprintf(
				__( 'Applied automated recommendation "%1$s": %2$s', 'performance-optimisation' ),
				$recommendation_id,
				implode( ', ', $changes )
			);

			new \PerformanceOptimise\Inc\Log( $message );

		} catch ( \Exception $e ) {
			error_log( 'Failed to log recommendation application: ' . $e->getMessage() );
		}
	}

	/**
	 * Get recommendation implementation status.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response REST response object.
	 */
	public function get_implementation_status( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$settings = get_option( 'wppo_settings', array() );

			$implementation_status = array(
				'page_caching'       => ! empty( $settings['cache_settings']['enablePageCaching'] ),
				'css_minification'   => ! empty( $settings['file_optimisation']['minifyCSS'] ),
				'js_minification'    => ! empty( $settings['file_optimisation']['minifyJS'] ),
				'html_minification'  => ! empty( $settings['file_optimisation']['minifyHTML'] ),
				'lazy_loading'       => ! empty( $settings['image_optimisation']['lazyLoadImages'] ),
				'image_optimization' => ! empty( $settings['image_optimisation']['convertImg'] ),
				'css_combination'    => ! empty( $settings['file_optimisation']['combineCSS'] ),
				'js_combination'     => ! empty( $settings['file_optimisation']['combineJS'] ),
				'js_defer'           => ! empty( $settings['file_optimisation']['deferJS'] ),
				'cache_preloading'   => ! empty( $settings['preload_settings']['enablePreloadCache'] ),
			);

			$total_features            = count( $implementation_status );
			$implemented_features      = count( array_filter( $implementation_status ) );
			$implementation_percentage = $total_features > 0 ? ( $implemented_features / $total_features ) * 100 : 0;

			return $this->send_success_response(
				array(
					'status'  => $implementation_status,
					'summary' => array(
						'total_features'            => $total_features,
						'implemented_features'      => $implemented_features,
						'implementation_percentage' => round( $implementation_percentage, 1 ),
					),
				)
			);

		} catch ( \Exception $e ) {
			return $this->send_error_response(
				'status_failed',
				__( 'Failed to get implementation status.', 'performance-optimisation' ),
				500
			);
		}
	}

	/**
	 * Dismiss a recommendation.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response REST response object.
	 */
	public function dismiss_recommendation( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$recommendation_id = $request->get_param( 'recommendation_id' );
			$reason            = $request->get_param( 'reason' ) ?: 'user_dismissed';

			if ( empty( $recommendation_id ) ) {
				return $this->send_error_response(
					'missing_recommendation_id',
					__( 'Recommendation ID is required.', 'performance-optimisation' ),
					400
				);
			}

			// Store dismissed recommendations.
			$dismissed                       = get_option( 'wppo_dismissed_recommendations', array() );
			$dismissed[ $recommendation_id ] = array(
				'dismissed_at' => current_time( 'mysql' ),
				'reason'       => $reason,
			);
			update_option( 'wppo_dismissed_recommendations', $dismissed );

			return $this->send_success_response(
				array(
					'message' => __( 'Recommendation dismissed successfully.', 'performance-optimisation' ),
				)
			);

		} catch ( \Exception $e ) {
			return $this->send_error_response(
				'dismiss_failed',
				__( 'Failed to dismiss recommendation.', 'performance-optimisation' ),
				500
			);
		}
	}
}
