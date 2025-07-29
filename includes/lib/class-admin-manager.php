<?php
/**
 * Admin Manager for Performance Optimisation.
 *
 * @package PerformanceOptimise
 * @since 2.0.0
 */

namespace PerformanceOptimise\Inc\Refactor;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Manager
 *
 * @package PerformanceOptimise\Inc\Refactor
 */
class Admin_Manager {

	/**
	 * Options for performance optimisation settings.
	 *
	 * @var array<string, mixed>
	 * @since 2.0.0
	 */
	private array $options;

	/**
	 * Suffix for the admin page hook.
	 *
	 * @var string
	 * @since 2.0.0
	 */
	private string $admin_page_hook_suffix = '';

	/**
	 * Admin_Manager constructor.
	 *
	 * @param array<string, mixed> $options The plugin options.
	 */
	public function __construct( array $options ) {
		$this->options = $options;
	}

	/**
	 * Register hooks for admin functionality.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'init_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_bar_scripts' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_settings_to_admin_bar' ), 100 );
	}

	/**
	 * Initialize the admin menu and associated asset loading hook.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function init_admin_menu(): void {
		$this->admin_page_hook_suffix = add_menu_page(
			__( 'Performance Optimisation', 'performance-optimisation' ),
			__( 'Performance Optimisation', 'performance-optimisation' ),
			'manage_options',
			'performance-optimisation',
			array( $this, 'render_admin_page' ),
			'dashicons-performance',
			2
		);
		add_action( "load-{$this->admin_page_hook_suffix}", array( $this, 'load_plugin_admin_page_assets' ) );
	}

	/**
	 * Display the admin page.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function render_admin_page(): void {
		echo '<div id="wppo-admin-app"></div>';
	}

	/**
	 * Enqueue scripts and styles for the admin bar menu enhancements.
	 *
	 * @since 2.0.0
	 */
	public function enqueue_admin_bar_scripts(): void {
		if ( is_admin_bar_showing() && current_user_can( 'manage_options' ) ) {
			wp_enqueue_script(
				'wppo-admin-bar-script',
				WPPO_PLUGIN_URL . 'assets/js/admin-bar.js',
				array( 'jquery' ),
				WPPO_VERSION,
				true
			);
			wp_localize_script(
				'wppo-admin-bar-script',
				'wppoAdminBar',
				array(
					'apiUrl'   => esc_url_raw( rest_url( Rest::NAMESPACE ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'pageId'   => is_singular() ? get_the_ID() : 0,
					'pagePath' => is_singular() ? esc_js( trim( wp_parse_url( get_permalink(), PHP_URL_PATH ), '/' ) ) : '',
					'i18n'     => array(
						'clearPageCache'   => __( 'Clear Cache for This Page', 'performance-optimisation' ),
						'clearAllCache'    => __( 'Clear All Cache', 'performance-optimisation' ),
						'cacheCleared'     => __( 'Cache cleared successfully.', 'performance-optimisation' ),
						'cacheClearError'  => __( 'Error clearing cache.', 'performance-optimisation' ),
						'confirmClearPage' => __( 'Are you sure you want to clear the cache for this page?', 'performance-optimisation' ),
						'confirmClearAll'  => __( 'Are you sure you want to clear ALL cache? This includes HTML pages and minified assets.', 'performance-optimisation' ),
					),
				)
			);
		}
	}


	/**
	 * Enqueue scripts and styles for the plugin's admin settings page.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function load_plugin_admin_page_assets(): void {
		$asset_file_path = WPPO_PLUGIN_PATH . 'build/redesign.asset.php';
		$asset_data      = file_exists( $asset_file_path ) ? include $asset_file_path : array(
			'dependencies' => array( 'wp-element', 'wp-components', 'wp-i18n' ),
			'version'      => WPPO_VERSION,
		);

		wp_enqueue_style(
			'performance-optimisation-admin-style',
			WPPO_PLUGIN_URL . 'assets/js/redesign.css',
			array(),
			$asset_data['version']
		);
		wp_enqueue_script(
			'performance-optimisation-admin-script',
			WPPO_PLUGIN_URL . 'assets/js/redesign.js',
			$asset_data['dependencies'],
			$asset_data['version'],
			true
		);

		$current_options = get_option( 'wppo_settings', array() );
		$image_info      = get_option( 'wppo_img_info', array() );

		$public_post_types    = get_post_types( array( 'public' => true ), 'objects' );
		$available_post_types = array();
		$excluded_post_types  = array( 'attachment', 'revision', 'nav_menu_item' ); // Common exclusions.
		foreach ( $public_post_types as $slug => $pt_object ) {
			if ( ! in_array( $slug, $excluded_post_types, true ) ) {
				$available_post_types[] = array(
					'value' => $slug,
					'label' => $pt_object->label,
				);
			}
		}
		$ui_options_data = array( 'availablePostTypes' => $available_post_types );

		wp_localize_script(
			'performance-optimisation-admin-script',
			'wppoAdminData',
			array(
				'apiUrl'         => esc_url_raw( rest_url( Rest::NAMESPACE . '/' ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'settings'       => $current_options,
				'imageInfo'      => $image_info,
				'cacheSize'      => Cache::get_cache_size(),
				'minifiedAssets' => Util::get_js_css_minified_file(),
				'uiData'         => $ui_options_data,
				'pluginVersion'  => WPPO_VERSION,
				'translations'   => $this->get_javascript_translations(),
			)
		);
		wp_enqueue_style( 'wp-components' );
	}

	/**
	 * Provides a centralized list of translations for JavaScript.
	 *
	 * @return array<string,string> Key-value pairs of translatable strings.
	 */
	private function get_javascript_translations(): array {
		return array(
			'performanceSettings'    => __( 'Performance Settings', 'performance-optimisation' ),
			'dashboard'              => __( 'Dashboard', 'performance-optimisation' ),
			'fileOptimization'       => __( 'File Optimization', 'performance-optimisation' ),
			'imageOptimization'      => __( 'Image Optimization', 'performance-optimisation' ),
			'preloadSettings'        => __( 'Preload & Preconnect', 'performance-optimisation' ),
			'tools'                  => __( 'Tools', 'performance-optimisation' ),
			'activityLog'            => __( 'Activity Log', 'performance-optimisation' ),
			'saving'                 => __( 'Saving...', 'performance-optimisation' ),
			'saveSettings'           => __( 'Save Settings', 'performance-optimisation' ),
			'settingsSaved'          => __( 'Settings saved successfully.', 'performance-optimisation' ),
			'errorSavingSettings'    => __( 'Error saving settings.', 'performance-optimisation' ),
			'minifyJS'               => __( 'Minify JavaScript Files', 'performance-optimisation' ),
			'minifyCSS'              => __( 'Minify CSS Files', 'performance-optimisation' ),
			'minifyInlineJS'         => __( 'Minify Inline JavaScript', 'performance-optimisation' ),
			'minifyInlineCSS'        => __( 'Minify Inline CSS', 'performance-optimisation' ),
			'combineCSS'             => __( 'Combine CSS Files', 'performance-optimisation' ),
			'minifyHTML'             => __( 'Minify HTML Output', 'performance-optimisation' ),
			'deferJS'                => __( 'Defer Non-Essential JavaScript', 'performance-optimisation' ),
			'delayJS'                => __( 'Delay JavaScript Execution', 'performance-optimisation' ),
			'removeWooCSSJS'         => __( 'Remove WooCommerce Assets on Non-Woo Pages', 'performance-optimisation' ),
			'excludeLabel'           => __( 'Exclude (handles, keywords, or URLs - one per line):', 'performance-optimisation' ),
			'lazyLoadImages'         => __( 'Lazy Load Images', 'performance-optimisation' ),
			'lazyLoadVideos'         => __( 'Lazy Load Videos (iframes/video tags)', 'performance-optimisation' ),
			'excludeFirstNImages'    => __( 'Exclude First N Images from Lazy Load:', 'performance-optimisation' ),
			'replaceImgToSVG'        => __( 'Use SVG Placeholders for Lazy Loaded Images', 'performance-optimisation' ),
			'convertImg'             => __( 'Enable Next-Gen Image Conversion (WebP/AVIF)', 'performance-optimisation' ),
			'conversionFormat'       => __( 'Preferred Conversion Format:', 'performance-optimisation' ),
			'webp'                   => __( 'WebP Only', 'performance-optimisation' ),
			'avif'                   => __( 'AVIF Only (if supported, else WebP)', 'performance-optimisation' ),
			'both'                   => __( 'Both (Serve AVIF if supported, else WebP)', 'performance-optimisation' ),
			'imgBatchSize'           => __( 'Image Conversion Batch Size (per cron run):', 'performance-optimisation' ),
			'preloadFrontPageImg'    => __( 'Preload Critical Images on Front Page', 'performance-optimisation' ),
			'preloadPostTypeImg'     => __( 'Preload Featured Images for Post Types', 'performance-optimisation' ),
			'selectPostTypes'        => __( 'Select Post Types:', 'performance-optimisation' ),
			'maxWidthImgSize'        => __( 'Max Width for Preloaded Srcset Images (px):', 'performance-optimisation' ),
			'enablePreloadCache'     => __( 'Enable Page Preloading (Static Cache Generation)', 'performance-optimisation' ),
			'enableCronJobs'         => __( 'Enable Plugin Cron Jobs (for preloading & image conversion)', 'performance-optimisation' ),
			'preconnect'             => __( 'Preconnect to External Domains', 'performance-optimisation' ),
			'prefetchDNS'            => __( 'Prefetch DNS for External Domains', 'performance-optimisation' ),
			'preloadFonts'           => __( 'Preload Fonts', 'performance-optimisation' ),
			'preloadCSS'             => __( 'Preload CSS Files', 'performance-optimisation' ),
			'clearCacheNow'          => __( 'Clear All Cache Now', 'performance-optimisation' ),
			'clearingCache'          => __( 'Clearing Cache...', 'performance-optimisation' ),
			'optimiseImagesNow'      => __( 'Optimize Pending Images Now', 'performance-optimisation' ),
			'optimizingImages'       => __( 'Optimizing Images...', 'performance-optimisation' ),
			'noPendingImages'        => __( 'No pending images to optimize.', 'performance-optimisation' ),
			'imagesOptimized'        => __( 'Image optimization process initiated.', 'performance-optimisation' ),
			'deleteOptimizedImages'  => __( 'Delete All Converted Images', 'performance-optimisation' ),
			'deletingImages'         => __( 'Deleting Images...', 'performance-optimisation' ),
			'imagesDeleted'          => __( 'Converted images deleted.', 'performance-optimisation' ),
			'confirmDeleteOptimized' => __( 'Are you sure you want to delete all converted WebP/AVIF images? Original images will not be affected.', 'performance-optimisation' ),
			'importSettings'         => __( 'Import Settings', 'performance-optimisation' ),
			'exportSettings'         => __( 'Export Settings', 'performance-optimisation' ),
			'importDesc'             => __( 'Import plugin settings from a JSON file.', 'performance-optimisation' ),
			'exportDesc'             => __( 'Export current plugin settings to a JSON file.', 'performance-optimisation' ),
			'selectJsonFile'         => __( 'Select JSON file', 'performance-optimisation' ),
			'importing'              => __( 'Importing...', 'performance-optimisation' ),
			'settingsImported'       => __( 'Settings imported successfully.', 'performance-optimisation' ),
			'errorImporting'         => __( 'Error importing settings.', 'performance-optimisation' ),
			'cacheStatus'            => __( 'Cache Status', 'performance-optimisation' ),
			'currentCacheSize'       => __( 'Current Cache Size:', 'performance-optimisation' ),
			'minifiedFiles'          => __( 'Minified Files', 'performance-optimisation' ),
			'jsFilesMinified'        => __( 'JavaScript Files Minified:', 'performance-optimisation' ),
			'cssFilesMinified'       => __( 'CSS Files Minified:', 'performance-optimisation' ),
			'imageConversionStatus'  => __( 'Image Conversion Status', 'performance-optimisation' ),
			'completed'              => __( 'Completed', 'performance-optimisation' ),
			'pending'                => __( 'Pending', 'performance-optimisation' ),
			'failed'                 => __( 'Failed', 'performance-optimisation' ),
			'skipped'                => __( 'Skipped', 'performance-optimisation' ),
			'recentActivities'       => __( 'Recent Activities', 'performance-optimisation' ),
			'loadingActivities'      => __( 'Loading activities...', 'performance-optimisation' ),
			'noActivities'           => __( 'No recent activities.', 'performance-optimisation' ),
			'loadMore'               => __( 'Load More', 'performance-optimisation' ),
		);
	}

	/**
	 * Adds custom settings to the WordPress admin bar.
	 *
	 * @since 2.0.0
	 * @param \WP_Admin_Bar $wp_admin_bar The WordPress admin bar object.
	 */
	public function add_settings_to_admin_bar( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'wppo_admin_bar_menu',
				'title' => '<span class="ab-icon dashicons-performance"></span>' . __( 'Perf Optimise', 'performance-optimisation' ),
				'href'  => admin_url( 'admin.php?page=performance-optimisation' ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => 'wppo_clear_all_cache',
				'parent' => 'wppo_admin_bar_menu',
				'title'  => __( 'Clear All Cache', 'performance-optimisation' ),
				'href'   => '#',
				'meta'   => array( 'class' => 'wppo-admin-bar-clear-all' ),
			)
		);

		if ( ! is_admin() && is_singular() ) {
			$wp_admin_bar->add_node(
				array(
					'id'     => 'wppo_clear_this_page_cache',
					'parent' => 'wppo_admin_bar_menu',
					'title'  => __( 'Clear Cache for This Page', 'performance-optimisation' ),
					'href'   => '#',
					'meta'   => array( 'class' => 'wppo-admin-bar-clear-this-page' ),
				)
			);
		}
	}
}
