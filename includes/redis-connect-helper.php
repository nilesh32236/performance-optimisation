<?php
/**
 * Redis Connection Helper.
 *
 * Provides a shared connection logic for both the admin dashboard and the object cache drop-in.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Establishes a Redis connection according to the provided configuration.
 *
 * @param array $config {
 *     Redis configuration.
 *
 *     @type string          $mode        Connection mode: 'standalone', 'sentinel', or 'cluster'.
 *     @type string          $host        Redis host for standalone mode.
 *     @type int             $port        Redis port for standalone mode.
 *     @type string          $password    Password for AUTH, if required.
 *     @type int             $database    Database index to select (default 0).
 *     @type array|string    $nodes       Nodes for cluster/sentinel. May be an array or a delimiter-separated string (commas, semicolons, or whitespace). Entries may include host:port; IPv6 with brackets is supported.
 *     @type string          $master_name Master name for sentinel mode (default 'mymaster').
 *     @type bool            $use_tls     Whether to use TLS for connections.
 *     @type bool            $persistent  Use persistent connections for standalone/cluster.
 *     @type string          $compression Compression algorithm to configure (one of: 'none', 'lzf', 'zstd', 'lz4').
 * }
 * @since 1.4.0
 * @return \Redis|\RedisCluster|\WP_Error A connected Redis client (`Redis` or `RedisCluster`) on success, or a `WP_Error` describing the failure. */
if ( ! function_exists( 'wppo_redis_connect' ) ) {
	/**
	 * Establishes a Redis connection according to the provided configuration.
	 *
	 * @param array $config {
	 *     Redis configuration.
	 *
	 *     @type string          $mode        Connection mode: 'standalone', 'sentinel', or 'cluster'.
	 *     @type string          $host        Redis host for standalone mode.
	 *     @type int             $port        Redis port for standalone mode.
	 *     @type string          $password    Password for AUTH, if required.
	 *     @type int             $database    Database index to select (default 0).
	 *     @type array|string    $nodes       Nodes for cluster/sentinel. May be an array or a delimiter-separated string (commas, semicolons, or whitespace). Entries may include host:port; IPv6 with brackets is supported.
	 *     @type string          $master_name Master name for sentinel mode (default 'mymaster').
	 *     @type bool            $use_tls     Whether to use TLS for connections.
	 *     @type bool            $persistent  Use persistent connections for standalone/cluster.
	 *     @type string          $compression Compression algorithm to configure (one of: 'none', 'lzf', 'zstd', 'lz4').
	 * }
	 * @since 1.4.0
	 * @return \Redis|\RedisCluster|\WP_Error A connected Redis client (`Redis` or `RedisCluster`) on success, or a `WP_Error` describing the failure. */
	function wppo_redis_connect( $config ) {
		$mode = $config['mode'] ?? 'standalone';

		// Use config password if provided; fall back to WPPO_REDIS_PASSWORD constant,
		// then WPPO_REDIS_PASSWORD environment variable.
		if ( empty( $config['password'] ) ) {
			$env_password = getenv( 'WPPO_REDIS_PASSWORD' );
			if ( defined( 'WPPO_REDIS_PASSWORD' ) ) {
				$config['password'] = WPPO_REDIS_PASSWORD;
			} elseif ( false !== $env_password ) {
				$config['password'] = $env_password;
			}
		}

		try {
			if ( 'cluster' === $mode ) {
				return wppo_redis_connect_cluster( $config );
			}

			if ( 'sentinel' === $mode ) {
				return wppo_redis_connect_sentinel( $config );
			}

			return wppo_redis_connect_standalone( $config );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'WPPO Redis connection error: ' . $e->getMessage() );
			return new \WP_Error( 'redis_err', __( 'Redis connection failed.', 'performance-optimisation' ) );
		}
	}
}

if ( ! function_exists( 'wppo_redis_connect_cluster' ) ) {
	/**
	 * Establishes a Redis Cluster connection.
	 *
	 * @param array $config Redis configuration.
	 * @since NEXT
	 * @return \RedisCluster|\WP_Error A connected RedisCluster client or a WP_Error on failure.
	 */
	function wppo_redis_connect_cluster( $config ) {
		if ( ! class_exists( 'RedisCluster' ) ) {
			return new \WP_Error( 'missing_cluster', __( 'RedisCluster class not found.', 'performance-optimisation' ) );
		}

		$nodes = wppo_parse_nodes( $config['nodes'] ?? array() );
		if ( empty( $nodes ) ) {
			return new \WP_Error( 'low_nodes', __( 'No nodes provided for Cluster.', 'performance-optimisation' ) );
		}

		$use_tls = isset( $config['use_tls'] ) ? (bool) $config['use_tls'] : false;
		if ( $use_tls ) {
			$nodes = array_map(
				function ( $node ) {
					return ( strpos( $node, 'tls://' ) === 0 ) ? $node : 'tls://' . $node;
				},
				$nodes
			);
		}

		$timeout    = 0.5;
		$password   = $config['password'] ?? '';
		$persistent = ! empty( $config['persistent'] );

		try {
			$cluster = new \RedisCluster( null, $nodes, $timeout, $timeout, $persistent, $password );

			wppo_apply_redis_options( $cluster, $config );

			return $cluster;
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'WPPO Redis cluster error: ' . $e->getMessage() );
			return new \WP_Error( 'cluster_fail', __( 'Redis Cluster connection failed.', 'performance-optimisation' ) );
		}
	}
}

