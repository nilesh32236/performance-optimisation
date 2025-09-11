<?php
/**
 * Preset Manager Class
 *
 * Manages optimization presets including creation, validation, migration,
 * and sharing of preset configurations.
 *
 * @package PerformanceOptimisation\Core\Presets
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\Presets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preset Manager class for handling optimization presets.
 */
class PresetManager {

	/**
	 * Available preset configurations.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $presets;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->presets = $this->get_default_presets();
	}

	/**
	 * Get all available presets.
	 *
	 * @return array<string, array<string, mixed>> Available presets.
	 */
	public function get_presets(): array {
		return $this->presets;
	}

	/**
	 * Get a specific preset by ID.
	 *
	 * @param string $preset_id Preset ID.
	 * @return array<string, mixed>|null Preset configuration or null if not found.
	 */
	public function get_preset( string $preset_id ): ?array {
		return $this->presets[ $preset_id ] ?? null;
	}

	/**
	 * Get preset settings for application.
	 *
	 * @param string $preset_id Preset ID.
	 * @return array<string, mixed> Preset settings.
	 */
	public function get_preset_settings( string $preset_id ): array {
		$preset = $this->get_preset( $preset_id );

		if ( ! $preset ) {
			return array();
		}

		return $preset['settings'] ?? array();
	}

