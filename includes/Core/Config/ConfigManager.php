<?php
/**
 * Configuration Manager
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\Config;

use PerformanceOptimisation\Interfaces\ConfigInterface;
use PerformanceOptimisation\Exceptions\ConfigurationException;

/**
 * Configuration management class
 *
 * @since 1.1.0
 */
class ConfigManager implements ConfigInterface {

	/**
	 * Configuration data
	 *
	 * @since 1.1.0
	 * @var array
	 */
	private array $config = array();

	/**
	 * WordPress option name for storing configuration
	 *
	 * @since 1.1.0
	 * @var string
	 */
	private string $option_name = 'wppo_settings';

	/**
	 * Default configuration values
	 *
	 * @since 1.1.0
	 * @var array
	 */
	private array $defaults = array(
		'caching'      => array(
			'page_cache_enabled'     => false,
			'cache_ttl'              => 3600,
			'cache_exclusions'       => array(),
			'object_cache_enabled'   => false,
			'fragment_cache_enabled' => false,
		),
		'minification' => array(
			'minify_css'          => true,
			'minify_js'           => true,
			'minify_html'         => false,
			'combine_css'         => false,
			'combine_js'          => false,
			'inline_critical_css' => false,
		),
		'images'       => array(
			'convert_to_webp'     => true,
			'convert_to_avif'     => false,
			'lazy_loading'        => true,
			'compression_quality' => 85,
			'resize_large_images' => true,
			'max_image_width'     => 1920,
			'max_image_height'    => 1080,
		),
		'preloading'   => array(
			'dns_prefetch'         => array(),
			'preconnect'           => array(),
			'preload_fonts'        => array(),
			'preload_critical_css' => false,
		),
		'database'     => array(
			'cleanup_revisions' => false,
			'cleanup_spam'      => false,
			'cleanup_trash'     => false,
			'optimize_tables'   => false,
		),
		'advanced'     => array(
			'disable_emojis'       => false,
			'disable_embeds'       => false,
			'remove_query_strings' => false,
			'defer_js'             => false,
			'async_js'             => false,
		),
	);

	/**
	 * Constructor
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		$this->load();
	}

	/**
	 * Get a configuration value
	 *
	 * @since 1.1.0
	 * @param string $key     Configuration key (supports dot notation)
	 * @param mixed  $default Default value if key doesn't exist
	 * @return mixed Configuration value
	 */
	public function get( string $key, $default = null ) {
		return $this->get_nested_value( $this->config, $key, $default );
	}

	/**
	 * Set a configuration value
	 *
	 * @since 1.1.0
	 * @param string $key   Configuration key (supports dot notation)
	 * @param mixed  $value Configuration value
	 * @return void
	 */
	public function set( string $key, $value ): void {
		$this->set_nested_value( $this->config, $key, $value );
	}

	/**
	 * Check if a configuration key exists
	 *
	 * @since 1.1.0
	 * @param string $key Configuration key (supports dot notation)
	 * @return bool True if key exists, false otherwise
	 */
	public function has( string $key ): bool {
		return null !== $this->get_nested_value( $this->config, $key );
	}

	/**
	 * Remove a configuration key
	 *
	 * @since 1.1.0
	 * @param string $key Configuration key (supports dot notation)
	 * @return void
	 */
	public function remove( string $key ): void {
		$this->unset_nested_value( $this->config, $key );
	}

	/**
	 * Get all configuration values
	 *
	 * @since 1.1.0
	 * @return array All configuration values
	 */
	public function all(): array {
		return $this->config;
	}

	/**
	 * Save configuration to persistent storage
	 *
	 * @since 1.1.0
	 * @return bool True on success, false on failure
	 */
	public function save(): bool {
		return update_option( $this->option_name, $this->config );
	}

	/**
	 * Load configuration from persistent storage
	 *
	 * @since 1.1.0
	 * @return bool True on success, false on failure
	 */
	public function load(): bool {
		$saved_config = get_option( $this->option_name, array() );
		$this->config = $this->merge_with_defaults( $saved_config );
		return true;
	}

	/**
	 * Reset configuration to defaults
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public function reset(): void {
		$this->config = $this->defaults;
		$this->save();
	}

	/**
	 * Get default configuration values
	 *
	 * @since 1.1.0
	 * @return array Default configuration values
	 */
	public function get_defaults(): array {
		return $this->defaults;
	}

	/**
	 * Validate configuration values
	 *
	 * @since 1.1.0
	 * @param array $config Configuration to validate
	 * @return array Validated configuration
	 * @throws ConfigurationException If validation fails
	 */
	public function validate( array $config ): array {
		$validated = array();

		// Validate caching settings
		if ( isset( $config['caching'] ) ) {
			$validated['caching'] = $this->validate_caching_config( $config['caching'] );
		}

		// Validate minification settings
		if ( isset( $config['minification'] ) ) {
			$validated['minification'] = $this->validate_minification_config( $config['minification'] );
		}

		// Validate image settings
		if ( isset( $config['images'] ) ) {
			$validated['images'] = $this->validate_images_config( $config['images'] );
		}

		return array_merge( $this->defaults, $validated );
	}

	/**
	 * Merge saved configuration with defaults
	 *
	 * @since 1.1.0
	 * @param array $saved_config Saved configuration
	 * @return array Merged configuration
	 */
	private function merge_with_defaults( array $saved_config ): array {
		return array_replace_recursive( $this->defaults, $saved_config );
	}

