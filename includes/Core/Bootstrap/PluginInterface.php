<?php
/**
 * Plugin Interface
 *
 * @package PerformanceOptimisation\Core\Bootstrap
 * @since   2.0.0
 */

namespace PerformanceOptimisation\Core\Bootstrap;

/**
 * Plugin Interface
 *
 * Defines the contract for plugin implementations.
 *
 * @since 2.0.0
 */
interface PluginInterface {

	/**
	 * Initialize the plugin.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function initialize(): void;

	/**
	 * Activate the plugin.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function activate(): void;

	/**
	 * Deactivate the plugin.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function deactivate(): void;

	/**
	 * Get plugin version.
	 *
	 * @since 2.0.0
	 *
	 * @return string Plugin version.
	 */
	public function get_version(): string;

	/**
	 * Get plugin path.
	 *
	 * @since 2.0.0
	 *
	 * @return string Plugin path.
	 */
	public function get_path(): string;

	/**
	 * Get plugin URL.
	 *
	 * @since 2.0.0
	 *
	 * @return string Plugin URL.
	 */
	public function get_url(): string;

	/**
	 * Check if plugin is initialized.
	 *
	 * @since 2.0.0
	 *
	 * @return bool True if initialized, false otherwise.
	 */
	public function is_initialized(): bool;
}
