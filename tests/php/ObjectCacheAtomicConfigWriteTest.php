<?php
/**
 * Tests for atomic Redis config writes (issue #1202).
 *
 * Covers Object_Cache::write_config_atomic() (tmp-write + verify + rename)
 * and the enable() wiring: the happy path publishes verified config without
 * ever clobbering the live file directly, an interrupted rename leaves the
 * original bytes intact, and verification/rename failures return WP_Error
 * with the old config untouched.
 *
 * @package PerformanceOptimise\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Test fixtures are co-located by convention.
// phpcs:disable WordPress.Files.FileName -- Fixture class names cannot derive the *Test.php file name.

use PerformanceOptimise\Inc\Object_Cache;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * In-memory WP_Filesystem stand-in for atomic config-write tests.
 *
 * Tracks the live config separately from staged tmp/backup siblings so tests
 * can prove the live bytes are only ever replaced by a verified rename.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Atomic_Config_FS_Mock {
	/**
	 * Live config contents.
	 *
	 * @var string
	 */
	public $live = '';

	/**
	 * Whether the live config exists.
	 *
	 * @var bool
	 */
	public $live_exists = false;

	/**
	 * Staged sibling contents keyed by path.
	 *
	 * @var array<string, string>
	 */
	public $files = array();

	/**
	 * Whether put_contents succeeds (false simulates disk-full).
	 *
	 * @var bool
	 */
	public $put_result = true;

	/**
	 * Whether move succeeds (false simulates an interrupted rename).
	 *
	 * @var bool
	 */
	public $move_result = true;

	/**
	 * Whether copy succeeds.
	 *
	 * @var bool
	 */
	public $copy_result = true;

	/**
	 * When true, staged tmp reads return truncated bytes (torn write).
	 *
	 * @var bool
	 */
	public $corrupt_tmp_read = false;

	/**
	 * Every put_contents destination, in call order.
	 *
	 * @var string[]
	 */
	public $put_log = array();

	/**
	 * Every move (src, dst) pair, in call order.
	 *
	 * @var array<int, array{0:string,1:string}>
	 */
	public $move_log = array();

	/**
	 * Every copy destination, in call order.
	 *
	 * @var string[]
	 */
	public $copy_log = array();

	/**
	 * Every deleted path, in call order.
	 *
	 * @var string[]
	 */
	public $deleted = array();

	/**
	 * Live config path (mirrors Object_Cache::__construct()).
	 *
	 * @return string
	 */
	public function live_path(): string {
		return WP_CONTENT_DIR . '/wppo-redis-config.php';
	}

	/**
	 * Whether a path is a config staging sibling.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function is_tmp_path( $path ): bool {
		return 0 === strpos( (string) $path, $this->live_path() . '.tmp' );
	}

	/**
	 * Staged tmp siblings still present.
	 *
	 * @return string[]
	 */
	public function tmp_leftovers(): array {
		$left = array();
		foreach ( $this->files as $path => $contents ) {
			unset( $contents );
			if ( $this->is_tmp_path( $path ) ) {
				$left[] = $path;
			}
		}
		return $left;
	}

	/**
	 * Check existence.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function exists( $path ) {
		if ( $path === $this->live_path() ) {
			return $this->live_exists;
		}
		return isset( $this->files[ $path ] );
	}

	/**
	 * Read contents.
	 *
	 * @param string $path Path.
	 * @return string|false
	 */
	public function get_contents( $path ) {
		if ( $path === $this->live_path() ) {
			return $this->live_exists ? $this->live : false;
		}
		if ( ! isset( $this->files[ $path ] ) ) {
			return false;
		}
		$stored = $this->files[ $path ];
		if ( $this->corrupt_tmp_read && $this->is_tmp_path( $path ) && is_string( $stored ) ) {
			return (string) substr( $stored, 0, (int) ( strlen( $stored ) / 2 ) );
		}
		return $stored;
	}

	/**
	 * Write contents.
	 *
	 * @param string $path     Path.
	 * @param string $contents Contents.
	 * @param int    $chmod    Mode.
	 * @return bool
	 */
	public function put_contents( $path, $contents, $chmod = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->put_log[] = $path;
		if ( ! $this->put_result ) {
			return false;
		}
		if ( $path === $this->live_path() ) {
			$this->live        = $contents;
			$this->live_exists = true;
		} else {
			$this->files[ $path ] = $contents;
		}
		return true;
	}

	/**
	 * Move src to dst.
	 *
	 * @param string $src       Source.
	 * @param string $dst       Destination.
	 * @param bool   $overwrite Overwrite.
	 * @return bool
	 */
	public function move( $src, $dst, $overwrite = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->move_log[] = array( $src, $dst );
		if ( ! $this->move_result ) {
			return false;
		}
		$content = $src === $this->live_path() ? $this->live : ( $this->files[ $src ] ?? false );
		if ( false === $content ) {
			return false;
		}
		if ( $dst === $this->live_path() ) {
			$this->live        = $content;
			$this->live_exists = true;
		} else {
			$this->files[ $dst ] = $content;
		}
		unset( $this->files[ $src ] );
		$this->deleted[] = $src;
		return true;
	}

	/**
	 * Copy src to dst.
	 *
	 * @param string $src       Source.
	 * @param string $dst       Destination.
	 * @param bool   $overwrite Overwrite.
	 * @param int    $chmod     Mode.
	 * @return bool
	 */
	public function copy( $src, $dst, $overwrite = false, $chmod = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->copy_log[] = $dst;
		if ( ! $this->copy_result ) {
			return false;
		}
		$content = $src === $this->live_path() ? $this->live : ( $this->files[ $src ] ?? '' );
		if ( $dst === $this->live_path() ) {
			$this->live        = $content;
			$this->live_exists = true;
		} else {
			$this->files[ $dst ] = $content;
		}
		return true;
	}

	/**
	 * Delete a path.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function delete( $path ) {
		$this->deleted[] = $path;
		if ( $path === $this->live_path() ) {
			$this->live        = '';
			$this->live_exists = false;
		}
		unset( $this->files[ $path ] );
		return true;
	}

	/**
	 * List staged siblings under a directory.
	 *
	 * @param string $path Directory.
	 * @return array<string, array>
	 */
	public function dirlist( $path ) {
		$out = array();
		foreach ( $this->files as $file_path => $contents ) {
			unset( $contents );
			if ( dirname( $file_path ) === $path ) {
				$base         = basename( $file_path );
				$out[ $base ] = array(
					'name' => $base,
					'type' => 'f',
				);
			}
		}
		return $out;
	}
}

