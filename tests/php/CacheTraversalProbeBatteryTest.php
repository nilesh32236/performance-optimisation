<?php
/**
 * Traversal-probe regression battery for the static-cache choke point (issue #1270).
 *
 * Drives all four NVD probe families (double-encoded `..`, backslash/drive/UNC,
 * overlong leaf, symlink escape; CVE-2026-18051 / CVE-2026-9282 class) directly
 * through `Cache::safe_path_for_url()` and asserts the acceptance triple in one
 * place: (1) hostile probes are refused with exactly one probe-log entry each,
 * (2) no path ever resolves outside `wp-content/cache/wppo/{domain}/`, and
 * (3) a foreign `advanced-cache.php` is left untouched and `.htaccess` content
 * stays byte-identical.
 *
 * Double-encoding policy (Option A, preserves existing asserted behaviour in
 * `CacheSafePathTest` / `CachePathContainmentTest`): single-decode semantics
 * keep `%252e` literal on disk, so double-encoded probes map to a benign
 * contained literal inside the domain tree rather than refusing. The battery
 * locks that containment (prefix + no `..`/NUL) instead of demanding a refusal.
 * Every other family must refuse with a probe log.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Advanced_Cache_Handler;
use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\Htaccess_Handler;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Test fixtures are co-located by convention.
// phpcs:disable WordPress.Files.FileName -- Fixture class names cannot derive the *Test.php file name.

/**
 * In-memory $wpdb recorder for probe-log assertions.
 *
 * @package PerformanceOptimise\Tests
 * @since 2.2.0
 */
class WPPO_ProbeBattery_Wpdb_Recorder {

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Recorded insert payloads.
	 *
	 * @var array<int, array>
	 */
	public $inserts = array();

	/**
	 * Record an insert.
	 *
	 * @param string $table  Table name.
	 * @param array  $data   Row data.
	 * @param array  $format Formats.
	 * @return int Always 1 (truthy, like a successful insert).
	 */
	public function insert( $table, $data, $format = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->inserts[] = array(
			'table' => $table,
			'data'  => $data,
		);
		return 1;
	}
}

/**
 * Recording filesystem for drop-in / htaccess invariance assertions.
 *
 * Real methods are required because the handlers guard on method_exists().
 *
 * @package PerformanceOptimise\Tests
 * @since 2.2.0
 */
class WPPO_ProbeBattery_Fake_Fs {

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
 * Hostile-probe battery for the single cache-path choke point.
 *
 * @package PerformanceOptimise\Tests
 * @since 2.2.0
 */
class CacheTraversalProbeBatteryTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Superglobal backup.
	 *
	 * @var array
	 */
	private $server_backup = array();

	/**
	 * Recorder swapped in as $wpdb.
	 *
	 * @var WPPO_ProbeBattery_Wpdb_Recorder|null
	 */
	private $wpdb_recorder = null;

	/**
	 * Previous $wpdb instance.
	 *
	 * @var mixed
	 */
	private $wpdb_backup = null;

	/**
	 * Whether a $wpdb instance existed before the test.
	 *
	 * @var bool
	 */
	private $wpdb_had_instance = false;

	/**
	 * Previous $wp_filesystem instance.
	 *
	 * @var mixed
	 */
	private $filesystem_backup = null;

	/**
	 * Whether $wp_filesystem existed before the test.
	 *
	 * @var bool
	 */
	private $filesystem_had_instance = false;

	/**
	 * Temp fixture root created per symlink/outside-tree test.
	 *
	 * @var string
	 */
	private $fixture_root = '';

