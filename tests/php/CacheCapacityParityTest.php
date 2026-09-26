<?php
/**
 * P3-005 compatibility tests for the Cache_Capacity extraction.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\Cache_Capacity;
use PerformanceOptimise\Inc\Loader_Map;
use Brain\Monkey\Functions;

/**
 * Pins the public capacity facade and its single accounting owner.
 *
 * @package PerformanceOptimise\Tests
 */
class CacheCapacityParityTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Capacity facade methods retained for current callers.
	 *
	 * @var string[]
	 */
	private array $facade_methods = array(
		'get_cache_size',
		'get_cache_stats',
		'get_cache_cap_settings',
		'get_cache_bytes_and_files',
		'get_cache_size_bytes',
		'get_cache_file_count',
		'is_randomized_query_asset',
		'get_cache_cap_status',
		'maybe_enforce_cache_cap',
		'evict_oldest_cache_entries',
		'evict_oldest_cache_files_by_count',
	);

	/**
	 * Byte/file accounting uses one shared walk and counts only index.html.
	 *
	 * @return void
	 */
	public function test_single_walk_counts_all_bytes_but_only_cached_pages(): void {
		Functions\when( 'trailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/\\' ) . '/';
			}
		);
		$filesystem = new WPPO_Capacity_Filesystem_Harness();
		$cache      = new WPPO_Capacity_Cache_Harness( $filesystem );
		$capacity   = new Cache_Capacity( $cache );

		$this->assertSame(
			array(
				'bytes' => 84,
				'files' => 2,
			),
			$capacity->get_cache_bytes_and_files()
		);
		$this->assertSame( 3, $filesystem->dirlist_calls, 'One recursive walk must enumerate each directory once.' );
	}

	/**
	 * Oldest eviction uses mtime order and existing sibling-aware size totals.
	 *
	 * @return void
	 */
	public function test_oldest_eviction_deletes_oldest_entry_first(): void {
		Functions\when( 'trailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/\\' ) . '/';
			}
		);
		$filesystem = new WPPO_Capacity_Filesystem_Harness();
		$cache      = new WPPO_Capacity_Cache_Harness( $filesystem );
		$capacity   = new Cache_Capacity( $cache );

		$this->assertSame( 35, $capacity->evict_oldest_cache_entries( 1 ) );
		$this->assertSame(
			array( '/cache/wppo/example.com/old/index.html' ),
			$cache->deleted_files
		);
	}

	/**
	 * The compatibility facade and new owner are both resolvable.
	 *
	 * @return void
	 */
	public function test_capacity_owner_and_public_facade_are_loadable(): void {
		$this->assertTrue( class_exists( Cache_Capacity::class ) );
		foreach ( $this->facade_methods as $method ) {
			$this->assertTrue( method_exists( Cache::class, $method ), $method );
			$this->assertTrue( method_exists( Cache_Capacity::class, $method ), $method );
		}
		$this->assertSame( 'Cache/class-cache-capacity.php', Loader_Map::fallback_map()['Cache_Capacity'] );
		$this->assertSame( Loader_Map::path_for( 'Cache_Capacity' ), Loader_Map::path_for( Cache_Capacity::class ) );
	}

	/**
	 * Defaults and malformed values retain the fail-safe policy.
	 *
	 * @return void
	 */
	public function test_cap_settings_defaults_and_clamping_are_preserved(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) {
				if ( 'wppo_settings' === $name ) {
					return array(
						'cache_settings' => array(
							'cacheMaxSizeMB'            => 0,
							'cacheSizeWarnRatio'        => 1,
							'cacheSizeEnforce'          => 'not-a-boolean',
							'cacheMaxFiles'             => 50,
							'cacheRandomizedQueryGuard' => 'not-a-boolean',
						),
					);
				}
				return $fallback;
			}
		);
		\PerformanceOptimise\Inc\Util::clear_settings_cache();

		$this->assertSame(
			array(
				'max_mb'           => 512,
				'warn_ratio'       => 0.8,
				'enforce'          => true,
				'max_files'        => 100,
				'randomized_guard' => true,
			),
			Cache::get_cache_cap_settings()
		);
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile, PSR1.Classes.ClassDeclaration.MultipleClasses