if ( ! function_exists( 'wppo_redis_connect_sentinel' ) ) {
	/**
	 * Establishes a Redis Sentinel connection.
	 *
	 * @param array $config Redis configuration.
	 * @since NEXT
	 * @return \Redis|\WP_Error A connected Redis client or a WP_Error on failure.
	 */
	function wppo_redis_connect_sentinel( $config ) {
		if ( ! class_exists( 'RedisSentinel' ) ) {
			return new \WP_Error( 'missing_sentinel', __( 'RedisSentinel class not found.', 'performance-optimisation' ) );
		}

		$nodes = wppo_parse_nodes( $config['nodes'] ?? array() );
		if ( empty( $nodes ) ) {
			return new \WP_Error( 'low_nodes', __( 'Not enough Sentinel nodes configured.', 'performance-optimisation' ) );
		}

		if ( version_compare( phpversion( 'redis' ), '6.0.0', '<' ) ) {
			return new \WP_Error( 'redis_version', __( 'Sentinel mode requires phpredis version 6.0.0 or higher.', 'performance-optimisation' ) );
		}

		$master_name  = $config['master_name'] ?? 'mymaster';
		$use_tls      = isset( $config['use_tls'] ) ? (bool) $config['use_tls'] : false;
		$password     = $config['password'] ?? '';
		$database     = isset( $config['database'] ) ? (int) $config['database'] : 0;
		$timeout      = 0.5;
		$retry        = 0;
		$read_timeout = 0;
		$errors       = array();

		foreach ( $nodes as $node ) {
			$parsed_node = wppo_parse_redis_node( $node );
			$s_host      = $parsed_node['host'];
			$s_port      = $parsed_node['port'];

			try {
				$sentinel = new \RedisSentinel(
					array(
						'host'           => $s_host,
						'port'           => $s_port,
						'timeout'        => $timeout,
						'retry_interval' => $retry,
						'read_timeout'   => $read_timeout,
					)
				);
				$address  = $sentinel->getMasterAddrByName( $master_name );

				if ( $address ) {
					if ( ! class_exists( 'Redis' ) ) {
						return new \WP_Error( 'missing_redis', __( 'The Redis class is not available.', 'performance-optimisation' ) );
					}
					$redis = new \Redis();
					$host  = $use_tls ? 'tls://' . $address[0] : $address[0];

					if ( $redis->connect( $host, (int) $address[1], $timeout ) ) {
						if ( $password && ! $redis->auth( $password ) ) {
							$redis->close();
							return new \WP_Error( 'auth_fail', __( 'Sentinel Master Auth failed.', 'performance-optimisation' ) );
						}

						if ( ! $redis->select( $database ) ) {
							$redis->close();
							/* translators: %d: Database index */
							return new \WP_Error( 'select_fail', sprintf( __( 'Failed to select Redis database: %d', 'performance-optimisation' ), $database ) );
						}

						wppo_apply_redis_options( $redis, $config );

						return $redis;
					}
				}
			} catch ( \Throwable $e ) {
				$errors[] = __( 'Sentinel node connection failed.', 'performance-optimisation' );
				continue;
			}
		}

		$error_msg = __( 'Could not resolve master via Sentinels.', 'performance-optimisation' );
		if ( ! empty( $errors ) ) {
			$error_msg .= ' ' . end( $errors );
		} else {
			$error_msg .= ' ' . __( 'No sentinel nodes responded or master not found.', 'performance-optimisation' );
		}

		return new \WP_Error( 'sentinel_fail', $error_msg );
	}
}

