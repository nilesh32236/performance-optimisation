<?php
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
 */

class Main {

	private array $exclude_css = array( 'wppo-combine-css' );
	private array $exclude_js  = array(
		'jquery',
	);
	private $filesystem;

	private Image_Optimisation $image_optimisation;

	private $options;
	/**
	 * Constructor.
	 *
	 * Initializes the class by including necessary files and setting up hooks.
	 */
	public function __construct() {
		$this->options = get_option( 'wppo_settings', array() );

		$this->includes();
		$this->setup_hooks();
		$this->filesystem         = Util::init_filesystem();
		$this->image_optimisation = new Image_Optimisation( $this->options );
	}

	/**
	 * Include required files.
	 *
	 * Loads the autoloader and includes other class files needed for the plugin.
	 *
	 * @return void
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
	}

	/**
	 * Setup WordPress hooks.
	 *
	 * Registers actions and filters used by the plugin.
	 *
	 * @return void
	 */
	private function setup_hooks(): void {
		add_action( 'admin_menu', array( $this, 'init_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_filter( 'script_loader_tag', array( $this, 'add_defer_attribute' ), 10, 3 );
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
				$exclude_css = Util::process_urls( $this->options['file_optimisation']['excludeCSS'] );

				$this->exclude_css = array_merge( $this->exclude_css, (array) $exclude_css );
			}

			add_filter( 'style_loader_tag', array( $this, 'minify_css' ), 10, 3 );
		}

		add_action( 'wp_head', array( $this, 'add_preload_prefatch_preconnect' ), 1 );

		new Metabox();
		new Cron();
	}

	/**
	 * Initialize the admin menu.
	 *
	 * Adds the Performance Optimisation menu to the WordPress admin dashboard.
	 *
	 * @return void
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
	 */
	public function admin_page(): void {
		require_once WPPO_PLUGIN_PATH . 'templates/app.html';
	}

