<?php
/**
 * Plugin Performance Optimizer
 *
 * Optimizes the plugin's own performance and resource usage.
 *
 * @package PerformanceOptimisation\Core\Performance
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Core\Performance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin Performance Optimizer class.
 */
class PluginOptimizer {

	/**
	 * Cache for expensive operations.
	 *
	 * @var array<string, mixed>
	 */
	private static array $cache = array();

	/**
	 * Lazy-loaded components.
	 *
	 * @var array<string, object>
	 */
	private static array $components = array();

	/**
	 * Initialize performance optimizations.
	 *
	 * @return void
	 */
	public function init(): void {
		// Optimize database queries
		add_action( 'init', array( $this, 'optimize_database_queries' ) );

		// Lazy load admin components
		add_action( 'admin_init', array( $this, 'lazy_load_admin_components' ) );

		// Optimize asset loading
		add_action( 'wp_enqueue_scripts', array( $this, 'optimize_asset_loading' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'optimize_admin_asset_loading' ), 999 );

		// Cache expensive operations
		add_action( 'init', array( $this, 'setup_operation_caching' ) );

		// Optimize cron jobs
		add_action( 'init', array( $this, 'optimize_cron_jobs' ) );

		// Memory optimization
		add_action( 'shutdown', array( $this, 'cleanup_memory' ) );
	}

	/**
	 * Optimize database queries.
	 *
	 * @return void
	 */
	public function optimize_database_queries(): void {
		// Add database query caching
		add_filter( 'wppo_cache_database_query', array( $this, 'cache_database_query' ), 10, 3 );

		// Optimize settings queries
		add_filter( 'pre_option_wppo_settings', array( $this, 'optimize_settings_query' ) );

		// Batch database operations
		add_action( 'wppo_batch_database_operations', array( $this, 'batch_database_operations' ) );
	}

	/**
	 * Cache database queries.
	 *
	 * @param mixed                $result Query result.
	 * @param string               $query SQL query.
	 * @param array<string, mixed> $args Query arguments.
	 * @return mixed Cached or fresh result.
	 */
	public function cache_database_query( $result, string $query, array $args ) {
		$cache_key = 'wppo_db_' . md5( $query . serialize( $args ) );

		// Check cache first
		$cached_result = wp_cache_get( $cache_key, 'wppo_database' );
		if ( $cached_result !== false ) {
			return $cached_result;
		}

		// If no result provided, execute query
		if ( $result === null ) {
			global $wpdb;
			$result = $wpdb->get_results( $wpdb->prepare( $query, $args ) );
		}

		// Cache result for 5 minutes
		wp_cache_set( $cache_key, $result, 'wppo_database', 300 );

		return $result;
	}

	/**
	 * Optimize settings query.
	 *
	 * @param mixed $value Option value.
	 * @return mixed Optimized value.
	 */
	public function optimize_settings_query( $value ) {
		// Use static cache for settings to avoid repeated database queries
		static $settings_cache = null;

		if ( $settings_cache === null ) {
			$settings_cache = get_option( 'wppo_settings', array() );
		}

		return $settings_cache;
	}

