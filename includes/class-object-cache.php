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
			'enabled'        => false,
			'redis_missing'  => ! class_exists( 'Redis' ),
			'foreign_dropin' => false,
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

		if ( ! $status['foreign_dropin'] && $status['enabled'] && class_exists( 'Redis' ) ) {
			try {
				$redis = new \Redis();
				if ( file_exists( $this->config_path ) ) {
					$config   = include $this->config_path; // phpcs:ignore WPThemeReview.CoreFunctionality.FileInclude.FileIncludeFound
					$host     = isset( $config['host'] ) ? $config['host'] : '127.0.0.1';
					$port     = isset( $config['port'] ) ? (int) $config['port'] : 6379;
					$password = isset( $config['password'] ) ? $config['password'] : '';

					if ( $redis->connect( $host, $port, 1.0 ) ) {
						if ( ! empty( $password ) ) {
							$redis->auth( $password );
						}
						$info = $redis->info();
						if ( $info ) {
							$status['telemetry'] = array(
								'uptime_in_days'         => isset( $info['uptime_in_days'] ) ? $info['uptime_in_days'] : 0,
								'connected_clients'      => isset( $info['connected_clients'] ) ? $info['connected_clients'] : 0,
								'used_memory_human'      => isset( $info['used_memory_human'] ) ? $info['used_memory_human'] : '0B',
								'used_memory_peak_human' => isset( $info['used_memory_peak_human'] ) ? $info['used_memory_peak_human'] : '0B',
								'total_connections_received' => isset( $info['total_connections_received'] ) ? $info['total_connections_received'] : 0,
								'keyspace_hits'          => isset( $info['keyspace_hits'] ) ? $info['keyspace_hits'] : 0,
								'keyspace_misses'        => isset( $info['keyspace_misses'] ) ? $info['keyspace_misses'] : 0,
							);
						}
						$redis->close();
					}
				}
			} catch ( \Exception $e ) {
				$status['telemetry_error'] = $e->getMessage();
			}
		}

		return $status;
	}

	/**
	 * Ping the Redis server to test connection.
	 *
	 * @param string $host Redis host.
	 * @param int    $port Redis port.
	 * @param string $password Redis password (optional).
	 * @param int    $database Redis Database ID.
	 * @return bool|\WP_Error True if connected, WP_Error on failure.
	 */
	public function ping( $host = '127.0.0.1', $port = 6379, $password = '', $database = 0 ) {
		if ( ! class_exists( 'Redis' ) ) {
			return new \WP_Error( 'missing_extension', 'The PhpRedis extension is not installed on this server.' );
		}

		try {
			$redis = new \Redis();
			if ( $redis->connect( $host, $port, 2.0 ) ) {
				if ( ! empty( $password ) ) {
					if ( ! $redis->auth( $password ) ) {
						$redis->close();
						return new \WP_Error( 'redis_auth_failed', 'Redis authentication failed.' );
					}
				}
				if ( ! $redis->select( $database ) ) {
					$redis->close();
					return new \WP_Error( 'redis_select_failed', 'Redis select DB failed.' );
				}
				$redis->close();
				return true;
			}
		} catch ( \Exception $e ) {
			return new \WP_Error( 'redis_error', $e->getMessage() );
		}

		return new \WP_Error( 'connection_failed', 'Could not connect to Redis server.' );
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

		// Write config file safely without var_export.
		$host_str = addslashes( (string) $config['host'] );
		$port_int = (int) $config['port'];
		$pass_str = addslashes( (string) $config['password'] );
		$db_int   = (int) $config['database'];

		$config_content  = "<?php\n// Auto-generated by Performance Optimisation\n";
		$config_content .= "return array(\n";
		$config_content .= "\t'host'     => '{$host_str}',\n";
		$config_content .= "\t'port'     => {$port_int},\n";
		$config_content .= "\t'password' => '{$pass_str}',\n";
		$config_content .= "\t'database' => {$db_int},\n";
		$config_content .= ");\n";

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
