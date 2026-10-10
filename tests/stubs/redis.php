<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName,Generic.Files.OneObjectStructurePerFile,WordPress.NamingConventions.ValidFunctionName,PSR2.Methods.MethodDeclaration,Squiz.Commenting.FunctionComment,Generic.Commenting.DocComment,Generic.CodeAnalysis.UnusedFunctionParameter,Generic.Formatting.MultipleStatementAlignment -- Psalm-only stub: names mirror phpredis, bodies ignore params.
/**
 * Psalm-only stubs for the phpredis extension and WordPress content constants.
 *
 * This file is NEVER loaded at runtime: it exists solely so the Psalm
 * security scan (psalm.xml <stubs>) can resolve the `Redis`, `RedisCluster`
 * and `RedisSentinel` symbols plus `WP_CONTENT_DIR` without the phpredis
 * PHP extension installed in the scan image. Every runtime call site keeps
 * its `class_exists()` / `defined()` guards, so behaviour on hosts without
 * phpredis is unchanged.
 *
 * Only the subset of the phpredis API used by this plugin is declared, with
 * signatures matching the extension's documented behaviour. Return types are
 * deliberately narrow (`bool` for `auth()`/`select()`/`exists()`) so strict
 * comparisons in the callers analyse cleanly.
 *
 * Excluded from the release ZIP via /tests/ (.distignore). Method names
 * intentionally mirror the extension (setOption, incrBy, …) instead of
 * WordPress conventions — see the file-level phpcs:disable below.
 *
 * @package PerformanceOptimise
 * @since NEXT
 */

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', '/tmp/wordpress/wp-content' );
}

if ( ! class_exists( 'Redis' ) ) {
	/**
	 * Psalm stub of the phpredis Redis client (standalone mode).
	 *
	 * @since NEXT
	 */
	class Redis {
		public const OPT_SERIALIZER     = 1;
		public const SERIALIZER_NONE    = 0;
		public const SERIALIZER_PHP     = 1;
		public const SERIALIZER_IGBINARY = 2;
		public const SERIALIZER_MSGPACK = 3;
		public const OPT_COMPRESSION    = 10;
		public const COMPRESSION_NONE   = 0;
		public const COMPRESSION_LZF    = 1;
		public const COMPRESSION_ZSTD   = 2;
		public const COMPRESSION_LZ4    = 3;
		public const OPT_SSL_VERIFY_PEER = 11;
		public const OPT_SSL_CAFILE     = 12;
		public const MULTI              = 1;
		public const PIPELINE           = 2;

		/**
		 * @param string $host
		 * @param int $port
		 * @param float $timeout
		 * @return bool
		 */
		public function connect( $host, $port = 6379, $timeout = 0.0 ) {
			return true;
		}

		/**
		 * @param string $host
		 * @param int $port
		 * @param float $timeout
		 * @return bool
		 */
		public function pconnect( $host, $port = 6379, $timeout = 0.0 ) {
			return true;
		}

		/**
		 * @param mixed $password
		 * @return bool
		 */
		public function auth( $password ) {
			return true;
		}

		/**
		 * @param int $db
		 * @return bool
		 */
		public function select( $db ) {
			return true;
		}

		/**
		 * @return bool
		 */
		public function close() {
			return true;
		}

		/**
		 * @param string $message
		 * @return string|bool
		 */
		public function ping( $message = null ) {
			return true;
		}

		/**
		 * @return array
		 */
		public function info() {
			return array();
		}

		/**
		 * @param int $option
		 * @param mixed $value
		 * @return bool
		 */
		public function setOption( $option, $value ) {
			return true;
		}

		/**
		 * @param string $key
		 * @param mixed $value
		 * @param mixed $options
		 * @return bool
		 */
		public function set( $key, $value, $options = null ) {
			return true;
		}

		/**
		 * @param string $key
		 * @param mixed $value
		 * @return bool
		 */
		public function setnx( $key, $value ) {
			return true;
		}

		/**
		 * @param string $key
		 * @param int $expire
		 * @param mixed $value
		 * @return bool
		 */
		public function setex( $key, $expire, $value ) {
			return true;
		}

		/**
		 * @param string $key
		 * @return mixed
		 */
		public function get( $key ) {
			return false;
		}

		/**
		 * @param array $keys
		 * @return array|false
		 */
		public function mGet( $keys ) {
			return array();
		}

		/**
		 * @param array $data
		 * @return bool
		 */
		public function mSet( $data ) {
			return true;
		}

		/**
		 * @param int $mode
		 * @return static
		 */
		public function multi( $mode = 2 ) {
			return $this;
		}

		/**
		 * @return array|false
		 */
		public function exec() {
			return array();
		}

		/**
		 * @param mixed $keys
		 * @return int|false
		 */
		public function del( $keys ) {
			return 1;
		}

		/**
		 * @param mixed $key
		 * @return bool
		 */
		public function exists( $key ) {
			return true;
		}

		/**
		 * @return bool
		 */
		public function flushDb() {
			return true;
		}

		/**
		 * @param mixed $iterator
		 * @param mixed ...$args
		 * @return array|false
		 */
		public function scan( &$iterator, ...$args ) {
			return array();
		}

		/**
		 * @param string $key
		 * @param int $value
		 * @return int|false
		 */
		public function incrBy( $key, $value ) {
			return 1;
		}

		/**
		 * @param string $key
		 * @param int $value
		 * @return int|false
		 */
		public function decrBy( $key, $value ) {
			return 1;
		}
	}
}