	/**
	 * Batch database operations.
	 *
	 * @return void
	 */
	public function batch_database_operations(): void {
		global $wpdb;

		// Collect pending operations
		$pending_operations = get_transient( 'wppo_pending_db_operations' );
		if ( empty( $pending_operations ) ) {
			return;
		}

		// Start transaction for better performance
		$wpdb->query( 'START TRANSACTION' );

		try {
			foreach ( $pending_operations as $operation ) {
				switch ( $operation['type'] ) {
					case 'insert':
						$wpdb->insert( $operation['table'], $operation['data'] );
						break;
					case 'update':
						$wpdb->update( $operation['table'], $operation['data'], $operation['where'] );
						break;
					case 'delete':
						$wpdb->delete( $operation['table'], $operation['where'] );
						break;
				}
			}

			$wpdb->query( 'COMMIT' );
			delete_transient( 'wppo_pending_db_operations' );

		} catch ( Exception $e ) {
			$wpdb->query( 'ROLLBACK' );
			\PerformanceOptimisation\Utils\LoggingUtil::error( 'WPPO: Batch database operation failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Lazy load admin components.
	 *
	 * @return void
	 */
	public function lazy_load_admin_components(): void {
		if ( ! is_admin() ) {
			return;
		}

		// Only load components when needed
		$current_page = sanitize_text_field( $_GET['page'] ?? '' );

		switch ( $current_page ) {
			case 'performance-optimisation':
				$this->load_component( 'dashboard' );
				break;
			case 'performance-optimisation-settings':
				$this->load_component( 'settings' );
				break;
			case 'performance-optimisation-analytics':
				$this->load_component( 'analytics' );
				break;
			case 'performance-optimisation-wizard':
				$this->load_component( 'wizard' );
				break;
		}
	}

	/**
	 * Load component on demand.
	 *
	 * @param string $component Component name.
	 * @return object|null Component instance.
	 */
	private function load_component( string $component ): ?object {
		if ( isset( self::$components[ $component ] ) ) {
			return self::$components[ $component ];
		}

		$component_classes = array(
			'dashboard' => 'PerformanceOptimisation\\Admin\\Dashboard',
			'settings'  => 'PerformanceOptimisation\\Admin\\Settings',
			'analytics' => 'PerformanceOptimisation\\Admin\\Analytics',
			'wizard'    => 'PerformanceOptimisation\\Admin\\Wizard',
		);

		if ( ! isset( $component_classes[ $component ] ) ) {
			return null;
		}

		$class_name = $component_classes[ $component ];

		if ( class_exists( $class_name ) ) {
			self::$components[ $component ] = new $class_name();
			return self::$components[ $component ];
		}

		return null;
	}

	/**
	 * Optimize asset loading.
	 *
	 * @return void
	 */
	public function optimize_asset_loading(): void {
		// Only load assets on pages that need them
		if ( ! $this->should_load_frontend_assets() ) {
			return;
		}

		// Defer non-critical scripts
		add_filter( 'script_loader_tag', array( $this, 'defer_non_critical_scripts' ), 10, 2 );

		// Preload critical assets
		add_action( 'wp_head', array( $this, 'preload_critical_assets' ) );

		// Optimize asset delivery
		$this->optimize_asset_delivery();
	}

	/**
	 * Check if frontend assets should be loaded.
	 *
	 * @return bool True if assets should be loaded.
	 */
	private function should_load_frontend_assets(): bool {
		// Don't load on admin pages
		if ( is_admin() ) {
			return false;
		}

		// Don't load on login/register pages
		if ( in_array( $GLOBALS['pagenow'], array( 'wp-login.php', 'wp-register.php' ) ) ) {
			return false;
		}

		// Check if any optimization features are active
		$settings = get_option( 'wppo_settings', array() );

		$active_features = array(
			$settings['image_optimisation']['lazyLoadImages'] ?? false,
			$settings['cache_settings']['enablePageCaching'] ?? false,
		);

		return in_array( true, $active_features, true );
	}

	/**
	 * Defer non-critical scripts.
	 *
	 * @param string $tag Script tag.
	 * @param string $handle Script handle.
	 * @return string Modified script tag.
	 */
	public function defer_non_critical_scripts( string $tag, string $handle ): string {
		// List of non-critical scripts that can be deferred
		$deferrable_scripts = array(
			'wppo-analytics',
			'wppo-lazy-loading',
			'wppo-image-optimization',
		);

		if ( in_array( $handle, $deferrable_scripts, true ) ) {
			return str_replace( ' src', ' defer src', $tag );
		}

		return $tag;
	}

	/**
	 * Preload critical assets.
	 *
	 * @return void
	 */
	public function preload_critical_assets(): void {
		$critical_assets = array(
			array(
				'href' => plugins_url( 'assets/css/critical.css', WPPO_PLUGIN_FILE ),
				'as'   => 'style',
			),
			array(
				'href' => plugins_url( 'assets/js/critical.js', WPPO_PLUGIN_FILE ),
				'as'   => 'script',
			),
		);

		foreach ( $critical_assets as $asset ) {
			if ( file_exists( WP_CONTENT_DIR . str_replace( WP_CONTENT_URL, '', $asset['href'] ) ) ) {
				printf(
					'<link rel="preload" href="%s" as="%s">%s',
					esc_url( $asset['href'] ),
					esc_attr( $asset['as'] ),
					"\n"
				);
			}
		}
	}

	/**
	 * Optimize asset delivery.
	 *
	 * @return void
	 */
	private function optimize_asset_delivery(): void {
		// Combine and minify CSS files
		add_filter( 'wppo_combine_css', '__return_true' );

		// Use CDN for assets if available
		$this->setup_asset_cdn();

		// Enable asset versioning for cache busting
		add_filter( 'wppo_asset_version', array( $this, 'get_asset_version' ) );
	}

	/**
	 * Setup asset CDN.
	 *
	 * @return void
	 */
	private function setup_asset_cdn(): void {
		$settings = get_option( 'wppo_settings', array() );
		$cdn_url  = $settings['cdn']['asset_url'] ?? '';

		if ( ! empty( $cdn_url ) ) {
			add_filter(
				'wppo_asset_url',
				function ( $url ) use ( $cdn_url ) {
					return str_replace( plugins_url( '', WPPO_PLUGIN_FILE ), $cdn_url, $url );
				}
			);
		}
	}

	/**
	 * Get asset version for cache busting.
	 *
	 * @param string $file Asset file path.
	 * @return string Asset version.
	 */
	public function get_asset_version( string $file ): string {
		static $versions = array();

		if ( ! isset( $versions[ $file ] ) ) {
			$file_path         = plugin_dir_path( WPPO_PLUGIN_FILE ) . $file;
			$versions[ $file ] = file_exists( $file_path ) ? filemtime( $file_path ) : '1.0.0';
		}

		return (string) $versions[ $file ];
	}

	/**
	 * Optimize admin asset loading.
	 *
	 * @return void
	 */
	public function optimize_admin_asset_loading(): void {
		$current_screen = get_current_screen();

		// Only load admin assets on plugin pages
		if ( ! $current_screen || strpos( $current_screen->id, 'performance-optimisation' ) === false ) {
			return;
		}

		// Load assets based on current page
		$this->load_page_specific_assets( $current_screen->id );

		// Optimize admin asset delivery
		add_filter( 'script_loader_tag', array( $this, 'optimize_admin_scripts' ), 10, 2 );
	}

	/**
	 * Load page-specific assets.
	 *
	 * @param string $page_id Current page ID.
	 * @return void
	 */
	private function load_page_specific_assets( string $page_id ): void {
		$page_assets = array(
			'toplevel_page_performance-optimisation' => array( 'dashboard', 'analytics' ),
			'performance-optimisation_page_performance-optimisation-settings' => array( 'settings' ),
			'performance-optimisation_page_performance-optimisation-wizard' => array( 'wizard' ),
		);

		$assets_to_load = $page_assets[ $page_id ] ?? array();

		foreach ( $assets_to_load as $asset ) {
			$this->enqueue_optimized_asset( $asset );
		}
	}

	/**
	 * Enqueue optimized asset.
	 *
	 * @param string $asset Asset name.
	 * @return void
	 */
	private function enqueue_optimized_asset( string $asset ): void {
		$asset_config = array(
			'dashboard' => array(
				'js'  => 'assets/js/admin/dashboard.js',
				'css' => 'assets/css/admin/dashboard.css',
			),
			'settings'  => array(
				'js'  => 'assets/js/admin/settings.js',
				'css' => 'assets/css/admin/settings.css',
			),
			'analytics' => array(
				'js'  => 'assets/js/admin/analytics.js',
				'css' => 'assets/css/admin/analytics.css',
			),
			'wizard'    => array(
				'js'  => 'build/wizard.js',
				'css' => 'assets/css/admin/wizard.css',
			),
		);

		if ( ! isset( $asset_config[ $asset ] ) ) {
			return;
		}

		$config = $asset_config[ $asset ];

		// Enqueue JavaScript
		if ( isset( $config['js'] ) ) {
			wp_enqueue_script(
				"wppo-{$asset}",
				plugins_url( $config['js'], WPPO_PLUGIN_FILE ),
				array( 'jquery' ),
				$this->get_asset_version( $config['js'] ),
				true
			);
		}

		// Enqueue CSS
		if ( isset( $config['css'] ) ) {
			wp_enqueue_style(
				"wppo-{$asset}",
				plugins_url( $config['css'], WPPO_PLUGIN_FILE ),
				array(),
				$this->get_asset_version( $config['css'] )
			);
		}
	}

	/**
	 * Optimize admin scripts.
	 *
	 * @param string $tag Script tag.
	 * @param string $handle Script handle.
	 * @return string Optimized script tag.
	 */
	public function optimize_admin_scripts( string $tag, string $handle ): string {
		// Add async loading for non-critical admin scripts
		$async_scripts = array(
			'wppo-analytics',
			'wppo-charts',
		);

		if ( in_array( $handle, $async_scripts, true ) ) {
			return str_replace( ' src', ' async src', $tag );
		}

		return $tag;
	}

	/**
	 * Setup operation caching.
	 *
	 * @return void
	 */
	public function setup_operation_caching(): void {
		// Cache expensive file system operations
		add_filter( 'wppo_cache_file_operation', array( $this, 'cache_file_operation' ), 10, 2 );

		// Cache API responses
		add_filter( 'wppo_cache_api_response', array( $this, 'cache_api_response' ), 10, 3 );

		// Cache computed values
		add_filter( 'wppo_cache_computed_value', array( $this, 'cache_computed_value' ), 10, 3 );
	}

	/**
	 * Cache file operations.
	 *
	 * @param mixed  $result Operation result.
	 * @param string $operation Operation type.
	 * @return mixed Cached or fresh result.
	 */
	public function cache_file_operation( $result, string $operation ) {
		$cache_key = "wppo_file_{$operation}";

		// Check cache
		if ( isset( self::$cache[ $cache_key ] ) ) {
			return self::$cache[ $cache_key ];
		}

		// Store in cache
		self::$cache[ $cache_key ] = $result;

		return $result;
	}

	/**
	 * Cache API responses.
	 *
	 * @param mixed                $response API response.
	 * @param string               $endpoint API endpoint.
	 * @param array<string, mixed> $params Request parameters.
	 * @return mixed Cached or fresh response.
	 */
	public function cache_api_response( $response, string $endpoint, array $params ) {
		$cache_key = 'wppo_api_' . md5( $endpoint . serialize( $params ) );

		// Check transient cache
		$cached_response = get_transient( $cache_key );
		if ( $cached_response !== false ) {
			return $cached_response;
		}

		// Cache response for 5 minutes
		set_transient( $cache_key, $response, 300 );

		return $response;
	}

	/**
	 * Cache computed values.
	 *
	 * @param mixed                $value Computed value.
	 * @param string               $computation Computation identifier.
	 * @param array<string, mixed> $inputs Input parameters.
	 * @return mixed Cached or fresh value.
	 */
	public function cache_computed_value( $value, string $computation, array $inputs ) {
		$cache_key = "wppo_computed_{$computation}_" . md5( serialize( $inputs ) );

		// Check object cache
		$cached_value = wp_cache_get( $cache_key, 'wppo_computed' );
		if ( $cached_value !== false ) {
			return $cached_value;
		}

		// Cache value for 10 minutes
		wp_cache_set( $cache_key, $value, 'wppo_computed', 600 );

		return $value;
	}

	/**
	 * Optimize cron jobs.
	 *
	 * @return void
	 */
	public function optimize_cron_jobs(): void {
		// Batch cron operations
		add_action( 'wppo_batch_cron_operations', array( $this, 'batch_cron_operations' ) );

		// Optimize cron scheduling
		add_filter( 'cron_schedules', array( $this, 'add_optimized_cron_schedules' ) );

		// Prevent cron overlap
		add_action( 'wppo_cron_start', array( $this, 'prevent_cron_overlap' ) );
	}

	/**
	 * Batch cron operations.
	 *
	 * @return void
	 */
	public function batch_cron_operations(): void {
		// Collect all pending cron operations
		$operations = array(
			'image_optimization' => get_option( 'wppo_pending_image_optimization', array() ),
			'cache_cleanup'      => get_option( 'wppo_pending_cache_cleanup', array() ),
			'database_cleanup'   => get_option( 'wppo_pending_database_cleanup', array() ),
		);

		// Process operations in batches
		foreach ( $operations as $type => $items ) {
			if ( ! empty( $items ) ) {
				$this->process_cron_batch( $type, array_slice( $items, 0, 10 ) );
			}
		}
	}

	/**
	 * Process cron batch.
	 *
	 * @param string       $type Operation type.
	 * @param array<mixed> $items Items to process.
	 * @return void
	 */
	private function process_cron_batch( string $type, array $items ): void {
		switch ( $type ) {
			case 'image_optimization':
				$this->process_image_optimization_batch( $items );
				break;
			case 'cache_cleanup':
				$this->process_cache_cleanup_batch( $items );
				break;
			case 'database_cleanup':
				$this->process_database_cleanup_batch( $items );
				break;
		}
	}

	/**
	 * Process image optimization batch.
	 *
	 * @param array<mixed> $items Images to optimize.
	 * @return void
	 */
	private function process_image_optimization_batch( array $items ): void {
		foreach ( $items as $item ) {
			// Process image optimization
			do_action( 'wppo_optimize_image', $item );
		}
	}

	/**
	 * Process cache cleanup batch.
	 *
	 * @param array<mixed> $items Cache items to clean.
	 * @return void
	 */
	private function process_cache_cleanup_batch( array $items ): void {
		foreach ( $items as $item ) {
			// Clean cache item
			wp_cache_delete( $item['key'], $item['group'] );
		}
	}

	/**
	 * Process database cleanup batch.
	 *
	 * @param array<mixed> $items Database items to clean.
	 * @return void
	 */
	private function process_database_cleanup_batch( array $items ): void {
		global $wpdb;

		foreach ( $items as $item ) {
			// Clean database item
			$wpdb->delete( $item['table'], $item['where'] );
		}
	}

	/**
	 * Add optimized cron schedules.
	 *
	 * @param array<string, array<string, mixed>> $schedules Existing schedules.
	 * @return array<string, array<string, mixed>> Modified schedules.
	 */
	public function add_optimized_cron_schedules( array $schedules ): array {
		$schedules['wppo_every_5_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every 5 Minutes', 'performance-optimisation' ),
		);

		$schedules['wppo_every_15_minutes'] = array(
			'interval' => 900,
			'display'  => __( 'Every 15 Minutes', 'performance-optimisation' ),
		);

		return $schedules;
	}

	/**
	 * Prevent cron overlap.
	 *
	 * @param string $cron_name Cron job name.
	 * @return void
	 */
	public function prevent_cron_overlap( string $cron_name ): void {
		$lock_key = "wppo_cron_lock_{$cron_name}";

		// Check if cron is already running
		if ( get_transient( $lock_key ) ) {
			wp_die( 'Cron job already running' );
		}

		// Set lock for 5 minutes
		set_transient( $lock_key, time(), 300 );

		// Remove lock when done
		add_action(
			'shutdown',
			function () use ( $lock_key ) {
				delete_transient( $lock_key );
			}
		);
	}

	/**
	 * Cleanup memory.
	 *
	 * @return void
	 */
	public function cleanup_memory(): void {
		// Clear static caches
		self::$cache = array();

		// Clear component instances
		self::$components = array();

		// Force garbage collection
		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}
	}

	/**
	 * Get performance metrics.
	 *
	 * @return array<string, mixed> Performance metrics.
	 */
	public function get_performance_metrics(): array {
		return array(
			'memory_usage'      => memory_get_usage( true ),
			'memory_peak'       => memory_get_peak_usage( true ),
			'cache_hits'        => count( self::$cache ),
			'loaded_components' => array_keys( self::$components ),
			'execution_time'    => microtime( true ) - ( $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true ) ),
		);
	}
}
