<?php
/**
 * Configuration Service
 *
 * Centralized configuration management with validation and environment support.
 *
 * @package PerformanceOptimisation\Services
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Services;

use PerformanceOptimisation\Interfaces\ServiceContainerInterface;
use PerformanceOptimisation\Core\Config\ConfigManager;
use PerformanceOptimisation\Core\Config\ConfigSchema;
use PerformanceOptimisation\Core\Config\ConfigEnvironment;
use PerformanceOptimisation\Utils\LoggingUtil;
use PerformanceOptimisation\Utils\ValidationUtil;
use PerformanceOptimisation\Utils\FileSystemUtil;
use PerformanceOptimisation\Exceptions\ConfigurationException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configuration Service Class
 */
class ConfigurationService {

	/**
	 * Service container.
	 *
	 * @var ServiceContainerInterface
	 */
	private ServiceContainerInterface $container;

	/**
	 * Configuration manager.
	 *
	 * @var ConfigManager
	 */
	private ConfigManager $config_manager;

	/**
	 * Logger instance.
	 *
	 * @var LoggingUtil
	 */
	private LoggingUtil $logger;

	/**
	 * Validator instance.
	 *
	 * @var ValidationUtil
	 */
	private ValidationUtil $validator;

	/**
	 * FileSystem instance.
	 *
	 * @var FileSystemUtil
	 */
	private FileSystemUtil $filesystem;

	/**
	 * Configuration schema.
	 *
	 * @var ConfigSchema
	 */
	private ConfigSchema $config_schema;

	/**
	 * Configuration environment.
	 *
	 * @var ConfigEnvironment
	 */
	private ConfigEnvironment $config_environment;

	/**
	 * Configuration cache.
	 *
	 * @var array
	 */
	private array $config_cache = array();

	/**
	 * Constructor.
	 *
	 * @param ServiceContainerInterface $container Service container.
	 */
	public function __construct( ServiceContainerInterface $container ) {
		$this->container = $container;

		// Get services if available, otherwise use defaults.
		try {
			$this->logger = $container->get( 'logger' );
		} catch ( \Exception $e ) {
			$this->logger = new LoggingUtil();
		}

		try {
			$this->validator = $container->get( 'validator' );
		} catch ( \Exception $e ) {
			$this->validator = new ValidationUtil();
		}

		try {
			$this->filesystem = $container->get( 'filesystem' );
		} catch ( \Exception $e ) {
			$this->filesystem = new FileSystemUtil();
		}

		// Initialize config components.
		$this->config_schema      = new ConfigSchema();
		$this->config_environment = new ConfigEnvironment();
		$this->config_manager     = new ConfigManager( $this->logger, $this->validator, $this->filesystem );

		$this->logger->debug(
			'ConfigurationService initialized',
			array(
				'environment' => $this->config_environment->getEnvironment(),
				'schema_keys' => array_keys( $this->config_schema->getSchema() ),
			)
		);
	}

	/**
	 * Get configuration value.
	 *
	 * @param string $key     Configuration key (dot notation supported).
	 * @param mixed  $default Default value if key not found.
	 * @return mixed Configuration value.
	 */
	public function get( string $key, $default = null ) {
		// Check cache first.
		if ( isset( $this->config_cache[ $key ] ) ) {
			return $this->config_cache[ $key ];
		}

		// Get from config manager.
		$value = $this->config_manager->get( $key, $default );

		// Apply environment-specific overrides.
		$env_overrides = $this->config_environment->getEnvironmentOverrides();
		if ( isset( $env_overrides[ $key ] ) ) {
			$value = $env_overrides[ $key ];
		}

		// Cache the result.
		$this->config_cache[ $key ] = $value;

		return $value;
	}

	/**
	 * Set configuration value with validation.
	 *
	 * @param string $key   Configuration key (dot notation supported).
	 * @param mixed  $value Configuration value.
	 * @return bool True on success, false on failure.
	 * @throws ConfigurationException If validation fails.
	 */
	public function set( string $key, $value ): bool {
		try {
			// Validate the value against schema.
			$this->validate_value( $key, $value );

			// Set in config manager.
			$this->config_manager->set( $key, $value );

			// Update cache.
			$this->config_cache[ $key ] = $value;

			$this->logger->info(
				'Configuration value set',
				array(
					'key'  => $key,
					'type' => gettype( $value ),
				)
			);

			return true;

		} catch ( ConfigurationException $e ) {
			$this->logger->error(
				'Configuration validation failed',
				array(
					'key'   => $key,
					'error' => $e->getMessage(),
				)
			);
			throw $e;
		}
	}

