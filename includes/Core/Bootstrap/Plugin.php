<?php
/**
 * Main Plugin Bootstrap Class
 *
 * @package PerformanceOptimisation\Core\Bootstrap
 * @since   2.0.0
 */

namespace PerformanceOptimisation\Core\Bootstrap;

use PerformanceOptimisation\Core\Cache\AdvancedCacheHandler;
use PerformanceOptimisation\Core\ServiceContainer;
use PerformanceOptimisation\Interfaces\ServiceContainerInterface;
use PerformanceOptimisation\Core\Config\ConfigManager;
use PerformanceOptimisation\Services\CronService;
use PerformanceOptimisation\Utils\LoggingUtil;
use PerformanceOptimisation\Utils\FileSystemUtil;
use PerformanceOptimisation\Services\CacheService;


/**
 * Plugin Class
 *
 * Main plugin bootstrap class that handles initialization and lifecycle.
 *
 * @since 2.0.0
 */
class Plugin implements PluginInterface {

	/**
	 * Plugin instance.
	 *
	 * @since 2.0.0
	 * @var Plugin|null
	 */
	private static ?Plugin $_instance = null;

	/**
	 * Service container.
	 *
	 * @since 2.0.0
	 * @var ServiceContainerInterface
	 */
	private ServiceContainerInterface $_container;

	/**
	 * Plugin file path.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	private string $_plugin_file;

	/**
	 * Plugin version.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	private string $_version;

	/**
	 * Initialization status.
	 *
	 * @since 2.0.0
	 * @var bool
	 */
	private bool $_initialized = false;

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 *
	 * @param string $plugin_file Plugin file path.
	 * @param string $version     Plugin version.
	 */
	private function __construct( string $plugin_file, string $version ) {
		$this->_plugin_file = $plugin_file;
		$this->_version     = $version;
		$this->_container   = ServiceContainer::getInstance();
	}

	/**
	 * Get plugin instance.
	 *
	 * @since 2.0.0
	 *
	 * @param string $plugin_file Plugin file path.
	 * @param string $version     Plugin version.
	 * @return Plugin
	 */
	public static function getInstance( string $plugin_file = '', string $version = '' ): Plugin {
		if ( null === self::$_instance ) {
			self::$_instance = new self( $plugin_file, $version );
		}

		return self::$_instance;
	}

	/**
	 * Initialize the plugin.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function initialize(): void {
		if ( $this->_initialized ) {
			return;
		}

		$start_time = microtime( true );

		// Register core services.
		$this->registerCoreServices();

		// Load plugin dependencies.
		$this->loadDependencies();

		// Setup WordPress hooks.
		$this->setupHooks();

		// Initialize features.
		$this->initializeFeatures();

		$this->_initialized = true;

		$elapsed = microtime( true ) - $start_time;
		error_log( sprintf( 'WPPO: Plugin initialized in %.2f ms', $elapsed * 1000 ) );

		/**
		 * Fires after plugin initialization.
	 *
		 * @since 2.0.0
	 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'wppo_plugin_initialized', $this );
	}

	/**
	 * Activate the plugin.
	 *
	 * @since 2.0.0
	 *
	 * @throws \Exception If activation fails.
	 * @return void
	 */
	public function activate(): void {
		$activation_steps = array();

		try {
			// Check system requirements.
			$this->checkSystemRequirements();
			$activation_steps[] = 'system_check';

			// Load dependencies first
			$this->loadDependencies();
			$activation_steps[] = 'dependencies';

			// Register services for activation
			$this->registerCoreServices();
			$activation_steps[] = 'services';

			// Setup advanced caching
			AdvancedCacheHandler::create();
			$activation_steps[] = 'advanced_cache';

			$this->add_wp_cache_constant();
			$activation_steps[] = 'wp_cache_constant';

			// Create database tables.
			$this->create_activity_log_table();
			$activation_steps[] = 'activity_log_table';

			$this->createDatabaseTables();
			$activation_steps[] = 'database_tables';

			// Set default options.
			$this->setDefaultOptions();
			$activation_steps[] = 'default_options';

			// Schedule cron jobs
			$this->scheduleCronEvents();
			$activation_steps[] = 'cron_events';

			// Create cache directories.
			$this->createCacheDirectories();
			$activation_steps[] = 'cache_directories';

			flush_rewrite_rules();

			// Set activation flag for setup wizard
			update_option( 'wppo_show_setup_wizard', true );

			LoggingUtil::info(
				__( 'Plugin activated successfully', 'performance-optimisation' ),
				array(
					'version'     => $this->_version,
					'php_version' => PHP_VERSION,
					'wp_version'  => get_bloginfo( 'version' ),
				)
			);

			// Fire activation complete hook for testing.
			do_action( 'wppo_activation_complete', $this, $activation_steps );

		} catch ( \Exception $e ) {
			LoggingUtil::error(
				'Plugin activation failed: ' . $e->getMessage(),
				array(
					'completed_steps' => $activation_steps,
					'error_trace'     => $e->getTraceAsString(),
				)
			);

			// Rollback completed steps.
			$this->rollbackActivation( $activation_steps );

			throw $e; // Re-throw to prevent activation
		}
	}

