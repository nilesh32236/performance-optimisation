<?php
/**
 * Admin Class
 *
 * @package PerformanceOptimisation\Admin
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Admin;

use PerformanceOptimisation\Interfaces\ServiceContainerInterface;
use PerformanceOptimisation\Services\SettingsService;
use PerformanceOptimisation\Services\CacheService;
use PerformanceOptimisation\Utils\LoggingUtil;
use PerformanceOptimisation\Utils\ValidationUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin
 *
 * @package PerformanceOptimisation\Admin
 */
class Admin {

	private ServiceContainerInterface $container;
	private ?SettingsService $settingsService = null;
	private ?CacheService $cacheService = null;
	private LoggingUtil $logger;
	private ValidationUtil $validator;
	private ?Metabox $metabox = null;

	public function __construct( ServiceContainerInterface $container ) {
		$this->container = $container;
		
		// Initialize logger first
		try {
			$this->logger = $container->get( 'logger' );
		} catch ( \Exception $e ) {
			$this->logger = new LoggingUtil();
		}
		
		// Get services if available, otherwise skip
		try {
			$this->settingsService = $container->get( 'settings_service' );
		} catch ( \Exception $e ) {
			$this->logger->error( 'SettingsService not available in Admin: ' . $e->getMessage() );
		}
		
		try {
			$this->cacheService = $container->get( 'cache_service' );
		} catch ( \Exception $e ) {
			// Cache service not critical for admin
		}
		
		try {
			$this->validator = $container->get( 'validator' );
		} catch ( \Exception $e ) {
			$this->validator = new ValidationUtil();
		}
		
		try {
			$this->metabox = $container->get( 'metabox' );
		} catch ( \Exception $e ) {
			// Metabox not critical
		}
	}

	public function setup_hooks(): void {
		add_action( 'admin_menu', array( $this, 'init_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_wizard' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_bar_scripts' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_settings_to_admin_bar' ), 100 );
		
		// Add AJAX handlers for admin bar actions
		add_action( 'wp_ajax_wppo_clear_all_cache', array( $this, 'handle_clear_all_cache' ) );
		add_action( 'wp_ajax_wppo_clear_page_cache', array( $this, 'handle_clear_page_cache' ) );
		
		$this->logger->debug( 'Admin hooks setup completed' );
	}

	public function init_admin_menu(): void {
		$hook_suffix = add_menu_page(
			__( 'Performance Optimisation', 'performance-optimisation' ),
			__( 'Performance Optimisation', 'performance-optimisation' ),
			'manage_options',
			'performance-optimisation',
			array( $this, 'render_admin_page' ),
			'dashicons-performance',
			2
		);
		add_action( "load-{$hook_suffix}", array( $this, 'load_plugin_admin_page_assets' ) );

		$wizard_hook_suffix = add_submenu_page(
			null,
			__( 'Performance Optimisation Setup', 'performance-optimisation' ),
			__( 'Setup Wizard', 'performance-optimisation' ),
			'manage_options',
			'performance-optimisation-setup',
			array( $this, 'render_wizard_page' )
		);
		add_action( "load-{$wizard_hook_suffix}", array( $this, 'load_wizard_page_assets' ) );
	}