	/**
	 * Update multiple configuration values.
	 *
	 * @param array $config Configuration array.
	 * @return bool True on success, false on failure.
	 * @throws ConfigurationException If validation fails.
	 */
	public function update( array $config ): bool {
		try {
			// Validate entire configuration.
			$validation_result = $this->validate_configuration( $config );
			if ( ! $validation_result['valid'] ) {
				throw new ConfigurationException(
					'Configuration validation failed: ' . implode( ', ', $validation_result['errors'] )
				);
			}

			// Update each value.
			foreach ( $config as $section => $values ) {
				if ( is_array( $values ) ) {
					foreach ( $values as $key => $value ) {
						$full_key = $section . '.' . $key;
						$this->config_manager->set( $full_key, $value );
						$this->config_cache[ $full_key ] = $value;
					}
				} else {
					$this->config_manager->set( $section, $values );
					$this->config_cache[ $section ] = $values;
				}
			}

			// Save to persistent storage.
			$result = $this->config_manager->save();

			if ( $result ) {
				$this->logger->info(
					'Configuration updated successfully',
					array(
						'sections'   => array_keys( $config ),
						'total_keys' => $this->count_config_keys( $config ),
					)
				);
			}

			return $result;

		} catch ( ConfigurationException $e ) {
			$this->logger->error(
				'Configuration update failed',
				array(
					'error' => $e->getMessage(),
				)
			);
			throw $e;
		}
	}

	/**
	 * Get all configuration values.
	 *
	 * @return array All configuration values.
	 */
	public function all(): array {
		$config = $this->config_manager->all();

		// Apply environment overrides.
		foreach ( $this->config_environment->getEnvironmentOverrides() as $key => $value ) {
			$this->set_nested_value( $config, $key, $value );
		}

		return $config;
	}

	/**
	 * Check if configuration key exists.
	 *
	 * @param string $key Configuration key.
	 * @return bool True if key exists, false otherwise.
	 */
	public function has( string $key ): bool {
		$env_overrides = $this->config_environment->getEnvironmentOverrides();
		return $this->config_manager->has( $key ) || isset( $env_overrides[ $key ] );
	}

	/**
	 * Remove configuration key.
	 *
	 * @param string $key Configuration key.
	 * @return bool True on success, false on failure.
	 */
	public function remove( string $key ): bool {
		$this->config_manager->remove( $key );
		unset( $this->config_cache[ $key ] );

		$this->logger->debug( 'Configuration key removed', array( 'key' => $key ) );

		return $this->config_manager->save();
	}

	/**
	 * Reset configuration to defaults.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function reset(): bool {
		$this->config_manager->reset();
		$this->config_cache = array();

		$this->logger->info( 'Configuration reset to defaults' );

		return true;
	}

	/**
	 * Save configuration to persistent storage.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function save(): bool {
		return $this->config_manager->save();
	}

	/**
	 * Reload configuration from storage.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function reload(): bool {
		$this->config_cache = array();
		return $this->config_manager->reload();
	}

	/**
	 * Get configuration schema.
	 *
	 * @return array Configuration schema.
	 */
	public function get_schema(): array {
		return $this->config_schema->getSchema();
	}

