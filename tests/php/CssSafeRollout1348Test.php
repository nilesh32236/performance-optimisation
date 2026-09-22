<?php
/**
 * Tests for issue #1348: safe CSS/JS rollout (stage → preview → promote).
 *
 * Covers the shared Util slot helpers (staged-path mapping, promote with
 * last-good retention, fallback restore, slot description) plus the
 * Used_CSS / Critical_CSS stage → promote → verify → rollback round-trips
 * and the read-only rollout status payloads. Generation dry-runs are
 * exercised through their refusal paths only (the fetch pipeline needs a
 * live HTTP round-trip).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Critical_CSS;
use PerformanceOptimise\Inc\Rest;
use PerformanceOptimise\Inc\Used_CSS;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Safe-rollout tests for issue #1348.
 */
class CssSafeRollout1348Test extends \PHPUnit\Framework\TestCase {
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
		// Host-header residue from host-header tests (e.g.
		// UsedCssHostTest) would flip this instance into
		// host-mismatch refusal: these tests always run canonical.
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
					// Last-good retention engaged so promote-time
					// fallback assertions exercise the real path.
					return array( 'file_optimisation' => array( 'purgeFallbackEnabled' => true ) );
				}
				return $fallback;
			}
		);
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		Functions\when( 'get_page_templates' )->justReturn( array() );
	}

	/**
	 * Restore the original globals.
	 *
	 * @return void
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
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
			 * @param string $source      Source.
			 * @param string $dest        Destination.
			 * @param mixed  $overwrite   Overwrite flag.
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
	 * Staged-path mapping covers plain, variant, and hash files.
	 *
	 * @return void
	 */
	public function test_staged_path_mapping(): void {
		$this->assertSame(
			'/c/used-css.staged.css',
			Util::get_staged_path_for( '/c/used-css.css' )
		);
		$this->assertSame(
			'/c/used-css.mobile.staged.css',
			Util::get_staged_path_for( '/c/used-css.mobile.css' )
		);
		$this->assertSame(
			'/c/abc123.staged.css',
			Util::get_staged_path_for( '/c/abc123.css' )
		);
	}

	/**
	 * Staged-path mapping refuses loops, fallbacks, blobs, and probes.
	 *
	 * @return void
	 */
	public function test_staged_path_refusals(): void {
		$this->assertSame( '', Util::get_staged_path_for( '' ) );
		$this->assertSame( '', Util::get_staged_path_for( '/c/fallback.css' ) );
		$this->assertSame( '', Util::get_staged_path_for( '/c/used-css.staged.css' ) );
		$this->assertSame( '', Util::get_staged_path_for( '/c/used-css.css.gz' ) );
		$this->assertSame( '', Util::get_staged_path_for( '/c/page.html' ) );
		$this->assertSame( '', Util::get_staged_path_for( '/c/../evil.css' ) );
		// Non-absolute input has no directory sibling to stage into.
		$this->assertSame( '', Util::get_staged_path_for( 'used-css.css' ) );
	}

	/**
	 * Promote swaps staged over live and consumes the stage.
	 *
	 * @return void
	 */
	public function test_promote_staged_file(): void {
		$fs                                  = $this->make_fs();
		$live                                = '/c/used-css.css';
		$fs->files[ $live ]                  = '.old{color:red}';
		$fs->files['/c/used-css.staged.css'] = '.new{color:blue}';
		$allow                               = static function ( $path ) {
			return 0 === strpos( $path, '/c/' );
		};
		$this->assertTrue( Util::promote_staged_file( $fs, $allow, $live ) );
		$this->assertSame( '.new{color:blue}', $fs->files[ $live ] );
		$this->assertArrayNotHasKey( '/c/used-css.staged.css', $fs->files );
		// Last-good retained alongside the promote.
		$this->assertSame( '.old{color:red}', $fs->files['/c/fallback.css'] );
	}

	/**
	 * Promote is a no-op without a servable stage.
	 *
	 * @return void
	 */
	public function test_promote_without_stage_is_noop(): void {
		$fs    = $this->make_fs();
		$live  = '/c/used-css.css';
		$allow = static function ( $path ) {
			return 0 === strpos( $path, '/c/' );
		};
		$this->assertFalse( Util::promote_staged_file( $fs, $allow, $live ) );
		$this->assertArrayNotHasKey( $live, $fs->files );
		$fs->files[ $live ]                  = '.live{color:red}';
		$fs->files['/c/used-css.staged.css'] = '';
		$this->assertFalse( Util::promote_staged_file( $fs, $allow, $live ) );
		$this->assertSame( '.live{color:red}', $fs->files[ $live ] );
	}

	/**
	 * Restore copies the fallback over live and keeps the fallback.
	 *
	 * @return void
	 */
	public function test_restore_fallback_file(): void {
		$fs                           = $this->make_fs();
		$live                         = '/c/used-css.css';
		$fs->files['/c/fallback.css'] = '.good{color:green}';
		$allow                        = static function ( $path ) {
			return 0 === strpos( $path, '/c/' );
		};
		$this->assertTrue( Util::restore_fallback_file( $fs, $allow, $live ) );
		$this->assertSame( '.good{color:green}', $fs->files[ $live ] );
		$this->assertSame( '.good{color:green}', $fs->files['/c/fallback.css'] );
		// A directory with no fallback sibling restores nothing.
		$lonely_allow = static function ( $path ) {
			return 0 === strpos( $path, '/lonely/' );
		};
		$this->assertFalse( Util::restore_fallback_file( $fs, $lonely_allow, '/lonely/used-css.css' ) );
	}

	/**
	 * Slot description reports sizes, checksums, change, and fallback flags.
	 *
	 * @return void
	 */
	public function test_describe_rollout_slot(): void {
		$fs                                  = $this->make_fs();
		$live                                = '/c/used-css.css';
		$fs->files[ $live ]                  = '.a{color:red}';
		$fs->files['/c/used-css.staged.css'] = '.a{color:blue}';
		$fs->files['/c/fallback.css']        = '.a{color:red}';
		$slot                                = Util::describe_rollout_slot( $fs, $live );
		$this->assertSame( 13, $slot['live_bytes'] );
		$this->assertNotSame( '', $slot['live_checksum'] );
		$this->assertTrue( $slot['staged'] );
		$this->assertSame( 14, $slot['staged_bytes'] );
		$this->assertTrue( $slot['staged_changed'] );
		$this->assertTrue( $slot['fallback'] );
		// Identical stage reads as unchanged.
		$fs->files['/c/used-css.staged.css'] = '.a{color:red}';
		$slot                                = Util::describe_rollout_slot( $fs, $live );
		$this->assertTrue( $slot['staged'] );
		$this->assertFalse( $slot['staged_changed'] );
	}

	/**
	 * Build a Used_CSS instance resolving under the fixture content dir.
	 *
	 * @return Used_CSS
	 */
	private function make_used_css(): Used_CSS {
		return new Used_CSS( array() );
	}

	/**
	 * Used-CSS stage → promote round-trip keeps live untouched until promote.
	 *
	 * @return void
	 */
	public function test_used_css_stage_promote_roundtrip(): void {
		$fs = $this->make_fs();
		$this->use_fs( $fs );
		$url      = 'http://example.com/sample-page/';
		$used_css = $this->make_used_css();
		$live     = $used_css->get_used_css_path( $url );
		$this->assertNotSame( '', $live );

		$preview = $used_css->stage_used_css( '.new{color:blue}', $url );
		$this->assertTrue( $preview['staged'] );
		$this->assertSame( '', $preview['reason'] );
		$this->assertGreaterThan( 0, $preview['bytes'] );
		$this->assertNotSame( '', $preview['checksum'] );
		$this->assertSame( 0, $preview['live_bytes'] );
		// No live file yet: the stage trivially differs.
		$this->assertTrue( $preview['changed'] );
		$this->assertArrayNotHasKey( $live, $fs->files );

		$this->assertTrue( $used_css->promote_staged_used_css( $url ) );
		$this->assertSame( '.new{color:blue}', $fs->files[ $live ] );
		$this->assertArrayNotHasKey( Util::get_staged_path_for( $live ), $fs->files );

		// Second promote with no stage is a no-op.
		$this->assertFalse( $used_css->promote_staged_used_css( $url ) );
	}

	/**
	 * Used-CSS health gate: healthy live, restore-from-fallback, degraded.
	 *
	 * @return void
	 */
	public function test_used_css_verify_health_states(): void {
		$fs = $this->make_fs();
		$this->use_fs( $fs );
		$url      = 'http://example.com/health-page/';
		$used_css = $this->make_used_css();
		$live     = $used_css->get_used_css_path( $url );
		$this->assertNotSame( '', $live );

		// Nothing anywhere: degraded with no fallback.
		$health = $used_css->verify_used_css_health( $url );
		$this->assertSame( 'degraded', $health['status'] );
		$this->assertSame( 'no-fallback', $health['reason'] );

		// Seed live via stage + promote, then break it with a fallback present.
		$used_css->stage_used_css( '.live{color:red}', $url );
		$this->assertTrue( $used_css->promote_staged_used_css( $url ) );
		$health = $used_css->verify_used_css_health( $url );
		$this->assertSame( 'healthy', $health['status'] );
		$this->assertSame( 'live', $health['reason'] );

		// Simulate a broken deploy: live deleted, last-good retained.
		unset( $fs->files[ $live ] );
		$fs->files[ Util::get_purge_fallback_path_for( $live ) ] = '.good{color:green}';
		$health = $used_css->verify_used_css_health( $url );
		$this->assertSame( 'restored', $health['status'] );
		$this->assertSame( 'fallback', $health['reason'] );
		$this->assertSame( '.good{color:green}', $fs->files[ $live ] );
	}

	/**
	 * Used-CSS explicit rollback restores last-good and reports honestly.
	 *
	 * @return void
	 */
	public function test_used_css_explicit_rollback(): void {
		$fs = $this->make_fs();
		$this->use_fs( $fs );
		$url      = 'http://example.com/rollback-page/';
		$used_css = $this->make_used_css();

		// No fallback anywhere: honest false.
		$this->assertFalse( $used_css->rollback_used_css_to_fallback( $url, 'manual' ) );

		$live = $used_css->get_used_css_path( $url );
		$fs->files[ Util::get_purge_fallback_path_for( $live ) ] = '.good{color:green}';
		$this->assertTrue( $used_css->rollback_used_css_to_fallback( $url, 'manual' ) );
		$this->assertSame( '.good{color:green}', $fs->files[ $live ] );
	}

	/**
	 * Preview generation refuses bad posts and off-host permalinks without fetching.
	 *
	 * @return void
	 */
	public function test_generate_preview_refusals(): void {
		$used_css = $this->make_used_css();

		$refused = $used_css->generate_preview_for_post( 0 );
		$this->assertFalse( $refused['staged'] );
		$this->assertSame( 'post', $refused['reason'] );

		Functions\when( 'get_permalink' )->justReturn( false );
		$refused = $used_css->generate_preview_for_post( 42 );
		$this->assertFalse( $refused['staged'] );
		$this->assertSame( 'post', $refused['reason'] );

		Functions\when( 'get_permalink' )->justReturn( 'http://evil.example/health/' );
		$refused = $used_css->generate_preview_for_post( 42 );
		$this->assertFalse( $refused['staged'] );
		$this->assertSame( 'host', $refused['reason'] );
	}

	/**
	 * Critical-CSS stage → promote → verify round-trip on a template hash.
	 *
	 * @return void
	 */
	public function test_ccss_stage_promote_verify_roundtrip(): void {
		$fs = $this->make_fs();
		$this->use_fs( $fs );
		$hash = Critical_CSS::get_template_hash( 'home' );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_\-]{1,128}$/', $hash );

		$preview = Critical_CSS::stage_ccss_for_template( $hash, '.hero{color:blue}' );
		$this->assertTrue( $preview['staged'] );
		$this->assertSame( '', $preview['reason'] );
		$this->assertTrue( $preview['changed'] );

		$this->assertTrue( Critical_CSS::promote_staged_ccss( $hash ) );

		$slot = Critical_CSS::get_ccss_rollout_status( $hash );
		$this->assertGreaterThan( 0, $slot['live_bytes'] );
		$this->assertFalse( $slot['staged'] );
		$this->assertSame( 'healthy', $slot['health'] );

		$health = Critical_CSS::verify_ccss_health( $hash );
		$this->assertSame( 'healthy', $health['status'] );
	}

	/**
	 * Critical-CSS health restores last-good when live is broken.
	 *
	 * @return void
	 */
	public function test_ccss_verify_restores_fallback(): void {
		$fs = $this->make_fs();
		$this->use_fs( $fs );
		$hash = Critical_CSS::get_template_hash( 'single' );

		$health = Critical_CSS::verify_ccss_health( $hash );
		$this->assertSame( 'degraded', $health['status'] );
		$this->assertSame( 'no-fallback', $health['reason'] );

		// Broken live (empty file) with a retained fallback restores.
		$live               = WP_CONTENT_DIR . '/cache/wppo/ccss/' . $hash . '.css';
		$fs->files[ $live ] = '';
		$fs->files[ Util::get_purge_fallback_path_for( $live ) ] = '.good{color:green}';
		$health = Critical_CSS::verify_ccss_health( $hash );
		$this->assertSame( 'restored', $health['status'] );
		$this->assertSame( '.good{color:green}', $fs->files[ $live ] );
	}

	/**
	 * Critical-CSS staging refuses hostile hashes and unservable CSS.
	 *
	 * @return void
	 */
	public function test_ccss_stage_refusals(): void {
		$fs = $this->make_fs();
		$this->use_fs( $fs );

		$refused = Critical_CSS::stage_ccss_for_template( '../../evil', '.a{color:red}' );
		$this->assertFalse( $refused['staged'] );
		$this->assertSame( 'hash', $refused['reason'] );

		$hash    = Critical_CSS::get_template_hash( 'page' );
		$refused = Critical_CSS::stage_ccss_for_template( $hash, '' );
		$this->assertFalse( $refused['staged'] );
		$this->assertSame( 'empty', $refused['reason'] );

		$this->assertFalse( Critical_CSS::promote_staged_ccss( '../../evil' ) );
		$this->assertFalse( Critical_CSS::rollback_ccss_to_fallback( '../../evil' ) );
	}

	/**
	 * Status payload carries the rollout slot per template.
	 *
	 * @return void
	 */
	public function test_status_all_carries_rollout(): void {
		$fs = $this->make_fs();
		$this->use_fs( $fs );
		$all = Critical_CSS::get_status_all();
		$this->assertNotEmpty( $all );
		foreach ( $all as $hash => $entry ) {
			$this->assertArrayHasKey( 'rollout', $entry );
			$this->assertArrayHasKey( 'health', $entry['rollout'] );
			$this->assertContains( $entry['rollout']['health'], array( 'healthy', 'restorable', 'degraded' ) );
		}
	}

	/**
	 * Rollout flags without a post scope 400 instead of bulk-queueing.
	 *
	 * Guards the issue #1348 review footgun: dry_run/promote/rollback/
	 * health with no post_id must never fall through to bulk
	 * regenerate_all().
	 *
	 * @return void
	 */
	public function test_used_css_rollout_flags_require_post_id(): void {
		$rest = new Rest();
		foreach ( array( 'dry_run', 'promote', 'rollback', 'health' ) as $flag ) {
			$request  = new \WP_REST_Request( array( $flag => 1 ) );
			$response = $rest->used_css_regenerate( $request );
			$this->assertSame( 400, $response->get_status(), "Flag {$flag} without post_id must 400" );
			$this->assertFalse( $response->get_data()['success'] );
		}
	}

	/**
	 * Rollout flags without a template scope 400 instead of bulk-queueing.
	 *
	 * @return void
	 */
	public function test_ccss_rollout_flags_require_template(): void {
		$rest = new Rest();
		foreach ( array( 'dry_run', 'promote', 'rollback', 'health' ) as $flag ) {
			$request  = new \WP_REST_Request( array( $flag => 1 ) );
			$response = $rest->regenerate_ccss( $request );
			$this->assertSame( 400, $response->get_status(), "Flag {$flag} without template must 400" );
			$this->assertFalse( $response->get_data()['success'] );
		}
	}

	/**
	 * CCSS dry-run preview refuses unknown templates without generating.
	 *
	 * @return void
	 */
	public function test_ccss_preview_refuses_unknown_template(): void {
		$preview = Critical_CSS::generate_preview_for_template( 'no-such-template' );
		$this->assertFalse( $preview['staged'] );
		$this->assertSame( 'template', $preview['reason'] );
	}
}
