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
 * Connect to Redis based on configuration.
 *
 * @param array $config {
 *     Redis configuration.
 *
 *     @type string $mode        Connection mode (standalone, sentinel, cluster).
 *     @type string $host        Redis host.
 *     @type int    $port        Redis port.
 *     @type string $password    Redis password.
 *     @type int    $database    Redis database ID.
 *     @type array|string $nodes       List of nodes for cluster/sentinel. String values may be comma- or newline-delimited lists of host:port entries.
 *     @type string $master_name Master name for sentinel.
 *     @type bool   $use_tls     Whether to use TLS.
 *     @type bool   $persistent  Whether to use persistent connections.
 *     @type string $compression Compression algorithm (none, lzf, zstd, lz4).
 * }
 * @return \Redis|\RedisCluster|\WP_Error Connected instance or error.
 */
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
			$errors = array();
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
 * Apply performance and serialization options to a Redis client.
 *
 * @param \Redis|\RedisCluster $redis  Redis client instance.
 * @param array                $config Configuration options.
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
 * Parses a string or array of nodes into a normalized array.
 *
 * @since 1.4.0
 * @param array|string $nodes Raw nodes input.
 * @return array Normalized array of nodes.
 */
function wppo_parse_nodes( $nodes ) {
	if ( is_string( $nodes ) ) {
		return array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', $nodes ) ) );
	}
	return (array) $nodes;
}
