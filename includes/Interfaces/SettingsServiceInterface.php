<?php
/**
 * Settings Service Interface
 *
 * @package PerformanceOptimisation\Interfaces
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Interfaces;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface SettingsServiceInterface
 *
 * @package PerformanceOptimisation\Interfaces
 */
interface SettingsServiceInterface {

	/**
	 * Retrieve all settings.
	 *
	 * @since 2.0.0
	 * @return array Associative array of all settings.
	 */
	public function get_settings(): array;

	/**
	 * Update multiple settings.
	 *
	 * @since 2.0.0
	 * @param array $new_settings New settings to apply.
	 * @return bool True on success, false on failure.
	 */
	public function update_settings( array $new_settings ): bool;

	/**
	 * Get a specific setting.
	 *
	 * @since 2.0.0
	 * @param string $group Setting group.
	 * @param string $key Setting key.
	 * @return mixed Setting value or null if not found.
	 */
	public function get_setting( string $group, string $key );

	/**
	 * Update a specific setting.
	 *
	 * @since 2.0.0
	 * @param string $group Setting group.
	 * @param string $key Setting key.
	 * @param mixed  $value New value for the setting.
	 * @return bool True on success, false on failure.
	 */
	public function update_setting( string $group, string $key, $value ): bool;
}