if ( ! function_exists( 'wppo_redis_connect_standalone' ) ) {
	/**
	 * Establishes a standalone Redis connection.
	 *
	 * @param array $config Redis configuration.
	 * @since NEXT
	 * @return \Redis|\WP_Error A connected Redis client or a WP_Error on failure.
	 */
	function wppo_redis_connect_standalone( $config ) {
		if ( ! class_exists( 'Redis' ) ) {
			return new \WP_Error( 'missing_redis', __( 'The Redis class is not available.', 'performance-optimisation' ) );
		}

		$use_tls  = isset( $config['use_tls'] ) ? (bool) $config['use_tls'] : false;
		$host     = $config['host'] ?? '127.0.0.1';
		$port     = isset( $config['port'] ) ? (int) $config['port'] : 6379;
		$password = $config['password'] ?? '';
		$database = isset( $config['database'] ) ? (int) $config['database'] : 0;
		$timeout  = 0.5;

		if ( $use_tls && strpos( $host, 'tls://' ) !== 0 ) {
			$host = 'tls://' . $host;
		}

		$redis = new \Redis();
		$func  = ! empty( $config['persistent'] ) ? 'pconnect' : 'connect';

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( @$redis->$func( $host, $port, $timeout ) ) {
			if ( ! empty( $password ) && $redis->auth( $password ) === false ) {
				$redis->close();
				return new \WP_Error( 'auth_fail', __( 'Redis Auth failed.', 'performance-optimisation' ) );
			}

			if ( ! $redis->select( $database ) ) {
				$redis->close();
				/* translators: %d: Database index */
				return new \WP_Error( 'select_fail', sprintf( __( 'Failed to select Redis database: %d', 'performance-optimisation' ), $database ) );
			}

			wppo_apply_redis_options( $redis, $config );

			return $redis;
		}

		return new \WP_Error( 'conn_fail', __( 'Could not connect to Redis. Please ensure the service is running.', 'performance-optimisation' ) );
	}
}

if ( ! function_exists( 'wppo_parse_redis_node' ) ) {
	/**
	 * Parses a Redis node string into host and port components.
	 *
	 * Handles standard "host:port" formats as well as IPv6 enclosed in brackets.
	 *
	 * @param string $node The node string to parse.
	 * @since NEXT
	 * @return array Associative array containing 'host' and 'port'.
	 */
	function wppo_parse_redis_node( $node ) {
		if ( strpos( $node, '[' ) === 0 ) {
			$port_start = strpos( $node, ']:' );
			if ( false !== $port_start ) {
				$host = substr( $node, 1, $port_start - 1 );
				$port = (int) substr( $node, $port_start + 2 );
			} else {
				$host = trim( $node, '[]' );
				$port = 26379;
			}
		} elseif ( substr_count( $node, ':' ) > 1 ) {
			$host = $node;
			$port = 26379;
		} else {
			$last_colon = strrpos( $node, ':' );
			if ( false !== $last_colon ) {
				$host = substr( $node, 0, $last_colon );
				$port = (int) substr( $node, $last_colon + 1 );
			} else {
				$host = $node;
				$port = 26379;
			}
		}

		return array(
			'host' => $host,
			'port' => $port,
		);
	}
}

if ( ! function_exists( 'wppo_apply_redis_options' ) ) {
	/**
	 * Configure serializer and optional compression on a Redis client based on provided settings.
	 *
	 * Selects the igbinary serializer when available, otherwise falls back to the PHP serializer.
	 * If `$config['compression']` is set to "lzf", "zstd", or "lz4" and the corresponding phpRedis
	 * compression constant is defined, applies that compression option.
	 *
	 * @param \Redis|\RedisCluster $redis  Redis client instance to configure.
	 * @param array                $config Configuration options; recognizes `compression` which may be
	 *                                      "lzf", "zstd", "lz4", or "none".
	 * @since 1.4.0
	 */
	function wppo_apply_redis_options( $redis, $config ) {
		$serializer = defined( '\Redis::SERIALIZER_IGBINARY' ) ? \Redis::SERIALIZER_IGBINARY : \Redis::SERIALIZER_PHP;
		$redis->setOption( \Redis::OPT_SERIALIZER, $serializer );

		if ( isset( $config['compression'] ) && 'none' !== $config['compression'] ) {
			$compression_type = null;
			if ( 'lzf' === $config['compression'] && defined( '\Redis::COMPRESSION_LZF' ) ) {
				$compression_type = \Redis::COMPRESSION_LZF;
			} elseif ( 'zstd' === $config['compression'] && defined( '\Redis::COMPRESSION_ZSTD' ) ) {
				$compression_type = \Redis::COMPRESSION_ZSTD;
			} elseif ( 'lz4' === $config['compression'] && defined( '\Redis::COMPRESSION_LZ4' ) ) {
				$compression_type = \Redis::COMPRESSION_LZ4;
			}

			if ( null !== $compression_type ) {
				$redis->setOption( \Redis::OPT_COMPRESSION, $compression_type );
			}
		}
	}
}

if ( ! function_exists( 'wppo_parse_nodes' ) ) {
	/**
	 * Normalize a string or array of Redis node specifications into a trimmed list.
	 *
	 * Accepts a string containing nodes separated by whitespace, commas, or semicolons, or an array of node strings.
	 *
	 * @since 1.4.0
	 * @param array|string $nodes Node specifications as a delimited string or an array of strings.
	 * @return string[] Trimmed node strings with empty entries removed; returns an empty array for unsupported input.
	 */
	function wppo_parse_nodes( $nodes ) {
		if ( is_string( $nodes ) ) {
			$nodes = preg_split( '/[\s,;]+/', $nodes );
			if ( false === $nodes ) {
				return array();
			}
		}

		if ( is_array( $nodes ) ) {
			return array_values( array_filter( array_map( 'trim', $nodes ) ) );
		}

		return array();
	}
}