	private function add_available_post_types_to_options() {
		$post_types = get_post_types( array( 'public' => true ), 'names' );

		$excluded            = array( 'attachment' );
		$filtered_post_types = array_keys( array_diff( $post_types, $excluded ) );

		$this->options['image_optimisation']['availablePostTypes'] = $filtered_post_types;
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * Loads CSS and JavaScript files for the admin dashboard page.
	 *
	 * @return void
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

		wp_enqueue_style( 'performance-optimisation-style', WPPO_PLUGIN_URL . 'build/style-index.css', array(), WPPO_VERSION, 'all' );
		wp_enqueue_script( 'performance-optimisation-script', WPPO_PLUGIN_URL . 'build/index.js', array( 'wp-i18n', 'wp-element' ), WPPO_VERSION, true );

		$this->add_available_post_types_to_options();
		wp_localize_script(
			'performance-optimisation-script',
			'wppoSettings',
			array(
				'apiUrl'       => get_rest_url( null, 'performance-optimisation/v1/' ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'settings'     => $this->options,
				'image_info'   => get_option( 'wppo_img_info', array() ),
				'cache_size'   => Cache::get_cache_size(),
				'total_js_css' => Util::get_js_css_minified_file(),
				'translations' => array(
					'performanceSettings'      => __( 'Performance Settings', 'performance-optimisation' ),
					'dashboard'                => __( ' Dashboard', 'performance-optimisation' ),
					'fileOptimization'         => __( ' File Optimization', 'performance-optimisation' ),
					'preload'                  => __( ' Preload', 'performance-optimisation' ),
					'imageOptimization'        => __( ' Image Optimization', 'performance-optimisation' ),
					'tools'                    => __( ' Tools', 'performance-optimisation' ),
					'failedFetchActivities'    => __( 'Failed to fetch activities:', 'performance-optimisation' ),
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
					'lazyLoadImagesDesc'       => __( 'Enable lazy loading for images to improve the initial load speed by loading images only when they appear in the viewport.', 'performance-optimisation' ),
					'excludeFistImages'        => __( 'Enter number you want to exclude first', 'performance-optimisation' ),
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
				),
			),
		);
	}

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
			wp_enqueue_script( 'wppo-lazyload', WPPO_PLUGIN_URL . 'src/lazyload.js', array(), WPPO_VERSION, true );
		}
	}

	public function remove_woocommerce_scripts() {
		$exclude_url_to_keep_js_css = array();
		if ( isset( $this->options['file_optimisation']['excludeUrlToKeepJSCSS'] ) && ! empty( $this->options['file_optimisation']['excludeUrlToKeepJSCSS'] ) ) {
			$exclude_url_to_keep_js_css = Util::process_urls( $this->options['file_optimisation']['excludeUrlToKeepJSCSS'] );

			// Safely retrieve and sanitize the current URL
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

				if ( $current_url === $exclude_url ) {
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

		// Add a submenu under the custom setting
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
					'href'   => '#', // You can replace with actual URL or function if needed
					'meta'   => array(
						'title' => __( 'Clear cache for this specific page or post', 'performance-optimisation' ),
						'class' => 'page-' . $current_id,
					),
				)
			);
		}
	}

	/**
	 * Add defer attribute to scripts.
	 *
	 * Filters script tags to add the defer attribute for non-admin pages.
	 *
	 * @param string $tag    The script tag HTML.
	 * @param string $handle The script's registered handle.
	 * @param string $src    The script's source URL.
	 * @return string Modified script tag with defer attribute.
	 */
	public function add_defer_attribute( $tag, $handle, $src ): string {
		if ( is_user_logged_in() ) {
			return $tag;
		}

		$exclude_js = array( 'wppo-lazyload' );

		if ( isset( $this->options['file_optimisation']['deferJS'] ) && (bool) $this->options['file_optimisation']['deferJS'] ) {

			if ( isset( $this->options['file_optimisation']['excludeDeferJS'] ) && ! empty( $this->options['file_optimisation']['excludeDeferJS'] ) ) {
				$exclude_defer = Util::process_urls( $this->options['file_optimisation']['excludeDeferJS'] );

				$exclude_defer = array_merge( $exclude_js, (array) $exclude_defer );
			} else {
				$exclude_defer = $exclude_js;
			}

			if ( ! in_array( $handle, $exclude_defer, true ) ) {
				$tag = str_replace( ' src', ' defer="defer" src', $tag );
			}
		}

		if ( isset( $this->options['file_optimisation']['delayJS'] ) && (bool) $this->options['file_optimisation']['delayJS'] ) {

			if ( isset( $this->options['file_optimisation']['excludeDelayJS'] ) && ! empty( $this->options['file_optimisation']['excludeDelayJS'] ) ) {
				$exclude_delay = Util::process_urls( $this->options['file_optimisation']['excludeDelayJS'] );

				$exclude_delay = array_merge( $exclude_js, (array) $exclude_delay );
			} else {
				$exclude_delay = $exclude_js;
			}

			if ( ! in_array( $handle, $exclude_delay, true ) ) {
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

	public function add_preload_prefatch_preconnect() {

		$preload_settings = $this->options['preload_settings'] ?? array();

		// Preconnect origins
		if ( isset( $preload_settings['preconnect'] ) && (bool) $preload_settings['preconnect'] ) {
			if ( isset( $preload_settings['preconnectOrigins'] ) && ! empty( $preload_settings['preconnectOrigins'] ) ) {
				$preconnect_origins = Util::process_urls( $preload_settings['preconnectOrigins'] );

				foreach ( $preconnect_origins as $origin ) {
					Util::generate_preload_link( $origin, 'preconnect', '', true );
				}
			}
		}

		// Prefetch DNS origins
		if ( isset( $preload_settings['prefetchDNS'] ) && (bool) $preload_settings['prefetchDNS'] ) {
			if ( isset( $preload_settings['dnsPrefetchOrigins'] ) && ! empty( $preload_settings['dnsPrefetchOrigins'] ) ) {
				$dns_prefetch_origins = Util::process_urls( $preload_settings['dnsPrefetchOrigins'] );

				foreach ( $dns_prefetch_origins as $origin ) {
					Util::generate_preload_link( $origin, 'dns-prefetch' );
				}
			}
		}

		// Preload fonts
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
							$font_type = ''; // Fallback if unknown extension
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

	public function minify_css( $tag, $handle, $href ) {
		$local_path = Util::get_local_path( $href );

		if ( in_array( $handle, $this->exclude_css, true ) || empty( $href ) || $this->is_css_minified( $local_path ) || is_user_logged_in() ) {
			return $tag;
		}

		$css_minifier = new Minify\CSS( $local_path, WP_CONTENT_DIR . '/cache/wppo/min/css' );
		$cached_file  = $css_minifier->minify();

		if ( $cached_file ) {
			$file_version = fileatime( Util::get_local_path( $cached_file ) );
			$new_href     = content_url( 'cache/wppo/min/css/' . basename( $cached_file ) ) . '?ver=' . $file_version;
			$new_tag      = str_replace( $href, $new_href, $tag );
			return $new_tag;
		}

		return $tag;
	}

	public function minify_js( $tag, $handle, $src ) {
		$local_path = Util::get_local_path( $src );

		if ( in_array( $handle, $this->exclude_js, true ) || empty( $src ) || $this->is_js_minified( $local_path ) || is_user_logged_in() ) {
			return $tag;
		}

		$js_minifier = new Minify\JS( $local_path, WP_CONTENT_DIR . '/cache/wppo/min/js' );
		$cached_file = $js_minifier->minify();

		if ( $cached_file ) {
			$file_version = fileatime( Util::get_local_path( $cached_file ) );

			$new_src = content_url( 'cache/wppo/min/js/' . basename( $cached_file ) ) . '?ver=' . $file_version;
			$new_tag = str_replace( $src, $new_src, $tag );
			return $new_tag;
		}

		return $tag;
	}

	private function is_css_minified( $file_path ) {
		$file_name = basename( $file_path );

		if ( preg_match( '/(\.min\.css|\.bundle\.css|\.bundle\.min\.css)$/i', $file_name ) ) {
			return true;
		}

		$css_content = $this->filesystem->get_contents( $file_path );
		$line        = preg_split( '/\r\n|\r|\n/', $css_content );

		if ( 10 >= count( $line ) ) {
			return true;
		}

		return false;
	}
	private function is_js_minified( $file_path ) {
		$file_name = basename( $file_path );

		if ( preg_match( '/(\.min\.js|\.bundle\.js|\.bundle\.min\.js)$/i', $file_name ) ) {
			return true;
		}

		$js_content = $this->filesystem->get_contents( $file_path );
		$line       = preg_split( '/\r\n|\r|\n/', $js_content );

		if ( 10 >= count( $line ) ) {
			return true;
		}

		return false;
	}
}
