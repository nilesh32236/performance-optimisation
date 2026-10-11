<?php
/**
 * Tests for atomic drop-in/htaccess write centralisation (issue #1821).
 *
 * Pins the single-owner contract: every live drop-in and htaccess mutation
 * routes through the Filesystem helper (same-dir tmp + verifier + rename),
 * a failed rename leaves the live bytes untouched, duplicated markers abort
 * the move, orphan tmp siblings are swept, and no non-atomic live
 * `file_put_contents()` / `insert_with_markers()` call site remains outside
 * the helper and its documented allowlist.
 *
 * @package PerformanceOptimise\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Test fixtures are co-located by convention.
// phpcs:disable WordPress.Files.FileName -- Fixture class names cannot derive the *Test.php file name.

use PerformanceOptimise\Inc\Filesystem;

/**
 * In-memory WP_Filesystem stand-in for centralisation tests.
 *
 * Tracks the live file separately from staged tmp siblings so tests can
 * prove the live bytes are only ever replaced by a verified rename.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Atomic_Centralisation_FS_Mock {
	/**
	 * File contents keyed by path.
	 *
	 * @var array<string, string>
	 */
	public $files = array();

	/**
	 * Directories known to exist.
	 *
	 * @var array<string, bool>
	 */
	public $dirs = array();

	/**
	 * Whether put_contents succeeds (false simulates disk-full).
	 *
	 * @var bool
	 */
	public $put_result = true;

	/**
	 * Whether move succeeds (false simulates a killed rename).
	 *
	 * @var bool
	 */
	public $move_result = true;

	/**
	 * Every delete destination, in call order.
	 *
	 * @var string[]
	 */
	public $deleted = array();

	/**
	 * Write file contents.
	 *
	 * @param string $path File path.
	 * @param string $contents File contents.
	 * @param int    $mode File mode.
	 * @return bool
	 */
	public function put_contents( $path, $contents, $mode = 0644 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! $this->put_result ) {
			return false;
		}
		$this->files[ (string) $path ] = (string) $contents;
		return true;
	}

	/**
	 * Read file contents.
	 *
	 * @param string $path File path.
	 * @return string|false
	 */
	public function get_contents( $path ) {
		return array_key_exists( (string) $path, $this->files ) ? $this->files[ (string) $path ] : false;
	}

	/**
	 * Check existence of a file or directory.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function exists( $path ) {
		$path = (string) $path;
		return array_key_exists( $path, $this->files ) || isset( $this->dirs[ $path ] );
	}

	/**
	 * Move a file.
	 *
	 * @param string $src Source path.
	 * @param string $dst Destination path.
	 * @param bool   $overwrite Overwrite flag.
	 * @return bool
	 */
	public function move( $src, $dst, $overwrite = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! $this->move_result ) {
			return false;
		}
		$src = (string) $src;
		$dst = (string) $dst;
		if ( ! array_key_exists( $src, $this->files ) ) {
			return false;
		}
		$this->files[ $dst ] = $this->files[ $src ];
		unset( $this->files[ $src ] );
		return true;
	}

	/**
	 * Copy a file.
	 *
	 * @param string $src Source path.
	 * @param string $dst Destination path.
	 * @param bool   $overwrite Overwrite flag.
	 * @return bool
	 */
	public function copy( $src, $dst, $overwrite = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$src = (string) $src;
		$dst = (string) $dst;
		if ( ! array_key_exists( $src, $this->files ) ) {
			return false;
		}
		$this->files[ $dst ] = $this->files[ $src ];
		return true;
	}

	/**
	 * Delete a file.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	public function delete( $path ) {
		$this->deleted[] = (string) $path;
		unset( $this->files[ (string) $path ] );
		return true;
	}

	/**
	 * List a directory.
	 *
	 * @param string $dir Directory path.
	 * @return array<string, array{name: string}>
	 */
	public function dirlist( $dir ) {
		$dir     = rtrim( (string) $dir, '/' );
		$listing = array();
		foreach ( $this->files as $path => $contents ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			if ( dirname( (string) $path ) === $dir ) {
				$name             = basename( (string) $path );
				$listing[ $name ] = array( 'name' => $name );
			}
		}
		return $listing;
	}

	/**
	 * Change a file mode (best-effort parity surface).
	 *
	 * @param string $path File path.
	 * @param int    $mode File mode.
	 * @return bool
	 */
	public function chmod( $path, $mode = 0644 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return true;
	}
}

/**
 * Atomic write centralisation tests.
 *
 * @package PerformanceOptimise\Tests
 */
class AtomicWriteCentralisationTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Reset Brain Monkey between tests.
	 */
	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Duplicate-marker verifier: zero or one complete blocks pass, duplicates and partials fail.
	 */
	public function test_verify_no_duplicate_markers_vectors(): void {
		$begin = '# BEGIN wppo_rules';
		$end   = '# END wppo_rules';

		$this->assertTrue( Filesystem::verify_marker_block( "head\n" . $begin . "\nRule\n" . $end . "\n", $begin, $end, true ) );
		$this->assertTrue( Filesystem::verify_marker_block( "no markers here\n", $begin, $end, false ) );
		$this->assertFalse( Filesystem::verify_marker_block( "no markers here\n", $begin, $end, true ) );
		// Duplicated blocks are rejected.
		$dup = $begin . "\nA\n" . $end . "\n" . $begin . "\nB\n" . $end . "\n";
		$this->assertFalse( Filesystem::verify_marker_block( $dup, $begin, $end, true ) );
		$this->assertFalse( Filesystem::verify_no_duplicate_markers( $dup, $begin, $end ) );
		// Partial block (BEGIN without END) is rejected.
		$this->assertFalse( Filesystem::verify_no_duplicate_markers( "head\n" . $begin . "\nRule\n", $begin, $end ) );
		// END before BEGIN is rejected.
		$this->assertFalse( Filesystem::verify_marker_block( $end . "\n" . $begin . "\n", $begin, $end, true ) );
		// Zero or one complete blocks pass the shared verifier.
		$this->assertTrue( Filesystem::verify_no_duplicate_markers( "plain\n", $begin, $end ) );
		$this->assertTrue( Filesystem::verify_no_duplicate_markers( $begin . "\nA\n" . $end . "\n", $begin, $end ) );
		// Util facade proxies agree with the owner.
		$this->assertSame(
			Filesystem::verify_no_duplicate_markers( $dup, $begin, $end ),
			\PerformanceOptimise\Inc\Util::verify_no_duplicate_markers( $dup, $begin, $end )
		);
	}

	/**
	 * Failed rename leaves the live bytes untouched and reports failure.
	 */
	public function test_atomic_write_failed_rename_leaves_live_untouched(): void {
		$fs                 = new WPPO_Atomic_Centralisation_FS_Mock();
		$live               = '/tmp/wordpress/wp-content/advanced-cache.php';
		$original           = "<?php\n// WPPO_ADVANCED_CACHE_DROPIN\n";
		$fs->files[ $live ] = $original;
		$fs->move_result    = false;

		$result = Filesystem::atomic_file_put_contents( $fs, $live, "<?php\n// WPPO_ADVANCED_CACHE_DROPIN updated\n" );

		$this->assertFalse( $result );
		$this->assertSame( $original, $fs->files[ $live ], 'Live bytes must be unchanged after a failed rename.' );
	}

	/**
	 * Duplicated markers abort the move via the caller verifier; the live file keeps parseable PHP.
	 */
	public function test_atomic_write_php_verified_rejects_duplicate_markers(): void {
		$fs                 = new WPPO_Atomic_Centralisation_FS_Mock();
		$live               = '/tmp/wordpress/wp-content/advanced-cache.php';
		$original           = "<?php\n// WPPO_ADVANCED_CACHE_DROPIN\ndefine( 'X', 1 );\n";
		$fs->files[ $live ] = $original;

		$begin = '# BEGIN wppo_rules';
		$end   = '# END wppo_rules';
		$torn  = "<?php\n" . $begin . "\nA\n" . $end . "\n" . $begin . "\nB\n" . $end . "\n";

		$result = Filesystem::atomic_write_php_verified(
			$fs,
			$live,
			$torn,
			static function ( $contents ) use ( $begin, $end ): bool {
				return Filesystem::verify_no_duplicate_markers( (string) $contents, $begin, $end );
			}
		);

		$this->assertFalse( $result );
		$this->assertSame( $original, $fs->files[ $live ], 'Live file must keep the prior version when the verifier rejects.' );
		$this->assertTrue( Filesystem::verify_php_syntax( $fs->files[ $live ] ), 'Surviving live file must stay parseable.' );
	}

	/**
	 * Tmp sweep removes only residue siblings, never the live file or foreign entries.
	 */
	public function test_sweep_atomic_tmp_removes_orphans_only(): void {
		$fs                 = new WPPO_Atomic_Centralisation_FS_Mock();
		$dir                = '/tmp/wordpress/wp-content';
		$fs->dirs[ $dir ]   = true;
		$live               = $dir . '/advanced-cache.php';
		$fs->files[ $live ] = "<?php\n// live\n";
		$fs->files[ $dir . '/advanced-cache.php.tmp.1234567-1-abcd1234' ] = 'orphan';
		$fs->files[ $dir . '/advanced-cache.php.wppo-tmp-abc' ]           = 'orphan';
		$fs->files[ $dir . '/advanced-cache.php.wppo-bak' ]               = 'backup';
		$fs->files[ $dir . '/object-cache.php' ]                          = "<?php\n// foreign live\n";
		$fs->files[ $dir . '/index.php' ]                                 = "<?php\n// unrelated\n";

		$removed = Filesystem::sweep_atomic_tmp( $fs, $dir, 'advanced-cache.php' );

		$this->assertSame( 3, $removed );
		$this->assertArrayHasKey( $live, $fs->files );
		$this->assertArrayHasKey( $dir . '/object-cache.php', $fs->files );
		$this->assertArrayHasKey( $dir . '/index.php', $fs->files );
		$this->assertArrayNotHasKey( $dir . '/advanced-cache.php.tmp.1234567-1-abcd1234', $fs->files );
		$this->assertSame(
			Filesystem::sweep_atomic_tmp( $fs, $dir, 'advanced-cache.php' ),
			\PerformanceOptimise\Inc\Util::sweep_atomic_tmp( $fs, $dir, 'advanced-cache.php' )
		);
	}

	/**
	 * Source scanner: no non-atomic live file writes outside the helper and its documented allowlist.
	 *
	 * Native `file_put_contents()` / `insert_with_markers()` calls are only
	 * permitted with a same-context marker (`intentional`, `legacy`, or
	 * `non-live`) or inside the standalone `uninstall.php` (no autoloader).
	 */
	public function test_no_live_direct_writes_outside_helper(): void {
		$root       = dirname( __DIR__, 2 );
		$scan_dirs  = array( $root . '/includes' );
		$scan_files = array( $root . '/uninstall.php' );
		$offenders  = array();

		foreach ( $scan_dirs as $scan_dir ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $scan_dir, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				$path = (string) $file;
				if ( 'php' !== strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
					continue;
				}
				$this->scan_file_for_direct_writes( $path, $root, $offenders );
			}
		}
		foreach ( $scan_files as $path ) {
			if ( is_readable( $path ) ) {
				$this->scan_file_for_direct_writes( $path, $root, $offenders, true );
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'Non-atomic live writes must route through Filesystem (issue #1821):' . "\n" . implode( "\n", $offenders )
		);
	}

	/**
	 * Scan one file for non-atomic live write calls.
	 *
	 * @param string   $path File path.
	 * @param string   $root Project root for relative labels.
	 * @param string[] $offenders Offender accumulator.
	 * @param bool     $standalone Whether standalone context (uninstall) is allowlisted wholesale.
	 * @return void
	 */
	private function scan_file_for_direct_writes( string $path, string $root, array &$offenders, bool $standalone = false ): void {
		if ( $standalone ) {
			return;
		}
		$lines = file( $path, FILE_IGNORE_NEW_LINES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file -- Test source scanner.
		if ( ! is_array( $lines ) ) {
			return;
		}
		$total = count( $lines );
		for ( $i = 0; $i < $total; ++$i ) {
			$trimmed = ltrim( $lines[ $i ] );
			if ( '' === $trimmed || '*' === substr( $trimmed, 0, 1 ) || '/' === substr( $trimmed, 0, 1 ) || '#' === substr( $trimmed, 0, 1 ) ) {
				continue;
			}
			$has_native = (bool) preg_match( '/(?<!::)(?<!->)(?<!_)\bfile_put_contents\s*\(/', $lines[ $i ] )
				|| (bool) preg_match( '/(?<!::)(?<!->)(?<!_)\binsert_with_markers\s*\(/', $lines[ $i ] );
			if ( ! $has_native ) {
				continue;
			}
			$from    = max( 0, $i - 3 );
			$context = strtolower( implode( "\n", array_slice( $lines, $from, 7 ) ) );
			if ( false !== strpos( $context, 'intentional' ) || false !== strpos( $context, 'legacy' ) || false !== strpos( $context, 'non-live' ) ) {
				continue;
			}
			$offenders[] = str_replace( $root . '/', '', $path ) . ':' . ( $i + 1 ) . ': ' . trim( $lines[ $i ] );
		}
	}
}
