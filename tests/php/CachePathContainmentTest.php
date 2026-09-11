<?php
/**
 * Tests for the centralized static-cache path containment helper (issue #1034).
 *
 * Covers Util::sanitize_cache_path() (traversal vectors, foreign-host,
 * multisite/domain isolation, filename allowlist, authority-only
 * absolute-form detection), Util::is_cache_path_contained() fail-closed
 * behavior, the shared atomic-write primitives, and the Used_CSS
 * default-'' (current-page) resolution.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Used_CSS;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Test fixtures are co-located by convention.
// phpcs:disable WordPress.Files.FileName -- Fixture class names cannot derive the *Test.php file name.

/**
 * In-memory $wpdb recorder so traversal-probe Log::add() can run.
 *
 * @package PerformanceOptimise\Tests
 * @since 2.0.0
 */
class WPPO_Containment_Wpdb_Recorder {

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
 * Fake filesystem with real methods for atomic-write tests.
 *
 * Real methods are required because atomic_file_put_contents() guards on
 * method_exists().
 *
 * @package PerformanceOptimise\Tests
 * @since 2.0.0
 */
class WPPO_Containment_Fake_Fs {

	/**
	 * Written contents keyed by path.
	 *
	 * @var array<string, string>
	 */
	public $written = array();

	/**
	 * Deleted paths.
	 *
	 * @var array<int, string>
	 */
	public $deleted = array();

	/**
	 * Whether move() should succeed.
	 *
	 * @var bool
	 */
	public $move_succeeds = true;

	/**
	 * Write contents.
	 *
	 * @param string $path     Path.
	 * @param string $contents Contents.
	 * @param int    $chmod    Mode.
	 * @return bool
	 */
	public function put_contents( $path, $contents, $chmod = 0644 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->written[ $path ] = $contents;
		return true;
	}

	/**
	 * Move a file.
	 *
	 * @param string $from      Source.
	 * @param string $to        Destination.
	 * @param bool   $overwrite Overwrite.
	 * @return bool
	 */
	public function move( $from, $to, $overwrite = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! $this->move_succeeds ) {
			return false;
		}
		if ( isset( $this->written[ $from ] ) ) {
			$this->written[ $to ] = $this->written[ $from ];
			unset( $this->written[ $from ] );
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
		$this->deleted[] = $path;
		unset( $this->written[ $path ] );
		return true;
	}
}

/**
 * Central containment helper tests.
 *
 * @package PerformanceOptimise\Tests
 * @since 2.0.0
 */
class CachePathContainmentTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Superglobal backup.
	 *
	 * @var array
	 */
	private $server_backup = array();

	/**
	 * Previous $wpdb instance to restore in tearDown.
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

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_kses_post' )->returnArg();

		$this->use_recorder_wpdb();
		$this->reset_used_css_probe_flag();
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
		if ( $this->wpdb_had_instance ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$this->wpdb_backup       = null;
		$this->wpdb_had_instance = false;
		$this->reset_used_css_probe_flag();
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Swap global $wpdb for an insert recorder so Log::add() can run.
	 */
	private function use_recorder_wpdb(): void {
		$this->wpdb_had_instance = isset( $GLOBALS['wpdb'] );
		$this->wpdb_backup       = $this->wpdb_had_instance ? $GLOBALS['wpdb'] : null;
		$GLOBALS['wpdb']         = new WPPO_Containment_Wpdb_Recorder();
	}

	/**
	 * Reset the once-per-request probe-log flag on Used_CSS.
	 */
	private function reset_used_css_probe_flag(): void {
		$prop = new \ReflectionProperty( Used_CSS::class, 'traversal_probe_logged' );
		$prop->setAccessible( true );
		$prop->setValue( null, false );
	}

	/**
	 * Cache root used across containment assertions.
	 *
	 * @return string
	 */
	private function root(): string {
		return '/tmp/wordpress/wp-content/cache/wppo';
	}

