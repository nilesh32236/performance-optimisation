<?php
/**
 * Monitor Controller Class
 *
 * REST API endpoints for performance monitoring.
 *
 * @package PerformanceOptimisation\Core\API
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Core\API;

use PerformanceOptimisation\Monitor\PageSpeedService;
use PerformanceOptimisation\Monitor\AssetAnalyzer;
use PerformanceOptimisation\Monitor\SystemInfo;
use PerformanceOptimisation\Monitor\SuggestionsService;
use PerformanceOptimisation\Monitor\MetricsStorage;
use PerformanceOptimisation\Monitor\CronService;
use PerformanceOptimisation\Interfaces\ServiceContainerInterface;
use PerformanceOptimisation\Services\SettingsService;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MonitorController
 */
class MonitorController extends WP_REST_Controller {

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'performance-optimisation/v1';

	/**
	 * Rest base.
	 *
	 * @var string
	 */
	protected $rest_base = 'monitor';

	/**
	 * PageSpeed service.
	 *
	 * @var PageSpeedService
	 */
	private PageSpeedService $pagespeed_service;

	/**
	 * Asset analyzer.
	 *
	 * @var AssetAnalyzer
	 */
	private AssetAnalyzer $asset_analyzer;

	/**
	 * System info.
	 *
	 * @var SystemInfo
	 */
	private SystemInfo $system_info;

	/**
	 * Suggestions service.
	 *
	 * @var SuggestionsService
	 */
	private SuggestionsService $suggestions_service;

	/**
	 * Metrics storage.
	 *
	 * @var MetricsStorage
	 */
	private MetricsStorage $metrics_storage;

