<?php
/**
 * Hardening tests for .htaccess writes (issue #1121).
 *
 * Covers: CRLF/NUL/marker-injection rejection in sanitize_rules(),
 * atomic temp+rename with byte-identical checksum verification and backup
 * restore on torn writes, unique tmp names for concurrent saves, and
 * Nginx (incl. multisite) server gating that skips .htaccess writes.
 *
 * @package PerformanceOptimise\Tests
 *
 * @phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
 */

use PerformanceOptimise\Inc\Htaccess_Handler;
use PerformanceOptimise\Inc\LiteSpeed_Integration;
use PerformanceOptimise\Inc\Server_Rules;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Hardening tests for .htaccess writes.
 *
 * @package PerformanceOptimise\Tests
 */
class HtaccessHandlerHardeningTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Previous SERVER_SOFTWARE to restore in tearDown.
	 *
	 * @var string|null
	 */
	private $server_software_backup = null;

	/**
	 * Whether SERVER_SOFTWARE existed before the test.
	 *
	 * @var bool
	 */
	private $server_software_existed = false;

	/**
	 * Set up Brain Monkey, the filesystem mock, and WP stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		Util::clear_settings_cache();
		LiteSpeed_Integration::reset_cache();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test-only superglobal backup.
		$this->server_software_existed = isset( $_SERVER['SERVER_SOFTWARE'] );
		if ( $this->server_software_existed ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test-only superglobal backup.
			$this->server_software_backup = $_SERVER['SERVER_SOFTWARE'];
		}
		// Default to Apache so write-path tests exercise the file logic.
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.62 (Unix)';

		Functions\when( 'get_home_path' )->justReturn( '/tmp/wordpress/' );
		Functions\when( 'WP_Filesystem' )->justReturn( true );
		Functions\when( 'wp_rand' )->justReturn( 123456 );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return $value;
			}
		);
	}

	/**
	 * Restore superglobals and tear down Brain Monkey.
	 */
	protected function tearDown(): void {
		if ( $this->server_software_existed ) {
			$_SERVER['SERVER_SOFTWARE'] = $this->server_software_backup;
		} else {
			unset( $_SERVER['SERVER_SOFTWARE'] );
		}
		unset( $GLOBALS['wp_filesystem'] );
		LiteSpeed_Integration::reset_cache();
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Install a filesystem mock plus a faithful insert_with_markers shim.
	 *
	 * @param WPPO_Hardening_FS_Mock $fs Filesystem mock.
	 */
	private function stub_htaccess_env( $fs ): void {
		$GLOBALS['wp_filesystem'] = $fs;
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
				return $fs->put_contents( $file, $new, 0644 );
			}
		);
	}

	/**
	 * Call the private sanitize_rules() via reflection.
	 *
	 * @param array $rules Rules lines.
	 * @return array|false Sanitized rules or false when rejected.
	 */
	private function sanitize( array $rules ) {
		$method = new \ReflectionMethod( Htaccess_Handler::class, 'sanitize_rules' );
		$method->setAccessible( true );
		return $method->invoke( null, $rules );
	}

	/**
	 * Valid rules pass sanitization unchanged.
	 */
	public function test_sanitize_rules_accepts_valid_rules(): void {
		$rules = array( '<IfModule mod_deflate.c>', '    AddOutputFilterByType DEFLATE text/html', '', '</IfModule>' );
		$this->assertSame( $rules, $this->sanitize( $rules ) );
		$this->assertSame( array(), $this->sanitize( array() ) );
	}

	/**
	 * CRLF injection payloads are rejected.
	 */
	public function test_sanitize_rules_rejects_crlf_injection(): void {
		$this->assertFalse( $this->sanitize( array( 'ExpiresActive On', "Evil: injected\r\nHeader: x" ) ) );
		$this->assertFalse( $this->sanitize( array( "line1\nline2" ) ) );
		$this->assertFalse( $this->sanitize( array( "line1\rline2" ) ) );
		$this->assertFalse( $this->sanitize( array( "nul\0byte" ) ) );
	}

	/**
	 * Non-string and overlong lines are rejected.
	 */
	public function test_sanitize_rules_rejects_non_string_and_overlong(): void {
		$this->assertFalse( $this->sanitize( array( 123 ) ) );
		$this->assertFalse( $this->sanitize( array( null ) ) );
		$this->assertFalse( $this->sanitize( array( str_repeat( 'a', 5000 ) ) ) );
	}

	/**
	 * Forged wppo_rules markers are rejected so the single-block
	 * verifier assertion cannot be broken by filter input.
	 */
	public function test_sanitize_rules_rejects_forged_markers(): void {
		$this->assertFalse( $this->sanitize( array( '# BEGIN wppo_rules' ) ) );
		$this->assertFalse( $this->sanitize( array( '# END wppo_rules' ) ) );
		$this->assertFalse( $this->sanitize( array( '  # begin   wppo_rules' ) ) );
		// Ordinary comments mentioning other markers stay valid.
		$this->assertSame(
			array( '# BEGIN WordPress', '# WPPO Next-gen delivery' ),
			$this->sanitize( array( '# BEGIN WordPress', '# WPPO Next-gen delivery' ) )
		);
	}

	/**
	 * A CRLF payload smuggled through the rules filter aborts the write
	 * and leaves the file unchanged.
	 */
	public function test_update_rules_rejects_crlf_payload_leaves_file_unchanged(): void {
		$original                              = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n";
		$fs                                    = new WPPO_Hardening_FS_Mock();
		$fs->files['/tmp/wordpress/.htaccess'] = $original;
		$this->stub_htaccess_env( $fs );

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wppo_htaccess_rules' === $hook && is_array( $value ) ) {
					$value[] = "Evil-Header: injected\r\nX-Split: yes";
				}
				return $value;
			}
		);

		$this->assertFalse( Htaccess_Handler::update_rules( true ) );
		$this->assertSame( $original, $fs->files['/tmp/wordpress/.htaccess'] );
	}

	/**
	 * A torn rename (post-write content mismatch) restores the original
	 * file and reports failure — the file stays intact.
	 */
	public function test_torn_write_restores_original_and_returns_false(): void {
		$original                              = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n";
		$fs                                    = new WPPO_Hardening_FS_Mock();
		$fs->files['/tmp/wordpress/.htaccess'] = $original;
		$fs->corrupt_live_after_move           = true;
		$this->stub_htaccess_env( $fs );

		$this->assertFalse( Htaccess_Handler::update_rules( true ) );
		$this->assertSame( $original, $fs->files['/tmp/wordpress/.htaccess'] );
	}

	/**
	 * A failed move (FS failure) returns false and never blanks the file.
	 */
	public function test_fs_move_failure_returns_false_leaves_file_intact(): void {
		$original                              = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n";
		$fs                                    = new WPPO_Hardening_FS_Mock();
		$fs->files['/tmp/wordpress/.htaccess'] = $original;
		$fs->fail_move                         = true;
		$this->stub_htaccess_env( $fs );
		Functions\when( 'insert_with_markers' )->justReturn( false );

		$this->assertFalse( Htaccess_Handler::update_rules( true ) );
		$this->assertSame( $original, $fs->files['/tmp/wordpress/.htaccess'] );
	}

	/**
	 * Sequential (concurrent-simulated) saves keep exactly one marker
	 * block and use distinct tmp names so racers never share a tmp file.
	 */
	public function test_sequential_writes_keep_single_block_with_unique_tmp_names(): void {
		$fs                                    = new WPPO_Hardening_FS_Mock();
		$fs->files['/tmp/wordpress/.htaccess'] = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n";
		$this->stub_htaccess_env( $fs );

		$this->assertTrue( Htaccess_Handler::update_rules( true ) );
		$this->assertTrue( Htaccess_Handler::update_rules( true ) );

		$final = $fs->files['/tmp/wordpress/.htaccess'];
		$this->assertSame( 1, substr_count( $final, '# BEGIN wppo_rules' ) );
		$this->assertSame( 1, substr_count( $final, '# END wppo_rules' ) );
		$this->assertStringContainsString( '# BEGIN WordPress', $final );

		$tmp_paths = array_values(
			array_filter(
				$fs->put_log,
				static function ( $path ) {
					return false !== strpos( (string) $path, '.wppo-tmp-' );
				}
			)
		);
		$this->assertGreaterThanOrEqual( 1, count( $tmp_paths ) );
		$this->assertSame( count( $tmp_paths ), count( array_unique( $tmp_paths ) ) );
	}

	/**
	 * On Nginx the .htaccess write is skipped: success is reported and
	 * the file is never touched (the Nginx snippet is surfaced read-only
	 * via the server_rules endpoint instead).
	 */
	public function test_nginx_skips_htaccess_write(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.24.0';
		LiteSpeed_Integration::reset_cache();

		$original                              = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n";
		$fs                                    = new WPPO_Hardening_FS_Mock();
		$fs->files['/tmp/wordpress/.htaccess'] = $original;
		$this->stub_htaccess_env( $fs );

		$this->assertTrue( Htaccess_Handler::update_rules( true ) );
		$this->assertSame( $original, $fs->files['/tmp/wordpress/.htaccess'] );
		$this->assertSame( array(), $fs->put_log );
		$this->assertTrue( Server_Rules::should_skip_htaccess_write() );
		$this->assertFalse( Server_Rules::supports_htaccess() );
	}

	/**
	 * Multisite on Nginx skips the write as well: server detection is
	 * server-level (shared network-wide) and no per-site file is touched.
	 */
	public function test_multisite_nginx_skips_htaccess_write(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.24.0';
		LiteSpeed_Integration::reset_cache();
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 2 );

		$original                              = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n";
		$fs                                    = new WPPO_Hardening_FS_Mock();
		$fs->files['/tmp/wordpress/.htaccess'] = $original;
		$this->stub_htaccess_env( $fs );

		$this->assertTrue( Htaccess_Handler::update_rules( true ) );
		$this->assertTrue( Htaccess_Handler::remove_rules() );
		$this->assertSame( $original, $fs->files['/tmp/wordpress/.htaccess'] );
	}

	/**
	 * Apache and LiteSpeed proceed to the .htaccess write; unknown
	 * servers fail open to the legacy behavior.
	 */
	public function test_htaccess_supported_servers(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.62 (Unix)';
		$this->assertTrue( Server_Rules::supports_htaccess() );
		$this->assertFalse( Server_Rules::should_skip_htaccess_write() );

		$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
		$this->assertTrue( Server_Rules::supports_htaccess() );

		unset( $_SERVER['SERVER_SOFTWARE'] );
		$this->assertTrue( Server_Rules::supports_htaccess() );
		$this->assertFalse( Server_Rules::should_skip_htaccess_write() );
	}

	/**
	 * A successful Apache write produces a verified single marker block.
	 */
	public function test_apache_write_produces_verified_block(): void {
		$fs                                    = new WPPO_Hardening_FS_Mock();
		$fs->files['/tmp/wordpress/.htaccess'] = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n";
		$this->stub_htaccess_env( $fs );

		$this->assertTrue( Htaccess_Handler::update_rules( true ) );

		$final = $fs->files['/tmp/wordpress/.htaccess'];
		$this->assertSame( 1, substr_count( $final, '# BEGIN wppo_rules' ) );
		$this->assertSame( 1, substr_count( $final, '# END wppo_rules' ) );
		$this->assertTrue( Htaccess_Handler::has_rules() );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile

/**
 * In-memory filesystem for htaccess hardening tests.
 *
 * Tracks tmp writes separately so existence checks behave like a real
 * filesystem, and supports failure injection (failed move, torn rename).
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Hardening_FS_Mock {
	/**
	 * Live files keyed by absolute path.
	 *
	 * @var array<string,string>
	 */
	public $files = array();

	/**
	 * Tmp sibling writes keyed by absolute path.
	 *
	 * @var array<string,string>
	 */
	public $tmp_files = array();

	/**
	 * Every put_contents() path, in order.
	 *
	 * @var string[]
	 */
	public $put_log = array();

	/**
	 * When true, move() fails (FS failure simulation).
	 *
	 * @var bool
	 */
	public $fail_move = false;

	/**
	 * When true, move() writes truncated content to the live path to
	 * simulate a torn rename.
	 *
	 * @var bool
	 */
	public $corrupt_live_after_move = false;

	/**
	 * Check whether a path is a tmp sibling.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private function is_tmp_path( $path ): bool {
		return false !== strpos( (string) $path, '.wppo-tmp-' );
	}

	/**
	 * Check existence.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function exists( $path ) {
		$path = (string) $path;
		if ( $this->is_tmp_path( $path ) ) {
			return isset( $this->tmp_files[ $path ] );
		}
		if ( isset( $this->files[ $path ] ) ) {
			return true;
		}
		foreach ( $this->files as $file => $ignored ) {
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
		if ( $this->is_tmp_path( $path ) ) {
			return isset( $this->tmp_files[ $path ] ) ? $this->tmp_files[ $path ] : false;
		}
		return isset( $this->files[ $path ] ) ? $this->files[ $path ] : false;
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
		$this->put_log[] = (string) $path;
		if ( $this->is_tmp_path( $path ) ) {
			$this->tmp_files[ (string) $path ] = (string) $contents;
			return true;
		}
		$this->files[ (string) $path ] = (string) $contents;
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
		if ( $this->fail_move ) {
			return false;
		}
		$src     = (string) $src;
		$dst     = (string) $dst;
		$content = isset( $this->tmp_files[ $src ] ) ? $this->tmp_files[ $src ] : ( isset( $this->files[ $src ] ) ? $this->files[ $src ] : '' );
		if ( $this->corrupt_live_after_move ) {
			$this->files[ $dst ] = "# truncated mid-rename\n";
		} else {
			$this->files[ $dst ] = $content;
		}
		unset( $this->tmp_files[ $src ] );
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
		$src = (string) $src;
		$dst = (string) $dst;
		if ( $this->is_tmp_path( $src ) ) {
			$content = isset( $this->tmp_files[ $src ] ) ? $this->tmp_files[ $src ] : '';
		} else {
			$content = isset( $this->files[ $src ] ) ? $this->files[ $src ] : '';
		}
		if ( $this->is_tmp_path( $dst ) ) {
			$this->tmp_files[ $dst ] = $content;
		} else {
			$this->files[ $dst ] = $content;
		}
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
		unset( $this->files[ $path ], $this->tmp_files[ $path ] );
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
