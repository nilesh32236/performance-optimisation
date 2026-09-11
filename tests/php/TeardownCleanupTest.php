<?php
/**
 * Tests for teardown cleanup (issue #1060).
 *
 * Covers: Htaccess_Handler marker add/remove helpers, backup-artifact sweep,
 * Advanced_Cache_Handler stale-artifact cleanup, and static guards on the
 * standalone uninstall.php + Deactivate wiring.
 *
 * @package PerformanceOptimise\Tests
 *
 * @phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
 * @phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
 */

use PerformanceOptimise\Inc\Advanced_Cache_Handler;
use PerformanceOptimise\Inc\Htaccess_Handler;
use Brain\Monkey\Functions;

/**
 * Teardown cleanup tests.
 *
 * @package PerformanceOptimise\Tests
 */
class TeardownCleanupTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Reset Brain Monkey and the filesystem mock between tests.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp_filesystem'] );
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Register the filesystem-backed stubs for htaccess tests.
	 *
	 * @param WPPO_Htaccess_FS_Mock $fs Filesystem mock.
	 */
	private function stub_htaccess_env( $fs ): void {
		$GLOBALS['wp_filesystem'] = $fs;
		Functions\when( 'wp_normalize_path' )->alias(
			static function ( $path ) {
				$path = str_replace( '\\', '/', (string) $path );
				$path = preg_replace( '|(?<=.)/+|', '/', $path );
				return $path;
			}
		);
		Functions\when( 'get_home_path' )->justReturn( '/tmp/wordpress/' );
		Functions\when( 'WP_Filesystem' )->justReturn( true );
		// Pin the tmp-suffix RNG: other suites declare a wp_rand stub that
		// does not survive its own tearDown, so resolve it explicitly here.
		Functions\when( 'wp_rand' )->justReturn( 123456 );
		Functions\when( 'insert_with_markers' )->alias(
			static function ( $file, $marker, $rules ) use ( $fs ) {
				$block = '';
				if ( array() !== $rules ) {
					$block = '# BEGIN ' . $marker . "\n" . implode( "\n", $rules ) . "\n# END " . $marker . "\n";
				}
				$current = $fs->get_contents( $file );
				if ( ! is_string( $current ) ) {
					$current = '';
				}
				$begin = '# BEGIN ' . $marker;
				$end   = '# END ' . $marker;
				$start = strpos( $current, $begin );
				$stop  = strpos( $current, $end );
				if ( false !== $start && false !== $stop && $stop > $start ) {
					$line_end = strpos( $current, "\n", $stop );
					$head     = substr( $current, 0, $start );
					$tail     = false === $line_end ? '' : substr( $current, $line_end + 1 );
					if ( '' === $block ) {
						$new = rtrim( $head, "\r\n" ) . ( '' === trim( (string) $tail ) ? '' : "\n" . ltrim( (string) $tail, "\r\n" ) );
					} else {
						$new = $head . $block . ltrim( (string) $tail, "\r\n" );
					}
				} elseif ( '' === $block ) {
					$new = $current;
				} else {
					$new = rtrim( $current, "\r\n" ) . "\n" . $block;
				}
				return $fs->put_contents( '' === $new ? $file : $file, $new, 0644 );
			}
		);
	}

	/**
	 * Test that has_rules() detects the marker block.
	 */
	public function test_has_rules_detects_marker(): void {
		$fs                                    = new WPPO_Htaccess_FS_Mock();
		$fs->files['/tmp/wordpress/.htaccess'] = "# BEGIN wppo_rules\nExpiresActive On\n# END wppo_rules\n";
		$this->stub_htaccess_env( $fs );

		$this->assertTrue( Htaccess_Handler::has_rules() );
	}

	/**
	 * Test that has_rules() returns false when no marker is present.
	 */
	public function test_has_rules_false_when_absent(): void {
		$fs                                    = new WPPO_Htaccess_FS_Mock();
		$fs->files['/tmp/wordpress/.htaccess'] = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n";
		$this->stub_htaccess_env( $fs );

		$this->assertFalse( Htaccess_Handler::has_rules() );
	}

	/**
	 * Test that remove_rules() removes the marker and leaves other blocks intact.
	 */
	public function test_remove_rules_removes_marker_block(): void {
		$fs                                    = new WPPO_Htaccess_FS_Mock();
		$fs->files['/tmp/wordpress/.htaccess'] = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n# BEGIN wppo_rules\nExpiresActive On\n# END wppo_rules\n";
		$fs->files['/tmp/wordpress/.htaccess.wppo-bak'] = 'backup';
		$this->stub_htaccess_env( $fs );

		$this->assertTrue( Htaccess_Handler::remove_rules() );

		$final = $fs->files['/tmp/wordpress/.htaccess'];
		$this->assertStringNotContainsString( 'wppo_rules', $final );
		$this->assertStringContainsString( '# BEGIN WordPress', $final );
		$this->assertFalse( Htaccess_Handler::has_rules() );
	}

	/**
	 * Test that remove_rules() is a no-op success when no marker exists.
	 */
	public function test_remove_rules_noop_when_absent(): void {
		$fs                                    = new WPPO_Htaccess_FS_Mock();
		$fs->files['/tmp/wordpress/.htaccess'] = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n";
		$this->stub_htaccess_env( $fs );

		$this->assertTrue( Htaccess_Handler::remove_rules() );
	}

	/**
	 * Test that cleanup_backup_artifacts() deletes the .wppo-bak sibling.
	 */
	public function test_cleanup_backup_artifacts_deletes_bak(): void {
		$fs                                    = new WPPO_Htaccess_FS_Mock();
		$fs->files['/tmp/wordpress/.htaccess'] = "# BEGIN WordPress\n# END WordPress\n";
		$fs->files['/tmp/wordpress/.htaccess.wppo-bak'] = 'backup';
		$this->stub_htaccess_env( $fs );

		Htaccess_Handler::cleanup_backup_artifacts();

		$this->assertArrayNotHasKey( '/tmp/wordpress/.htaccess.wppo-bak', $fs->files );
		$this->assertArrayHasKey( '/tmp/wordpress/.htaccess', $fs->files );
	}

	/**
	 * Stale-artifact cleanup removes the drop-in backup sibling.
	 */
	public function test_cleanup_stale_artifacts_removes_backup(): void {
		$fs                       = new WPPO_AdvancedCache_FS_Mock();
		$GLOBALS['wp_filesystem'] = $fs;
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'WP_Filesystem' )->justReturn( true );

		$backup_path                = Advanced_Cache_Handler::get_dropin_backup_path();
		$fs->copied[ $backup_path ] = '<?php // old backup';

		Advanced_Cache_Handler::cleanup_stale_artifacts();

		$this->assertContains( $backup_path, $fs->deleted );
	}

	/**
	 * Deactivate wires htaccess removal on teardown.
	 */
	public function test_deactivate_calls_htaccess_removal(): void {
		$path   = WPPO_PLUGIN_PATH . 'includes/class-deactivate.php';
		$source = file_get_contents( $path );
		$this->assertNotFalse( $source );
		$this->assertStringContainsString( 'remove_htaccess_rules', (string) $source );
		$this->assertStringContainsString( 'cleanup_dropin_artifacts', (string) $source );
	}

	/**
	 * Uninstall keeps the standalone htaccess removal + network hoist.
	 */
	public function test_uninstall_has_htaccess_removal_and_network_hoist(): void {
		$path   = WPPO_PLUGIN_PATH . 'uninstall.php';
		$source = file_get_contents( $path );
		$this->assertNotFalse( $source );
		$source = (string) $source;
		$this->assertStringContainsString( 'function wppo_remove_htaccess_rules', $source );
		$this->assertStringContainsString( 'function wppo_cleanup_network_files', $source );
		$this->assertStringContainsString( 'function wppo_cleanup_dropin_artifacts', $source );
		$this->assertStringContainsString( '# BEGIN wppo_rules', $source );
		$this->assertStringContainsString( 'wppo_cleanup_site( false )', $source );
		$this->assertStringContainsString( "function_exists( 'is_multisite' )", $source );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile

/**
 * Minimal in-memory filesystem for htaccess teardown tests.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Htaccess_FS_Mock {
	/**
	 * Files keyed by absolute path.
	 *
	 * @var array<string,string>
	 */
	public $files = array();

	/**
	 * Deleted paths.
	 *
	 * @var string[]
	 */
	public $deleted = array();

	/**
	 * Simulate file existence (files + directories holding files).
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function exists( $path ) {
		$path = (string) $path;
		if ( isset( $this->files[ $path ] ) && ! in_array( $path, $this->deleted, true ) ) {
			return true;
		}
		foreach ( $this->files as $file => $ignored ) {
			if ( in_array( $file, $this->deleted, true ) ) {
				continue;
			}
			if ( 0 === strpos( $file, rtrim( $path, '/' ) . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Read file contents.
	 *
	 * @param string $path Path.
	 * @return string|false
	 */
	public function get_contents( $path ) {
		$path = (string) $path;
		if ( isset( $this->files[ $path ] ) && ! in_array( $path, $this->deleted, true ) ) {
			return $this->files[ $path ];
		}
		return false;
	}

	/**
	 * Write file contents.
	 *
	 * @param string $path     Path.
	 * @param string $contents Contents.
	 * @param int    $chmod    Mode (unused).
	 * @return bool
	 */
	public function put_contents( $path, $contents, $chmod = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$path                 = (string) $path;
		$this->files[ $path ] = (string) $contents;
		$this->deleted        = array_values( array_diff( $this->deleted, array( $path ) ) );
		return true;
	}

	/**
	 * Move a file.
	 *
	 * @param string $src       Source.
	 * @param string $dst       Destination.
	 * @param bool   $overwrite Overwrite (unused).
	 * @return bool
	 */
	public function move( $src, $dst, $overwrite = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$src = (string) $src;
		$dst = (string) $dst;
		if ( isset( $this->files[ $src ] ) ) {
			$this->files[ $dst ] = $this->files[ $src ];
			unset( $this->files[ $src ] );
		} elseif ( ! isset( $this->files[ $dst ] ) ) {
			$this->files[ $dst ] = '';
		}
		$this->deleted[] = $src;
		return true;
	}

	/**
	 * Copy a file.
	 *
	 * @param string $src       Source.
	 * @param string $dst       Destination.
	 * @param bool   $overwrite Overwrite (unused).
	 * @param int    $chmod     Mode (unused).
	 * @return bool
	 */
	public function copy( $src, $dst, $overwrite = false, $chmod = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$src                 = (string) $src;
		$dst                 = (string) $dst;
		$this->files[ $dst ] = isset( $this->files[ $src ] ) ? $this->files[ $src ] : '';
		return true;
	}

	/**
	 * Delete a file.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function delete( $path ) {
		$path = (string) $path;
		unset( $this->files[ $path ] );
		$this->deleted[] = $path;
		return true;
	}

	/**
	 * List a directory.
	 *
	 * @param string $dir Directory.
	 * @return array
	 */
	public function dirlist( $dir ) {
		$dir     = rtrim( (string) $dir, '/' );
		$listing = array();
		foreach ( $this->files as $file => $ignored ) {
			if ( 0 === strpos( $file, $dir . '/' ) ) {
				$base             = basename( $file );
				$listing[ $base ] = array( 'name' => $base );
			}
		}
		return $listing;
	}

	/**
	 * Writable check.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function is_writable( $path ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return true;
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
