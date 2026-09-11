<?php
/**
 * Plugin Name:       Performance Optimisation
 * Description:       Speed up WordPress with page caching, JS/CSS minify, lazy load, WebP/AVIF images, Redis object cache, and database cleanup. Simple and powerful.
 * Requires at least: 6.2
 * Requires PHP:      8.2
 * Tested up to:      7.1
 * Version:           2.0.0
 * Author:            Nilesh Kanzariya
 * Author URI:        https://github.com/nilesh32236
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       performance-optimisation
 * Domain Path:       /languages
 *
 * @package PerformanceOptimise
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PerformanceOptimise\Inc\Activate;
use PerformanceOptimise\Inc\Deactivate;
use PerformanceOptimise\Inc\Main;

// Define plugin constants.
if ( ! defined( 'WPPO_PLUGIN_PATH' ) ) {
	define( 'WPPO_PLUGIN_PATH', wp_normalize_path( plugin_dir_path( __FILE__ ) ) );
}

if ( ! defined( 'WPPO_PLUGIN_URL' ) ) {
	define( 'WPPO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'WPPO_VERSION' ) ) {
	define( 'WPPO_VERSION', '2.0.0' );
}

// Minimum supported runtimes (mirrors the plugin header above).
if ( ! defined( 'WPPO_REQUIRES_PHP' ) ) {
	define( 'WPPO_REQUIRES_PHP', '8.2' );
}

if ( ! defined( 'WPPO_REQUIRES_WP' ) ) {
	define( 'WPPO_REQUIRES_WP', '6.2' );
}

if ( ! function_exists( 'wppo_get_wp_version' ) ) {
	/**
	 * Read the current WordPress version without fataling.
	 *
	 * Prefers the already-loaded `$wp_version` global and falls back to
	 * `get_bloginfo( 'version' )`. Returns an empty string when the version
	 * cannot be determined so callers can fail open.
	 *
	 * @since 2.0.0
	 * @return string WordPress version string, or empty string when unknown.
	 */
	function wppo_get_wp_version(): string {
		try {
			if ( isset( $GLOBALS['wp_version'] ) && is_string( $GLOBALS['wp_version'] ) && '' !== trim( (string) $GLOBALS['wp_version'] ) ) {
				return trim( (string) $GLOBALS['wp_version'] );
			}

			if ( function_exists( 'get_bloginfo' ) ) {
				try {
					$version = get_bloginfo( 'version' );
				} catch ( \Throwable $e ) {
					unset( $e );
					return '';
				}

				if ( is_string( $version ) && '' !== trim( $version ) ) {
					return trim( $version );
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		return '';
	}
}

if ( ! function_exists( 'wppo_requirements_met' ) ) {
	/**
	 * Check whether the PHP and WordPress floors are met.
	 *
	 * The optional parameters exist solely so tests can exercise both sides
	 * of each floor without redefining the `PHP_VERSION` constant. An empty
	 * or unknown WordPress version fails open (treated as met) so a broken
	 * version API cannot itself take the site down; an unknown PHP version
	 * fails closed. Any unexpected error fails closed to "do not boot".
	 *
	 * @since 2.0.0
	 * @param string|null $php_version Optional PHP version override (defaults to the runtime version).
	 * @param string|null $wp_version  Optional WordPress version override (defaults to the detected version).
	 * @return bool True when the plugin may boot.
	 */
	function wppo_requirements_met( ?string $php_version = null, ?string $wp_version = null ): bool {
		try {
			$required_php = defined( 'WPPO_REQUIRES_PHP' ) ? (string) WPPO_REQUIRES_PHP : '8.2';
			$required_wp  = defined( 'WPPO_REQUIRES_WP' ) ? (string) WPPO_REQUIRES_WP : '6.2';

			if ( null === $php_version ) {
				$php_version = defined( 'PHP_VERSION' ) ? (string) PHP_VERSION : '';
				if ( '' === $php_version && function_exists( 'phpversion' ) ) {
					$php_version = (string) phpversion();
				}
			}

			if ( '' === trim( $php_version ) || version_compare( $php_version, $required_php, '<' ) ) {
				return false;
			}

			if ( null === $wp_version ) {
				$wp_version = wppo_get_wp_version();
			}

			$wp_version = trim( (string) $wp_version );
			if ( '' === $wp_version ) {
				return true;
			}

			return version_compare( $wp_version, $required_wp, '>=' );
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}
	}
}

if ( ! function_exists( 'wppo_render_requirements_notice' ) ) {
	/**
	 * Render the runtime-floor admin notice naming required vs detected versions.
	 *
	 * Capability-gated to administrators and fully guarded so the notice
	 * itself can never fatal, even when WordPress APIs are unavailable.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	function wppo_render_requirements_notice(): void {
		try {
			if ( function_exists( 'current_user_can' ) ) {
				try {
					if ( ! current_user_can( 'manage_options' ) ) {
						return;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					return;
				}
			} else {
				return;
			}

			$required_php = defined( 'WPPO_REQUIRES_PHP' ) ? (string) WPPO_REQUIRES_PHP : '8.2';
			$required_wp  = defined( 'WPPO_REQUIRES_WP' ) ? (string) WPPO_REQUIRES_WP : '6.2';
			$detected_php = defined( 'PHP_VERSION' ) ? (string) PHP_VERSION : 'unknown';
			$detected_wp  = wppo_get_wp_version();
			if ( '' === $detected_wp ) {
				$detected_wp = 'unknown';
			}

			echo '<div class="notice notice-error" role="alert" aria-live="assertive"><p><strong>';
			echo esc_html__( 'Performance Optimisation', 'performance-optimisation' );
			echo '</strong> &mdash; ';
			printf(
				/* translators: 1: required PHP version, 2: detected PHP version, 3: required WordPress version, 4: detected WordPress version. */
				esc_html__( 'requires PHP %1$s+ (detected %2$s) and WordPress %3$s+ (detected %4$s). The plugin is paused; update to re-enable optimisations.', 'performance-optimisation' ),
				esc_html( $required_php ),
				esc_html( $detected_php ),
				esc_html( $required_wp ),
				esc_html( $detected_wp )
			);
			echo '</p></div>';
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}
}

