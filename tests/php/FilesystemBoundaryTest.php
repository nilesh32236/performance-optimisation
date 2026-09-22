<?php
/**
 * Regression tests for the REF-003 Filesystem boundary extraction (issue #1500).
 *
 * Pins byte-identical behavior for the filesystem core moved from `Util` to
 * `PerformanceOptimise\Inc\Filesystem`: containment batteries (encoded `..`,
 * symlinks, prefix-edge cases), minify allow/deny vectors, atomic-write
 * round-trips, verified-PHP-write failure paths, plus facade-proxy
 * equivalence (`Util::x === Filesystem::x`) for every moved method.
 *
 * @package PerformanceOptimise\Tests
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Filesystem;
use PerformanceOptimise\Inc\Util;

/**
 * Filesystem boundary tests.
 *
 * @package PerformanceOptimise\Tests
 */
class FilesystemBoundaryTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Fake ABSPATH root used by the containment batteries.
	 *
	 * @var string
	 */
	private const FAKE_ROOT = '/tmp/wordpress/cache/wppo';

	/**
	 * Reconfigure the URL helpers for local-path vectors.
	 *
	 * @return void
	 */
	private function stub_url_helpers(): void {
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				if ( -1 === $component ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Emulates wp_parse_url() in tests; native int-component support.
					$result = parse_url( (string) $url );
					return false === $result ? false : $result;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- See above.
				$result = parse_url( (string) $url, $component );
				return null === $result ? null : $result;
			}
		);
		Functions\when( 'wp_normalize_path' )->alias(
			static function ( $path ) {
				$path = str_replace( '\\', '/', (string) $path );
				$path = preg_replace( '|(?<=.)/+|', '/', (string) $path );
				if ( str_starts_with( (string) $path, '//' ) ) {
					$path = '/' . ltrim( (string) $path, '/' );
				}
				return $path;
			}
		);
		Functions\when( 'home_url' )->alias(
			static function () {
				return 'http://example.com';
			}
		);
	}

	/**
	 * Lexical containment: inside passes, sibling-prefix never matches.
	 */
	public function test_cache_path_contained_prefix_edges(): void {
		$this->stub_url_helpers();
		$root   = self::FAKE_ROOT;
		$domain = 'example.com';

		$this->assertTrue( Filesystem::is_cache_path_contained( $root, $domain, $root . '/example.com/index.html' ) );
		$this->assertTrue( Filesystem::is_cache_path_contained( $root, $domain, $root . '/example.com/a/b/c.html' ) );
		// Sibling-prefix directories must never prefix-match.
		$this->assertFalse( Filesystem::is_cache_path_contained( $root, $domain, $root . '/example.com-evil/index.html' ) );
		$this->assertFalse( Filesystem::is_cache_path_contained( $root, $domain, $root . '/other.com/index.html' ) );
		$this->assertFalse( Filesystem::is_cache_path_contained( $root, $domain, '/etc/passwd' ) );
		// Fail-closed inputs.
		$this->assertFalse( Filesystem::is_cache_path_contained( '', $domain, $root . '/example.com/index.html' ) );
		$this->assertFalse( Filesystem::is_cache_path_contained( $root, '', $root . '/example.com/index.html' ) );
		$this->assertFalse( Filesystem::is_cache_path_contained( $root, $domain, '' ) );
		$this->assertFalse( Filesystem::is_cache_path_contained( $root, $domain, $root . "/example.com/\0.html" ) );
		$this->assertFalse( Filesystem::is_cache_path_contained( $root, $domain, $root . '/example.com/../evil.html' ) );
	}

	/**
	 * Write-path validator rejects traversal payloads outright.
	 */
	public function test_validate_cache_write_path_vectors(): void {
		$this->stub_url_helpers();
		$root   = self::FAKE_ROOT;
		$domain = 'example.com';

		$this->assertTrue( Filesystem::validate_cache_write_path( $root, $domain, $root . '/example.com/page/index.html' ) );
		$this->assertFalse( Filesystem::validate_cache_write_path( $root, $domain, $root . '/example.com/../evil.html' ) );
		$this->assertFalse( Filesystem::validate_cache_write_path( $root, $domain, $root . "/example.com/\0evil.html" ) );
		$this->assertFalse( Filesystem::validate_cache_write_path( $root, $domain, '/tmp/wordpress/cache/wppo-evil/example.com/index.html' ) );
		// Hostile domain segments fail closed.
		$this->assertFalse( Filesystem::validate_cache_write_path( $root, '../evil', $root . '/example.com/index.html' ) );
		$this->assertFalse( Filesystem::validate_cache_write_path( $root, 'a/b', $root . '/example.com/index.html' ) );
	}

	/**
	 * Symlink-aware containment: escapes fail closed, fresh trees stay writable.
	 */
	public function test_realpath_contained_symlink_battery(): void {
		$this->stub_url_helpers();
		$sandbox = sys_get_temp_dir() . '/wppo-fs-' . getmypid();
		$outside = sys_get_temp_dir() . '/wppo-fs-outside-' . getmypid();
		$domain  = 'example.com';
		try {
			$this->make_sandbox_dir( $sandbox . '/' . $domain . '/sub' );
			$this->make_sandbox_dir( $outside );
			if ( ! is_dir( $sandbox . '/' . $domain . '/sub' ) || ! is_dir( $outside ) ) {
				$this->markTestSkipped( 'Could not create real-FS sandbox for symlink battery.' );
			}

			// Brand-new (not yet existing) page tree: nothing resolves, lexical verdict stands.
			$this->assertTrue( Filesystem::is_realpath_contained( $sandbox, $domain, $sandbox . '/' . $domain . '/brand-new-page/index.html' ) );
			// Existing directory inside the tree.
			$this->assertTrue( Filesystem::is_realpath_contained( $sandbox, $domain, $sandbox . '/' . $domain . '/sub' ) );
			// Lexical outsider still refused.
			$this->assertFalse( Filesystem::is_realpath_contained( $sandbox, $domain, $outside . '/x.html' ) );

			// Symlink escape planted inside the tree resolves outside and fails closed.
			$link = $sandbox . '/' . $domain . '/evil-link';
			if ( function_exists( 'symlink' ) && @symlink( $outside, $link ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Symlink may be unavailable on some hosts; guarded below.
				$this->assertFalse( Filesystem::is_realpath_contained( $sandbox, $domain, $link ), 'Symlinked purge target escaping the tree must fail closed.' );
				$this->assertFalse( Filesystem::is_realpath_contained( $sandbox, $domain, $link . '/nested.html' ), 'Paths beneath a symlinked escape must fail closed.' );
			}
			// Symlink staying inside the tree keeps working.
			$inner = $sandbox . '/' . $domain . '/inner-link';
			if ( function_exists( 'symlink' ) && @symlink( $sandbox . '/' . $domain . '/sub', $inner ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- See above.
				$this->assertTrue( Filesystem::is_realpath_contained( $sandbox, $domain, $inner ) );
			}
		} finally {
			$this->remove_sandbox( $sandbox );
			$this->remove_sandbox( $outside );
		}
	}

	/**
	 * Recursively create a test sandbox directory (plain PHP, no WP dependency).
	 *
	 * @param string $dir Directory to create.
	 * @return void
	 */
	private function make_sandbox_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
		}
	}

	/**
	 * Recursively remove a test sandbox directory.
	 *
	 * @param string $dir Directory to remove.
	 * @return void
	 */
	private function remove_sandbox( string $dir ): void {
		if ( '' === $dir || ! file_exists( $dir ) ) {
			return;
		}
		if ( is_link( $dir ) ) {
			unlink( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			return;
		}
		if ( ! is_dir( $dir ) ) {
			unlink( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			return;
		}
		foreach ( (array) glob( $dir . '/*' ) as $entry ) {
			$this->remove_sandbox( (string) $entry );
		}
		foreach ( (array) glob( $dir . '/.*' ) as $entry ) {
			if ( '.' === basename( (string) $entry ) || '..' === basename( (string) $entry ) ) {
				continue;
			}
			$this->remove_sandbox( (string) $entry );
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
	}

	/**
	 * Realpath resolver keeps the lexical remainder for not-yet-existing paths.
	 */
	public function test_resolve_realpath_vectors(): void {
		$this->stub_url_helpers();
		$this->assertNull( Filesystem::resolve_realpath( '' ) );

		$existing = (string) realpath( sys_get_temp_dir() );
		$this->assertNotSame( '', $existing );
		$resolved = Filesystem::resolve_realpath( $existing );
		$this->assertIsString( $resolved );
		$this->assertSame( wp_normalize_path( $existing ), $resolved );

		// Not-yet-existing leaf: nearest existing ancestor plus remainder.
		$child = Filesystem::resolve_realpath( $existing . '/wppo-no-such-leaf-' . getmypid() );
		$this->assertIsString( $child );
		$this->assertStringEndsWith( '/wppo-no-such-leaf-' . getmypid(), (string) $child );
	}

	/**
	 * Cache-path builder: contained mapping in, probes out.
	 */
	public function test_sanitize_cache_path_vectors(): void {
		$this->stub_url_helpers();
		$root   = self::FAKE_ROOT;
		$domain = 'example.com';

		$this->assertSame(
			$root . '/example.com/hello/index.html',
			Filesystem::sanitize_cache_path( $root, $domain, '/hello', 'index.html' )
		);
		$this->assertSame(
			$root . '/example.com/index.html',
			Filesystem::sanitize_cache_path( $root, $domain, '/', 'index.html' )
		);
		// Same-host absolute URL maps to its path.
		$this->assertSame(
			$root . '/example.com/hello/index.html',
			Filesystem::sanitize_cache_path( $root, $domain, 'http://example.com/hello', 'index.html' )
		);
		// Query strings carrying URLs never false-positive the absolute-form gate.
		$this->assertSame(
			$root . '/example.com/search/index.html',
			Filesystem::sanitize_cache_path( $root, $domain, '/search?redirect=https://other.test/', 'index.html' )
		);
		// Probes refused.
		$this->assertSame( '', Filesystem::sanitize_cache_path( $root, $domain, 'https://evil.test/hello', 'index.html' ) );
		$this->assertSame( '', Filesystem::sanitize_cache_path( $root, $domain, '/a/../../x', 'index.html' ) );
		$this->assertSame( '', Filesystem::sanitize_cache_path( $root, $domain, '/%2e%2e/x', 'index.html' ) );
		$this->assertSame( '', Filesystem::sanitize_cache_path( $root, $domain, '/hello', '../evil.html' ) );
		$this->assertSame( '', Filesystem::sanitize_cache_path( $root, $domain, '/hello', 'has space.html' ) );
		$this->assertSame( '', Filesystem::sanitize_cache_path( '', $domain, '/hello', 'index.html' ) );
		$this->assertSame( '', Filesystem::sanitize_cache_path( $root, 'not a host!!', '/hello', 'index.html' ) );
		$this->assertSame( '', Filesystem::sanitize_cache_path( $root, $domain, '//evil.test/hello', 'index.html' ) );
	}

	/**
	 * Host normalization vectors.
	 */
	public function test_normalize_cache_host_vectors(): void {
		$this->assertSame( 'example.com', Filesystem::normalize_cache_host( 'Example.COM ' ) );
		$this->assertSame( 'example.com', Filesystem::normalize_cache_host( 'example.com:8080' ) );
		$this->assertSame( '::1', Filesystem::normalize_cache_host( '[::1]:8080' ) );
		$this->assertSame( '', Filesystem::normalize_cache_host( '' ) );
		$this->assertSame( '', Filesystem::normalize_cache_host( 'a/../b' ) );
		$this->assertSame( '', Filesystem::normalize_cache_host( 'a/b' ) );
		$this->assertSame( '', Filesystem::normalize_cache_host( '[::1]evil' ) );
		$this->assertSame( '', Filesystem::normalize_cache_host( '[::1' ) );
	}

	/**
	 * URL-path sanitizer: single-decode semantics, foreign-host refusal.
	 */
	public function test_sanitize_cache_url_path_vectors(): void {
		$this->stub_url_helpers();
		$this->assertSame( 'a/b', Filesystem::sanitize_cache_url_path( '/a/b' ) );
		$this->assertSame( '', Filesystem::sanitize_cache_url_path( '/%2e%2e/x' ) );
		$this->assertSame( '', Filesystem::sanitize_cache_url_path( '/%00/x' ) );
		// Double-encoded stays literal on disk (never re-decoded).
		$this->assertSame( '%2e/x', Filesystem::sanitize_cache_url_path( '/%252e/x' ) );
		$this->assertSame( '', Filesystem::sanitize_cache_url_path( 'C:\\foo' ) );
		$this->assertSame( '', Filesystem::sanitize_cache_url_path( 'https://evil.test/x', 'example.com' ) );
		$this->assertSame( 'x', Filesystem::sanitize_cache_url_path( 'https://example.com/x', 'example.com' ) );
		$this->assertSame( '', Filesystem::sanitize_cache_url_path( 'https://example.com?x=1', 'example.com' ) );
		// Benign filename containing `..` is not over-blocked.
		$this->assertSame( 'my..photo.jpg', Filesystem::sanitize_cache_url_path( '/my..photo.jpg' ) );
	}

	/**
	 * Local-path mapping: ABSPATH bounds, wrapper and traversal refusal.
	 */
	public function test_get_local_path_vectors(): void {
		$this->stub_url_helpers();
		$this->assertSame(
			'/tmp/wordpress/wp-content/themes/my-theme/style.css',
			Filesystem::get_local_path( 'http://example.com/wp-content/themes/my-theme/style.css' )
		);
		$this->assertSame( '', Filesystem::get_local_path( 'php://filter/convert.base64-encode/resource=/etc/passwd' ) );
		$this->assertSame( '', Filesystem::get_local_path( 'file:///etc/passwd' ) );
		$this->assertSame( '', Filesystem::get_local_path( 'data:text/html,<p>x</p>' ) );
		$this->assertSame( '', Filesystem::get_local_path( 'http://example.com/../../etc/passwd' ) );
		$this->assertSame( '', Filesystem::get_local_path( 'http://example.com/%2e%2e/%2e%2e/etc/passwd' ) );
		$this->assertSame( '', Filesystem::get_local_path( "http://example.com/\0/x.css" ) );
	}

	/**
	 * Subdirectory installs strip the home path prefix only.
	 */
	public function test_get_local_path_subdirectory_install(): void {
		$this->stub_url_helpers();
		Functions\when( 'home_url' )->alias(
			static function () {
				return 'http://example.com/subdir';
			}
		);
		Util::reset_runtime_caches();
		$this->assertSame(
			'/tmp/wordpress/wp-content/a.css',
			Filesystem::get_local_path( 'http://example.com/subdir/wp-content/a.css' )
		);
		$this->assertSame(
			Util::get_local_path( 'http://example.com/subdir/wp-content/a.css' ),
			Filesystem::get_local_path( 'http://example.com/subdir/wp-content/a.css' )
		);
	}

	/**
	 * Minify gate: wrappers, PHP targets, and missing files refused.
	 */
	public function test_minify_path_deny_vectors(): void {
		$this->stub_url_helpers();
		$this->assertFalse( Filesystem::is_minify_path_allowed( '' ) );
		$this->assertFalse( Filesystem::is_minify_path_allowed( null ) );
		$this->assertFalse( Filesystem::is_minify_path_allowed( array( 'x' ) ) );
		$this->assertFalse( Filesystem::is_minify_path_allowed( "/tmp/wordpress/\0.css" ) );
		$this->assertFalse( Filesystem::is_minify_path_allowed( '/tmp/wordpress/../etc/passwd' ) );
		$this->assertFalse( Filesystem::is_minify_path_allowed( 'php://filter/resource=/tmp/wordpress/a.css' ) );
		$this->assertFalse( Filesystem::is_minify_path_allowed( 'data:text/css,body{}' ) );
		$this->assertFalse( Filesystem::is_minify_path_allowed( '/tmp/wordpress/wp-config.php' ) );
		$this->assertFalse( Filesystem::is_minify_path_allowed( '/tmp/wordpress/no-such-dir-' . getmypid() . '/a.css' ) );
		$this->assertSame( '', Filesystem::validate_minify_path( '/tmp/wordpress/wp-config.php' ) );
	}

	/**
	 * Minify gate: real files inside the allowed roots resolve.
	 */
	public function test_minify_path_allow_vectors(): void {
		$this->stub_url_helpers();
		$dir  = '/tmp/wordpress/wp-content/wppo-fs-allow-' . getmypid();
		$file = $dir . '/a.css';
		$link = $dir . '/escape.css';
		try {
			$this->make_sandbox_dir( $dir );
			file_put_contents( $file, 'body{}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
			if ( ! is_readable( $file ) ) {
				$this->markTestSkipped( 'Could not create real-FS minify fixture.' );
			}
			$roots = Filesystem::get_minify_allowed_roots();
			$this->assertContains( wp_normalize_path( ABSPATH ), $roots );
			$this->assertContains( wp_normalize_path( (string) WP_CONTENT_DIR ), $roots );

			$this->assertTrue( Filesystem::is_minify_path_allowed( $file ) );
			$this->assertSame( (string) realpath( $file ), Filesystem::validate_minify_path( $file ) );

			// A symlink inside the roots pointing outside resolves outside and is refused.
			if ( function_exists( 'symlink' ) && @symlink( '/etc/hostname', $link ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Symlink may be unavailable on some hosts; guarded.
				$this->assertFalse( Filesystem::is_minify_path_allowed( $link ), 'Symlink escaping the allowed roots must be refused.' );
			}
			// Sibling-prefix directory outside the roots never matches.
			$this->assertFalse( Filesystem::is_minify_path_allowed( sys_get_temp_dir() . '/wppo-fs-allow-' . getmypid() . '.css' ) );
		} finally {
			$this->remove_sandbox( $dir );
		}
	}

	/**
	 * Atomic tmp paths are unique siblings of the final path.
	 */
	public function test_atomic_tmp_path_vectors(): void {
		$this->assertSame( '', Filesystem::atomic_tmp_path( '' ) );
		$first  = Filesystem::atomic_tmp_path( '/tmp/x.css' );
		$second = Filesystem::atomic_tmp_path( '/tmp/x.css' );
		$this->assertStringStartsWith( '/tmp/x.css.tmp.', $first );
		$this->assertStringStartsWith( '/tmp/x.css.tmp.', $second );
		$this->assertNotSame( $first, $second );
	}

	/**
	 * Atomic write round-trip via an in-memory filesystem mock.
	 */
	public function test_atomic_file_put_contents_round_trip(): void {
		$fs = new WPPO_Filesystem_Boundary_FS_Mock();
		$this->assertTrue( Filesystem::atomic_file_put_contents( $fs, '/tmp/atomic.css', 'body{}' ) );
		$this->assertSame( 'body{}', $fs->store['/tmp/atomic.css'] ?? null );

		$this->assertFalse( Filesystem::atomic_file_put_contents( $fs, '', 'body{}' ) );
		$this->assertFalse( Filesystem::atomic_file_put_contents( new \stdClass(), '/tmp/atomic.css', 'body{}' ) );

		$failing_move              = new WPPO_Filesystem_Boundary_FS_Mock();
		$failing_move->move_result = false;
		$this->assertFalse( Filesystem::atomic_file_put_contents( $failing_move, '/tmp/atomic.css', 'body{}' ) );
		$this->assertArrayNotHasKey( '/tmp/atomic.css', $failing_move->store );
	}

	/**
	 * PHP syntax gate vectors.
	 */
	public function test_verify_php_syntax_vectors(): void {
		$this->assertTrue( Filesystem::verify_php_syntax( "<?php\necho 1;\n" ) );
		$this->assertTrue( Filesystem::verify_php_syntax( "\xEF\xBB\xBF<?php\necho 1;\n" ) );
		$this->assertFalse( Filesystem::verify_php_syntax( '' ) );
		$this->assertFalse( Filesystem::verify_php_syntax( "echo 1;\n" ) );
		$this->assertFalse( Filesystem::verify_php_syntax( "<?php if ( true ) { echo 1;\n" ) );
		$this->assertFalse( Filesystem::verify_php_syntax( "<?php function foo( {\n" ) );
	}

	/**
	 * Verified PHP write: success, failure, and unsupported-transport paths.
	 */
	public function test_atomic_write_php_verified_vectors(): void {
		$valid = "<?php\ndefine( 'DB_NAME', 'test' );\n";

		// Success path.
		$fs                              = new WPPO_Filesystem_Boundary_FS_Mock();
		$fs->store['/tmp/wp-config.php'] = $valid;
		$new                             = $valid . "define( 'WP_CACHE', true );\n";
		$this->assertTrue(
			Filesystem::atomic_write_php_verified(
				$fs,
				'/tmp/wp-config.php',
				$new,
				static function ( $c ): bool {
					return is_string( $c ) && false !== strpos( $c, 'WP_CACHE' );
				}
			)
		);
		$this->assertSame( $new, $fs->store['/tmp/wp-config.php'] );
		$this->assertArrayNotHasKey( '/tmp/wp-config.php.wppo-bak', $fs->store );

		// Broken syntax leaves the live file untouched.
		$fs2                              = new WPPO_Filesystem_Boundary_FS_Mock();
		$fs2->store['/tmp/wp-config.php'] = $valid;
		$this->assertFalse( Filesystem::atomic_write_php_verified( $fs2, '/tmp/wp-config.php', "<?php if ( true ) { broken\n" ) );
		$this->assertSame( $valid, $fs2->store['/tmp/wp-config.php'] );

		// Failing caller expectation leaves the live file untouched.
		$fs3                              = new WPPO_Filesystem_Boundary_FS_Mock();
		$fs3->store['/tmp/wp-config.php'] = $valid;
		$this->assertFalse(
			Filesystem::atomic_write_php_verified(
				$fs3,
				'/tmp/wp-config.php',
				$new,
				static function (): bool {
					return false;
				}
			)
		);
		$this->assertSame( $valid, $fs3->store['/tmp/wp-config.php'] );

		// Empty contents and unsupported transports.
		$this->assertFalse( Filesystem::atomic_write_php_verified( $fs3, '/tmp/wp-config.php', '' ) );
		$this->assertNull( Filesystem::atomic_write_php_verified( new \stdClass(), '/tmp/wp-config.php', $new ) );
		$this->assertFalse( Filesystem::atomic_write_php_verified( $fs3, '', $new ) );
	}

	/**
	 * Htaccess isolation guard vectors.
	 */
	public function test_htaccess_path_allowed_vectors(): void {
		$this->assertTrue( Filesystem::is_htaccess_path_allowed( '/tmp/wordpress/.htaccess' ) );
		$this->assertFalse( Filesystem::is_htaccess_path_allowed( '' ) );
		$this->assertFalse( Filesystem::is_htaccess_path_allowed( '/tmp/wordpress/nginx.conf' ) );
		$this->assertFalse( Filesystem::is_htaccess_path_allowed( '/tmp/wordpress/../.htaccess' ) );
		$this->assertFalse( Filesystem::is_htaccess_path_allowed( "/tmp/wordpress/\0.htaccess" ) );
		$this->assertFalse(
			Filesystem::is_htaccess_path_allowed( wp_normalize_path( (string) WP_CONTENT_DIR ) . '/cache/wppo/.htaccess' )
		);
	}

	/**
	 * Facade proxies: Util::x === Filesystem::x on every moved method.
	 */
	public function test_util_proxies_match_boundary(): void {
		$this->stub_url_helpers();
		$root   = self::FAKE_ROOT;
		$domain = 'example.com';
		$path   = $root . '/example.com/a/index.html';

		$this->assertSame( Filesystem::is_cache_path_contained( $root, $domain, $path ), Util::is_cache_path_contained( $root, $domain, $path ) );
		$this->assertSame( Filesystem::is_cache_path_contained( $root, $domain, '/etc/passwd' ), Util::is_cache_path_contained( $root, $domain, '/etc/passwd' ) );
		$this->assertSame( Filesystem::validate_cache_write_path( $root, $domain, $path ), Util::validate_cache_write_path( $root, $domain, $path ) );
		$this->assertSame( Filesystem::validate_cache_write_path( $root, '../x', $path ), Util::validate_cache_write_path( $root, '../x', $path ) );
		$this->assertSame( Filesystem::resolve_realpath( sys_get_temp_dir() ), Util::resolve_realpath( sys_get_temp_dir() ) );
		$this->assertSame( Filesystem::resolve_realpath( '' ), Util::resolve_realpath( '' ) );
		$this->assertSame( Filesystem::is_htaccess_path_allowed( '/tmp/wordpress/.htaccess' ), Util::is_htaccess_path_allowed( '/tmp/wordpress/.htaccess' ) );
		$this->assertSame(
			Filesystem::sanitize_cache_path( $root, $domain, '/hello', 'index.html' ),
			Util::sanitize_cache_path( $root, $domain, '/hello', 'index.html' )
		);
		$this->assertSame(
			Filesystem::sanitize_cache_path( $root, $domain, 'https://evil.test/x', 'index.html' ),
			Util::sanitize_cache_path( $root, $domain, 'https://evil.test/x', 'index.html' )
		);
		$this->assertSame( Filesystem::normalize_cache_host( 'Example.COM:8080' ), Util::normalize_cache_host( 'Example.COM:8080' ) );
		$this->assertSame( Filesystem::normalize_cache_host( 'bad/../host' ), Util::normalize_cache_host( 'bad/../host' ) );
		$this->assertSame( Filesystem::sanitize_cache_url_path( '/a/b' ), Util::sanitize_cache_url_path( '/a/b' ) );
		$this->assertSame( Filesystem::sanitize_cache_url_path( '/%2e%2e/x', 'example.com' ), Util::sanitize_cache_url_path( '/%2e%2e/x', 'example.com' ) );
		$this->assertSame( Filesystem::get_local_path( 'http://example.com/wp-content/a.css' ), Util::get_local_path( 'http://example.com/wp-content/a.css' ) );
		$this->assertSame( Filesystem::get_local_path( 'php://filter/x' ), Util::get_local_path( 'php://filter/x' ) );
		$this->assertSame( Filesystem::get_minify_allowed_roots(), Util::get_minify_allowed_roots() );
		$this->assertSame( Filesystem::is_minify_path_allowed( '/tmp/wordpress/wp-config.php' ), Util::is_minify_path_allowed( '/tmp/wordpress/wp-config.php' ) );
		$this->assertSame( Filesystem::validate_minify_path( 'php://x' ), Util::validate_minify_path( 'php://x' ) );
		$this->assertSame( Filesystem::verify_php_syntax( "<?php\necho 1;\n" ), Util::verify_php_syntax( "<?php\necho 1;\n" ) );
		$this->assertSame( Filesystem::verify_php_syntax( "<?php broken ((\n" ), Util::verify_php_syntax( "<?php broken ((\n" ) );
		$this->assertSame( Filesystem::get_js_css_minified_file(), Util::get_js_css_minified_file() );
		$this->assertSame( Filesystem::init_filesystem(), Util::init_filesystem() );
		$this->assertSame( Filesystem::prepare_cache_dir( '' ), Util::prepare_cache_dir( '' ) );

		// Atomic writers with fresh identical fixtures per side.
		$make = static function (): WPPO_Filesystem_Boundary_FS_Mock {
			$fs                      = new WPPO_Filesystem_Boundary_FS_Mock();
			$fs->store['/tmp/p.css'] = 'a{}';
			return $fs;
		};
		$this->assertSame(
			Filesystem::atomic_file_put_contents( $make(), '/tmp/p.css', 'b{}' ),
			Util::atomic_file_put_contents( $make(), '/tmp/p.css', 'b{}' )
		);
		$valid = "<?php\ndefine( 'X', 1 );\n";
		$mkphp = static function () use ( $valid ): WPPO_Filesystem_Boundary_FS_Mock {
			$fs                      = new WPPO_Filesystem_Boundary_FS_Mock();
			$fs->store['/tmp/w.php'] = $valid;
			return $fs;
		};
		$this->assertSame(
			Filesystem::atomic_write_php_verified( $mkphp(), '/tmp/w.php', $valid . "define( 'Y', 2 );\n" ),
			Util::atomic_write_php_verified( $mkphp(), '/tmp/w.php', $valid . "define( 'Y', 2 );\n" )
		);
		$this->assertSame(
			Filesystem::atomic_write_php_verified( $mkphp(), '/tmp/w.php', "<?php broken ((\n" ),
			Util::atomic_write_php_verified( $mkphp(), '/tmp/w.php', "<?php broken ((\n" )
		);

		// Tmp paths are unique per call: structural equivalence only.
		foreach ( array( '', '/tmp/x.css' ) as $candidate ) {
			$a = Filesystem::atomic_tmp_path( $candidate );
			$b = Util::atomic_tmp_path( $candidate );
			if ( '' === $candidate ) {
				$this->assertSame( '', $a );
				$this->assertSame( '', $b );
				continue;
			}
			$this->assertStringStartsWith( $candidate . '.tmp.', $a );
			$this->assertStringStartsWith( $candidate . '.tmp.', $b );
		}
	}

	/**
	 * Counts dir stays pinned to Util::min_cache_dir() (single source of truth).
	 *
	 * The private Filesystem::min_cache_dir_for_counts() mirrors
	 * Util::min_cache_dir() by design (decoupling); this regression test
	 * fails the suite on future drift that would silently corrupt the
	 * dashboard JS/CSS counts.
	 */
	public function test_min_cache_dir_counts_matches_util(): void {
		$this->stub_url_helpers();
		Util::reset_runtime_caches();
		$method = new \ReflectionMethod( Filesystem::class, 'min_cache_dir_for_counts' );
		foreach ( array( 1, 2 ) as $blog_id ) {
			Functions\when( 'get_current_blog_id' )->justReturn( $blog_id );
			$this->assertSame( Util::min_cache_dir(), $method->invoke( null ), "Counts dir drifted for blog {$blog_id}." );
		}
	}

	/**
	 * Local-path mapping stays pinned to the Util facade on root + subdirectory installs.
	 *
	 * The private Filesystem::home_url_for_local_path() mirrors the no-arg
	 * form of Util::cached_home_url() with extra early-boot guards; this
	 * regression test fails the suite on drift that would silently break
	 * subdirectory-install path stripping in get_local_path().
	 */
	public function test_get_local_path_matches_util_across_installs(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		foreach ( array( 'http://example.com', 'http://example.com/subdir' ) as $home ) {
			$this->stub_url_helpers();
			Functions\when( 'has_filter' )->justReturn( false );
			Functions\when( 'home_url' )->alias(
				static function () use ( $home ) {
					return $home;
				}
			);
			Util::reset_runtime_caches();
			$urls = array(
				$home . '/wp-content/a.css',
				$home . '/wp-content/themes/my-theme/style.css',
				'php://filter/convert.base64-encode/resource=/etc/passwd',
				'http://example.com/../../etc/passwd',
			);
			foreach ( $urls as $url ) {
				$this->assertSame(
					Util::get_local_path( $url ),
					Filesystem::get_local_path( $url ),
					"Local-path drift for home {$home} url {$url}."
				);
			}
		}
	}

	/**
	 * Minify roots memo: one upload-dir lookup per blog, bypassed when filtered.
	 */
	public function test_minify_allowed_roots_memoization(): void {
		$this->stub_url_helpers();
		Functions\when( 'has_filter' )->justReturn( false );
		$upload_calls = 0;
		Functions\when( 'wp_upload_dir' )->alias(
			static function () use ( &$upload_calls ) {
				++$upload_calls;
				return array( 'basedir' => '/tmp/wordpress/wp-content/uploads' );
			}
		);
		Filesystem::reset_minify_roots_cache();

		$first  = Filesystem::get_minify_allowed_roots();
		$second = Filesystem::get_minify_allowed_roots();
		$this->assertSame( $first, $second );
		$this->assertSame( 1, $upload_calls, 'Second call must hit the per-blog memo.' );

		// Hot-path callers (validate per asset) reuse the memo.
		Filesystem::validate_minify_path( '/tmp/wordpress/wp-config.php' );
		$this->assertSame( 1, $upload_calls, 'validate_minify_path() must reuse the memo.' );

		// Per-blog isolation: a new blog recomputes once, then memoizes.
		Functions\when( 'get_current_blog_id' )->justReturn( 2 );
		Filesystem::get_minify_allowed_roots();
		$this->assertSame( 2, $upload_calls, 'New blog must recompute once.' );
		Filesystem::get_minify_allowed_roots();
		$this->assertSame( 2, $upload_calls, 'Repeat call on the new blog must hit the memo.' );

		// Filter present: bypass the memo so context-dependent output is never cached.
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'has_filter' )->justReturn( 10 );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				return $value;
			}
		);
		Filesystem::reset_minify_roots_cache();
		$upload_calls = 0;
		Filesystem::get_minify_allowed_roots();
		Filesystem::get_minify_allowed_roots();
		$this->assertSame( 2, $upload_calls, 'Filtered calls must bypass the memo.' );
	}

	/**
	 * REF-012 slot helpers stay in Util (explicit non-goal of REF-003).
	 */
	public function test_rollout_slot_helpers_stay_in_util(): void {
		foreach ( array( 'get_staged_path_for', 'promote_staged_file', 'is_purge_fallback_enabled', 'describe_rollout_slot', 'css_file_valid' ) as $method ) {
			$this->assertTrue( method_exists( Util::class, $method ), "Util must keep {$method} (REF-012)." );
			$this->assertFalse( method_exists( Filesystem::class, $method ), "Filesystem must not own {$method} (REF-012)." );
		}
	}

	/**
	 * Guardrail: Util must not exceed 170 methods after the extraction.
	 */
	public function test_util_method_count_guardrail(): void {
		$count = count( ( new \ReflectionClass( Util::class ) )->getMethods() );
		$this->assertLessThanOrEqual( 170, $count, "Util grew past 170 methods ({$count})." );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Test fixture co-located by convention.
/**
 * In-memory WP_Filesystem-shaped mock for the boundary tests.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Filesystem_Boundary_FS_Mock {
	/**
	 * File store keyed by path.
	 *
	 * @var array<string, string>
	 */
	public $store = array();

	/**
	 * Whether put_contents succeeds.
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
	 * Whether a path exists.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function exists( $path ): bool {
		return array_key_exists( $path, $this->store );
	}

	/**
	 * Whether a path is a directory (mock: never).
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function is_dir( $path ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return false;
	}

	/**
	 * Make a directory (mock: always true).
	 *
	 * @param string $path Path.
	 * @param int    $chmod Mode.
	 * @return bool
	 */
	public function mkdir( $path, $chmod = 0755 ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return true;
	}

	/**
	 * Read a path.
	 *
	 * @param string $path Path.
	 * @return string|false
	 */
	public function get_contents( $path ) {
		return array_key_exists( $path, $this->store ) ? $this->store[ $path ] : false;
	}

	/**
	 * Write a path.
	 *
	 * @param string $path Path.
	 * @param string $contents Contents.
	 * @param int    $chmod Mode.
	 * @return bool
	 */
	public function put_contents( $path, $contents, $chmod = 0644 ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! $this->put_result ) {
			return false;
		}
		$this->store[ $path ] = $contents;
		return true;
	}

	/**
	 * Move a path.
	 *
	 * @param string $from Source.
	 * @param string $to Destination.
	 * @param bool   $overwrite Overwrite.
	 * @return bool
	 */
	public function move( $from, $to, $overwrite = false ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! $this->move_result || ! array_key_exists( $from, $this->store ) ) {
			return false;
		}
		$this->store[ $to ] = $this->store[ $from ];
		unset( $this->store[ $from ] );
		return true;
	}

	/**
	 * Copy a path.
	 *
	 * @param string $from Source.
	 * @param string $to Destination.
	 * @param bool   $overwrite Overwrite.
	 * @return bool
	 */
	public function copy( $from, $to, $overwrite = false ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! array_key_exists( $from, $this->store ) ) {
			return false;
		}
		$this->store[ $to ] = $this->store[ $from ];
		return true;
	}

	/**
	 * Delete a path.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function delete( $path ): bool {
		unset( $this->store[ $path ] );
		return true;
	}
}
