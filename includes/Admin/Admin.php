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

	/**
	 * Service container instance.
	 *
	 * @var ServiceContainerInterface
	 */
	private ServiceContainerInterface $container;

	/**
	 * Settings service instance.
	 *
	 * @var SettingsService|null
	 */
	private ?SettingsService $settings_service = null;

	/**
	 * Cache service instance.
	 *
	 * @var CacheService|null
	 */
	private ?CacheService $cache_service = null;

	/**
	 * Logger instance.
	 *
	 * @var LoggingUtil
	 */
	private LoggingUtil $logger;

	/**
	 * Validation utility instance.
	 *
	 * @var ValidationUtil
	 */
	private ValidationUtil $validator;

	/**
	 * Metabox instance.
	 *
	 * @var Metabox|null
	 */
	private ?Metabox $metabox = null;

	/**
	 * Constructor.
	 *
	 * @param ServiceContainerInterface $container Service container.
	 */
	public function __construct( ServiceContainerInterface $container ) {
		$this->container = $container;

		// Initialize logger first.
		try {
			$this->logger = $container->get( 'logger' );
		} catch ( \Exception $e ) {
			$this->logger = new LoggingUtil();
		}

		// Get services if available, otherwise skip.
		try {
			$this->settings_service = $container->get( 'settings_service' );
		} catch ( \Exception $e ) {
			$this->logger->error( 'SettingsService not available in Admin: ' . $e->getMessage() );
		}

		try {
			$this->cache_service = $container->get( 'cache_service' );
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		} catch ( \Exception $e ) {
			// Cache service not critical for admin.
		}

		try {
			$this->validator = $container->get( 'validator' );
		} catch ( \Exception $e ) {
			$this->validator = new ValidationUtil();
		}

		try {
			$metabox_service = $container->get( 'metabox' );
			if ( $metabox_service instanceof Metabox ) {
				$this->metabox = $metabox_service;
			}
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		} catch ( \Exception $e ) {
			// Metabox not critical.
		}
	}

	/**
	 * Setup admin hooks.
	 *
	 * @return void
	 */
	public function setup_hooks(): void {
		add_action( 'admin_menu', array( $this, 'init_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_wizard' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_bar_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_admin_bar_scripts' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_settings_to_admin_bar' ), 100 );

		// Add AJAX handlers for admin bar actions.
		add_action( 'wp_ajax_wppo_clear_all_cache', array( $this, 'handle_clear_all_cache' ) );
		add_action( 'wp_ajax_wppo_clear_page_cache', array( $this, 'handle_clear_page_cache' ) );
		add_action( 'wp_ajax_wppo_get_cache_stats', array( $this, 'ajax_get_cache_stats' ) );

		$this->logger->debug( 'Admin hooks setup completed' );
	}

	/**
	 * Initialize admin menu.
	 *
	 * @return void
	 */
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
			'', // Empty string instead of null for hidden menu
			__( 'Performance Optimisation Setup', 'performance-optimisation' ),
			__( 'Setup Wizard', 'performance-optimisation' ),
			'manage_options',
			'performance-optimisation-setup',
			array( $this, 'render_wizard_page' )
		);
		add_action( "load-{$wizard_hook_suffix}", array( $this, 'load_wizard_page_assets' ) );
	}

	/**
	 * Redirect to setup wizard if not completed.
	 *
	 * @return void
	 */
	public function maybe_redirect_to_wizard(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'performance-optimisation-setup' === $current_page ) {
			return;
		}

		if ( get_option( 'wppo_setup_wizard_completed', false ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=performance-optimisation-setup' ) );
		exit;
	}

	/**
	 * Render the main admin page.
	 *
	 * @return void
	 */
	public function render_admin_page(): void {
		echo '<div class="wrap"><div id="performance-optimisation-admin-app"></div></div>';
	}

	/**
	 * Render the setup wizard page.
	 *
	 * @return void
	 */
	public function render_wizard_page(): void {
		echo '<div class="wrap"><div id="performance-optimisation-wizard-app"></div></div>';
	}

	/**
	 * Load assets for the admin page.
	 *
	 * @return void
	 */
	public function load_plugin_admin_page_assets(): void {
		$asset_file_path = WPPO_PLUGIN_PATH . 'build/index.asset.php';
		$asset_file      = file_exists( $asset_file_path ) ? include $asset_file_path : array(
			'dependencies' => array(),
			'version'      => file_exists( WPPO_PLUGIN_PATH . 'build/index.js' ) ? filemtime( WPPO_PLUGIN_PATH . 'build/index.js' ) : WPPO_VERSION,
		);

		wp_enqueue_style(
			'performance-optimisation-admin-style',
			WPPO_PLUGIN_URL . 'build/index.css',
			array( 'wp-components' ),
			$asset_file['version']
		);

		// Ensure WordPress components styles are loaded.
		wp_enqueue_style( 'wp-components' );

		// Add inline CSS to ensure proper styling.
		wp_add_inline_style(
			'performance-optimisation-admin-style',
			'
			.wppo-admin { 
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
				line-height: 1.5 !important;
			}
			.wppo-admin .components-tab-panel__tabs { 
				border-bottom: 1px solid #ddd !important; 
			}
			.wppo-admin .components-tab-panel__tab-content { 
				padding: 24px !important; 
			}
		'
		);
		wp_enqueue_script(
			'performance-optimisation-admin-script',
			WPPO_PLUGIN_URL . 'build/index.js',
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);

		// Get comprehensive admin data.
		$admin_data = $this->get_admin_data();

		wp_localize_script(
			'performance-optimisation-admin-script',
			'wppoAdmin',
			$admin_data
		);

		$this->logger->debug( 'Admin page assets loaded', array( 'version' => $asset_file['version'] ) );
	}

	/**
	 * Load assets for the wizard page.
	 *
	 * @return void
	 */
	public function load_wizard_page_assets(): void {
		$asset_file_path = WPPO_PLUGIN_PATH . 'build/wizard.asset.php';
		$asset_file      = file_exists( $asset_file_path ) ? include $asset_file_path : array(
			'dependencies' => array(),
			'version'      => WPPO_VERSION,
		);

		wp_enqueue_style(
			'performance-optimisation-wizard-style',
			WPPO_PLUGIN_URL . 'build/wizard.css',
			array( 'wp-components' ),
			$asset_file['version']
		);
		wp_enqueue_script(
			'performance-optimisation-wizard-script',
			WPPO_PLUGIN_URL . 'build/wizard.js',
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);

		// Get wizard-specific data.
		$wizard_data = $this->get_wizard_data();

		wp_localize_script(
			'performance-optimisation-wizard-script',
			'wppoWizardData',
			$wizard_data
		);

		$this->logger->debug(
			'Wizard page assets loaded',
			array(
				'version'    => $asset_file['version'],
				'css_url'    => WPPO_PLUGIN_URL . 'build/wizard.css',
				'css_exists' => file_exists( WPPO_PLUGIN_PATH . 'build/wizard.css' ),
			)
		);
	}

	/**
	 * Enqueue scripts for the admin bar.
	 *
	 * @return void
	 */
	public function enqueue_admin_bar_scripts(): void {
		if ( is_admin_bar_showing() && current_user_can( 'manage_options' ) ) {
			wp_enqueue_style(
				'wppo-admin-bar-style',
				plugin_dir_url( dirname( __DIR__ ) ) . 'assets/css/admin-bar.css',
				array(),
				WPPO_VERSION
			);

			wp_enqueue_script(
				'wppo-admin-bar-script',
				plugin_dir_url( dirname( __DIR__ ) ) . 'assets/js/admin-bar.js',
				array( 'jquery' ),
				WPPO_VERSION,
				true
			);

			wp_localize_script(
				'wppo-admin-bar-script',
				'wppoAdminBar',
				array(
					'ajaxurl'  => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'wppo_clear_cache' ),
					'pagePath' => is_singular() ? ltrim( wp_parse_url( get_permalink(), PHP_URL_PATH ), '/' ) : '',
				)
			);
		}
	}

	/**
	 * Add settings to the admin bar.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 * @return void
	 */
	public function add_settings_to_admin_bar( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Get cache statistics for display.
		$cache_stats = $this->get_cache_stats();
		$cache_size  = $cache_stats['formatted_total_size'] ?? '0 B';

		if ( isset( $cache_stats['error'] ) ) {
			$cache_size = 'Error';
			$this->logger->warning( 'Cache stats unavailable for admin bar' );
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'wppo_admin_bar_menu',
				'title' => '<span class="ab-icon dashicons-performance"></span>' .
					esc_html__( 'Perf Optimise', 'performance-optimisation' ) .
					' <span class="wppo-cache-size">(' . esc_html( $cache_size ) . ')</span>',
				'href'  => admin_url( 'admin.php?page=performance-optimisation' ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => 'wppo_clear_all_cache',
				'parent' => 'wppo_admin_bar_menu',
				'title'  => esc_html__( 'Clear All Cache', 'performance-optimisation' ),
				'href'   => wp_nonce_url(
					admin_url( 'admin-ajax.php?action=wppo_clear_all_cache' ),
					'wppo_clear_cache'
				),
				'meta'   => array( 'class' => 'wppo-admin-bar-clear-all' ),
			)
		);

		if ( ! is_admin() && is_singular() ) {
			$wp_admin_bar->add_node(
				array(
					'id'     => 'wppo_clear_this_page_cache',
					'parent' => 'wppo_admin_bar_menu',
					'title'  => esc_html__( 'Clear Cache for This Page', 'performance-optimisation' ),
					'href'   => wp_nonce_url(
						admin_url(
							'admin-ajax.php?action=wppo_clear_page_cache&page_url=' .
							rawurlencode( get_permalink() )
						),
						'wppo_clear_cache'
					),
					'meta'   => array( 'class' => 'wppo-admin-bar-clear-this-page' ),
				)
			);
		}

		// Add optimization status.
		$optimization_status = $this->get_optimization_status();
		$wp_admin_bar->add_node(
			array(
				'id'     => 'wppo_optimization_status',
				'parent' => 'wppo_admin_bar_menu',
				'title'  => sprintf(
					/* translators: %s: Optimization status label */
					esc_html__( 'Status: %s', 'performance-optimisation' ),
					$optimization_status['label']
				),
				'href'   => admin_url( 'admin.php?page=performance-optimisation' ),
				'meta'   => array( 'class' => 'wppo-status-' . $optimization_status['status'] ),
			)
		);
	}

	/**
	 * Handle AJAX request to clear all cache.
	 *
	 * @throws \Exception If cache service is unavailable.
	 */
	public function handle_clear_all_cache(): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
			if ( wp_doing_ajax() ) {
				wp_send_json_error( 'Invalid request method' );
			}
			wp_die( esc_html__( 'Invalid request method', 'performance-optimisation' ), 405 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			if ( wp_doing_ajax() ) {
				wp_send_json_error( 'Insufficient permissions' );
			}
			wp_die( esc_html__( 'Insufficient permissions.', 'performance-optimisation' ), 403 );
		}

		if (
			! isset( $_POST['_wpnonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'wppo_clear_cache' )
		) {
			if ( wp_doing_ajax() ) {
				wp_send_json_error( 'Invalid nonce' );
			}
			wp_die( esc_html__( 'Invalid nonce.', 'performance-optimisation' ), 403 );
		}

		try {
			if ( ! $this->cache_service ) {
				throw new \Exception( 'Cache service not available' );
			}

			$result = $this->cache_service->clear_cache( 'all' );

			$this->logger->info(
				'All cache cleared via admin bar',
				array(
					'user_id' => get_current_user_id(),
					'result'  => $result,
				)
			);

			if ( wp_doing_ajax() ) {
				wp_send_json_success( 'Cache cleared successfully' );
			}

			$referer = wp_get_referer();
			wp_safe_redirect( $referer ? $referer : admin_url() );
			exit;
		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to clear all cache: ' . $e->getMessage() );
			if ( wp_doing_ajax() ) {
				wp_send_json_error( $e->getMessage() );
			}
			wp_die(
				esc_html__( 'Failed to clear cache. Please try again.', 'performance-optimisation' )
			);
		}
	}

	/**
	 * Handle AJAX request to clear page cache.
	 *
	 * @throws \Exception If cache service is unavailable.
	 */
	public function handle_clear_page_cache(): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
			if ( wp_doing_ajax() ) {
				wp_send_json_error( 'Invalid request method' );
			}
			wp_die( esc_html__( 'Invalid request method', 'performance-optimisation' ), 405 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			if ( wp_doing_ajax() ) {
				wp_send_json_error( 'Insufficient permissions' );
			}
			wp_die( esc_html__( 'Insufficient permissions.', 'performance-optimisation' ), 403 );
		}

		if (
			! isset( $_POST['_wpnonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'wppo_clear_cache' )
		) {
			if ( wp_doing_ajax() ) {
				wp_send_json_error( 'Invalid nonce' );
			}
			wp_die( esc_html__( 'Invalid nonce.', 'performance-optimisation' ), 403 );
		}

		$page_url = isset( $_POST['page_url'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			? $this->validator->sanitizeUrl( wp_unslash( $_POST['page_url'] ) )
			: '';
		if ( empty( $page_url ) ) {
			if ( wp_doing_ajax() ) {
				wp_send_json_error( 'Invalid page URL' );
			}
			wp_die( esc_html__( 'Invalid page URL.', 'performance-optimisation' ) );
		}

		try {
			if ( ! $this->cache_service ) {
				throw new \Exception( 'Cache service not available' );
			}

			$result = $this->cache_service->invalidate_cache( $page_url );

			$this->logger->info(
				'Page cache cleared via admin bar',
				array(
					'user_id'  => get_current_user_id(),
					'page_url' => $page_url,
					'result'   => $result,
				)
			);

			if ( wp_doing_ajax() ) {
				wp_send_json_success( 'Page cache cleared successfully' );
			}

			$referer = wp_get_referer();
			wp_safe_redirect( $referer ? $referer : $page_url );
			exit;
		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to clear page cache: ' . $e->getMessage(), array( 'page_url' => $page_url ) );
			if ( wp_doing_ajax() ) {
				wp_send_json_error( $e->getMessage() );
			}
			wp_die(
				esc_html__( 'Failed to clear page cache. Please try again.', 'performance-optimisation' )
			);
		}
	}

	/**
	 * Get current optimization status.
	 *
	 * @return array Status information.
	 */
	private function get_optimization_status(): array {
		$settings = $this->get_settings();

		$active_optimizations = 0;
		$total_optimizations  = 0;

		// Check various optimization settings.
		$optimization_checks = array(
			'minification'       => $settings['minification']['enable_css_minification'] ?? false,
			'caching'            => $settings['caching']['enable_page_caching'] ?? false,
			'image_optimization' => $settings['image_optimization']['enable_webp_conversion'] ?? false,
			'lazy_loading'       => $settings['lazy_loading']['enable_image_lazy_loading'] ?? false,
		);

		foreach ( $optimization_checks as $check ) {
			++$total_optimizations;
			if ( $check ) {
				++$active_optimizations;
			}
		}

		$percentage = $total_optimizations > 0 ? ( $active_optimizations / $total_optimizations ) * 100 : 0;

		if ( $percentage >= 75 ) {
			return array(
				'status' => 'good',
				'label'  => __( 'Optimized', 'performance-optimisation' ),
			);
		} elseif ( $percentage >= 50 ) {
			return array(
				'status' => 'warning',
				'label'  => __( 'Partial', 'performance-optimisation' ),
			);
		} else {
			return array(
				'status' => 'error',
				'label'  => __( 'Needs Setup', 'performance-optimisation' ),
			);
		}
	}

	/**
	 * Safely get settings with error handling and defaults.
	 *
	 * @return array Settings or default values.
	 */
	private function get_settings(): array {
		if ( ! $this->settings_service ) {
			$this->logger->error( 'Settings service not available' );
			return $this->get_default_settings();
		}

		try {
			return $this->settings_service->get_settings();
		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to get settings: ' . $e->getMessage() );
			return $this->getDefaultSettings();
		}
	}

	/**
	 * Get default settings when service is unavailable.
	 *
	 * @return array Default settings.
	 */
	private function get_default_settings(): array {
		return array(
			'minification'       => array( 'enable_css_minification' => false ),
			'caching'            => array( 'enable_page_caching' => false ),
			'image_optimization' => array( 'enable_webp_conversion' => false ),
			'lazy_loading'       => array( 'enable_image_lazy_loading' => false ),
		);
	}

	/**
	 * Safely get cache statistics with error handling.
	 *
	 * @return array Cache statistics or error information.
	 */
	private function get_cache_stats(): array {
		try {
			if ( ! $this->cache_service ) {
				throw new \Exception( 'Cache service unavailable' );
			}

			$cache_stats = $this->cache_service->get_cache_stats();

			// Ensure we have a formatted size.
			if ( isset( $cache_stats['total_size'] ) && ! isset( $cache_stats['total_size_formatted'] ) ) {
				$cache_stats['total_size_formatted'] = size_format( $cache_stats['total_size'], 2 );
			} elseif ( ! isset( $cache_stats['total_size_formatted'] ) ) {
				$cache_stats['total_size_formatted'] = '0 B';
			}

			return $cache_stats;
		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to get cache stats: ' . $e->getMessage() );
			return array(
				'total_size_formatted' => '0 B',
				'error'                => $e->getMessage(),
			);
		}
	}

	/**
	 * AJAX handler to get cache stats.
	 *
	 * @return void
	 */
	public function ajax_get_cache_stats(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions', 403 );
		}

		$cache_stats = $this->get_cache_stats();

		if ( isset( $cache_stats['error'] ) ) {
			wp_send_json_error( $cache_stats['error'] );
		}

		wp_send_json_success( $cache_stats );
	}

	/**
	 * Get comprehensive admin data for JavaScript.
	 *
	 * @return array Admin data.
	 */
	private function get_admin_data(): array {
		$cache_stats         = $this->get_cache_stats();
		$optimization_status = $this->get_optimization_status();

		return array(
			'apiUrl'             => rest_url( 'performance-optimisation/v1' ),
			'nonce'              => wp_create_nonce( 'wp_rest' ),
			'settings'           => $this->get_settings(),
			'cacheStats'         => $cache_stats,
			'optimizationStatus' => $optimization_status,
			'capabilities'       => array(
				'manage_options' => current_user_can( 'manage_options' ),
				'edit_posts'     => current_user_can( 'edit_posts' ),
			),
			'urls'               => array(
				'admin'       => admin_url( 'admin.php?page=performance-optimisation' ),
				'wizard'      => admin_url( 'admin.php?page=performance-optimisation-setup' ),
				'clear_cache' => wp_nonce_url(
					admin_url( 'admin-ajax.php?action=wppo_clear_all_cache' ),
					'wppo_clear_cache'
				),
			),
			'i18n'               => array(
				'clearingCache' => __( 'Clearing cache...', 'performance-optimisation' ),
				'cacheCleared'  => __( 'Cache cleared successfully!', 'performance-optimisation' ),
				'error'         => __( 'An error occurred. Please try again.', 'performance-optimisation' ),
			),
		);
	}

	/**
	 * Get wizard-specific data for JavaScript.
	 *
	 * @return array Wizard data.
	 */
	private function get_wizard_data(): array {
		return array(
			'apiUrl'          => rest_url( 'performance-optimisation/v1' ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'currentStep'     => get_option( 'wppo_wizard_current_step', 1 ),
			'serverInfo'      => $this->get_server_info(),
			'recommendations' => $this->get_optimization_recommendations(),
			'urls'            => array(
				'admin'    => admin_url( 'admin.php?page=performance-optimisation' ),
				'complete' => admin_url( 'admin.php?page=performance-optimisation&wizard=completed' ),
			),
			'i18n'            => array(
				'next'     => __( 'Next', 'performance-optimisation' ),
				'previous' => __( 'Previous', 'performance-optimisation' ),
				'complete' => __( 'Complete Setup', 'performance-optimisation' ),
				'skip'     => __( 'Skip', 'performance-optimisation' ),
			),
		);
	}

	/**
	 * Get server information for wizard.
	 *
	 * @return array Server information.
	 */
	private function get_server_info(): array {
		return array(
			'php_version'         => PHP_VERSION,
			'wp_version'          => get_bloginfo( 'version' ),
			'memory_limit'        => ini_get( 'memory_limit' ),
			'max_execution_time'  => ini_get( 'max_execution_time' ),
			'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
			'extensions'          => array(
				'gd'      => extension_loaded( 'gd' ),
				'imagick' => extension_loaded( 'imagick' ),
				'curl'    => extension_loaded( 'curl' ),
				'zip'     => extension_loaded( 'zip' ),
			),
		);
	}

	/**
	 * Get optimization recommendations based on server capabilities.
	 *
	 * @return array Recommendations.
	 */
	private function get_optimization_recommendations(): array {
		$recommendations = array();
		$server_info     = $this->get_server_info();

		// Memory-based recommendations.
		$memory_limit = $this->parse_memory_limit( $server_info['memory_limit'] );
		if ( $memory_limit < 128 * 1024 * 1024 ) { // Less than 128MB.
			$recommendations[] = array(
				'type'    => 'warning',
				'title'   => __( 'Low Memory Limit', 'performance-optimisation' ),
				'message' => __(
					'Your server has a low memory limit. Consider enabling conservative optimization settings.',
					'performance-optimisation'
				),
			);
		}

		// Extension-based recommendations.
		if ( ! $server_info['extensions']['gd'] && ! $server_info['extensions']['imagick'] ) {
			$recommendations[] = array(
				'type'    => 'error',
				'title'   => __( 'No Image Processing Extension', 'performance-optimisation' ),
				'message' => __(
					'Neither GD nor ImageMagick is available. Image optimization will be limited.',
					'performance-optimisation'
				),
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
	private function parse_memory_limit( string $memory_limit ): int {
		$memory_limit = trim( $memory_limit );
		$last_char    = strtolower( $memory_limit[ strlen( $memory_limit ) - 1 ] );
		$number       = (int) $memory_limit;

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