/**
 * Minimal-transport filesystem stand-in forcing the manual fallback.
 *
 * Deliberately exposes only put_contents()/get_contents()/move()/delete()
 * (+ dirlist for the orphan sweep) — no exists()/copy() — so
 * Util::atomic_write_php_verified() returns null and
 * Object_Cache::write_config_atomic() exercises its manual tmp + verify +
 * rename path instead of the shared verified writer.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Atomic_Config_Minimal_FS_Mock {
	/**
	 * Live config contents.
	 *
	 * @var string
	 */
	public $live = '';

	/**
	 * Whether the live config exists.
	 *
	 * @var bool
	 */
	public $live_exists = false;

	/**
	 * Staged sibling contents keyed by path.
	 *
	 * @var array<string, string>
	 */
	public $files = array();

	/**
	 * Whether put_contents succeeds (false simulates disk-full).
	 *
	 * @var bool
	 */
	public $put_result = true;

	/**
	 * Whether move succeeds (false simulates an interrupted rename).
	 *
	 * @var bool
	 */
	public $move_result = true;

	/**
	 * When true, staged tmp reads return truncated bytes (torn write).
	 *
	 * @var bool
	 */
	public $corrupt_tmp_read = false;

	/**
	 * When true, move() publishes truncated bytes (torn copy+delete transport).
	 *
	 * @var bool
	 */
	public $torn_live_after_move = false;

	/**
	 * Every put_contents destination, in call order.
	 *
	 * @var string[]
	 */
	public $put_log = array();

	/**
	 * Every move (src, dst) pair, in call order.
	 *
	 * @var array<int, array{0:string,1:string}>
	 */
	public $move_log = array();

	/**
	 * Every deleted path, in call order.
	 *
	 * @var string[]
	 */
	public $deleted = array();

	/**
	 * Live config path (mirrors Object_Cache::__construct()).
	 *
	 * @return string
	 */
	public function live_path(): string {
		return WP_CONTENT_DIR . '/wppo-redis-config.php';
	}

	/**
	 * Whether a path is a config staging sibling.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function is_tmp_path( $path ): bool {
		return 0 === strpos( (string) $path, $this->live_path() . '.tmp' );
	}

	/**
	 * Staged tmp siblings still present.
	 *
	 * @return string[]
	 */
	public function tmp_leftovers(): array {
		$left = array();
		foreach ( $this->files as $path => $contents ) {
			unset( $contents );
			if ( $this->is_tmp_path( $path ) ) {
				$left[] = $path;
			}
		}
		return $left;
	}

	/**
	 * Read contents.
	 *
	 * @param string $path Path.
	 * @return string|false
	 */
	public function get_contents( $path ) {
		if ( $path === $this->live_path() ) {
			return $this->live_exists ? $this->live : false;
		}
		if ( ! isset( $this->files[ $path ] ) ) {
			return false;
		}
		$stored = $this->files[ $path ];
		if ( $this->corrupt_tmp_read && $this->is_tmp_path( $path ) && is_string( $stored ) ) {
			return (string) substr( $stored, 0, (int) ( strlen( $stored ) / 2 ) );
		}
		return $stored;
	}

	/**
	 * Write contents.
	 *
	 * @param string $path     Path.
	 * @param string $contents Contents.
	 * @param int    $chmod    Mode.
	 * @return bool
	 */
	public function put_contents( $path, $contents, $chmod = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->put_log[] = $path;
		if ( ! $this->put_result ) {
			return false;
		}
		if ( $path === $this->live_path() ) {
			$this->live        = $contents;
			$this->live_exists = true;
		} else {
			$this->files[ $path ] = $contents;
		}
		return true;
	}

	/**
	 * Move src to dst.
	 *
	 * @param string $src       Source.
	 * @param string $dst       Destination.
	 * @param bool   $overwrite Overwrite.
	 * @return bool
	 */
	public function move( $src, $dst, $overwrite = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->move_log[] = array( $src, $dst );
		if ( ! $this->move_result ) {
			return false;
		}
		$content = $src === $this->live_path() ? $this->live : ( $this->files[ $src ] ?? false );
		if ( false === $content ) {
			return false;
		}
		if ( $this->torn_live_after_move && $dst === $this->live_path() && is_string( $content ) ) {
			$content = (string) substr( $content, 0, (int) ( strlen( $content ) / 2 ) );
		}
		if ( $dst === $this->live_path() ) {
			$this->live        = $content;
			$this->live_exists = true;
		} else {
			$this->files[ $dst ] = $content;
		}
		unset( $this->files[ $src ] );
		$this->deleted[] = $src;
		return true;
	}

	/**
	 * Delete a path.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function delete( $path ) {
		$this->deleted[] = $path;
		if ( $path === $this->live_path() ) {
			$this->live        = '';
			$this->live_exists = false;
		}
		unset( $this->files[ $path ] );
		return true;
	}

	/**
	 * List staged siblings under a directory.
	 *
	 * @param string $path Directory.
	 * @return array<string, array>
	 */
	public function dirlist( $path ) {
		$out = array();
		foreach ( $this->files as $file_path => $contents ) {
			unset( $contents );
			if ( dirname( $file_path ) === $path ) {
				$base         = basename( $file_path );
				$out[ $base ] = array(
					'name' => $base,
					'type' => 'f',
				);
			}
		}
		return $out;
	}
}

