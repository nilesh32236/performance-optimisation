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
				'performance_audit'  => array(
					'pagespeed_api_key' => '',
					'high_value_urls'   => array(),
					'auto_fix_enabled'  => false,
				),
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
		if ( file_exists( WPPO_PLUGIN_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
			require_once WPPO_PLUGIN_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
		}
		require_once WPPO_PLUGIN_PATH . 'includes/class-log.php';
		require_once WPPO_PLUGIN_PATH . 'includes/class-util.php';
		require_once WPPO_PLUGIN_PATH . 'includes/minify/class-html.php';
		require_once WPPO_PLUGIN_PATH . 'includes/minify/class-css.php';
		require_once WPPO_PLUGIN_PATH . 'includes/minify/class-js.php';
		require_once WPPO_PLUGIN_PATH . 'includes/class-cache.php';
		require_once WPPO_PLUGIN_PATH . 'includes/class-metabox.php';
		require_once WPPO_PLUGIN_PATH . 'includes/class-image-optimisation.php';
		require_once WPPO_PLUGIN_PATH . 'includes/class-img-converter.php';
		require_once WPPO_PLUGIN_PATH . 'includes/class-cron.php';
		require_once WPPO_PLUGIN_PATH . 'includes/class-rest.php';
		require_once WPPO_PLUGIN_PATH . 'includes/class-database-cleanup.php';
		require_once WPPO_PLUGIN_PATH . 'includes/class-asset-manager.php';
		require_once WPPO_PLUGIN_PATH . 'includes/class-htaccess-handler.php';
		require_once WPPO_PLUGIN_PATH . 'includes/class-server-rules.php';
		require_once WPPO_PLUGIN_PATH . 'includes/class-core-tweaks.php';
		require_once WPPO_PLUGIN_PATH . 'includes/class-object-cache.php';

		// Phase 1 & 2 — Diagnostics & PageSpeed (v1.5.0-1.6.0).
		// Load on admin, AJAX, Cron, or REST API requests.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			require_once WPPO_PLUGIN_PATH . 'includes/class-telemetry.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-system-info.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-pagespeed.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-suggestion-engine.php';
		}

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
		add_action( 'admin_init', array( $this, 'maybe_fix_wp_cache' ) );
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

		// Phase 2 — Register Action Scheduler callback for background PageSpeed scans.
		add_action( 'wppo_pagespeed_scan', array( 'PerformanceOptimise\Inc\Pagespeed', 'run_scan' ), 10, 1 );

		// Clear all cache on structural changes that invalidate every cached page.
		add_action( 'permalink_structure_changed', array( __CLASS__, 'clear_all_cache' ) );
		add_action( 'switch_theme', array( __CLASS__, 'clear_all_cache' ) );
		add_action( 'update_option_wppo_settings', array( __CLASS__, 'on_settings_update' ), 10, 2 );
		add_action( 'activated_plugin', array( __CLASS__, 'clear_all_cache' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'clear_all_cache' ) );

		add_action( 'wp_ajax_wppo_get_nonce', array( $rest, 'ajax_get_nonce' ) );
	}

	/**
	 * Automatically try to fix WP_CACHE if it is missing or disabled.
	 *
	 * Runs on admin_init.
	 *
	 * @return void
	 */
	public function maybe_fix_wp_cache(): void {
		if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
			return;
		}

		// Only run this check once per hour to avoid constant I/O.
		if ( get_transient( 'wppo_wp_cache_fix_checked' ) ) {
			return;
		}

		require_once WPPO_PLUGIN_PATH . 'includes/class-activate.php';
		$notices = Activate::add_wp_cache_constant();

		if ( empty( $notices ) ) {
			// Success — throttle for 1 hour.
			set_transient( 'wppo_wp_cache_fix_checked', 1, HOUR_IN_SECONDS );
		} else {
			// Failure — merge notice keys into existing transient to notify user immediately.
			$existing_notices = get_transient( 'wppo_activation_notices' );
			$existing_notices = is_array( $existing_notices ) ? $existing_notices : array();
			$new_notices      = array_unique( array_merge( $existing_notices, (array) $notices ) );
			set_transient( 'wppo_activation_notices', $new_notices, 30 );
		}
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
					'apiUrl'  => get_rest_url( null, 'performance-optimisation/v1' ),
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
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
				'apiUrl'            => get_rest_url( null, 'performance-optimisation/v1/' ),
				'nonce'             => wp_create_nonce( 'wp_rest' ),
				'settings'          => $this->options,
				'image_info'        => get_option( 'wppo_img_info', array() ),
				'cache_size'        => $cache_size,
				'total_js_css'      => $total_js_css,
				'performance_audit' => array(
					'homeUrl'                   => home_url( '/' ),
					'pagespeedApiKeyConfigured' => ! empty( $this->options['performance_audit']['pagespeed_api_key'] ),
					'highValueUrls'             => $this->options['performance_audit']['high_value_urls'] ?? array(), // Phase 3 will populate this.
					'autoFixEnabled'            => (bool) ( $this->options['performance_audit']['auto_fix_enabled'] ?? false ),
				),
				// Frontend theme colors for accent syncing.
				'themeColors'       => $this->get_frontend_theme_colors(),
			),
		);

		wp_set_script_translations( 'performance-optimisation-script', 'performance-optimisation' );
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
	 * Dequeues configured WooCommerce CSS and JS handles unless the current URL is excluded.
	 *
	 * Reads `file_optimisation.excludeUrlToKeepJSCSS` and, if the current front-end URL matches any entry
	 * (exact match or prefix match when an entry contains the `(.*)` suffix), preserves scripts/styles.
	 * Otherwise reads `file_optimisation.removeCssJsHandle` and dequeues each entry prefixed with
	 * `style:` (dequeues a style handle) or `script:` (dequeues a script handle).
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

			foreach ( $exclude_url_to_keep_js_css as $exclude_url ) {
				if ( 0 !== strpos( $exclude_url, 'http' ) ) {
					$exclude_url = home_url( $exclude_url );
				}

				if ( false !== strpos( $exclude_url, '(.*)' ) ) {
					$exclude_prefix = str_replace( '(.*)', '', $exclude_url );

					if ( 0 === strpos( untrailingslashit( $current_url ), untrailingslashit( $exclude_prefix ) ) ) {
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
		if ( ! empty( $preload_settings['preconnect'] ) && ! empty( $preload_settings['preconnectOrigins'] ) ) {
			$preconnect_origins = Util::process_urls( $preload_settings['preconnectOrigins'] );

			foreach ( $preconnect_origins as $origin ) {
				Util::generate_preload_link( $origin, 'preconnect', '', true );
			}
		}

		// Prefetch DNS origins.
		if ( ! empty( $preload_settings['prefetchDNS'] ) && ! empty( $preload_settings['dnsPrefetchOrigins'] ) ) {
			$dns_prefetch_origins = Util::process_urls( $preload_settings['dnsPrefetchOrigins'] );

			foreach ( $dns_prefetch_origins as $origin ) {
				Util::generate_preload_link( $origin, 'dns-prefetch' );
			}
		}

		// Preload fonts.
		if ( ! empty( $preload_settings['preloadFonts'] ) && ! empty( $preload_settings['preloadFontsUrls'] ) ) {
			$preload_fonts_urls = Util::process_urls( $preload_settings['preloadFontsUrls'] );

			foreach ( $preload_fonts_urls as $font_url ) {
				$font_url       = preg_match( '/^https?:\/\//i', $font_url ) ? $font_url : content_url( $font_url );
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
				}

				Util::generate_preload_link( $font_url, 'preload', 'font', true, $font_type );
			}
		}

		// Preload CSS.
		if ( ! empty( $preload_settings['preloadCSS'] ) && ! empty( $preload_settings['preloadCSSUrls'] ) ) {
			$preload_css_urls = Util::process_urls( $preload_settings['preloadCSSUrls'] );

			foreach ( $preload_css_urls as $css_url ) {
				$css_url = preg_match( '/^https?:\/\//i', $css_url ) ? $css_url : content_url( $css_url );
				Util::generate_preload_link( $css_url, 'preload', 'style' );
			}
		}

		$this->image_optimisation->preload_images();
	}

	/**
	 * Checks if an asset name (URL or file path) indicates it is already minified.
	 *
	 * @since 1.5.1
	 *
	 * @param  string $url_or_path The asset URL or local file path.
	 * @param  string $ext         The asset extension (css or js).
	 * @return bool True if the asset name indicates it's minified.
	 */
	private function is_minified_asset_name( string $url_or_path, string $ext ): bool {
		if ( empty( $url_or_path ) ) {
			return false;
		}

		$path = wp_parse_url( $url_or_path, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			$path = $url_or_path;
		}

		return (bool) preg_match( '/(\.min|\.bundle|-min)\.' . preg_quote( $ext, '/' ) . '$/i', $path );
	}

	/**
	 * Rewrites CSS link tags to use minified versions if they exist.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $tag    The link tag HTML.
	 * @param  string $handle The CSS file's handle.
	 * @param  string $href   The CSS file's source URL.
	 * @return string Modified link tag with minified CSS.
	 */
	public function minify_css( $tag, $handle, $href ) {
		// Early return for logged-in users, empty URLs, or excluded handles
		// to avoid the expensive Util::get_local_path() computation.
		if ( is_user_logged_in() || empty( $href ) || in_array( $handle, $this->exclude_css, true ) ) {
			return $tag;
		}

		// Early return if the URL already indicates a minified file.
		if ( $this->is_minified_asset_name( $href, 'css' ) ) {
			return $tag;
		}

		$local_path = Util::get_local_path( $href );

		if ( $this->is_css_minified( $local_path ) ) {
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
	 * Rewrites script tags to use minified versions if they exist.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $tag    The script tag HTML.
	 * @param  string $handle The script's registered handle.
	 * @param  string $src    The script's source URL.
	 * @return string Modified script tag with minified JavaScript.
	 */
	public function minify_js( $tag, $handle, $src ) {
		// Early return for logged-in users, empty URLs, or excluded handles
		// to avoid the expensive Util::get_local_path() computation.
		if ( is_user_logged_in() || empty( $src ) || in_array( $handle, $this->exclude_js, true ) ) {
			return $tag;
		}

		// Early return if the URL already indicates a minified file.
		if ( $this->is_minified_asset_name( $src, 'js' ) ) {
			return $tag;
		}

		$local_path = Util::get_local_path( $src );

		if ( $this->is_js_minified( $local_path ) ) {
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

		if ( $this->is_minified_asset_name( $file_path, 'css' ) ) {
			return true;
		}

		if ( ! file_exists( $file_path ) ) {
			return true;
		}

		// Cache the file line count check to avoid expensive fopen/fgets on every page load.
		$cache_key = 'is_min_css_' . md5( $file_path . filemtime( $file_path ) );
		$cached    = wp_cache_get( $cache_key, 'wppo_assets', false, $found );

		if ( $found ) {
			return (bool) $cached;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return true;
		}

		$line_count = 0;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
		while ( false !== fgets( $handle ) ) {
			++$line_count;
			if ( $line_count > 10 ) {
				break;
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		$is_minified = 10 >= $line_count;
		wp_cache_set( $cache_key, (int) $is_minified, 'wppo_assets' );

		return $is_minified;
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

		if ( $this->is_minified_asset_name( $file_path, 'js' ) ) {
			return true;
		}

		if ( ! file_exists( $file_path ) ) {
			return true;
		}

		// Cache the file line count check to avoid expensive fopen/fgets on every page load.
		$cache_key = 'is_min_js_' . md5( $file_path . filemtime( $file_path ) );
		$cached    = wp_cache_get( $cache_key, 'wppo_assets', false, $found );

		if ( $found ) {
			return (bool) $cached;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return true;
		}

		$line_count = 0;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
		while ( false !== fgets( $handle ) ) {
			++$line_count;
			if ( $line_count > 10 ) {
				break;
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		$is_minified = 10 >= $line_count;
		wp_cache_set( $cache_key, (int) $is_minified, 'wppo_assets' );

		return $is_minified;
	}
}
