<?php
/**
 * Admin Class
 *
 * @package PerformanceOptimisation\Admin
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Admin;

use PerformanceOptimisation\Services\SettingsService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin
 *
 * @package PerformanceOptimisation\Admin
 */
class Admin {

	private SettingsService $settingsService;

	public function __construct( SettingsService $settingsService ) {
		$this->settingsService = $settingsService;
		new Metabox( $settingsService );
	}

	public function setup_hooks(): void {
		add_action( 'admin_menu', array( $this, 'init_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_wizard' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_bar_scripts' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_settings_to_admin_bar' ), 100 );
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

		if ( isset( $_GET['page'] ) && 'performance-optimisation-setup' === $_GET['page'] ) {
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

		wp_localize_script(
			'performance-optimisation-admin-script',
			'wppoAdminData',
			array(
				'apiUrl'   => rest_url( 'wppo/v1' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'settings' => $this->settingsService->get_settings(),
			)
		);
	}

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
				'apiUrl' => rest_url( 'wppo/v1' ),
				'nonce'  => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

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
