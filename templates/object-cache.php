<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Redis Object Cache Drop-in for Performance Optimisation.
 *
 * phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
 * phpcs:disable Squiz.Classes.ClassFileName.NoMatch
 *
 * @package PerformanceOptimise
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_cache_add' ) ) :

	/**
	 * Setup object cache.
	 */
	function wp_cache_init() {
		global $wp_object_cache;
		$wp_object_cache = new WP_Object_Cache(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Adds data to the cache, if the cache key doesn't already exist.
	 *
	 * @param int|string $key    The cache key to use for retrieval later.
	 * @param mixed      $data   The data to add to the cache.
	 * @param string     $group  Optional. The group to add the cache to. Enables the same key to be used across groups. Default empty.
	 * @param int        $expire Optional. When the cache data should expire, in seconds. Default 0 (no expiration).
	 * @return bool True on success, false if cache key and group already exist.
	 */
	function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
		global $wp_object_cache;
		return $wp_object_cache->add( $key, $data, $group, (int) $expire );
	}

	/**
	 * Sets the list of global cache groups.
	 *
	 * @param string|string[] $groups List of groups that should not have their key prefixed with the site ID.
	 */
	function wp_cache_add_global_groups( $groups ) {
		global $wp_object_cache;
		$wp_object_cache->add_global_groups( $groups );
	}

	/**
	 * Sets the list of non-persistent cache groups.
	 *
	 * @param string|string[] $groups List of groups that should not be saved to the persistent cache.
	 */
	function wp_cache_add_non_persistent_groups( $groups ) {
		global $wp_object_cache;
		$wp_object_cache->add_non_persistent_groups( $groups );
	}

	/**
	 * Closes the cache.
	 *
	 * @return true
	 */
	function wp_cache_close() {
		global $wp_object_cache;
		return $wp_object_cache->close();
	}

	/**
	 * Decrements numeric cache item's value.
	 *
	 * @param int|string $key    The cache key to decrement.
	 * @param int        $offset Optional. The amount by which to decrement the item's value. Default 1.
	 * @param string     $group  Optional. The group the key is in. Default empty.
	 * @return int|false The item's new value on success, false on failure.
	 */
	function wp_cache_decrease( $key, $offset = 1, $group = '' ) {
		global $wp_object_cache;
		return $wp_object_cache->decrease( $key, $offset, $group );
	}

	/**
	 * Removes the cache contents matching key and group.
	 *
	 * @param int|string $key   What the contents in the cache are called.
	 * @param string     $group Optional. Where the cache contents are grouped. Default empty.
	 * @return bool True on successful removal, false on failure.
	 */
	function wp_cache_delete( $key, $group = '' ) {
		global $wp_object_cache;
		return $wp_object_cache->delete( $key, $group );
	}

	/**
	 * Removes all cache items.
	 *
	 * @return bool True on success, false on failure.
	 */
	function wp_cache_flush() {
		global $wp_object_cache;
		return $wp_object_cache->flush();
	}

	/**
	 * Retrieves the cache contents from the cache by key and group.
	 *
	 * @param int|string $key   The key under which the cache contents are stored.
	 * @param string     $group Optional. Where the cache contents are grouped. Default empty.
	 * @param bool       $force Optional. Whether to force an update of the local cache from the persistent cache. Default false.
	 * @param bool       $found Optional. Whether the key was found in the cache (passed by reference). Disambiguates a return of false. Default null.
	 * @return mixed|false The cache contents on success, false on failure to retrieve contents.
	 */
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
		global $wp_object_cache;
		return $wp_object_cache->get( $key, $group, $force, $found );
	}

	/**
	 * Increments numeric cache item's value.
	 *
	 * @param int|string $key    The cache key to increment.
	 * @param int        $offset Optional. The amount by which to increment the item's value. Default 1.
	 * @param string     $group  Optional. The group the key is in. Default empty.
	 * @return int|false The item's new value on success, false on failure.
	 */
	function wp_cache_increase( $key, $offset = 1, $group = '' ) {
		global $wp_object_cache;
		return $wp_object_cache->increase( $key, $offset, $group );
	}

	/**
	 * Replaces the contents of the cache with new data.
	 *
	 * @param int|string $key    The cache key to insert data under.
	 * @param mixed      $data   The data to insert.
	 * @param string     $group  Optional. The group to insert data into. Default empty.
	 * @param int        $expire Optional. When the cache data should expire, in seconds. Default 0 (no expiration).
	 * @return bool True if contents were replaced, false if original value does not exist.
	 */
	function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
		global $wp_object_cache;
		return $wp_object_cache->replace( $key, $data, $group, (int) $expire );
	}

	/**
	 * Saves the data to the cache.
	 *
	 * @param int|string $key    The cache key to insert data under.
	 * @param mixed      $data   The data to insert.
	 * @param string     $group  Optional. The group to insert data into. Default empty.
	 * @param int        $expire Optional. When the cache data should expire, in seconds. Default 0 (no expiration).
	 * @return bool True on success, false on failure.
	 */
	function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
		global $wp_object_cache;
		return $wp_object_cache->set( $key, $data, $group, (int) $expire );
	}

	/**
	 * Switches the internal blog ID.
	 *
	 * @param int $blog_id Blog ID.
	 */
	function wp_cache_switch_to_blog( $blog_id ) {
		global $wp_object_cache;
		$wp_object_cache->switch_to_blog( $blog_id );
	}

	/**
	 * Retrieves multiple values from the cache in one call.
	 *
	 * @param array  $keys  Array of keys under which the cache contents are stored.
	 * @param string $group Optional. Where the cache contents are grouped. Default empty.
	 * @param bool   $force Optional. Whether to force an update of the local cache from the persistent cache. Default false.
	 * @return array Array of return values, grouped by key.
	 */
	function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
		global $wp_object_cache;
		if ( method_exists( $wp_object_cache, 'get_multiple' ) ) {
			return $wp_object_cache->get_multiple( $keys, $group, $force );
		}
		$values = array();
		foreach ( $keys as $key ) {
			$values[ $key ] = wp_cache_get( $key, $group, $force );
		}
		return $values;
	}

	/**
	 * Sets multiple values to the cache in one call.
	 *
	 * @param array  $data   Array of keys and values to be set.
	 * @param string $group  Optional. Where the cache contents are grouped. Default empty.
	 * @param int    $expire Optional. When the cache data should expire, in seconds. Default 0 (no expiration).
	 * @return bool[] Array of return values, grouped by key.
	 */
	function wp_cache_set_multiple( $data, $group = '', $expire = 0 ) {
		global $wp_object_cache;
		if ( method_exists( $wp_object_cache, 'set_multiple' ) ) {
			return $wp_object_cache->set_multiple( $data, $group, $expire );
		}
		$values = array();
		foreach ( $data as $key => $value ) {
			$values[ $key ] = wp_cache_set( $key, $value, $group, $expire );
		}
		return $values;
	}

	/**
	 * Adds multiple values to the cache in one call.
	 *
	 * @param array  $data   Array of keys and values to be added.
	 * @param string $group  Optional. Where the cache contents are grouped. Default empty.
	 * @param int    $expire Optional. When the cache data should expire, in seconds. Default 0 (no expiration).
	 * @return bool[] Array of return values, grouped by key.
	 */
	function wp_cache_add_multiple( $data, $group = '', $expire = 0 ) {
		global $wp_object_cache;
		$values = array();
		foreach ( $data as $key => $value ) {
			$values[ $key ] = wp_cache_add( $key, $value, $group, $expire );
		}
		return $values;
	}

	/**
	 * Deletes multiple values from the cache in one call.
	 *
	 * @param array  $keys  Array of keys to be deleted.
	 * @param string $group Optional. Where the cache contents are grouped. Default empty.
	 * @return bool[] Array of return values, grouped by key.
	 */
	function wp_cache_delete_multiple( $keys, $group = '' ) {
		global $wp_object_cache;
		if ( method_exists( $wp_object_cache, 'delete_multiple' ) ) {
			return $wp_object_cache->delete_multiple( $keys, $group );
		}
		$values = array();
		foreach ( $keys as $key ) {
			$values[ $key ] = wp_cache_delete( $key, $group );
		}
		return $values;
	}

	/**
	 * WP_Object_Cache Redis Manager.
	 *
	 * phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
	 * phpcs:disable Squiz.Classes.ClassFileName.NoMatch
	 * phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed
	 */
	class WP_Object_Cache {

		/**
		 * Holds the cache global groups.
		 *
		 * @var array
		 */
		private $global_groups = array();

		/**
		 * Holds the non-persistent cache groups.
		 *
		 * @var array
		 */
		private $no_mc_groups = array();

		/**
		 * Holds the local runtime cache.
		 *
		 * @var array
		 */
		private $cache = array();

		/**
		 * Holds the Redis client instance.
		 *
		 * @var \Redis
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
		 */
		private function connect_redis() {
			if ( ! class_exists( 'Redis' ) ) {
				return;
			}

			// Read configuration.
			$config_file = WP_CONTENT_DIR . '/wppo-redis-config.php';
			$config      = array();

			if ( file_exists( $config_file ) ) {
				$config = include $config_file; // phpcs:ignore WPThemeReview.CoreFunctionality.FileInclude.FileIncludeFound
			}

			$mode       = apply_filters( 'wppo_redis_mode', isset( $config['mode'] ) ? $config['mode'] : 'standalone' );
			$use_tls    = apply_filters( 'wppo_redis_use_tls', isset( $config['use_tls'] ) ? (bool) $config['use_tls'] : false );
			$password   = apply_filters( 'wppo_redis_password', isset( $config['password'] ) ? $config['password'] : '' );
			$database   = apply_filters( 'wppo_redis_database', isset( $config['database'] ) ? (int) $config['database'] : 0 );
			$timeout    = apply_filters( 'wppo_redis_timeout', 1.0 );
			$persistent = apply_filters( 'wppo_redis_persistent', isset( $config['persistent'] ) ? (bool) $config['persistent'] : false );

			$this->redis         = null;
			$this->redis_replica = null;

			try {
				if ( 'cluster' === $mode && class_exists( 'RedisCluster' ) ) {
					$nodes = isset( $config['nodes'] ) ? (array) $config['nodes'] : array();
					if ( $use_tls ) {
						$nodes = array_map(
							function ( $node ) {
								return ( strpos( $node, 'tls://' ) === 0 ) ? $node : 'tls://' . $node;
							},
							$nodes
						);
					}
					$this->redis           = new \RedisCluster( null, $nodes, $timeout, $timeout, true, $password );
					$this->redis_connected = true;
				} elseif ( 'sentinel' === $mode && class_exists( 'RedisSentinel' ) ) {
					$nodes       = isset( $config['nodes'] ) ? (array) $config['nodes'] : array();
					$master_name = isset( $config['master_name'] ) ? $config['master_name'] : 'mymaster';

					foreach ( $nodes as $node ) {
						list( $s_host, $s_port ) = array_pad( explode( ':', $node ), 2, 26379 );
						try {
							$sentinel = new \RedisSentinel(
								array(
									'host' => $s_host,
									'port' => (int) $s_port,
								)
							);
							$address  = $sentinel->getMasterAddrByName( $master_name );
							if ( $address ) {
								$this->redis = new \Redis();
								$host        = $use_tls ? 'tls://' . $address[0] : $address[0];
								if ( $this->redis->connect( $host, (int) $address[1], $timeout ) ) {
									if ( ! empty( $password ) && $this->redis->auth( $password ) === false ) {
										$this->redis->close();
										continue;
									}
									$this->redis->select( $database );
									$this->redis_connected = true;
									break;
								}
							}
						} catch ( \Exception $e ) {
							continue;
						}
					}
				} else {
					// Standalone default connection.
					$host = apply_filters( 'wppo_redis_host', isset( $config['host'] ) ? $config['host'] : '127.0.0.1' );
					$port = apply_filters( 'wppo_redis_port', isset( $config['port'] ) ? (int) $config['port'] : 6379 );
					if ( $use_tls && strpos( $host, 'tls://' ) !== 0 ) {
						$host = 'tls://' . $host;
					}

					$this->redis  = new \Redis();
					$connect_func = $persistent ? 'pconnect' : 'connect';
					if ( $this->redis->$connect_func( $host, $port, $timeout ) ) {
						if ( ! empty( $password ) && $this->redis->auth( $password ) === false ) {
							$this->redis->close();
							return;
						}
						$this->redis->select( $database );
						$this->redis_connected = true;

						// Standalone replica support.
						if ( isset( $config['replicas'] ) && is_array( $config['replicas'] ) && ! empty( $config['replicas'] ) ) {
							$replica = $config['replicas'][ array_rand( $config['replicas'] ) ];
							$r_host  = isset( $replica['host'] ) ? $replica['host'] : '127.0.0.1';
							$r_port  = isset( $replica['port'] ) ? (int) $replica['port'] : 6379;
							$r_pass  = isset( $replica['password'] ) ? $replica['password'] : $password;
							try {
								$tmp_replica = new \Redis();
								if ( $use_tls && strpos( $r_host, 'tls://' ) !== 0 ) {
									$r_host = 'tls://' . $r_host;
								}
								if ( $tmp_replica->connect( $r_host, $r_port, $timeout ) ) {
									if ( ! empty( $r_pass ) ) {
										$tmp_replica->auth( $r_pass );
									}
									$tmp_replica->select( $database );
									$this->redis_replica = $tmp_replica;
								}
							} catch ( \Exception $e ) {
								$this->redis_replica = null;
							}
						}
					}
				}

				if ( $this->redis_connected && $this->redis ) {
					$serializer = defined( '\Redis::SERIALIZER_IGBINARY' ) ? \Redis::SERIALIZER_IGBINARY : \Redis::SERIALIZER_PHP;
					$this->redis->setOption( \Redis::OPT_SERIALIZER, $serializer );
					if ( $this->redis_replica ) {
						$this->redis_replica->setOption( \Redis::OPT_SERIALIZER, $serializer );
					}

					// Apply compression if configured.
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
							$this->redis->setOption( \Redis::OPT_COMPRESSION, $compression_type );
							if ( $this->redis_replica ) {
								$this->redis_replica->setOption( \Redis::OPT_COMPRESSION, $compression_type );
							}
						}
					}
				}
			} catch ( \Exception $e ) {
				$this->redis_connected = false;
				$this->redis_replica   = null;
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
							if ( ! empty( $keys ) ) {
								$this->redis->del( $keys );
							}
						} while ( 0 !== $cursor );
					}
					return true;
				}

				$cursor = null;
				do {
					$keys = $this->redis->scan( $cursor, $pattern, 100 );
					if ( ! empty( $keys ) ) {
						$this->redis->del( $keys );
					}
				} while ( 0 !== $cursor );

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
		 * @return bool|int New value or false.
		 */
		public function increase( $key, $offset = 1, $group = 'default' ) {
			if ( in_array( $group, $this->no_mc_groups, true ) || ! $this->redis_connected ) {
				return false; // Memcached/Redis specific feature only.
			}

			$formatted_key = $this->get_key( $key, $group );
			return $this->redis->incrBy( $formatted_key, $offset );
		}

		/**
		 * Decreases a cached value.
		 *
		 * @param int|string $key    Cache key.
		 * @param int        $offset Offset amount.
		 * @param string     $group  Cache group.
		 * @return bool|int New value or false.
		 */
		public function decrease( $key, $offset = 1, $group = 'default' ) {
			if ( in_array( $group, $this->no_mc_groups, true ) || ! $this->redis_connected ) {
				return false;
			}

			$formatted_key = $this->get_key( $key, $group );
			return $this->redis->decrBy( $formatted_key, $offset );
		}

		/**
		 * Adds global groups.
		 *
		 * @param array $groups Global groups.
		 */
		public function add_global_groups( $groups ) {
			$groups              = (array) $groups;
			$this->global_groups = array_merge( $this->global_groups, $groups );
			$this->global_groups = array_unique( $this->global_groups );
		}

		/**
		 * Adds non persistent groups.
		 *
		 * @param array $groups Non persistent groups.
		 */
		public function add_non_persistent_groups( $groups ) {
			$groups             = (array) $groups;
			$this->no_mc_groups = array_merge( $this->no_mc_groups, $groups );
			$this->no_mc_groups = array_unique( $this->no_mc_groups );
		}

		/**
		 * Switches to a different blog.
		 *
		 * @param int $blog_id Blog ID.
		 */
		public function switch_to_blog( $blog_id ) {
			$this->blog_prefix = ( is_multisite() ? $blog_id : $GLOBALS['table_prefix'] ) . ':';
		}

		/**
		 * Closes the connection.
		 *
		 * @return bool True.
		 */
		public function close() {
			if ( $this->redis_connected ) {
				$this->redis->close();
				$this->redis_connected = false;
			}
			return true;
		}
	}

endif;
