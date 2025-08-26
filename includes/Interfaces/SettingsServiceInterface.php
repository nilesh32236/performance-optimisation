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

	public function get_settings(): array;

	public function update_settings( array $new_settings ): bool;

	public function get_setting( string $group, string $key );

	public function update_setting( string $group, string $key, $value ): bool;
}
