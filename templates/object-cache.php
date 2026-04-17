<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Redis Object Cache Drop-in for Performance Optimisation
 *
 * @package PerformanceOptimise
 */

/**
 * Object Cache Drop-in for WordPress.
 */
if ( ! class_exists( 'WP_Object_Cache' ) ) {
	/**
	 * WP_Object_Cache class.
	 *
	 * phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed
	 */
	class WP_Object_Cache {
		/**
		 * Holds the cache data.
		 *
		 * @var array
		 */
		private $cache = array();

		/**
		 * Holds the Redis client instance.
		 *
		 * @var \Redis|\RedisCluster|null
		 */
		private $redis;

		/**
		 * Holds the Redis client replica instance.
		 *
		 * @var \Redis|null
		 */
		private $redis_replica = null;

		/**
		 * Flag indicating if Redis is connected.
		 *
		 * @var bool
		 */
		private $redis_connected = false;

		/**
		 * Prefix for the blog namespace.
		 *
		 * @var string
		 */
		public $blog_prefix;

		/**
		 * Constructor.
		 */
		public function __construct() {
			global $table_prefix;

			$this->blog_prefix = ( is_multisite() ? get_current_blog_id() : $table_prefix ) . ':';

			$this->connect_redis();
		}

		/**
		 * Connects to Redis server.
		 *
		 * @return void
		 */
		private function connect_redis() {
			$config_file = WP_CONTENT_DIR . '/wppo-redis-config.php';
			$config      = array();

			if ( file_exists( $config_file ) ) {
				$config = include $config_file; // phpcs:ignore WPThemeReview.CoreFunctionality.FileInclude.FileIncludeFound
			}

			if ( ! is_array( $config ) ) {
				return;
			}

			$use_tls  = $config['use_tls'] ?? false;
			$database = isset( $config['database'] ) ? (int) $config['database'] : 0;
			$password = $config['password'] ?? '';
			$timeout  = 0.5;

			try {
				$connection = self::wppo_redis_connect( $config );

				if ( is_wp_error( $connection ) ) {
					$this->redis_connected = false;
					return;
				}

				$this->redis           = $connection;
				$this->redis_connected = true;

				// Standalone replica support.
				if ( 'standalone' === ( $config['mode'] ?? 'standalone' ) && isset( $config['replicas'] ) && is_array( $config['replicas'] ) && ! empty( $config['replicas'] ) ) {
					$replica = $config['replicas'][ array_rand( $config['replicas'] ) ];
					$r_host  = $replica['host'] ?? '127.0.0.1';
					$r_port  = isset( $replica['port'] ) ? (int) $replica['port'] : 6379;
					$r_pass  = $replica['password'] ?? $password;
					try {
						$tmp_replica = new \Redis();
						if ( $use_tls && strpos( $r_host, 'tls://' ) !== 0 ) {
							$r_host = 'tls://' . $r_host;
						}
						if ( $tmp_replica->connect( $r_host, $r_port, $timeout ) ) {
							$replica_auth_ok = true;
							if ( ! empty( $r_pass ) ) {
								$replica_auth_ok = $tmp_replica->auth( $r_pass );
							}

							if ( $replica_auth_ok && $tmp_replica->select( $database ) ) {
								$this->redis_replica = $tmp_replica;
							} else {
								$tmp_replica->close();
							}
						}
					} catch ( \Throwable $e ) {
						$this->redis_replica = null;
					}
				}

				if ( $this->redis_connected && $this->redis ) {
					self::wppo_apply_redis_options( $this->redis, $config );
					if ( $this->redis_replica ) {
						self::wppo_apply_redis_options( $this->redis_replica, $config );
					}
				}
			} catch ( \Throwable $e ) {
				$this->redis_connected = false;
				$this->redis_replica   = null;
			}
		}

		/**
		 * Connect to Redis based on configuration.
		 *
		 * @param array $config Redis configuration.
		 * @return \Redis|\RedisCluster|\WP_Error Connected instance or error.
		 */
		private static function wppo_redis_connect( $config ) {
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
					$nodes = $config['nodes'] ?? array();
					if ( is_string( $nodes ) ) {
						$nodes = array_filter( array_map( 'trim', explode( "\n", $nodes ) ) );
					}
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

						self::wppo_apply_redis_options( $cluster, $config );

						return $cluster;
					} catch ( \Throwable $e ) {
						return new \WP_Error( 'cluster_fail', 'Redis Cluster connection failed: ' . $e->getMessage() );
					}
				}

				if ( 'sentinel' === $mode ) {
					if ( ! class_exists( 'RedisSentinel' ) ) {
						return new \WP_Error( 'missing_sentinel', 'RedisSentinel class not found.' );
					}
					$nodes       = $config['nodes'] ?? array();
					$master_name = $config['master_name'] ?? 'mymaster';

					if ( is_string( $nodes ) ) {
						$nodes = array_filter( array_map( 'trim', explode( "\n", $nodes ) ) );
					}

					if ( empty( $nodes ) ) {
						return new \WP_Error( 'low_nodes', 'Not enough Sentinel nodes configured.' );
					}

					if ( version_compare( phpversion( 'redis' ), '6.0.0', '<' ) ) {
						return new \WP_Error( 'redis_version', 'Sentinel mode requires phpredis version 6.0.0 or higher.' );
					}

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

									self::wppo_apply_redis_options( $redis, $config );

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

					self::wppo_apply_redis_options( $redis, $config );

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
		private static function wppo_apply_redis_options( $redis, $config ) {
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
		 * Retrieves the actual key prefixed correctly.
		 *
		 * @param string $key   Cache key.
		 * @param string $group Cache group.
		 * @return string Prefix cache key.
		 */
		private function get_key( $key, $group = '' ) {
			$group = empty( $group ) ? 'default' : $group;

			if ( in_array( $group, $this->global_groups, true ) ) {
				$prefix = '';
			} else {
				$prefix = $this->blog_prefix;
			}

			return $prefix . $group . ':' . $key;
		}

		/**
		 * Adds data to the cache if it doesn't already exist.
		 *
		 * @param int|string $key    Cache key.
		 * @param mixed      $data   Cache data.
		 * @param string     $group  Cache group.
		 * @param int        $expire Expiration.
		 * @return bool True on success.
		 */
		public function add( $key, $data, $group = 'default', $expire = 0 ) {
			if ( wp_suspend_cache_addition() ) {
				return false;
			}

			if ( in_array( $group, $this->no_mc_groups, true ) || ! $this->redis_connected ) {
				$local_key = $this->get_key( $key, $group );
				if ( isset( $this->cache[ $local_key ] ) ) {
					return false;
				}
				$this->cache[ $local_key ] = $data;
				return true;
			}

			$formatted_key = $this->get_key( $key, $group );

			if ( $this->redis->exists( $formatted_key ) ) {
				return false;
			}

			return $this->set( $key, $data, $group, $expire );
		}

		/**
		 * Sets data to the cache.
		 *
		 * @param int|string $key    Cache key.
		 * @param mixed      $data   Cache data.
		 * @param string     $group  Cache group.
		 * @param int        $expire Expiration.
		 * @return bool True on success.
		 */
		public function set( $key, $data, $group = 'default', $expire = 0 ) {
			if ( in_array( $group, $this->no_mc_groups, true ) || ! $this->redis_connected ) {
				$this->cache[ $this->get_key( $key, $group ) ] = $data;
				return true;
			}

			$formatted_key = $this->get_key( $key, $group );

			if ( $expire > 0 ) {
				return $this->redis->setex( $formatted_key, $expire, $data );
			}

			return $this->redis->set( $formatted_key, $data );
		}

		/**
		 * Gets data from the cache.
		 *
		 * @param int|string $key   Cache key.
		 * @param string     $group Cache group.
		 * @param bool       $force Force from Redis.
		 * @param bool       $found Result flag.
		 * @return mixed False if failed.
		 */
		public function get( $key, $group = 'default', $force = false, &$found = null ) {
			$local_key = $this->get_key( $key, $group );

			if ( in_array( $group, $this->no_mc_groups, true ) || ! $this->redis_connected ) {
				if ( isset( $this->cache[ $local_key ] ) ) {
					$found = true;
					return $this->cache[ $local_key ];
				}
				$found = false;
				return false;
			}

			if ( ! $force && isset( $this->cache[ $local_key ] ) ) {
				$found = true;
				return $this->cache[ $local_key ];
			}

			$redis_instance = $this->redis_replica ? $this->redis_replica : $this->redis;
			$value          = $redis_instance->get( $local_key );

			if ( false === $value ) {
				$found = false;
				return false;
			}

			$found                     = true;
			$this->cache[ $local_key ] = $value;
			return $value;
		}

		/**
		 * Retrieves multiple values from the cache.
		 *
		 * @param array  $keys  Array of keys.
		 * @param string $group Cache group.
		 * @param bool   $force Force from Redis.
		 * @return array Array of return values.
		 */
		public function get_multiple( $keys, $group = 'default', $force = false ) {
			$values = array();
			if ( empty( $keys ) ) {
				return $values;
			}

			if ( in_array( $group, $this->no_mc_groups, true ) || ! $this->redis_connected ) {
				foreach ( $keys as $key ) {
					$local_key = $this->get_key( $key, $group );
					if ( ! $force && isset( $this->cache[ $local_key ] ) ) {
						$values[ $key ] = $this->cache[ $local_key ];
					} else {
						$values[ $key ] = false;
					}
				}
				return $values;
			}

			// Exclude keys already in local cache if not forcing.
			$keys_to_fetch  = array();
			$formatted_keys = array();
			foreach ( $keys as $key ) {
				$local_key = $this->get_key( $key, $group );
				if ( ! $force && isset( $this->cache[ $local_key ] ) ) {
					$values[ $key ] = $this->cache[ $local_key ];
				} else {
					$keys_to_fetch[]  = $key;
					$formatted_keys[] = $local_key;
				}
			}

			if ( empty( $keys_to_fetch ) ) {
				return $values;
			}

			$redis_instance = $this->redis_replica ? $this->redis_replica : $this->redis;
			$redis_values   = $redis_instance->mGet( $formatted_keys );

			foreach ( $keys_to_fetch as $index => $key ) {
				if ( isset( $redis_values[ $index ] ) && false !== $redis_values[ $index ] ) {
					$local_key                 = $formatted_keys[ $index ];
					$this->cache[ $local_key ] = $redis_values[ $index ];
					$values[ $key ]            = $redis_values[ $index ];
				} else {
					$values[ $key ] = false;
				}
			}

			return $values;
		}

		/**
		 * Sets multiple values to the cache.
		 *
		 * @param array  $data   Array of keys and values.
		 * @param string $group  Cache group.
		 * @param int    $expire Expiration.
		 * @return bool True on success.
		 */
		public function set_multiple( $data, $group = 'default', $expire = 0 ) {
			if ( empty( $data ) ) {
				return true;
			}

			$formatted_data = array();
			foreach ( $data as $key => $value ) {
				$local_key                    = $this->get_key( $key, $group );
				$this->cache[ $local_key ]    = $value;
				$formatted_data[ $local_key ] = $value;
			}

			if ( in_array( $group, $this->no_mc_groups, true ) || ! $this->redis_connected ) {
				return true;
			}

			if ( $expire > 0 ) {
				// We must use a pipeline for mSet with expiration.
				$pipeline = $this->redis->multi( \Redis::PIPELINE );
				foreach ( $formatted_data as $k => $v ) {
					$pipeline->setex( $k, $expire, $v );
				}
				$pipeline->exec();
				return true;
			}

			return $this->redis->mSet( $formatted_data );
		}

		/**
		 * Deletes data from the cache.
		 *
		 * @param int|string $key   Cache key.
		 * @param string     $group Cache group.
		 * @return bool True on success.
		 */
		public function delete( $key, $group = 'default' ) {
			$local_key = $this->get_key( $key, $group );
			unset( $this->cache[ $local_key ] );

			if ( in_array( $group, $this->no_mc_groups, true ) || ! $this->redis_connected ) {
				return true;
			}

			return (bool) $this->redis->del( $local_key );
		}

		/**
		 * Deletes multiple values from the cache.
		 *
		 * @param array  $keys  Array of keys.
		 * @param string $group Cache group.
		 * @return bool True on success.
		 */
		public function delete_multiple( $keys, $group = 'default' ) {
			if ( empty( $keys ) ) {
				return true;
			}

			$formatted_keys = array();
			foreach ( $keys as $key ) {
				$local_key = $this->get_key( $key, $group );
				unset( $this->cache[ $local_key ] );
				$formatted_keys[] = $local_key;
			}

			if ( in_array( $group, $this->no_mc_groups, true ) || ! $this->redis_connected ) {
				return true;
			}

			return (bool) $this->redis->del( $formatted_keys );
		}

		/**
		 * Replaces existing data.
		 *
		 * @param int|string $key    Cache key.
		 * @param mixed      $data   Cache data.
		 * @param string     $group  Cache group.
		 * @param int        $expire Expiration.
		 * @return bool True on success.
		 */
		public function replace( $key, $data, $group = 'default', $expire = 0 ) {
			if ( in_array( $group, $this->no_mc_groups, true ) || ! $this->redis_connected ) {
				return false;
			}

			$formatted_key = $this->get_key( $key, $group );

			if ( ! $this->redis->exists( $formatted_key ) ) {
				return false;
			}

			return $this->set( $key, $data, $group, $expire );
		}

		/**
		 * Flushes the object cache for this site only.
		 *
		 * Uses a SCAN loop to find and delete keys matching this site's prefix,
		 * avoiding a global FLUSH. Operators may opt in to a full flushDb() via
		 * the 'object_cache_allow_flush_all' filter for single-site/isolated setups.
		 *
		 * @return bool True on success.
		 */
		public function flush() {
			$this->cache = array();
			if ( $this->redis_connected ) {
				if ( apply_filters( 'object_cache_allow_flush_all', false ) ) {
					return $this->redis->flushDb();
				}

				$prefix  = $this->blog_prefix;
				$pattern = $prefix . '*';

				if ( $this->redis instanceof \RedisCluster ) {
					$masters = $this->redis->_masters();
					foreach ( $masters as $node ) {
						$cursor = null;
						do {
							$keys = $this->redis->scan( $cursor, $node, $pattern, 100 );
							if ( false === $keys ) {
								break;
							}
							if ( is_array( $keys ) && ! empty( $keys ) ) {
								$this->redis->del( $keys );
							}
						} while ( $cursor && ( is_numeric( $cursor ) && 0 !== (int) $cursor ) );
					}
					return true;
				}

				$cursor = null;
				do {
					$keys = $this->redis->scan( $cursor, $pattern, 100 );
					if ( false === $keys ) {
						break;
					}
					if ( is_array( $keys ) && ! empty( $keys ) ) {
						$this->redis->del( $keys );
					}
				} while ( $cursor && ( is_numeric( $cursor ) && 0 !== (int) $cursor ) );

				return true;
			}
			return true;
		}

		/**
		 * Increases a cached value.
		 *
		 * @param int|string $key    Cache key.
		 * @param int        $offset Offset amount.
		 * @param string     $group  Cache group.
		 * @return int|bool The new value or false.
		 */
		public function incr( $key, $offset = 1, $group = 'default' ) {
			if ( in_array( $group, $this->no_mc_groups, true ) || ! $this->redis_connected ) {
				$local_key = $this->get_key( $key, $group );
				if ( ! isset( $this->cache[ $local_key ] ) ) {
					$this->cache[ $local_key ] = 0;
				}
				$this->cache[ $local_key ] += $offset;
				return $this->cache[ $local_key ];
			}

			return $this->redis->incrBy( $this->get_key( $key, $group ), $offset );
		}

		/**
		 * Decreases a cached value.
		 *
		 * @param int|string $key    Cache key.
		 * @param int        $offset Offset amount.
		 * @param string     $group  Cache group.
		 * @return int|bool The new value or false.
		 */
		public function decr( $key, $offset = 1, $group = 'default' ) {
			if ( in_array( $group, $this->no_mc_groups, true ) || ! $this->redis_connected ) {
				$local_key = $this->get_key( $key, $group );
				if ( ! isset( $this->cache[ $local_key ] ) ) {
					$this->cache[ $local_key ] = 0;
				}
				$this->cache[ $local_key ] -= $offset;
				return $this->cache[ $local_key ];
			}

			return $this->redis->decrBy( $this->get_key( $key, $group ), $offset );
		}

		/**
		 * Sets the list of global groups.
		 *
		 * @param array $groups Global groups.
		 * @return void
		 */
		public function add_global_groups( $groups ) {
			$groups              = (array) $groups;
			$this->global_groups = array_unique( array_merge( $this->global_groups, $groups ) );
		}

		/**
		 * Sets the list of groups that should not be cached in Redis.
		 *
		 * @param array $groups Non-persistent groups.
		 * @return void
		 */
		public function add_non_persistent_groups( $groups ) {
			$groups             = (array) $groups;
			$this->no_mc_groups = array_unique( array_merge( $this->no_mc_groups, $groups ) );
		}

		/**
		 * Magic getter for backward compatibility.
		 *
		 * @param string $name Property name.
		 * @return mixed
		 */
		public function __get( $name ) {
			return $this->$name;
		}

		/**
		 * Non-persistent groups.
		 *
		 * @var array
		 */
		private $no_mc_groups = array();

		/**
		 * Global groups.
		 *
		 * @var array
		 */
		private $global_groups = array();
	}
}

/**
 * Global cache functions.
 */

/**
 * Adds data to the cache.
 *
 * @param int|string $key    Cache key.
 * @param mixed      $data   Cache data.
 * @param string     $group  Cache group.
 * @param int        $expire Expiration.
 * @return bool True on success.
 */
function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->add( $key, $data, $group, (int) $expire );
}

