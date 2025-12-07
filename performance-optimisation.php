<?php
/**
 * Performance Optimisation
 *
 * Comprehensive WordPress performance optimization plugin with caching,
 * minification, image optimization, and advanced analytics.
 *
 * @package PerformanceOptimisation
 * @since   2.0.0
 * @author  Nilesh Kanzariya
 * @license GPL-2.0-or-later
 * @link    https://wordpress.org/plugins/performance-optimisation/
 *
 * Plugin Name:       Performance Optimisation
 * Plugin URI:        https://wordpress.org/plugins/performance-optimisation/
 * Description:       Comprehensive WordPress performance optimization with caching, minification, image optimization, lazy loading, and advanced analytics dashboard. Includes setup wizard and automated recommendations.
 * Version:           2.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Tested up to:      6.4
 * Author:            Nilesh Kanzariya
 * Author URI:        https://profiles.wordpress.org/nileshkanzariya/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       performance-optimisation
 * Domain Path:       /languages
 * Network:           false
 * Update URI:        https://wordpress.org/plugins/performance-optimisation/
 *
 * Performance Optimisation is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 *
 * Performance Optimisation is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Performance Optimisation. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check PHP version compatibility.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo '<strong>Performance Optimisation:</strong> This plugin requires PHP 7.4 or higher. ';
			echo 'Current version: ' . esc_html( PHP_VERSION );
			echo '</p></div>';
		}
	);
	return;
}

// Check WordPress version compatibility.
global $wp_version;
if ( version_compare( $wp_version, '6.2', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo '<strong>Performance Optimisation:</strong> This plugin requires WordPress 6.2 or higher. ';
			echo 'Current version: ' . esc_html( $wp_version );
			echo '</p></div>';
		}
	);
	return;
}

// Define plugin constants.
define( 'WPPO_PLUGIN_FILE', __FILE__ );
define( 'WPPO_PLUGIN_PATH', plugin_dir_path( WPPO_PLUGIN_FILE ) );
define( 'WPPO_PLUGIN_URL', plugin_dir_url( WPPO_PLUGIN_FILE ) );

// Optimized version detection.
if ( ! defined( 'WPPO_VERSION' ) ) {
	$version = '2.0.0'; // Fallback version.

	if ( function_exists( 'get_file_data' ) ) {
		$headers = get_file_data( WPPO_PLUGIN_FILE, array( 'Version' => 'Version' ) );
		$version = $headers['Version'] ? $headers['Version'] : $version;
	} elseif ( function_exists( 'get_plugin_data' ) ) {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin_data = get_plugin_data( WPPO_PLUGIN_FILE, false, false );
		$version     = $plugin_data['Version'] ? $plugin_data['Version'] : $version;
	}

	define( 'WPPO_VERSION', $version );
}

// Load Composer autoloader with validation.
$autoloader = WPPO_PLUGIN_PATH . 'vendor/autoload.php';
if ( file_exists( $autoloader ) ) {
	require_once $autoloader;
} else {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Performance Optimisation: Composer dependencies not found. Some features may not work properly.', 'performance-optimisation' );
			echo '</p></div>';
		}
	);
}

// Legacy class aliases removed - using modern architecture only.

use PerformanceOptimisation\Core\Bootstrap\Plugin;

// Prevent function redefinition.
if ( ! function_exists( 'wppo_check_conflicts' ) ) {

	/**
	 * Check for conflicting plugins.
	 */
	function wppo_check_conflicts(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			return true;
		}

		$conflicting_plugins = array(
			'wp-rocket/wp-rocket.php'             => 'WP Rocket',
			'w3-total-cache/w3-total-cache.php'   => 'W3 Total Cache',
			'wp-super-cache/wp-cache.php'         => 'WP Super Cache',
			'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
		);

		foreach ( $conflicting_plugins as $plugin => $name ) {
			if ( is_plugin_active( $plugin ) ) {
				add_action(
					'admin_notices',
					function () use ( $name ) {
						printf(
							'<div class="notice notice-warning"><p>%s</p></div>',
							sprintf(
								/* translators: %s: Plugin name */
								esc_html__(
									'Performance Optimisation detected %s is active. This may cause conflicts. Consider deactivating one of the plugins.',
									'performance-optimisation'
								),
								esc_html( $name )
							)
						);
					}
				);
				return false;
			}
		}
		return true;
	}

} // End function_exists check

