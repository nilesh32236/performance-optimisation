<?php
/**
 * Settings Service
 *
 * Modern settings management with validation, migration, and configuration service integration.
 *
 * @package PerformanceOptimisation\Services
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Services;

use PerformanceOptimisation\Interfaces\SettingsServiceInterface;
use PerformanceOptimisation\Interfaces\ServiceContainerInterface;
use PerformanceOptimisation\Services\ConfigurationService;
use PerformanceOptimisation\Utils\ValidationUtil;
use PerformanceOptimisation\Utils\LoggingUtil;
use PerformanceOptimisation\Utils\PerformanceUtil;
use PerformanceOptimisation\Exceptions\ConfigurationException;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Settings Service Class
 *
 * Provides modern settings management with validation, migration, and caching.
 */
class SettingsService implements SettingsServiceInterface
{

	/**
	 * Legacy option name for migration.
	 */
	private const LEGACY_OPTION_NAME = 'wppo_settings';

	/**
	 * Current settings version for migration.
	 */
	private const SETTINGS_VERSION = '2.0.0';

	/**
	 * Service container.
	 *
	 * @var ServiceContainerInterface
	 */
	private ServiceContainerInterface $container;

	/**
	 * Configuration service.
	 *
	 * @var ConfigurationService|null
	 */
	private ?ConfigurationService $config = null;

	/**
	 * Validator instance.
	 *
	 * @var ValidationUtil
	 */
	private ValidationUtil $validator;

	/**
	 * Logger instance.
	 *
	 * @var LoggingUtil
	 */
	private LoggingUtil $logger;

	/**
	 * Performance utility.
	 *
	 * @var PerformanceUtil
	 */
	private PerformanceUtil $performance;

	/**
	 * Settings cache.
	 *
	 * @var array
	 */
	private array $settings_cache = array();

	/**
	 * Migration status.
	 *
	 * @var bool
	 */
	private bool $migration_completed = false;

	/**
	 * Constructor.
	 *
	 * @param ServiceContainerInterface $container Service container.
	 */
	public function __construct(ServiceContainerInterface $container)
	{
		$this->container = $container;

		// Get services if available, otherwise use defaults
		try {
			$this->validator = $container->get('validator');
		} catch (\Exception $e) {
			$this->validator = new ValidationUtil();
		}

		try {
			$this->logger = $container->get('logger');
		} catch (\Exception $e) {
			$this->logger = new LoggingUtil();
		}

		try {
			$this->performance = $container->get('performance');
		} catch (\Exception $e) {
			$this->performance = new PerformanceUtil();
		}

		$this->initialize();
	}

	/**
	 * Get configuration service (lazy-loaded).
	 *
	 * @return ConfigurationService|null
	 */
	private function getConfig(): ?ConfigurationService
	{
		if ($this->config === null) {
			try {
				$this->config = $this->container->get('PerformanceOptimisation\\Services\\ConfigurationService');
			} catch (\Exception $e) {
				$this->logger->debug('ConfigurationService not available: ' . $e->getMessage());
				return null;
			}
		}
		return $this->config;
	}

	/**
	 * Get all settings.
	 *
	 * @return array All settings.
	 */
	public function get_settings(): array
	{
		$timer_name = 'settings_get_all';
		$this->performance->startTimer($timer_name);

		try {
			// Try to use ConfigurationService if available, otherwise fall back to WordPress options
			$config = $this->getConfig();
			if ($config !== null) {
				$settings = $config->all();
			} else {
				// Fallback: get settings directly from WordPress options
				$settings = get_option(self::LEGACY_OPTION_NAME, $this->get_default_settings());
			}

			$this->performance->endTimer($timer_name);
			return $settings;

		} catch (\Exception $e) {
			$this->performance->endTimer($timer_name);
			$this->logger->error('Failed to get all settings: ' . $e->getMessage());
			return $this->get_default_settings();
		}
	}

