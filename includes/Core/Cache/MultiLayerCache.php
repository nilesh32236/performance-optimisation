<?php
/**
 * Multi-Layer Cache System
 *
 * @package PerformanceOptimisation\Core\Cache
 * @since   2.1.0
 */

namespace PerformanceOptimisation\Core\Cache;

use PerformanceOptimisation\Interfaces\CacheInterface;
use PerformanceOptimisation\Utils\LoggingUtil;

/**
 * Multi-Layer Cache Implementation
 *
 * Provides L1 (Memory), L2 (Redis/Memcached), L3 (File) caching layers
 */
class MultiLayerCache implements CacheInterface {

	private array $layers         = array();
	private array $stats          = array(
		'l1_hits' => 0,
		'l2_hits' => 0,
		'l3_hits' => 0,
		'misses'  => 0,
		'sets'    => 0,
	);
	private array $memory_cache   = array();
	private int $max_memory_items = 1000;

	public function __construct() {
		$this->initializeLayers();
	}

	private function initializeLayers(): void {
		$this->layers['memory'] = true;

		if ( class_exists( 'Redis' ) && extension_loaded( 'redis' ) ) {
			$this->layers['redis'] = $this->initializeRedis();
		} elseif ( class_exists( 'Memcached' ) && extension_loaded( 'memcached' ) ) {
			$this->layers['memcached'] = $this->initializeMemcached();
		}

		$this->layers['file'] = new FileCache();
	}

	public function get( string $key, $default = null ) {
		// L1: Memory
		if ( isset( $this->memory_cache[ $key ] ) ) {
			$data = $this->memory_cache[ $key ];
			if ( $data['expires'] === 0 || $data['expires'] > time() ) {
				++$this->stats['l1_hits'];
				return $data['value'];
			}
			unset( $this->memory_cache[ $key ] );
		}

		// L2: Redis/Memcached
		if ( isset( $this->layers['redis'] ) ) {
			$value = $this->layers['redis']->get( $key );
			if ( $value !== false ) {
				++$this->stats['l2_hits'];
				$this->setMemoryCache( $key, $value, 300 );
				return $value;
			}
		}

		// L3: File
		if ( isset( $this->layers['file'] ) ) {
			$value = $this->layers['file']->get( $key, null );
			if ( $value !== null ) {
				++$this->stats['l3_hits'];
				$this->setMemoryCache( $key, $value, 300 );
				if ( isset( $this->layers['redis'] ) ) {
					$this->layers['redis']->setex( $key, 3600, $value );
				}
				return $value;
			}
		}

		++$this->stats['misses'];
		return $default;
	}

	public function set( string $key, $value, int $ttl = 3600 ): bool {
		++$this->stats['sets'];

		$this->setMemoryCache( $key, $value, min( $ttl, 300 ) );

		if ( isset( $this->layers['redis'] ) ) {
			$this->layers['redis']->setex( $key, $ttl, $value );
		}

		if ( isset( $this->layers['file'] ) ) {
			$this->layers['file']->set( $key, $value, $ttl );
		}

		return true;
	}

	public function delete( string $key ): bool {
		unset( $this->memory_cache[ $key ] );

		if ( isset( $this->layers['redis'] ) ) {
			$this->layers['redis']->del( $key );
		}

		if ( isset( $this->layers['file'] ) ) {
			$this->layers['file']->delete( $key );
		}

		return true;
	}

	public function clear(): bool {
		$this->memory_cache = array();

		if ( isset( $this->layers['redis'] ) ) {
			$this->layers['redis']->flushAll();
		}

		if ( isset( $this->layers['file'] ) ) {
			$this->layers['file']->clear();
		}

		return true;
	}

	private function setMemoryCache( string $key, $value, int $ttl ): void {
		if ( count( $this->memory_cache ) >= $this->max_memory_items ) {
			$this->memory_cache = array_slice( $this->memory_cache, -900, null, true );
		}

		$this->memory_cache[ $key ] = array(
			'value'   => $value,
			'expires' => $ttl > 0 ? time() + $ttl : 0,
		);
	}

	private function initializeRedis() {
		try {
			$redis = new \Redis();
			$redis->connect( '127.0.0.1', 6379 );
			return $redis;
		} catch ( \Exception $e ) {
			LoggingUtil::log( 'Redis connection failed: ' . $e->getMessage(), 'warning' );
			return false;
		}
	}

	private function initializeMemcached() {
		try {
			$memcached = new \Memcached();
			$memcached->addServer( '127.0.0.1', 11211 );
			return $memcached;
		} catch ( \Exception $e ) {
			LoggingUtil::log( 'Memcached connection failed: ' . $e->getMessage(), 'warning' );
			return false;
		}
	}

	public function getStats(): array {
		$total    = array_sum( $this->stats );
		$hit_rate = $total > 0 ?
			( ( $this->stats['l1_hits'] + $this->stats['l2_hits'] + $this->stats['l3_hits'] ) / $total ) * 100 : 0;

		return array_merge(
			$this->stats,
			array(
				'hit_rate'         => round( $hit_rate, 2 ),
				'total_requests'   => $total,
				'memory_items'     => count( $this->memory_cache ),
				'available_layers' => array_keys( array_filter( $this->layers ) ),
			)
		);
	}

	public function warmUp( array $critical_keys = array() ): void {
		foreach ( $critical_keys as $key => $generator ) {
			if ( ! $this->get( $key ) ) {
				$value = is_callable( $generator ) ? $generator() : $generator;
				$this->set( $key, $value, 3600 );
			}
		}
	}
}
