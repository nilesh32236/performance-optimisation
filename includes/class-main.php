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
 * Handles the inclusion of necessary files, setup of hooks, and core functionalities.
 *
 * @since 1.0.0
 */
final class Main {

	/**
	 * The single instance of the class.
	 *
	 * @var Main|null
	 */
	private static ?Main $instance = null;

	/**
	 * Options for performance optimisation settings.
	 *
	 * @var array<string, mixed>
	 */
	private array $options;

	/**
	 * Image Optimisation instance.
	 *
	 * @var Image_Optimisation|null
	 */
	private ?Image_Optimisation $image_optimisation = null;

	/**
	 * Get the singleton instance of the class.
	 *
	 * @return Main
	 */
	public static function get_instance(): Main {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * Initializes the class by loading dependencies and setting up hooks.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->options = get_option( 'wppo_settings', array() );

		$this->load_dependencies();
		$this->setup_hooks();

		if ( ! empty( $this->options['image_optimisation']['convertImg'] ) || ! empty( $this->options['image_optimisation']['lazyLoadImages'] ) ) {
			$this->image_optimisation = new Image_Optimisation( $this->options );
		}
	}

	/**
	 * Load required dependencies.
	 *
	 * @since 1.0.0
	 */
	private function load_dependencies(): void {
		if ( file_exists( WPPO_PLUGIN_PATH . 'vendor/autoload.php' ) ) {
			require_once WPPO_PLUGIN_PATH . 'vendor/autoload.php';
		}

		$class_files = array(
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

		foreach ( $class_files as $file ) {
			$path = WPPO_PLUGIN_PATH . 'includes/' . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Setup WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	private function setup_hooks(): void {
		add_action( 'admin_menu', array( $this, 'init_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_wizard' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_bar_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_scripts' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_settings_to_admin_bar' ), 100 );
		add_action( 'wp_head', array( $this, 'add_preload_prefetch_preconnect_links' ), 1 );

		// Script and Style tag modification.
		add_filter( 'script_loader_tag', array( $this, 'modify_script_loader_tag' ), 20, 3 );
		add_filter( 'style_loader_tag', array( $this, 'modify_style_loader_tag' ), 20, 3 );

		// WooCommerce asset removal.
		if ( ! empty( $this->options['file_optimisation']['removeWooCSSJS'] ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'conditionally_remove_woocommerce_assets' ), 999 );
		}

		// Caching hooks.
		$cache_manager = new Cache();
		add_action( 'template_redirect', array( $cache_manager, 'generate_dynamic_static_html' ), 5 );
		add_action( 'save_post', array( $cache_manager, 'invalidate_dynamic_static_html' ) );

		if ( ! empty( $this->options['file_optimisation']['combineCSS'] ) ) {
			add_action( 'wp_print_styles', array( $cache_manager, 'combine_css' ), PHP_INT_MAX - 10 );
		}

		// REST API, Metabox, and Cron initialization.
		add_action( 'rest_api_init', array( new Rest(), 'register_routes' ) );
		new Metabox();
		new Cron();
	}

	/**
	 * Initialize the admin menu and associated asset loading hook.
	 *
	 * @since 1.0.0
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

		// Add hidden wizard page
		$wizard_hook_suffix = add_submenu_page(
			null, // Hidden from menu
			__( 'Performance Optimisation Setup', 'performance-optimisation' ),
			__( 'Setup Wizard', 'performance-optimisation' ),
			'manage_options',
			'performance-optimisation-setup',
			array( $this, 'render_wizard_page' )
		);
		add_action( "load-{$wizard_hook_suffix}", array( $this, 'load_wizard_page_assets' ) );
	}

	/**
	 * Check if we should redirect to the setup wizard.
	 *
	 * @since 1.0.0
	 */
	public function maybe_redirect_to_wizard(): void {
		// Only redirect on admin pages
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Don't redirect if we're already on the wizard page
		if ( isset( $_GET['page'] ) && 'performance-optimisation-setup' === $_GET['page'] ) {
			return;
		}

		// Don't redirect if wizard is already completed
		if ( get_option( 'wppo_setup_wizard_completed', false ) ) {
			return;
		}

		// Use transient to prevent redirect loops
		$redirect_done = get_transient( 'wppo_wizard_redirect_done' );
		if ( $redirect_done ) {
			return;
		}

		// Set transient to prevent multiple redirects
		set_transient( 'wppo_wizard_redirect_done', true, HOUR_IN_SECONDS );

		// Redirect to wizard
		wp_safe_redirect( admin_url( 'admin.php?page=performance-optimisation-setup' ) );
		exit;
	}

	/**
	 * Display the admin page.
	 *
	 * @since 1.0.0
	 */
	public function render_admin_page(): void {
		echo '<div class="wrap"><div id="performance-optimisation-admin-app"></div></div>';
	}

	/**
	 * Display the wizard page.
	 *
	 * @since 1.0.0
	 */
	public function render_wizard_page(): void {
		echo '<div class="wrap">';
		echo '<div id="performance-optimisation-wizard-app">';

		// Fallback content for JavaScript-disabled environments
		echo '<noscript>';
		echo '<div class="wppo-wizard-fallback">';
		echo '<h1>' . __( 'Performance Optimisation Setup', 'performance-optimisation' ) . '</h1>';
		echo '<div class="notice notice-warning">';
		echo '<p>' . __( 'This setup wizard requires JavaScript to function properly. Please enable JavaScript in your browser and refresh this page.', 'performance-optimisation' ) . '</p>';
		echo '<p>' . __( 'Alternatively, you can configure the plugin settings manually from the', 'performance-optimisation' ) . ' ';
		echo '<a href="' . admin_url( 'admin.php?page=performance-optimisation' ) . '">' . __( 'main settings page', 'performance-optimisation' ) . '</a>.';
		echo '</p>';
		echo '</div>';
		echo '</div>';
		echo '</noscript>';

		// Loading indicator while JavaScript loads
		echo '<div class="wppo-wizard-loading-initial" style="text-align: center; padding: 50px;">';
		echo '<div class="spinner is-active" style="float: none; margin: 0 auto 20px;"></div>';
		echo '<p>' . __( 'Loading setup wizard...', 'performance-optimisation' ) . '</p>';
		echo '</div>';

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Enqueue scripts and styles for the plugin's admin settings page.
	 *
	 * @since 1.0.0
	 */
	public function load_plugin_admin_page_assets(): void {
		$asset_file = include WPPO_PLUGIN_PATH . 'build/index.asset.php';

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

		$public_post_types    = get_post_types( array( 'public' => true ), 'objects' );
		$available_post_types = array();
		foreach ( $public_post_types as $slug => $pt ) {
			if ( ! in_array( $slug, array( 'attachment', 'revision', 'nav_menu_item' ), true ) ) {
				$available_post_types[] = array(
					'value' => $slug,
					'label' => $pt->label,
				);
			}
		}

		wp_localize_script(
			'performance-optimisation-admin-script',
			'wppoAdminData',
			array(
				'apiUrl'         => rest_url( Rest::NAMESPACE . '/' ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'settings'       => $this->options,
				'imageInfo'      => get_option( 'wppo_img_info', array() ),
				'cacheSize'      => Cache::get_cache_size(),
				'minifiedAssets' => Util::get_js_css_minified_file(),
				'uiData'         => array( 'availablePostTypes' => $available_post_types ),
				'pluginVersion'  => WPPO_VERSION,
				'translations'   => $this->get_javascript_translations(),
			)
		);
		wp_set_script_translations( 'performance-optimisation-admin-script', 'performance-optimisation', WPPO_PLUGIN_PATH . 'languages' );
		wp_enqueue_style( 'wp-components' );
	}

	/**
	 * Provides a centralized list of translations for JavaScript.
	 *
	 * @return array<string,string> Key-value pairs of translatable strings.
	 */
	private function get_javascript_translations(): array {
		return array(
			// General UI.
			'dashboard'             => __( 'Dashboard', 'performance-optimisation' ),
			'fileOptimization'      => __( 'File Optimization', 'performance-optimisation' ),
			'imageOptimization'     => __( 'Image Optimization', 'performance-optimisation' ),
			'preloadSettings'       => __( 'Preload & Preconnect', 'performance-optimisation' ),
			'tools'                 => __( 'Tools', 'performance-optimisation' ),
			'activityLog'           => __( 'Activity Log', 'performance-optimisation' ),
			'saveSettings'          => __( 'Save Settings', 'performance-optimisation' ),
			'saving'                => __( 'Saving...', 'performance-optimisation' ),

			// Statuses & Actions.
			'completed'             => __( 'Completed', 'performance-optimisation' ),
			'pending'               => __( 'Pending', 'performance-optimisation' ),
			'failed'                => __( 'Failed', 'performance-optimisation' ),
			'skipped'               => __( 'Skipped', 'performance-optimisation' ),
			'clearCacheNow'         => __( 'Clear All Cache Now', 'performance-optimisation' ),
			'clearingCache'         => __( 'Clearing Cache...', 'performance-optimisation' ),
			'optimiseImagesNow'     => __( 'Optimize Pending Images Now', 'performance-optimisation' ),
			'optimizingImages'      => __( 'Optimizing Images...', 'performance-optimisation' ),
			'deleteOptimizedImages' => __( 'Delete All Converted Images', 'performance-optimisation' ),
			'deletingImages'        => __( 'Deleting Images...', 'performance-optimisation' ),

			// Settings Fields.
			'minifyJS'              => __( 'Minify JavaScript Files', 'performance-optimisation' ),
			'minifyCSS'             => __( 'Minify CSS Files', 'performance-optimisation' ),
			'combineCSS'            => __( 'Combine CSS Files', 'performance-optimisation' ),
			'minifyHTML'            => __( 'Minify HTML Output', 'performance-optimisation' ),
			'deferJS'               => __( 'Defer Non-Essential JavaScript', 'performance-optimisation' ),
			'delayJS'               => __( 'Delay JavaScript Execution', 'performance-optimisation' ),
			'lazyLoadImages'        => __( 'Lazy Load Images', 'performance-optimisation' ),
			'convertImg'            => __( 'Enable Next-Gen Image Conversion (WebP/AVIF)', 'performance-optimisation' ),
			'enablePreloadCache'    => __( 'Enable Page Preloading (Static Cache Generation)', 'performance-optimisation' ),
			'enableCronJobs'        => __( 'Enable Plugin Cron Jobs', 'performance-optimisation' ),

			// Re-run wizard functionality.
			'setupWizard'           => __( 'Setup Wizard', 'performance-optimisation' ),
			'setupWizardDesc'       => __( 'Re-run the setup wizard to reconfigure your performance optimization settings.', 'performance-optimisation' ),
			'rerunSetupWizard'      => __( 'Re-run Setup Wizard', 'performance-optimisation' ),
			'confirmRerunWizard'    => __( 'Are you sure you want to re-run the setup wizard? This will reset the wizard and allow you to reconfigure your settings.', 'performance-optimisation' ),
			'errorRerunWizard'      => __( 'Error resetting setup wizard.', 'performance-optimisation' ),

			// Wizard completion status.
			'wizardCompleted'       => get_option( 'wppo_setup_wizard_completed', false ),
		);
	}

	/**
	 * Enqueue scripts and styles for the wizard page.
	 *
	 * @since 1.0.0
	 */
	public function load_wizard_page_assets(): void {
		$asset_file = include WPPO_PLUGIN_PATH . 'build/wizard.asset.php';

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

		wp_localize_script(
			'performance-optimisation-wizard-script',
			'wppoWizardData',
			array(
				'apiUrl'       => rest_url( Rest::NAMESPACE . '/' ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'translations' => $this->get_wizard_translations(),
			)
		);
		wp_set_script_translations( 'performance-optimisation-wizard-script', 'performance-optimisation', WPPO_PLUGIN_PATH . 'languages' );
		wp_enqueue_style( 'wp-components' );
	}

	/**
	 * Provides translations for the wizard JavaScript.
	 *
	 * @return array<string,string> Key-value pairs of translatable strings.
	 */
	private function get_wizard_translations(): array {
		return array(
			'welcomeTitle'       => __( 'Welcome to Performance Optimisation!', 'performance-optimisation' ),
			'welcomeDescription' => __( 'Let\'s make your site fast in just a few clicks.', 'performance-optimisation' ),
			'letsGetStarted'     => __( 'Let\'s Get Started', 'performance-optimisation' ),
			'nextStep'           => __( 'Next', 'performance-optimisation' ),
			'previousStep'       => __( 'Back', 'performance-optimisation' ),
			'finishSetup'        => __( 'Finish Setup & Start Optimizing', 'performance-optimisation' ),
			'standardPreset'     => __( 'Standard (Safe)', 'performance-optimisation' ),
			'recommendedPreset'  => __( 'Recommended (Balanced)', 'performance-optimisation' ),
			'aggressivePreset'   => __( 'Aggressive (Maximum Speed)', 'performance-optimisation' ),
			'recommended'        => __( 'Recommended', 'performance-optimisation' ),
			'preloadCache'       => __( 'Automatically prepare cached versions of your pages for faster delivery.', 'performance-optimisation' ),
			'imageConversion'    => __( 'Automatically convert uploaded images to modern, faster formats (like WebP).', 'performance-optimisation' ),
			'setupComplete'      => __( 'All done! Performance Optimisation is now speeding up your site.', 'performance-optimisation' ),
			'goToDashboard'      => __( 'Go to the Dashboard', 'performance-optimisation' ),
		);
	}

	/**
	 * Enqueues frontend scripts, like lazyload.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_frontend_scripts(): void {
		if ( is_admin() || is_user_logged_in() ) {
			return;
		}

		$lazyload_images_enabled = ! empty( $this->options['image_optimisation']['lazyLoadImages'] );
		$lazyload_videos_enabled = ! empty( $this->options['image_optimisation']['lazyLoadVideos'] );

		if ( $lazyload_images_enabled || $lazyload_videos_enabled ) {
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
					'apiUrl'   => rest_url( Rest::NAMESPACE ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'pagePath' => is_singular() ? ltrim( wp_parse_url( get_permalink(), PHP_URL_PATH ), '/' ) : '',
					'i18n'     => array(
						'confirmClearPage' => __( 'Are you sure you want to clear the cache for this page?', 'performance-optimisation' ),
						'confirmClearAll'  => __( 'Are you sure you want to clear ALL cache?', 'performance-optimisation' ),
					),
				)
			);
		}
	}

	/**
	 * Removes WooCommerce-related scripts and styles on non-WooCommerce pages.
	 *
	 * @since 1.0.0
	 */
	public function conditionally_remove_woocommerce_assets(): void {
		if ( ! class_exists( 'WooCommerce' ) || is_woocommerce() || is_cart() || is_checkout() || is_account_page() ) {
			return;
		}

		// Additional logic to check against excluded pages can be added here if needed.

		// Default handles to remove. Can be made filterable if needed.
		$styles_to_remove  = array( 'woocommerce-layout', 'woocommerce-smallscreen', 'woocommerce-general' );
		$scripts_to_remove = array( 'wc-cart-fragments', 'woocommerce', 'wc-add-to-cart' );

		foreach ( $styles_to_remove as $handle ) {
			wp_dequeue_style( $handle );
		}
		foreach ( $scripts_to_remove as $handle ) {
			wp_dequeue_script( $handle );
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
	 * @param string $tag    The <script> tag.
	 * @param string $handle The script's handle.
	 * @param string $src    The script's source URL.
	 * @return string Modified script tag.
	 */
	public function modify_script_loader_tag( string $tag, string $handle, string $src ): string {
		if ( is_user_logged_in() || is_admin() || empty( $src ) ) {
			return $tag;
		}

		// Minification logic can be added here if not handled by a combination step.

		$should_defer = ! empty( $this->options['file_optimisation']['deferJS'] ) && ! $this->is_handle_excluded( $handle, 'defer_js' );
		$should_delay = ! empty( $this->options['file_optimisation']['delayJS'] ) && ! $this->is_handle_excluded( $handle, 'delay_js' );

		if ( $should_delay ) {
			$tag = str_replace( ' src=', ' data-wppo-src=', $tag );
			if ( preg_match( '/type=(["\'])(.*?)\1/', $tag, $type_match ) ) {
				$tag = str_replace( $type_match[0], 'type="wppo/javascript" data-wppo-type="' . esc_attr( $type_match[2] ) . '"', $tag );
			} else {
				$tag = str_replace( '<script', '<script type="wppo/javascript"', $tag );
			}
		} elseif ( $should_defer ) {
			if ( strpos( $tag, 'type="module"' ) === false ) {
				$tag = str_replace( ' src=', ' defer src=', $tag );
			}
		}

		return $tag;
	}

	/**
	 * Modifies style loader tag for minification.
	 *
	 * @since 1.0.0
	 * @param string $tag    The <link> tag.
	 * @param string $handle The style's handle.
	 * @param string $href   The style's source URL.
	 * @return string Modified style tag.
	 */
	public function modify_style_loader_tag( string $tag, string $handle, string $href ): string {
		if ( is_user_logged_in() || is_admin() || empty( $href ) ) {
			return $tag;
		}

		if ( ! empty( $this->options['file_optimisation']['minifyCSS'] ) && ! $this->is_handle_excluded( $handle, 'css' ) && ! Util::is_already_minified( $href ) ) {
			$minifier     = new Minify\CSS( Util::get_local_path( $href ), wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/min/css' ) );
			$minified_url = $minifier->minify();
			if ( $minified_url ) {
				$tag = str_replace( esc_url( $href ), esc_url( $minified_url ), $tag );
			}
		}
		return $tag;
	}

	/**
	 * Checks if a handle is excluded based on settings.
	 *
	 * @param string $handle Handle of the asset.
	 * @param string $type   Type of exclusion list ('js', 'css', etc.).
	 * @return bool True if excluded.
	 */
	private function is_handle_excluded( string $handle, string $type ): bool {
		$key_map = array(
			'js'          => 'excludeJS',
			'css'         => 'excludeCSS',
			'combine_css' => 'excludeCombineCSS',
			'defer_js'    => 'excludeDeferJS',
			'delay_js'    => 'excludeDelayJS',
		);

		if ( ! isset( $key_map[ $type ] ) ) {
			return false;
		}

		$exclusions         = Util::process_urls( $this->options['file_optimisation'][ $key_map[ $type ] ] ?? '' );
		$default_exclusions = array( 'jquery', 'jquery-core', 'jquery-migrate', 'wppo-lazyload' );
		$all_exclusions     = array_unique( array_merge( $exclusions, $default_exclusions ) );

		return in_array( $handle, $all_exclusions, true );
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

		$settings   = $this->options['preload_settings'] ?? array();
		$link_types = array(
			'preconnect'   => 'preconnectOrigins',
			'dns-prefetch' => 'dnsPrefetchOrigins',
			'preload-font' => 'preloadFontsUrls',
			'preload-css'  => 'preloadCSSUrls',
		);

		foreach ( $link_types as $rel => $setting_key ) {
			if ( ! empty( $settings[ $setting_key ] ) ) {
				$urls = Util::process_urls( (string) $settings[ $setting_key ] );
				foreach ( $urls as $url ) {
					Util::generate_resource_hint_link( $rel, $url );
				}
			}
		}

		if ( $this->image_optimisation ) {
			$this->image_optimisation->preload_images_on_page_load();
		}
	}

	/**
	 * Check if the setup wizard has been completed.
	 *
	 * @since 1.0.0
	 * @return bool True if wizard is completed, false otherwise.
	 */
	public function is_wizard_completed(): bool {
		return (bool) get_option( 'wppo_setup_wizard_completed', false );
	}

	/**
	 * Get wizard completion analytics data.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed> Analytics data about wizard usage.
	 */
	public function get_wizard_analytics(): array {
		$completion_time  = get_option( 'wppo_wizard_completion_time' );
		$selected_preset  = get_option( 'wppo_wizard_selected_preset' );
		$enabled_features = get_option( 'wppo_wizard_enabled_features', array() );

		return array(
			'completed'        => $this->is_wizard_completed(),
			'completion_time'  => $completion_time,
			'selected_preset'  => $selected_preset,
			'enabled_features' => $enabled_features,
			'reset_count'      => get_option( 'wppo_wizard_reset_count', 0 ),
		);
	}

	/**
	 * Ensure wizard settings are compatible with existing plugin features.
	 *
	 * @since 1.0.0
	 * @return bool True if settings are compatible, false if conflicts exist.
	 */
	public function validate_wizard_compatibility(): bool {
		$current_settings = get_option( 'wppo_settings', array() );

		// Check for any critical conflicts
		$conflicts = array();

		// Example: Check if caching is enabled but server doesn't support it
		if ( ! empty( $current_settings['cache_settings']['enablePageCaching'] ) ) {
			if ( ! is_writable( WP_CONTENT_DIR ) ) {
				$conflicts[] = 'Cache directory not writable';
			}
		}

		// Log any conflicts found
		if ( ! empty( $conflicts ) ) {
			new Log( 'Wizard compatibility issues found: ' . implode( ', ', $conflicts ) );
			return false;
		}

		return true;
	}
}