	/**
	 * Update multiple settings with validation.
	 *
	 * @param array $new_settings New settings to update.
	 * @return bool True on success, false on failure.
	 */
	public function update_settings(array $new_settings): bool
	{
		$timer_name = 'settings_update_multiple';
		$this->performance->startTimer($timer_name);

		try {
			// Migrate legacy settings format if needed
			$new_settings = $this->migrateLegacySettings($new_settings);

			$config = $this->getConfig();
			if ($config !== null) {
				// Use ConfigurationService if available
				$result = $config->update($new_settings);
			} else {
				// Fallback: update WordPress options directly
				$result = update_option(self::LEGACY_OPTION_NAME, $new_settings);
			}

			if ($result) {
				// Clear settings cache
				$this->settings_cache = array();

				// Log successful update
				$this->logger->info(
					'Settings updated successfully',
					array(
						'sections' => array_keys($new_settings),
						'total_keys' => $this->countSettingsKeys($new_settings),
					)
				);

				// Trigger settings updated action
				do_action('wppo_settings_updated', $new_settings, $this);
			}

			$this->performance->endTimer($timer_name);
			return $result;

		} catch (ConfigurationException $e) {
			$this->performance->endTimer($timer_name);
			$this->logger->error('Settings update failed: ' . $e->getFormattedMessage());
			return false;
		}
	}

	/**
	 * Get a specific setting value.
	 *
	 * @param string $group Setting group.
	 * @param string $key   Setting key.
	 * @param mixed  $default Default value if not found.
	 * @return mixed Setting value.
	 */
	public function get_setting(string $group, string $key, $default = null)
	{
		$cache_key = "{$group}.{$key}";

		// Check cache first
		if (isset($this->settings_cache[$cache_key])) {
			return $this->settings_cache[$cache_key];
		}

		$config = $this->getConfig();
		if ($config !== null) {
			$value = $config->get($cache_key, $default);
		} else {
			// Fallback: get from WordPress options
			$all_settings = get_option(self::LEGACY_OPTION_NAME, array());
			$value = $all_settings[$group][$key] ?? $default;
		}

		// Cache the result
		$this->settings_cache[$cache_key] = $value;

		return $value;
	}

	/**
	 * Update a single setting value.
	 *
	 * @param string $group Setting group.
	 * @param string $key   Setting key.
	 * @param mixed  $value Setting value.
	 * @return bool True on success, false on failure.
	 */
	public function update_setting(string $group, string $key, $value): bool
	{
		$timer_name = 'settings_update_single';
		$this->performance->startTimer($timer_name);

		try {
			$config_key = "{$group}.{$key}";
			$config = $this->getConfig();

			if ($config !== null) {
				// Set in configuration service (includes validation)
				$result = $config->set($config_key, $value);
				if ($result) {
					$result = $config->save();
				}
			} else {
				// Fallback: update WordPress option directly
				$all_settings = get_option(self::LEGACY_OPTION_NAME, array());
				$all_settings[$group][$key] = $value;
				$result = update_option(self::LEGACY_OPTION_NAME, $all_settings);
			}

			if ($result) {
				// Update cache
				$this->settings_cache[$config_key] = $value;

				$this->logger->debug(
					'Single setting updated',
					array(
						'group' => $group,
						'key' => $key,
						'type' => gettype($value),
					)
				);

				// Trigger setting updated action
				do_action('wppo_setting_updated', $group, $key, $value, $this);
			}

			$this->performance->endTimer($timer_name);
			return $result;

		} catch (\Exception $e) {
			$this->performance->endTimer($timer_name);
			$this->logger->error(
				'Single setting update failed',
				array(
					'group' => $group,
					'key' => $key,
					'error' => $e->getMessage(),
				)
			);
			return false;
		}
	}

	/**
	 * Initialize default settings.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function initialize_default_settings(): bool
	{
		try {
			$config = $this->getConfig();

			// Check if settings already exist
			if ($config !== null && $config->has('caching.page_cache_enabled')) {
				$this->logger->debug('Settings already initialized, skipping');
				return true;
			}

			// Check WordPress option as fallback
			$existing = get_option(self::LEGACY_OPTION_NAME, array());
			if (!empty($existing)) {
				$this->logger->debug('Settings already exist in WordPress options, skipping');
				return true;
			}

			// Set default configuration
			$defaults = $this->get_default_settings();

			if ($config !== null) {
				$result = $config->update($defaults);
			} else {
				$result = update_option(self::LEGACY_OPTION_NAME, $defaults);
			}

			if ($result) {
				// Set settings version
				update_option('wppo_settings_version', self::SETTINGS_VERSION);

				$this->logger->info(
					'Default settings initialized',
					array(
						'version' => self::SETTINGS_VERSION,
						'sections' => array_keys($defaults),
					)
				);
			}

			return $result;

		} catch (\Exception $e) {
			$this->logger->error('Failed to initialize default settings: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Reset settings to defaults.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function reset_to_defaults(): bool
	{
		try {
			$this->getConfig()->reset();
			$this->settings_cache = array();

			$this->logger->info('Settings reset to defaults');

			// Trigger settings reset action
			do_action('wppo_settings_reset', $this);

			return true;

		} catch (\Exception $e) {
			$this->logger->error('Failed to reset settings: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Export settings to file.
	 *
	 * @param string $file_path File path to export to.
	 * @param string $format    Export format (json, php).
	 * @return bool True on success, false on failure.
	 */
	public function export_settings(string $file_path, string $format = 'json'): bool
	{
		return $this->getConfig()->export($file_path, $format);
	}