	/**
	 * Hostile inputs that must always be refused.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function refused_vector_provider(): array {
		return array(
			'plain dotdot'          => array( '../etc/passwd' ),
			'nested dotdot'         => array( 'foo/../../../bar' ),
			'single-encoded dotdot' => array( '%2e%2e/etc/passwd' ),
			'upper-encoded dotdot'  => array( '%2E%2E/x' ),
			'encoded slash'         => array( '/..%2f..%2fetc' ),
			'null byte'             => array( '%00' ),
			'null byte infix'       => array( 'a%00b' ),
			'drive prefix'          => array( 'C:/windows' ),
			'drive backslash'       => array( 'C:\\windows\\system32' ),
			'unc prefix'            => array( '\\\\server\\share' ),
			'protocol-relative'     => array( '//evil.com/about/' ),
			'foreign host'          => array( 'https://evil.com/about/' ),
			'foreign traversal'     => array( 'https://evil.com/../x' ),
		);
	}

	/**
	 * The central helper refuses traversal vectors.
	 *
	 * @param string $payload Hostile input.
	 */
	#[DataProvider( 'refused_vector_provider' )]
	public function test_sanitize_cache_path_refuses_vectors( string $payload ): void {
		$this->assertSame( '', Util::sanitize_cache_path( $this->root(), 'example.com', $payload, 'index.html' ) );
	}

	/**
	 * The central helper accepts benign inputs.
	 */
	public function test_sanitize_cache_path_accepts_benign(): void {
		$root = $this->root();

		$this->assertSame( "{$root}/example.com/index.html", Util::sanitize_cache_path( $root, 'example.com', '/', 'index.html' ) );
		$this->assertSame( "{$root}/example.com/index.html", Util::sanitize_cache_path( $root, 'example.com', '', 'index.html' ) );

		$nested = Util::sanitize_cache_path( $root, 'example.com', '/about/us/', 'index.html' );
		$this->assertSame( "{$root}/example.com/about/us/index.html", $nested );

		$same_host = Util::sanitize_cache_path( $root, 'example.com', 'https://example.com/about/', 'index.html' );
		$this->assertSame( "{$root}/example.com/about/index.html", $same_host );

		// Double-encoding stays literal on disk (never re-decoded): safe.
		$literal = Util::sanitize_cache_path( $root, 'example.com', '/%252e%252e/foo', 'index.html' );
		$this->assertStringContainsString( 'example.com/%2e%2e/foo/index.html', $literal );
	}

	/**
	 * A benign relative path whose query carries a URL is not misread as absolute-form.
	 */
	public function test_sanitize_cache_path_ignores_query_fragment_urls(): void {
		$resolved = Util::sanitize_cache_path( $this->root(), 'example.com', '/search?redirect=https://other', 'index.html' );
		$this->assertStringContainsString( 'example.com/search/index.html', $resolved );
	}

	/**
	 * Per-site domains are isolated: one site can never address another site tree.
	 */
	public function test_sanitize_cache_path_domain_isolation(): void {
		$root  = $this->root();
		$other = Util::sanitize_cache_path( $root, 'site2.example.com', '/about/', 'index.html' );

		$this->assertStringContainsString( 'site2.example.com/about/index.html', $other );
		$this->assertFalse( Util::is_cache_path_contained( $root, 'example.com', $other ) );
		$this->assertTrue( Util::is_cache_path_contained( $root, 'site2.example.com', $other ) );
	}

	/**
	 * File names are allowlisted.
	 */
	public function test_sanitize_cache_path_filename_allowlist(): void {
		$root = $this->root();

		foreach ( array( '../x', 'a/b', 'a\\b', "x\0y", str_repeat( 'a', 65 ) ) as $bad ) {
			$this->assertSame( '', Util::sanitize_cache_path( $root, 'example.com', '/about/', $bad ), "filename accepted: {$bad}" );
		}

		$this->assertStringContainsString(
			'used-css.css',
			Util::sanitize_cache_path( $root, 'example.com', '/about/', 'used-css.css' )
		);
	}