if ( ! class_exists( 'RedisCluster' ) ) {
	/**
	 * Psalm stub of the phpredis RedisCluster client (cluster mode).
	 *
	 * @since NEXT
	 */
	class RedisCluster {
		public const OPT_SERIALIZER     = 1;
		public const SERIALIZER_NONE    = 0;
		public const SERIALIZER_PHP     = 1;
		public const SERIALIZER_IGBINARY = 2;
		public const SERIALIZER_MSGPACK = 3;
		public const OPT_COMPRESSION    = 10;
		public const COMPRESSION_NONE   = 0;
		public const COMPRESSION_LZF    = 1;
		public const COMPRESSION_ZSTD   = 2;
		public const COMPRESSION_LZ4    = 3;
		public const OPT_SSL_VERIFY_PEER = 11;
		public const OPT_SSL_CAFILE     = 12;
		public const MULTI              = 1;
		public const PIPELINE           = 2;

		/**
		 * @param string|null $name
		 * @param array $seeds
		 * @param float $timeout
		 * @param float $read_timeout
		 * @param bool $persistent
		 * @param mixed $auth
		 */
		public function __construct( $name, $seeds, $timeout = 0.0, $read_timeout = 0.0, $persistent = false, $auth = null ) {
		}

		/**
		 * @return bool
		 */
		public function close() {
			return true;
		}

		/**
		 * @param string $message
		 * @return string|bool
		 */
		public function ping( $message = null ) {
			return true;
		}

		/**
		 * @return array
		 */
		public function info() {
			return array();
		}

		/**
		 * @return array
		 */
		public function _masters() {
			return array();
		}

		/**
		 * @param int $option
		 * @param mixed $value
		 * @return bool
		 */
		public function setOption( $option, $value ) {
			return true;
		}

		/**
		 * @param string $key
		 * @param mixed $value
		 * @param mixed $options
		 * @return bool
		 */
		public function set( $key, $value, $options = null ) {
			return true;
		}

		/**
		 * @param string $key
		 * @param int $expire
		 * @param mixed $value
		 * @return bool
		 */
		public function setex( $key, $expire, $value ) {
			return true;
		}

		/**
		 * @param string $key
		 * @return mixed
		 */
		public function get( $key ) {
			return false;
		}

		/**
		 * @param array $keys
		 * @return array|false
		 */
		public function mGet( $keys ) {
			return array();
		}

		/**
		 * @param array $data
		 * @return bool
		 */
		public function mSet( $data ) {
			return true;
		}

		/**
		 * @param int $mode
		 * @return static
		 */
		public function multi( $mode = 2 ) {
			return $this;
		}

		/**
		 * @return array|false
		 */
		public function exec() {
			return array();
		}

		/**
		 * @param mixed $keys
		 * @return int|false
		 */
		public function del( $keys ) {
			return 1;
		}

		/**
		 * @param mixed $key
		 * @return bool
		 */
		public function exists( $key ) {
			return true;
		}

		/**
		 * @return bool
		 */
		public function flushDb() {
			return true;
		}

		/**
		 * @param mixed $iterator
		 * @param mixed ...$args
		 * @return array|false
		 */
		public function scan( &$iterator, ...$args ) {
			return array();
		}

		/**
		 * @param string $key
		 * @param int $value
		 * @return int|false
		 */
		public function incrBy( $key, $value ) {
			return 1;
		}

		/**
		 * @param string $key
		 * @param int $value
		 * @return int|false
		 */
		public function decrBy( $key, $value ) {
			return 1;
		}
	}
}

if ( ! class_exists( 'RedisSentinel' ) ) {
	/**
	 * Psalm stub of the phpredis RedisSentinel client (sentinel mode).
	 *
	 * @since NEXT
	 */
	class RedisSentinel {
		/**
		 * @param array $options
		 */
		public function __construct( $options ) {
		}

		/**
		 * @param string $master
		 * @return array|false
		 */
		public function getMasterAddrByName( $master ) {
			return array( '127.0.0.1', 6379 );
		}
	}
}
