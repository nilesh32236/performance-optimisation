<?php
/**
 * Enhanced Settings Service
 *
 * @package PerformanceOptimisation\Services
 * @since   2.1.0
 */

namespace PerformanceOptimisation\Services;

use PerformanceOptimisation\Interfaces\SettingsServiceInterface;
use PerformanceOptimisation\Utils\LoggingUtil;
use PerformanceOptimisation\Utils\ValidationUtil;

/**
 * Enhanced Settings Service Class
 */
class EnhancedSettingsService implements SettingsServiceInterface {

	private array $settings_cache   = array();
	private array $default_settings = array();
	private string $option_name     = 'wppo_settings';

	public function __construct() {
		$this->initializeDefaults();
	}

	private function initializeDefaults(): void {
		$this->default_settings = array(
			'caching'      => array(
				'page_cache_enabled'    => false,
				'cache_ttl'             => 3600,
				'cache_exclusions'      => array(),
				'browser_cache_ttl'     => 31536000, // 1 year
				'gzip_compression'      => true,
				'cache_mobile_separate' => false,
				'cache_logged_in_users' => false,
			),
			'minification' => array(
				'minify_css'             => true,
				'minify_js'              => true,
				'minify_html'            => false,
				'combine_css'            => false,
				'combine_js'             => false,
				'inline_critical_css'    => false,
				'defer_non_critical_css' => false,
				'defer_js'               => false,
			),
			'images'       => array(
				'convert_to_webp'     => true,
				'convert_to_avif'     => false,
				'lazy_loading'        => true,
				'compression_quality' => 85,
				'max_width'           => 1920,
				'max_height'          => 1080,
				'progressive_jpeg'    => true,
				'strip_metadata'      => true,
			),
			'database'     => array(
				'cleanup_revisions'      => false,
				'cleanup_spam'           => false,
				'cleanup_trash'          => false,
				'cleanup_transients'     => true,
				'optimize_tables'        => false,
				'auto_cleanup_frequency' => 'weekly',
			),
			'advanced'     => array(
				'disable_emojis'        => false,
				'disable_embeds'        => false,
				'disable_xml_rpc'       => false,
				'remove_query_strings'  => false,
				'disable_heartbeat'     => false,
				'limit_post_revisions'  => 5,
				'increase_memory_limit' => false,
			),
			'performance'  => array(
				'enable_monitoring'  => true,
				'track_core_vitals'  => true,
				'performance_budget' => array(
					'fcp' => 1.8, // First Contentful Paint
					'lcp' => 2.5, // Largest Contentful Paint
					'fid' => 100, // First Input Delay
					'cls' => 0.1, // Cumulative Layout Shift
				),
			),
			'security'     => array(
				'hide_wp_version'            => true,
				'disable_file_editing'       => true,
				'remove_wp_meta'             => true,
				'disable_directory_browsing' => true,
			),
		);
	}

	public function get_setting( string $section, string $key = null, $default = null ) {
		$settings = $this->getAllSettings();

		if ( $key === null ) {
			return $settings[ $section ] ?? $default;
		}

		return $settings[ $section ][ $key ] ?? $default;
	}

	public function update_setting( string $section, string $key, $value ): bool {
		$settings = $this->getAllSettings();

		if ( ! isset( $settings[ $section ] ) ) {
			$settings[ $section ] = array();
		}

		// Validate the setting
		if ( ! $this->validateSetting( $section, $key, $value ) ) {
			LoggingUtil::warning( "Invalid setting value for {$section}.{$key}" );
			return false;
		}

		$settings[ $section ][ $key ] = $value;

		return $this->saveSettings( $settings );
	}

	public function getAllSettings(): array {
		if ( ! empty( $this->settings_cache ) ) {
			return $this->settings_cache;
		}

		$saved_settings       = get_option( $this->option_name, array() );
		$this->settings_cache = $this->mergeWithDefaults( $saved_settings );

		return $this->settings_cache;
	}

	public function updateSettings( array $new_settings ): bool {
		try {
			// Validate all settings
			foreach ( $new_settings as $section => $section_settings ) {
				if ( ! is_array( $section_settings ) ) {
					continue;
				}

				foreach ( $section_settings as $key => $value ) {
					if ( ! $this->validateSetting( $section, $key, $value ) ) {
						throw new \InvalidArgumentException( "Invalid setting: {$section}.{$key}" );
					}
				}
			}

			// Merge with existing settings
			$current_settings = $this->getAllSettings();
			$merged_settings  = array_replace_recursive( $current_settings, $new_settings );

			return $this->saveSettings( $merged_settings );

		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Failed to update settings: ' . $e->getMessage() );
			return false;
		}
	}

	public function resetSettings( string $section = null ): bool {
		if ( $section ) {
			$settings             = $this->getAllSettings();
			$settings[ $section ] = $this->default_settings[ $section ] ?? array();
			return $this->saveSettings( $settings );
		}

		return $this->saveSettings( $this->default_settings );
	}

	public function exportSettings(): array {
		return array(
			'version'     => WPPO_VERSION,
			'exported_at' => current_time( 'mysql' ),
			'settings'    => $this->getAllSettings(),
		);
	}

	public function importSettings( array $import_data ): bool {
		if ( ! isset( $import_data['settings'] ) || ! is_array( $import_data['settings'] ) ) {
			return false;
		}

		return $this->updateSettings( $import_data['settings'] );
	}