	/**
	 * Validate configuration against schema.
	 *
	 * @param array $config Configuration to validate.
	 * @return array Validation result with 'valid' boolean and 'errors' array.
	 */
	public function validate_configuration( array $config ): array {
		$errors = array();
		$schema = $this->config_schema->getSchema();

		foreach ( $config as $section => $values ) {
			if ( ! isset( $schema[ $section ] ) ) {
				$errors[] = "Unknown configuration section: {$section}";
				continue;
			}

			if ( is_array( $values ) ) {
				foreach ( $values as $key => $value ) {
					$full_key = $section . '.' . $key;
					try {
						$this->validate_value( $full_key, $value );
					} catch ( ConfigurationException $e ) {
						$errors[] = $e->getMessage();
					}
				}
			}
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}

	/**
	 * Get current environment.
	 *
	 * @return string Current environment.
	 */
	public function get_environment(): string {
		return $this->config_environment->getEnvironment();
	}

	/**
	 * Set environment.
	 *
	 * @param string $environment Environment name.
	 * @return bool True on success, false on failure.
	 */
	public function set_environment( string $environment ): bool {
		if ( $this->config_environment->setEnvironment( $environment ) ) {
			$this->config_cache = array(); // Clear cache to reload with new environment.
			return true;
		}
		return false;
	}

	/**
	 * Export configuration to file.
	 *
	 * @param string $file_path File path to export to.
	 * @param string $format    Export format (json, php).
	 * @return bool True on success, false on failure.
	 * @throws ConfigurationException If export format is unsupported.
	 */
	public function export( string $file_path, string $format = 'json' ): bool {
		try {
			$config = $this->all();

			switch ( $format ) {
				case 'json':
					$content = wp_json_encode( $config, JSON_PRETTY_PRINT );
					break;
				case 'php':
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
					$content = "<?php\nreturn " . var_export( $config, true ) . ";\n";
					break;
				default:
					throw new ConfigurationException( "Unsupported export format: {$format}" );
			}

			$result = $this->filesystem->writeFile( $file_path, $content );

			if ( $result ) {
				$this->logger->info(
					'Configuration exported',
					array(
						'file'   => $file_path,
						'format' => $format,
					)
				);
			}

			return $result;

		} catch ( \Exception $e ) {
			$this->logger->error(
				'Configuration export failed',
				array(
					'file'  => $file_path,
					'error' => $e->getMessage(),
				)
			);
			return false;
		}
	}

	/**
	 * Import configuration from file.
	 *
	 * @param string $file_path File path to import from.
	 * @param bool   $merge     Whether to merge with existing config.
	 * @return bool True on success, false on failure.
	 * @throws ConfigurationException If file not found, invalid format, or validation fails.
	 */
	public function import( string $file_path, bool $merge = true ): bool {
		try {
			if ( ! $this->filesystem->fileExists( $file_path ) ) {
				throw new ConfigurationException( "Configuration file not found: {$file_path}" );
			}

			$content   = $this->filesystem->readFile( $file_path );
			$extension = pathinfo( $file_path, PATHINFO_EXTENSION );

			switch ( $extension ) {
				case 'json':
					$imported_config = json_decode( $content, true );
					if ( json_last_error() !== JSON_ERROR_NONE ) {
						throw new ConfigurationException( 'Invalid JSON format' );
					}
					break;
				case 'php':
					$imported_config = include $file_path;
					if ( ! is_array( $imported_config ) ) {
						throw new ConfigurationException( 'PHP file must return an array' );
					}
					break;
				default:
					throw new ConfigurationException( "Unsupported import format: {$extension}" );
			}

			// Validate imported configuration.
			$validation_result = $this->validate_configuration( $imported_config );
			if ( ! $validation_result['valid'] ) {
				throw new ConfigurationException(
					'Imported configuration is invalid: ' . implode( ', ', $validation_result['errors'] )
				);
			}

			if ( $merge ) {
				$current_config  = $this->all();
				$imported_config = array_replace_recursive( $current_config, $imported_config );
			}

			$result = $this->update( $imported_config );

			if ( $result ) {
				$this->logger->info(
					'Configuration imported',
					array(
						'file'  => $file_path,
						'merge' => $merge,
					)
				);
			}

			return $result;

		} catch ( \Exception $e ) {
			$this->logger->error(
				'Configuration import failed',
				array(
					'file'  => $file_path,
					'error' => $e->getMessage(),
				)
			);
			return false;
		}
	}

	/**
	 * Validate a single configuration value.
	 *
	 * @param string $key   Configuration key.
	 * @param mixed  $value Configuration value.
	 * @throws ConfigurationException If validation fails.
	 */
	private function validate_value( string $key, $value ): void {
		$schema_key = $this->get_schema_key( $key );
		if ( ! $schema_key ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new ConfigurationException( "Unknown configuration key: {$key}" );
		}

		$schema = $schema_key['schema'];

		// Type validation.
		switch ( $schema['type'] ) {
			case 'boolean':
				if ( ! is_bool( $value ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					throw new ConfigurationException( "Configuration key '{$key}' must be a boolean" );
				}
				break;
			case 'integer':
				if ( ! is_int( $value ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					throw new ConfigurationException( "Configuration key '{$key}' must be an integer" );
				}
				if ( isset( $schema['min'] ) && $value < $schema['min'] ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					throw new ConfigurationException( "Configuration key '{$key}' must be at least {$schema['min']}" );
				}
				if ( isset( $schema['max'] ) && $value > $schema['max'] ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					throw new ConfigurationException( "Configuration key '{$key}' must be at most {$schema['max']}" );
				}
				break;
			case 'string':
				if ( ! is_string( $value ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					throw new ConfigurationException( "Configuration key '{$key}' must be a string" );
				}
				break;
			case 'array':
				if ( ! is_array( $value ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					throw new ConfigurationException( "Configuration key '{$key}' must be an array" );
				}
				break;
		}
	}

	/**
	 * Get schema for a configuration key.
	 *
	 * @param string $key Configuration key.
	 * @return array|null Schema information or null if not found.
	 */
	private function get_schema_key( string $key ): ?array {
		$parts = explode( '.', $key );
		if ( count( $parts ) !== 2 ) {
			return null;
		}

		$section = $parts[0];
		$field   = $parts[1];

		$schema = $this->config_schema->getSchema();

		if ( ! isset( $schema[ $section ][ $field ] ) ) {
			return null;
		}

		return array(
			'section' => $section,
			'field'   => $field,
			'schema'  => $schema[ $section ][ $field ],
		);
	}

	/**
	 * Set nested value in array using dot notation.
	 *
	 * @param array  $data  Array to modify.
	 * @param string $key   Dot notation key.
	 * @param mixed  $value Value to set.
	 */
	private function set_nested_value( array &$data, string $key, $value ): void {
		$keys    = explode( '.', $key );
		$current = &$data;

		foreach ( $keys as $k ) {
			if ( ! isset( $current[ $k ] ) || ! is_array( $current[ $k ] ) ) {
				$current[ $k ] = array();
			}
			$current = &$current[ $k ];
		}

		$current = $value;
	}

	/**
	 * Count configuration keys recursively.
	 *
	 * @param array $config Configuration array.
	 * @return int Number of keys.
	 */
	private function count_config_keys( array $config ): int {
		$count = 0;
		foreach ( $config as $value ) {
			if ( is_array( $value ) ) {
				$count += $this->count_config_keys( $value );
			} else {
				++$count;
			}
		}
		return $count;
	}
}
