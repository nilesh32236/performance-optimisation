<?php
/**
 * System Info Class
 *
 * Provides server, PHP, WordPress, and database information.
 *
 * @package PerformanceOptimisation\Monitor
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Monitor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SystemInfo
 */
class SystemInfo {

	/**
	 * Get all system information.
	 *
	 * @return array Complete system info.
	 */
	public function get_all(): array {
		return array(
			'php'       => $this->get_php_info(),
			'wordpress' => $this->get_wordpress_info(),
			'database'  => $this->get_database_info(),
			'server'    => $this->get_server_info(),
			'cache'     => $this->get_cache_info(),
			'plugins'   => $this->get_plugin_info(),
		);
	}

	/**
	 * Get PHP information.
	 *
	 * @return array PHP details.
	 */
	public function get_php_info(): array {
		return array(
			'version'            => PHP_VERSION,
			'memory_limit'       => ini_get( 'memory_limit' ),
			'max_execution_time' => ini_get( 'max_execution_time' ),
			'upload_max_size'    => ini_get( 'upload_max_filesize' ),
			'post_max_size'      => ini_get( 'post_max_size' ),
			'max_input_vars'     => ini_get( 'max_input_vars' ),
			'extensions'         => implode(
				', ',
				array_keys(
					array_filter(
						array(
							'gd'       => extension_loaded( 'gd' ),
							'imagick'  => extension_loaded( 'imagick' ),
							'curl'     => extension_loaded( 'curl' ),
							'zip'      => extension_loaded( 'zip' ),
							'mbstring' => extension_loaded( 'mbstring' ),
							'xml'      => extension_loaded( 'xml' ),
							'opcache'  => extension_loaded( 'Zend OPcache' ),
						)
					)
				)
			),
			'opcache_enabled'    => ( function_exists( 'opcache_get_status' ) && opcache_get_status() !== false ) ? 'Yes' : 'No',
		);
	}

	/**
	 * Get WordPress information.
	 *
	 * @return array WordPress details.
	 */
	public function get_wordpress_info(): array {
		global $wp_version;

		return array(
			'version'          => $wp_version,
			'site_url'         => get_site_url(),
			'home_url'         => get_home_url(),
			'is_multisite'     => is_multisite() ? 'Yes' : 'No',
			'debug_mode'       => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'Yes' : 'No',
			'debug_log'        => ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ? 'Yes' : 'No',
			'cron_enabled'     => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? 'Yes' : 'No',
			'memory_limit'     => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : 'Not set',
			'max_memory_limit' => defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : 'Not set',
			'theme'            => $this->get_theme_info(), // Still returns array, need to fix
			'language'         => get_locale(),
			'timezone'         => wp_timezone_string(),
		);
	}

	/**
	 * Get theme information.
	 *
	 * @return array Theme details.
	 */
	private function get_theme_info(): string {
		$theme = wp_get_theme();
		return sprintf( '%s (v%s)', $theme->get( 'Name' ), $theme->get( 'Version' ) );
	}

	/**
	 * Get database information.
	 *
	 * @return array Database details.
	 */
	public function get_database_info(): array {
		global $wpdb;

		return array(
			'server'        => $wpdb->db_server_info(),
			'database_name' => $wpdb->dbname,
			'table_prefix'  => $wpdb->prefix,
			'charset'       => $wpdb->charset,
			'collate'       => $wpdb->collate,
			'table_count'   => $this->get_table_count(),
			'database_size' => $this->get_database_size(),
		);
	}

	/**
	 * Get table count.
	 *
	 * @return int Number of tables.
	 */
	private function get_table_count(): int {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$tables = $wpdb->get_results( 'SHOW TABLES', ARRAY_N );
		return count( $tables );
	}

	/**
	 * Get database size.
	 *
	 * @return string Formatted database size.
	 */
	private function get_database_size(): string {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT SUM(data_length + index_length) AS size FROM information_schema.tables WHERE table_schema = %s',
				$wpdb->dbname
			)
		);

		if ( $result && $result->size ) {
			return size_format( $result->size );
		}

		return 'Unknown';
	}

	/**
	 * Get server information.
	 *
	 * @return array Server details.
	 */
	public function get_server_info(): array {
		return array(
			'software'     => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'Unknown',
			'php_sapi'     => php_sapi_name(),
			'os'           => PHP_OS,
			'architecture' => PHP_INT_SIZE * 8 . '-bit',
			'https'        => is_ssl(),
			'server_ip'    => isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : 'Unknown',
		);
	}

	/**
	 * Get cache information.
	 *
	 * @return array Cache status.
	 */
	public function get_cache_info(): array {
		return array(
			'object_cache'      => wp_using_ext_object_cache() ? 'Yes' : 'No',
			'object_cache_type' => $this->detect_object_cache(),
			'page_cache'        => $this->detect_page_cache() ? 'Yes' : 'No',
		);
	}

	/**
	 * Detect object cache type.
	 *
	 * @return string Cache type.
	 */
	private function detect_object_cache(): string {
		if ( ! wp_using_ext_object_cache() ) {
			return 'None';
		}

		if ( class_exists( 'Redis' ) ) {
			return 'Redis';
		}

		if ( class_exists( 'Memcached' ) ) {
			return 'Memcached';
		}

		if ( class_exists( 'Memcache' ) ) {
			return 'Memcache';
		}

		return 'Unknown';
	}

	/**
	 * Detect if page cache is active.
	 *
	 * @return bool Whether page cache is detected.
	 */
	private function detect_page_cache(): bool {
		return defined( 'WP_CACHE' ) && WP_CACHE;
	}

	/**
	 * Get plugin information.
	 *
	 * @return array Active plugins.
	 */
	public function get_plugin_info(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );
		$plugins        = array();

		foreach ( $active_plugins as $plugin_path ) {
			if ( isset( $all_plugins[ $plugin_path ] ) ) {
				$plugins[] = array(
					'name'    => $all_plugins[ $plugin_path ]['Name'],
					'version' => $all_plugins[ $plugin_path ]['Version'],
					'author'  => $all_plugins[ $plugin_path ]['Author'],
				);
			}
		}

		return array(
			'active_plugins' => (string) count( $active_plugins ),
			'total_plugins'  => (string) count( $all_plugins ),
		);
	}
}
