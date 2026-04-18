<?php
/**
 * Redis Connection Helper.
 *
 * Provides a shared connection logic for both the admin dashboard and the object cache drop-in.
 *
 * @package PerformanceOptimise
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
 * @return \Redis|\RedisCluster|\WP_Error A connected Redis client (`Redis` or `RedisCluster`) on success, or a `WP_Error` describing the failure. */
function wppo_redis_connect( $config ) {
	$mode     = $config['mode'] ?? 'standalone';
	$password = $config['password'] ?? '';
	$database = isset( $config['database'] ) ? (int) $config['database'] : 0;
	$use_tls  = isset( $config['use_tls'] ) ? (bool) $config['use_tls'] : false;
	$timeout  = 0.5;

	try {
		if ( 'cluster' === $mode ) {
			if ( ! class_exists( 'RedisCluster' ) ) {
				return new \WP_Error( 'missing_cluster', 'RedisCluster class not found.' );
			}
			$nodes = wppo_parse_nodes( $config['nodes'] ?? array() );
			if ( empty( $nodes ) ) {
				return new \WP_Error( 'low_nodes', 'No nodes provided for Cluster.' );
			}

			if ( $use_tls ) {
				$nodes = array_map(
					function ( $node ) {
						return ( strpos( $node, 'tls://' ) === 0 ) ? $node : 'tls://' . $node;
					},
					$nodes
				);
			}

			try {
				$persistent = ! empty( $config['persistent'] );
				$cluster    = new \RedisCluster( null, $nodes, $timeout, $timeout, $persistent, $password );

				wppo_apply_redis_options( $cluster, $config );

				return $cluster;
			} catch ( \Throwable $e ) {
				return new \WP_Error( 'cluster_fail', 'Redis Cluster connection failed: ' . $e->getMessage() );
			}
		}

		if ( 'sentinel' === $mode ) {
			if ( ! class_exists( 'RedisSentinel' ) ) {
				return new \WP_Error( 'missing_sentinel', 'RedisSentinel class not found.' );
			}
			$nodes = wppo_parse_nodes( $config['nodes'] ?? array() );

			if ( empty( $nodes ) ) {
				return new \WP_Error( 'low_nodes', 'Not enough Sentinel nodes configured.' );
			}

			if ( version_compare( phpversion( 'redis' ), '6.0.0', '<' ) ) {
				return new \WP_Error( 'redis_version', 'Sentinel mode requires phpredis version 6.0.0 or higher.' );
			}

			$master_name = $config['master_name'] ?? 'mymaster';
			$errors      = array();
			foreach ( $nodes as $node ) {
				// Robust parsing of host and port (handles IPv6).
				if ( strpos( $node, '[' ) === 0 ) {
					$port_start = strpos( $node, ']:' );
					if ( false !== $port_start ) {
						$s_host = substr( $node, 1, $port_start - 1 );
						$s_port = (int) substr( $node, $port_start + 2 );
					} else {
						$s_host = trim( $node, '[]' );
						$s_port = 26379;
					}
				} else {
					$last_colon = strrpos( $node, ':' );
					if ( false !== $last_colon ) {
						$s_host = substr( $node, 0, $last_colon );
						$s_port = (int) substr( $node, $last_colon + 1 );
					} else {
						$s_host = $node;
						$s_port = 26379;
					}
				}

				try {
					$sentinel = new \RedisSentinel(
						array(
							'host' => $s_host,
							'port' => (int) $s_port,
						)
					);
					$address  = $sentinel->getMasterAddrByName( $master_name );
					if ( $address ) {
						if ( ! class_exists( 'Redis' ) ) {
							return new \WP_Error( 'missing_redis', 'The Redis class is not available.' );
						}
						$redis = new \Redis();
						$host  = $use_tls ? 'tls://' . $address[0] : $address[0];
						if ( $redis->connect( $host, (int) $address[1], $timeout ) ) {
							if ( $password && ! $redis->auth( $password ) ) {
								$redis->close();
								return new \WP_Error( 'auth_fail', 'Sentinel Master Auth failed.' );
							}

							if ( ! $redis->select( $database ) ) {
								$redis->close();
								return new \WP_Error( 'select_fail', "Failed to select Redis database: $database" );
							}

							wppo_apply_redis_options( $redis, $config );

							return $redis;
						}
					}
				} catch ( \Throwable $e ) {
					$errors[] = $s_host . ':' . $s_port . ' - ' . $e->getMessage();
					continue;
				}
			}

			$error_msg = 'Could not resolve master via Sentinels.';
			if ( ! empty( $errors ) ) {
				$error_msg .= ' Last error: ' . end( $errors );
			} else {
				$error_msg .= ' No sentinel nodes responded or master not found.';
			}
			return new \WP_Error( 'sentinel_fail', $error_msg );
		}

		// Standalone default connection.
		if ( ! class_exists( 'Redis' ) ) {
			return new \WP_Error( 'missing_redis', 'The Redis class is not available.' );
		}
		$host = $config['host'] ?? '127.0.0.1';
		$port = isset( $config['port'] ) ? (int) $config['port'] : 6379;
		if ( $use_tls && strpos( $host, 'tls://' ) !== 0 ) {
			$host = 'tls://' . $host;
		}

		$redis = new \Redis();
		$func  = ! empty( $config['persistent'] ) ? 'pconnect' : 'connect';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( @$redis->$func( $host, $port, $timeout ) ) {
			if ( ! empty( $password ) && $redis->auth( $password ) === false ) {
				$redis->close();
				return new \WP_Error( 'auth_fail', 'Redis Auth failed.' );
			}

			if ( ! $redis->select( $database ) ) {
				$redis->close();
				return new \WP_Error( 'select_fail', "Failed to select Redis database: $database" );
			}

			wppo_apply_redis_options( $redis, $config );

			return $redis;
		}
	} catch ( \Throwable $e ) {
		return new \WP_Error( 'redis_err', $e->getMessage() );
	}

	return new \WP_Error( 'conn_fail', 'Could not connect to Redis. Please ensure the service is running.' );
}

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