	/**
	 * Rollback activation steps on failure.
	 *
	 * @since 2.0.0
	 *
	 * @param array $completed_steps Array of completed activation steps.
	 * @return void
	 */
	private function rollbackActivation( array $completed_steps ): void {
		try {
			foreach ( array_reverse( $completed_steps ) as $step ) {
				switch ( $step ) {
					case 'advanced_cache':
						AdvancedCacheHandler::remove();
						break;
					case 'wp_cache_constant':
						$this->remove_wp_cache_constant();
						break;
					case 'cron_events':
						wp_clear_scheduled_hook( 'wppo_cleanup_cache' );
						wp_clear_scheduled_hook( 'wppo_optimize_images' );
						break;
					case 'cache_directories':
						$cache_dir = WP_CONTENT_DIR . '/cache/wppo/';
						if ( is_dir( $cache_dir ) ) {
							$this->removeDirectory( $cache_dir );
						}
						break;
				}
			}
			LoggingUtil::info( 'Activation rollback completed' );
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Rollback failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Deactivate the plugin.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function deactivate(): void {
		try {
			// Clear WordPress cron jobs directly
			wp_clear_scheduled_hook( 'wppo_page_cron_hook' );
			wp_clear_scheduled_hook( 'wppo_generate_static_page' );
			wp_clear_scheduled_hook( 'wppo_image_optimization_cron' );

			// Remove advanced caching files with validation
			$advanced_cache_file = WP_CONTENT_DIR . '/advanced-cache.php';
			if ( file_exists( $advanced_cache_file ) ) {
				// Validate it's our file before deletion
				$file_content = file_get_contents( $advanced_cache_file );
				if ( strpos( $file_content, 'Performance Optimisation Plugin' ) !== false ) {
					if ( ! unlink( $advanced_cache_file ) ) {
						LoggingUtil::warning( 'Failed to remove advanced-cache.php file' );
					}
				}
			}

			// Remove cache directory with validation
			$cache_dir = WP_CONTENT_DIR . '/cache/wppo/';
			if ( is_dir( $cache_dir ) ) {
				// Validate path is within wp-content for security
				$real_cache_dir   = realpath( $cache_dir );
				$real_content_dir = realpath( WP_CONTENT_DIR );
				if ( $real_cache_dir && $real_content_dir && strpos( $real_cache_dir, $real_content_dir ) === 0 ) {
					$this->removeDirectory( $cache_dir );
				} else {
					LoggingUtil::warning( 'Cache directory path validation failed' );
				}
			}

			flush_rewrite_rules();

			LoggingUtil::info(
				__( 'Plugin deactivated successfully', 'performance-optimisation' ),
				array(
					'version' => $this->_version,
				)
			);

		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Plugin deactivation failed: ' . $e->getMessage() );
			// Don't throw on deactivation to allow WordPress to complete the process
		}
	}

	/**
	 * Get plugin version.
	 *
	 * @since 2.0.0
	 *
	 * @return string Plugin version.
	 */
	public function getVersion(): string {
		return $this->_version;
	}

	/**
	 * Get plugin path.
	 *
	 * @since 2.0.0
	 *
	 * @return string Plugin path.
	 */
	public function getPath(): string {
		return plugin_dir_path( $this->_plugin_file );
	}

	/**
	 * Get plugin URL.
	 *
	 * @since 2.0.0
	 *
	 * @return string Plugin URL.
	 */
	public function getUrl(): string {
		return plugin_dir_url( $this->_plugin_file );
	}

	/**
	 * Check if plugin is initialized.
	 *
	 * @since 2.0.0
	 *
	 * @return bool True if initialized, false otherwise.
	 */
	public function isInitialized(): bool {
		return $this->_initialized;
	}

	/**
	 * Get service container.
	 *
	 * @since 2.0.0
	 *
	 * @return ServiceContainerInterface Service container.
	 */
	public function getContainer(): ServiceContainerInterface {
		return $this->_container;
	}

	/**
	 * Register core services using the modern service container.
	 *
	 * @since 2.0.0
	 *
	 * @throws \Exception If service registration fails.
	 * @return void
	 */
	private function registerCoreServices(): void {
		try {
			// Register container itself.
			$this->_container->singleton( ServiceContainerInterface::class, $this->_container );

			// Register plugin instance
			$this->_container->singleton( PluginInterface::class, $this );
			$this->_container->singleton( self::class, $this );

			// Register configuration manager with error handling
			$this->_container->singleton(
				ConfigManager::class,
				function ( ServiceContainerInterface $container ) {
					try {
						return new ConfigManager();
					} catch ( \Exception $e ) {
						LoggingUtil::error( 'Failed to create ConfigManager: ' . $e->getMessage() );
						throw $e;
					}
				}
			);

			// Use the modern service registration system.
			$this->_container->registerCoreServices();

			// Register plugin-specific services.
			$this->registerPluginServices();

			LoggingUtil::info( 'Core services registered', $this->_container->getStats() );
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Service registration failed: ' . $e->getMessage() );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \Exception( 'Failed to register core services: ' . $e->getMessage() );
		}
	}

	/**
	 * Register plugin-specific services.
	 *
	 * @return void
	 */
	private function registerPluginServices(): void {
		// Register API controllers
		$this->_container->singleton(
			'PerformanceOptimisation\\Core\\API\\RestController',
			function ( ServiceContainerInterface $container ) {
				return new \PerformanceOptimisation\Core\API\RestController( $container );
			}
		);

		$this->_container->singleton(
			'PerformanceOptimisation\\Core\\API\\ApiRouter',
			function ( ServiceContainerInterface $container ) {
				return new \PerformanceOptimisation\Core\API\ApiRouter( $container );
			}
		);

		// Register Core classes
		$this->_container->singleton( 'PerformanceOptimisation\\Core\\Config\\ConfigManager', ConfigManager::class );

		// Register aliases for easy access
		$this->_container->alias( 'plugin', self::class );
		$this->_container->alias( 'config', ConfigManager::class );
		$this->_container->alias( 'rest_controller', 'PerformanceOptimisation\\Core\\API\\RestController' );
		$this->_container->alias( 'api_router', 'PerformanceOptimisation\\Core\\API\\ApiRouter' );
	}

	/**
	 * Load plugin dependencies.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function loadDependencies(): void {
		// Load Composer autoloader if available.
		$autoloader = $this->getPath() . 'vendor/autoload.php';
		if ( file_exists( $autoloader ) ) {
			require_once $autoloader;
		} else {
			LoggingUtil::warning( 'Composer autoloader not found. Some features may not work properly.' );
		}

		$this->load_plugin_files();
	}

	/**
	 * Load required plugin files.
	 *
	 * @since 2.0.0
	 *
	 * @throws \Exception If required files are missing.
	 * @return void
	 */
	private function load_plugin_files(): void {
		$required_files = array(
			'includes/Utils/FileSystemUtil.php',
			'includes/Utils/LoggingUtil.php',
			'includes/Interfaces/OptimizerInterface.php',
			'includes/Interfaces/SettingsServiceInterface.php',
			'includes/Interfaces/ImageServiceInterface.php',
			'includes/Interfaces/CacheServiceInterface.php',
			'includes/Interfaces/OptimizationServiceInterface.php',
			'includes/Interfaces/ImageProcessorInterface.php',
			'includes/Core/Config/ConfigInterface.php',
			'includes/Core/Config/ConfigManager.php',
			'includes/Core/Cache/CacheDropin.php',
			'includes/Services/CacheService.php',
			'includes/Services/OptimizationService.php',
			'includes/Services/ImageService.php',
			'includes/Services/SettingsService.php',
			'includes/Admin/Admin.php',
			'includes/Admin/Metabox.php',
			'includes/Frontend/Frontend.php',
			'includes/Core/Cache/AdvancedCacheHandler.php',
			'includes/Core/API/RestController.php',
			'includes/Optimizers/CssOptimizer.php',
			'includes/Optimizers/JsOptimizer.php',
			'includes/Optimizers/HtmlOptimizer.php',
			'includes/Optimizers/ImageProcessor.php',
			'includes/Utils/ConversionQueue.php',
			'includes/Utils/ValidationUtil.php',
		);

		$missing_files = array();
		foreach ( $required_files as $file ) {
			$file_path = $this->getPath() . $file;
			if ( file_exists( $file_path ) ) {
				require_once $file_path;
			} else {
				$missing_files[] = $file;
			}
		}

		if ( ! empty( $missing_files ) ) {
			LoggingUtil::error( 'Missing required files: ' . implode( ', ', $missing_files ) );
			throw new \Exception( 'Required plugin files are missing. Please reinstall the plugin.' );
		}
	}

	/**
	 * Setup WordPress hooks.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function setupHooks(): void {
		try {
			// Setup admin hooks.
			if ( is_admin() ) {
				try {
					// Directly instantiate Admin class to avoid service container issues
					$admin = new \PerformanceOptimisation\Admin\Admin( $this->_container );
					$admin->setup_hooks();
				} catch ( \Exception $e ) {
					LoggingUtil::error( 'Failed to setup admin hooks: ' . $e->getMessage() );
				}
			}

			// Frontend and admin components are initialized by their respective service providers
			// No need to manually initialize them here

			// REST API hooks
			add_action( 'rest_api_init', array( $this, 'initRestApi' ) );

			// Internationalization
			add_action( 'init', array( $this, 'loadTextdomain' ) );

			// Plugin lifecycle hooks
			add_action( 'wppo_clear_all_cache', array( $this, 'handleClearAllCache' ) );
			add_action( 'wppo_cleanup_cache', array( $this, 'handleCleanupCache' ) );
			add_action( 'wppo_optimize_images', array( $this, 'handleOptimizeImages' ) );

			// Performance monitoring hooks
			if ( $this->_container->get( 'settings_service' )->get_setting( 'performance', 'enable_monitoring' ) ) {
				add_action( 'wp_footer', array( $this, 'addPerformanceTracking' ), 999 );
			}

			LoggingUtil::debug( 'WordPress hooks setup completed' );

		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Failed to setup hooks: ' . $e->getMessage() );
		}
	}

	/**
	 * Initialize features.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function initializeFeatures(): void {
		// Initialize feature modules based on configuration.
		// For now, skip configuration loading to avoid dependency issues
		// $config = $this->_container->get( 'PerformanceOptimisation\\Core\\Config\\ConfigManager' );

		// Initialize image optimization features
		if ( $this->_container->has( 'lazy_load_service' ) ) {
			$lazy_load_service = $this->_container->get( 'lazy_load_service' );
			$lazy_load_service->init();
		}

		if ( $this->_container->has( 'next_gen_image_service' ) ) {
			$next_gen_service = $this->_container->get( 'next_gen_image_service' );
			$next_gen_service->init();
		}

		if ( $this->_container->has( 'image_service' ) ) {
			// Just getting the service will instantiate it and run the constructor hooks
			$this->_container->get( 'image_service' );
		}

		// This will be expanded when we create feature modules.
		/**
		 * Fires when features should be initialized.
	 *
		 * @since 2.0.0
	 *
		 * @param Plugin        $plugin Plugin instance.
		 * @param ConfigManager $config Configuration manager.
		 */
		do_action( 'wppo_initialize_features', $this, null );
	}

	/**
	 * Create database tables.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function createDatabaseTables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Performance statistics table.
		$stats_table = $wpdb->prefix . 'wppo_performance_stats';
		$stats_sql   = "CREATE TABLE $stats_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			metric_name varchar(100) NOT NULL,
			metric_value text NOT NULL,
			recorded_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY metric_name (metric_name),
			KEY recorded_at (recorded_at)
		) $charset_collate;";

		// Cache queue table.
		$queue_table = $wpdb->prefix . 'wppo_cache_queue';
		$queue_sql   = "CREATE TABLE $queue_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			cache_key varchar(255) NOT NULL,
			action enum('invalidate', 'refresh') NOT NULL,
			priority tinyint(1) DEFAULT 5,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY cache_key (cache_key),
			KEY priority (priority)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $stats_sql );
		dbDelta( $queue_sql );
	}

	/**
	 * Set default options.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function setDefaultOptions(): void {
		$default_options = array(
			'caching'      => array(
				'page_cache_enabled' => false,
				'cache_ttl'          => 3600,
				'cache_exclusions'   => array(),
			),
			'minification' => array(
				'minify_css'  => true,
				'minify_js'   => true,
				'combine_css' => false,
				'minify_html' => false,
			),
			'images'       => array(
				'convert_to_webp'        => true,
				'convert_to_avif'        => false,
				'auto_convert_on_upload' => true,
				'lazy_loading'           => true,
				'compression_quality'    => 85,
			),
		);

		add_option( 'wppo_settings', $default_options );
		add_option( 'wppo_version', $this->_version );
	}

	/**
	 * Schedule cron events.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function scheduleCronEvents(): void {
		if ( ! wp_next_scheduled( 'wppo_cleanup_cache' ) ) {
			wp_schedule_event( time(), 'daily', 'wppo_cleanup_cache' );
		}

		if ( ! wp_next_scheduled( 'wppo_optimize_images' ) ) {
			wp_schedule_event( time(), 'hourly', 'wppo_optimize_images' );
		}
	}

	/**
	 * Clear scheduled cron events.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function clearCronEvents(): void {
		wp_clear_scheduled_hook( 'wppo_cleanup_cache' );
		wp_clear_scheduled_hook( 'wppo_optimize_images' );
	}

	/**
	 * Clear cache.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function clearCache(): void {
		// This will be implemented when we create the cache module.
		/**
		 * Fires when cache should be cleared.
	 *
		 * @since 2.0.0
		 */
		do_action( 'wppo_clear_all_cache' );
	}