/**
 * Minimal Cache owner double exposing only capacity bridges.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Capacity_Cache_Harness extends Cache {
	/**
	 * Filesystem double.
	 *
	 * @var object
	 */
	private object $capacity_filesystem_double;

	/**
	 * Deleted cache paths.
	 *
	 * @var string[]
	 */
	public array $deleted_files = array();

	/**
	 * Constructor.
	 *
	 * @param object $filesystem Filesystem double.
	 */
	public function __construct( object $filesystem ) {
		$this->capacity_filesystem_double = $filesystem;
	}

	/**
	 * Capacity filesystem bridge.
	 *
	 * @return object Filesystem double.
	 */
	public function capacity_filesystem(): object|false|null {
		return $this->capacity_filesystem_double;
	}

	/**
	 * Capacity cache root bridge.
	 *
	 * @return string Cache root.
	 */
	public function capacity_cache_root_dir(): string {
		return '/cache/wppo';
	}

	/**
	 * Capacity domain bridge.
	 *
	 * @return string Cache domain.
	 */
	public function capacity_domain(): string {
		return 'example.com';
	}

	/**
	 * Capacity containment bridge.
	 *
	 * @param string $path Path.
	 * @return bool Always contained in this test double.
	 */
	public function capacity_is_path_contained( string $path ): bool {
		return '' !== $path;
	}

	/**
	 * Capacity deletion bridge.
	 *
	 * @param string $file_path File path.
	 * @return bool Always succeeds.
	 */
	public function capacity_delete_cache_files( string $file_path ): bool {
		$this->deleted_files[] = $file_path;
		return true;
	}
}

/**
 * In-memory directory listing for capacity accounting.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Capacity_Filesystem_Harness {
	/**
	 * Number of dirlist calls.
	 *
	 * @var int
	 */
	public int $dirlist_calls = 0;

	/**
	 * Whether a directory exists.
	 *
	 * @param string $path Directory path.
	 * @return bool Always true.
	 */
	public function is_dir( string $path ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return true;
	}

	/**
	 * Return deterministic directory entries.
	 *
	 * @param string $directory Directory path.
	 * @return array<int,array<string,mixed>>|false Directory entries.
	 */
	public function dirlist( string $directory ) {
		++$this->dirlist_calls;
		$directory = rtrim( $directory, '/' );
		if ( '/cache/wppo' === $directory ) {
			return array(
				array(
					'name' => 'example.com',
					'type' => 'd',
				),
			);
		}
		if ( '/cache/wppo/example.com' === $directory ) {
			return array(
				array(
					'name' => 'old',
					'type' => 'd',
				),
				array(
					'name' => 'new',
					'type' => 'd',
				),
			);
		}
		$old = str_ends_with( $directory, '/old' );
		return array(
			array(
				'name'        => 'index.html',
				'type'        => 'f',
				'size'        => $old ? 30 : 40,
				'lastmodunix' => $old ? 10 : 20,
			),
			array(
				'name' => 'other.css',
				'type' => 'f',
				'size' => 7,
			),
		);
	}

	/**
	 * File size.
	 *
	 * @param string $path File path.
	 * @return int Synthetic size.
	 */
	public function size( string $path ): int {
		if ( str_ends_with( $path, '.gz' ) ) {
			return 5;
		}
		return str_contains( $path, 'old' ) ? 30 : 40;
	}

	/**
	 * File mtime.
	 *
	 * @param string $path File path.
	 * @return int Synthetic mtime.
	 */
	public function mtime( string $path ): int {
		return str_contains( $path, 'old' ) ? 10 : 20;
	}

	/**
	 * Sibling existence.
	 *
	 * @param string $path File path.
	 * @return bool Whether the sibling exists.
	 */
	public function exists( string $path ): bool {
		return str_ends_with( $path, '.gz' ) && str_contains( $path, 'old' );
	}
}

// phpcs:enable Generic.Files.OneObjectStructurePerFile, PSR1.Classes.ClassDeclaration.MultipleClasses
