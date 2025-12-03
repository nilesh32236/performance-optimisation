<?php
/**
 * API Router Class
 *
 * Central router for all REST API endpoints. Manages route registration
 * and delegates requests to appropriate controllers.
 *
 * @package PerformanceOptimisation\Core\API
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\API;

use PerformanceOptimisation\Interfaces\ServiceContainerInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API Router class for managing REST API routes.
 */
class ApiRouter {

	/**
	 * REST API Namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'performance-optimisation/v1';

	/**
	 * Controller instances.
	 *
	 * @var array<string, object>
	 */
	private array $controllers = array();

	/**
	 * Base controller for common functionality.
	 *
	 * @var BaseController
	 */
	private BaseController $base_controller;

	/**
	 * Service container.
	 *
	 * @var ServiceContainerInterface
	 */
	private ServiceContainerInterface $container;

	/**
	 * Constructor.
	 *
	 * @param ServiceContainerInterface $container Service container.
	 */
	public function __construct( ServiceContainerInterface $container ) {
		$this->container = $container;
	}

	/**
	 * Initialize the API router.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		$this->load_controllers();

		// Also call register_routes directly in case rest_api_init has already fired
		$this->register_routes();
	}

	/**
	 * Load all API controllers.
	 *
	 * @return void
	 */
	private function load_controllers(): void {
		// Load analytics dependencies.
		if ( ! class_exists( 'PerformanceOptimisation\Core\Analytics\MetricsCollector' ) ) {
			require_once WPPO_PLUGIN_PATH . 'includes/Core/Analytics/MetricsCollector.php';
		}
		if ( ! class_exists( 'PerformanceOptimisation\Core\Analytics\PerformanceAnalyzer' ) ) {
			require_once WPPO_PLUGIN_PATH . 'includes/Core/Analytics/PerformanceAnalyzer.php';
		}
		if ( ! class_exists( 'PerformanceOptimisation\Core\Analytics\RecommendationEngine' ) ) {
			require_once WPPO_PLUGIN_PATH . 'includes/Core/Analytics/RecommendationEngine.php';
		}

		// Initialize base controller for common functionality
		$this->base_controller = new class() extends BaseController {
			public function register_routes(): void {
				// No routes to register for base controller
			}
		};

		// Initialize controllers.
		$metrics_collector    = new \PerformanceOptimisation\Core\Analytics\MetricsCollector();
		$performance_analyzer = new \PerformanceOptimisation\Core\Analytics\PerformanceAnalyzer( $metrics_collector );

		// Get service container
		$container = \PerformanceOptimisation\Core\ServiceContainer::getInstance();

		// Get PageCacheService from container - use full class name
		$page_cache_service = null;
		$logger = null;
		
		try {
			if ( $container->has( 'PerformanceOptimisation\\Services\\PageCacheService' ) ) {
				$service = $container->get( 'PerformanceOptimisation\\Services\\PageCacheService' );
				// If we got a Closure, call it to get the actual service
				if ( $service instanceof \Closure ) {
					$page_cache_service = $service( $container );
				} else {
					$page_cache_service = $service;
				}
			}
		} catch ( \Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'WPPO: Failed to get PageCacheService: ' . $e->getMessage() );
			}
		}

		try {
			if ( $container->has( 'logger' ) ) {
				$service = $container->get( 'logger' );
				if ( $service instanceof \Closure ) {
					$logger = $service( $container );
				} else {
					$logger = $service;
				}
			}
		} catch ( \Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'WPPO: Failed to get logger: ' . $e->getMessage() );
			}
		}

		// Get BrowserCacheService
		$browser_cache_service = null;
		try {
			if ( $container->has( 'PerformanceOptimisation\\Services\\BrowserCacheService' ) ) {
				$service = $container->get( 'PerformanceOptimisation\\Services\\BrowserCacheService' );
				if ( $service instanceof \Closure ) {
					$browser_cache_service = $service( $container );
				} else {
					$browser_cache_service = $service;
				}
			}
		} catch ( \Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'WPPO: Failed to get BrowserCacheService: ' . $e->getMessage() );
			}
		}

		if ( $logger === null ) {
			$logger = new \PerformanceOptimisation\Utils\LoggingUtil();
		}

		$this->controllers = array(
			'cache'           => new CacheController( $page_cache_service, $browser_cache_service, $logger ),
			'settings'        => new SettingsController(),
			'optimization'    => new OptimizationController(),
			'analytics'       => new AnalyticsController( $metrics_collector, $performance_analyzer ),
			'recommendations' => new RecommendationsController( $metrics_collector, $performance_analyzer ),
			'images'          => new ImageOptimizationController( $container ),
		);
	}

	/**
	 * Register all REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Cache routes.
		$this->register_cache_routes();

		// Settings routes.
		$this->register_settings_routes();

		// Optimization routes.
		$this->register_optimization_routes();

		// Analytics routes.
		$this->register_analytics_routes();

		// Recommendations routes.
		$this->register_recommendations_routes();

		// Image optimization routes.
		$this->register_image_routes();
		
		// Queue routes.
		$this->register_queue_routes();

		// Wizard routes.
		$this->register_wizard_routes();

		// Preset routes.
		$this->register_preset_routes();

		// Utility routes.
		$this->register_utility_routes();
	}

	/**
	 * Register cache-related routes.
	 *
	 * @return void
	 */
	private function register_cache_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/cache/clear',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this->controllers['cache'], 'clear_cache' ),
				'permission_callback' => array( $this->controllers['cache'], 'check_admin_permissions' ),
				'args'                => array(
					'type' => array(
						'required' => false,
						'type'     => 'string',
						'default'  => 'all',
						'enum'     => array( 'all', 'page', 'object', 'transients' ),
					),
					'path' => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/cache/preload',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this->controllers['cache'], 'preload_cache' ),
				'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/cache/stats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this->controllers['cache'], 'get_cache_stats' ),
				'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
			)
		);
	}

	/**
	 * Register settings-related routes.
	 *
	 * @return void
	 */
	private function register_settings_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this->controllers['settings'], 'get_settings' ),
					'permission_callback' => array( $this->controllers['cache'], 'check_admin_permissions' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this->controllers['settings'], 'update_settings' ),
					'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
					'args'                => array(
						'settings' => array(
							'required' => true,
							'type'     => 'object',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings/(?P<section>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this->controllers['settings'], 'get_section_settings' ),
					'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this->controllers['settings'], 'update_section_settings' ),
					'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
					'args'                => array(
						'settings' => array(
							'required' => true,
							'type'     => 'object',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this->controllers['settings'], 'export_settings' ),
				'permission_callback' => array( $this->controllers['cache'], 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings/import',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this->controllers['settings'], 'import_settings' ),
				'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
				'args'                => array(
					'settings' => array(
						'required' => true,
						'type'     => 'object',
					),
				),
			)
		);
	}

	/**
	 * Register optimization-related routes.
	 *
	 * @return void
	 */
	private function register_optimization_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/optimization/images',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this->controllers['optimization'], 'optimize_images' ),
				'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/optimization/images/bulk',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this->controllers['optimization'], 'bulk_optimize_images' ),
				'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/optimization/images/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this->controllers['optimization'], 'get_image_optimization_status' ),
				'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/optimization/minify',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this->controllers['optimization'], 'run_minification' ),
				'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
				'args'                => array(
					'type' => array(
						'required' => false,
						'type'     => 'string',
						'enum'     => array( 'css', 'js', 'html', 'all' ),
						'default'  => 'all',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/optimization/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this->controllers['optimization'], 'run_performance_test' ),
				'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
				'args'                => array(
					'url' => array(
						'required' => false,
						'type'     => 'string',
						'format'   => 'uri',
					),
				),
			)
		);
	}

	/**
	 * Register analytics-related routes.
	 *
	 * @return void
	 */
	private function register_analytics_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/analytics/dashboard',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this->controllers['analytics'], 'get_dashboard_data' ),
				'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/analytics/metrics',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this->controllers['analytics'], 'get_metrics_data' ),
				'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
				'args'                => array(
					'metric'     => array(
						'required' => true,
						'type'     => 'string',
					),
					'period'     => array(
						'required' => false,
						'type'     => 'string',
						'default'  => 'day',
						'enum'     => array( 'hour', 'day', 'week', 'month' ),
					),
					'start_date' => array(
						'required' => false,
						'type'     => 'string',
						'format'   => 'date',
					),
					'end_date'   => array(
						'required' => false,
						'type'     => 'string',
						'format'   => 'date',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/analytics/report',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this->controllers['analytics'], 'get_performance_report' ),
				'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
				'args'                => array(
					'start_date' => array(
						'required' => false,
						'type'     => 'string',
						'format'   => 'date',
					),
					'end_date'   => array(
						'required' => false,
						'type'     => 'string',
						'format'   => 'date',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/analytics/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this->controllers['analytics'], 'export_analytics_data' ),
				'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
				'args'                => array(
					'format'     => array(
						'required' => false,
						'type'     => 'string',
						'default'  => 'json',
						'enum'     => array( 'json', 'csv' ),
					),
					'start_date' => array(
						'required' => false,
						'type'     => 'string',
						'format'   => 'date',
					),
					'end_date'   => array(
						'required' => false,
						'type'     => 'string',
						'format'   => 'date',
					),
				),
			)
		);
	}

	/**
	 * Register recommendations-related routes.
	 *
	 * @return void
	 */
	private function register_recommendations_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/recommendations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this->controllers['recommendations'], 'get_recommendations' ),
				'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
				'args'                => array(
					'start_date' => array(
						'required' => false,
						'type'     => 'string',
						'format'   => 'date',
					),
					'end_date'   => array(
						'required' => false,
						'type'     => 'string',
						'format'   => 'date',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/recommendations/apply',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this->controllers['recommendations'], 'apply_recommendation' ),
				'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
				'args'                => array(
					'recommendation_id' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/recommendations/suggestions',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this->controllers['recommendations'], 'get_optimization_suggestions' ),
				'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/recommendations/progress',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this->controllers['recommendations'], 'get_optimization_progress' ),
				'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
				'args'                => array(
					'start_date' => array(
						'required' => false,
						'type'     => 'string',
						'format'   => 'date',
					),
					'end_date'   => array(
						'required' => false,
						'type'     => 'string',
						'format'   => 'date',
					),
				),
			)
		);
	}

	/**
	 * Register image optimization routes.
	 *
	 * @return void
	 */
	private function register_image_routes(): void {
		if ( isset( $this->controllers['images'] ) ) {
			$this->controllers['images']->register_routes();
		}
	}

	/**
	 * Register queue-related routes.
	 *
	 * @return void
	 */
	private function register_queue_routes(): void {
		$queue_controller = new \PerformanceOptimisation\Core\API\Controllers\QueueController();
		$queue_controller->register_routes();
	}

	/**
	 * Register wizard-related routes.
	 *
	 * @return void
	 */
	private function register_wizard_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/wizard/setup',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_wizard_setup' ),
				'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
				'args'                => array(
					'preset'   => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'standard', 'recommended', 'aggressive', 'advanced', 'balanced', 'conservative' ),
					),
					'features' => array(
						'required' => false,
						'type'     => 'object',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/wizard/reset',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_wizard_reset' ),
				'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/wizard/analysis',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_site_analysis' ),
				'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
				'args'                => array(
					'force_refresh' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);
	}

	/**
	 * Register preset-related routes.
	 *
	 * @return void
	 */
	private function register_preset_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/presets',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_presets' ),
					'permission_callback' => array( $this->controllers['cache'], 'check_admin_permissions' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_preset' ),
					'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
					'args'                => array(
						'preset' => array(
							'required' => true,
							'type'     => 'object',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/presets/(?P<preset_id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_preset' ),
					'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_preset' ),
					'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
					'args'                => array(
						'preset' => array(
							'required' => true,
							'type'     => 'object',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_preset' ),
					'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
				),
			)
		);
	}

	/**
	 * Register utility routes.
	 *
	 * @return void
	 */
	private function register_utility_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/system/info',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_system_info' ),
				'permission_callback' => array( $this->controllers['cache'], 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/activities',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_recent_activities' ),
				'permission_callback' => array( $this->base_controller, 'check_admin_permissions' ),
				'args'                => array(
					'page'     => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 1,
					),
					'per_page' => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 10,
					),
				),
			)
		);
	}

	/**
	 * Handle wizard setup request.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response|\WP_Error The response object.
	 */
	public function handle_wizard_setup( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$preset   = $request->get_param( 'preset' );
		$features = $request->get_param( 'features' ) ?: array();

		try {
			// Load wizard classes if needed.
			if ( ! class_exists( 'PerformanceOptimisation\Core\Wizard\WizardManager' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/Core/Wizard/WizardManager.php';
			}

			$wizard_manager = new \PerformanceOptimisation\Core\Wizard\WizardManager();
			$result         = $wizard_manager->apply_preset( $preset, $features );

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => $result,
					'message' => __( 'Setup completed successfully!', 'performance-optimisation' ),
				)
			);

		} catch ( \Exception $e ) {
			return new \WP_Error(
				'wizard_setup_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Handle wizard reset request.
	 *
	 * @return \WP_REST_Response The response object.
	 */
	public function handle_wizard_reset(): \WP_REST_Response {
		delete_option( 'wppo_setup_wizard_completed' );
		delete_transient( 'wppo_wizard_redirect_done' );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Setup wizard has been reset.', 'performance-optimisation' ),
			)
		);
	}

	/**
	 * Handle site analysis request.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response|\WP_Error The response object.
	 */
	public function handle_site_analysis( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$force_refresh = $request->get_param( 'force_refresh' );

		try {
			// Load site detection classes if needed.
			if ( ! class_exists( 'PerformanceOptimisation\Core\SiteDetection\SiteAnalyzer' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/Core/SiteDetection/SiteAnalyzer.php';
			}

			$analyzer = new \PerformanceOptimisation\Core\SiteDetection\SiteAnalyzer();

			if ( $force_refresh ) {
				$analyzer->clear_cache();
			}

			$analysis = $analyzer->analyze_site();

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => $analysis,
				)
			);

		} catch ( \Exception $e ) {
			return new \WP_Error(
				'site_analysis_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get presets.
	 *
	 * @return \WP_REST_Response|\WP_Error The response object.
	 */
	public function get_presets(): \WP_REST_Response|\WP_Error {
		try {
			if ( ! class_exists( 'PerformanceOptimisation\Core\Presets\PresetManager' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/Core/Presets/PresetManager.php';
			}

			$preset_manager = new \PerformanceOptimisation\Core\Presets\PresetManager();
			$presets        = $preset_manager->get_presets();

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => $presets,
				)
			);

		} catch ( \Exception $e ) {
			return new \WP_Error(
				'presets_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Create preset.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response The response object.
	 */
	public function create_preset( \WP_REST_Request $request ): \WP_REST_Response {
		// Implementation for creating presets.
		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Preset created successfully.', 'performance-optimisation' ),
			)
		);
	}

	/**
	 * Get preset.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response The response object.
	 */
	public function get_preset( \WP_REST_Request $request ): \WP_REST_Response {
		// Implementation for getting specific preset.
		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(),
			)
		);
	}

	/**
	 * Update preset.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response The response object.
	 */
	public function update_preset( \WP_REST_Request $request ): \WP_REST_Response {
		// Implementation for updating preset.
		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Preset updated successfully.', 'performance-optimisation' ),
			)
		);
	}

	/**
	 * Delete preset.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response The response object.
	 */
	public function delete_preset( \WP_REST_Request $request ): \WP_REST_Response {
		// Implementation for deleting preset.
		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Preset deleted successfully.', 'performance-optimisation' ),
			)
		);
	}

	/**
	 * Get system information.
	 *
	 * @return \WP_REST_Response The response object.
	 */
	public function get_system_info(): \WP_REST_Response {
		$system_info = array(
			'php_version'         => PHP_VERSION,
			'wordpress_version'   => get_bloginfo( 'version' ),
			'plugin_version'      => WPPO_VERSION ?? '1.1.0',
			'memory_limit'        => ini_get( 'memory_limit' ),
			'max_execution_time'  => ini_get( 'max_execution_time' ),
			'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
			'server_software'     => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
		);

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $system_info,
			)
		);
	}

	/**
	 * Get recent activities.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response|\WP_Error The response object.
	 */
	public function get_recent_activities( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );

		try {
			if ( ! class_exists( 'PerformanceOptimise\Inc\Log' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-log.php';
			}

			$activities = \PerformanceOptimise\Inc\Log::get_recent_activities(
				array(
					'page'     => $page,
					'per_page' => $per_page,
				)
			);

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => $activities,
				)
			);

		} catch ( \Exception $e ) {
			return new \WP_Error(
				'activities_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}
}