	/**
	 * Import settings from file.
	 *
	 * @param string $file_path File path to import from.
	 * @param bool   $merge     Whether to merge with existing settings.
	 * @return bool True on success, false on failure.
	 */
	public function import_settings(string $file_path, bool $merge = true): bool
	{
		$result = $this->getConfig()->import($file_path, $merge);

		if ($result) {
			$this->settings_cache = array(); // Clear cache

			// Trigger settings imported action
			do_action('wppo_settings_imported', $file_path, $merge, $this);
		}

		return $result;
	}

	/**
	 * Get settings validation errors.
	 *
	 * @param array $settings Settings to validate.
	 * @return array Validation result with 'valid' boolean and 'errors' array.
	 */
	public function validate_settings(array $settings): array
	{
		return $this->getConfig()->validateConfiguration($settings);
	}

	/**
	 * Check if settings need migration.
	 *
	 * @return bool True if migration is needed, false otherwise.
	 */
	public function needs_migration(): bool
	{
		$current_version = get_option('wppo_settings_version', '1.0.0');
		return version_compare($current_version, self::SETTINGS_VERSION, '<');
	}

	/**
	 * Migrate settings from older versions.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function migrate_settings(): bool
	{
		if ($this->migration_completed) {
			return true;
		}

		try {
			$this->performance->startTimer('settings_migration');

			$current_version = get_option('wppo_settings_version', '1.0.0');
			$this->logger->info(
				'Starting settings migration',
				array(
					'from_version' => $current_version,
					'to_version' => self::SETTINGS_VERSION,
				)
			);

			// Get legacy settings
			$legacy_settings = get_option(self::LEGACY_OPTION_NAME, array());

			if (!empty($legacy_settings)) {
				// Migrate legacy settings to new format
				$migrated_settings = $this->migrateLegacySettings($legacy_settings);

				// Update configuration with migrated settings
				$config = $this->getConfig();
				if ($config !== null) {
					$config->update($migrated_settings);
				} else {
					// Fallback: update WordPress option directly
					update_option(self::LEGACY_OPTION_NAME, $migrated_settings);
				}

				// Backup legacy settings
				update_option(self::LEGACY_OPTION_NAME . '_backup_' . time(), $legacy_settings);

				$this->logger->info(
					'Legacy settings migrated',
					array(
						'legacy_keys' => array_keys($legacy_settings),
						'migrated_keys' => array_keys($migrated_settings),
					)
				);
			}

			// Update settings version
			update_option('wppo_settings_version', self::SETTINGS_VERSION);

			$this->migration_completed = true;
			$duration = $this->performance->endTimer("settings_migration");

			$this->logger->info(
				'Settings migration completed',
				array(
					'duration' => $duration,
					'version' => self::SETTINGS_VERSION,
				)
			);

			// Trigger migration completed action
			do_action('wppo_settings_migrated', $current_version, self::SETTINGS_VERSION, $this);

			return true;

		} catch (\Exception $e) {
			$this->logger->error('Settings migration failed: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Get settings statistics.
	 *
	 * @return array Settings statistics.
	 */
	public function get_stats(): array
	{
		$all_settings = $this->get_settings();

		return array(
			'total_sections' => count($all_settings),
			'total_settings' => $this->countSettingsKeys($all_settings),
			'cache_size' => count($this->settings_cache),
			'version' => get_option('wppo_settings_version', '1.0.0'),
			'environment' => $this->getConfig()->getEnvironment(),
			'migration_needed' => $this->needs_migration(),
		);
	}