if ( ! function_exists( 'wppo_version_guard' ) ) {
	/**
	 * Enforce the PHP/WP floors: refuse boot below them with an admin notice.
	 *
	 * Never force-deactivates and never writes options or transients, so the
	 * frontend stays up (fail-open to unoptimised) and multisite notices stay
	 * per-site. The optional parameters are test-only overrides passed
	 * through to `wppo_requirements_met()`.
	 *
	 * @since 2.0.0
	 * @param string|null $php_version Optional PHP version override (defaults to the runtime version).
	 * @param string|null $wp_version  Optional WordPress version override (defaults to the detected version).
	 * @return bool True when the plugin may boot, false when paused.
	 */
	function wppo_version_guard( ?string $php_version = null, ?string $wp_version = null ): bool {
		try {
			if ( wppo_requirements_met( $php_version, $wp_version ) ) {
				return true;
			}

			if ( function_exists( 'add_action' ) ) {
				try {
					add_action( 'admin_notices', 'wppo_render_requirements_notice' );
					add_action( 'network_admin_notices', 'wppo_render_requirements_notice' );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			return false;
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}
	}
}

// Unit tests load this file for the guard helpers above without booting Main:
// tests define WPPO_UNIT_TESTS before requiring the plugin entry point.
if ( defined( 'WPPO_UNIT_TESTS' ) && WPPO_UNIT_TESTS ) {
	return;
}

// Load Composer autoloader.
require_once WPPO_PLUGIN_PATH . 'vendor/autoload.php';

// Boot only on supported runtimes; below the floors the guard registers an
// admin notice and the site keeps running unoptimised (fail-open frontend).
if ( wppo_version_guard() ) {
	new Main();
}

if ( ! function_exists( 'wppo_activate' ) ) {
	/**
	 * Activation hook callback function.
	 *
	 * @since 1.0.0
	 * Includes the activation class and runs the activation process.
	 */
	function wppo_activate(): void {
		Activate::init();
	}
}
register_activation_hook( __FILE__, 'wppo_activate' );

if ( ! function_exists( 'wppo_deactivate' ) ) {
	/**
	 * Deactivation hook callback function.
	 *
	 * @since 1.0.0
	 * Includes the deactivation class and runs the deactivation process.
	 */
	function wppo_deactivate(): void {
		Deactivate::init();
	}
}
register_deactivation_hook( __FILE__, 'wppo_deactivate' );