	/**
	 * Get nested value using dot notation
	 *
	 * @since 1.1.0
	 * @param array  $array   Array to search
	 * @param string $key     Key in dot notation
	 * @param mixed  $default Default value
	 * @return mixed Found value or default
	 */
	private function get_nested_value( array $array, string $key, $default = null ) {
		$keys  = explode( '.', $key );
		$value = $array;

		foreach ( $keys as $k ) {
			if ( ! is_array( $value ) || ! array_key_exists( $k, $value ) ) {
				return $default;
			}
			$value = $value[ $k ];
		}

		return $value;
	}

	/**
	 * Set nested value using dot notation
	 *
	 * @since 1.1.0
	 * @param array  $array Array to modify
	 * @param string $key   Key in dot notation
	 * @param mixed  $value Value to set
	 * @return void
	 */
	private function set_nested_value( array &$array, string $key, $value ): void {
		$keys    = explode( '.', $key );
		$current = &$array;

		foreach ( $keys as $k ) {
			if ( ! isset( $current[ $k ] ) || ! is_array( $current[ $k ] ) ) {
				$current[ $k ] = array();
			}
			$current = &$current[ $k ];
		}

		$current = $value;
	}

	/**
	 * Unset nested value using dot notation
	 *
	 * @since 1.1.0
	 * @param array  $array Array to modify
	 * @param string $key   Key in dot notation
	 * @return void
	 */
	private function unset_nested_value( array &$array, string $key ): void {
		$keys     = explode( '.', $key );
		$last_key = array_pop( $keys );
		$current  = &$array;

		foreach ( $keys as $k ) {
			if ( ! isset( $current[ $k ] ) || ! is_array( $current[ $k ] ) ) {
				return;
			}
			$current = &$current[ $k ];
		}

		unset( $current[ $last_key ] );
	}

	/**
	 * Validate caching configuration
	 *
	 * @since 1.1.0
	 * @param array $config Caching configuration
	 * @return array Validated configuration
	 * @throws ConfigurationException If validation fails
	 */
	private function validate_caching_config( array $config ): array {
		$validated = array();

		// Validate boolean values
		$boolean_keys = array( 'page_cache_enabled', 'object_cache_enabled', 'fragment_cache_enabled' );
		foreach ( $boolean_keys as $key ) {
			if ( isset( $config[ $key ] ) ) {
				$validated[ $key ] = (bool) $config[ $key ];
			}
		}

		// Validate cache TTL
		if ( isset( $config['cache_ttl'] ) ) {
			$ttl = (int) $config['cache_ttl'];
			if ( $ttl < 60 || $ttl > 86400 ) {
				throw new ConfigurationException( 'Cache TTL must be between 60 and 86400 seconds.' );
			}
			$validated['cache_ttl'] = $ttl;
		}

		// Validate exclusions array
		if ( isset( $config['cache_exclusions'] ) ) {
			$validated['cache_exclusions'] = is_array( $config['cache_exclusions'] ) ? $config['cache_exclusions'] : array();
		}

		return $validated;
	}

	/**
	 * Validate minification configuration
	 *
	 * @since 1.1.0
	 * @param array $config Minification configuration
	 * @return array Validated configuration
	 */
	private function validate_minification_config( array $config ): array {
		$validated = array();

		// Validate boolean values
		$boolean_keys = array( 'minify_css', 'minify_js', 'minify_html', 'combine_css', 'combine_js', 'inline_critical_css' );
		foreach ( $boolean_keys as $key ) {
			if ( isset( $config[ $key ] ) ) {
				$validated[ $key ] = (bool) $config[ $key ];
			}
		}

		return $validated;
	}

	/**
	 * Validate images configuration
	 *
	 * @since 1.1.0
	 * @param array $config Images configuration
	 * @return array Validated configuration
	 * @throws ConfigurationException If validation fails
	 */
	private function validate_images_config( array $config ): array {
		$validated = array();

		// Validate boolean values
		$boolean_keys = array( 'convert_to_webp', 'convert_to_avif', 'lazy_loading', 'resize_large_images' );
		foreach ( $boolean_keys as $key ) {
			if ( isset( $config[ $key ] ) ) {
				$validated[ $key ] = (bool) $config[ $key ];
			}
		}

		// Validate compression quality
		if ( isset( $config['compression_quality'] ) ) {
			$quality = (int) $config['compression_quality'];
			if ( $quality < 1 || $quality > 100 ) {
				throw new ConfigurationException( 'Image compression quality must be between 1 and 100.' );
			}
			$validated['compression_quality'] = $quality;
		}

		// Validate max dimensions
		if ( isset( $config['max_image_width'] ) ) {
			$width = (int) $config['max_image_width'];
			if ( $width < 100 || $width > 5000 ) {
				throw new ConfigurationException( 'Max image width must be between 100 and 5000 pixels.' );
			}
			$validated['max_image_width'] = $width;
		}

		if ( isset( $config['max_image_height'] ) ) {
			$height = (int) $config['max_image_height'];
			if ( $height < 100 || $height > 5000 ) {
				throw new ConfigurationException( 'Max image height must be between 100 and 5000 pixels.' );
			}
			$validated['max_image_height'] = $height;
		}

		return $validated;
	}
}