	public function maybe_redirect_to_wizard(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current_page = sanitize_text_field( $_GET['page'] ?? '' );
		if ( 'performance-optimisation-setup' === $current_page ) {
			return;
		}

		if ( get_option( 'wppo_setup_wizard_completed', false ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=performance-optimisation-setup' ) );
		exit;
	}

	public function render_admin_page(): void {
		echo '<div class="wrap"><div id="performance-optimisation-admin-app"></div></div>';
	}

	public function render_wizard_page(): void {
		echo '<div class="wrap"><div id="performance-optimisation-wizard-app"></div></div>';
	}

	public function load_plugin_admin_page_assets(): void {
		$asset_file_path = WPPO_PLUGIN_PATH . 'build/index.asset.php';
		$asset_file = file_exists( $asset_file_path ) ? include $asset_file_path : array(
			'dependencies' => array(),
			'version' => WPPO_VERSION,
		);

		wp_enqueue_style(
			'performance-optimisation-admin-style',
			WPPO_PLUGIN_URL . 'build/style-index.css',
			array(),
			$asset_file['version']
		);
		wp_enqueue_script(
			'performance-optimisation-admin-script',
			WPPO_PLUGIN_URL . 'build/index.js',
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);

		// Get comprehensive admin data
		$admin_data = $this->getAdminData();

		wp_localize_script(
			'performance-optimisation-admin-script',
			'wppoAdminData',
			$admin_data
		);

		$this->logger->debug( 'Admin page assets loaded', array( 'version' => $asset_file['version'] ) );
	}

	public function load_wizard_page_assets(): void {
		$asset_file_path = WPPO_PLUGIN_PATH . 'build/wizard.asset.php';
		$asset_file = file_exists( $asset_file_path ) ? include $asset_file_path : array(
			'dependencies' => array(),
			'version' => WPPO_VERSION,
		);

		wp_enqueue_style(
			'performance-optimisation-wizard-style',
			WPPO_PLUGIN_URL . 'build/wizard.css',
			array(),
			$asset_file['version']
		);
		wp_enqueue_script(
			'performance-optimisation-wizard-script',
			WPPO_PLUGIN_URL . 'build/wizard.js',
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);

		// Get wizard-specific data
		$wizard_data = $this->getWizardData();

		wp_localize_script(
			'performance-optimisation-wizard-script',
			'wppoWizardData',
			$wizard_data
		);

		$this->logger->debug( 'Wizard page assets loaded', array( 'version' => $asset_file['version'] ) );
	}

	public function enqueue_admin_bar_scripts(): void {
		if ( is_admin_bar_showing() && current_user_can( 'manage_options' ) ) {
			$asset_file = WPPO_PLUGIN_PATH . 'build/admin-bar.asset.php';
			$asset      = file_exists( $asset_file ) ? require $asset_file : array(
				'dependencies' => array( 'jquery' ),
				'version'      => WPPO_VERSION,
			);

			wp_enqueue_script(
				'wppo-admin-bar-script',
				WPPO_PLUGIN_URL . 'build/admin-bar.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);
			wp_localize_script(
				'wppo-admin-bar-script',
				'wppoAdminBar',
				array(
					'apiUrl'   => rest_url( 'wppo/v1' ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'pagePath' => is_singular() ? ltrim( wp_parse_url( get_permalink(), PHP_URL_PATH ), '/' ) : '',
				)
			);
		}
	}

	public function add_settings_to_admin_bar( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Get cache statistics for display
		if ($this->cacheService !== null) {
			$cache_stats = $this->cacheService->getCacheStats();
			$cache_size = $cache_stats['total_size_formatted'] ?? '0 B';
		} else {
			$cache_size = '0 B';
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'wppo_admin_bar_menu',
				'title' => '<span class="ab-icon dashicons-performance"></span>' . __( 'Perf Optimise', 'performance-optimisation' ) . ' <span class="wppo-cache-size">(' . $cache_size . ')</span>',
				'href'  => admin_url( 'admin.php?page=performance-optimisation' ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => 'wppo_clear_all_cache',
				'parent' => 'wppo_admin_bar_menu',
				'title'  => __( 'Clear All Cache', 'performance-optimisation' ),
				'href'   => wp_nonce_url( admin_url( 'admin-ajax.php?action=wppo_clear_all_cache' ), 'wppo_clear_cache' ),
				'meta'   => array( 'class' => 'wppo-admin-bar-clear-all' ),
			)
		);

		if ( ! is_admin() && is_singular() ) {
			$wp_admin_bar->add_node(
				array(
					'id'     => 'wppo_clear_this_page_cache',
					'parent' => 'wppo_admin_bar_menu',
					'title'  => __( 'Clear Cache for This Page', 'performance-optimisation' ),
					'href'   => wp_nonce_url( admin_url( 'admin-ajax.php?action=wppo_clear_page_cache&page_url=' . urlencode( get_permalink() ) ), 'wppo_clear_cache' ),
					'meta'   => array( 'class' => 'wppo-admin-bar-clear-this-page' ),
				)
			);
		}

		// Add optimization status
		$optimization_status = $this->getOptimizationStatus();
		$wp_admin_bar->add_node(
			array(
				'id'     => 'wppo_optimization_status',
				'parent' => 'wppo_admin_bar_menu',
				'title'  => sprintf( __( 'Status: %s', 'performance-optimisation' ), $optimization_status['label'] ),
				'href'   => admin_url( 'admin.php?page=performance-optimisation' ),
				'meta'   => array( 'class' => 'wppo-status-' . $optimization_status['status'] ),
			)
		);
	}

	/**
	 * Handle AJAX request to clear all cache.
	 */
	public function handle_clear_all_cache(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Insufficient permissions.', 'performance-optimisation' ), 403 );
		}

		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'wppo_clear_cache' ) ) {
			wp_die( __( 'Invalid nonce.', 'performance-optimisation' ), 403 );
		}

		try {
			if ($this->cacheService !== null) {
				$result = $this->cacheService->clearAllCache();
			} else {
				$result = false;
			}
			
			$this->logger->info( 'All cache cleared via admin bar', array(
				'user_id' => get_current_user_id(),
				'result' => $result,
			) );

			wp_safe_redirect( wp_get_referer() ?: admin_url() );
			exit;
		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to clear all cache: ' . $e->getMessage() );
			wp_die( __( 'Failed to clear cache. Please try again.', 'performance-optimisation' ) );
		}
	}

	/**
	 * Handle AJAX request to clear page cache.
	 */
	public function handle_clear_page_cache(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Insufficient permissions.', 'performance-optimisation' ), 403 );
		}

		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'wppo_clear_cache' ) ) {
			wp_die( __( 'Invalid nonce.', 'performance-optimisation' ), 403 );
		}

		$page_url = $this->validator->sanitizeUrl( $_GET['page_url'] ?? '' );
		if ( empty( $page_url ) ) {
			wp_die( __( 'Invalid page URL.', 'performance-optimisation' ) );
		}

		try {
			if ($this->cacheService !== null) {
				$result = $this->cacheService->clearCache( 'page', $page_url );
			} else {
				$result = false;
			}
			
			$this->logger->info( 'Page cache cleared via admin bar', array(
				'user_id' => get_current_user_id(),
				'page_url' => $page_url,
				'result' => $result,
			) );

			wp_safe_redirect( wp_get_referer() ?: $page_url );
			exit;
		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to clear page cache: ' . $e->getMessage(), array( 'page_url' => $page_url ) );
			wp_die( __( 'Failed to clear page cache. Please try again.', 'performance-optimisation' ) );
		}
	}

	/**
	 * Get current optimization status.
	 *
	 * @return array Status information.
	 */
	private function getOptimizationStatus(): array {
		if ($this->settingsService === null) {
			return array();
		}
		$settings = $this->settingsService->get_settings();
		
		$active_optimizations = 0;
		$total_optimizations = 0;

		// Check various optimization settings
		$optimization_checks = array(
			'minification' => $settings['minification']['enable_css_minification'] ?? false,
			'caching' => $settings['caching']['enable_page_caching'] ?? false,
			'image_optimization' => $settings['image_optimization']['enable_webp_conversion'] ?? false,
			'lazy_loading' => $settings['lazy_loading']['enable_image_lazy_loading'] ?? false,
		);

		foreach ( $optimization_checks as $check ) {
			$total_optimizations++;
			if ( $check ) {
				$active_optimizations++;
			}
		}

		$percentage = $total_optimizations > 0 ? ( $active_optimizations / $total_optimizations ) * 100 : 0;

		if ( $percentage >= 75 ) {
			return array( 'status' => 'good', 'label' => __( 'Optimized', 'performance-optimisation' ) );
		} elseif ( $percentage >= 50 ) {
			return array( 'status' => 'warning', 'label' => __( 'Partial', 'performance-optimisation' ) );
		} else {
			return array( 'status' => 'error', 'label' => __( 'Needs Setup', 'performance-optimisation' ) );
		}
	}

	/**
	 * Get comprehensive admin data for JavaScript.
	 *
	 * @return array Admin data.
	 */
	private function getAdminData(): array {
		if ($this->cacheService !== null) {
			$cache_stats = $this->cacheService->getCacheStats();
		} else {
			$cache_stats = array();
		}
		$optimization_status = $this->getOptimizationStatus();

		return array(
			'apiUrl' => rest_url( 'wppo/v1' ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'settings' => $this->settingsService !== null ? $this->settingsService->get_settings() : array(),
			'cacheStats' => $cache_stats,
			'optimizationStatus' => $optimization_status,
			'capabilities' => array(
				'manage_options' => current_user_can( 'manage_options' ),
				'edit_posts' => current_user_can( 'edit_posts' ),
			),
			'urls' => array(
				'admin' => admin_url( 'admin.php?page=performance-optimisation' ),
				'wizard' => admin_url( 'admin.php?page=performance-optimisation-setup' ),
				'clear_cache' => wp_nonce_url( admin_url( 'admin-ajax.php?action=wppo_clear_all_cache' ), 'wppo_clear_cache' ),
			),
			'i18n' => array(
				'clearingCache' => __( 'Clearing cache...', 'performance-optimisation' ),
				'cacheCleared' => __( 'Cache cleared successfully!', 'performance-optimisation' ),
				'error' => __( 'An error occurred. Please try again.', 'performance-optimisation' ),
			),
		);
	}

	/**
	 * Get wizard-specific data for JavaScript.
	 *
	 * @return array Wizard data.
	 */
	private function getWizardData(): array {
		return array(
			'apiUrl' => rest_url( 'wppo/v1' ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'currentStep' => get_option( 'wppo_wizard_current_step', 1 ),
			'serverInfo' => $this->getServerInfo(),
			'recommendations' => $this->getOptimizationRecommendations(),
			'urls' => array(
				'admin' => admin_url( 'admin.php?page=performance-optimisation' ),
				'complete' => admin_url( 'admin.php?page=performance-optimisation&wizard=completed' ),
			),
			'i18n' => array(
				'next' => __( 'Next', 'performance-optimisation' ),
				'previous' => __( 'Previous', 'performance-optimisation' ),
				'complete' => __( 'Complete Setup', 'performance-optimisation' ),
				'skip' => __( 'Skip', 'performance-optimisation' ),
			),
		);
	}

	/**
	 * Get server information for wizard.
	 *
	 * @return array Server information.
	 */
	private function getServerInfo(): array {
		return array(
			'php_version' => PHP_VERSION,
			'wp_version' => get_bloginfo( 'version' ),
			'memory_limit' => ini_get( 'memory_limit' ),
			'max_execution_time' => ini_get( 'max_execution_time' ),
			'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
			'extensions' => array(
				'gd' => extension_loaded( 'gd' ),
				'imagick' => extension_loaded( 'imagick' ),
				'curl' => extension_loaded( 'curl' ),
				'zip' => extension_loaded( 'zip' ),
			),
		);
	}

	/**
	 * Get optimization recommendations based on server capabilities.
	 *
	 * @return array Recommendations.
	 */
	private function getOptimizationRecommendations(): array {
		$recommendations = array();
		$server_info = $this->getServerInfo();

		// Memory-based recommendations
		$memory_limit = $this->parseMemoryLimit( $server_info['memory_limit'] );
		if ( $memory_limit < 128 * 1024 * 1024 ) { // Less than 128MB
			$recommendations[] = array(
				'type' => 'warning',
				'title' => __( 'Low Memory Limit', 'performance-optimisation' ),
				'message' => __( 'Your server has a low memory limit. Consider enabling conservative optimization settings.', 'performance-optimisation' ),
			);
		}

		// Extension-based recommendations
		if ( ! $server_info['extensions']['gd'] && ! $server_info['extensions']['imagick'] ) {
			$recommendations[] = array(
				'type' => 'error',
				'title' => __( 'No Image Processing Extension', 'performance-optimisation' ),
				'message' => __( 'Neither GD nor ImageMagick is available. Image optimization will be limited.', 'performance-optimisation' ),
			);
		}

		return $recommendations;
	}

	/**
	 * Parse memory limit string to bytes.
	 *
	 * @param string $memory_limit Memory limit string.
	 * @return int Memory limit in bytes.
	 */
	private function parseMemoryLimit( string $memory_limit ): int {
		$memory_limit = trim( $memory_limit );
		if ( empty( $memory_limit ) ) {
			return 0;
		}
		$last_char = strtolower( $memory_limit[ strlen( $memory_limit ) - 1 ] );
		$number = (int) $memory_limit;

		switch ( $last_char ) {
			case 'g':
				$number *= 1024 * 1024 * 1024;
				break;
			case 'm':
				$number *= 1024 * 1024;
				break;
			case 'k':
				$number *= 1024;
				break;
		}

		return $number;
	}
}
