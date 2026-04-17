<?php
/**
 * Object Cache Manager.
 *
 * Handles Redis drop-in installation, removal, and testing.
 *
 * @package PerformanceOptimise
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Object_Cache
 *
 * @since 1.3.0
 */
class Object_Cache {

	/**
	 * Path to the object cache drop-in.
	 *
	 * @var string
	 */
	private $dropin_path;

	/**
	 * Path to the config file.
	 *
	 * @var string
	 */
	private $config_path;

	/**
	 * Path to the template file.
	 *
	 * @var string
	 */
	private $template_path;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->dropin_path   = apply_filters( 'wppo_object_cache_dropin_path', WP_CONTENT_DIR . '/object-cache.php' );
		$this->config_path   = WP_CONTENT_DIR . '/wppo-redis-config.php';
		$this->template_path = WPPO_PLUGIN_PATH . 'templates/object-cache.php';
	}

	/**
	 * Get the status of the object cache.
	 *
	 * @return array Status information.
	 */
	public function get_status() {
		$status = array(
			'enabled'         => false,
			'redis_missing'   => ! class_exists( 'Redis' ),
			'redis_reachable' => false,
			'foreign_dropin'  => false,
		);

		if ( file_exists( $this->dropin_path ) ) {
			$wp_filesystem = Util::init_filesystem();

			if ( $wp_filesystem ) {
				$content = $wp_filesystem->get_contents( $this->dropin_path );
				if ( false !== strpos( $content, 'Redis Object Cache Drop-in for Performance Optimisation' ) ) {
					$status['enabled'] = true;
				} else {
					$status['foreign_dropin'] = true;
				}
			}
		}

		if ( ! $status['redis_missing'] ) {
			try {
				// Determine connection settings (Dashboard settings have priority over on-disk config).
				$options = get_option( 'wppo_settings', array() );
				$config  = $options['object_cache'] ?? array();

				if ( empty( $config ) && file_exists( $this->config_path ) ) {
					$config = include $this->config_path; // phpcs:ignore WPThemeReview.CoreFunctionality.FileInclude.FileIncludeFound
				}

				$connection = $this->connect_internal( $config );

				if ( ! is_wp_error( $connection ) ) {
					$status['redis_reachable'] = true;
					$redis                     = $connection;

					// Collect telemetry if enabled and reachable.
					if ( $status['enabled'] ) {
						$info = $redis->info();
						if ( $info ) {
							$status['telemetry'] = array(
								'uptime_in_days'         => $info['uptime_in_days'] ?? 0,
								'connected_clients'      => $info['connected_clients'] ?? 0,
								'used_memory_human'      => $info['used_memory_human'] ?? '0B',
								'used_memory_peak_human' => $info['used_memory_peak_human'] ?? '0B',
								'total_connections_received' => $info['total_connections_received'] ?? 0,
								'keyspace_hits'          => $info['keyspace_hits'] ?? 0,
								'keyspace_misses'        => $info['keyspace_misses'] ?? 0,
								'keys'                   => ( isset( $info['db0'] ) && preg_match( '/keys=([0-9]+)/', $info['db0'], $matches ) ) ? (int) $matches[1] : 0,
							);
						}
					}
					$redis->close();
				} else {
					$status['telemetry_error'] = $connection->get_error_message();
				}
			} catch ( \Exception $e ) {
				$status['redis_reachable'] = false;
				$status['telemetry_error'] = $e->getMessage();
			}
		}

		return $status;
	}

	/**
	 * Internal helper to connect to Redis based on config.
	 *
	 * @param array $config Configuration array.
	 * @return \Redis|\RedisCluster|\WP_Error
	 */
	private function connect_internal( $config ) {
		require_once WPPO_PLUGIN_PATH . 'includes/redis-connect-helper.php';
		return wppo_redis_connect( $config );
	}

	/**
	 * Ping the Redis server to test connection.
	 *
	 * @param array $config Connection configuration.
	 * @return bool|\WP_Error True if connected, WP_Error on failure.
	 */
	public function ping( $config = array() ) {
		if ( ! class_exists( 'Redis' ) ) {
			return new \WP_Error( 'missing_extension', 'The PhpRedis extension is not installed.' );
		}

		$connection = $this->connect_internal( $config );

		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		if ( method_exists( $connection, 'ping' ) ) {
			try {
				$result = $connection->ping();
				$connection->close();

				if ( true === $result || '+PONG' === $result || ( is_string( $result ) && stripos( $result, 'PONG' ) !== false ) ) {
					return true;
				}
				return new \WP_Error( 'ping_fail', 'Ping returned false' );
			} catch ( \Exception $e ) {
				if ( method_exists( $connection, 'close' ) ) {
					$connection->close();
				}
				return new \WP_Error( 'ping_exception', $e->getMessage() );
			}
		}

		$connection->close();
		return new \WP_Error( 'no_ping_method', 'Connection does not support ping' );
	}


	/**
	 * Enable the Object Cache by writing the config and copying the drop-in.
	 *
	 * @param array $config Connection configuration.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function enable( $config ) {
		if ( ! class_exists( 'Redis' ) ) {
			return new \WP_Error( 'missing_extension', 'The PhpRedis extension is not installed.' );
		}

		$status = $this->get_status();
		if ( $status['foreign_dropin'] ) {
			return new \WP_Error( 'foreign_dropin', 'Another Object Cache drop-in is already present. Please disable it before enabling this one.' );
		}

		// Format nodes as array for the config file if it's a string.
		if ( ! empty( $config['nodes'] ) && is_string( $config['nodes'] ) ) {
			$config['nodes'] = array_filter( array_map( 'trim', explode( "\n", $config['nodes'] ) ) );
		}

		$config_data = $config;

		// Write config file using var_export for clean array representation.
		$config_content = "<?php\n// Auto-generated by Performance Optimisation\n";
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		$config_content .= 'return ' . var_export( $config_data, true ) . ";\n";

		$wp_filesystem = Util::init_filesystem();

		if ( ! $wp_filesystem->put_contents( $this->config_path, $config_content, FS_CHMOD_FILE ) ) {
			return new \WP_Error( 'write_error', 'Cannot write configuration file to wp-content.' );
		}

		// Copy drop-in.
		if ( ! $wp_filesystem->copy( $this->template_path, $this->dropin_path, true, FS_CHMOD_FILE ) ) {
			$wp_filesystem->delete( $this->config_path );
			return new \WP_Error( 'write_error', 'Cannot copy object-cache.php drop-in to wp-content.' );
		}

		// Optionally, ping cache flush if enabled just to clear old cruft.
		wp_cache_flush();

		return true;
	}


	/**
	 * Disable the Object Cache by removing the drop-in and config.
	 *
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function disable() {
		$status = $this->get_status();
		if ( $status['foreign_dropin'] ) {
			return new \WP_Error( 'foreign_dropin', 'A foreign drop-in exists. We will not delete it for safety.' );
		}

		$wp_filesystem = Util::init_filesystem();

		if ( file_exists( $this->dropin_path ) ) {
			if ( ! $wp_filesystem->delete( $this->dropin_path ) ) {
				return new \WP_Error( 'delete_error', 'Cannot delete object-cache.php from wp-content.' );
			}
		}

		if ( file_exists( $this->config_path ) ) {
			$wp_filesystem->delete( $this->config_path );
		}

		return true;
	}

	/**
	 * Flush the complete object cache.
	 *
	 * @return bool
	 */
	public function flush() {
		if ( function_exists( 'wp_cache_flush' ) ) {
			return wp_cache_flush();
		}
		return false;
	}
}
