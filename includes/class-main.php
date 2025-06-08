<?php
/**
 * Performance Optimisation main functionality.
 *
 * This file includes the main class for the performance optimisation plugin,
 * which handles tasks like including necessary files, setting up hooks, and managing
 * image optimisation, JS and CSS minification, and more.
 *
 * @package PerformanceOptimise
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc;

use PerformanceOptimise\Inc\Minify;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main Class for Performance Optimisation.
 *
 * Handles the inclusion of necessary files, setup of hooks, and core functionalities
 * such as generating and invalidating dynamic static HTML.
 *
 * @since 1.0.0
 */
class Main {

	/**
	 * List of CSS handles to exclude from combining or minification.
	 * Updated by settings.
	 *
	 * @var array<string>
	 * @since 1.0.0
	 */
	private array $excluded_css_handles = array( 'wppo-combined-css' );

	/**
	 * List of JavaScript handles to exclude from minification.
	 * Updated by settings.
	 *
	 * @var array<string>
	 * @since 1.0.0
	 */
	private array $excluded_js_handles = array( 'jquery', 'jquery-core', 'jquery-migrate' );

	/**
	 * Filesystem instance for file operations.
	 *
	 * @var \WP_Filesystem_Base|null
	 * @since 1.0.0
	 */
	private ?\WP_Filesystem_Base $filesystem;

	/**
	 * Image Optimisation instance for handling image optimization.
	 *
	 * @var Image_Optimisation|null
	 * @since 1.0.0
	 */
	private ?Image_Optimisation $image_optimisation = null;

	/**
	 * Options for performance optimisation settings.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.0
	 */
	private array $options;

	/**
	 * Suffix for the admin page hook.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private string $admin_page_hook_suffix = '';


	/**
	 * Constructor.
	 *
	 * Initializes the class by including necessary files and setting up hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->options = get_option( 'wppo_settings', array() );

		$this->load_dependencies();
		$this->setup_hooks();
		$this->filesystem = Util::init_filesystem();

		if ( ! empty( $this->options['image_optimisation']['convertImg'] ) || ! empty( $this->options['image_optimisation']['lazyLoadImages'] ) ) {
			$this->image_optimisation = new Image_Optimisation( $this->options );
		}
	}

	/**
	 * Load required dependencies (classes).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function load_dependencies(): void {
		$base_path = WPPO_PLUGIN_PATH . 'includes/';
		if ( file_exists( WPPO_PLUGIN_PATH . 'vendor/autoload.php' ) ) {
			require_once WPPO_PLUGIN_PATH . 'vendor/autoload.php';
		}

		$classes = array(
			'class-util.php',
			'class-log.php',
			'minify/class-css.php',
			'minify/class-js.php',
			'minify/class-html.php',
			'class-cache.php',
			'class-img-converter.php',
			'class-image-optimisation.php',
			'class-metabox.php',
			'class-cron.php',
			'class-rest.php',
			'class-advanced-cache-handler.php',
		);

		foreach ( $classes as $file ) {
			if ( file_exists( $base_path . $file ) ) {
				require_once $base_path . $file;
			}
		}
	}

	/**
	 * Setup WordPress hooks.
	 *
	 * Registers actions and filters used by the plugin.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function setup_hooks(): void {
		add_action( 'admin_menu', array( $this, 'init_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_bar_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_scripts' ) );

		add_filter( 'script_loader_tag', array( $this, 'modify_script_loader_tag' ), 10, 3 );
		add_filter( 'style_loader_tag', array( $this, 'modify_style_loader_tag' ), 10, 3 );

		add_action( 'admin_bar_menu', array( $this, 'add_settings_to_admin_bar' ), 100 );
		add_action( 'wp_head', array( $this, 'add_preload_prefetch_preconnect_links' ), 1 );

		if ( ! empty( $this->options['file_optimisation']['removeWooCSSJS'] ) && (bool) $this->options['file_optimisation']['removeWooCSSJS'] ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'conditionally_remove_woocommerce_assets' ), 999 );
		}

		$cache_manager = new Cache();
		add_action( 'template_redirect', array( $cache_manager, 'generate_dynamic_static_html' ), 5 );
		add_action( 'save_post', array( $cache_manager, 'invalidate_dynamic_static_html' ) );

		if ( ! empty( $this->options['file_optimisation']['combineCSS'] ) && (bool) $this->options['file_optimisation']['combineCSS'] ) {
			add_action( 'wp_print_styles', array( $cache_manager, 'combine_css' ), PHP_INT_MAX - 10 );
		}

		$rest_api_handler = new Rest();
		add_action( 'rest_api_init', array( $rest_api_handler, 'register_routes' ) );

		new Metabox();
		new Cron();
	}

	/**
	 * Initialize the admin menu and associated asset loading hook.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
	 * @return void
	 */
	public function render_admin_page(): void {
		include WPPO_PLUGIN_PATH . 'templates/app.php';
	}

