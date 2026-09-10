<?php
/**
 * Tests for cache + used-CSS path traversal hardening (issue #990).
 *
 * Covers encoded-sequence normalization (dot-dot, single-encoded,
 * double-encoded, null bytes, absolute URLs/paths, drive/UNC prefixes)
 * across Util::sanitize_cache_url_path(), Cache::get_cache_file_path()
 * (incl. role-hash/variant suffix allowlist and absolute-form refusal) and
 * Used_CSS::save_used_css()/delete_used_css(), plus the .htaccess atomic
 * backup/restore contract. All refusals fail open (serve dynamic/uncached,
 * never fatal) and log a traversal probe via the activity log.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\Htaccess_Handler;
use PerformanceOptimise\Inc\Used_CSS;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Test fixtures are co-located by convention.
// phpcs:disable WordPress.Files.FileName -- Fixture class names cannot derive the *Test.php file name.

/**
 * In-memory $wpdb recorder for traversal-probe Log::add() assertions.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Traversal_Wpdb_Recorder {

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
 * Fake filesystem with real methods for .htaccess atomicity tests.
 *
 * Real methods are required because read_existing_rules() guards on
 * method_exists(), which fails for __call-based mocks.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Traversal_Fake_Fs {

	/**
	 * File contents to return from get_contents().
	 *
	 * @var string
	 */
	public $contents = '';

	/**
	 * Whether exists() reports true.
	 *
	 * @var bool
	 */
	public $exists = true;

	/**
	 * Whether is_writable() reports true.
	 *
	 * @var bool
	 */
	public $writable = true;

	/**
	 * Check existence.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function exists( $path ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return $this->exists;
	}

	/**
	 * Check writability.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function is_writable( $path ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return $this->writable;
	}

	/**
	 * Read contents.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public function get_contents( $path ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return $this->contents;
	}
}

/**
 * Traversal hardening tests.
 *
 * @package PerformanceOptimise\Tests
 */
class CacheTraversalTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Superglobal backup so hostile hosts/URIs never leak into later tests.
	 *
	 * @var array
	 */
	private $server_backup = array();

	/**
	 * Recorder swapped in as $wpdb for probe-log assertions.
	 *
	 * @var WPPO_Traversal_Wpdb_Recorder|null
	 */
	private $wpdb_recorder = null;

	/**
	 * Previous $wpdb instance to restore in tearDown.
	 *
	 * @var mixed
	 */
	private $wpdb_backup = null;

	/**
	 * Back up superglobals and install common stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		Util::clear_settings_cache();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test-only superglobal backup/restore.
		$this->server_backup['HTTP_HOST'] = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : null;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test-only superglobal backup/restore.
		$this->server_backup['REQUEST_URI'] = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test-only superglobal backup/restore.
		$this->server_backup['QUERY_STRING'] = isset( $_SERVER['QUERY_STRING'] ) ? $_SERVER['QUERY_STRING'] : null;

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_kses_post' )->returnArg();

		$this->reset_traversal_flags();
	}

	/**
	 * Restore superglobals, $wpdb, and Brain Monkey.
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
		if ( null === $this->server_backup['QUERY_STRING'] ) {
			unset( $_SERVER['QUERY_STRING'] );
		} else {
			$_SERVER['QUERY_STRING'] = $this->server_backup['QUERY_STRING'];
		}
		if ( null !== $this->wpdb_recorder ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		}
		$this->reset_traversal_flags();
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Reset the once-per-request probe-log flags on both classes.
	 */
	private function reset_traversal_flags(): void {
		foreach ( array( Cache::class, Used_CSS::class ) as $class ) {
			$prop = new \ReflectionProperty( $class, 'traversal_probe_logged' );
			$prop->setAccessible( true );
			$prop->setValue( null, false );
		}
	}

	/**
	 * Swap global $wpdb for an insert recorder so Log::add() can run.
	 *
	 * @return WPPO_Traversal_Wpdb_Recorder The recorder.
	 */
	private function use_recorder_wpdb(): WPPO_Traversal_Wpdb_Recorder {
		$this->wpdb_backup   = $GLOBALS['wpdb'];
		$this->wpdb_recorder = new WPPO_Traversal_Wpdb_Recorder();
		$GLOBALS['wpdb']     = $this->wpdb_recorder;
		return $this->wpdb_recorder;
	}

	/**
	 * Whether the recorder captured a probe message containing $needle.
	 *
	 * @param WPPO_Traversal_Wpdb_Recorder $recorder Recorder.
	 * @param string                       $needle   Message fragment.
	 * @return bool
	 */
	private function probe_logged( WPPO_Traversal_Wpdb_Recorder $recorder, string $needle ): bool {
		foreach ( $recorder->inserts as $insert ) {
			if ( false !== strpos( (string) ( $insert['data']['activity'] ?? '' ), $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Construct a Cache instance for the given request URI.
	 *
	 * @param string $request_uri Request URI.
	 * @return Cache
	 */
	private function make_cache( string $request_uri ): Cache {
		$_SERVER['HTTP_HOST']   = 'example.com';
		$_SERVER['REQUEST_URI'] = $request_uri;
		unset( $_SERVER['QUERY_STRING'] );
		return new Cache();
	}

	/**
	 * Read a private property off an instance.
	 *
	 * @param Cache|Used_CSS $instance Instance.
	 * @param string         $prop     Property name.
	 * @return mixed
	 */
	private function read_prop( $instance, string $prop ) {
		$reflection = new \ReflectionProperty( $instance, $prop );
		$reflection->setAccessible( true );
		return $reflection->getValue( $instance );
	}

	/**
	 * Invoke the private Cache::get_cache_file_path().
	 *
	 * @param Cache  $cache     Cache instance.
	 * @param string $type      File type.
	 * @param string $role_hash Role hash suffix.
	 * @param string $variant   Variant suffix.
	 * @return string
	 */
	private function cache_file_path( Cache $cache, string $type = 'html', string $role_hash = '', string $variant = '' ): string {
		$method = new \ReflectionMethod( Cache::class, 'get_cache_file_path' );
		$method->setAccessible( true );
		return $method->invoke( $cache, $type, $role_hash, $variant );
	}

	/**
	 * Hostile payloads that must sanitize to ''.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function hostile_path_provider(): array {
		return array(
			'plain dotdot'            => array( '../etc/passwd' ),
			'nested dotdot'           => array( 'foo/../../../bar' ),
			'windows backslash'       => array( '..\\..\\windows' ),
			'single-encoded dotdot'   => array( '%2e%2e/etc/passwd' ),
			'single-encoded upper'    => array( '%2E%2E/x' ),
			'encoded slash traversal' => array( '/..%2f..%2fetc' ),
			'null byte'               => array( '%00' ),
			'null byte infix'         => array( 'a%00b' ),
			'dotdot null'             => array( '..%00' ),
			'encoded dotdot null'     => array( '%2e%2e%00' ),
			'windows drive backslash' => array( 'C:\\windows\\system32' ),
			'windows drive slash'     => array( 'C:/windows' ),
			'unc prefix'              => array( '\\\\server\\share' ),
			'absolute url traversal'  => array( 'https://evil.com/../x' ),
			'absolute path traversal' => array( 'https://evil.com/%2e%2e/x' ),
		);
	}

	/**
	 * Benign inputs and their expected sanitized form.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function benign_path_provider(): array {
		return array(
			'homepage slash'         => array( '/', '' ),
			'empty'                  => array( '', '' ),
			'plain path'             => array( '/about/', 'about' ),
			'nested path'            => array( 'about/us/', 'about/us' ),
			'absolute url benign'    => array( 'http://example.com/about/', 'about' ),
			// Double-encoding stays literal on disk (never re-decoded): safe.
			'double-encoded literal' => array( '/%252e%252e/foo', '%2e%2e/foo' ),
			'encoded space'          => array( '/my%20page/', 'my page' ),
		);
	}

	/**
	 * The shared helper refuses the hostile matrix.
	 *
	 * @param string $payload Hostile input.
	 */
	#[DataProvider( 'hostile_path_provider' )]
	public function test_sanitize_helper_refuses_hostile_matrix( string $payload ): void {
		$this->assertSame( '', Util::sanitize_cache_url_path( $payload ) );
	}

	/**
	 * The shared helper preserves benign inputs.
	 *
	 * @param string $payload  Input.
	 * @param string $expected Expected sanitized form.
	 */
	#[DataProvider( 'benign_path_provider' )]
	public function test_sanitize_helper_preserves_benign( string $payload, string $expected ): void {
		$this->assertSame( $expected, Util::sanitize_cache_url_path( $payload ) );
	}

	/**
	 * The Cache constructor flags hostile request URIs (never mapped to homepage).
	 *
	 * @param string $payload Hostile request URI.
	 */
	#[DataProvider( 'hostile_path_provider' )]
	public function test_cache_constructor_rejects_hostile_request_uri( string $payload ): void {
		$recorder = $this->use_recorder_wpdb();
		$uri      = '/' . ltrim( $payload, '/' );
		// Absolute-URL-looking payloads are sent as-is (absolute-form target).
		if ( false !== strpos( $payload, '://' ) || 0 === strpos( ltrim( $payload ), '//' ) || 0 === strpos( ltrim( $payload ), '\\\\' ) || preg_match( '#^[a-zA-Z]:#', ltrim( $payload ) ) ) {
			$uri = $payload;
		}

		$cache = $this->make_cache( $uri );

		$this->assertTrue( $this->read_prop( $cache, 'path_rejected' ) );
		$this->assertSame( '', $this->read_prop( $cache, 'url_path' ) );
		$this->assertSame( '', $this->cache_file_path( $cache ) );
		$this->assertTrue( $this->probe_logged( $recorder, 'Blocked cache path traversal probe' ) );
	}

	/**
	 * Absolute-form request targets are refused even with a benign path.
	 */
	public function test_cache_absolute_form_request_target_refused(): void {
		foreach ( array( 'https://evil.com/about/', '//evil.com/about/' ) as $uri ) {
			$this->reset_traversal_flags();
			$recorder = $this->use_recorder_wpdb();
			$cache    = $this->make_cache( $uri );

			$this->assertTrue( $this->read_prop( $cache, 'path_rejected' ), "Failed for URI: {$uri}" );
			$this->assertSame( '', $this->cache_file_path( $cache ), "Failed for URI: {$uri}" );
			$this->assertTrue( $this->probe_logged( $recorder, 'Blocked cache path traversal probe' ), "Failed for URI: {$uri}" );
			$GLOBALS['wpdb']     = $this->wpdb_backup;
			$this->wpdb_recorder = null;
		}
	}

	/**
	 * A benign request still builds a contained cache path.
	 */
	public function test_cache_benign_request_builds_contained_path(): void {
		$cache = $this->make_cache( '/about/' );

		$this->assertFalse( $this->read_prop( $cache, 'path_rejected' ) );
		$this->assertSame( 'about', $this->read_prop( $cache, 'url_path' ) );

		$path = $this->cache_file_path( $cache );
		$this->assertStringContainsString( 'example.com/about/index.html', $path );
	}

	/**
	 * Role-hash / variant suffixes are allowlisted.
	 */
	public function test_cache_suffix_allowlist(): void {
		$cache = $this->make_cache( '/about/' );

		foreach ( array( '../x', '../../..', 'a/b', 'a\\b', str_repeat( 'a', 33 ), 'x%00y' ) as $bad ) {
			$this->reset_traversal_flags();
			$this->assertSame( '', $this->cache_file_path( $cache, 'html', $bad ), "role_hash accepted: {$bad}" );
			$this->reset_traversal_flags();
			$this->assertSame( '', $this->cache_file_path( $cache, 'html', '', $bad ), "variant accepted: {$bad}" );
		}

		$this->assertStringContainsString( 'index-abc123XYZ-9.html', $this->cache_file_path( $cache, 'html', 'abc123XYZ-9', '' ) );
	}

	/**
	 * Used-CSS save refuses the hostile matrix and logs a probe (no write).
	 *
	 * @param string $payload Hostile URL suffix.
	 */
	#[DataProvider( 'hostile_path_provider' )]
	public function test_used_css_save_refuses_hostile_matrix( string $payload ): void {
		$_SERVER['HTTP_HOST']   = 'example.com';
		$_SERVER['REQUEST_URI'] = '/about/';
		$recorder               = $this->use_recorder_wpdb();
		$used_css               = new Used_CSS();

		$url = 'http://example.com/' . ltrim( $payload, '/' );
		if ( false !== strpos( $payload, '://' ) || preg_match( '#^[a-zA-Z]:#', ltrim( $payload ) ) || 0 === strpos( ltrim( $payload ), '\\\\' ) ) {
			$url = $payload;
		}

		$this->assertSame( '', $used_css->get_used_css_path( $url ) );
		$this->assertSame( '', $used_css->get_used_css_url( $url ) );
		$this->assertFalse( $used_css->save_used_css( '.a{color:red}', $url ) );
		$this->assertTrue( $this->probe_logged( $recorder, 'Blocked used-CSS path traversal probe' ) );
	}

	/**
	 * A benign used-CSS URL still resolves inside the domain directory.
	 */
	public function test_used_css_benign_path_contained(): void {
		$_SERVER['HTTP_HOST']   = 'example.com';
		$_SERVER['REQUEST_URI'] = '/about/';

		$used_css = new Used_CSS();
		$path     = $used_css->get_used_css_path( 'http://example.com/about/' );

		$this->assertStringContainsString( 'example.com/about/used-css.css', $path );
	}

	/**
	 * Used-CSS delete refuses hostile URLs without touching the filesystem.
	 */
	public function test_used_css_delete_refuses_hostile_without_filesystem_touch(): void {
		$_SERVER['HTTP_HOST']   = 'example.com';
		$_SERVER['REQUEST_URI'] = '/about/';
		$this->use_recorder_wpdb();

		$fs = \Mockery::mock();
		$fs->shouldReceive( 'exists' )->never();
		$fs->shouldReceive( 'delete' )->never();
		$GLOBALS['wp_filesystem'] = $fs;
		Functions\when( 'WP_Filesystem' )->justReturn( true );

		$used_css = new Used_CSS();
		$this->assertFalse( $used_css->delete_used_css( 'http://example.com/../../etc/passwd' ) );
	}

	/**
	 * Failed .htaccess update leaves the prior rules intact (atomic restore).
	 */
	public function test_htaccess_failed_update_restores_prior_rules(): void {
		$fs                       = new WPPO_Traversal_Fake_Fs();
		$fs->contents             = "# BEGIN wppo_rules\nRuleA\n# END wppo_rules\n";
		$GLOBALS['wp_filesystem'] = $fs;
		Functions\when( 'WP_Filesystem' )->justReturn( true );
		Functions\when( 'get_home_path' )->justReturn( '/tmp/wordpress/' );

		$calls = array();
		Functions\when( 'insert_with_markers' )->alias(
			static function ( $file, $marker, $rules ) use ( &$calls ) {
				$calls[] = array(
					'file'   => $file,
					'marker' => $marker,
					'rules'  => $rules,
				);
				// First write fails; the restore write succeeds.
				return count( $calls ) > 1;
			}
		);

		$this->assertFalse( Htaccess_Handler::update_rules( true ) );
		$this->assertCount( 2, $calls );
		$this->assertContains( 'RuleA', $calls[1]['rules'] );
	}
}
