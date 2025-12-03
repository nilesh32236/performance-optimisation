<?php
/**
 * Wizard Manager Class
 *
 * @package PerformanceOptimisation
 */

namespace PerformanceOptimisation\Core\Wizard;

/**
 * Class WizardManager
 *
 * Handles wizard setup and preset application.
 */
class WizardManager {

	/**
	 * Apply a preset configuration with optional features.
	 *
	 * @param string $preset The preset to apply.
	 * @param array  $features Optional features to enable.
	 * @return array Result of the preset application.
	 */
	public function apply_preset( string $preset, array $features = array() ): array {
		try {
			// Map frontend preset names to internal names
			$preset_map = array(
				'advanced'    => 'aggressive',
				'standard'    => 'conservative',
				'recommended' => 'balanced',
			);

			$internal_preset = $preset_map[ $preset ] ?? $preset;

			// Get default settings for the preset
			$settings = $this->get_preset_settings( $internal_preset );

			// Apply feature overrides
			if ( ! empty( $features ) ) {
				$settings = $this->apply_feature_overrides( $settings, $features );
			}

			// Save the settings
			update_option( 'wppo_settings', $settings );

			// Mark wizard as completed
			update_option( 'wppo_setup_wizard_completed', true );

			return array(
				'success'  => true,
				'preset'   => $preset,
				'settings' => $settings,
				'message'  => sprintf( 'Successfully applied %s preset configuration.', $preset ),
			);

		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * Get default settings for a preset.
	 *
	 * @param string $preset The preset name.
	 * @return array Default settings for the preset.
	 */
	private function get_preset_settings( string $preset ): array {
		$base_settings = array(
			'cache_settings'     => array(
				'enablePageCaching' => false,
				'cacheExpiration'   => 3600,
			),
			'file_optimisation'  => array(
				'minifyCSS'  => false,
				'minifyJS'   => false,
				'minifyHTML' => false,
			),
			'image_optimisation' => array(
				'lazyLoadImages' => false,
				'convertToWebP'  => false,
			),
			'preload_settings'   => array(
				'enablePreloadCache' => false,
			),
		);

		switch ( $preset ) {
			case 'aggressive':
				return array_merge_recursive(
					$base_settings,
					array(
						'cache_settings'     => array(
							'enablePageCaching' => true,
							'cacheExpiration'   => 7200,
						),
						'file_optimisation'  => array(
							'minifyCSS'  => true,
							'minifyJS'   => true,
							'minifyHTML' => true,
						),
						'image_optimisation' => array(
							'lazyLoadImages' => true,
							'convertToWebP'  => true,
						),
						'preload_settings'   => array(
							'enablePreloadCache' => true,
						),
					)
				);

			case 'balanced':
				return array_merge_recursive(
					$base_settings,
					array(
						'cache_settings'     => array(
							'enablePageCaching' => true,
							'cacheExpiration'   => 3600,
						),
						'file_optimisation'  => array(
							'minifyCSS' => true,
							'minifyJS'  => true,
						),
						'image_optimisation' => array(
							'lazyLoadImages' => true,
						),
					)
				);

			case 'conservative':
			default:
				return array_merge_recursive(
					$base_settings,
					array(
						'cache_settings'     => array(
							'enablePageCaching' => true,
						),
						'image_optimisation' => array(
							'lazyLoadImages' => true,
						),
					)
				);
		}
	}

	/**
	 * Apply feature overrides to settings.
	 *
	 * @param array $settings Base settings.
	 * @param array $features Feature overrides.
	 * @return array Modified settings.
	 */
	private function apply_feature_overrides( array $settings, array $features ): array {
		// Map frontend feature names to settings paths
		$feature_map = array(
			'preloadCache'    => 'preload_settings.enablePreloadCache',
			'imageConversion' => 'image_optimisation.convertToWebP',
			'criticalCSS'     => 'file_optimisation.minifyCSS',
			'resourceHints'   => 'cache_settings.enablePageCaching',
		);

		foreach ( $features as $feature => $enabled ) {
			if ( isset( $feature_map[ $feature ] ) && $enabled ) {
				$path                             = explode( '.', $feature_map[ $feature ] );
				$settings[ $path[0] ][ $path[1] ] = true;
			}
		}

		return $settings;
	}
}