	/**
	 * Set up stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		Util::reset_runtime_caches();
		Util::clear_settings_cache();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test-only backup.
		$this->server_backup['HTTP_HOST'] = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : null;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test-only backup.
		$this->server_backup['REQUEST_URI'] = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
		$this->filesystem_backup            = isset( $GLOBALS['wp_filesystem'] ) ? $GLOBALS['wp_filesystem'] : null;
		$this->filesystem_had_instance      = isset( $GLOBALS['wp_filesystem'] );

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_kses_post' )->returnArg();

		$this->reset_probe_flag();
	}

	/**
	 * Restore state.
	 */
	protected function tearDown(): void {
		$this->remove_fixture_root();
		if ( null === $this->server_backup['HTTP_HOST'] ) {
			unset( $_SERVER['HTTP_HOST'] );
		} else {
			$_SERVER['HTTP_HOST'] = $this->server_backup['HTTP_HOST'];
		}
		if ( null === $this->server_backup['REQUEST_URI'] ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->server_backup['REQUEST_URI'];
		}
		if ( null !== $this->wpdb_recorder ) {
			if ( $this->wpdb_had_instance ) {
				$GLOBALS['wpdb'] = $this->wpdb_backup;
			} else {
				unset( $GLOBALS['wpdb'] );
			}
			$this->wpdb_recorder     = null;
			$this->wpdb_backup       = null;
			$this->wpdb_had_instance = false;
		}
		if ( $this->filesystem_had_instance ) {
			$GLOBALS['wp_filesystem'] = $this->filesystem_backup;
		} else {
			unset( $GLOBALS['wp_filesystem'] );
		}
		$this->filesystem_backup       = null;
		$this->filesystem_had_instance = false;
		$this->reset_probe_flag();
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Reset the once-per-request probe flag.
	 *
	 * @since 2.2.0
	 * @return void
	 */
	private function reset_probe_flag(): void {
		if ( ! ( new \ReflectionClass( Cache::class ) )->hasProperty( 'traversal_probe_logged' ) ) {
			return;
		}
		$prop = new \ReflectionProperty( Cache::class, 'traversal_probe_logged' );
		$prop->setAccessible( true );
		$prop->setValue( null, false );
	}

	/**
	 * Remove the temp fixture tree.
	 *
	 * @since 2.2.0
	 * @return void
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
	 * Swap in the recorder $wpdb.
	 *
	 * @since 2.2.0
	 * @return WPPO_ProbeBattery_Wpdb_Recorder
	 */
	private function use_recorder_wpdb(): WPPO_ProbeBattery_Wpdb_Recorder {
		$this->wpdb_had_instance = isset( $GLOBALS['wpdb'] );
		$this->wpdb_backup       = $this->wpdb_had_instance ? $GLOBALS['wpdb'] : null;
		$this->wpdb_recorder     = new WPPO_ProbeBattery_Wpdb_Recorder();
		$GLOBALS['wpdb']         = $this->wpdb_recorder;
		return $this->wpdb_recorder;
	}

	/**
	 * Count probe-log entries in the recorder.
	 *
	 * @since 2.2.0
	 * @param WPPO_ProbeBattery_Wpdb_Recorder $recorder Recorder.
	 * @return int
	 */
	private function probe_count( WPPO_ProbeBattery_Wpdb_Recorder $recorder ): int {
		$count = 0;
		foreach ( $recorder->inserts as $insert ) {
			if ( false !== strpos( (string) ( $insert['data']['activity'] ?? '' ), 'Blocked cache path traversal probe' ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Construct a benign Cache instance.
	 *
	 * @since 2.2.0
	 * @return Cache
	 */
	private function make_cache(): Cache {
		$_SERVER['HTTP_HOST']   = 'example.com';
		$_SERVER['REQUEST_URI'] = '/about/';
		unset( $_SERVER['QUERY_STRING'] );
		return new Cache();
	}

	/**
	 * Invoke the private safe_path_for_url().
	 *
	 * @since 2.2.0
	 * @param Cache  $cache    Instance.
	 * @param string $url_path URL path or URL.
	 * @param string $filename Leaf filename.
	 * @return string
	 */
	private function safe_path( Cache $cache, string $url_path, string $filename ): string {
		$method = new \ReflectionMethod( Cache::class, 'safe_path_for_url' );
		$method->setAccessible( true );
		return $method->invoke( $cache, $url_path, $filename );
	}

	/**
	 * Cache root used across containment assertions.
	 *
	 * @since 2.2.0
	 * @return string
	 */
	private function root(): string {
		return '/tmp/wordpress/wp-content/cache/wppo';
	}

	/**
	 * Double-encoded probes: single-decode keeps them literal on disk.
	 *
	 * @since 2.2.0
	 * @return array<string,array{0:string}>
	 */
	public static function double_encoded_provider(): array {
		return array(
			'double-encoded dotdot'  => array( '%252e%252e/foo' ),
			'double-encoded upper'   => array( '%252E%252E/x' ),
			'double-encoded slash'   => array( '%252fetc' ),
			'slashed double-encoded' => array( '/%252e%252e/foo' ),
		);
	}

	/**
	 * Probes that must be refused with a probe log entry.
	 *
	 * Single-encoded dot-dot, backslash/drive/UNC, null bytes, absolute-form
	 * foreign targets, and traversal segments.
	 *
	 * @since 2.2.0
	 * @return array<string,array{0:string}>
	 */
	public static function refused_probe_provider(): array {
		return array(
			'single-encoded dotdot'   => array( '%2e%2e/etc/passwd' ),
			'single-encoded upper'    => array( '%2E%2E/x' ),
			'encoded slash traversal' => array( '/..%2f..%2fetc' ),
			'encoded backslash'       => array( '/..%5c..%5cx' ),
			'windows backslash'       => array( '..\\..\\windows' ),
			'backslash infix'         => array( 'about\\..\\x' ),
			'plain dotdot'            => array( '../etc/passwd' ),
			'nested dotdot'           => array( 'foo/../../../bar' ),
			'windows drive slash'     => array( 'C:/windows' ),
			'windows drive backslash' => array( 'C:\\windows\\system32' ),
			'unc prefix'              => array( '\\\\server\\share' ),
			'foreign traversal'       => array( 'https://evil.com/../x' ),
			'foreign host'            => array( 'https://evil.com/about/' ),
			'protocol-relative trav'  => array( '//evil.com/../x' ),
			'null byte'               => array( '%00' ),
			'null byte infix'         => array( 'a%00b' ),
			'dotdot null'             => array( '..%00' ),
			'long traversal'          => array( '../../../etc/passwd' ),
		);
	}

	/**
	 * Overlong leaf filenames that must be refused.
	 *
	 * @since 2.2.0
	 * @return array<string,array{0:string}>
	 */
	public static function overlong_leaf_provider(): array {
		return array(
			'65 chars'         => array( str_repeat( 'a', 65 ) ),
			'300 chars'        => array( str_repeat( 'b', 300 ) ),
			'overlong indexed' => array( 'index-' . str_repeat( 'c', 60 ) . '.html' ),
		);
	}

	/**
	 * Double-encoded probes stay a contained literal inside the domain tree.
	 *
	 * @since 2.2.0
	 * @param string $payload Double-encoded probe.
	 */
	#[DataProvider( 'double_encoded_provider' )]
	public function test_double_encoded_probes_stay_contained_literal( string $payload ): void {
		$this->use_recorder_wpdb();
		$cache = $this->make_cache();
		$root  = $this->root() . '/example.com/';

		$this->reset_probe_flag();
		$resolved = $this->safe_path( $cache, $payload, 'index.html' );

		// Single-decode semantics: either refused outright or mapped to a
		// benign literal — both are safe, but neither may escape the tree.
		if ( '' !== $resolved ) {
			$this->assertStringStartsWith( $root, $resolved, "Escape for payload: {$payload}" );
			$this->assertStringNotContainsString( '..', $resolved, "Dot-dot in resolved path: {$payload}" );
			$this->assertStringNotContainsString( "\0", $resolved, "NUL in resolved path: {$payload}" );
			$this->assertTrue( Util::is_cache_path_contained( $this->root(), 'example.com', $resolved ), "Uncontained: {$payload}" );
		} else {
			$this->assertSame( '', $resolved );
		}
	}

	/**
	 * Hostile probes are refused with exactly one probe-log entry each.
	 *
	 * @since 2.2.0
	 * @param string $payload Hostile probe.
	 */
	#[DataProvider( 'refused_probe_provider' )]
	public function test_hostile_probes_refused_with_single_probe_log( string $payload ): void {
		$recorder = $this->use_recorder_wpdb();
		$cache    = $this->make_cache();

		$this->reset_probe_flag();
		$before   = $this->probe_count( $recorder );
		$resolved = $this->safe_path( $cache, $payload, 'index.html' );

		$this->assertSame( '', $resolved, "Probe not refused: {$payload}" );
		$this->assertSame( $before + 1, $this->probe_count( $recorder ), "Probe log missing or duplicated: {$payload}" );
	}

	/**
	 * Overlong leaf filenames are refused with a probe log entry.
	 *
	 * @since 2.2.0
	 * @param string $leaf Overlong leaf filename.
	 */
	#[DataProvider( 'overlong_leaf_provider' )]
	public function test_overlong_leaf_refused_with_probe_log( string $leaf ): void {
		$recorder = $this->use_recorder_wpdb();
		$cache    = $this->make_cache();

		$this->reset_probe_flag();
		$before   = $this->probe_count( $recorder );
		$resolved = $this->safe_path( $cache, '/about/', $leaf );

		$this->assertSame( '', $resolved, "Overlong leaf accepted: {$leaf}" );
		$this->assertSame( $before + 1, $this->probe_count( $recorder ), "Probe log missing for overlong leaf: {$leaf}" );
	}

	/**
	 * A symlink planted inside the cache tree cannot redirect the choke point outside.
	 *
	 * @since 2.2.0
	 */
	public function test_symlink_escape_refused_through_choke_point(): void {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() unavailable.' );
		}
		$base = rtrim( sys_get_temp_dir(), '/\\' ) . '/wppo-probe-' . uniqid();
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

		$recorder = $this->use_recorder_wpdb();
		$cache    = $this->make_cache();

		// Point the choke point at the temp fixture tree (unique root, so the
		// memoized realpath anchor cannot collide with other tests).
		$root_prop = new \ReflectionProperty( Cache::class, 'cache_root_dir' );
		$root_prop->setAccessible( true );
		$root_prop->setValue( $cache, $root );
		$domain_prop = new \ReflectionProperty( Cache::class, 'domain' );
		$domain_prop->setAccessible( true );
		$domain_prop->setValue( $cache, 'example.com' );

		// Benign control: a real directory under the domain still resolves.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
		mkdir( $root . '/example.com/about', 0777, true );
		$this->reset_probe_flag();
		$benign = $this->safe_path( $cache, '/about/', 'index.html' );
		$this->assertStringStartsWith( $root . '/example.com/', $benign );

		// Attack: lexically contained but resolving outside via the symlink.
		$this->reset_probe_flag();
		$before   = $this->probe_count( $recorder );
		$resolved = $this->safe_path( $cache, '/evilseg/', 'index.html' );
		$this->assertSame( '', $resolved, 'Symlink escape was not refused.' );
		$this->assertSame( $before + 1, $this->probe_count( $recorder ), 'Symlink probe was not logged.' );
		$this->assertFalse( Util::validate_cache_write_path( $root, 'example.com', $link . '/index.html' ) );
	}

	/**
	 * The hostile battery creates nothing outside the domain tree.
	 *
	 * @since 2.2.0
	 */
	public function test_hostile_battery_creates_nothing_outside_domain_tree(): void {
		$recorder = $this->use_recorder_wpdb();
		$cache    = $this->make_cache();
		$root     = $this->root() . '/example.com/';

		$payloads = array();
		foreach ( self::refused_probe_provider() as $case ) {
			$payloads[] = $case[0];
		}
		foreach ( self::double_encoded_provider() as $case ) {
			$payloads[] = $case[0];
		}

		foreach ( $payloads as $payload ) {
			$this->reset_probe_flag();
			$resolved = $this->safe_path( $cache, $payload, 'index.html' );
			if ( '' === $resolved ) {
				continue;
			}
			$this->assertStringStartsWith( $root, $resolved, "Outside-tree write for: {$payload}" );
			$this->assertStringNotContainsString( '..', $resolved, "Dot-dot in resolved path: {$payload}" );
			$this->assertStringNotContainsString( "\0", $resolved, "NUL in resolved path: {$payload}" );
			$this->assertTrue( Util::validate_cache_write_path( $this->root(), 'example.com', $resolved ), "Validator rejected benign literal: {$payload}" );
		}

		// Overlong leaves never resolve.
		foreach ( self::overlong_leaf_provider() as $case ) {
			$this->reset_probe_flag();
			$this->assertSame( '', $this->safe_path( $cache, '/about/', $case[0] ), 'Overlong leaf resolved outside policy.' );
		}

		// The battery exercised the choke point; refusals were logged.
		$this->assertGreaterThan( 0, $this->probe_count( $recorder ) );
	}

	/**
	 * Foreign drop-in is detected as foreign and hostile htaccess targets are refused byte-identical.
	 *
	 * @since 2.2.0
	 */
	public function test_foreign_dropin_untouched_and_htaccess_byte_identical(): void {
		$foreign_contents = "<?php\n// Another plugin's drop-in.\n";
		$htaccess_before  = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n";

		$fs = new WPPO_ProbeBattery_Fake_Fs();
		$fs->files[ Advanced_Cache_Handler::get_dropin_path() ] = $foreign_contents;
		$GLOBALS['wp_filesystem']                               = $fs;

		// Foreign drop-in: recognized as not-ours, reported as foreign, never overwritten.
		$this->assertFalse( Advanced_Cache_Handler::is_our_dropin() );
		$this->assertTrue( Advanced_Cache_Handler::foreign_dropin_present() );
		$this->assertSame( $foreign_contents, $fs->files[ Advanced_Cache_Handler::get_dropin_path() ] );
		$this->assertArrayNotHasKey( Advanced_Cache_Handler::get_dropin_path(), $fs->put_log );

		// Hostile htaccess targets (incl. cache-tree) are refused and stay byte-identical.
		$targets = array(
			'/tmp/wordpress/wp-content/cache/wppo/example.com/.htaccess',
			'/tmp/wordpress/wp-content/cache/wppo/.htaccess',
			'/tmp/wordpress/../etc/.htaccess',
			'/tmp/wordpress/.htaccess.bak',
		);
		foreach ( $targets as $target ) {
			$ht_fs                   = new WPPO_ProbeBattery_Fake_Fs();
			$ht_fs->files[ $target ] = $htaccess_before;
			$this->assertFalse( Util::is_htaccess_path_allowed( $target ), "htaccess guard allowed: {$target}" );
			$result = $this->atomic_write_htaccess( $target, $ht_fs, array( 'ExpiresActive On' ) );
			$this->assertFalse( $result, "attack target wrote: {$target}" );
			$this->assertSame( $htaccess_before, $ht_fs->files[ $target ], "content changed: {$target}" );
			$this->assertArrayNotHasKey( $target, $ht_fs->put_log );
		}
	}

	/**
	 * Call the private htaccess atomic writer via reflection.
	 *
	 * @since 2.2.0
	 * @param string $path  Target path.
	 * @param mixed  $fs    Filesystem mock.
	 * @param array  $rules Rules lines.
	 * @return bool|null
	 */
	private function atomic_write_htaccess( string $path, $fs, array $rules ) {
		$method = new \ReflectionMethod( Htaccess_Handler::class, 'atomic_write_verified' );
		$method->setAccessible( true );
		return $method->invoke( null, $path, $fs, $rules );
	}
}
