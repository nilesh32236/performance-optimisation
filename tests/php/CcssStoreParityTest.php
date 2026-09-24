<?php
/**
 * Parity tests for the FUT-001 Ccss_Store extraction (issue #1560).
 *
 * Ccss_Store owns the storage/staging/health/status cluster extracted
 * verbatim from Critical_CSS (file resolution, containment, stage /
 * promote / rollback, rollout status, health gate, variant files, content
 * access, status shapes, and storage memos); Critical_CSS keeps thin
 * same-signature static proxies. These tests pin owner behavior plus proxy
 * parity so the split cannot drift: path resolution + containment (incl.
 * traversal refusal), stage/promote/rollback lifecycle, health verdicts,
 * status shape, memo reset, and multisite domain isolation.
 *
 * An anonymous disk double (not a Mockery mock) keeps the
 * `method_exists( $filesystem, ... )` guards green while no real disk is
 * touched for the staged paths; the existence memo is pinned via
 * reflection so the real filesystem stays untouched.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Ccss_Store;
use PerformanceOptimise\Inc\Critical_CSS;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Storage/store parity tests for issue #1560.
 */
class CcssStoreParityTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap {
		setUp as private bootstrap_setup;
		tearDown as private bootstrap_teardown;
	}

	/**
	 * Original global $wpdb before it is swapped for the test fake.
	 *
	 * @var mixed
	 */
	private $original_wpdb;

	/**
	 * Original global $wp_filesystem before it is swapped for the test fake.
	 *
	 * @var mixed
	 */
	private $original_filesystem;

	/**
	 * Swap in fakes, then seed stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$this->bootstrap_setup();
		unset( $_SERVER['HTTP_HOST'] );
		global $wpdb, $wp_filesystem;
		$this->original_wpdb       = $wpdb;
		$this->original_filesystem = $wp_filesystem ?? null;
		$wpdb                      = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Pretend the activity-log insert succeeded.
			 *
			 * @param string $table  Table name.
			 * @param array  $data   Data to insert.
			 * @param mixed  $format Format array.
			 * @return int
			 */
			public function insert( $table, $data, $format = null ) {
				unset( $table, $data, $format );
				return 1;
			}
		};
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) {
				if ( 'wppo_settings' === $name ) {
					return array( 'file_optimisation' => array( 'purgeFallbackEnabled' => true ) );
				}
				return $fallback;
			}
		);
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		Functions\when( 'get_page_templates' )->justReturn( array() );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
	}

	/**
	 * Restore the original globals.
	 *
	 * @return void
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		Ccss_Store::set_status_cache_reader( null );
		Ccss_Store::reset_ccss_memo();
		global $wpdb, $wp_filesystem;
		$wpdb          = $this->original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_filesystem = $this->original_filesystem; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->bootstrap_teardown();
	}

	/**
	 * In-memory filesystem fake speaking the WP_Filesystem subset the slot helpers need.
	 *
	 * @return object
	 */
	private function make_fs() {
		return new class() {
			/**
			 * Path => contents store.
			 *
			 * @var array<string,string>
			 */
			public $files = array();

			/**
			 * Whether a path exists.
			 *
			 * @param string $path Path.
			 * @return bool
			 */
			public function exists( $path ) {
				return array_key_exists( $path, $this->files );
			}

			/**
			 * Byte size or false.
			 *
			 * @param string $path Path.
			 * @return int|false
			 */
			public function size( $path ) {
				return array_key_exists( $path, $this->files ) ? strlen( $this->files[ $path ] ) : false;
			}

			/**
			 * Read a file.
			 *
			 * @param string $path Path.
			 * @return string|false
			 */
			public function get_contents( $path ) {
				return array_key_exists( $path, $this->files ) ? $this->files[ $path ] : false;
			}

			/**
			 * Write a file.
			 *
			 * @param string $path     Path.
			 * @param string $contents Contents.
			 * @param mixed  $mode     Mode (ignored).
			 * @return bool
			 */
			public function put_contents( $path, $contents, $mode = 0644 ) {
				unset( $mode );
				$this->files[ $path ] = (string) $contents;
				return true;
			}

			/**
			 * Copy a file.
			 *
			 * @param string $source    Source.
			 * @param string $dest      Destination.
			 * @param mixed  $overwrite Overwrite flag.
			 * @return bool
			 */
			public function copy( $source, $dest, $overwrite = false ) {
				unset( $overwrite );
				if ( ! array_key_exists( $source, $this->files ) ) {
					return false;
				}
				$this->files[ $dest ] = $this->files[ $source ];
				return true;
			}

			/**
			 * Move a file.
			 *
			 * @param string $source    Source.
			 * @param string $dest      Destination.
			 * @param mixed  $overwrite Overwrite flag.
			 * @return bool
			 */
			public function move( $source, $dest, $overwrite = false ) {
				unset( $overwrite );
				if ( ! array_key_exists( $source, $this->files ) ) {
					return false;
				}
				$this->files[ $dest ] = $this->files[ $source ];
				unset( $this->files[ $source ] );
				return true;
			}

			/**
			 * Delete a file.
			 *
			 * @param string $path Path.
			 * @return bool
			 */
			public function delete( $path ) {
				unset( $this->files[ $path ] );
				return true;
			}

			/**
			 * Directory probe (fixtures pretend prepared).
			 *
			 * @param string $path Path.
			 * @return bool
			 */
			public function is_dir( $path ) {
				unset( $path );
				return true;
			}

			/**
			 * Directory creation (no-op: store is path-agnostic).
			 *
			 * @param string $path Path.
			 * @return bool
			 */
			public function mkdir( $path ) {
				unset( $path );
				return true;
			}
		};
	}

	/**
	 * Point Util::init_filesystem() at the in-memory fake.
	 *
	 * @param object $fs Filesystem fake.
	 * @return void
	 */
	private function use_fs( $fs ): void {
		$GLOBALS['wp_filesystem'] = $fs; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		Functions\when( 'WP_Filesystem' )->justReturn( true );
	}

	/**
	 * Invoke a Critical_CSS private proxy via reflection (no setAccessible).
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private function invoke_critical( string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( Critical_CSS::class, $method );
		return $ref->invokeArgs( null, $args );
	}

	/**
	 * Path resolution + containment agree between owner and facade.
	 *
	 * @return void
	 */
	public function test_path_resolution_and_containment(): void {
		$hash = Critical_CSS::get_template_hash( 'home' );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_\\-]{1,128}$/', $hash );

		$store_file    = Ccss_Store::get_ccss_file( $hash );
		$critical_file = $this->invoke_critical( 'get_ccss_file', array( $hash ) );
		$this->assertSame( $critical_file, $store_file );
		$this->assertStringEndsWith( '/' . $hash . '.css', $store_file );

		$this->assertTrue( Ccss_Store::is_ccss_path_contained( $store_file ) );
		$this->assertTrue( $this->invoke_critical( 'is_ccss_path_contained', array( $store_file ) ) );
		$this->assertFalse( Ccss_Store::is_ccss_path_contained( '/tmp/evil.css' ) );
		$this->assertFalse( Ccss_Store::is_ccss_path_contained( '' ) );

		$this->assertTrue( Ccss_Store::is_valid_template_hash( $hash ) );
		$this->assertTrue( $this->invoke_critical( 'is_valid_template_hash', array( $hash ) ) );
	}

	/**
	 * Traversal payloads are refused by both owner and facade.
	 *
	 * @return void
	 */
	public function test_traversal_refusal(): void {
		$this->use_fs( $this->make_fs() );

		foreach ( array( '../../evil', '', '../hash', 'a/b', 'hash.css' ) as $bad ) {
			$this->assertSame( '', Ccss_Store::get_ccss_file( $bad ), "hash {$bad} must resolve to ''" );
			$this->assertFalse( Ccss_Store::is_valid_template_hash( $bad ) );
		}
		// NUL byte never stays contained.
		$this->assertFalse( Ccss_Store::is_ccss_path_contained( "/c/x\0.css" ) );

		$refused = Ccss_Store::stage_ccss_for_template( '../../evil', '.a{color:red}' );
		$this->assertFalse( $refused['staged'] );
		$this->assertSame( 'hash', $refused['reason'] );

		$this->assertFalse( Ccss_Store::promote_staged_ccss( '../../evil' ) );
		$this->assertFalse( Ccss_Store::rollback_ccss_to_fallback( '../../evil' ) );

		// Facade mirrors the refusals.
		$critical_refused = Critical_CSS::stage_ccss_for_template( '../../evil', '.a{color:red}' );
		$this->assertFalse( $critical_refused['staged'] );
		$this->assertFalse( Critical_CSS::promote_staged_ccss( '../../evil' ) );
		$this->assertFalse( Critical_CSS::rollback_ccss_to_fallback( '../../evil' ) );
	}

	/**
	 * Store stage → promote → rollback lifecycle on a template hash.
	 *
	 * @return void
	 */
	public function test_stage_promote_rollback_lifecycle(): void {
		$fs = $this->make_fs();
		$this->use_fs( $fs );
		$hash = Critical_CSS::get_template_hash( 'home' );

		$preview = Ccss_Store::stage_ccss_for_template( $hash, '.hero{color:blue}' );
		$this->assertTrue( $preview['staged'] );
		$this->assertSame( '', $preview['reason'] );
		$this->assertTrue( $preview['changed'] );

		$this->assertTrue( Ccss_Store::promote_staged_ccss( $hash ) );

		$slot = Ccss_Store::get_ccss_rollout_status( $hash );
		$this->assertGreaterThan( 0, $slot['live_bytes'] );
		$this->assertFalse( $slot['staged'] );
		$this->assertSame( 'healthy', $slot['health'] );

		// Break live, keep last-good: rollback restores.
		$live = Ccss_Store::get_ccss_file( $hash );
		unset( $fs->files[ $live ] );
		$fs->files[ Util::get_purge_fallback_path_for( $live ) ] = '.good{color:green}';
		$this->assertTrue( Ccss_Store::rollback_ccss_to_fallback( $hash, 'manual' ) );
		$this->assertSame( '.good{color:green}', $fs->files[ $live ] );
	}

	/**
	 * Facade proxies return the same lifecycle verdicts as the owner.
	 *
	 * @return void
	 */
	public function test_facade_parity_lifecycle(): void {
		$fs = $this->make_fs();
		$this->use_fs( $fs );
		$hash = Critical_CSS::get_template_hash( 'single' );

		$owner_preview  = Ccss_Store::stage_ccss_for_template( $hash, '.card{color:red}' );
		$facade_preview = Critical_CSS::stage_ccss_for_template( $hash, '.card{color:red}' );
		$this->assertSame( $owner_preview['staged'], $facade_preview['staged'] );

		// One staged file exists: the owner consumes it, so re-stage before
		// the facade promote to compare like-for-like true verdicts.
		$this->assertTrue( Ccss_Store::promote_staged_ccss( $hash ) );
		Ccss_Store::stage_ccss_for_template( $hash, '.card{color:red}' );
		$this->assertTrue( Critical_CSS::promote_staged_ccss( $hash ) );

		$this->assertSame(
			Ccss_Store::get_ccss_rollout_status( $hash ),
			Critical_CSS::get_ccss_rollout_status( $hash )
		);
		$this->assertSame(
			Ccss_Store::verify_ccss_health( $hash ),
			Critical_CSS::verify_ccss_health( $hash )
		);
		$this->assertSame(
			Ccss_Store::get_status_all(),
			Critical_CSS::get_status_all()
		);
	}

	/**
	 * Health verdicts: healthy live, restored fallback, degraded empty.
	 *
	 * @return void
	 */
	public function test_health_verdicts(): void {
		$fs = $this->make_fs();
		$this->use_fs( $fs );
		$hash = Critical_CSS::get_template_hash( 'page' );

		$health = Ccss_Store::verify_ccss_health( $hash );
		$this->assertSame( 'degraded', $health['status'] );
		$this->assertSame( 'no-fallback', $health['reason'] );

		Ccss_Store::stage_ccss_for_template( $hash, '.live{color:red}' );
		$this->assertTrue( Ccss_Store::promote_staged_ccss( $hash ) );
		$health = Ccss_Store::verify_ccss_health( $hash );
		$this->assertSame( 'healthy', $health['status'] );
		$this->assertSame( 'live', $health['reason'] );

		$live = Ccss_Store::get_ccss_file( $hash );
		unset( $fs->files[ $live ] );
		$fs->files[ Util::get_purge_fallback_path_for( $live ) ] = '.good{color:green}';
		$health = Ccss_Store::verify_ccss_health( $hash );
		$this->assertSame( 'restored', $health['status'] );
		$this->assertSame( 'fallback', $health['reason'] );
		$this->assertSame( '.good{color:green}', $fs->files[ $live ] );
	}

	/**
	 * Status shape carries the rollout slot per template.
	 *
	 * @return void
	 */
	public function test_status_shape(): void {
		$this->use_fs( $this->make_fs() );
		$all = Ccss_Store::get_status_all();
		$this->assertNotEmpty( $all );
		foreach ( $all as $entry ) {
			$this->assertArrayHasKey( 'status', $entry );
			$this->assertArrayHasKey( 'label', $entry );
			$this->assertArrayHasKey( 'size', $entry );
			$this->assertArrayHasKey( 'truncated', $entry );
			$this->assertArrayHasKey( 'rollout', $entry );
			$this->assertArrayHasKey( 'health', $entry['rollout'] );
			$this->assertContains( $entry['rollout']['health'], array( 'healthy', 'restorable', 'degraded' ) );
		}
	}

	/**
	 * Memo reset clears owner memos; the facade clears both halves.
	 *
	 * @return void
	 */
	public function test_memo_reset(): void {
		$this->use_fs( $this->make_fs() );
		$hash = Critical_CSS::get_template_hash( 'home' );

		// Populate owner memos.
		Ccss_Store::ccss_exists( $hash );
		Ccss_Store::get_templates();
		Ccss_Store::get_sample_url( 'home' );

		$exists_prop = new \ReflectionProperty( Ccss_Store::class, 'ccss_exists_cache' );
		$this->assertNotEmpty( $exists_prop->getValue() );

		Ccss_Store::reset_ccss_memo();
		$this->assertSame( array(), $exists_prop->getValue() );
		$templates_prop = new \ReflectionProperty( Ccss_Store::class, 'templates_memo' );
		$this->assertNull( $templates_prop->getValue() );

		// Facade reset clears the store half too.
		Ccss_Store::ccss_exists( $hash );
		$this->assertNotEmpty( $exists_prop->getValue() );
		Critical_CSS::reset_ccss_memo();
		$this->assertSame( array(), $exists_prop->getValue() );
	}

	/**
	 * Canonical constants stay single-sourced (FUT-001 review).
	 *
	 * CCSS_DIR / TEMPLATE_HASH_PATTERN live only on Ccss_Store;
	 * VIEWPORT_VARIANTS keeps a BC alias on Critical_CSS pinned equal.
	 *
	 * @return void
	 */
	public function test_constant_parity(): void {
		$this->assertSame( '/cache/wppo/ccss', Ccss_Store::CCSS_DIR );
		$this->assertSame( '/^[A-Za-z0-9_\\-]{1,128}$/', Ccss_Store::TEMPLATE_HASH_PATTERN );
		$this->assertSame( Ccss_Store::VIEWPORT_VARIANTS, Critical_CSS::VIEWPORT_VARIANTS );
		$this->assertSame( array( 'mobile', 'desktop' ), Ccss_Store::VIEWPORT_VARIANTS );

		// Critical_CSS no longer duplicates the storage constants.
		$this->assertFalse( ( new \ReflectionClass( Critical_CSS::class ) )->hasConstant( 'CCSS_DIR' ) );
		$this->assertFalse( ( new \ReflectionClass( Critical_CSS::class ) )->hasConstant( 'TEMPLATE_HASH_PATTERN' ) );
	}

	/**
	 * Injected status-cache reader breaks the store -> facade edge.
	 *
	 * @return void
	 */
	public function test_status_cache_reader_seam(): void {
		$this->use_fs( $this->make_fs() );
		Ccss_Store::set_status_cache_reader(
			static function ( string $hash ): string {
				unset( $hash );
				return 'queued';
			}
		);
		try {
			$all = Ccss_Store::get_status_all();
			$this->assertNotEmpty( $all );
			foreach ( $all as $entry ) {
				$this->assertSame( 'queued', $entry['status'] );
			}
		} finally {
			Ccss_Store::set_status_cache_reader( null );
		}
		// Default bridge still resolves without the seam.
		$all = Ccss_Store::get_status_all();
		$this->assertNotEmpty( $all );
	}

	/**
	 * Multisite domain isolation: hashes and sample-URL memos are blog-scoped.
	 *
	 * @return void
	 */
	public function test_multisite_isolation(): void {
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		$hash_one = Critical_CSS::get_template_hash( 'home' );

		Functions\when( 'get_current_blog_id' )->justReturn( 2 );
		$hash_two = Critical_CSS::get_template_hash( 'home' );
		$this->assertNotSame( $hash_one, $hash_two );

		Ccss_Store::reset_ccss_memo();
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Ccss_Store::get_sample_url( 'home' );
		Functions\when( 'get_current_blog_id' )->justReturn( 2 );
		Ccss_Store::get_sample_url( 'home' );

		$sample_prop = new \ReflectionProperty( Ccss_Store::class, 'sample_url_cache' );
		$memos       = $sample_prop->getValue();
		$this->assertArrayHasKey( '1:home', $memos );
		$this->assertArrayHasKey( '2:home', $memos );
	}
}
