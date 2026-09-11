<?php
/**
 * Tests for atomic wp-config.php WP_CACHE edits with verify and rollback.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Activate;
use PerformanceOptimise\Inc\Deactivate;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests for atomic wp-config.php WP_CACHE edits.
 *
 * @package PerformanceOptimise\Tests
 */
class WpConfigAtomicTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Valid wp-config.php fixture.
	 *
	 * @return string
	 */
	private function valid_config(): string {
		return "<?php\ndefine( 'DB_NAME', 'test' );\n/* That's all, stop editing! Happy publishing. */\n";
	}

	/**
	 * Make the in-memory filesystem mock.
	 *
	 * @param string $contents Live file contents.
	 * @param bool   $put_ok Whether put_contents succeeds (disk-full simulation when false).
	 * @param bool   $move_ok Whether move succeeds.
	 * @return WPPO_WpConfig_FS_Mock
	 */
	private function make_fs( string $contents, bool $put_ok = true, bool $move_ok = true ): WPPO_WpConfig_FS_Mock {
		$fs              = new WPPO_WpConfig_FS_Mock();
		$fs->contents    = $contents;
		$fs->file_exists = true;
		$fs->put_result  = $put_ok;
		$fs->move_result = $move_ok;
		$fs->writable    = true;
		return $fs;
	}

	/**
	 * Point ABSPATH wp-config.php at the mock.
	 *
	 * @return void
	 */
	private function stub_path_helpers(): void {
		Functions\when( 'wp_normalize_path' )->alias(
			static function ( $path ) {
				$path = str_replace( '\\', '/', (string) $path );
				return preg_replace( '|(?<=.)/+|', '/', $path );
			}
		);
		Functions\when( 'WP_Filesystem' )->justReturn( true );
	}

	/**
	 * Verified write succeeds and live file parses.
	 */
	public function test_atomic_write_verified_success(): void {
		$this->stub_path_helpers();
		$fs = $this->make_fs( $this->valid_config() );

		$new = $this->valid_config() . "if ( ! defined( 'WP_CACHE' ) ) {\n\tdefine( 'WP_CACHE', true );\n}\n";
		$ok  = Util::atomic_write_php_verified(
			$fs,
			'/tmp/wordpress/wp-config.php',
			$new,
			static function ( $c ): bool {
				return is_string( $c ) && false !== strpos( $c, 'WP_CACHE' );
			}
		);

		$this->assertTrue( $ok );
		$this->assertStringContainsString( 'WP_CACHE', $fs->contents );
		$this->assertTrue( Util::verify_php_syntax( $fs->contents ) );
		$this->assertArrayHasKey( '/tmp/wordpress/wp-config.php.wppo-bak', $fs->copied );
		$this->assertContains( '/tmp/wordpress/wp-config.php.wppo-bak', $fs->deleted );
	}

	/**
	 * Expectation failure leaves the live file untouched.
	 */
	public function test_atomic_write_verify_failure_keeps_live(): void {
		$this->stub_path_helpers();
		$original = $this->valid_config();
		$fs       = $this->make_fs( $original );

		$ok = Util::atomic_write_php_verified(
			$fs,
			'/tmp/wordpress/wp-config.php',
			$original . 'broken-no-wpcache-marker',
			static function (): bool {
				return false;
			}
		);

		$this->assertFalse( $ok );
		$this->assertSame( $original, $fs->contents );
	}

	/**
	 * Disk-full (put_contents failure) leaves live file untouched.
	 */
	public function test_atomic_write_disk_full_keeps_live(): void {
		$this->stub_path_helpers();
		$original = $this->valid_config();
		$fs       = $this->make_fs( $original, false );

		$ok = Util::atomic_write_php_verified(
			$fs,
			'/tmp/wordpress/wp-config.php',
			$original . "define( 'WP_CACHE', true );\n"
		);

		$this->assertFalse( $ok );
		$this->assertSame( $original, $fs->contents );
	}

	/**
	 * Truncated PHP fails the syntax check.
	 */
	public function test_verify_php_syntax_rejects_truncated(): void {
		$this->assertTrue( Util::verify_php_syntax( $this->valid_config() ) );
		$this->assertFalse( Util::verify_php_syntax( "<?php\ndefine( 'WP_CACHE', true );\nif ( ! defined(" ) );
		$this->assertFalse( Util::verify_php_syntax( '' ) );
		$this->assertFalse( Util::verify_php_syntax( 'not php at all' ) );
	}

	/**
	 * Activate adds WP_CACHE atomically.
	 */
	public function test_activate_adds_wp_cache_atomically(): void {
		$this->stub_path_helpers();
		$fs                       = $this->make_fs( $this->valid_config() );
		$GLOBALS['wp_filesystem'] = $fs;

		try {
			$notice = Activate::add_wp_cache_constant();
		} finally {
			unset( $GLOBALS['wp_filesystem'] );
		}

		$this->assertNull( $notice );
		$this->assertStringContainsString( 'WP_CACHE', $fs->contents );
		$this->assertTrue( Util::verify_php_syntax( $fs->contents ) );
	}

	/**
	 * Activate surfaces wp_config_write_failed on disk-full.
	 */
	public function test_activate_returns_notice_on_disk_full(): void {
		$this->stub_path_helpers();
		$fs                       = $this->make_fs( $this->valid_config(), false );
		$GLOBALS['wp_filesystem'] = $fs;

		try {
			$notice = Activate::add_wp_cache_constant();
		} finally {
			unset( $GLOBALS['wp_filesystem'] );
		}

		$this->assertSame( 'wp_config_write_failed', $notice );
		$this->assertSame( $this->valid_config(), $fs->contents );
	}

	/**
	 * Deactivate removes the WPPO block atomically.
	 */
	public function test_deactivate_removes_wp_cache_atomically(): void {
		$this->stub_path_helpers();
		$with_block               = "<?php\ndefine( 'DB_NAME', 'test' );\n/** Enables WordPress Cache */\nif ( ! defined( 'WP_CACHE' ) ) {\n\tdefine( 'WP_CACHE', true );\n}\n/* That's all, stop editing! Happy publishing. */\n";
		$fs                       = $this->make_fs( $with_block );
		$GLOBALS['wp_filesystem'] = $fs;

		try {
			$notice = Deactivate::remove_wp_cache_constant();
		} finally {
			unset( $GLOBALS['wp_filesystem'] );
		}

		$this->assertNull( $notice );
		$this->assertStringNotContainsString( 'Enables WordPress Cache', $fs->contents );
		$this->assertTrue( Util::verify_php_syntax( $fs->contents ) );
	}

	/**
	 * Unsupported transport returns null so callers can use legacy fallback.
	 */
	public function test_atomic_write_returns_null_without_methods(): void {
		$fs = new \stdClass();
		$this->assertNull( Util::atomic_write_php_verified( $fs, '/tmp/wordpress/wp-config.php', "<?php\nfoo();\n" ) );
		$this->assertNull( Util::atomic_write_php_verified( null, '/tmp/wordpress/wp-config.php', "<?php\nfoo();\n" ) );
	}

	/**
	 * Unreadable original is a verified failure (not unsupported transport).
	 */
	public function test_atomic_write_unreadable_original_returns_false(): void {
		$this->stub_path_helpers();
		$fs = new WPPO_WpConfig_Unreadable_Mock();

		$ok = Util::atomic_write_php_verified(
			$fs,
			'/tmp/wordpress/wp-config.php',
			"<?php\ndefine( 'WP_CACHE', true );\n"
		);

		$this->assertFalse( $ok );
	}

	/**
	 * Curly string interpolation still verifies.
	 */
	public function test_verify_php_syntax_allows_curly_interpolation(): void {
		$code = "<?php\n\$name = 'world';\n\$s = \"hello \${name} and {\$name}\";\n";
		$this->assertTrue( Util::verify_php_syntax( $code ) );
		$this->assertTrue( Util::verify_php_syntax( "\xEF\xBB\xBF<?php\ndefine( 'A', 1 );\n" ) );
	}

	/**
	 * Move failure leaves the live file untouched.
	 */
	public function test_atomic_write_move_failure_keeps_live(): void {
		$this->stub_path_helpers();
		$original = $this->valid_config();
		$fs       = $this->make_fs( $original, true, false );

		$ok = Util::atomic_write_php_verified(
			$fs,
			'/tmp/wordpress/wp-config.php',
			$original . "define( 'WP_CACHE', true );\n"
		);

		$this->assertFalse( $ok );
		$this->assertSame( $original, $fs->contents );
	}

	/**
	 * Torn rename (corrupt live re-read) restores the original.
	 */
	public function test_atomic_write_post_rename_mismatch_restores(): void {
		$this->stub_path_helpers();
		$original                    = $this->valid_config();
		$fs                          = $this->make_fs( $original );
		$fs->corrupt_live_after_move = true;

		$ok = Util::atomic_write_php_verified(
			$fs,
			'/tmp/wordpress/wp-config.php',
			$original . "define( 'WP_CACHE', true );\n"
		);

		$this->assertFalse( $ok );
		$this->assertSame( $original, $fs->contents );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile

/**
 * In-memory WP_Filesystem mock for wp-config atomic-write tests.
 */
class WPPO_WpConfig_FS_Mock {
	/**
	 * Live file contents.
	 *
	 * @var string
	 */
	public $contents = '';
	/**
	 * Whether the live file exists.
	 *
	 * @var bool
	 */
	public $file_exists = true;
	/**
	 * Whether put_contents succeeds (false simulates disk-full).
	 *
	 * @var bool
	 */
	public $put_result = true;
	/**
	 * Whether move succeeds.
	 *
	 * @var bool
	 */
	public $move_result = true;
	/**
	 * Whether the file is writable.
	 *
	 * @var bool
	 */
	public $writable = true;
	/**
	 * When true, move() writes corrupt PHP to the live path to simulate a
	 * torn rename, exercising the post-rename restore branch.
	 *
	 * @var bool
	 */
	public $corrupt_live_after_move = false;
	/**
	 * Tmp/any-path writes keyed by path.
	 *
	 * @var array
	 */
	public $put_paths = array();
	/**
	 * Backup/extra copies keyed by path.
	 *
	 * @var array
	 */
	public $copied = array();
	/**
	 * Deleted paths.
	 *
	 * @var array
	 */
	public $deleted = array();

	/**
	 * Check whether a path exists in the mock.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function exists( $path ) {
		if ( $this->is_tmp_path( $path ) ) {
			return isset( $this->put_paths[ $path ] );
		}
		if ( $this->is_backup_path( $path ) ) {
			return isset( $this->copied[ $path ] );
		}
		if ( in_array( $path, $this->deleted, true ) && ! isset( $this->put_paths[ $path ] ) ) {
			return false;
		}
		return $this->file_exists;
	}

	/**
	 * Check whether a path is writable in the mock.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function is_writable( $path ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return $this->writable;
	}

	/**
	 * Read mock file contents.
	 *
	 * @param string $path Path.
	 * @return string|false
	 */
	public function get_contents( $path ) {
		if ( $this->is_tmp_path( $path ) ) {
			return isset( $this->put_paths[ $path ] ) ? $this->put_paths[ $path ] : false;
		}
		if ( $this->is_backup_path( $path ) ) {
			return isset( $this->copied[ $path ] ) ? $this->copied[ $path ] : false;
		}
		return $this->file_exists ? $this->contents : false;
	}

	/**
	 * Write mock file contents.
	 *
	 * @param string $path Path.
	 * @param string $contents Contents.
	 * @param int    $chmod Mode.
	 * @return bool
	 */
	public function put_contents( $path, $contents, $chmod = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! $this->put_result ) {
			return false;
		}
		$this->put_paths[ $path ] = $contents;
		if ( ! $this->is_tmp_path( $path ) && ! $this->is_backup_path( $path ) ) {
			$this->contents    = $contents;
			$this->file_exists = true;
			unset( $this->deleted[ array_search( $path, $this->deleted, true ) ] );
		}
		return true;
	}

	/**
	 * Move mock file contents from src to dst.
	 *
	 * @param string $src Source.
	 * @param string $dst Destination.
	 * @param bool   $overwrite Overwrite.
	 * @return bool
	 */
	public function move( $src, $dst, $overwrite = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! $this->move_result ) {
			return false;
		}
		$content = isset( $this->put_paths[ $src ] ) ? $this->put_paths[ $src ] : $this->contents;
		if ( $this->is_backup_path( $dst ) ) {
			$this->copied[ $dst ] = $content;
		} else {
			$this->contents    = $this->corrupt_live_after_move ? "<?php\nbroken trunc if ( ! defined(" : $content;
			$this->file_exists = true;
		}
		$this->deleted[] = $src;
		unset( $this->put_paths[ $src ] );
		return true;
	}

	/**
	 * Copy mock file contents from src to dst.
	 *
	 * @param string $src Source.
	 * @param string $dst Destination.
	 * @param bool   $overwrite Overwrite.
	 * @param int    $chmod Mode.
	 * @return bool
	 */
	public function copy( $src, $dst, $overwrite = false, $chmod = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( $this->is_tmp_path( $src ) ) {
			$content = isset( $this->put_paths[ $src ] ) ? $this->put_paths[ $src ] : '';
		} elseif ( $this->is_backup_path( $src ) ) {
			$content = isset( $this->copied[ $src ] ) ? $this->copied[ $src ] : '';
		} else {
			$content = $this->contents;
		}
		$this->copied[ $dst ] = $content;
		if ( ! $this->is_backup_path( $dst ) && ! $this->is_tmp_path( $dst ) ) {
			$this->contents    = $content;
			$this->file_exists = true;
		}
		return true;
	}

	/**
	 * Delete a mock path.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function delete( $path ) {
		$this->deleted[] = $path;
		unset( $this->put_paths[ $path ] );
		return true;
	}

	/**
	 * Check whether a path is an atomic-write tmp sibling.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private function is_tmp_path( $path ): bool {
		return false !== strpos( (string) $path, '.tmp.' ) || false !== strpos( (string) $path, '.wppo-tmp-' );
	}

	/**
	 * Check whether a path is a backup sibling.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private function is_backup_path( $path ): bool {
		return false !== strpos( (string) $path, '.wppo-bak' );
	}
}

/**
 * Filesystem mock whose live-file read fails (returns false).
 */
class WPPO_WpConfig_Unreadable_Mock {
	/**
	 * Check existence.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function exists( $path ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return true;
	}

	/**
	 * Fail the read.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function get_contents( $path ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return false;
	}

	/**
	 * Write.
	 *
	 * @param string $path Path.
	 * @param string $contents Contents.
	 * @param int    $chmod Mode.
	 * @return bool
	 */
	public function put_contents( $path, $contents, $chmod = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return true;
	}

	/**
	 * Move.
	 *
	 * @param string $src Source.
	 * @param string $dst Destination.
	 * @param bool   $overwrite Overwrite.
	 * @return bool
	 */
	public function move( $src, $dst, $overwrite = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return true;
	}

	/**
	 * Copy.
	 *
	 * @param string $src Source.
	 * @param string $dst Destination.
	 * @param bool   $overwrite Overwrite.
	 * @param int    $chmod Mode.
	 * @return bool
	 */
	public function copy( $src, $dst, $overwrite = false, $chmod = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return true;
	}

	/**
	 * Delete.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function delete( $path ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return true;
	}
}
