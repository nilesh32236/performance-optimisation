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

		// Register core services.
		$this->registerCoreServices();

		// Load plugin dependencies.
		$this->loadDependencies();

		// Setup WordPress hooks.
		$this->setupHooks();

		// Initialize features.
		$this->initializeFeatures();

		$this->_initialized = true;

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
	 * @return void
	 */
	public function activate(): void {
		try {
			// Load dependencies first
			$this->loadDependencies();
			
			// Register services for activation
			$this->registerCoreServices();

			// Setup advanced caching
			AdvancedCacheHandler::create();
			$this->add_wp_cache_constant();

			// Create database tables
			$this->create_activity_log_table();
			$this->createDatabaseTables();

			// Set default options
			$this->setDefaultOptions();

			// Schedule cron jobs using container
			$cron_service = $this->_container->get( 'cron_service' );
			$cron_service->schedule_cron_jobs();

			// Clear cache to ensure fresh start
			$cache_service = $this->_container->get( 'cache_service' );
			$cache_service->clearAllCache();

			flush_rewrite_rules();

			LoggingUtil::info( __( 'Plugin activated successfully', 'performance-optimisation' ), array(
				'version' => $this->_version,
				'services_registered' => $this->_container->getStats()['services_registered'] ?? 0,
			) );

		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Plugin activation failed: ' . $e->getMessage() );
			throw $e; // Re-throw to prevent activation
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

			// Remove advanced caching files directly
			$advanced_cache_file = WP_CONTENT_DIR . '/advanced-cache.php';
			if ( file_exists( $advanced_cache_file ) ) {
				unlink( $advanced_cache_file );
			}

			// Remove cache directory
			$cache_dir = WP_CONTENT_DIR . '/cache/wppo/';
			if ( is_dir( $cache_dir ) ) {
				$this->removeDirectory( $cache_dir );
			}

			flush_rewrite_rules();

			LoggingUtil::info( __( 'Plugin deactivated successfully', 'performance-optimisation' ), array(
				'version' => $this->_version,
			) );

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
	 * @return void
	 */
	private function registerCoreServices(): void {
		// Register container itself
		$this->_container->singleton( ServiceContainerInterface::class, $this->_container );

		// Register plugin instance
		$this->_container->singleton( PluginInterface::class, $this );
		$this->_container->singleton( self::class, $this );

		// Register configuration manager
		$this->_container->singleton( ConfigManager::class, function( ServiceContainerInterface $container ) {
			return new ConfigManager();
		} );

		// Use the modern service registration system
		$this->_container->registerCoreServices();

		// Register plugin-specific services
		$this->registerPluginServices();

		LoggingUtil::info( 'Core services registered', $this->_container->getStats() );
	}

	/**
	 * Register plugin-specific services.
	 *
	 * @return void
	 */
	private function registerPluginServices(): void {
		// Register API controllers
		$this->_container->singleton( 'PerformanceOptimisation\\Core\\API\\RestController', function( ServiceContainerInterface $container ) {
			return new \PerformanceOptimisation\Core\API\RestController( $container );
		} );

		$this->_container->singleton( 'PerformanceOptimisation\\Core\\API\\ApiRouter', function( ServiceContainerInterface $container ) {
			return new \PerformanceOptimisation\Core\API\ApiRouter( $container );
		} );

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
		}

		$this->load_plugin_files();
	}

	private function load_plugin_files(): void {
		require_once $this->getPath() . 'includes/Interfaces/OptimizerInterface.php';
		require_once $this->getPath() . 'includes/Interfaces/SettingsServiceInterface.php';
		require_once $this->getPath() . 'includes/Interfaces/ImageServiceInterface.php';
		require_once $this->getPath() . 'includes/Interfaces/CacheServiceInterface.php';
		require_once $this->getPath() . 'includes/Interfaces/OptimizationServiceInterface.php';
		require_once $this->getPath() . 'includes/Interfaces/ImageProcessorInterface.php';
		require_once $this->getPath() . 'includes/Core/Config/ConfigInterface.php';
		require_once $this->getPath() . 'includes/Core/Config/ConfigManager.php';
		require_once $this->getPath() . 'includes/Optimizers/ModernCssOptimizer.php';
		require_once $this->getPath() . 'includes/Optimizers/JsOptimizer.php';
		require_once $this->getPath() . 'includes/Optimizers/HtmlOptimizer.php';
		require_once $this->getPath() . 'includes/Optimizers/ModernImageProcessor.php';
		require_once $this->getPath() . 'includes/Utils/ConversionQueue.php';
		require_once $this->getPath() . 'includes/Utils/ValidationUtil.php';
		require_once $this->getPath() . 'includes/Utils/FileSystemUtil.php';
		require_once $this->getPath() . 'includes/Utils/LoggingUtil.php';
		require_once $this->getPath() . 'includes/Core/Cache/CacheDropin.php';
		require_once $this->getPath() . 'includes/Services/CacheService.php';
		require_once $this->getPath() . 'includes/Services/OptimizationService.php';
		require_once $this->getPath() . 'includes/Services/ImageService.php';
		require_once $this->getPath() . 'includes/Services/SettingsService.php';
		require_once $this->getPath() . 'includes/Admin/Admin.php';
		require_once $this->getPath() . 'includes/Admin/Metabox.php';
		require_once $this->getPath() . 'includes/Frontend/Frontend.php';
		require_once $this->getPath() . 'includes/Core/Cache/AdvancedCacheHandler.php';
		require_once $this->getPath() . 'includes/Core/API/RestController.php';
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
			// Setup admin hooks
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
				'convert_to_webp'     => true,
				'lazy_loading'        => true,
				'compression_quality' => 85,
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
			$rest_controller->register_routes();

			// Initialize API Router for additional endpoints
			$api_router = $this->_container->get( 'api_router' );
			$api_router->init();

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
			$result = $cache_service->clearAllCache();
			
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
			$performance = $this->_container->get( 'performance' );
			
			$performance->startTimer( 'cache_cleanup' );
			$result = $cache_service->cleanupExpiredCache();
			$duration = $performance->endTimer( 'cache_cleanup' );
			
			LoggingUtil::info( 'Cache cleanup completed', array(
				'result' => $result,
				'duration' => $duration,
			) );
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
			$performance = $this->_container->get( 'performance' );
			
			$performance->startTimer( 'image_optimization' );
			$result = $image_service->processBatch( array( 'batch_size' => 5 ) );
			$duration = $performance->endTimer( 'image_optimization' );
			
			LoggingUtil::info( 'Image optimization batch completed', array(
				'result' => $result,
				'duration' => $duration,
			) );
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
			$performance = $this->_container->get( 'performance' );
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
		// Initialize WordPress filesystem
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

		if ( preg_match( "/^define\s*\(\s*['\\]\'WP_CACHE[\'\\]\'\s*,\s*false\s*\)\s*;$/m", $config_content ) ) {
			$config_content = preg_replace( "/^define\s*\(\s*['\\]\'WP_CACHE[\'\\]\'\s*,\s*false\s*\)\s*;$/m", $comment . PHP_EOL . $constant_definition, $config_content );
		} elseif ( ! preg_match( "/^define\s*\(\s*['\\]\'WP_CACHE[\'\\]\'\s*,\s*true\s*\)\s*;$/m", $config_content ) ) {
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
	 * Removes WP_CACHE constant from wp-config.php file if present and set by this plugin.
	 *
	 * Ensures that the constant enabling WordPress caching, if added by this plugin,
	 * is removed during deactivation.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function remove_wp_cache_constant(): void {
		// Initialize WordPress filesystem
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
