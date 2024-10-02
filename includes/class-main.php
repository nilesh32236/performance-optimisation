<?php
namespace PerformanceOptimise\Inc;

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

		$cache = new Cache();
		add_action( 'template_redirect', array( $cache, 'generate_dynamic_static_html' ) );
		add_action( 'save_post', array( $cache, 'invalidate_dynamic_static_html' ) );

		$rest = new Rest();
		add_action( 'rest_api_init', array( $rest, 'register_routes' ) );

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
}
