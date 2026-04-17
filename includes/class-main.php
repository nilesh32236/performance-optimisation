<?php
/**
 * Performance Optimisation main functionality.
 *
 * This file includes the main class for the performance optimisation plugin,
 * which handles tasks like including necessary files, setting up hooks, and managing
 * image optimisation, JS and CSS minification, and more.
 *
 * @package PerformanceOptimise
 * @since   1.0.0
 */

namespace PerformanceOptimise\Inc;

use PerformanceOptimise\Inc\Minify;

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
	 * List of CSS handles to exclude from combining.
	 *
	 * @var   array
	 * @since 1.0.0
	 */
	private array $exclude_css = array( 'wppo-combine-css' );

	/**
	 * List of JavaScript handles to exclude from minification.
	 *
	 * @var   array
	 * @since 1.0.0
	 */
	private array $exclude_js = array(
		'jquery',
	);

	/**
	 * List of JavaScript handles/URLs to exclude from deferring.
	 *
	 * @var   array
	 * @since 1.1.1
	 */
	private array $exclude_defer_js = array();

	/**
	 * List of JavaScript handles/URLs to exclude from delaying.
	 *
	 * @var   array
	 * @since 1.1.1
	 */
	private array $exclude_delay_js = array();

	/**
	 * Filesystem instance for file operations.
	 *
	 * @var   object
	 * @since 1.0.0
	 */
	private $filesystem;

	/**
	 * Image Optimisation instance for handling image optimization.
	 *
	 * @var   Image_Optimisation
	 * @since 1.0.0
	 */
	private Image_Optimisation $image_optimisation;

	/**
	 * Options for performance optimisation settings.
	 *
	 * @var   array
	 * @since 1.0.0
	 */
	private $options;

	/**
	 * Constructor.
	 *
	 * Initializes the class by including necessary files and setting up hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->options = get_option(
			'wppo_settings',
			array(
				'file_optimisation'  => array(
					'enableServerRules' => false,
					'cdnURL'            => '',
				),
				'preload_settings'   => array(),
				'image_optimisation' => array(),
			)
		);

		$this->includes();
		$this->setup_hooks();
		$this->filesystem         = Util::init_filesystem();
		$this->image_optimisation = new Image_Optimisation( $this->options );

		$file_optimisation_opts = $this->options['file_optimisation'] ?? array();
		new Core_Tweaks( $file_optimisation_opts );
	}

	/**
	 * Include required files.
	 *
	 * Loads the autoloader and includes other class files needed for the plugin.
	 *
	 * @return void
	 * @since  1.0.0
	 */
	private function includes(): void {
		require_once WPPO_PLUGIN_PATH . 'vendor/autoload.php';
		require_once WPPO_PLUGIN_PATH . 'includes/class-log.php';
		require_once WPPO_PLUGIN_PATH . 'includes/class-util.php';
		require_once WPPO_PLUGIN_PATH . 'includes/minify/class-html.php';
		require_once WPPO_PLUGIN_PATH . 'includes/minify/class-css.php';
		require_once WPPO_PLUGIN_PATH . 'includes/minify/class-js.php';
		require_once WPPO_PLUGIN_PATH . 'includes/class-cache.php';
		require_once WPPO_PLUGIN_PATH . 'includes/class-metabox.php';
		require_once WPPO_PLUGIN_PATH . 'includes/class-image-optimisation.php';
		require_once WPPO_PLUGIN_PATH . 'includes/class-cron.php';
		require_once WPPO_PLUGIN_PATH . 'includes/class-rest.php';
		require_once WPPO_PLUGIN_PATH . 'includes/class-database-cleanup.php';
		require_once WPPO_PLUGIN_PATH . 'includes/class-asset-manager.php';
		require_once WPPO_PLUGIN_PATH . 'includes/class-htaccess-handler.php';
		require_once WPPO_PLUGIN_PATH . 'includes/class-core-tweaks.php';
		require_once WPPO_PLUGIN_PATH . 'includes/class-object-cache.php';

		if ( is_admin() ) {
			require_once WPPO_PLUGIN_PATH . 'includes/class-admin-notices.php';
			new Admin_Notices();
		}
	}

	/**
	 * Setup WordPress hooks.
	 *
	 * Registers actions and filters used by the plugin.
	 *
	 * @return void
	 * @since  1.0.0
	 */
	private function setup_hooks(): void {
		add_action( 'admin_menu', array( $this, 'init_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_filter( 'script_loader_tag', array( $this, 'add_defer_attribute' ), 10, 2 );
		add_action( 'admin_bar_menu', array( $this, 'add_setting_to_admin_bar' ), 100 );

		if ( isset( $this->options['file_optimisation']['removeWooCSSJS'] ) && (bool) $this->options['file_optimisation']['removeWooCSSJS'] ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'remove_woocommerce_scripts' ), 999 );
		}

		$cache = new Cache();
		add_action( 'template_redirect', array( $cache, 'generate_dynamic_static_html' ) );
		add_action( 'save_post', array( $cache, 'invalidate_dynamic_static_html' ) );
		if ( isset( $this->options['file_optimisation']['combineCSS'] ) && (bool) $this->options['file_optimisation']['combineCSS'] ) {
			add_action( 'wp_enqueue_scripts', array( $cache, 'combine_css' ), PHP_INT_MAX );
		}

		$rest = new Rest();
		add_action( 'rest_api_init', array( $rest, 'register_routes' ) );

		if ( isset( $this->options['file_optimisation']['minifyJS'] ) && (bool) $this->options['file_optimisation']['minifyJS'] ) {
			if ( isset( $this->options['file_optimisation']['excludeJS'] ) && ! empty( $this->options['file_optimisation']['excludeJS'] ) ) {
				$exclude_js = Util::process_urls( $this->options['file_optimisation']['excludeJS'] );

				$this->exclude_js = array_merge( $this->exclude_js, (array) $exclude_js );
			}

			add_filter( 'script_loader_tag', array( $this, 'minify_js' ), 10, 3 );
		}

		if ( isset( $this->options['file_optimisation']['minifyCSS'] ) && (bool) $this->options['file_optimisation']['minifyCSS'] ) {
			if ( isset( $this->options['file_optimisation']['excludeCSS'] ) && ! empty( $this->options['file_optimisation']['excludeCSS'] ) ) {
				$exclude_css       = Util::process_urls( $this->options['file_optimisation']['excludeCSS'] );
				$this->exclude_css = array_merge( $this->exclude_css, (array) $exclude_css );
			}

			add_filter( 'style_loader_tag', array( $this, 'minify_css' ), 10, 3 );
		}

		if ( isset( $this->options['file_optimisation']['deferJS'] ) && (bool) $this->options['file_optimisation']['deferJS'] ) {
			$exclude_js = array( 'wppo-lazyload' );
			if ( isset( $this->options['file_optimisation']['excludeDeferJS'] ) && ! empty( $this->options['file_optimisation']['excludeDeferJS'] ) ) {
				$exclude_defer          = Util::process_urls( $this->options['file_optimisation']['excludeDeferJS'] );
				$this->exclude_defer_js = array_merge( $exclude_js, (array) $exclude_defer );
			} else {
				$this->exclude_defer_js = $exclude_js;
			}
		}

		if ( isset( $this->options['file_optimisation']['delayJS'] ) && (bool) $this->options['file_optimisation']['delayJS'] ) {
			$exclude_js = array( 'wppo-lazyload' );
			if ( isset( $this->options['file_optimisation']['excludeDelayJS'] ) && ! empty( $this->options['file_optimisation']['excludeDelayJS'] ) ) {
				$exclude_delay          = Util::process_urls( $this->options['file_optimisation']['excludeDelayJS'] );
				$this->exclude_delay_js = array_merge( $exclude_js, (array) $exclude_delay );
			} else {
				$this->exclude_delay_js = $exclude_js;
			}
		}

		add_action( 'wp_head', array( $this, 'add_preload_prefatch_preconnect' ), 1 );

		new Metabox();
		new Cron();
		new Asset_Manager();

		// Register Action Scheduler callback for background image processing.
		add_action( 'wppo_convert_image_background', array( $this, 'process_background_image' ), 10, 1 );

		// Clear all cache on structural changes that invalidate every cached page.
		add_action( 'permalink_structure_changed', array( __CLASS__, 'clear_all_cache' ) );
		add_action( 'switch_theme', array( __CLASS__, 'clear_all_cache' ) );
		add_action( 'update_option_wppo_settings', array( __CLASS__, 'on_settings_update' ), 10, 2 );
		add_action( 'activated_plugin', array( __CLASS__, 'clear_all_cache' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'clear_all_cache' ) );
	}

	/**
	 * Callback for when plugin settings are updated.
	 *
	 * @param mixed $old_value The old option value.
	 * @param mixed $value     The new option value.
	 * @since 1.2.0
	 */
	public static function on_settings_update( $old_value, $value ) {
		self::clear_all_cache();

		// Handle .htaccess rules update.
		$old_enable = isset( $old_value['file_optimisation']['enableServerRules'] ) ? (bool) $old_value['file_optimisation']['enableServerRules'] : false;
		$new_enable = isset( $value['file_optimisation']['enableServerRules'] ) ? (bool) $value['file_optimisation']['enableServerRules'] : false;

		if ( $old_enable !== $new_enable ) {
			$ok = Htaccess_Handler::update_rules( $new_enable );

			if ( ! $ok ) {
				// Rollback the setting if .htaccess update failed.
				$value['file_optimisation']['enableServerRules'] = $old_enable;

				// Prevent infinite loop by temporary removing the action.
				remove_action( 'update_option_wppo_settings', array( __CLASS__, 'on_settings_update' ), 10 );
				update_option( 'wppo_settings', $value );
				add_action( 'update_option_wppo_settings', array( __CLASS__, 'on_settings_update' ), 10, 2 );

				add_action(
					'admin_notices',
					function () {
						echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Performance Optimization: Failed to update .htaccess rules. Please check file permissions.', 'performance-optimisation' ) . '</p></div>';
					}
				);
			}
		}
	}

	/**
	 * Clear the entire plugin cache.
	 *
	 * Called when structural changes (permalink update, theme switch, etc.)
	 * invalidate all cached pages.
	 *
	 * @since 1.1.0
	 */
	public static function clear_all_cache() {
		Cache::clear_cache();
	}

	/**
	 * Process a single image conversion in the background via Action Scheduler.
	 *
	 * @param array $args { source_path, format } for the image to convert.
	 * @since 1.1.0
	 */
	public function process_background_image( $args ) {
		if ( empty( $args['source_path'] ) || empty( $args['format'] ) ) {
			return;
		}

		$options       = get_option( 'wppo_settings', array() );
		$img_converter = new Img_Converter( $options );

		$source_path = wp_normalize_path( $args['source_path'] );
		$format      = sanitize_text_field( $args['format'] );

		if ( file_exists( $source_path ) ) {
			$img_converter->convert_image( $source_path, $format );
		}
	}

	/**
	 * Initialize the admin menu.
	 *
	 * Adds the Performance Optimisation menu to the WordPress admin dashboard.
	 *
	 * @return void
	 * @since  1.0.0
	 */
	public function init_menu(): void {
		add_menu_page(
			__( 'Performance Optimisation', 'performance-optimisation' ),
			__( 'Performance Optimisation', 'performance-optimisation' ),
			'manage_options',
			'performance-optimisation',
			array( $this, 'admin_page' ),
			'dashicons-admin-post',
			'2.1',
		);
	}

	/**
	 * Display the admin page.
	 *
	 * Includes the admin page template for rendering.
	 *
	 * @return void
	 * @since  1.0.0
	 */
	public function admin_page(): void {
		require_once WPPO_PLUGIN_PATH . 'templates/app.html';
	}

	/**
	 * Add available post types to options.
	 *
	 * Filters out non-public post types and adds the available post types to options.
	 *
	 * @return void
	 * @since  1.0.0
	 */
	private function add_available_post_types_to_options() {
		$post_types = get_post_types( array( 'public' => true ), 'names' );

		$excluded            = array( 'attachment' );
		$filtered_post_types = array_keys( array_diff( $post_types, $excluded ) );

		$this->options['image_optimisation']['availablePostTypes'] = $filtered_post_types;
	}

	/**
	 * Extract the active frontend theme's primary color.
	 *
	 * Checks block theme (theme.json) first, then classic theme (customizer).
	 *
	 * @since  2.0.0
	 * @return array{primary?: string, secondary?: string, text?: string}
	 */
	private function get_frontend_theme_colors(): array {
		$colors = array(
			'primary'   => '',
			'secondary' => '',
			'text'      => '',
		);

		// Strategy 1: Block theme — read from theme.json (WP 5.8+).
		if ( function_exists( 'wp_get_global_settings' ) ) {
			$settings = wp_get_global_settings();
			$palette  = $settings['color']['palette']['theme'] ?? array();

			foreach ( $palette as $entry ) {
				$slug = sanitize_title( $entry['slug'] ?? '' );
				$hex  = sanitize_hex_color( $entry['color'] ?? '' );

				if ( ! $hex ) {
					continue;
				}

				if ( in_array( $slug, array( 'primary', 'brand', 'accent' ), true ) ) {
					$colors['primary'] = $hex;
				} elseif ( in_array( $slug, array( 'secondary', 'secondary-brand' ), true ) ) {
					$colors['secondary'] = $hex;
				} elseif ( in_array( $slug, array( 'foreground', 'contrast', 'body-text' ), true ) ) {
					$colors['text'] = $hex;
				}
			}
		}

		// Strategy 2: Classic theme — check Customizer settings.
		if ( empty( $colors['primary'] ) ) {
			$primary = get_theme_mod( 'primary_color', '' );
			if ( empty( $primary ) ) {
				$primary = get_theme_mod( 'accent_color', '' );
			}
			if ( ! empty( $primary ) ) {
				$colors['primary'] = sanitize_hex_color( $primary );
			}
		}

		// Strategy 3: Extract from the theme's header_textcolor.
		if ( empty( $colors['text'] ) ) {
			$header_text_color = get_header_textcolor();
			if ( 'blank' !== $header_text_color && ! empty( $header_text_color ) ) {
				$colors['text'] = '#' . ltrim( sanitize_hex_color_no_hash( $header_text_color ), '#' );
			}
		}

		return array_filter( $colors );
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * Loads CSS and JavaScript files for the admin dashboard page.
	 *
	 * @return void
	 * @since  1.0.0
	 */
	public function admin_enqueue_scripts(): void {
		$screen = get_current_screen();

		if ( is_admin_bar_showing() ) {
			wp_enqueue_script( 'wppo-admin-bar-script', WPPO_PLUGIN_URL . 'src/main.js', array(), WPPO_VERSION, true );
			wp_localize_script(
				'wppo-admin-bar-script',
				'wppoObject',
				array(
					'apiUrl' => get_rest_url( null, 'performance-optimisation/v1' ),
					'nonce'  => wp_create_nonce( 'wp_rest' ),
				)
			);
		}

		if ( 'toplevel_page_performance-optimisation' !== $screen->base ) {
			return;
		}

		$asset_file = WPPO_PLUGIN_PATH . 'build/index.asset.php';

		// Include the asset file to retrieve dependencies and version.
		$asset_data = file_exists( $asset_file ) ? include $asset_file : array(
			'dependencies' => array(),
			'version'      => false,
		);

		wp_enqueue_style( 'performance-optimisation-style', WPPO_PLUGIN_URL . 'build/style-index.css', array(), $asset_data['version'], 'all' );
		wp_enqueue_script( 'performance-optimisation-script', WPPO_PLUGIN_URL . 'build/index.js', $asset_data['dependencies'], $asset_data['version'], true );

		$this->add_available_post_types_to_options();

		$cache_size = get_transient( 'wppo_cache_size' );
		if ( false === $cache_size ) {
			$cache_size = Cache::get_cache_size();
			set_transient( 'wppo_cache_size', $cache_size, 15 * MINUTE_IN_SECONDS );
		}

		$total_js_css = get_transient( 'wppo_total_js_css' );
		if ( false === $total_js_css ) {
			$total_js_css = Util::get_js_css_minified_file();
			set_transient( 'wppo_total_js_css', $total_js_css, 15 * MINUTE_IN_SECONDS );
		}

		wp_localize_script(
			'performance-optimisation-script',
			'wppoSettings',
			array(
				'apiUrl'       => get_rest_url( null, 'performance-optimisation/v1/' ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'settings'     => $this->options,
				'image_info'   => get_option( 'wppo_img_info', array() ),
				'cache_size'   => $cache_size,
				'total_js_css' => $total_js_css,
				'translations' => array(
					'performanceSettings'      => __( 'Performance Settings', 'performance-optimisation' ),
					'dashboard'                => __( ' Dashboard', 'performance-optimisation' ),
					'openMenu'                 => __( 'Open Menu', 'performance-optimisation' ),
					'closeMenu'                => __( 'Close Menu', 'performance-optimisation' ),
					'fileOptimization'         => __( ' File Optimization', 'performance-optimisation' ),
					'preload'                  => __( ' Preload', 'performance-optimisation' ),
					'imageOptimization'        => __( ' Image Optimization', 'performance-optimisation' ),
					'tools'                    => __( ' Tools', 'performance-optimisation' ),
					'failedFetchActivities'    => __( 'Failed to fetch activities:', 'performance-optimisation' ),
					'sidebar.expand'           => __( 'Expand sidebar', 'performance-optimisation' ),
					'sidebar.collapse'         => __( 'Collapse sidebar', 'performance-optimisation' ),
					'clearCacheSuccess'        => __( 'Cache cleared successfully: ', 'performance-optimisation' ),
					'errorClearCache'          => __( 'Error clearing cache: ', 'performance-optimisation' ),
					'completed'                => __( 'Completed', 'performance-optimisation' ),
					'pending'                  => __( 'Pending', 'performance-optimisation' ),
					'failed'                   => __( 'Failed', 'performance-optimisation' ),
					'optimizing'               => __( 'Optimizing...', 'performance-optimisation' ),
					'optimiseNow'              => __( 'Optimize Now', 'performance-optimisation' ),
					'removing'                 => __( 'Removing...', 'performance-optimisation' ),
					'removeOptimized'          => __( 'Remove Optimized', 'performance-optimisation' ),
					'recentActivities'         => __( 'Recent Activities', 'performance-optimisation' ),
					'loadingRecentActivities'  => __( 'Loading recent activities...', 'performance-optimisation' ),
					'noPendingImage'           => __( 'No pending images to convert!', 'performance-optimisation' ),
					'imgOptimiseSuccess'       => __( 'Images optimized successfully', 'performance-optimisation' ),
					'errorOptimiseImg'         => __( 'Error optimizing images:', 'performance-optimisation' ),
					'noImgRemove'              => __( 'No optimized images to remove!', 'performance-optimisation' ),
					'removedOptimiseImg'       => __( 'Optimized images removed successfully!', 'performance-optimisation' ),
					'removedImg'               => __( 'Removed images: ', 'performance-optimisation' ),
					'someImgNotRemoved'        => __( 'Some images could not be removed.', 'performance-optimisation' ),
					'failedToRemove'           => __( 'Failed to remove:', 'performance-optimisation' ),
					'errorRemovingImg'         => __( 'Error removing optimized images:', 'performance-optimisation' ),
					'errorEccurredRemovingImg' => __( 'An error occurred while removing optimized images.', 'performance-optimisation' ),
					'cacheStatus'              => __( 'Cache Status', 'performance-optimisation' ),
					'currentCacheSize'         => __( 'Current Cache Size: ', 'performance-optimisation' ),
					'clearing'                 => __( 'Clearing...', 'performance-optimisation' ),
					'clearCacheNow'            => __( 'Clear Cache Now', 'performance-optimisation' ),
					'JSCSSOptimisation'        => __( 'JavaScript & CSS Optimization', 'performance-optimisation' ),
					'JSFilesMinified'          => __( 'JavaScript Files Minified: ', 'performance-optimisation' ),
					'CSSFilesMinified'         => __( 'CSS Files Minified: ', 'performance-optimisation' ),
					'selectFiles'              => __( 'Please select a file.', 'performance-optimisation' ),
					'fileImported'             => __( 'File imported successfully', 'performance-optimisation' ),
					'fileImporting'            => __( 'Error importing settings:', 'performance-optimisation' ),
					'fileErrorImport'          => __( 'An error occurred during import.', 'performance-optimisation' ),
					'invalidJSON'              => __( 'Invalid JSON file:', 'performance-optimisation' ),
					'invalidFileFormat'        => __( 'Invalid file format. Please select a valid JSON file.', 'performance-optimisation' ),
					'exportSettings'           => __( 'Export Settings', 'performance-optimisation' ),
					'exportPluginSettings'     => __( 'Export performance optimization plugin settings.', 'performance-optimisation' ),
					'importSettings'           => __( 'Import Settings', 'performance-optimisation' ),
					'importPluginSettings'     => __( 'Import performance optimization plugin settings.', 'performance-optimisation' ),
					'formSubmitted'            => __( 'Form Submitted:', 'performance-optimisation' ),
					'formSubmissionError'      => __( 'Form submission error:', 'performance-optimisation' ),
					'fileOptimizationSettings' => __( 'File Optimization Settings', 'performance-optimisation' ),
					'minifyJS'                 => __( 'Minify JavaScript', 'performance-optimisation' ),
					'excludeJSFiles'           => __( 'Exclude specific JavaScript files', 'performance-optimisation' ),
					'minifyCSS'                => __( 'Minify CSS', 'performance-optimisation' ),
					'excludeCSSFiles'          => __( 'Exclude specific CSS files', 'performance-optimisation' ),
					'combineCSS'               => __( 'Combine CSS', 'performance-optimisation' ),
					'excludeCombineCSS'        => __( 'Exclude CSS files to combine', 'performance-optimisation' ),
					'removeWooCSSJS'           => __( 'Remove woocommerce css and js from other page', 'performance-optimisation' ),
					'removeWooCSSJSWarning'    => __( 'Removing WooCommerce assets can break cart, checkout, or product pages if URLs or handles are wrong. Test store flows after enabling.', 'performance-optimisation' ),
					'excludeUrlToKeepJSCSS'    => __( 'Exclude Url to keep woocommerce css and js', 'performance-optimisation' ),
					'removeCssJsHandle'        => __( 'Enter handle which script and style you want to remove', 'performance-optimisation' ),
					'minifyHTML'               => __( 'Minify HTML', 'performance-optimisation' ),
					'deferJS'                  => __( 'Defer Loading JavaScript', 'performance-optimisation' ),
					'excludeDeferJS'           => __( 'Exclude specific JavaScript files', 'performance-optimisation' ),
					'delayJS'                  => __( 'Delay Loading JavaScript', 'performance-optimisation' ),
					'excludeDelayJS'           => __( 'Exclude specific JavaScript files', 'performance-optimisation' ),
					'saving'                   => __( 'Saving...', 'performance-optimisation' ),
					'saveSettings'             => __( 'Save Settings', 'performance-optimisation' ),
					'imgOptimizationsettings'  => __( 'Image Optimization Settings', 'performance-optimisation' ),
					'lazyLoadImages'           => __( 'Lazy Load Images', 'performance-optimisation' ),
					'excludeImages'            => __( 'Exclude specific image URLs', 'performance-optimisation' ),
					'excludeVideos'            => __( 'Exclude specific video URLs', 'performance-optimisation' ),
					'lazyLoadImagesDesc'       => __( 'Enable lazy loading for images to improve the initial load speed by loading images only when they appear in the viewport.', 'performance-optimisation' ),
					'wrapInPicture'            => __( 'Wrap Image in Picture Tag', 'performance-optimisation' ),
					/* translators: %s: The HTML tag name */
					'wrapInPictureDesc'        => sprintf( esc_html__( 'Enable this to wrap images in a %s tag for better performance with next-gen formats.', 'performance-optimisation' ), '<code>&lt;picture&gt;</code>' ),
					'lazyLoadVideos'           => __( 'Lazy Load Videos', 'performance-optimisation' ),
					'lazyLoadVideosDesc'       => __( 'Enable lazy loading for videos to improve initial load speed.', 'performance-optimisation' ),
					'excludeFirstImages'       => __( 'Enter number you want to exclude first', 'performance-optimisation' ),
					'replaceImgToSVG'          => __( 'Replace Low-Resolution Placeholder with SVG', 'performance-optimisation' ),
					'replaceImgToSVGDesc'      => __( 'Use SVG placeholders for images that are being lazy-loaded to improve page rendering performance.', 'performance-optimisation' ),
					'convertImg'               => __( 'Enable Image Conversion', 'performance-optimisation' ),
					'excludeConvertImages'     => __( 'Exclude specific images from conversion', 'performance-optimisation' ),
					'convertImgDesc'           => __( 'Convert images to WebP/AVIF format to reduce image size while maintaining quality.', 'performance-optimisation' ),
					'conversationFormat'       => __( 'Conversion Format:', 'performance-optimisation' ),
					'webp'                     => __( 'WebP', 'performance-optimisation' ),
					'avif'                     => __( 'AVIF', 'performance-optimisation' ),
					'both'                     => __( 'Both', 'performance-optimisation' ),
					'preloadFrontPageImg'      => __( 'Preload Images on Front Page', 'performance-optimisation' ),
					'preloadFrontPageImgDesc'  => __( 'Preload critical images on the front page to enhance initial load performance.', 'performance-optimisation' ),
					'preloadFrontPageImgUrl'   => __( 'Enter img url (full/partial) to preload this img in front page.', 'performance-optimisation' ),
					'preloadPostTypeImg'       => __( 'Preload Feature Images for Post Types', 'performance-optimisation' ),
					'preloadPostTypeImgDesc'   => __( 'Select post types where feature images should be preloaded for better performance.', 'performance-optimisation' ),
					'excludePostTypeImgUrl'    => __( 'Exclude specific img to preload.', 'performance-optimisation' ),
					'maxWidthImgSize'          => __( 'Set max width so it can\'t load bigger img than it. 0 default.', 'performance-optimisation' ),
					'excludeSize'              => __( 'Exclude specific size to preload.', 'performance-optimisation' ),
					'preloadSettings'          => __( 'Preload Settings', 'performance-optimisation' ),
					'enablePreloadCache'       => __( 'Enable Preloading Cache', 'performance-optimisation' ),
					'excludePreloadCache'      => __( 'Exclude specific resources from preloading', 'performance-optimisation' ),
					'enablePreloadCacheDesc'   => __( 'Preload the cache to improve page load times by caching key resources.', 'performance-optimisation' ),
					'preconnect'               => __( 'Preconnect', 'performance-optimisation' ),
					'preconnectOrigins'        => __( 'Add preconnect origins, one per line (e.g., https://fonts.gstatic.com)', 'performance-optimisation' ),
					'preconnectDesc'           => __( 'Add origins to preconnect, improving the speed of resource loading.', 'performance-optimisation' ),
					'prefetchDNS'              => __( 'Prefetch DNS', 'performance-optimisation' ),
					'dnsPrefetchOrigins'       => __( 'Enter domains for DNS prefetching, one per line (e.g., https://example.com)', 'performance-optimisation' ),
					'prefetchDNSDesc'          => __( 'Prefetch DNS for external domains to reduce DNS lookup times.', 'performance-optimisation' ),
					'preloadFonts'             => __( 'Preload Fonts', 'performance-optimisation' ),
					'preloadFontsUrls'         => __( "Enter fonts for preloading, one per line (e.g., https://example.com/fonts/font.woff2)\n/your-theme/fonts/font.woff2", 'performance-optimisation' ),
					'preloadFontsDesc'         => __( 'Preload fonts to ensure faster loading and rendering of text.', 'performance-optimisation' ),
					'preloadCSS'               => __( 'Preload CSS', 'performance-optimisation' ),
					'preloadCSSUrls'           => __( "Enter CSS for preloading, one per line (e.g., https://example.com/style.css)\n/your-theme/css/style.css", 'performance-optimisation' ),
					'preloadCSSDesc'           => __( 'Preload CSS to ensure faster rendering and style application', 'performance-optimisation' ),
					// Database Cleanup translations.
					'databaseOptimization'     => __( ' Database Optimization', 'performance-optimisation' ),
					'dbRevisions'              => __( 'Post Revisions', 'performance-optimisation' ),
					'dbRevisionsDesc'          => __( 'Old versions of your posts saved during editing.', 'performance-optimisation' ),
					'dbAutoDrafts'             => __( 'Auto Drafts', 'performance-optimisation' ),
					'dbAutoDraftsDesc'         => __( 'Automatically saved drafts that are no longer needed.', 'performance-optimisation' ),
					'dbTrashedPosts'           => __( 'Trashed Posts', 'performance-optimisation' ),
					'dbTrashedPostsDesc'       => __( 'Posts that have been moved to the trash.', 'performance-optimisation' ),
					'dbSpamComments'           => __( 'Spam Comments', 'performance-optimisation' ),
					'dbSpamCommentsDesc'       => __( 'Comments marked as spam.', 'performance-optimisation' ),
					'dbTrashedComments'        => __( 'Trashed Comments', 'performance-optimisation' ),
					'dbTrashedCommentsDesc'    => __( 'Comments that have been moved to the trash.', 'performance-optimisation' ),
					'dbExpiredTransients'      => __( 'Expired Transients', 'performance-optimisation' ),
					'dbExpiredTransientsDesc'  => __( 'Temporary cached data that has expired.', 'performance-optimisation' ),
					'dbOrphanPostmeta'         => __( 'Orphaned Post Meta', 'performance-optimisation' ),
					'dbOrphanPostmetaDesc'     => __( 'Metadata entries with no associated post.', 'performance-optimisation' ),
					'dbCleanupSuccess'         => __( 'Cleaned', 'performance-optimisation' ),
					'dbItemsRemoved'           => __( 'items removed', 'performance-optimisation' ),
					'dbCleanupError'           => __( 'An error occurred during cleanup.', 'performance-optimisation' ),
					'dbCleanAllSuccess'        => __( 'All cleanup complete', 'performance-optimisation' ),
					'dbTotalItemsRemoved'      => __( 'total items removed', 'performance-optimisation' ),
					'dbTotalItems'             => __( 'Total Items to Clean', 'performance-optimisation' ),
					'dbCleaning'               => __( 'Cleaning...', 'performance-optimisation' ),
					'dbCleanAll'               => __( 'Clean All', 'performance-optimisation' ),
					'dbClean'                  => __( 'Clean', 'performance-optimisation' ),
					'dbCleanupIntro'           => __( 'Remove unnecessary data from your WordPress database to improve performance and reduce bloat.', 'performance-optimisation' ),
					'dbAutomatedCleanup'       => __( 'Automated Cleanup', 'performance-optimisation' ),
					'dbSchedule'               => __( 'Schedule Frequency', 'performance-optimisation' ),
					'dbScheduleNone'           => __( 'None (Manual Only)', 'performance-optimisation' ),
					'dbScheduleDaily'          => __( 'Daily', 'performance-optimisation' ),
					'dbScheduleWeekly'         => __( 'Weekly', 'performance-optimisation' ),
					'dbScheduleMonthly'        => __( 'Monthly', 'performance-optimisation' ),
					'dbRevKeepLatest'          => __( 'Always Keep Latest Revisions (Per Post)', 'performance-optimisation' ),
					'dbRevMaxAge'              => __( 'Max Age of Revisions to Keep (Days)', 'performance-optimisation' ),
					// Image job status translations.
					'imgJobsQueued'            => __( 'Jobs Queued', 'performance-optimisation' ),
					'imgProcessing'            => __( 'Processing in background...', 'performance-optimisation' ),
					'imgJobsComplete'          => __( 'All background jobs complete!', 'performance-optimisation' ),
					'enableServerRules'        => __( 'Enable Server-Side Rules (Gzip & Browser Caching)', 'performance-optimisation' ),
					'enableServerRulesDesc'    => __( 'Automatically add performance rules to your .htaccess file.', 'performance-optimisation' ),
					'cdnURL'                   => __( 'CDN URL', 'performance-optimisation' ),
					'cdnURLPlaceholder'        => __( 'https://cdn.example.com', 'performance-optimisation' ),
					// Confirmation dialog translations.
					'confirmDeleteTitle'       => __( 'Confirm Deletion', 'performance-optimisation' ),
					'confirmDeleteMsg'         => __( 'Permanently delete', 'performance-optimisation' ),
					'confirmDeleteNote'        => __( 'This action cannot be undone.', 'performance-optimisation' ),
					'confirmDeleteAll'         => __( 'This will permanently delete all items across every category. This cannot be undone.', 'performance-optimisation' ),
					'confirmImportTitle'       => __( 'Confirm Import', 'performance-optimisation' ),
					'confirmImportMsg'         => __( 'Importing this file will overwrite all current plugin settings. Continue?', 'performance-optimisation' ),
					'confirmRemoveImgTitle'    => __( 'Remove Optimized Images', 'performance-optimisation' ),
					'confirmRemoveImgMsg'      => __( 'This will delete all optimized WebP and AVIF copies. Original images will not be affected.', 'performance-optimisation' ),
					'confirm'                  => __( 'Confirm', 'performance-optimisation' ),
					'cancel'                   => __( 'Cancel', 'performance-optimisation' ),
					'deleteBtn'                => __( 'Delete', 'performance-optimisation' ),
					'deleteAllBtn'             => __( 'Delete All', 'performance-optimisation' ),
					// Inline notice text.
					'deferJSWarning'           => __( 'This may affect inline scripts. Test your site thoroughly after enabling. Use the exclusion list below for any scripts that break.', 'performance-optimisation' ),
					'delayJSWarning'           => __( 'Delayed scripts will not execute until user interaction. This can break scripts that need to run immediately. Test carefully.', 'performance-optimisation' ),
					'serverRulesWarning'       => __( 'This modifies your .htaccess file. Ensure you have a backup. If your site becomes inaccessible, revert via FTP.', 'performance-optimisation' ),
					'lazyLoadInfo'             => __( 'Images above the fold (header, hero) should be excluded to avoid layout shifts. Use the settings below to fine-tune.', 'performance-optimisation' ),
					'convertImgInfo'           => __( 'Converted images are served alongside originals. Browsers that don\'t support the format will fall back to the original automatically.', 'performance-optimisation' ),
					// Core Tweaks translations.
					'coreTweaks'               => __( 'Core Tweaks', 'performance-optimisation' ),
					'coreTweaksIntro'          => __( 'Disable unnecessary WordPress core features to reduce database weight and frontend requests.', 'performance-optimisation' ),
					'disableEmojis'            => __( 'Disable Emojis', 'performance-optimisation' ),
					'disableEmojisDesc'        => __( 'Removes the extra inline JS and wp-emoji-release.min.js file loaded on every page.', 'performance-optimisation' ),
					'disableEmbeds'            => __( 'Disable Embeds', 'performance-optimisation' ),
					'disableEmbedsDesc'        => __( 'Removes the wp-embed.min.js script if you do not embed WordPress content from other sites.', 'performance-optimisation' ),
					'disableDashicons'         => __( 'Disable Dashicons on Frontend', 'performance-optimisation' ),
					'disableDashiconsDesc'     => __( 'Prevents the heavy Dashicons CSS from loading for non-logged-in users.', 'performance-optimisation' ),
					'disableXMLRPC'            => __( 'Disable XML-RPC', 'performance-optimisation' ),
					'disableXMLRPCDesc'        => __( 'Security & performance fix that stops brute-force pingback attacks draining server CPU.', 'performance-optimisation' ),
					'heartbeatControl'         => __( 'Heartbeat API Control', 'performance-optimisation' ),
					'heartbeatControlDesc'     => __( 'The Heartbeat API pings admin-ajax.php frequently, causing CPU spikes. Control its behavior here.', 'performance-optimisation' ),
					'heartbeatOptDefault'      => __( 'Default Mode', 'performance-optimisation' ),
					'heartbeatOpt60s'          => __( 'Reduce Frequency (60 Seconds)', 'performance-optimisation' ),
					'heartbeatOptDisableExt'   => __( 'Disable on Frontend Only', 'performance-optimisation' ),
					'heartbeatOptDisableAll'   => __( 'Disable Everywhere', 'performance-optimisation' ),
					// Redis status translations.
					'redisUnreachable'         => __( 'Redis Server Unreachable:', 'performance-optimisation' ),
					'redisUnreachableDesc'     => __( 'Could not connect to the Redis server. Please ensure the service is running and accessible.', 'performance-optimisation' ),
					'connectionMode'           => __( 'Connection Mode', 'performance-optimisation' ),
					'standalone'               => __( 'Standalone / Default', 'performance-optimisation' ),
					'sentinel'                 => __( 'Redis Sentinel (High Availability)', 'performance-optimisation' ),
					'cluster'                  => __( 'Redis Cluster', 'performance-optimisation' ),
					'redisNodes'               => __( 'Redis Nodes', 'performance-optimisation' ),
					'redisNodesDesc'           => __( 'Enter one node per line (e.g. 127.0.0.1:26379).', 'performance-optimisation' ),
					'masterName'               => __( 'Sentinel Master Name', 'performance-optimisation' ),
					'masterNameDesc'           => __( 'The master group name configured in your Sentinels (default: mymaster).', 'performance-optimisation' ),
					'enableTls'                => __( 'Enable TLS (SSL)', 'performance-optimisation' ),
					'persistentConnection'     => __( 'Persistent Connections', 'performance-optimisation' ),
					'advancedSecurity'         => __( 'Advanced Security & Encryption', 'performance-optimisation' ),
					'highAvailability'         => __( 'High Availability Configuration', 'performance-optimisation' ),
				),

				// Frontend theme colors for accent syncing.
				'themeColors'  => $this->get_frontend_theme_colors(),
			),
		);
	}

	/**
	 * Enqueues scripts for performance optimization.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_scripts() {
		if ( is_admin_bar_showing() ) {
			wp_enqueue_script( 'wppo-admin-bar-script', WPPO_PLUGIN_URL . 'src/main.js', array(), WPPO_VERSION, true );
			wp_localize_script(
				'wppo-admin-bar-script',
				'wppoObject',
				array(
					'apiUrl' => get_rest_url( null, 'performance-optimisation/v1' ),
					'nonce'  => wp_create_nonce( 'wp_rest' ),
				)
			);
		}

		if ( ! is_user_logged_in() ) {
			$lazy_load_images = isset( $this->options['image_optimisation']['lazyLoadImages'] ) && (bool) $this->options['image_optimisation']['lazyLoadImages'];
			$lazy_load_videos = isset( $this->options['image_optimisation']['lazyLoadVideos'] ) && (bool) $this->options['image_optimisation']['lazyLoadVideos'];
			$delay_js         = isset( $this->options['file_optimisation']['delayJS'] ) && (bool) $this->options['file_optimisation']['delayJS'];

			if ( $lazy_load_images || $lazy_load_videos || $delay_js ) {
				wp_enqueue_script( 'wppo-lazyload', WPPO_PLUGIN_URL . 'build/lazyload.js', array(), WPPO_VERSION, true );
			}
		}
	}

	/**
	 * Removes WooCommerce-related scripts and styles based on settings.
	 *
	 * @since 1.0.0
	 */
	public function remove_woocommerce_scripts() {
		$exclude_url_to_keep_js_css = array();
		if ( isset( $this->options['file_optimisation']['excludeUrlToKeepJSCSS'] ) && ! empty( $this->options['file_optimisation']['excludeUrlToKeepJSCSS'] ) ) {
			$exclude_url_to_keep_js_css = Util::process_urls( $this->options['file_optimisation']['excludeUrlToKeepJSCSS'] );

			// Safely retrieve and sanitize the current URL.
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$parsed_uri  = str_replace( wp_parse_url( home_url(), PHP_URL_PATH ) ?? '', '', $request_uri );
			$current_url = home_url( sanitize_text_field( $parsed_uri ) );
			$current_url = rtrim( $current_url, '/' );

			foreach ( $exclude_url_to_keep_js_css as $exclude_url ) {
				if ( 0 !== strpos( $exclude_url, 'http' ) ) {
					$exclude_url = home_url( $exclude_url );
					$exclude_url = rtrim( $exclude_url, '/' );
				}

				if ( false !== strpos( $exclude_url, '(.*)' ) ) {
					$exclude_prefix = str_replace( '(.*)', '', $exclude_url );
					$exclude_prefix = rtrim( $exclude_prefix, '/' );

					if ( 0 === strpos( $current_url, $exclude_prefix ) ) {
						return;
					}
				}

				if ( untrailingslashit( $current_url ) === untrailingslashit( $exclude_url ) ) {
					return;
				}
			}
		}

		$remove_css_js_handle = array();
		if ( isset( $this->options['file_optimisation']['removeCssJsHandle'] ) && ! empty( $this->options['file_optimisation']['removeCssJsHandle'] ) ) {
			$remove_css_js_handle = Util::process_urls( $this->options['file_optimisation']['removeCssJsHandle'] );
		}

		if ( ! empty( $remove_css_js_handle ) ) {
			foreach ( $remove_css_js_handle as $handle ) {
				if ( 0 === strpos( $handle, 'style:' ) ) {
					$handle = str_replace( 'style:', '', $handle );
					$handle = trim( $handle );

					wp_dequeue_style( $handle );
				} elseif ( 0 === strpos( $handle, 'script:' ) ) {
					$handle = str_replace( 'script:', '', $handle );
					$handle = trim( $handle );

					wp_dequeue_script( $handle );
				}
			}
		}
	}

	/**
	 * Adds custom settings to the WordPress admin bar.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar The WordPress admin bar object used to add nodes and settings.
	 *
	 * @since 1.0.0
	 */
	public function add_setting_to_admin_bar( $wp_admin_bar ) {
		$wp_admin_bar->add_node(
			array(
				'id'    => 'wppo_setting',
				'title' => __( 'Performance Optimisation', 'performance-optimisation' ),
				'href'  => admin_url( 'admin.php?page=performance-optimisation' ),
				'meta'  => array(
					'class' => 'performance-optimisation-setting',
					'title' => __( 'Go to Performance Optimisation Setting', 'performance-optimisation' ),
				),
			),
		);

		// Add a submenu under the custom setting.
		$wp_admin_bar->add_node(
			array(
				'id'     => 'wppo_clear_all',
				'parent' => 'wppo_setting',
				'title'  => __( 'Clear All Cache', 'performance-optimisation' ),
				'href'   => '#',
			)
		);

		if ( ! is_admin() ) {
			$current_id = get_the_ID();

			$wp_admin_bar->add_node(
				array(
					'id'     => 'wppo_clear_this_page',
					'parent' => 'wppo_setting',
					'title'  => __( 'Clear This Page Cache', 'performance-optimisation' ),
					'href'   => '#', // You can replace with actual URL or function if needed.
					'meta'   => array(
						'title' => __( 'Clear cache for this specific page or post', 'performance-optimisation' ),
						'class' => 'page-' . $current_id,
					),
				)
			);
		}
	}

	/**
	 * Adds defer attribute to non-logged-in users' scripts.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $tag    The script tag HTML.
	 * @param  string $handle The script's registered handle.
	 * @return string Modified script tag with defer attribute.
	 */
	public function add_defer_attribute( $tag, $handle ): string {
		if ( is_user_logged_in() ) {
			return $tag;
		}

		if ( isset( $this->options['file_optimisation']['deferJS'] ) && (bool) $this->options['file_optimisation']['deferJS'] ) {
			if ( ! in_array( $handle, $this->exclude_defer_js, true ) ) {
				$tag = str_replace( ' src', ' defer="defer" src', $tag );
			}
		}

		if ( isset( $this->options['file_optimisation']['delayJS'] ) && (bool) $this->options['file_optimisation']['delayJS'] ) {
			if ( ! in_array( $handle, $this->exclude_delay_js, true ) ) {
				$tag = str_replace( ' src', ' wppo-src', $tag );
				$tag = preg_replace(
					'/type=("|\')text\/javascript("|\')/',
					'type="wppo/javascript" wppo-type="text/javascript"',
					$tag
				);
			}
		}

		return $tag;
	}

	/**
	 * Adds preload, prefetch, and preconnect links to optimize resource loading.
	 *
	 * @since 1.0.0
	 */
	public function add_preload_prefatch_preconnect() {

		$preload_settings = $this->options['preload_settings'] ?? array();

		// Preconnect origins.
		if ( isset( $preload_settings['preconnect'] ) && (bool) $preload_settings['preconnect'] ) {
			if ( isset( $preload_settings['preconnectOrigins'] ) && ! empty( $preload_settings['preconnectOrigins'] ) ) {
				$preconnect_origins = Util::process_urls( $preload_settings['preconnectOrigins'] );

				foreach ( $preconnect_origins as $origin ) {
					Util::generate_preload_link( $origin, 'preconnect', '', true );
				}
			}
		}

		// Prefetch DNS origins.
		if ( isset( $preload_settings['prefetchDNS'] ) && (bool) $preload_settings['prefetchDNS'] ) {
			if ( isset( $preload_settings['dnsPrefetchOrigins'] ) && ! empty( $preload_settings['dnsPrefetchOrigins'] ) ) {
				$dns_prefetch_origins = Util::process_urls( $preload_settings['dnsPrefetchOrigins'] );

				foreach ( $dns_prefetch_origins as $origin ) {
					Util::generate_preload_link( $origin, 'dns-prefetch' );
				}
			}
		}

		// Preload fonts.
		if ( isset( $preload_settings['preloadFonts'] ) && (bool) $preload_settings['preloadFonts'] ) {
			if ( isset( $preload_settings['preloadFontsUrls'] ) && ! empty( $preload_settings['preloadFontsUrls'] ) ) {
				$preload_fonts_urls = Util::process_urls( $preload_settings['preloadFontsUrls'] );

				foreach ( $preload_fonts_urls as $font_url ) {

					$font_url = preg_match( '/^https?:\/\//i', $font_url ) ? $font_url : content_url( $font_url );

					$font_extension = pathinfo( wp_parse_url( $font_url, PHP_URL_PATH ), PATHINFO_EXTENSION );
					$font_type      = '';

					switch ( strtolower( $font_extension ) ) {
						case 'woff2':
							$font_type = 'font/woff2';
							break;
						case 'woff':
							$font_type = 'font/woff';
							break;
						case 'ttf':
								$font_type = 'font/ttf';
							break;
						default:
							$font_type = ''; // Fallback if unknown extension.
					}

					Util::generate_preload_link( $font_url, 'preload', 'font', true, $font_type );
				}
			}
		}

		if ( isset( $preload_settings['preloadCSS'] ) && (bool) $preload_settings['preloadCSS'] ) {
			if ( isset( $preload_settings['preloadCSSUrls'] ) && ! empty( $preload_settings['preloadCSSUrls'] ) ) {
				$preload_css_urls = Util::process_urls( $preload_settings['preloadCSSUrls'] );

				foreach ( $preload_css_urls as $css_url ) {
					$css_url = preg_match( '/^https?:\/\//i', $css_url ) ? $css_url : content_url( $css_url );

					Util::generate_preload_link( $css_url, 'preload', 'style' );
				}
			}
		}

		$this->image_optimisation->preload_images();
	}

	/**
	 * Minifies CSS files and serves them from cache.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $tag    The link tag HTML.
	 * @param  string $handle The CSS file's handle.
	 * @param  string $href   The CSS file's source URL.
	 * @return string Modified link tag with minified CSS.
	 */
	public function minify_css( $tag, $handle, $href ) {
		$local_path = Util::get_local_path( $href );

		if ( is_user_logged_in() || empty( $href ) || in_array( $handle, $this->exclude_css, true ) || $this->is_css_minified( $local_path ) ) {
			return $tag;
		}

		$css_minifier = new Minify\CSS( $local_path, wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/min/css' ) );
		$cached_file  = $css_minifier->minify();

		if ( $cached_file ) {
			$file_version = fileatime( Util::get_local_path( $cached_file ) );
			$new_href     = content_url( 'cache/wppo/min/css/' . basename( $cached_file ) ) . '?ver=' . $file_version;
			$new_tag      = str_replace( $href, $new_href, $tag );
			return $new_tag;
		}

		return $tag;
	}

	/**
	 * Minifies JavaScript files and serves them from cache.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $tag    The script tag HTML.
	 * @param  string $handle The script's registered handle.
	 * @param  string $src    The script's source URL.
	 * @return string Modified script tag with minified JavaScript.
	 */
	public function minify_js( $tag, $handle, $src ) {
		$local_path = Util::get_local_path( $src );

		if ( is_user_logged_in() || empty( $src ) || in_array( $handle, $this->exclude_js, true ) || $this->is_js_minified( $local_path ) ) {
			return $tag;
		}

		$js_minifier = new Minify\JS( $local_path, wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/min/js' ) );
		$cached_file = $js_minifier->minify();

		if ( $cached_file ) {
			$file_version = fileatime( Util::get_local_path( $cached_file ) );

			$new_src = content_url( 'cache/wppo/min/js/' . basename( $cached_file ) ) . '?ver=' . $file_version;
			$new_tag = str_replace( $src, $new_src, $tag );
			return $new_tag;
		}

		return $tag;
	}

	/**
	 * Checks if a CSS file is already minified.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $file_path Path to the CSS file.
	 * @return bool True if the file is minified, false otherwise.
	 */
	private function is_css_minified( $file_path ) {
		if ( empty( $file_path ) ) {
			return true;
		}

		$file_name = basename( $file_path );

		if ( preg_match( '/(\.min\.css|\.bundle\.css|\.bundle\.min\.css)$/i', $file_name ) ) {
			return true;
		}

		if ( ! file_exists( $file_path ) ) {
			return true;
		}

		$css_content = $this->filesystem->get_contents( $file_path );
		if ( ! is_string( $css_content ) ) {
			return true;
		}

		$line_count = ( '' === $css_content ) ? 0 : substr_count( $css_content, "\n" ) + 1;

		if ( 10 >= $line_count ) {
			return true;
		}

		return false;
	}

	/**
	 * Checks if a JavaScript file is already minified.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $file_path Path to the JavaScript file.
	 * @return bool True if the file is minified, false otherwise.
	 */
	private function is_js_minified( $file_path ) {
		if ( empty( $file_path ) ) {
			return true;
		}

		$file_name = basename( $file_path );

		if ( preg_match( '/(\.min\.js|\.bundle\.js|\.bundle\.min\.js)$/i', $file_name ) ) {
			return true;
		}

		if ( ! file_exists( $file_path ) ) {
			return true;
		}

		$js_content = $this->filesystem->get_contents( $file_path );
		if ( ! is_string( $js_content ) ) {
			return true;
		}

		$line_count = ( '' === $js_content ) ? 0 : substr_count( $js_content, "\n" ) + 1;

		if ( 10 >= $line_count ) {
			return true;
		}

		return false;
	}
}