	/**
	 * Containment fails closed on dot-dot / null bytes even when prefix-matching.
	 */
	public function test_is_cache_path_contained_fails_closed(): void {
		$root = $this->root();

		$this->assertTrue( Util::is_cache_path_contained( $root, 'example.com', "{$root}/example.com/about/index.html" ) );
		$this->assertFalse( Util::is_cache_path_contained( $root, 'example.com', "{$root}/example.com/../evil/index.html" ) );
		$this->assertFalse( Util::is_cache_path_contained( $root, 'example.com', $root . "/example.com/a\0b/index.html" ) );
		// Sibling-prefix: `example.com-evil` must never match `example.com`.
		$this->assertFalse( Util::is_cache_path_contained( $root, 'example.com', "{$root}/example.com-evil/index.html" ) );
		$this->assertFalse( Util::is_cache_path_contained( '', 'example.com', "{$root}/example.com/index.html" ) );
	}

	/**
	 * Tmp names are unique siblings of the final path.
	 */
	public function test_atomic_tmp_path_unique_sibling(): void {
		$final = $this->root() . '/example.com/about/index.html';

		$first  = Util::atomic_tmp_path( $final );
		$second = Util::atomic_tmp_path( $final );

		$this->assertNotSame( $first, $second );
		$this->assertStringStartsWith( $final . '.tmp.', $first );
		$this->assertSame( '', Util::atomic_tmp_path( '' ) );
	}

	/**
	 * A failed move leaves no partial file behind.
	 */
	public function test_atomic_write_failed_move_leaves_no_partial(): void {
		$fs                = new WPPO_Containment_Fake_Fs();
		$fs->move_succeeds = false;
		$final             = $this->root() . '/example.com/about/index.html';

		$this->assertFalse( Util::atomic_file_put_contents( $fs, $final, '<html></html>' ) );
		$this->assertArrayNotHasKey( $final, $fs->written );
		$this->assertNotEmpty( $fs->deleted );
	}

	/**
	 * A successful atomic write lands the full contents at the final path.
	 */
	public function test_atomic_write_success(): void {
		$fs    = new WPPO_Containment_Fake_Fs();
		$final = $this->root() . '/example.com/about/index.html';

		$this->assertTrue( Util::atomic_file_put_contents( $fs, $final, '<html></html>' ) );
		$this->assertSame( '<html></html>', $fs->written[ $final ] ?? null );
	}

	/**
	 * Default-'' resolves the current REQUEST_URI page, not the homepage file.
	 */
	public function test_used_css_empty_url_resolves_current_page(): void {
		$_SERVER['HTTP_HOST']   = 'example.com';
		$_SERVER['REQUEST_URI'] = '/about/';

		$used_css = new Used_CSS();

		$this->assertStringContainsString( 'example.com/about/used-css.css', $used_css->get_used_css_path( '' ) );
		$this->assertStringContainsString( 'example.com/about/used-css.css', $used_css->get_used_css_url( '' ) );
	}

	/**
	 * Default-'' on the homepage still maps to the homepage file.
	 */
	public function test_used_css_empty_url_homepage(): void {
		$_SERVER['HTTP_HOST']   = 'example.com';
		$_SERVER['REQUEST_URI'] = '/';

		$used_css = new Used_CSS();

		$this->assertStringContainsString( 'example.com/used-css.css', $used_css->get_used_css_path( '' ) );
	}

	/**
	 * Default-'' with a hostile REQUEST_URI is refused, never mapped to homepage.
	 */
	public function test_used_css_empty_url_hostile_request_uri_refused(): void {
		$_SERVER['HTTP_HOST']   = 'example.com';
		$_SERVER['REQUEST_URI'] = '/../etc/passwd';

		$used_css = new Used_CSS();

		$this->assertSame( '', $used_css->get_used_css_path( '' ) );
		$this->assertSame( '', $used_css->get_used_css_url( '' ) );
	}
}