	/**
	 * Cron service.
	 *
	 * @var CronService
	 */
	private CronService $cron_service;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->pagespeed_service   = new PageSpeedService();
		$this->asset_analyzer      = new AssetAnalyzer();
		$this->system_info         = new SystemInfo();
		$this->suggestions_service = new SuggestionsService();
		$this->metrics_storage     = new MetricsStorage();
		$this->cron_service        = new CronService();
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/pagespeed',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_pagespeed_data' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array(
						'url'     => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'esc_url_raw',
						),
						'refresh' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/assets',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_assets_data' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array(
						'url'     => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'esc_url_raw',
						),
						'refresh' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/system-info',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_system_info' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/overview',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_overview' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/suggestions',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_suggestions' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array(
						'url'     => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'esc_url_raw',
						),
						'refresh' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/apply-fix',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'apply_fix' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array(
						'action' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/chart-data',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_chart_data' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array(
						'device' => array(
							'type'              => 'string',
							'default'           => 'mobile',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'since'  => array(
							'type'              => 'string',
							'default'           => '-7 days',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/monitoring-status',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_monitoring_status' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/run-check',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'run_manual_check' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);
	}

	/**
	 * Check admin permission.
	 *
	 * @return bool|WP_Error
	 */
	public function check_admin_permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this resource.', 'performance-optimisation' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Get PageSpeed data.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_pagespeed_data( WP_REST_Request $request ): WP_REST_Response {
		$url       = $request->get_param( 'url' );
		$url       = ! empty( $url ) ? $url : home_url( '/' );
		$refresh   = $request->get_param( 'refresh' );
		$use_cache = ! $refresh;

		$data = $this->pagespeed_service->get_full_pagespeed_data( $url, $use_cache );

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
			),
			200
		);
	}

	/**
	 * Get assets analysis data.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_assets_data( WP_REST_Request $request ): WP_REST_Response {
		$url       = $request->get_param( 'url' );
		$url       = ! empty( $url ) ? $url : home_url( '/' );
		$refresh   = $request->get_param( 'refresh' );
		$use_cache = ! $refresh;

		$data = $this->asset_analyzer->analyze( $url, $use_cache );

		return new WP_REST_Response(
			array(
				'success' => $data['success'] ?? false,
				'data'    => $data,
			),
			200
		);
	}

	/**
	 * Get system information.
	 *
	 * @return WP_REST_Response
	 */
	public function get_system_info(): WP_REST_Response {
		$data = $this->system_info->get_all();

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
			),
			200
		);
	}

	/**
	 * Get monitoring overview (combined data).
	 *
	 * @return WP_REST_Response
	 */
	public function get_overview(): WP_REST_Response {
		$home_url = home_url( '/' );

		$pagespeed = $this->pagespeed_service->get_pagespeed_data( $home_url, 'mobile', true );
		$assets    = $this->asset_analyzer->analyze( $home_url, true );

		$data = array(
			'url'       => $home_url,
			'timestamp' => current_time( 'mysql' ),
			'scores'    => $pagespeed['scores'] ?? array(),
			'metrics'   => $pagespeed['metrics'] ?? array(),
			'assets'    => $assets['summary'] ?? array(),
		);

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
			),
			200
		);
	}

	/**
	 * Get performance improvement suggestions.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_suggestions( WP_REST_Request $request ): WP_REST_Response {
		$url       = $request->get_param( 'url' );
		$url       = ! empty( $url ) ? $url : home_url( '/' );
		$refresh   = $request->get_param( 'refresh' );
		$use_cache = ! $refresh;

		// Fetch PageSpeed data.
		$pagespeed_data = $this->pagespeed_service->get_full_pagespeed_data( $url, $use_cache );

		// Fetch asset data.
		$asset_data = $this->asset_analyzer->analyze( $url, $use_cache );

		// Get current settings.
		$settings = array();
		if ( class_exists( 'PerformanceOptimisation\Services\SettingsService' ) && $this->container ) {
			try {
				if ( $this->container->has( 'PerformanceOptimisation\Services\SettingsService' ) ) {
					$settings_service = $this->container->get( 'PerformanceOptimisation\Services\SettingsService' );
					$settings         = $settings_service->get_settings();
				} else {
					$settings_service = new SettingsService( $this->container );
					$settings         = $settings_service->get_settings();
				}
			} catch ( \Exception $e ) {
				// Fallback to empty settings if service fails
			}
		}

		// Generate suggestions.
		try {
			$suggestions = $this->suggestions_service->get_all_suggestions(
				$pagespeed_data,
				$asset_data,
				$settings
			);

			// Get priority counts.
			$counts = $this->suggestions_service->get_priority_counts( $suggestions );

			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => array(
						'suggestions' => $suggestions,
						'counts'      => $counts,
						'url'         => $url,
						'timestamp'   => current_time( 'mysql' ),
					),
				),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Error generating suggestions: ' . $e->getMessage(),
					'data'    => array(),
				),
				500
			);
		}
	}

	/**
	 * Apply a quick fix by enabling a setting.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function apply_fix( WP_REST_Request $request ): WP_REST_Response {
		$action = $request->get_param( 'action' );

		// Map action to setting path.
		$action_map = array(
			'caching'     => array( 'cache_settings', 'page_cache_enabled', true ),
			'lazy_load'   => array( 'image_optimization', 'lazy_load_enabled', true ),
			'minify_css'  => array( 'minification', 'minify_css', true ),
			'minify_js'   => array( 'minification', 'minify_js', true ),
			'minify_html' => array( 'minification', 'minify_html', true ),
			'defer_js'    => array( 'advanced', 'defer_js', true ),
			'delay_js'    => array( 'advanced', 'delay_js', true ),
			'images'      => array( 'image_optimization', 'webp_conversion', true ),
		);

		if ( ! isset( $action_map[ $action ] ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Unknown action.', 'performance-optimisation' ),
				),
				400
			);
		}

		list( $group, $key, $value ) = $action_map[ $action ];

		// Get current settings.
		$settings = get_option( 'performance_optimisation_settings', array() );

		// Ensure group exists.
		if ( ! isset( $settings[ $group ] ) ) {
			$settings[ $group ] = array();
		}

		// Update the setting.
		$settings[ $group ][ $key ] = $value;

		// Save settings.
		update_option( 'performance_optimisation_settings', $settings );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => sprintf(
					/* translators: %s: action name */
					__( '%s has been enabled.', 'performance-optimisation' ),
					ucfirst( str_replace( '_', ' ', $action ) )
				),
				'action'  => $action,
			),
			200
		);
	}

	/**
	 * Get chart data for historical metrics.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_chart_data( WP_REST_Request $request ): WP_REST_Response {
		$device = $request->get_param( 'device' );
		$device = in_array( $device, array( 'mobile', 'desktop' ), true ) ? $device : 'mobile';
		$since  = $request->get_param( 'since' );

		$data = $this->metrics_storage->get_chart_data( '', $device, $since );

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
			),
			200
		);
	}

	/**
	 * Get monitoring status.
	 *
	 * @return WP_REST_Response
	 */
	public function get_monitoring_status(): WP_REST_Response {
		$status = $this->cron_service->get_status();

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $status,
			),
			200
		);
	}

	/**
	 * Run a manual performance check.
	 *
	 * @return WP_REST_Response
	 */
	public function run_manual_check(): WP_REST_Response {
		// Ensure table exists.
		$this->metrics_storage->create_table();

		// Run the check.
		$results = $this->cron_service->run_performance_check();

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Performance check completed.', 'performance-optimisation' ),
				'data'    => $results,
			),
			200
		);
	}
}
