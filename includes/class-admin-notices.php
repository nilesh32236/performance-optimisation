<?php
/**
 * Admin notices: activation issues, cache conflicts, onboarding.
 *
 * @package PerformanceOptimise\Inc
 * @since   1.2.1
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Admin_Notices' ) ) {

	/**
	 * Admin notices handler.
	 */
	class Admin_Notices {

		/**
		 * Other full-page caching plugins that may conflict with WPPO drop-ins.
		 *
		 * @var array<string, string> Plugin file => human label.
		 */
		private const COMPETING_CACHE_PLUGINS = array(
			'wp-super-cache/wp-cache.php'            => 'WP Super Cache',
			'w3-total-cache/w3-total-cache.php'      => 'W3 Total Cache',
			'wp-fastest-cache/wpFastestCache.php'    => 'WP Fastest Cache',
			'litespeed-cache/litespeed-cache.php'    => 'LiteSpeed Cache',
			'cache-enabler/cache-enabler.php'        => 'Cache Enabler',
			'sg-cachepress/sg-cachepress.php'        => 'SG Optimizer',
			'wp-rocket/wp-rocket.php'                => 'WP Rocket',
			'comet-cache/comet-cache.php'            => 'Comet Cache',
			'swift-performance-lite/performance.php' => 'Swift Performance Lite',
			'swift-performance/performance.php'      => 'Swift Performance',
		);

		/**
		 * Register hooks.
		 */
		public function __construct() {
			add_action( 'admin_notices', array( $this, 'render_notices' ) );
			add_action( 'admin_init', array( $this, 'handle_dismiss' ) );
		}

		/**
		 * Dismiss notices via query arg + nonce.
		 *
		 * @return void
		 */
		public function handle_dismiss(): void {
			if ( ! isset( $_GET['wppo_dismiss'], $_GET['_wpnonce'] ) ) {
				return;
			}

			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wppo_dismiss_notice' ) ) {
				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$key = sanitize_key( wp_unslash( $_GET['wppo_dismiss'] ) );

			if ( 'welcome' === $key ) {
				delete_transient( 'wppo_show_welcome_notice' );
			}

			if ( 'activation' === $key ) {
				delete_transient( 'wppo_activation_notices' );
			}

			wp_safe_redirect( remove_query_arg( array( 'wppo_dismiss', '_wpnonce' ) ) );
			exit;
		}

		/**
		 * Output notices.
		 *
		 * @return void
		 */
		public function render_notices(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$this->maybe_welcome_notice();
			$this->maybe_activation_notices();
			$this->maybe_competing_plugins_notice();
		}

		/**
		 * Welcome / onboarding after activation.
		 *
		 * @return void
		 */
		private function maybe_welcome_notice(): void {
			if ( ! get_transient( 'wppo_show_welcome_notice' ) ) {
				return;
			}

			$dismiss = wp_nonce_url(
				add_query_arg( 'wppo_dismiss', 'welcome' ),
				'wppo_dismiss_notice',
				'_wpnonce'
			);

			echo '<div class="notice notice-success"><p>';
			echo esc_html__( 'Performance Optimisation is active. Advanced options like Defer/Delay JavaScript are off by default — enable them only after testing. Open the settings page to review safe defaults.', 'performance-optimisation' );
			echo ' <a href="' . esc_url( admin_url( 'admin.php?page=performance-optimisation' ) ) . '">' . esc_html__( 'Open settings', 'performance-optimisation' ) . '</a>';
			echo ' &mdash; <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'performance-optimisation' ) . '</a>';
			echo '</p></div>';
		}

		/**
		 * One-time notices from activation (wp-config, foreign drop-in).
		 *
		 * @return void
		 */
		private function maybe_activation_notices(): void {
			$notices = get_transient( 'wppo_activation_notices' );

			if ( ! is_array( $notices ) || empty( $notices ) ) {
				return;
			}

			$messages = array();

			foreach ( $notices as $key ) {
				switch ( $key ) {
					case 'foreign_dropin':
						$messages[] = __( 'Another plugin or your host already manages <code>wp-content/advanced-cache.php</code>. Performance Optimisation did not replace that file. Page-cache drop-ins from two sources can conflict — use only one full-page cache solution.', 'performance-optimisation' );
						break;
					case 'wp_cache_disabled':
						$messages[] = __( '<code>WP_CACHE</code> is set to <code>false</code> in your configuration. The plugin did not change it. Set <code>WP_CACHE</code> to <code>true</code> in wp-config.php if you want WordPress to load advanced-cache drop-ins.', 'performance-optimisation' );
						break;
					case 'wp_config_fs':
					case 'wp_config_writable':
					case 'wp_config_read':
						$messages[] = __( 'Could not update wp-config.php (filesystem access or permissions). If you need <code>WP_CACHE</code>, add it manually or fix file permissions.', 'performance-optimisation' );
						break;
					case 'wp_config_write_failed':
						$messages[] = __( 'Failed to write wp-config.php. Please check file permissions.', 'performance-optimisation' );
						break;
					default:
						break;
				}
			}

			if ( empty( $messages ) ) {
				return;
			}

			$dismiss = wp_nonce_url(
				add_query_arg( 'wppo_dismiss', 'activation' ),
				'wppo_dismiss_notice',
				'_wpnonce'
			);

			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Performance Optimisation', 'performance-optimisation' ) . '</strong></p><ul style="list-style:disc;padding-left:1.25em;">';
			foreach ( array_unique( $messages ) as $html ) {
				echo '<li>' . wp_kses_post( $html ) . '</li>';
			}
			echo '</ul><p><a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss this notice', 'performance-optimisation' ) . '</a></p></div>';
		}

		/**
		 * Warn when another page-cache plugin is active alongside this one.
		 *
		 * @return void
		 */
		private function maybe_competing_plugins_notice(): void {
			if ( ! Advanced_Cache_Handler::is_our_dropin() ) {
				return;
			}

			$active = self::get_active_plugin_files();
			$found  = array();

			foreach ( self::COMPETING_CACHE_PLUGINS as $file => $label ) {
				if ( in_array( $file, $active, true ) ) {
					$found[ $file ] = $label;
				}
			}

			if ( empty( $found ) ) {
				return;
			}

			$names = implode( ', ', array_map( 'esc_html', $found ) );

			echo '<div class="notice notice-info is-dismissible"><p>';
			echo esc_html__( 'You have another page caching plugin active:', 'performance-optimisation' ) . ' ' . esc_html( $names ) . '. ';
			echo esc_html__( 'Running multiple full-page cache solutions can cause conflicts. Consider using only one.', 'performance-optimisation' );
			echo '</p></div>';
		}

		/**
		 * Active plugin paths for the current site.
		 *
		 * @return string[]
		 */
		private static function get_active_plugin_files(): array {
			$plugins = (array) get_option( 'active_plugins', array() );

			if ( is_multisite() ) {
				$network = (array) get_site_option( 'active_sitewide_plugins', array() );
				$plugins = array_merge( $plugins, array_keys( $network ) );
			}

			return array_values( array_unique( $plugins ) );
		}
	}
}
