<?php
/**
 * Configuration Interface
 *
 * @package PerformanceOptimisation\Core\Config
 * @since   2.0.0
 */

namespace PerformanceOptimisation\Core\Config;

/**
 * Config Interface
 *
 * Defines the contract for configuration management implementations.
 *
 * @since 2.0.0
 */
interface ConfigInterface {

	/**
	 * Get a configuration value.
	 *
	 * @since 2.0.0
	 *
	 * @param string $key     Configuration key (supports dot notation).
	 * @param mixed  $default Default value if key doesn't exist.
	 * @return mixed Configuration value.
	 */
	public function get( string $key, $default = null );

	/**
	 * Set a configuration value.
	 *
	 * @since 2.0.0
	 *
	 * @param string $key   Configuration key (supports dot notation).
	 * @param mixed  $value Configuration value.
	 * @return void
	 */
	public function set( string $key, $value ): void;

	/**
	 * Check if a configuration key exists.
	 *
	 * @since 2.0.0
	 *
	 * @param string $key Configuration key (supports dot notation).
	 * @return bool True if key exists, false otherwise.
	 */
	public function has( string $key ): bool;

	/**
	 * Remove a configuration key.
	 *
	 * @since 2.0.0
	 *
	 * @param string $key Configuration key (supports dot notation).
	 * @return void
	 */
	public function remove( string $key ): void;

	/**
	 * Get all configuration values.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, mixed> All configuration values.
	 */
	public function all(): array;

	/**
	 * Save configuration to persistent storage.
	 *
	 * @since 2.0.0
	 *
	 * @return bool True on success, false on failure.
	 */
	public function save(): bool;

	/**
	 * Reload configuration from persistent storage.
	 *
	 * @since 2.0.0
	 *
	 * @return bool True on success, false on failure.
	 */
	public function reload(): bool;

	/**
	 * Reset configuration to defaults.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function reset(): void;
}