	/**
	 * Initialize the service.
	 */
	private function initialize(): void
	{
		// Defer configuration service usage - it will be loaded lazily when needed
		try {
			// Perform migration if needed (doesn't require config service)
			if ($this->needs_migration()) {
				$this->migrate_settings();
			}

			// Initialize default settings if needed
			$this->initialize_default_settings();

			$this->logger->debug(
				'SettingsService initialized',
				array(
					'version' => get_option('wppo_settings_version'),
				)
			);
		} catch (\Exception $e) {
			$this->logger->error('SettingsService initialization failed: ' . $e->getMessage());
		}
	}

	/**
	 * Migrate legacy settings format to new format.
	 *
	 * @param array $legacy_settings Legacy settings.
	 * @return array Migrated settings.
	 */
	private function migrateLegacySettings(array $legacy_settings): array
	{
		$migrated = array();

		// Map legacy file_optimisation to minification
		if (isset($legacy_settings['file_optimisation'])) {
			$file_opt = $legacy_settings['file_optimisation'];
			$migrated['minification'] = array(
				'minify_css' => $file_opt['minifyCss'] ?? false,
				'minify_js' => $file_opt['minifyJs'] ?? false,
				'minify_html' => $file_opt['minifyHtml'] ?? false,
				'combine_css' => $file_opt['combineCss'] ?? false,
				'combine_js' => $file_opt['combineJs'] ?? false,
			);
		}

		// Map legacy image_optimisation to images
		if (isset($legacy_settings['image_optimisation'])) {
			$img_opt = $legacy_settings['image_optimisation'];
			$migrated['images'] = array(
				'convert_to_webp' => $img_opt['webp_conversion'] ?? false,
				'convert_to_avif' => $img_opt['avif_conversion'] ?? false,
				'lazy_loading' => $img_opt['lazy_loading'] ?? false,
				'compression_quality' => $img_opt['quality'] ?? 85,
			);
		}

		// Map legacy preload_settings to caching
		if (isset($legacy_settings['preload_settings'])) {
			$preload = $legacy_settings['preload_settings'];
			$migrated['caching'] = array(
				'page_cache_enabled' => $preload['enablePreloadCache'] ?? false,
			);
		}

		// Keep any modern format settings as-is
		foreach (array('caching', 'minification', 'images', 'preloading', 'database', 'advanced') as $section) {
			if (isset($legacy_settings[$section])) {
				$migrated[$section] = array_merge($migrated[$section] ?? array(), $legacy_settings[$section]);
			}
		}

		return $migrated;
	}

	/**
	 * Get default settings structure.
	 *
	 * @return array Default settings.
	 */
	private function get_default_settings(): array
	{
		return array(
			'caching' => array(
				'page_cache_enabled' => false,
				'cache_ttl' => 3600,
				'cache_exclusions' => array(),
				'object_cache_enabled' => false,
				'fragment_cache_enabled' => false,
			),
			'minification' => array(
				'minify_css' => true,
				'minify_js' => true,
				'minify_html' => false,
				'combine_css' => false,
				'combine_js' => false,
				'inline_critical_css' => false,
				'exclude_css' => array(),
				'exclude_js' => array(),
				'exclude_css_files' => array(),
				'exclude_js_files' => array(),
			),
			'images' => array(
				'convert_to_webp' => true,
				'convert_to_avif' => false,
				'lazy_loading' => true,
				'compression_quality' => 85,
				'resize_large_images' => true,
				'max_image_width' => 1920,
				'max_image_height' => 1080,
			),
			'preloading' => array(
				'preload_fonts' => array(),
				'preload_critical_css' => false,
				'dns_prefetch' => array(),
				'preconnect' => array(),
				'preload_images' => array(),
			),
			'heartbeat_control' => array(
				'enabled' => false,
				'locations' => array(
					'dashboard' => 60, // Default 60s
					'post_edit' => 15, // Default 15s
					'frontend' => 60, // Default 60s
				),
			),
			'database' => array(
				'cleanup_revisions' => false,
				'cleanup_spam' => false,
				'cleanup_trash' => false,
				'optimize_tables' => false,
			),
			'advanced' => array(
				'disable_emojis' => false,
				'disable_embeds' => false,
				'remove_query_strings' => false,
				'defer_js' => false,
				'async_js' => false,
			),
		);
	}

	/**
	 * Count settings keys recursively.
	 *
	 * @param array $settings Settings array.
	 * @return int Number of keys.
	 */
	private function countSettingsKeys(array $settings): int
	{
		$count = 0;
		foreach ($settings as $value) {
			if (is_array($value)) {
				$count += $this->countSettingsKeys($value);
			} else {
				++$count;
			}
		}
		return $count;
	}
}