	private function mergeWithDefaults( array $saved_settings ): array {
		return array_replace_recursive( $this->default_settings, $saved_settings );
	}

	private function saveSettings( array $settings ): bool {
		$result = update_option( $this->option_name, $settings );

		if ( $result ) {
			$this->settings_cache = $settings;

			// Clear any related caches
			$this->clearRelatedCaches();

			// Log the change
			LoggingUtil::info( 'Settings updated successfully' );

			// Trigger action hook
			do_action( 'wppo_settings_updated', $settings );
		}

		return $result;
	}

	private function validateSetting( string $section, string $key, $value ): bool {
		$validation_rules = $this->getValidationRules();

		if ( ! isset( $validation_rules[ $section ][ $key ] ) ) {
			return true; // No validation rule, allow it
		}

		$rule = $validation_rules[ $section ][ $key ];

		return ValidationUtil::validate( $value, $rule );
	}

	private function getValidationRules(): array {
		return array(
			'caching'      => array(
				'page_cache_enabled'    => 'boolean',
				'cache_ttl'             => array( 'integer', 'min:300', 'max:86400' ),
				'cache_exclusions'      => 'array',
				'browser_cache_ttl'     => array( 'integer', 'min:3600' ),
				'gzip_compression'      => 'boolean',
				'cache_mobile_separate' => 'boolean',
				'cache_logged_in_users' => 'boolean',
			),
			'minification' => array(
				'minify_css'             => 'boolean',
				'minify_js'              => 'boolean',
				'minify_html'            => 'boolean',
				'combine_css'            => 'boolean',
				'combine_js'             => 'boolean',
				'inline_critical_css'    => 'boolean',
				'defer_non_critical_css' => 'boolean',
				'defer_js'               => 'boolean',
			),
			'images'       => array(
				'convert_to_webp'     => 'boolean',
				'convert_to_avif'     => 'boolean',
				'lazy_loading'        => 'boolean',
				'compression_quality' => array( 'integer', 'min:50', 'max:100' ),
				'max_width'           => array( 'integer', 'min:100', 'max:4000' ),
				'max_height'          => array( 'integer', 'min:100', 'max:4000' ),
				'progressive_jpeg'    => 'boolean',
				'strip_metadata'      => 'boolean',
			),
			'database'     => array(
				'cleanup_revisions'      => 'boolean',
				'cleanup_spam'           => 'boolean',
				'cleanup_trash'          => 'boolean',
				'cleanup_transients'     => 'boolean',
				'optimize_tables'        => 'boolean',
				'auto_cleanup_frequency' => array( 'string', 'in:daily,weekly,monthly' ),
			),
			'advanced'     => array(
				'disable_emojis'        => 'boolean',
				'disable_embeds'        => 'boolean',
				'disable_xml_rpc'       => 'boolean',
				'remove_query_strings'  => 'boolean',
				'disable_heartbeat'     => 'boolean',
				'limit_post_revisions'  => array( 'integer', 'min:0', 'max:50' ),
				'increase_memory_limit' => 'boolean',
			),
			'performance'  => array(
				'enable_monitoring' => 'boolean',
				'track_core_vitals' => 'boolean',
			),
			'security'     => array(
				'hide_wp_version'            => 'boolean',
				'disable_file_editing'       => 'boolean',
				'remove_wp_meta'             => 'boolean',
				'disable_directory_browsing' => 'boolean',
			),
		);
	}

	private function clearRelatedCaches(): void {
		// Clear WordPress object cache
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		// Clear plugin-specific caches
		delete_transient( 'wppo_settings_cache' );

		// Clear any page caches if caching settings changed
		do_action( 'wppo_clear_page_cache' );
	}

	public function getSettingsSchema(): array {
		return array(
			'caching' => array(
				'title'       => __( 'Caching Settings', 'performance-optimisation' ),
				'description' => __( 'Configure page and object caching options', 'performance-optimisation' ),
				'fields'      => array(
					'page_cache_enabled' => array(
						'type'        => 'boolean',
						'title'       => __( 'Enable Page Caching', 'performance-optimisation' ),
						'description' => __( 'Cache full HTML pages for faster loading', 'performance-optimisation' ),
					),
					'cache_ttl'          => array(
						'type'        => 'integer',
						'title'       => __( 'Cache TTL (seconds)', 'performance-optimisation' ),
						'description' => __( 'How long to keep cached pages', 'performance-optimisation' ),
						'min'         => 300,
						'max'         => 86400,
					),
					// ... more fields
				),
			),
			// ... more sections
		);
	}

	public function getPerformanceImpact( string $section, string $key ): array {
		$impact_map = array(
			'caching.page_cache_enabled' => array(
				'performance_gain' => 'high',
				'complexity'       => 'low',
				'description'      => __( 'Significantly reduces server load and page load times', 'performance-optimisation' ),
			),
			'minification.minify_css'    => array(
				'performance_gain' => 'medium',
				'complexity'       => 'low',
				'description'      => __( 'Reduces CSS file sizes by removing whitespace', 'performance-optimisation' ),
			),
			// ... more mappings
		);

		return $impact_map[ "{$section}.{$key}" ] ?? array(
			'performance_gain' => 'unknown',
			'complexity'       => 'unknown',
			'description'      => '',
		);
	}
}
