<?php
/**
 * System Info Class
 *
 * Collects PHP, database, WordPress, server, and cache environment details
 * for display in the WPPO admin dashboard. All fields are null-safe — missing
 * server variables return null rather than triggering PHP warnings.
 *
 * @package PerformanceOptimise\Inc
 * @since   1.5.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\System_Info' ) ) {

	/**
	 * Class System_Info
	 *
	 * Provides static methods to gather server, PHP, database, WordPress,
	 * and cache environment information.
	 *
	 * @since 1.5.0
	 */
	class System_Info {

		/**
		 * Known cache plugin slugs used to detect active cache plugins.
		 *
		 * @var   string[]
		 * @since 1.5.0
		 */
		private static array $cache_plugin_slugs = array(
			'w3-total-cache',
			'wp-super-cache',
			'wp-rocket',
			'litespeed-cache',
			'redis-cache',
			'wp-fastest-cache',
			'comet-cache',
			'hyper-cache',
			'performance-optimisation',
		);

		/**
		 * Return all system info groups in a single array.
		 *
		 * @since  1.5.0
		 * @return array {
		 *     @type array $php          PHP environment details.
		 *     @type array $database     Database details.
		 *     @type array $wordpress    WordPress installation details.
		 *     @type array $wp_constants Key WordPress constants.
		 *     @type array $server       Server environment details.
		 *     @type array $cache        Cache status details.
		 * }
		 */
		public static function get_all(): array {
			return array(
				'php'          => self::get_php(),
				'database'     => self::get_database(),
				'wordpress'    => self::get_wordpress(),
				'wp_constants' => self::get_wp_constants(),
				'server'       => self::get_server(),
				'cache'        => self::get_cache(),
			);
		}

		/**
		 * Get PHP environment details.
		 *
		 * @since  1.5.0
		 * @return array {
		 *     @type string|null $version             PHP version string.
		 *     @type string|null $sapi                PHP SAPI name.
		 *     @type string|null $memory_limit         memory_limit ini value.
		 *     @type string|null $max_execution_time   max_execution_time ini value.
		 *     @type string|null $upload_max_filesize  upload_max_filesize ini value.
		 *     @type string|null $post_max_size        post_max_size ini value.
		 *     @type string|null $display_errors       display_errors ini value.
		 *     @type int         $extensions_count     Number of loaded PHP extensions.
		 * }
		 */
		public static function get_php(): array {
			return array(
				'version'             => phpversion() ? phpversion() : null,
				'sapi'                => php_sapi_name() ? php_sapi_name() : null,
				'memory_limit'        => ini_get( 'memory_limit' ) ? ini_get( 'memory_limit' ) : null,
				'max_execution_time'  => ini_get( 'max_execution_time' ) ? ini_get( 'max_execution_time' ) : null,
				'upload_max_filesize' => ini_get( 'upload_max_filesize' ) ? ini_get( 'upload_max_filesize' ) : null,
				'post_max_size'       => ini_get( 'post_max_size' ) ? ini_get( 'post_max_size' ) : null,
				'display_errors'      => ini_get( 'display_errors' ) ? ini_get( 'display_errors' ) : null,
				'extensions_count'    => count( get_loaded_extensions() ),
			);
		}

		/**
		 * Get database environment details.
		 *
		 * @since  1.5.0
		 * @global \wpdb $wpdb WordPress database abstraction object.
		 * @return array {
		 *     @type string|null $server_version  MySQL/MariaDB server version.
		 *     @type string|null $extension        PHP database extension class name.
		 *     @type string|null $client_version   Client library version.
		 *     @type string|null $max_connections  max_connections MySQL variable.
		 * }
		 */
		public static function get_database(): array {
			global $wpdb;

			return array(
				'server_version'  => $wpdb->db_version() ? $wpdb->db_version() : null,
				'extension'       => isset( $wpdb->dbh ) ? get_class( $wpdb->dbh ) : null,
				'client_version'  => $wpdb->dbh->client_info ?? null,
				'max_connections' => self::get_mysql_var( 'max_connections' ),
			);
		}

		/**
		 * Get WordPress installation details.
		 *
		 * @since  1.5.0
		 * @return array {
		 *     @type string $version              WordPress version.
		 *     @type string $environment_type     WP_ENVIRONMENT_TYPE constant value.
		 *     @type string $permalink_structure  Current permalink structure.
		 *     @type string $using_https          'Yes' or 'No'.
		 *     @type string $multisite            'Yes' or 'No'.
		 * }
		 */
		public static function get_wordpress(): array {
			return array(
				'version'             => get_bloginfo( 'version' ),
				'environment_type'    => defined( 'WP_ENVIRONMENT_TYPE' ) ? WP_ENVIRONMENT_TYPE : 'production',
				'permalink_structure' => get_option( 'permalink_structure' ) ? get_option( 'permalink_structure' ) : __( 'Default', 'performance-optimisation' ),
				'using_https'         => is_ssl() ? __( 'Yes', 'performance-optimisation' ) : __( 'No', 'performance-optimisation' ),
				'multisite'           => is_multisite() ? __( 'Yes', 'performance-optimisation' ) : __( 'No', 'performance-optimisation' ),
			);
		}

		/**
		 * Get key WordPress constants.
		 *
		 * @since  1.5.0
		 * @return array {
		 *     @type string $WP_DEBUG        'true', 'false', or 'undefined'.
		 *     @type string $WP_CACHE        'true', 'false', or 'undefined'.
		 *     @type string $WP_MEMORY_LIMIT Configured memory limit or 'undefined'.
		 *     @type string $WP_DEBUG_LOG    'true', 'false', or 'undefined'.
		 *     @type string $SCRIPT_DEBUG    'true', 'false', or 'undefined'.
		 * }
		 */
		public static function get_wp_constants(): array {
			return array(
				'WP_DEBUG'        => self::format_constant( 'WP_DEBUG' ),
				'WP_CACHE'        => self::format_constant( 'WP_CACHE' ),
				'WP_MEMORY_LIMIT' => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : 'undefined',
				'WP_DEBUG_LOG'    => self::format_constant( 'WP_DEBUG_LOG' ),
				'SCRIPT_DEBUG'    => self::format_constant( 'SCRIPT_DEBUG' ),
			);
		}

		/**
		 * Get server environment details.
		 *
		 * All values are null-safe — missing $_SERVER keys return null.
		 *
		 * @since  1.5.0
		 * @return array {
		 *     @type string|null $server_software  Server software string.
		 *     @type string      $os               OS name and kernel version.
		 *     @type string      $architecture     CPU architecture.
		 * }
		 */
		public static function get_server(): array {
			return array(
				'server_software' => isset( $_SERVER['SERVER_SOFTWARE'] )
					? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) )
					: null,
				'os'              => PHP_OS . ' ' . php_uname( 'r' ),
				'architecture'    => php_uname( 'm' ),
			);
		}

		/**
		 * Get cache environment details.
		 *
		 * @since  1.5.0
		 * @return array {
		 *     @type string          $object_cache_status  'Enabled' or 'Disabled'.
		 *     @type string          $active_cache_plugin  Slug of active cache plugin or 'None'.
		 *     @type string          $peak_memory_usage    Human-readable peak memory usage.
		 *     @type string          $current_memory_usage Human-readable current memory usage.
		 *     @type string[]|null   $woocommerce_presets  WooCommerce high-value URL presets, or null.
		 * }
		 */
		public static function get_cache(): array {
			return array(
				'object_cache_status'  => wp_using_ext_object_cache()
					? esc_html__( 'Enabled', 'performance-optimisation' )
					: esc_html__( 'Disabled', 'performance-optimisation' ),
				'active_cache_plugin'  => self::get_active_cache_plugin(),
				'peak_memory_usage'    => size_format( memory_get_peak_usage( true ) ),
				'current_memory_usage' => size_format( memory_get_usage() ),
				'woocommerce_presets'  => self::get_woocommerce_presets(),
			);
		}

		/**
		 * Get WooCommerce high-value URL presets.
		 *
		 * Returns checkout and cart URLs when WooCommerce is active.
		 *
		 * @since  1.5.0
		 * @return string[]|null Array of preset URLs, or null if WooCommerce is not active.
		 */
		public static function get_woocommerce_presets(): ?array {
			if ( ! function_exists( 'wc_get_checkout_url' ) ) {
				return null;
			}

			$presets = array();

			$checkout_url = wc_get_checkout_url();
			if ( $checkout_url ) {
				$presets[] = esc_url_raw( $checkout_url );
			}

			$cart_url = wc_get_cart_url();
			if ( $cart_url ) {
				$presets[] = esc_url_raw( $cart_url );
			}

			return ! empty( $presets ) ? $presets : null;
		}

		/**
		 * Get the slug of the first detected active cache plugin.
		 *
		 * @since  1.5.0
		 * @return string Plugin slug or 'None' if no cache plugin is active.
		 */
		private static function get_active_cache_plugin(): string {
			$active_plugins = (array) get_option( 'active_plugins', array() );

			foreach ( $active_plugins as $plugin_path ) {
				$slug = dirname( $plugin_path );
				// Single-file plugins have dirname of '.'.
				if ( '.' === $slug ) {
					$slug = str_replace( '.php', '', basename( $plugin_path ) );
				}
				if ( in_array( $slug, self::$cache_plugin_slugs, true ) ) {
					return $slug;
				}
			}

			return esc_html__( 'None', 'performance-optimisation' );
		}

		/**
		 * Retrieve a MySQL/MariaDB server variable.
		 *
		 * @since  1.5.0
		 * @param  string $variable The MySQL variable name to retrieve.
		 * @return string|null The variable value, or null if not found.
		 */
		private static function get_mysql_var( string $variable ): ?string {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->get_var(
				$wpdb->prepare( 'SHOW VARIABLES LIKE %s', $variable )
			);

			return $result ? $result : null;
		}

		/**
		 * Format a boolean WordPress constant as a readable string.
		 *
		 * @since  1.5.0
		 * @param  string $constant The constant name to check.
		 * @return string 'true', 'false', or 'undefined'.
		 */
		private static function format_constant( string $constant ): string {
			if ( ! defined( $constant ) ) {
				return 'undefined';
			}
			return constant( $constant ) ? 'true' : 'false';
		}
	}
}
