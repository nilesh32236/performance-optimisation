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

	private array $minify_exclude = array(
		'jquery',
	);

	/**
	 * Constructor.
	 *
	 * Initializes the class by including necessary files and setting up hooks.
	 */
	public function __construct() {
		$this->includes();
		$this->setup_hooks();
	}

	/**
	 * Include required files.
	 *
	 * Loads the autoloader and includes other class files needed for the plugin.
	 *
	 * @return void
	 */
	private function includes(): void {
		require_once QTPO_PLUGIN_PATH . 'vendor/autoload.php';
		require_once QTPO_PLUGIN_PATH . 'includes/class-log.php';
		require_once QTPO_PLUGIN_PATH . 'includes/class-util.php';
		require_once QTPO_PLUGIN_PATH . 'includes/class-webp-converter.php';
		require_once QTPO_PLUGIN_PATH . 'includes/minify/class-html.php';
		require_once QTPO_PLUGIN_PATH . 'includes/minify/class-css.php';
		require_once QTPO_PLUGIN_PATH . 'includes/minify/class-js.php';
		require_once QTPO_PLUGIN_PATH . 'includes/class-cache.php';
		require_once QTPO_PLUGIN_PATH . 'includes/class-cron.php';
		require_once QTPO_PLUGIN_PATH . 'includes/class-rest.php';
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

		add_action( 'wp_enqueue_scripts', array( $this, 'minify_assets' ) );
		$cache = new Cache();
		add_action( 'template_redirect', array( $cache, 'generate_dynamic_static_html' ) );
		add_action( 'save_post', array( $cache, 'invalidate_dynamic_static_html' ) );

		$rest = new Rest();
		add_action( 'rest_api_init', array( $rest, 'register_routes' ) );

		add_filter( 'wp_generate_attachment_metadata', array( $this, 'convert_images_to_webp' ), 10, 2 );
		add_filter( 'wp_get_attachment_image_src', array( $this, 'maybe_serve_webp_image' ), 10, 4 );

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
		require_once QTPO_PLUGIN_PATH . 'templates/app.html';
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

		if ( 'toplevel_page_performance-optimisation' !== $screen->base ) {
			return;
		}

		wp_enqueue_style( 'performance-optimisation-style', QTPO_PLUGIN_URL . 'build/style-index.css', array(), '1.0.0', 'all' );
		wp_enqueue_script( 'performance-optimisation-script', QTPO_PLUGIN_URL . 'build/index.js', array( 'wp-element' ), '1.0.0', true );

		wp_localize_script(
			'performance-optimisation-script',
			'qtpoSettings',
			array(
				'apiUrl'     => get_rest_url( null, 'performance-optimisation/v1/' ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'settings'   => get_option( 'qtpo_settings', array() ),
				'cache_size' => Cache::get_cache_size(),
			),
		);
	}

	public function enqueue_scripts() {
		if ( is_admin_bar_showing() ) {
			wp_enqueue_script( 'qtpo-admin-bar-script', QTPO_PLUGIN_URL . 'src/main.js', array(), '1.0.0', true );
			wp_localize_script(
				'qtpo-admin-bar-script',
				'qtpoObject',
				array(
					'apiUrl' => get_rest_url( null, 'performance-optimisation/v1' ),
					'nonce'  => wp_create_nonce( 'wp_rest' ),
				)
			);
		}
	}

	public function add_setting_to_admin_bar( $wp_admin_bar ) {
		$wp_admin_bar->add_node(
			array(
				'id'    => 'qtpo_setting',
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
				'id'     => 'qtpo_clear_all',
				'parent' => 'qtpo_setting',
				'title'  => __( 'Clear All Cache', 'performance-optimisation' ),
				'href'   => '#',
			)
		);

		if ( ! is_admin() ) {
			$current_id = get_the_ID();

			error_log( var_export( $current_id, true ) );
			$wp_admin_bar->add_node(
				array(
					'id'     => 'qtpo_clear_this_page',
					'parent' => 'qtpo_setting',
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
		if ( is_admin() ) {
			return $tag;
		}

		if ( in_array( $handle, array( 'wp-hooks', 'wp-i18n' ), true ) ) {
			return $tag;
		}
		return str_replace( ' src', ' defer="defer" src', $tag );
	}

	public function minify_assets() {
		global $wp_styles, $wp_scripts;

		foreach ( $wp_styles->queue as $handle ) {
			$style = $wp_styles->registered[ $handle ];

			if ( in_array( $handle, $this->minify_exclude, true ) || empty( $style->src ) ) {
				continue;
			}

			$this->minify_css( $style );
		}

		foreach ( $wp_scripts->queue as $handle ) {
			$script = $wp_scripts->registered[ $handle ];

			if ( in_array( $handle, $this->minify_exclude, true ) || empty( $script->src ) ) {
				error_log( 'continue: ' . $handle );
				continue; // Skip excluded handles
			}

			$this->minify_js( $script );
		}
	}

	private function minify_css( $style ) {
		$local_path = Util::get_local_path( $style->src );

		if ( $this->is_css_minified( $local_path ) ) {
			error_log( 'Skipping minification for already minified CSS: ' . $local_path );
			return;
		}

		$css_minifier = new Minify\CSS( $local_path, WP_CONTENT_DIR . '/cache/qtpo/min/css' );
		$cached_file  = $css_minifier->minify();

		if ( $cached_file ) {
			$file_version = fileatime( Util::get_local_path( $cached_file ) );
			wp_deregister_style( $style->handle );
			wp_register_style( $style->handle, $cached_file, $style->deps, $file_version, $style->args );
		}
	}

	private function minify_js( $script ) {
		$local_path = Util::get_local_path( $script->src );

		if ( $this->is_js_minified( $local_path ) ) {
			error_log( 'Skipping minification for already minified JS: ' . $local_path );
			return;
		}

		$js_minifier = new Minify\JS( $local_path, WP_CONTENT_DIR . '/cache/qtpo/min/js' );
		$cached_file = $js_minifier->minify();

		if ( $cached_file ) {
			$file_version = fileatime( Util::get_local_path( $cached_file ) );
			wp_deregister_script( $script->handle );
			wp_register_script( $script->handle, $cached_file, $script->deps, $file_version, $script->args );
		}
	}

	private function is_css_minified( $file_path ) {
		$file_name = basename( $file_path );

		if ( preg_match( '/(\.min\.css|\.bundle\.css|\.bundle\.min\.css)$/i', $file_name ) ) {
			return true;
		}

		$css_content = file_get_contents( $file_path );
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

		$js_content = file_get_contents( $file_path );
		$line       = preg_split( '/\r\n|\r|\n/', $js_content );

		error_log( 'Line : ' . count( $line ) . ' File name: ' . $file_name );

		if ( 10 >= count( $line ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Convert uploaded images to WebP format.
	 *
	 * @param array $metadata The attachment metadata.
	 * @param int $attachment_id The attachment ID.
	 * @return array The modified attachment metadata.
	 */
	public function convert_images_to_webp( $metadata, $attachment_id ) {
		$upload_dir     = wp_upload_dir();
		$webp_converter = new WebP_Converter();

		error_log( print_r( $upload_dir, true ) );
		// Get the full file path of the original image
		$file = get_attached_file( $attachment_id );

		// Convert the original image to WebP
		$webp_file = $webp_converter->get_webp_path( $file );
		$converted = $webp_converter->convert_to_webp( $file, $webp_file );

		if ( $converted ) {
			error_log( 'WebP conversion successful: ' . $webp_file );
		} else {
			error_log( 'WebP conversion failed: ' . $file );
		}

		// Convert additional image sizes to WebP
		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size => $size_data ) {
				$image_path = $upload_dir['path'] . '/' . $size_data['file'];
				$webp_path  = $webp_converter->get_webp_path( $image_path );

				$webp_converter->convert_to_webp( $image_path, $webp_path );
			}
		}

		return $metadata;
	}

	/**
	 * Serve WebP images if available and supported by the browser.
	 *
	 * @param array $image The image source array.
	 * @param int $attachment_id The attachment ID.
	 * @param string|array $size The requested size.
	 * @param bool $icon Whether the image is an icon.
	 * @return array Modified image source with WebP if applicable.
	 */
	public function maybe_serve_webp_image( $image, $attachment_id, $size, $icon ) {
		error_log( '$webp_image_path: ' . $_SERVER['HTTP_ACCEPT'] );
		if ( strpos( $_SERVER['HTTP_ACCEPT'], 'image/webp' ) === false ) {
			return $image;
		}

		// Check if the image is already in WebP format
		$image_extension = pathinfo( $image[0], PATHINFO_EXTENSION );
		if ( 'webp' === strtolower( $image_extension ) ) {
			// If the image is already a WebP, return it as is
			error_log( 'Image is already in WebP format, skipping conversion. ' . $image[0] );
			return $image;
		}

		$webp_converter  = new WebP_Converter();
		$webp_image_path = $webp_converter->get_webp_path( $image[0] );

		error_log( '$webp_image_path: ' . $webp_image_path );
		if ( file_exists( $webp_image_path ) ) {
			// Replace the original image URL with the WebP version
			$image[0] = str_replace( pathinfo( $image[0], PATHINFO_EXTENSION ), 'webp', $image[0] );
		}

		return $image;
	}
}
