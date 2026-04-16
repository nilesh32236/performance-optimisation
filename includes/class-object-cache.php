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
				// Determine connection settings (Config file has priority over DB settings).
				$config = array();
				if ( file_exists( $this->config_path ) ) {
					$config = include $this->config_path; // phpcs:ignore WPThemeReview.CoreFunctionality.FileInclude.FileIncludeFound
				} else {
					$options  = get_option( 'wppo_settings', array() );
					$config = isset( $options['object_cache'] ) ? $options['object_cache'] : array();
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
								'uptime_in_days'             => isset( $info['uptime_in_days'] ) ? $info['uptime_in_days'] : 0,
								'connected_clients'          => isset( $info['connected_clients'] ) ? $info['connected_clients'] : 0,
								'used_memory_human'          => isset( $info['used_memory_human'] ) ? $info['used_memory_human'] : '0B',
								'used_memory_peak_human'     => isset( $info['used_memory_peak_human'] ) ? $info['used_memory_peak_human'] : '0B',
								'total_connections_received' => isset( $info['total_connections_received'] ) ? $info['total_connections_received'] : 0,
								'keyspace_hits'              => isset( $info['keyspace_hits'] ) ? $info['keyspace_hits'] : 0,
								'keyspace_misses'            => isset( $info['keyspace_misses'] ) ? $info['keyspace_misses'] : 0,
								'keys'                       => isset( $info['db0'] ) ? (int) preg_replace( '/.*keys=([0-9]+).*/', '$1', $info['db0'] ) : 0,
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
		$mode     = isset( $config['mode'] ) ? $config['mode'] : 'standalone';
		$password = isset( $config['password'] ) ? $config['password'] : '';
		$database = isset( $config['database'] ) ? (int) $config['database'] : 0;
		$use_tls  = isset( $config['use_tls'] ) ? (bool) $config['use_tls'] : false;
		$timeout  = 0.5;

		try {
			if ( 'cluster' === $mode ) {
				if ( ! class_exists( 'RedisCluster' ) ) {
					return new \WP_Error( 'missing_cluster', 'RedisCluster class not found.' );
				}
				$nodes = isset( $config['nodes'] ) ? $config['nodes'] : array();
				if ( is_string( $nodes ) ) {
					$nodes = array_filter( array_map( 'trim', explode( "\n", $nodes ) ) );
				}
				if ( empty( $nodes ) ) {
					return new \WP_Error( 'low_nodes', 'No nodes provided for Cluster.' );
				}

				if ( $use_tls ) {
					$nodes = array_map( function( $node ) {
						return ( strpos( $node, 'tls://' ) === 0 ) ? $node : 'tls://' . $node;
					}, $nodes );
				}

				try {
					return new \RedisCluster( null, $nodes, $timeout, $timeout, true, $password );
				} catch ( \Exception $e ) {
					return new \WP_Error( 'cluster_fail', 'Redis Cluster connection failed: ' . $e->getMessage() );
				}
			}

			if ( 'sentinel' === $mode ) {
				if ( ! class_exists( 'RedisSentinel' ) ) {
					return new \WP_Error( 'missing_sentinel', 'RedisSentinel class not found.' );
				}
				$nodes       = isset( $config['nodes'] ) ? $config['nodes'] : array();
				$master_name = isset( $config['master_name'] ) ? $config['master_name'] : 'mymaster';

				if ( is_string( $nodes ) ) {
					$nodes = array_filter( array_map( 'trim', explode( "\n", $nodes ) ) );
				}

				$errors = array();
				foreach ( $nodes as $node ) {
					list( $s_host, $s_port ) = array_pad( explode( ':', $node ), 2, 26379 );
					try {
						$sentinel = new \RedisSentinel( array(
							'host' => $s_host,
							'port' => (int) $s_port,
						) );
						$address = $sentinel->getMasterAddrByName( $master_name );
						if ( $address ) {
							$redis = new \Redis();
							$host  = $use_tls ? 'tls://' . $address[0] : $address[0];
							if ( $redis->connect( $host, (int) $address[1], $timeout ) ) {
								if ( $password && ! $redis->auth( $password ) ) {
									return new \WP_Error( 'auth_fail', 'Sentinel Master Auth failed.' );
								}
								$redis->select( $database );
								return $redis;
							}
						}
					} catch ( \Exception $e ) {
						$errors[] = $s_host . ':' . $s_port . ' - ' . $e->getMessage();
						continue;
					}
				}
				return new \WP_Error( 'sentinel_fail', 'Could not resolve master via Sentinals. Last error: ' . end( $errors ) );
			}

			// Standalone
			$host = isset( $config['host'] ) ? $config['host'] : '127.0.0.1';
			$port = isset( $config['port'] ) ? (int) $config['port'] : 6379;
			if ( $use_tls && strpos( $host, 'tls://' ) !== 0 ) {
				$host = 'tls://' . $host;
			}

			$redis = new \Redis();
			$func  = ! empty( $config['persistent'] ) ? 'pconnect' : 'connect';
			if ( @$redis->$func( $host, $port, $timeout ) ) {
				$redis->select( $database );

				// Apply performance options
				$serializer = defined( '\Redis::SERIALIZER_IGBINARY' ) ? \Redis::SERIALIZER_IGBINARY : \Redis::SERIALIZER_PHP;
				$redis->setOption( \Redis::OPT_SERIALIZER, $serializer );

				if ( isset( $config['compression'] ) && 'none' !== $config['compression'] ) {
					$compression_type = 'none';
					if ( 'lzf' === $config['compression'] && defined( '\Redis::COMPRESSION_LZF' ) ) {
						$compression_type = \Redis::COMPRESSION_LZF;
					} elseif ( 'zstd' === $config['compression'] && defined( '\Redis::COMPRESSION_ZSTD' ) ) {
						$compression_type = \Redis::COMPRESSION_ZSTD;
					} elseif ( 'lz4' === $config['compression'] && defined( '\Redis::COMPRESSION_LZ4' ) ) {
						$compression_type = \Redis::COMPRESSION_LZ4;
					}

					if ( 'none' !== $compression_type ) {
						$redis->setOption( \Redis::OPT_COMPRESSION, $compression_type );
					}
				}

				return $redis;
			}
		} catch ( \Exception $e ) {
			return new \WP_Error( 'redis_err', $e->getMessage() );
		}

		return new \WP_Error( 'conn_fail', sprintf( 'Could not connect to Redis at %s:%s. Please ensure the service is running.', $host, $port ) );

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
				if ( $connection->ping() ) {
					$connection->close();
					return true;
				}
			} catch ( \Exception $e ) {
				return new \WP_Error( 'ping_fail', $e->getMessage() );
			}
		}

		$connection->close();
		return true;
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

		// Prepare data for config file.
		$config_data = array(
			'mode'        => isset( $config['mode'] ) ? sanitize_text_field( $config['mode'] ) : 'standalone',
			'host'        => isset( $config['host'] ) ? sanitize_text_field( $config['host'] ) : '127.0.0.1',
			'port'        => isset( $config['port'] ) ? (int) $config['port'] : 6379,
			'password'    => isset( $config['password'] ) ? (string) $config['password'] : '',
			'database'    => isset( $config['database'] ) ? (int) $config['database'] : 0,
			'nodes'       => isset( $config['nodes'] ) ? $config['nodes'] : '',
			'master_name' => isset( $config['master_name'] ) ? sanitize_text_field( $config['master_name'] ) : 'mymaster',
			'use_tls'     => isset( $config['use_tls'] ) ? (bool) $config['use_tls'] : false,
			'persistent'  => isset( $config['persistent'] ) ? (bool) $config['persistent'] : false,
			'compression' => isset( $config['compression'] ) ? sanitize_text_field( $config['compression'] ) : 'none',
		);

		// Format nodes as array for the config file.
		if ( ! empty( $config_data['nodes'] ) && is_string( $config_data['nodes'] ) ) {
			$config_data['nodes'] = array_filter( array_map( 'trim', explode( "\n", $config_data['nodes'] ) ) );
		}

		// Write config file using var_export for clean array representation.
		$config_content  = "<?php\n// Auto-generated by Performance Optimisation\n";
		$config_content .= "return " . var_export( $config_data, true ) . ";\n";

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