	/**
	 * Validate preset configuration.
	 *
	 * @param array<string, mixed> $preset_config Preset configuration.
	 * @return array<string, mixed> Validation result.
	 */
	public function validate_preset( array $preset_config ): array {
		$errors   = array();
		$warnings = array();

		// Validate required fields
		$required_fields = array( 'id', 'name', 'description', 'settings' );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $preset_config[ $field ] ) ) {
				$errors[] = sprintf( 'Missing required field: %s', $field );
			}
		}

		// Validate preset ID format
		if ( isset( $preset_config['id'] ) ) {
			if ( ! preg_match( '/^[a-z0-9_-]+$/', $preset_config['id'] ) ) {
				$errors[] = 'Preset ID must contain only lowercase letters, numbers, hyphens, and underscores';
			}
		}

		// Validate settings structure
		if ( isset( $preset_config['settings'] ) && is_array( $preset_config['settings'] ) ) {
			$validation_result = $this->validate_settings( $preset_config['settings'] );
			$errors            = array_merge( $errors, $validation_result['errors'] );
			$warnings          = array_merge( $warnings, $validation_result['warnings'] );
		}

		// Check for conflicts
		$conflicts = $this->check_preset_conflicts( $preset_config );
		if ( ! empty( $conflicts ) ) {
			$warnings = array_merge( $warnings, $conflicts );
		}

		return array(
			'valid'    => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Create a custom preset.
	 *
	 * @param array<string, mixed> $preset_config Preset configuration.
	 * @return array<string, mixed> Creation result.
	 */
	public function create_preset( array $preset_config ): array {
		// Validate preset
		$validation = $this->validate_preset( $preset_config );
		if ( ! $validation['valid'] ) {
			return array(
				'success' => false,
				'errors'  => $validation['errors'],
			);
		}

		// Add metadata
		$preset_config['created_at'] = current_time( 'mysql' );
		$preset_config['created_by'] = get_current_user_id();
		$preset_config['type']       = 'custom';
		$preset_config['version']    = '1.0.0';

		// Save preset
		$custom_presets                         = get_option( 'wppo_custom_presets', array() );
		$custom_presets[ $preset_config['id'] ] = $preset_config;

		$saved = update_option( 'wppo_custom_presets', $custom_presets );

		if ( $saved ) {
			// Add to available presets
			$this->presets[ $preset_config['id'] ] = $preset_config;

			return array(
				'success'   => true,
				'preset_id' => $preset_config['id'],
				'warnings'  => $validation['warnings'],
			);
		}

		return array(
			'success' => false,
			'errors'  => array( 'Failed to save preset to database' ),
		);
	}

	/**
	 * Update an existing preset.
	 *
	 * @param string               $preset_id Preset ID.
	 * @param array<string, mixed> $preset_config Updated preset configuration.
	 * @return array<string, mixed> Update result.
	 */
	public function update_preset( string $preset_id, array $preset_config ): array {
		// Check if preset exists and is custom
		$existing_preset = $this->get_preset( $preset_id );
		if ( ! $existing_preset ) {
			return array(
				'success' => false,
				'errors'  => array( 'Preset not found' ),
			);
		}

		if ( ( $existing_preset['type'] ?? 'default' ) !== 'custom' ) {
			return array(
				'success' => false,
				'errors'  => array( 'Cannot modify default presets' ),
			);
		}

		// Validate updated configuration
		$preset_config['id'] = $preset_id; // Ensure ID matches
		$validation          = $this->validate_preset( $preset_config );
		if ( ! $validation['valid'] ) {
			return array(
				'success' => false,
				'errors'  => $validation['errors'],
			);
		}

		// Update metadata
		$preset_config['updated_at'] = current_time( 'mysql' );
		$preset_config['updated_by'] = get_current_user_id();
		$preset_config['type']       = 'custom';

		// Increment version
		$current_version          = $existing_preset['version'] ?? '1.0.0';
		$preset_config['version'] = $this->increment_version( $current_version );

		// Save updated preset
		$custom_presets               = get_option( 'wppo_custom_presets', array() );
		$custom_presets[ $preset_id ] = $preset_config;

		$saved = update_option( 'wppo_custom_presets', $custom_presets );

		if ( $saved ) {
			// Update available presets
			$this->presets[ $preset_id ] = $preset_config;

			return array(
				'success'   => true,
				'preset_id' => $preset_id,
				'warnings'  => $validation['warnings'],
			);
		}

		return array(
			'success' => false,
			'errors'  => array( 'Failed to update preset in database' ),
		);
	}

	/**
	 * Delete a custom preset.
	 *
	 * @param string $preset_id Preset ID.
	 * @return array<string, mixed> Deletion result.
	 */
	public function delete_preset( string $preset_id ): array {
		// Check if preset exists and is custom
		$existing_preset = $this->get_preset( $preset_id );
		if ( ! $existing_preset ) {
			return array(
				'success' => false,
				'errors'  => array( 'Preset not found' ),
			);
		}

		if ( ( $existing_preset['type'] ?? 'default' ) !== 'custom' ) {
			return array(
				'success' => false,
				'errors'  => array( 'Cannot delete default presets' ),
			);
		}

		// Remove from database
		$custom_presets = get_option( 'wppo_custom_presets', array() );
		unset( $custom_presets[ $preset_id ] );

		$saved = update_option( 'wppo_custom_presets', $custom_presets );

		if ( $saved ) {
			// Remove from available presets
			unset( $this->presets[ $preset_id ] );

			return array(
				'success' => true,
			);
		}

		return array(
			'success' => false,
			'errors'  => array( 'Failed to delete preset from database' ),
		);
	}

	/**
	 * Export preset for sharing.
	 *
	 * @param string $preset_id Preset ID.
	 * @return array<string, mixed> Export result.
	 */
	public function export_preset( string $preset_id ): array {
		$preset = $this->get_preset( $preset_id );
		if ( ! $preset ) {
			return array(
				'success' => false,
				'errors'  => array( 'Preset not found' ),
			);
		}

		// Create export data
		$export_data = array(
			'preset'   => array(
				'id'          => $preset['id'],
				'name'        => $preset['name'],
				'description' => $preset['description'],
				'settings'    => $preset['settings'],
				'version'     => $preset['version'] ?? '1.0.0',
				'tags'        => $preset['tags'] ?? array(),
			),
			'metadata' => array(
				'exported_at'       => current_time( 'mysql' ),
				'exported_by'       => get_current_user_id(),
				'plugin_version'    => WPPO_VERSION ?? '1.0.0',
				'wordpress_version' => get_bloginfo( 'version' ),
			),
		);

		return array(
			'success' => true,
			'data'    => $export_data,
			'json'    => wp_json_encode( $export_data, JSON_PRETTY_PRINT ),
		);
	}

	/**
	 * Import preset from export data.
	 *
	 * @param array<string, mixed> $import_data Import data.
	 * @return array<string, mixed> Import result.
	 */
	public function import_preset( array $import_data ): array {
		// Validate import data structure
		if ( ! isset( $import_data['preset'] ) || ! is_array( $import_data['preset'] ) ) {
			return array(
				'success' => false,
				'errors'  => array( 'Invalid import data structure' ),
			);
		}

		$preset_data = $import_data['preset'];

		// Check if preset already exists
		$existing_preset = $this->get_preset( $preset_data['id'] );
		if ( $existing_preset ) {
			// Generate new ID if conflict
			$original_id = $preset_data['id'];
			$counter     = 1;
			while ( $this->get_preset( $preset_data['id'] ) ) {
				$preset_data['id'] = $original_id . '_' . $counter;
				++$counter;
			}
		}

		// Create the preset
		return $this->create_preset( $preset_data );
	}

	/**
	 * Migrate preset to new version.
	 *
	 * @param string $preset_id Preset ID.
	 * @param string $target_version Target version.
	 * @return array<string, mixed> Migration result.
	 */
	public function migrate_preset( string $preset_id, string $target_version ): array {
		$preset = $this->get_preset( $preset_id );
		if ( ! $preset ) {
			return array(
				'success' => false,
				'errors'  => array( 'Preset not found' ),
			);
		}

		$current_version = $preset['version'] ?? '1.0.0';

		// Check if migration is needed
		if ( version_compare( $current_version, $target_version, '>=' ) ) {
			return array(
				'success' => true,
				'message' => 'No migration needed',
			);
		}

		// Perform migration
		$migrated_settings = $this->migrate_settings( $preset['settings'], $current_version, $target_version );

		if ( $migrated_settings === false ) {
			return array(
				'success' => false,
				'errors'  => array( 'Migration failed' ),
			);
		}

		// Update preset with migrated settings
		$preset['settings']      = $migrated_settings;
		$preset['version']       = $target_version;
		$preset['migrated_at']   = current_time( 'mysql' );
		$preset['migrated_from'] = $current_version;

		// Save migrated preset
		if ( ( $preset['type'] ?? 'default' ) === 'custom' ) {
			return $this->update_preset( $preset_id, $preset );
		}

		// For default presets, just return the migrated data
		return array(
			'success'           => true,
			'migrated_settings' => $migrated_settings,
		);
	}

	/**
	 * Get default preset configurations.
	 *
	 * @return array<string, array<string, mixed>> Default presets.
	 */
	private function get_default_presets(): array {
		$presets = array(
			'safe'        => array(
				'id'                 => 'safe',
				'name'               => 'Safe Mode',
				'description'        => 'Basic optimizations that are safe for all websites and hosting environments.',
				'type'               => 'default',
				'version'            => '1.0.0',
				'tags'               => array( 'beginner', 'safe', 'compatible' ),
				'performance_impact' => 'low',
				'compatibility_risk' => 'low',
				'settings'           => array(
					'cache_settings'     => array(
						'enablePageCaching'     => true,
						'cacheExpiration'       => 3600,
						'excludePages'          => array(),
						'enableGzipCompression' => true,
					),
					'image_optimisation' => array(
						'lazyLoadImages'  => true,
						'lazyLoadVideos'  => false,
						'lazyLoadIframes' => false,
					),
					'file_optimisation'  => array(
						'minifyHTML'         => true,
						'removeHTMLComments' => false,
						'minifyCSS'          => false,
						'minifyJS'           => false,
						'combineCSS'         => false,
						'combineJS'          => false,
						'deferJS'            => false,
						'delayJS'            => false,
					),
					'preload_settings'   => array(
						'enablePreloadCache' => false,
						'enableCronJobs'     => true,
					),
				),
			),
			'recommended' => array(
				'id'                 => 'recommended',
				'name'               => 'Recommended',
				'description'        => 'The best balance of performance and compatibility for most websites.',
				'type'               => 'default',
				'version'            => '1.0.0',
				'tags'               => array( 'balanced', 'recommended', 'popular' ),
				'performance_impact' => 'medium',
				'compatibility_risk' => 'medium',
				'settings'           => array(
					'cache_settings'     => array(
						'enablePageCaching'     => true,
						'cacheExpiration'       => 3600,
						'excludePages'          => array(),
						'enableGzipCompression' => true,
						'enableBrowserCaching'  => true,
					),
					'image_optimisation' => array(
						'lazyLoadImages'  => true,
						'lazyLoadVideos'  => true,
						'lazyLoadIframes' => true,
						'convertImg'      => false,
						'format'          => 'webp',
					),
					'file_optimisation'  => array(
						'minifyHTML'         => true,
						'removeHTMLComments' => true,
						'minifyCSS'          => true,
						'minifyJS'           => false,
						'combineCSS'         => true,
						'combineJS'          => false,
						'deferJS'            => false,
						'delayJS'            => false,
					),
					'preload_settings'   => array(
						'enablePreloadCache' => true,
						'enableCronJobs'     => true,
						'preloadPages'       => 10,
					),
				),
			),
			'advanced'    => array(
				'id'                 => 'advanced',
				'name'               => 'Advanced',
				'description'        => 'Maximum performance optimizations. May require testing on some websites.',
				'type'               => 'default',
				'version'            => '1.0.0',
				'tags'               => array( 'advanced', 'performance', 'aggressive' ),
				'performance_impact' => 'high',
				'compatibility_risk' => 'high',
				'settings'           => array(
					'cache_settings'     => array(
						'enablePageCaching'     => true,
						'cacheExpiration'       => 7200,
						'excludePages'          => array(),
						'enableGzipCompression' => true,
						'enableBrowserCaching'  => true,
						'enableObjectCaching'   => true,
					),
					'image_optimisation' => array(
						'lazyLoadImages'  => true,
						'lazyLoadVideos'  => true,
						'lazyLoadIframes' => true,
						'convertImg'      => true,
						'format'          => 'webp',
						'quality'         => 85,
					),
					'file_optimisation'  => array(
						'minifyHTML'         => true,
						'removeHTMLComments' => true,
						'minifyCSS'          => true,
						'minifyJS'           => true,
						'combineCSS'         => true,
						'combineJS'          => true,
						'deferJS'            => true,
						'delayJS'            => true,
						'criticalCSS'        => true,
					),
					'preload_settings'   => array(
						'enablePreloadCache' => true,
						'enableCronJobs'     => true,
						'preloadPages'       => 25,
						'preloadResources'   => true,
					),
				),
			),
		);

		// Load custom presets
		$custom_presets = get_option( 'wppo_custom_presets', array() );
		if ( is_array( $custom_presets ) ) {
			$presets = array_merge( $presets, $custom_presets );
		}

		return $presets;
	}

	/**
	 * Validate settings configuration.
	 *
	 * @param array<string, mixed> $settings Settings to validate.
	 * @return array<string, mixed> Validation result.
	 */
	private function validate_settings( array $settings ): array {
		$errors   = array();
		$warnings = array();

		// Define expected setting groups and their types
		$setting_schema = array(
			'cache_settings'     => array(
				'enablePageCaching' => 'boolean',
				'cacheExpiration'   => 'integer',
				'excludePages'      => 'array',
			),
			'image_optimisation' => array(
				'lazyLoadImages' => 'boolean',
				'convertImg'     => 'boolean',
				'quality'        => 'integer',
			),
			'file_optimisation'  => array(
				'minifyHTML' => 'boolean',
				'minifyCSS'  => 'boolean',
				'minifyJS'   => 'boolean',
			),
			'preload_settings'   => array(
				'enablePreloadCache' => 'boolean',
				'enableCronJobs'     => 'boolean',
			),
		);

		foreach ( $settings as $group => $group_settings ) {
			if ( ! isset( $setting_schema[ $group ] ) ) {
				$warnings[] = sprintf( 'Unknown setting group: %s', $group );
				continue;
			}

			if ( ! is_array( $group_settings ) ) {
				$errors[] = sprintf( 'Setting group %s must be an array', $group );
				continue;
			}

			foreach ( $group_settings as $setting => $value ) {
				if ( ! isset( $setting_schema[ $group ][ $setting ] ) ) {
					$warnings[] = sprintf( 'Unknown setting: %s.%s', $group, $setting );
					continue;
				}

				$expected_type = $setting_schema[ $group ][ $setting ];
				$actual_type   = gettype( $value );

				// Type validation
				if ( $expected_type === 'boolean' && ! is_bool( $value ) ) {
					$errors[] = sprintf( 'Setting %s.%s must be boolean, %s given', $group, $setting, $actual_type );
				} elseif ( $expected_type === 'integer' && ! is_int( $value ) ) {
					$errors[] = sprintf( 'Setting %s.%s must be integer, %s given', $group, $setting, $actual_type );
				} elseif ( $expected_type === 'array' && ! is_array( $value ) ) {
					$errors[] = sprintf( 'Setting %s.%s must be array, %s given', $group, $setting, $actual_type );
				}

				// Value validation
				if ( $setting === 'cacheExpiration' && is_int( $value ) && $value < 300 ) {
					$warnings[] = 'Cache expiration less than 5 minutes may impact performance';
				}

				if ( $setting === 'quality' && is_int( $value ) && ( $value < 1 || $value > 100 ) ) {
					$errors[] = 'Image quality must be between 1 and 100';
				}
			}
		}

		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Check for preset conflicts.
	 *
	 * @param array<string, mixed> $preset_config Preset configuration.
	 * @return array<string> Conflict warnings.
	 */
	private function check_preset_conflicts( array $preset_config ): array {
		$conflicts = array();

		if ( ! isset( $preset_config['settings'] ) ) {
			return $conflicts;
		}

		$settings = $preset_config['settings'];

		// Check for aggressive settings combinations
		if ( isset( $settings['file_optimisation'] ) ) {
			$file_opts = $settings['file_optimisation'];

			if ( ( $file_opts['minifyJS'] ?? false ) && ( $file_opts['combineJS'] ?? false ) ) {
				$conflicts[] = 'Combining JS minification and combination may cause issues with some themes';
			}

			if ( ( $file_opts['deferJS'] ?? false ) && ( $file_opts['delayJS'] ?? false ) ) {
				$conflicts[] = 'Using both JS defer and delay may cause functionality issues';
			}
		}

		// Check for caching conflicts
		if ( isset( $settings['cache_settings'] ) ) {
			$cache_opts = $settings['cache_settings'];

			if ( ( $cache_opts['enableObjectCaching'] ?? false ) && ! extension_loaded( 'redis' ) && ! extension_loaded( 'memcached' ) ) {
				$conflicts[] = 'Object caching enabled but no Redis or Memcached extension found';
			}
		}

		return $conflicts;
	}

	/**
	 * Increment version number.
	 *
	 * @param string $version Current version.
	 * @return string Incremented version.
	 */
	private function increment_version( string $version ): string {
		$parts    = explode( '.', $version );
		$parts[2] = isset( $parts[2] ) ? (int) $parts[2] + 1 : 1;

		return implode( '.', $parts );
	}

	/**
	 * Migrate settings between versions.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @param string               $from_version Source version.
	 * @param string               $to_version Target version.
	 * @return array<string, mixed>|false Migrated settings or false on failure.
	 */
	private function migrate_settings( array $settings, string $from_version, string $to_version ) {
		// This is a simplified migration system
		// In a real implementation, you'd have specific migration rules for each version

		$migrated = $settings;

		// Example migration from 1.0.0 to 1.1.0
		if ( version_compare( $from_version, '1.1.0', '<' ) && version_compare( $to_version, '1.1.0', '>=' ) ) {
			// Add new settings with defaults
			if ( ! isset( $migrated['cache_settings']['enableBrowserCaching'] ) ) {
				$migrated['cache_settings']['enableBrowserCaching'] = true;
			}
		}

		return $migrated;
	}
}