if ( ! function_exists( 'wppo_initialize_plugin' ) ) {
	/**
	 * Initialize the plugin.
	 * This function is hooked to 'plugins_loaded' to ensure all WordPress core functions are available.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	function wppo_initialize_plugin(): void {
		// Check for plugin conflicts first.
		wppo_check_conflicts();

		try {
			$plugin = Plugin::getInstance( WPPO_PLUGIN_FILE, WPPO_VERSION );
			$plugin->initialize();
		} catch ( Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// Log error for debugging purposes.
				\PerformanceOptimisation\Utils\LoggingUtil::error( 'Performance Optimisation initialization error: ' . $e->getMessage() );
			}

			// Show admin notice for initialization failure.
			add_action(
				'admin_notices',
				function () use ( $e ) {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						sprintf(
						/* translators: %s: Error message */
							esc_html__( 'Performance Optimisation plugin failed to initialize: %s', 'performance-optimisation' ),
							esc_html( $e->getMessage() )
						)
					);
				}
			);
		}
	}
} // End wppo_initialize_plugin function_exists check

add_action( 'plugins_loaded', 'wppo_initialize_plugin' );

if ( ! function_exists( 'wppo_activate_plugin' ) ) {
	/**
	 * Activation hook callback function.
	 * Handles tasks to be performed when the plugin is activated.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	function wppo_activate_plugin(): void {
		$bootstrap_file = WPPO_PLUGIN_PATH . 'includes/Core/Bootstrap/Plugin.php';

		if ( ! file_exists( $bootstrap_file ) ) {
			wp_die(
				esc_html__( 'Plugin activation failed: Bootstrap file not found.', 'performance-optimisation' ),
				esc_html__( 'Plugin Activation Error', 'performance-optimisation' )
			);
		}

		try {
			require_once $bootstrap_file;
			$plugin = Plugin::getInstance( WPPO_PLUGIN_FILE, WPPO_VERSION );
			$plugin->activate();
		} catch ( Exception $e ) {
			wp_die(
				sprintf(
					/* translators: %s: Error message */
					esc_html__( 'Plugin activation failed: %s', 'performance-optimisation' ),
					esc_html( $e->getMessage() )
				),
				esc_html__( 'Plugin Activation Error', 'performance-optimisation' )
			);
		}
	}
} // End wppo_activate_plugin function_exists check

register_activation_hook( WPPO_PLUGIN_FILE, 'wppo_activate_plugin' );

