<?php
/**
 * Realpath containment tests for the static-cache write path (issue #1198).
 *
 * Covers Util::is_realpath_contained() (lexical fail-closed parity plus
 * symlink-escape rejection), Util::validate_cache_write_path(), and the
 * Htaccess_Handler isolation guard (cache-tree targets refused, pre-existing
 * content left byte-identical).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Htaccess_Handler;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Test fixtures are co-located by convention.
// phpcs:disable WordPress.Files.FileName -- Fixture class names cannot derive the *Test.php file name.

/**
 * Recording filesystem for htaccess isolation tests.
 *
 * Real methods are required because atomic_write_verified() guards on
 * method_exists().
 *
 * @package PerformanceOptimise\Tests
 * @since 2.2.0
 */
class WPPO_Realpath_Fake_Fs {

	/**
	 * Live files keyed by absolute path.
	 *
	 * @var array<string,string>
	 */
	public $files = array();

	/**
	 * Every put_contents() payload keyed by absolute path.
	 *
	 * @var array<string,string>
	 */
	public $put_log = array();

	/**
	 * Check existence.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function exists( $path ) {
		return isset( $this->files[ (string) $path ] );
	}

	/**
	 * Read file contents.
	 *
	 * @param string $path Path.
	 * @return string|false
	 */
	public function get_contents( $path ) {
		$path = (string) $path;
		return isset( $this->files[ $path ] ) ? $this->files[ $path ] : false;
	}

	/**
	 * Write file contents.
	 *
	 * @param string $path     Path.
	 * @param string $contents Contents.
	 * @param int    $chmod    Mode.
	 * @return bool
	 */
	public function put_contents( $path, $contents, $chmod = 0644 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->put_log[ (string) $path ] = (string) $contents;
		$this->files[ (string) $path ]   = (string) $contents;
		return true;
	}

	/**
	 * Move a file.
	 *
	 * @param string $src       Source.
	 * @param string $dst       Destination.
	 * @param bool   $overwrite Overwrite.
	 * @return bool
	 */
	public function move( $src, $dst, $overwrite = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$src = (string) $src;
		$dst = (string) $dst;
		if ( ! isset( $this->files[ $src ] ) ) {
			return false;
		}
		$this->files[ $dst ] = $this->files[ $src ];
		unset( $this->files[ $src ] );
		return true;
	}

	/**
	 * Copy a file.
	 *
	 * @param string $src       Source.
	 * @param string $dst       Destination.
	 * @param bool   $overwrite Overwrite.
	 * @return bool
	 */
	public function copy( $src, $dst, $overwrite = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$src = (string) $src;
		$dst = (string) $dst;
		if ( ! isset( $this->files[ $src ] ) ) {
			return false;
		}
		$this->files[ $dst ] = $this->files[ $src ];
		return true;
	}

	/**
	 * Delete a file.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function delete( $path ) {
		unset( $this->files[ (string) $path ] );
		return true;
	}

	/**
	 * Chmod a file (no-op success).
	 *
	 * @param string $path Path.
	 * @param int    $mode Mode.
	 * @return bool
	 */
	public function chmod( $path, $mode ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return true;
	}
}

/**
 * Realpath containment tests.
 *
 * @package PerformanceOptimise\Tests
 * @since 2.2.0
 */
class CacheRealpathContainmentTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Temp fixture root created per symlink test.
	 *
	 * @var string
	 */
	private $fixture_root = '';

	/**
	 * Set up Brain Monkey and common stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		Util::clear_settings_cache();
	}

	/**
	 * Tear down Brain Monkey and remove temp fixtures.
	 */
	protected function tearDown(): void {
		$this->remove_fixture_root();
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Remove the temp fixture tree.
	 */
	private function remove_fixture_root(): void {
		if ( '' === $this->fixture_root ) {
			return;
		}
		$root               = $this->fixture_root;
		$this->fixture_root = '';
		if ( ! is_dir( $root ) && ! is_link( $root ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $pathname ) {
			if ( is_link( $pathname ) || is_file( $pathname ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture cleanup.
				@unlink( $pathname );
			} elseif ( is_dir( $pathname ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture cleanup.
				@rmdir( $pathname );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture cleanup.
		@rmdir( $root );
	}

	/**
	 * Traversal payloads that must fail closed.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function traversal_payload_provider(): array {
		$root = '/tmp/wordpress/wp-content/cache/wppo';
		return array(
			'dot-dot segment'       => array( "{$root}/example.com/../evil/index.html" ),
			'nested dot-dot'        => array( "{$root}/example.com/a/../../evil/index.html" ),
			'null byte'             => array( $root . "/example.com/a\0b/index.html" ),
			'outside root'          => array( '/tmp/wordpress/wp-content/uploads/evil/index.html' ),
			'absolute escape'       => array( '/etc/passwd' ),
			'drive escape'          => array( 'C:/windows/system32/index.html' ),
			'unc escape'            => array( '\\\\server\\share\\index.html' ),
			'sibling prefix'        => array( "{$root}/example.com-evil/index.html" ),
			'cross-domain'          => array( "{$root}/other.com/about/index.html" ),
			'htaccess leaf smuggle' => array( "{$root}/example.com/../../.htaccess" ),
		);
	}

	/**
	 * Traversal payloads fail closed on both helpers.
	 *
	 * @param string $payload Hostile absolute path.
	 */
	#[DataProvider( 'traversal_payload_provider' )]
	public function test_traversal_payloads_fail_closed( string $payload ): void {
		$root = '/tmp/wordpress/wp-content/cache/wppo';
		$this->assertFalse( Util::is_realpath_contained( $root, 'example.com', $payload ), "realpath accepted: {$payload}" );
		$this->assertFalse( Util::validate_cache_write_path( $root, 'example.com', $payload ), "validator accepted: {$payload}" );
	}

	/**
	 * Empty parts fail closed.
	 */
	public function test_empty_parts_fail_closed(): void {
		$root  = '/tmp/wordpress/wp-content/cache/wppo';
		$valid = "{$root}/example.com/about/index.html";
		$this->assertFalse( Util::is_realpath_contained( '', 'example.com', $valid ) );
		$this->assertFalse( Util::is_realpath_contained( $root, '', $valid ) );
		$this->assertFalse( Util::is_realpath_contained( $root, 'example.com', '' ) );
		$this->assertFalse( Util::validate_cache_write_path( '', 'example.com', $valid ) );
		$this->assertFalse( Util::validate_cache_write_path( $root, '', $valid ) );
		$this->assertFalse( Util::validate_cache_write_path( $root, 'example.com', '' ) );
	}

	/**
	 * Benign contained paths pass (nothing exists on disk, lexical verdict stands).
	 */
	public function test_benign_paths_pass(): void {
		$root = '/tmp/wordpress/wp-content/cache/wppo';
		foreach ( array(
			"{$root}/example.com/index.html",
			"{$root}/example.com/about/index.html",
			"{$root}/example.com/about/us/index.html",
		) as $path ) {
			$this->assertTrue( Util::is_realpath_contained( $root, 'example.com', $path ), "realpath refused: {$path}" );
			$this->assertTrue( Util::validate_cache_write_path( $root, 'example.com', $path ), "validator refused: {$path}" );
		}
	}

	/**
	 * A symlink planted inside the cache tree cannot redirect a write outside.
	 */
	public function test_symlink_escape_is_rejected(): void {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() unavailable.' );
		}
		$base = rtrim( sys_get_temp_dir(), '/\\' ) . '/wppo-realpath-' . uniqid();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
		if ( ! mkdir( $base . '/cache/wppo/example.com', 0777, true ) || ! mkdir( $base . '/outside', 0777, true ) ) {
			$this->markTestSkipped( 'Cannot create temp fixture dirs.' );
		}
		$this->fixture_root = $base;
		$root               = $base . '/cache/wppo';

		$link = $root . '/example.com/evilseg';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_symlink -- Test fixture.
		if ( ! @symlink( $base . '/outside', $link ) ) {
			$this->markTestSkipped( 'Cannot create symlink fixture.' );
		}

		// Benign control: a real directory under the domain passes.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
		mkdir( $root . '/example.com/about', 0777, true );
		$this->assertTrue( Util::is_realpath_contained( $root, 'example.com', $root . '/example.com/about/index.html' ) );

		// Attack: lexically contained but resolving outside via the symlink.
		$this->assertFalse( Util::is_realpath_contained( $root, 'example.com', $link . '/index.html' ) );
		$this->assertFalse( Util::validate_cache_write_path( $root, 'example.com', $link . '/index.html' ) );
	}

	/**
	 * A symlinked domain directory pointing outside is rejected.
	 */
	public function test_symlinked_domain_dir_is_rejected(): void {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() unavailable.' );
		}
		$base = rtrim( sys_get_temp_dir(), '/\\' ) . '/wppo-realpath-dom-' . uniqid();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
		if ( ! mkdir( $base . '/cache/wppo', 0777, true ) || ! mkdir( $base . '/outside', 0777, true ) ) {
			$this->markTestSkipped( 'Cannot create temp fixture dirs.' );
		}
		$this->fixture_root = $base;
		$root               = $base . '/cache/wppo';

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_symlink -- Test fixture.
		if ( ! @symlink( $base . '/outside', $root . '/example.com' ) ) {
			$this->markTestSkipped( 'Cannot create symlink fixture.' );
		}

		$this->assertFalse( Util::is_realpath_contained( $root, 'example.com', $root . '/example.com/about/index.html' ) );
	}

	/**
	 * Brand-new nested pages (no ancestor beyond root) fall back to lexical pass.
	 */
	public function test_nonexistent_tree_falls_back_to_lexical(): void {
		$base = rtrim( sys_get_temp_dir(), '/\\' ) . '/wppo-realpath-new-' . uniqid();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
		if ( ! mkdir( $base . '/cache', 0777, true ) ) {
			$this->markTestSkipped( 'Cannot create temp fixture dirs.' );
		}
		$this->fixture_root = $base;
		$root               = $base . '/cache/wppo';

		$this->assertTrue( Util::is_realpath_contained( $root, 'example.com', $root . '/example.com/fresh/page/index.html' ) );
	}

	/**
	 * Call the private htaccess isolation guard via reflection.
	 *
	 * @param string $path Candidate path.
	 * @return bool
	 */
	private function htaccess_allowed( string $path ): bool {
		$method = new \ReflectionMethod( Htaccess_Handler::class, 'is_htaccess_write_allowed' );
		return (bool) $method->invoke( null, $path );
	}

	/**
	 * Call the private atomic htaccess writer via reflection.
	 *
	 * @param string $path  Target path.
	 * @param mixed  $fs    Filesystem mock.
	 * @param array  $rules Rules lines.
	 * @return bool|null
	 */
	private function atomic_write( string $path, $fs, array $rules ) {
		$method = new \ReflectionMethod( Htaccess_Handler::class, 'atomic_write_verified' );
		return $method->invoke( null, $path, $fs, $rules );
	}

	/**
	 * Legitimate .htaccess targets are allowed; hostile ones refused.
	 */
	public function test_htaccess_isolation_guard(): void {
		$this->assertTrue( $this->htaccess_allowed( '/tmp/wordpress/.htaccess' ) );
		$this->assertFalse( $this->htaccess_allowed( '' ) );
		$this->assertFalse( $this->htaccess_allowed( '/tmp/wordpress/.htaccess.bak' ) );
		$this->assertFalse( $this->htaccess_allowed( '/tmp/wordpress/htaccess' ) );
		$this->assertFalse( $this->htaccess_allowed( '/tmp/wordpress/../etc/.htaccess' ) );
		$this->assertFalse( $this->htaccess_allowed( "/tmp/wordpress/.htaccess\0" ) );
		// Cache-tree targets stay out of reach of cache-path resolution.
		$this->assertFalse( $this->htaccess_allowed( '/tmp/wordpress/wp-content/cache/wppo/.htaccess' ) );
		$this->assertFalse( $this->htaccess_allowed( '/tmp/wordpress/wp-content/cache/wppo/example.com/.htaccess' ) );
	}

	/**
	 * Attack set against the htaccess writer: no write, content byte-identical.
	 */
	public function test_htaccess_attack_set_leaves_content_byte_identical(): void {
		$original = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n";
		$targets  = array(
			'/tmp/wordpress/wp-content/cache/wppo/example.com/.htaccess',
			'/tmp/wordpress/wp-content/cache/wppo/.htaccess',
			'/tmp/wordpress/../etc/.htaccess',
			'/tmp/wordpress/.htaccess.bak',
		);
		foreach ( $targets as $target ) {
			$fs                   = new WPPO_Realpath_Fake_Fs();
			$fs->files[ $target ] = $original;
			$result               = $this->atomic_write( $target, $fs, array( 'ExpiresActive On' ) );
			$this->assertFalse( $result, "attack target wrote: {$target}" );
			$this->assertSame( $original, $fs->files[ $target ], "content changed: {$target}" );
			$this->assertArrayNotHasKey( $target, $fs->put_log );
		}
	}

	/**
	 * A legitimate htaccess write still succeeds byte-identical.
	 */
	public function test_htaccess_legitimate_write_succeeds(): void {
		Functions\when( 'wp_rand' )->justReturn( 123456 );
		$fs                   = new WPPO_Realpath_Fake_Fs();
		$target               = '/tmp/wordpress/.htaccess';
		$fs->files[ $target ] = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n";

		$result = $this->atomic_write( $target, $fs, array( 'ExpiresActive On' ) );
		$this->assertTrue( $result );
		$written = $fs->files[ $target ];
		$this->assertStringContainsString( '# BEGIN wppo_rules', $written );
		$this->assertStringContainsString( 'ExpiresActive On', $written );
		$this->assertStringContainsString( '# BEGIN WordPress', $written );
	}
}
