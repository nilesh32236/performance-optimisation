<?php
/**
 * Main Plugin Bootstrap Class
 *
 * @package PerformanceOptimisation\Core\Bootstrap
 * @since   2.0.0
 */

namespace PerformanceOptimisation\Core\Bootstrap;

use PerformanceOptimisation\Core\Container\Container;
use PerformanceOptimisation\Core\Container\ContainerInterface;
use PerformanceOptimisation\Core\Config\ConfigManager;

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
	 * @var ContainerInterface
	 */
	private ContainerInterface $_container;

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
		$this->_container   = new Container();
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

	public static function activate_plugin(): void {
		$instance = self::getInstance();
		$instance->createDatabaseTables();
		$instance->setDefaultOptions();
		$instance->scheduleCronEvents();
		flush_rewrite_rules();
	}

	public static function deactivate_plugin(): void {
		$instance = self::getInstance();
		$instance->clearCronEvents();
		$instance->clearCache();
		flush_rewrite_rules();
	}

	/**
	 * Activate the plugin.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function activate(): void {
		// Create necessary database tables.
		$this->createDatabaseTables();

		// Set default options.
		$this->setDefaultOptions();

		// Schedule cron events.
		$this->scheduleCronEvents();

		// Flush rewrite rules.
		flush_rewrite_rules();

		/**
		 * Fires after plugin activation.
		 *
		 * @since 2.0.0
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'wppo_plugin_activated', $this );
	}

	/**
	 * Deactivate the plugin.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function deactivate(): void {
		// Clear scheduled cron events.
		$this->clearCronEvents();

		// Clear cache.
		$this->clearCache();

		// Flush rewrite rules.
		flush_rewrite_rules();

		/**
		 * Fires after plugin deactivation.
		 *
		 * @since 2.0.0
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'wppo_plugin_deactivated', $this );
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
	 * @return ContainerInterface Service container.
	 */
	public function getContainer(): ContainerInterface {
		return $this->_container;
	}

	/**
	 * Register core services.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function registerCoreServices(): void {
		// Register container itself.
		$this->_container->singleton( ContainerInterface::class, $this->_container );

		// Register plugin instance.
		$this->_container->singleton( PluginInterface::class, $this );
		$this->_container->singleton( self::class, $this );

		// Register configuration manager.
		$this->_container->singleton(
			ConfigManager::class,
			function ( $container ) {
				return new ConfigManager();
			}
		);

		// Register services.
		$this->_container->singleton( \PerformanceOptimisation\Services\CacheService::class, \PerformanceOptimisation\Services\CacheService::class );
		$this->_container->singleton( \PerformanceOptimisation\Optimizers\CssOptimizer::class, \PerformanceOptimisation\Optimizers\CssOptimizer::class );
		$this->_container->singleton( \PerformanceOptimisation\Optimizers\JsOptimizer::class, \PerformanceOptimisation\Optimizers\JsOptimizer::class );
		$this->_container->singleton( \PerformanceOptimisation\Optimizers\HtmlOptimizer::class, \PerformanceOptimisation\Optimizers\HtmlOptimizer::class );
		$this->_container->singleton( \PerformanceOptimisation\Services\OptimizationService::class, \PerformanceOptimisation\Services\OptimizationService::class );
		$this->_container->singleton( \PerformanceOptimisation\Optimizers\ImageProcessor::class, \PerformanceOptimisation\Optimizers\ImageProcessor::class );
		$this->_container->singleton( \PerformanceOptimisation\Utils\ConversionQueue::class, \PerformanceOptimisation\Utils\ConversionQueue::class );
		$this->_container->singleton(
			\PerformanceOptimisation\Services\ImageService::class,
			function ( $container ) {
				$settings = get_option( 'wppo_settings', [] );
				return new \PerformanceOptimisation\Services\ImageService(
					$container->resolve( \PerformanceOptimisation\Optimizers\ImageProcessor::class ),
					$container->resolve( \PerformanceOptimisation\Utils\ConversionQueue::class ),
					$settings['image_optimisation'] ?? []
				);
			}
		);
				$this->_container->singleton( \PerformanceOptimisation\Services\SettingsService::class, \PerformanceOptimisation\Services\SettingsService::class );
		$this->_container->singleton( \PerformanceOptimisation\Admin\Admin::class, \PerformanceOptimisation\Admin\Admin::class );
		$this->_container->singleton( \PerformanceOptimisation\Frontend\Frontend::class, \PerformanceOptimisation\Frontend\Frontend::class );
		$this->_container->singleton( \PerformanceOptimise\Inc\Cron::class, \PerformanceOptimise\Inc\Cron::class );
		$this->_container->singleton( \PerformanceOptimisation\Core\API\RestController::class, \PerformanceOptimisation\Core\API\RestController::class );
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
		require_once $this->getPath() . 'includes/Optimizers/CssOptimizer.php';
		require_once $this->getPath() . 'includes/Optimizers/JsOptimizer.php';
		require_once $this->getPath() . 'includes/Optimizers/HtmlOptimizer.php';
		require_once $this->getPath() . 'includes/Optimizers/ImageProcessor.php';
		require_once $this->getPath() . 'includes/Utils/ConversionQueue.php';
		require_once $this->getPath() . 'includes/Utils/ValidationUtil.php';
		require_once $this->getPath() . 'includes/Core/Cache/CacheDropin.php';
		require_once $this->getPath() . 'includes/Services/CacheService.php';
		require_once $this->getPath() . 'includes/Services/OptimizationService.php';
		require_once $this->getPath() . 'includes/Services/ImageService.php';
		require_once $this->getPath() . 'includes/Services/SettingsService.php';
		require_once $this->getPath() . 'includes/Admin/Admin.php';
		require_once $this->getPath() . 'includes/Admin/Metabox.php';
		require_once $this->getPath() . 'includes/Frontend/Frontend.php';
		require_once $this->getPath() . 'includes/class-cron.php';
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
		$admin    = $this->_container->resolve( \PerformanceOptimisation\Admin\Admin::class );
		$frontend = $this->_container->resolve( \PerformanceOptimisation\Frontend\Frontend::class );

		if ( is_admin() ) {
			$admin->setup_hooks();
		} else {
			$frontend->setup_hooks();
		}

		// REST API hooks.
		add_action( 'rest_api_init', array( $this, 'initRestApi' ) );

		// Internationalization.
		add_action( 'init', array( $this, 'loadTextdomain' ) );

		// Cron.
		$cron = $this->_container->resolve( \PerformanceOptimise\Inc\Cron::class );
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
		$config = $this->_container->resolve( ConfigManager::class );

		// This will be expanded when we create feature modules.
		/**
		 * Fires when features should be initialized.
		 *
		 * @since 2.0.0
		 *
		 * @param Plugin        $plugin Plugin instance.
		 * @param ConfigManager $config Configuration manager.
		 */
		do_action( 'wppo_initialize_features', $this, $config );
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
		$rest_controller = $this->_container->resolve( \PerformanceOptimisation\Core\API\RestController::class );
		$rest_controller->register_routes();
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
}