	/**
	 * Initialize admin menu.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function initAdminMenu(): void {
		// This will be implemented when we create the admin module.
		/**
		 * Fires when admin menu should be initialized.
	 *
		 * @since 2.0.0
	 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'wppo_init_admin_menu', $this );
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @since 2.0.0
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueueAdminAssets( string $hook_suffix ): void {
		/**
		 * Fires when admin assets should be enqueued.
	 *
		 * @since 2.0.0
	 *
		 * @param string $hook_suffix Current admin page hook suffix.
		 * @param Plugin $plugin      Plugin instance.
		 */
		do_action( 'wppo_enqueue_admin_assets', $hook_suffix, $this );
	}

	/**
	 * Enqueue frontend assets.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function enqueueFrontendAssets(): void {
		/**
		 * Fires when frontend assets should be enqueued.
	 *
		 * @since 2.0.0
	 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'wppo_enqueue_frontend_assets', $this );
	}

	/**
	 * Initialize REST API.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function initRestApi(): void {
		try {
			$rest_controller = $this->_container->get( 'rest_controller' );
			if ( is_object( $rest_controller ) && method_exists( $rest_controller, 'register_routes' ) ) {
				$rest_controller->register_routes();
			}

			// Initialize API Router for additional endpoints
			LoggingUtil::debug( 'Attempting to get api_router from container' );
			try {
				// Workaround: manually instantiate ApiRouter since container is returning Closure
				LoggingUtil::debug( 'Manually instantiating ApiRouter as workaround' );
				$api_router = new \PerformanceOptimisation\Core\API\ApiRouter( $this->_container );
				LoggingUtil::debug( 'Created ApiRouter instance: ' . get_class( $api_router ) );

				if ( is_object( $api_router ) && method_exists( $api_router, 'init' ) ) {
					LoggingUtil::debug( 'Calling api_router->init()' );
					$api_router->init();
					LoggingUtil::debug( 'api_router->init() completed' );
				} else {
					LoggingUtil::error( 'api_router is not an object or does not have init method' );
				}
			} catch ( \Exception $e ) {
				LoggingUtil::error( 'Failed to initialize api_router: ' . $e->getMessage() );
			}

			LoggingUtil::debug( 'REST API initialized' );

		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Failed to initialize REST API: ' . $e->getMessage() );
		}
	}

	/**
	 * Handle clear all cache action.
	 *
	 * @return void
	 */
	public function handleClearAllCache(): void {
		try {
			$cache_service = $this->_container->get( 'cache_service' );
			$result        = $cache_service->clearCache();

			LoggingUtil::info( 'All cache cleared via action hook', array( 'result' => $result ) );
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Failed to clear all cache: ' . $e->getMessage() );
		}
	}

	/**
	 * Handle cleanup cache cron job.
	 *
	 * @return void
	 */
	public function handleCleanupCache(): void {
		try {
			$cache_service = $this->_container->get( 'cache_service' );
			$performance   = $this->_container->get( 'performance' );

			$performance->startTimer( 'cache_cleanup' );
			$result   = $cache_service->cleanupExpiredCache();
			$duration = $performance->endTimer( 'cache_cleanup' );

			LoggingUtil::info(
				'Cache cleanup completed',
				array(
					'result'   => $result,
					'duration' => $duration,
				)
			);
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Cache cleanup failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Handle optimize images cron job.
	 *
	 * @return void
	 */
	public function handleOptimizeImages(): void {
		try {
			$image_service = $this->_container->get( 'image_service' );
			$performance   = $this->_container->get( 'performance' );

			$performance->startTimer( 'image_optimization' );
			$result   = $image_service->processBatch( array( 'batch_size' => 5 ) );
			$duration = $performance->endTimer( 'image_optimization' );

			LoggingUtil::info(
				'Image optimization batch completed',
				array(
					'result'   => $result,
					'duration' => $duration,
				)
			);
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Image optimization failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Add performance tracking to footer.
	 *
	 * @return void
	 */
	public function addPerformanceTracking(): void {
		if ( is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		try {
			$performance   = $this->_container->get( 'performance' );
			$tracking_data = $performance->getPagePerformanceData();

			if ( ! empty( $tracking_data ) ) {
				echo '<script>';
				echo 'window.wppoPerformanceData = ' . wp_json_encode( $tracking_data ) . ';';
				echo 'console.log("WPPO Performance Data:", window.wppoPerformanceData);';
				echo '</script>';
			}
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Failed to add performance tracking: ' . $e->getMessage() );
		}
	}

	/**
	 * Load plugin textdomain.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function loadTextdomain(): void {
		load_plugin_textdomain(
			'performance-optimisation',
			false,
			dirname( plugin_basename( $this->_plugin_file ) ) . '/languages/'
		);
	}

	/**
	 * Adds the WP_CACHE constant to wp-config.php if not already defined or set to false.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function add_wp_cache_constant(): void {
		// Initialize WordPress filesystem.
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! $wp_filesystem ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'WPPO Activation: Filesystem could not be initialized for wp-config.php modification.' );
			}
			return;
		}

		$wp_config_path = wp_normalize_path( ABSPATH . 'wp-config.php' );
		if ( ! $wp_filesystem->is_writable( $wp_config_path ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'WPPO Activation: wp-config.php is not writable at ' . esc_html( $wp_config_path ) );
			}
			return;
		}

		$config_content = $wp_filesystem->get_contents( $wp_config_path );
		if ( false === $config_content ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'WPPO Activation: Could not read wp-config.php content.' );
			}
			return;
		}

		if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
			return; // Already correctly defined.
		}

		$constant_definition = "define( 'WP_CACHE', true );";
		$comment             = '/** Enables WordPress Cache (Performance Optimisation Plugin) */';
		$new_content_block   = PHP_EOL . $comment . PHP_EOL . $constant_definition . PHP_EOL;

		if ( preg_match( "/^define\s*\(\s*['\"]WP_CACHE['\"]\s*,\s*false\s*\)\s*;$/m", $config_content ) ) {
			$config_content = preg_replace( "/^define\s*\(\s*['\"]WP_CACHE['\"]\s*,\s*false\s*\)\s*;$/m", $comment . PHP_EOL . $constant_definition, $config_content );
		} elseif ( ! preg_match( "/^define\s*\(\s*['\"]WP_CACHE['\"]\s*,\s*true\s*\)\s*;$/m", $config_content ) ) {
			$stop_editing_marker = "/*\n	That's all, stop editing!";
			if ( strpos( $config_content, $stop_editing_marker ) !== false ) {
				$config_content = str_replace( $stop_editing_marker, $new_content_block . $stop_editing_marker, $config_content );
			} else {
				$config_content .= $new_content_block;
			}
		}

		$wp_filesystem->put_contents( $wp_config_path, $config_content, FS_CHMOD_FILE );
	}

	/**
	 * Creates the activity log table in the database if it doesn't exist.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function create_activity_log_table(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'wppo_activity_logs';
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			$sql = "CREATE TABLE {$table_name} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				activity TEXT NOT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id)
			) {$charset_collate};";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
		}
	}

	/**
	 * Check system requirements.
	 *
	 * @throws \Exception If requirements are not met.
	 */
	private function checkSystemRequirements(): void {
		// Check PHP version.
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \Exception( __( 'Performance Optimisation requires PHP 7.4 or higher.', 'performance-optimisation' ) );
		}

		// Check WordPress version.
		if ( version_compare( get_bloginfo( 'version' ), '6.2', '<' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \Exception( __( 'Performance Optimisation requires WordPress 6.2 or higher.', 'performance-optimisation' ) );
		}

		// Check memory limit.
		$memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		if ( $memory_limit < 134217728 ) { // 128MB
			LoggingUtil::warning( __( 'Memory limit is below recommended 128MB. Some features may not work properly.', 'performance-optimisation' ) );
		}

		// Check write permissions.
		if ( ! wp_is_writable( WP_CONTENT_DIR ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \Exception( __( 'wp-content directory is not writable. Please check file permissions.', 'performance-optimisation' ) );
		}
	}

	/**
	 * Create cache directories.
	 */
	private function createCacheDirectories(): void {
		$cache_dirs = array(
			WP_CONTENT_DIR . '/cache/wppo/',
			WP_CONTENT_DIR . '/cache/wppo/page/',
			WP_CONTENT_DIR . '/cache/wppo/object/',
			WP_CONTENT_DIR . '/cache/wppo/minify/',
			WP_CONTENT_DIR . '/cache/wppo/images/',
		);

		foreach ( $cache_dirs as $dir ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				LoggingUtil::warning( "Failed to create cache directory: {$dir}" );
			} else {
				// Add .htaccess for security
				$htaccess_content  = "# Performance Optimisation Cache Directory\n";
				$htaccess_content .= "Options -Indexes\n";
				$htaccess_content .= "<Files \"*.php\">\n";
				$htaccess_content .= "    Require all denied\n";
				$htaccess_content .= "</Files>\n";

				file_put_contents( $dir . '.htaccess', $htaccess_content );
			}
		}
	}

	/**
	 * Ensures that the constant enabling WordPress caching, if added by this plugin,
	 * is removed during deactivation.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function remove_wp_cache_constant(): void {
		// Initialize WordPress filesystem.
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! $wp_filesystem ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'WPPO Deactivation: Filesystem could not be initialized for wp-config.php modification.' );
			}
			return;
		}

		$wp_config_path = wp_normalize_path( ABSPATH . 'wp-config.php' );

		if ( ! $wp_filesystem->is_writable( $wp_config_path ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'WPPO Deactivation: wp-config.php is not writable at ' . esc_html( $wp_config_path ) );
			}
			return;
		}

		$config_content = $wp_filesystem->get_contents( $wp_config_path );
		if ( false === $config_content ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'WPPO Deactivation: Could not read wp-config.php content.' );
			}
			return;
		}

		$pattern = '/\/\*\* Enables WordPress Cache \(Performance Optimisation Plugin\) \*\*\/\s*define\s*\(\s*([\'\”])WP_CACHE\1\s*,\s*true\s*\);?\s*/s';

		if ( preg_match( $pattern, $config_content ) ) {
			$config_content = preg_replace( $pattern, '', $config_content );
			$wp_filesystem->put_contents( $wp_config_path, $config_content, FS_CHMOD_FILE );
		}
	}
}