	/**
	 * Enqueue scripts and styles for the admin bar menu enhancements.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
	 * @return void
	 */
	public function load_plugin_admin_page_assets(): void {
		$asset_file_path = WPPO_PLUGIN_PATH . 'build/index.asset.php';
		$asset_data      = file_exists( $asset_file_path ) ? include $asset_file_path : array(
			'dependencies' => array( 'wp-element', 'wp-components', 'wp-i18n' ),
			'version'      => WPPO_VERSION,
		);

		wp_enqueue_style(
			'performance-optimisation-admin-style',
			WPPO_PLUGIN_URL . 'assets/js/style-index.css',
			array(),
			$asset_data['version']
		);
		wp_enqueue_script(
			'performance-optimisation-admin-script',
			WPPO_PLUGIN_URL . 'assets/js/index.js',
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
	 * Enqueues frontend scripts, like lazyload.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_frontend_scripts(): void {
		$lazyload_images_enabled = ! empty( $this->options['image_optimisation']['lazyLoadImages'] ) && (bool) $this->options['image_optimisation']['lazyLoadImages'];
		$lazyload_videos_enabled = ! empty( $this->options['image_optimisation']['lazyLoadVideos'] ) && (bool) $this->options['image_optimisation']['lazyLoadVideos'];

		if ( ( $lazyload_images_enabled || $lazyload_videos_enabled ) && ! is_admin() && ! is_user_logged_in() ) {
			wp_enqueue_script(
				'wppo-lazyload',
				WPPO_PLUGIN_URL . 'assets/js/lazyload.js',
				array(),
				WPPO_VERSION,
				true
			);
		}
	}

	/**
	 * Removes WooCommerce-related scripts and styles on non-WooCommerce pages based on settings.
	 *
	 * @since 1.0.0
	 */
	public function conditionally_remove_woocommerce_assets(): void {
		if ( ! class_exists( 'WooCommerce' ) || is_woocommerce() || is_cart() || is_checkout() || is_account_page() ) {
			return;
		}

		$exclude_urls_from_removal = array();
		if ( ! empty( $this->options['file_optimisation']['excludeUrlToKeepJSCSS'] ) ) {
			$exclude_urls_from_removal = Util::process_urls( (string) $this->options['file_optimisation']['excludeUrlToKeepJSCSS'] );
		}

		$current_request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$current_page_url    = home_url( $current_request_uri );
		$current_page_url    = rtrim( $current_page_url, '/' );

		foreach ( $exclude_urls_from_removal as $exclude_url_pattern ) {
			$exclude_url_pattern = rtrim( $exclude_url_pattern, '/' );
			if ( 0 !== strpos( $exclude_url_pattern, 'http' ) ) {
				$exclude_url_pattern = home_url( $exclude_url_pattern );
				$exclude_url_pattern = rtrim( $exclude_url_pattern, '/' );
			}

			if ( str_ends_with( $exclude_url_pattern, '(.*)' ) ) {
				$base_pattern = rtrim( str_replace( '(.*)', '', $exclude_url_pattern ), '/' );
				if ( 0 === strpos( $current_page_url, $base_pattern ) ) {
					return;
				}
			} elseif ( $current_page_url === $exclude_url_pattern ) {
				return;
			}
		}

		$handles_to_remove_config = $this->options['file_optimisation']['removeCssJsHandle'] ?? '';
		$handles_to_remove        = Util::process_urls( (string) $handles_to_remove_config );

		if ( ! empty( $handles_to_remove ) ) {
			foreach ( $handles_to_remove as $handle_directive ) {
				if ( str_starts_with( $handle_directive, 'style:' ) ) {
					$handle = trim( str_replace( 'style:', '', $handle_directive ) );
					wp_dequeue_style( $handle );
				} elseif ( str_starts_with( $handle_directive, 'script:' ) ) {
					$handle = trim( str_replace( 'script:', '', $handle_directive ) );
					wp_dequeue_script( $handle );
				}
			}
		} else {
			$default_woo_handles = array(
				'style:woocommerce-layout',
				'style:woocommerce-smallscreen',
				'style:woocommerce-general',
				'script:wc-cart-fragments',
				'script:woocommerce',
				'script:wc-add-to-cart',
			);
			foreach ( $default_woo_handles as $handle_directive ) {
				if ( str_starts_with( $handle_directive, 'style:' ) ) {
					wp_dequeue_style( trim( str_replace( 'style:', '', $handle_directive ) ) );
				} elseif ( str_starts_with( $handle_directive, 'script:' ) ) {
					wp_dequeue_script( trim( str_replace( 'script:', '', $handle_directive ) ) );
				}
			}
		}
	}

	/**
	 * Adds custom settings to the WordPress admin bar.
	 *
	 * @since 1.0.0
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


	/**
	 * Modifies script loader tag for defer, delay, and minification.
	 *
	 * @since 1.0.0
	 * @param string $tag    The <script> tag for the enqueued script.
	 * @param string $handle The script's registered handle.
	 * @param string $src    The script's source URL.
	 * @return string Modified script tag.
	 */
	public function modify_script_loader_tag( string $tag, string $handle, string $src ): string {
		if ( is_user_logged_in() || is_admin() ) {
			return $tag;
		}

		$minify_js_enabled = ! empty( $this->options['file_optimisation']['minifyJS'] ) && (bool) $this->options['file_optimisation']['minifyJS'];
		if ( $minify_js_enabled && ! empty( $src ) && ! $this->is_handle_excluded( $handle, 'js' ) && ! $this->is_already_minified( $src, 'js' ) ) {
			$minifier     = new Minify\JS( Util::get_local_path( $src ), wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/min/js' ) );
			$minified_url = $minifier->minify();
			if ( $minified_url ) {
				$minified_local_path = Util::get_local_path( $minified_url );
				if ( $this->filesystem && $this->filesystem->exists( $minified_local_path ) ) {
					$version = (string) $this->filesystem->mtime( $minified_local_path );
					$new_src = esc_url( add_query_arg( 'ver', $version, $minified_url ) );
					$tag     = str_replace( esc_url( $src ), $new_src, $tag ); // Replace original src with minified.
					$src     = $new_src; // Update src for subsequent defer/delay logic.
				}
			}
		}

		$defer_js_enabled = ! empty( $this->options['file_optimisation']['deferJS'] ) && (bool) $this->options['file_optimisation']['deferJS'];
		if ( $defer_js_enabled && ! $this->is_handle_excluded( $handle, 'defer_js' ) ) {
			if ( strpos( $tag, 'type="module"' ) === false ) {
				$tag = str_replace( ' src=', ' defer src=', $tag );
			}
		}

		$delay_js_enabled = ! empty( $this->options['file_optimisation']['delayJS'] ) && (bool) $this->options['file_optimisation']['delayJS'];
		if ( $delay_js_enabled && ! $this->is_handle_excluded( $handle, 'delay_js' ) ) {
			if ( strpos( $tag, ' src=' ) !== false ) {
				$tag = str_replace( ' src=', ' data-wppo-src=', $tag );
			}
			$original_type = 'text/javascript';
			if ( preg_match( '/type=(["\'])(.*?)\1/', $tag, $type_match ) ) {
				$original_type = $type_match[2];
				$tag           = str_replace( $type_match[0], '', $tag ); // Remove original type attribute.
			}
			$tag = str_replace( '<script', '<script type="wppo/javascript" data-wppo-type="' . esc_attr( $original_type ) . '"', $tag );
		}

		return $tag;
	}

	/**
	 * Modifies style loader tag for minification.
	 *
	 * @since 1.0.0
	 * @param string $tag    The <link> tag for the enqueued style.
	 * @param string $handle The style's registered handle.
	 * @param string $href   The style's source URL.
	 * @return string Modified style tag.
	 */
	public function modify_style_loader_tag( string $tag, string $handle, string $href ): string {
		if ( is_user_logged_in() || is_admin() || empty( $href ) ) {
			return $tag;
		}

		$minify_css_enabled = ! empty( $this->options['file_optimisation']['minifyCSS'] ) && (bool) $this->options['file_optimisation']['minifyCSS'];

		if ( $minify_css_enabled && 'wppo-combined-css' !== $handle && ! $this->is_handle_excluded( $handle, 'css' ) && ! $this->is_already_minified( $href, 'css' ) ) {
			$minifier     = new Minify\CSS( Util::get_local_path( $href ), wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/min/css' ) );
			$minified_url = $minifier->minify();

			if ( $minified_url ) {
				$minified_local_path = Util::get_local_path( $minified_url );
				if ( $this->filesystem && $this->filesystem->exists( $minified_local_path ) ) {
					$version  = (string) $this->filesystem->mtime( $minified_local_path );
					$new_href = esc_url( add_query_arg( 'ver', $version, $minified_url ) );
					$tag      = str_replace( esc_url( $href ), $new_href, $tag );
				}
			}
		}
		return $tag;
	}

	/**
	 * Checks if a handle is excluded based on settings.
	 *
	 * @param string $handle Handle of the script/style.
	 * @param string $type   Type of exclusion list ('js', 'css', 'combine_css', 'defer_js', 'delay_js').
	 * @return bool True if excluded, false otherwise.
	 */
	private function is_handle_excluded( string $handle, string $type ): bool {
		$setting_key_map = array(
			'js'          => 'excludeJS',
			'css'         => 'excludeCSS',
			'combine_css' => 'excludeCombineCSS',
			'defer_js'    => 'excludeDeferJS',
			'delay_js'    => 'excludeDelayJS',
		);

		if ( ! isset( $setting_key_map[ $type ] ) ) {
			return false;
		}
		$setting_key = $setting_key_map[ $type ];

		$default_exclusions = array();
		if ( 'js' === $type || 'defer_js' === $type || 'delay_js' === $type ) {
			$default_exclusions = $this->excluded_js_handles;
			if ( 'defer_js' === $type || 'delay_js' === $type ) {
				$default_exclusions[] = 'wppo-lazyload'; // Lazyload script should not be deferred/delayed.
			}
		} elseif ( 'css' === $type || 'combine_css' === $type ) {
			$default_exclusions = $this->excluded_css_handles;
		}

		$user_exclusions_string = $this->options['file_optimisation'][ $setting_key ] ?? '';
		$user_exclusions        = Util::process_urls( (string) $user_exclusions_string );

		$all_exclusions = array_unique( array_merge( $default_exclusions, $user_exclusions ) );

		if ( in_array( $handle, $all_exclusions, true ) ) {
			return true;
		}

		global $wp_scripts, $wp_styles;
		$asset_src = '';
		if ( 'js' === $type || 'defer_js' === $type || 'delay_js' === $type ) {
			if ( isset( $wp_scripts->registered[ $handle ] ) ) {
				$asset_src = $wp_scripts->registered[ $handle ]->src;
			}
		} elseif ( 'css' === $type || 'combine_css' === $type ) {
			if ( isset( $wp_styles->registered[ $handle ] ) ) {
				$asset_src = $wp_styles->registered[ $handle ]->src;
			}
		}

		if ( $asset_src ) {
			foreach ( $all_exclusions as $exclusion_pattern ) {
				if ( str_contains( $asset_src, $exclusion_pattern ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Checks if a CSS/JS file URL suggests it's already minified.
	 *
	 * @since 1.0.0
	 * @param string $url  Path or URL to the asset file.
	 * @param string $type 'css' or 'js'.
	 * @return bool True if the file seems minified, false otherwise.
	 */
	private function is_already_minified( string $url, string $type ): bool {
		$file_name = basename( wp_parse_url( $url, PHP_URL_PATH ) );

		if ( preg_match( '/(\.min\.|\.bundle\.|\-min\.)' . $type . '$/i', $file_name ) ) {
			return true;
		}

		if ( str_contains( $url, '/cache/wppo/min/' ) ) {
			return true;
		}

		if ( $this->filesystem ) {
			$local_path = Util::get_local_path( $url );
			if ( $this->filesystem->exists( $local_path ) && $this->filesystem->is_readable( $local_path ) ) {
				$content = $this->filesystem->get_contents( $local_path );
				if ( $content ) {
					$lines = preg_split( '/\r\n|\r|\n/', $content );
					if ( count( $lines ) <= 10 ) {
						return true;
					}
				}
			}
		}

		return false;
	}


	/**
	 * Adds preload, prefetch, and preconnect links to the <head>.
	 *
	 * @since 1.0.0
	 */
	public function add_preload_prefetch_preconnect_links(): void {
		if ( is_admin() ) {
			return;
		}

		$preload_settings = $this->options['preload_settings'] ?? array();

		if ( ! empty( $preload_settings['preconnect'] ) && ! empty( $preload_settings['preconnectOrigins'] ) ) {
			$origins = Util::process_urls( (string) $preload_settings['preconnectOrigins'] );
			foreach ( $origins as $origin ) {
				if ( filter_var( $origin, FILTER_VALIDATE_URL ) ) {
					Util::generate_preload_link( $origin, 'preconnect', '', true ); // True for crossorigin.
				}
			}
		}

		if ( ! empty( $preload_settings['prefetchDNS'] ) && ! empty( $preload_settings['dnsPrefetchOrigins'] ) ) {
			$origins = Util::process_urls( (string) $preload_settings['dnsPrefetchOrigins'] );
			foreach ( $origins as $origin ) {
				$host = wp_parse_url( $origin, PHP_URL_HOST );
				if ( empty( $host ) && filter_var( 'http://' . $origin, FILTER_VALIDATE_URL ) ) {
					$host = $origin;
				}
				if ( $host ) {
					Util::generate_preload_link( '//' . $host, 'dns-prefetch' );
				}
			}
		}

		if ( ! empty( $preload_settings['preloadFonts'] ) && ! empty( $preload_settings['preloadFontsUrls'] ) ) {
			$font_urls = Util::process_urls( (string) $preload_settings['preloadFontsUrls'] );
			foreach ( $font_urls as $font_url ) {
				$absolute_font_url = $font_url;
				if ( ! preg_match( '/^https?:\/\//i', $font_url ) ) {
					$absolute_font_url = content_url( ltrim( $font_url, '/' ) ); // Assume relative to content dir.
				}
				$font_extension = strtolower( pathinfo( wp_parse_url( $absolute_font_url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
				$font_mime_type = '';
				switch ( $font_extension ) {
					case 'woff2':
						$font_mime_type = 'font/woff2';
						break;
					case 'woff':
						$font_mime_type = 'font/woff';
						break;
					case 'ttf':
						$font_mime_type = 'font/ttf';
						break;
					case 'otf':
						$font_mime_type = 'font/otf';
						break;
					case 'eot':
						$font_mime_type = 'application/vnd.ms-fontobject';
						break;
				}
				if ( ! empty( $font_mime_type ) ) {
					Util::generate_preload_link( $absolute_font_url, 'preload', 'font', true, $font_mime_type );
				}
			}
		}

		if ( ! empty( $preload_settings['preloadCSS'] ) && ! empty( $preload_settings['preloadCSSUrls'] ) ) {
			$css_urls = Util::process_urls( (string) $preload_settings['preloadCSSUrls'] );
			foreach ( $css_urls as $css_url ) {
				$absolute_css_url = $css_url;
				if ( ! preg_match( '/^https?:\/\//i', $css_url ) ) {
					$absolute_css_url = content_url( ltrim( $css_url, '/' ) );
				}
				Util::generate_preload_link( $absolute_css_url, 'preload', 'style' );
			}
		}

		if ( $this->image_optimisation ) {
			$this->image_optimisation->preload_images_on_page_load();
		}
	}
}
