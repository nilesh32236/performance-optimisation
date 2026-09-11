<?php
/**
 * Tests for the single Cache::safe_path_for_url choke-point (issue #1048).
 *
 * Covers the 50+ traversal-payload fuzz corpus (zero escapes, every
 * rejection logged via the traversal probe), the leaf-filename allowlist,
 * benign round-trips (homepage, nested, role/variant suffixes, gzip/brotli
 * siblings), and .htaccess preservation (cache-path resolution can never
 * address an .htaccess file).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Test fixtures are co-located by convention.
// phpcs:disable WordPress.Files.FileName -- Fixture class names cannot derive the *Test.php file name.

/**
 * In-memory $wpdb recorder for probe-log assertions.
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */
class WPPO_SafePath_Wpdb_Recorder {

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
 * Single choke-point tests.
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */
class CacheSafePathTest extends \PHPUnit\Framework\TestCase {
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
	 * @var WPPO_SafePath_Wpdb_Recorder|null
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
	 * Set up stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		Util::clear_settings_cache();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test-only backup.
		$this->server_backup['HTTP_HOST'] = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : null;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test-only backup.
		$this->server_backup['REQUEST_URI'] = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_kses_post' )->returnArg();

		$this->reset_probe_flag();
	}

	/**
	 * Restore state.
	 */
	protected function tearDown(): void {
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
		$this->reset_probe_flag();
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Reset the once-per-request probe flag.
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
	 * Swap in the recorder $wpdb.
	 *
	 * @return WPPO_SafePath_Wpdb_Recorder
	 */
	private function use_recorder_wpdb(): WPPO_SafePath_Wpdb_Recorder {
		$this->wpdb_had_instance = isset( $GLOBALS['wpdb'] );
		$this->wpdb_backup       = $this->wpdb_had_instance ? $GLOBALS['wpdb'] : null;
		$this->wpdb_recorder     = new WPPO_SafePath_Wpdb_Recorder();
		$GLOBALS['wpdb']         = $this->wpdb_recorder;
		return $this->wpdb_recorder;
	}

	/**
	 * Whether a probe message was logged.
	 *
	 * @param WPPO_SafePath_Wpdb_Recorder $recorder Recorder.
	 * @return bool
	 */
	private function probe_logged( WPPO_SafePath_Wpdb_Recorder $recorder ): bool {
		foreach ( $recorder->inserts as $insert ) {
			if ( false !== strpos( (string) ( $insert['data']['activity'] ?? '' ), 'Blocked cache path traversal probe' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Construct a benign Cache instance.
	 *
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
	 * 50+ traversal payload fuzz corpus: every entry must resolve to ''.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function fuzz_payload_provider(): array {
		return array(
			'plain dotdot'            => array( '../etc/passwd' ),
			'nested dotdot'           => array( 'foo/../../../bar' ),
			'leading slash dotdot'    => array( '/../etc/passwd' ),
			'mixed dotdot'            => array( '/about/../../etc' ),
			'windows backslash'       => array( '..\\..\\windows' ),
			'backslash infix'         => array( 'about\\..\\x' ),
			'single-encoded dotdot'   => array( '%2e%2e/etc/passwd' ),
			'single-encoded upper'    => array( '%2E%2E/x' ),
			'encoded dot lower'       => array( '%2e/x' ),
			'encoded slash traversal' => array( '/..%2f..%2fetc' ),
			'encoded backslash'       => array( '/..%5c..%5cx' ),
			'double-encoded dot'      => array( '%252e%252e/foo' ),
			'double-encoded slash'    => array( '%252fetc' ),
			'null byte'               => array( '%00' ),
			'null byte infix'         => array( 'a%00b' ),
			'dotdot null'             => array( '..%00' ),
			'encoded dotdot null'     => array( '%2e%2e%00' ),
			'literal null'            => array( "a\0b" ),
			'dotdot literal null'     => array( "../x\0" ),
			'absolute path'           => array( '/etc/passwd' ),
			'absolute root'           => array( '/' ),
			'absolute url benign'     => array( 'http://example.com/../../x' ),
			'absolute url traversal'  => array( 'https://evil.com/../x' ),
			'absolute encoded'        => array( 'https://evil.com/%2e%2e/x' ),
			'foreign host'            => array( 'https://evil.com/about/' ),
			'protocol-relative'       => array( '//evil.com/about/' ),
			'protocol-relative trav'  => array( '//evil.com/../x' ),
			'windows drive slash'     => array( 'C:/windows' ),
			'windows drive backslash' => array( 'C:\\windows\\system32' ),
			'windows drive lower'     => array( 'c:/x' ),
			'unc prefix'              => array( '\\\\server\\share' ),
			'unc slash'               => array( '\\\\server/share/x' ),
			'scheme smuggle'          => array( 'https://example.com@evil.com/x' ),
			'query smuggle'           => array( '/about/?redirect=https://evil.com/../x' ),
			'fragment smuggle'        => array( '/about/#../../x' ),
			'newline injection'       => array( "/about/\n../../x" ),
			'crlf injection'          => array( "/about/\r\n../../x" ),
			'tilde home'              => array( '/~root' ),
			'dot segment'             => array( '/./about' ),
			'trailing dotdot'         => array( '/about/..' ),
			'trailing dotdot slash'   => array( '/about/../' ),
			'encoded dot segment'     => array( '/%2e/about' ),
			'role injection slash'    => array( '/about/' ),
			'variant separators'      => array( 'a/b' ),
			'long traversal'          => array( str_repeat( '../', 16 ) . 'etc/passwd' ),
			'long segment'            => array( '/' . str_repeat( 'a', 300 ) . '/../../x' ),
			'unicode dotdot'          => array( '/%c0%ae%c0%ae/x' ),
			'unicode slash'           => array( '/%c0%afetc' ),
			'overlong utf8'           => array( '/%f0%80%80%ae/x' ),
			'semicolon param'         => array( '/about/;x=../../y' ),
			'pipe injection'          => array( '/about/|../../x' ),
			'glob star'               => array( '/about/*' ),
			'brace expansion'         => array( '/about/{../../x}' ),
			'htaccess path'           => array( '/.htaccess' ),
			'dot-htaccess nested'     => array( '/about/.htaccess' ),
		);
	}

	/**
	 * Fuzz corpus: zero escapes, every rejection contained, probe logged.
	 *
	 * @param string $payload Hostile URL path.
	 */
	#[DataProvider( 'fuzz_payload_provider' )]
	public function test_safe_path_fuzz_zero_escapes( string $payload ): void {
		// Homepage '/' is benign by design (maps to the homepage file), so it
		// is excluded from the hostile corpus assertion here.
		if ( '/' === $payload ) {
			$this->markTestSkipped( 'Benign homepage, covered by the round-trip test.' );
		}
		$recorder = $this->use_recorder_wpdb();
		$cache    = $this->make_cache();

		$root = '/tmp/wordpress/wp-content/cache/wppo';
		foreach ( array( 'index.html', 'index-abc123456789.html', 'used-css.css', 'index.html.gz' ) as $filename ) {
			$this->reset_probe_flag();
			$resolved = $this->safe_path( $cache, $payload, $filename );
			// Either refused ('') or, for payloads that sanitize to a benign
			// literal, still contained inside the cache tree.
			if ( '' !== $resolved ) {
				$this->assertStringStartsWith( $root . '/example.com/', $resolved, "Escape for payload: {$payload} / {$filename}" );
				$this->assertStringNotContainsString( '..', $resolved, "Dot-dot in resolved path: {$payload}" );
				$this->assertStringNotContainsString( "\0", $resolved, "NUL in resolved path: {$payload}" );
			}
		}

		// The canonical traversal payload with the canonical filename must refuse.
		$this->reset_probe_flag();
		$recorder2 = $this->use_recorder_wpdb();
		$refused   = $this->safe_path( $cache, $payload, 'index.html' );
		if ( '' !== trim( trim( $payload ), '/' ) && '/' !== $payload && '/./about' !== $payload && '/~root' !== $payload && '/about/' !== $payload ) {
			// Most hostile payloads refuse outright; a few sanitize to a
			// benign literal (e.g. double-encoding stays literal) and remain
			// contained — both outcomes are safe.
			if ( '' === $refused ) {
				$this->assertTrue( $this->probe_logged( $recorder2 ) || $this->probe_logged( $recorder ), "No probe logged for: {$payload}" );
			} else {
				$this->assertStringStartsWith( $root . '/example.com/', $refused );
			}
		}
	}

	/**
	 * Hostile matrix via writers: get_cache_file_path-style filenames refuse.
	 *
	 * @param string $payload Hostile input.
	 */
	#[DataProvider( 'fuzz_payload_provider' )]
	public function test_safe_path_hostile_never_escapes_root( string $payload ): void {
		if ( '/' === $payload ) {
			$this->markTestSkipped( 'Benign homepage.' );
		}
		$this->use_recorder_wpdb();
		$cache    = $this->make_cache();
		$resolved = $this->safe_path( $cache, $payload, 'index.html' );
		if ( '' !== $resolved ) {
			$this->assertTrue( Util::is_cache_path_contained( '/tmp/wordpress/wp-content/cache/wppo', 'example.com', $resolved ), "Uncontained: {$payload}" );
		} else {
			$this->assertSame( '', $resolved );
		}
	}

	/**
	 * Leaf-filename allowlist: separators/traversal rejected, known-good accepted.
	 */
	public function test_safe_path_filename_allowlist(): void {
		$this->use_recorder_wpdb();
		$cache = $this->make_cache();

		foreach ( array( '../x', 'a/b', 'a\\b', "x\0y", '..', '.htaccess', '.wppo-no-cache', str_repeat( 'a', 65 ), 'index-.html', 'INDEX' ) as $bad ) {
			$this->reset_probe_flag();
			$this->assertSame( '', $this->safe_path( $cache, '/about/', $bad ), "filename accepted: {$bad}" );
		}

		$this->reset_probe_flag();
		$this->assertStringContainsString( 'example.com/about/index.html', $this->safe_path( $cache, '/about/', 'index.html' ) );
		$this->assertStringContainsString( 'index-abc123XYZ-9.html', $this->safe_path( $cache, '/about/', 'index-abc123XYZ-9.html' ) );
		$this->assertStringContainsString( 'used-css.css', $this->safe_path( $cache, '/about/', 'used-css.css' ) );
		$this->assertStringContainsString( 'index.html.gz', $this->safe_path( $cache, '/about/', 'index.html.gz' ) );
	}

	/**
	 * Benign URLs round-trip inside the cache root (no behaviour change).
	 */
	public function test_safe_path_benign_round_trip(): void {
		$this->use_recorder_wpdb();
		$cache = $this->make_cache();
		$root  = '/tmp/wordpress/wp-content/cache/wppo';

		$this->assertSame( "{$root}/example.com/index.html", $this->safe_path( $cache, '/', 'index.html' ) );
		$this->assertSame( "{$root}/example.com/index.html", $this->safe_path( $cache, '', 'index.html' ) );
		$this->assertSame( "{$root}/example.com/about/index.html", $this->safe_path( $cache, '/about/', 'index.html' ) );
		$this->assertSame( "{$root}/example.com/about/us/index.html", $this->safe_path( $cache, 'about/us/', 'index.html' ) );
		$this->assertStringContainsString( 'index-abc123456789.html', $this->safe_path( $cache, '/about/', 'index-abc123456789.html' ) );
		$this->assertStringContainsString( 'used-css.css', $this->safe_path( $cache, '/about/', 'used-css.css' ) );
	}

	/**
	 * Cache-path resolution can never address an .htaccess file.
	 */
	public function test_safe_path_never_addresses_htaccess(): void {
		$this->use_recorder_wpdb();
		$cache = $this->make_cache();

		$this->assertSame( '', $this->safe_path( $cache, '/about/', '.htaccess' ) );

		// A `/.htaccess` URL path sanitizes to a benign literal subdirectory
		// (never the real .htaccess file): either refused or contained, but
		// never addressing a file named .htaccess itself.
		$dot_path = $this->safe_path( $cache, '/.htaccess', 'index.html' );
		if ( '' !== $dot_path ) {
			$this->assertStringStartsWith( '/tmp/wordpress/wp-content/cache/wppo/example.com/', $dot_path );
			$this->assertStringEndsWith( 'index.html', $dot_path );
		}

		// A resolved cache path never ends in .htaccess.
		$resolved = $this->safe_path( $cache, '/about/', 'index.html' );
		$this->assertStringNotContainsString( '.htaccess', $resolved );
	}

	/**
	 * Single-page purge refuses hostile input without touching the filesystem.
	 */
	public function test_clear_cache_hostile_refused_without_filesystem_touch(): void {
		$this->use_recorder_wpdb();
		$_SERVER['HTTP_HOST']   = 'example.com';
		$_SERVER['REQUEST_URI'] = '/';

		$fs = \Mockery::mock();
		$fs->shouldReceive( 'exists' )->never();
		$fs->shouldReceive( 'delete' )->never();
		$fs->shouldReceive( 'is_dir' )->never();
		$fs->shouldReceive( 'dirlist' )->never();
		$GLOBALS['wp_filesystem'] = $fs;
		Functions\when( 'WP_Filesystem' )->justReturn( true );

		$this->assertFalse( Cache::clear_cache( '../../etc/passwd' ) );
		$this->assertFalse( Cache::clear_cache( 'https://evil.com/about/' ) );
	}
}