if ( ! function_exists( 'wppo_deactivate_plugin' ) ) {
	/**
	 * Deactivation hook callback function.
	 * Handles tasks to be performed when the plugin is deactivated.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	function wppo_deactivate_plugin(): void {
		$bootstrap_file = WPPO_PLUGIN_PATH . 'includes/Core/Bootstrap/Plugin.php';

		if ( ! file_exists( $bootstrap_file ) ) {
			\PerformanceOptimisation\Utils\LoggingUtil::error( 'Performance Optimisation: Bootstrap file not found during deactivation' );
			return;
		}

		try {
			require_once $bootstrap_file;
			$plugin = Plugin::getInstance( WPPO_PLUGIN_FILE, WPPO_VERSION );
			$plugin->deactivate();
		} catch ( Exception $e ) {
			\PerformanceOptimisation\Utils\LoggingUtil::error( 'Performance Optimisation deactivation error: ' . $e->getMessage() );
		}
	}
} // End wppo_deactivate_plugin function_exists check

register_deactivation_hook( WPPO_PLUGIN_FILE, 'wppo_deactivate_plugin' );

if ( ! function_exists( 'wppo_add_settings_link' ) ) {
	/**
	 * Add a settings link to the plugin entry in the plugins admin page.
	 *
	 * @since 1.0.0
	 * @param array<string,string> $links Existing plugin action links.
	 * @return array<string,string> Modified plugin action links.
	 */
	function wppo_add_settings_link( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=performance-optimisation' ) ),
			esc_html__( 'Settings', 'performance-optimisation' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}
} // End wppo_add_settings_link function_exists check

add_filter( 'plugin_action_links_' . plugin_basename( WPPO_PLUGIN_FILE ), 'wppo_add_settings_link' );


// Temporary test page for drop-in management
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	add_action(
		'admin_menu',
		function () {
			add_submenu_page(
				'', // Empty string instead of null for hidden menu
				'Test Drop-in',
				'Test Drop-in',
				'manage_options',
				'wppo-test-dropin',
				function () {
					if ( ! current_user_can( 'manage_options' ) ) {
						wp_die( 'Unauthorized' );
					}

					$dropin_path   = WP_CONTENT_DIR . '/advanced-cache.php';
					$dropin_exists = file_exists( $dropin_path );

					// Handle actions
					if ( isset( $_GET['action'] ) && check_admin_referer( 'wppo-test-dropin' ) ) {
						try {
							$container = \PerformanceOptimisation\Core\ServiceContainer::getInstance();
							$service   = $container->get( 'PageCacheService' );

							switch ( $_GET['action'] ) {
								case 'create':
									$result = $service->create_advanced_cache_dropin();
									echo '<div class="notice notice-' . ( $result ? 'success' : 'error' ) . '"><p>' . ( $result ? 'Drop-in created!' : 'Failed to create drop-in.' ) . '</p></div>';
									break;
								case 'remove':
									$result = $service->remove_advanced_cache_dropin();
									echo '<div class="notice notice-' . ( $result ? 'success' : 'error' ) . '"><p>' . ( $result ? 'Drop-in removed!' : 'Failed to remove drop-in.' ) . '</p></div>';
									break;
								case 'enable':
									$result = $service->enable_cache();
									echo '<div class="notice notice-' . ( $result ? 'success' : 'error' ) . '"><p>' . ( $result ? 'Cache enabled!' : 'Failed to enable cache.' ) . '</p></div>';
									break;
								case 'disable':
									$result = $service->disable_cache();
									echo '<div class="notice notice-' . ( $result ? 'success' : 'error' ) . '"><p>' . ( $result ? 'Cache disabled!' : 'Failed to disable cache.' ) . '</p></div>';
									break;
							}
							$dropin_exists = file_exists( $dropin_path );
						} catch ( Exception $e ) {
							echo '<div class="notice notice-error"><p>Error: ' . esc_html( $e->getMessage() ) . '</p></div>';
						}
					}

					$nonce = wp_create_nonce( 'wppo-test-dropin' );
					?>
				<div class="wrap">
					<h1>Advanced Cache Drop-in Test</h1>
					<h2>Status</h2>
						<p>Drop-in exists: <strong><?php echo $dropin_exists ? 'YES' : 'NO'; ?></strong></p>
						<?php if ( $dropin_exists ) : ?>
							<p>Size: <?php echo size_format( filesize( $dropin_path ) ); ?></p>
							<p>Modified: <?php echo date( 'Y-m-d H:i:s', filemtime( $dropin_path ) ); ?></p>
						<?php endif; ?>
						<p>WP_CACHE: <strong><?php echo defined( 'WP_CACHE' ) && WP_CACHE ? 'ENABLED' : 'DISABLED'; ?></strong></p>
					
					<h2>Actions</h2>
					<p>
							<a href="?page=wppo-test-dropin&action=create&_wpnonce=<?php echo $nonce; ?>" class="button">Create Drop-in</a>
							<a href="?page=wppo-test-dropin&action=remove&_wpnonce=<?php echo $nonce; ?>" class="button">Remove Drop-in</a>
							<a href="?page=wppo-test-dropin&action=enable&_wpnonce=<?php echo $nonce; ?>" class="button button-primary">Enable Cache</a>
							<a href="?page=wppo-test-dropin&action=disable&_wpnonce=<?php echo $nonce; ?>" class="button">Disable Cache</a>
					</p>
					
						<?php if ( $dropin_exists ) : ?>
						<h2>Content Preview</h2>
							<textarea readonly style="width:100%;height:400px;font-family:monospace;font-size:12px;"><?php echo esc_textarea( file_get_contents( $dropin_path ) ); ?></textarea>
						<?php endif; ?>
				</div>
					<?php
				}
			);
		}
	);
}