/**
 * Scriptable Object_Cache replacing the Redis network edge for enable().
 *
 * Only ping() and get_status() are scripted — the config publish path
 * (write_config_atomic, drop-in copy, circuit cleanup) runs for real
 * against the in-memory filesystem mock.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Atomic_Enable_Cache extends Object_Cache {
	/**
	 * Scripted ping() result.
	 *
	 * @var bool|\WP_Error
	 */
	public static $ping_result = true;

	/**
	 * Scripted ping.
	 *
	 * @param array $config Connection configuration.
	 * @return bool|\WP_Error
	 */
	public function ping( $config = array() ) {
		return self::$ping_result;
	}

	/**
	 * Scripted status without touching the filesystem or Redis.
	 *
	 * @return array
	 */
	public function get_status() {
		return array(
			'enabled'         => false,
			'redis_missing'   => false,
			'redis_reachable' => true,
			'foreign_dropin'  => false,
		);
	}
}

/**
 * Atomic Redis config-write tests.
 *
 * @package PerformanceOptimise\Tests
 */
class ObjectCacheAtomicConfigWriteTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Previously active $wp_object_cache stub, restored in tearDown().
	 *
	 * @var mixed
	 */
	private $previous_object_cache = null;

	/**
	 * Files created in the real temp dir, removed in tearDown().
	 *
	 * @var string[]
	 */
	private array $tracked_temp_files = array();

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();

		// enable() finishes with wp_cache_flush() (a real function from the
		// drop-in template loaded in bootstrap). Pin a flush-capable cache
		// stub so earlier suites cannot leak a flush-less one via $GLOBALS.
		$this->previous_object_cache = $GLOBALS['wp_object_cache'] ?? null;
		$GLOBALS['wp_object_cache']  = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			/**
			 * No-op flush for enable() tests.
			 *
			 * @return bool
			 */
			public function flush() {
				return true;
			}
		};
	}

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		$GLOBALS['wp_object_cache'] = $this->previous_object_cache; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		foreach ( $this->tracked_temp_files as $file ) {
			if ( is_string( $file ) && '' !== $file && file_exists( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
				unlink( $file );
			}
		}
		$this->tracked_temp_files              = array();
		WPPO_Atomic_Enable_Cache::$ping_result = true;
		if ( isset( $GLOBALS['wp_filesystem'] ) ) {
			unset( $GLOBALS['wp_filesystem'] );
		}
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Original live config fixture.
	 *
	 * @return string
	 */
	private function original_config(): string {
		return "<?php\nif ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n\nreturn array (\n  'host' => '10.0.0.1',\n  'port' => 6379,\n);\n";
	}

	/**
	 * Fresh staged config fixture.
	 *
	 * @return string
	 */
	private function new_config(): string {
		return "<?php\n/**\n * Auto-generated by Performance Optimisation\n */\n\nif ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n\nreturn array (\n  'host' => '127.0.0.1',\n  'port' => 6379,\n  'mode' => 'standalone',\n);\n";
	}

	/**
	 * Filesystem mock with the original live config installed.
	 *
	 * @return WPPO_Atomic_Config_FS_Mock
	 */
	private function make_fs_with_original(): WPPO_Atomic_Config_FS_Mock {
		$fs              = new WPPO_Atomic_Config_FS_Mock();
		$fs->live        = $this->original_config();
		$fs->live_exists = true;
		return $fs;
	}

	/**
	 * Invoke the private write_config_atomic() helper.
	 *
	 * @param Object_Cache $manager Manager instance.
	 * @param string       $content Config source.
	 * @param object       $fs      Filesystem mock.
	 * @return mixed
	 */
	private function invoke_atomic_write( Object_Cache $manager, string $content, $fs ) {
		$method = new \ReflectionMethod( Object_Cache::class, 'write_config_atomic' );
		return $method->invoke( $manager, $content, $fs );
	}

	/**
	 * Assert that rendered config source includes to an array.
	 *
	 * Mirrors the drop-in's bare `include` of the live file: the published
	 * bytes must boot to an array, never a truncated fatal.
	 *
	 * @param string $source Config source.
	 * @return void
	 */
	private function assert_source_includes_to_array( string $source ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_tempnam -- Test fixture file.
		$tmp = tempnam( sys_get_temp_dir(), 'wppo-redis-cfg' );
		$this->assertNotFalse( $tmp );
		$this->tracked_temp_files[] = $tmp;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture file.
		file_put_contents( $tmp, $source );
		$included = include $tmp; // phpcs:ignore WPThemeReview.CoreFunctionality.FileInclude.FileIncludeFound
		$this->assertIsArray( $included, 'Published config must include to an array.' );
	}

	/**
	 * Config shape validator accepts well-formed source and rejects torn bytes.
	 */
	public function test_valid_config_content_shape(): void {
		$this->assertTrue( Object_Cache::is_valid_config_content( $this->new_config() ) );
		$this->assertTrue( Object_Cache::is_valid_config_content( $this->original_config() ) );
		$this->assertFalse( Object_Cache::is_valid_config_content( '' ) );
		$this->assertFalse( Object_Cache::is_valid_config_content( array( 'host' => 'x' ) ) );
		$this->assertFalse( Object_Cache::is_valid_config_content( 'not php at all' ) );
		$this->assertFalse( Object_Cache::is_valid_config_content( "<?php\nreturn array( 'host' => 'x' );\nif ( ! defined(" ) );
		$this->assertFalse( Object_Cache::is_valid_config_content( "<?php\necho 'no return';" ) );
	}

	/**
	 * Happy path: verified rename publishes config, tmp cleaned, live never clobbered directly.
	 */
	public function test_atomic_publish_happy_path(): void {
		$fs      = $this->make_fs_with_original();
		$manager = new WPPO_Atomic_Enable_Cache();
		$new     = $this->new_config();

		$result = $this->invoke_atomic_write( $manager, $new, $fs );

		$this->assertTrue( $result );
		$this->assertSame( $new, $fs->live, 'Live config must carry the new bytes after a verified rename.' );
		$this->assertSame( array(), $fs->tmp_leftovers(), 'Orphan tmp siblings must be cleaned.' );
		$this->assertNotContains( $fs->live_path(), $fs->put_log, 'The live path must never be written directly (rename-only publish).' );
		$moved_destinations = array_column( $fs->move_log, 1 );
		$this->assertContains( $fs->live_path(), $moved_destinations, 'Publish must go through a rename onto the live path.' );
		$this->assertTrue( Util::verify_php_syntax( $fs->live ), 'Published config must pass the PHP syntax check.' );
		$this->assert_source_includes_to_array( $fs->live );
	}

	/**
	 * Interrupted rename (move fails) leaves the original config bytes intact.
	 */
	public function test_interrupted_rename_keeps_original(): void {
		$fs              = $this->make_fs_with_original();
		$fs->move_result = false;
		$manager         = new WPPO_Atomic_Enable_Cache();

		$result = $this->invoke_atomic_write( $manager, $this->new_config(), $fs );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'write_error', $result->get_error_code() );
		$this->assertSame( $this->original_config(), $fs->live, 'Original config bytes must stay intact when the rename never lands.' );
		$this->assertTrue( $fs->live_exists );
		$this->assertSame( array(), $fs->tmp_leftovers(), 'Failed staging must be cleaned up.' );
	}

	/**
	 * Torn staged bytes (verify mismatch) return WP_Error with the old config untouched.
	 */
	public function test_verify_failure_keeps_original(): void {
		$fs                   = $this->make_fs_with_original();
		$fs->corrupt_tmp_read = true;
		$manager              = new WPPO_Atomic_Enable_Cache();

		$result = $this->invoke_atomic_write( $manager, $this->new_config(), $fs );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'write_error', $result->get_error_code() );
		$this->assertSame( $this->original_config(), $fs->live, 'Original config bytes must stay intact when verification fails.' );
		$this->assertSame( array(), $fs->tmp_leftovers(), 'Unverifiable staging must be cleaned up.' );
	}

	/**
	 * Disk-full (tmp write fails) returns WP_Error with the old config untouched.
	 */
	public function test_disk_full_keeps_original(): void {
		$fs             = $this->make_fs_with_original();
		$fs->put_result = false;
		$manager        = new WPPO_Atomic_Enable_Cache();

		$result = $this->invoke_atomic_write( $manager, $this->new_config(), $fs );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $this->original_config(), $fs->live );
	}

	/**
	 * Invalid source is rejected before touching the filesystem.
	 */
	public function test_invalid_source_rejected_before_write(): void {
		$fs      = $this->make_fs_with_original();
		$manager = new WPPO_Atomic_Enable_Cache();

		$result = $this->invoke_atomic_write( $manager, "<?php\ntruncated if ( ! defined(", $fs );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $this->original_config(), $fs->live );
		$this->assertSame( array(), $fs->put_log, 'Invalid source must never reach the filesystem.' );
	}

	/**
	 * Minimal filesystem mock with the original live config installed.
	 *
	 * @return WPPO_Atomic_Config_Minimal_FS_Mock
	 */
	private function make_minimal_fs_with_original(): WPPO_Atomic_Config_Minimal_FS_Mock {
		$fs              = new WPPO_Atomic_Config_Minimal_FS_Mock();
		$fs->live        = $this->original_config();
		$fs->live_exists = true;
		return $fs;
	}

	/**
	 * Manual fallback happy path: rename-only publish, unique staging tmp.
	 *
	 * The minimal mock lacks exists()/copy(), forcing
	 * Util::atomic_write_php_verified() to return null so the manual
	 * tmp + verify + rename fallback runs instead of the shared writer.
	 */
	public function test_manual_fallback_publish_happy_path(): void {
		$fs      = $this->make_minimal_fs_with_original();
		$manager = new WPPO_Atomic_Enable_Cache();
		$new     = $this->new_config();

		$result = $this->invoke_atomic_write( $manager, $new, $fs );

		$this->assertTrue( $result );
		$this->assertSame( $new, $fs->live, 'Live config must carry the new bytes after a verified rename.' );
		$this->assertSame( array(), $fs->tmp_leftovers(), 'Orphan tmp siblings must be cleaned.' );
		$this->assertNotContains( $fs->live_path(), $fs->put_log, 'The live path must never be written directly (rename-only publish).' );
		$moved_destinations = array_column( $fs->move_log, 1 );
		$this->assertContains( $fs->live_path(), $moved_destinations, 'Publish must go through a rename onto the live path.' );
		$this->assertNotEmpty( $fs->put_log, 'The fallback must stage through a tmp sibling.' );
		$this->assertNotSame( $fs->live_path() . Object_Cache::CONFIG_TMP_SUFFIX, $fs->put_log[0], 'The fallback must stage at a unique tmp sibling, not the shared fixed path.' );
		$this->assertTrue( Util::verify_php_syntax( $fs->live ), 'Published config must pass the PHP syntax check.' );
		$this->assert_source_includes_to_array( $fs->live );
	}

	/**
	 * Manual fallback torn live (non-atomic copy+delete transport) restores the original.
	 */
	public function test_manual_fallback_torn_live_restores_original(): void {
		$fs                       = $this->make_minimal_fs_with_original();
		$fs->torn_live_after_move = true;
		$manager                  = new WPPO_Atomic_Enable_Cache();

		$result = $this->invoke_atomic_write( $manager, $this->new_config(), $fs );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'write_error', $result->get_error_code() );
		$this->assertSame( $this->original_config(), $fs->live, 'A torn live file must be restored from the in-memory original.' );
		$this->assertTrue( $fs->live_exists );
		$this->assertSame( array(), $fs->tmp_leftovers(), 'Failed staging must be cleaned up.' );
	}

	/**
	 * Manual fallback move failure cleans the staging tmp and keeps the original.
	 */
	public function test_manual_fallback_move_failure_cleans_tmp(): void {
		$fs              = $this->make_minimal_fs_with_original();
		$fs->move_result = false;
		$manager         = new WPPO_Atomic_Enable_Cache();

		$result = $this->invoke_atomic_write( $manager, $this->new_config(), $fs );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'write_error', $result->get_error_code() );
		$this->assertSame( $this->original_config(), $fs->live );
		$this->assertSame( array(), $fs->tmp_leftovers(), 'Failed staging must be cleaned up.' );
	}

	/**
	 * Manual fallback torn staged bytes return WP_Error with the old config untouched.
	 */
	public function test_manual_fallback_verify_failure_keeps_original(): void {
		$fs                   = $this->make_minimal_fs_with_original();
		$fs->corrupt_tmp_read = true;
		$manager              = new WPPO_Atomic_Enable_Cache();

		$result = $this->invoke_atomic_write( $manager, $this->new_config(), $fs );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'write_error', $result->get_error_code() );
		$this->assertSame( $this->original_config(), $fs->live );
		$this->assertSame( array(), $fs->tmp_leftovers(), 'Unverifiable staging must be cleaned up.' );
	}

	/**
	 * Stale .wppo-bak backup from a killed run is swept on success.
	 */
	public function test_stale_backup_swept_on_success(): void {
		$fs = $this->make_fs_with_original();
		$fs->files[ $fs->live_path() . '.wppo-bak' ] = $this->original_config();
		$manager                                     = new WPPO_Atomic_Enable_Cache();

		$result = $this->invoke_atomic_write( $manager, $this->new_config(), $fs );

		$this->assertTrue( $result );
		$this->assertArrayNotHasKey( $fs->live_path() . '.wppo-bak', $fs->files, 'A stale backup must not linger beside the live config.' );
		$this->assertSame( $this->new_config(), $fs->live );
	}

	/**
	 * End-to-end publish of the config via enable().
	 *
	 * Runs isolated: declaring the Redis stub class must not leak into the
	 * shared suite process (other tests branch on class_exists('Redis')).
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_enable_publishes_config_atomically(): void {
		if ( ! class_exists( 'Redis' ) ) {
			eval( 'class Redis {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.runtime_eval -- Isolated-process test stub for the phpredis extension.
		}

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				unset( $hook );
				return $value;
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'WP_Filesystem' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		$fs = $this->make_fs_with_original();
		$fs->files[ $fs->live_path() . Object_Cache::CONFIG_TMP_SUFFIX ] = '<?php // orphan from a killed write';

		$GLOBALS['wp_filesystem'] = $fs;

		try {
			$manager = new WPPO_Atomic_Enable_Cache();
			$result  = $manager->enable(
				array(
					'host'     => '127.0.0.1',
					'port'     => 6379,
					'mode'     => 'standalone',
					'password' => 'connection-only-secret',
				)
			);
		} finally {
			unset( $GLOBALS['wp_filesystem'] );
		}

		$this->assertTrue( $result, 'enable() must succeed with a reachable Redis and a working filesystem.' );
		$this->assertStringContainsString( "'host' => '127.0.0.1'", $fs->live );
		$this->assertStringNotContainsString( 'connection-only-secret', $fs->live, 'Redis passwords must never be persisted in the generated config.' );
		$this->assertStringContainsString( 'return', $fs->live );
		$this->assertTrue( Util::verify_php_syntax( $fs->live ), 'Published config must pass the PHP syntax check.' );
		$this->assert_source_includes_to_array( $fs->live );
		$this->assertNotContains( $fs->live_path(), $fs->put_log, 'enable() must never write the live config directly.' );
		$moved_destinations = array_column( $fs->move_log, 1 );
		$this->assertContains( $fs->live_path(), $moved_destinations, 'enable() must publish via rename onto the live path.' );
		$this->assertSame( array(), $fs->tmp_leftovers(), 'Orphan tmp siblings must be swept.' );
	}
}