/**
 * Sets data to the cache.
 *
 * @param int|string $key    Cache key.
 * @param mixed      $data   Cache data.
 * @param string     $group  Cache group.
 * @param int        $expire Expiration.
 * @return bool True on success.
 */
function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->set( $key, $data, $group, (int) $expire );
}

/**
 * Gets data from the cache.
 *
 * @param int|string $key   Cache key.
 * @param string     $group Cache group.
 * @param bool       $force Force from Redis.
 * @param bool       $found Result flag.
 * @return mixed False if failed.
 */
function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	global $wp_object_cache;
	return $wp_object_cache->get( $key, $group, $force, $found );
}

/**
 * Deletes data from the cache.
 *
 * @param int|string $key   Cache key.
 * @param string     $group Cache group.
 * @return bool True on success.
 */
function wp_cache_delete( $key, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->delete( $key, $group );
}

/**
 * Flushes the object cache.
 */
function wp_cache_flush() {
	global $wp_object_cache;
	return $wp_object_cache->flush();
}

/**
 * Initializes the object cache.
 */
function wp_cache_init() {
	global $wp_object_cache;
	// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
	$wp_object_cache = new WP_Object_Cache();
}

/**
 * Replaces existing data.
 *
 * @param int|string $key    Cache key.
 * @param mixed      $data   Cache data.
 * @param string     $group  Cache group.
 * @param int        $expire Expiration.
 * @return bool True on success.
 */
function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->replace( $key, $data, $group, (int) $expire );
}

/**
 * Adds global groups.
 *
 * @param array $groups Global groups.
 */
function wp_cache_add_global_groups( $groups ) {
	global $wp_object_cache;
	$wp_object_cache->add_global_groups( $groups );
}

/**
 * Adds non-persistent groups.
 *
 * @param array $groups Non-persistent groups.
 */
function wp_cache_add_non_persistent_groups( $groups ) {
	global $wp_object_cache;
	$wp_object_cache->add_non_persistent_groups( $groups );
}

/**
 * Increases a cached value.
 *
 * @param int|string $key    Cache key.
 * @param int        $offset Offset amount.
 * @param string     $group  Cache group.
 * @return int|bool The new value or false.
 */
function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->incr( $key, $offset, $group );
}

/**
 * Decreases a cached value.
 *
 * @param int|string $key    Cache key.
 * @param int        $offset Offset amount.
 * @param string     $group  Cache group.
 * @return int|bool The new value or false.
 */
function wp_cache_decr( $key, $offset = 1, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->decr( $key, $offset, $group );
}
