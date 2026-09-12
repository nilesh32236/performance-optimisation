<?php
/**
 * Tests for the used-CSS requeue guards (issue #1107).
 *
 * Used_CSS::regenerate_all() used to de-duplicate only against pending Action
 * Scheduler jobs, so every call re-queued the whole site once prior jobs
 * completed. These tests cover the coarse cooldown, the $force bypass,
 * and the per-post variant-freshness skip (plus the matching freshness
 * skip in requeue_for_post()).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Used_CSS;
use Brain\Monkey\Functions;

/**
 * Guard tests for Used_CSS::regenerate_all() / ::requeue_for_post().
 *
 * @package PerformanceOptimise\Tests
 */
class UsedCssRegenerateGuardTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap {
		tearDown as private bootstrap_teardown;
	}

	/**
	 * Original $wpdb handle so the stub can be restored per test.
	 *
	 * @var mixed
	 */
	private $orig_wpdb;

	/**
	 * Cache-tree directories created for freshness probes.
	 *
	 * @var string[]
	 */
	private array $created_dirs = array();

	/**
	 * Swap the global $wpdb for a stub with prepare/get_results/insert.
	 *
	 * @param array $rows Rows for get_results().
	 * @return void
	 */
	private function stub_wpdb( array $rows ): void {
		global $wpdb;
		$this->orig_wpdb = $wpdb;
		$stub            = new class() {
			/**
			 * Rows returned by get_results().
			 *
			 * @var array
			 */
			public array $rows = array();

			/**
			 * Table name used by the cursor query.
			 *
			 * @var string
			 */
			public $posts = 'wp_posts';

			/**
			 * Table prefix used by Log::add().
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Passthrough prepare stub.
			 *
			 * @param string $query Query.
			 * @param mixed  ...$args Args.
			 * @return string
			 */
			public function prepare( $query, ...$args ) {
				unset( $args );
				return $query;
			}

			/**
			 * Return the canned rows.
			 *
			 * @param string $query Query.
			 * @param mixed  $output Output type.
			 * @return array
			 */
			public function get_results( $query, $output = null ) {
				unset( $query, $output );
				return $this->rows;
			}

			/**
			 * Pretend the insert failed so Log::add() stays side-effect free.
			 *
			 * @param string $table Table.
			 * @param array  $data Data.
			 * @param mixed  $format Format.
			 * @return false
			 */
			public function insert( $table, $data, $format = null ) {
				unset( $table, $data, $format );
				return false;
			}
		};
		$stub->rows      = $rows;
		$wpdb            = $stub;
	}

	/**
	 * Restore the global $wpdb and remove created cache files.
	 */
	protected function tearDown(): void {
		if ( null !== $this->orig_wpdb ) {
			$GLOBALS['wpdb'] = $this->orig_wpdb;
			$this->orig_wpdb = null;
		}
		foreach ( $this->created_dirs as $dir ) {
			$this->remove_dir( $dir );
		}
		$this->created_dirs = array();
		$this->bootstrap_teardown();
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$path = $dir . '/' . $item;
				if ( is_dir( $path ) && ! is_link( $path ) ) {
					$this->remove_dir( $path );
				} else {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup only.
					unlink( $path );
				}
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup only.
	}

	/**
	 * Shared function stubs for the regenerate_all() path.
	 *
	 * @param array    $options Settings for wppo_settings.
	 * @param int      $last_regen Timestamp for the cooldown option.
	 * @param array    $updates Sink for update_option() writes.
	 * @param callable $permalink Permalink resolver (int $post_id => string|false).
	 * @return void
	 */
	private function stub_common( array $options, int $last_regen, array &$updates, callable $permalink ): void {
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'get_post_types' )->justReturn( array( 'post' => 'post' ) );
		Functions\when( 'as_get_scheduled_actions' )->justReturn( array() );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( $options, $last_regen ) {
				if ( 'wppo_settings' === $name ) {
					return $options;
				}
				if ( Used_CSS::LAST_FULL_REGEN_OPTION === $name ) {
					return $last_regen;
				}
				return $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value, $autoload = null ) use ( &$updates ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature must match update_option().
				$updates[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_permalink' )->alias( $permalink );
	}

	/**
	 * Create a fresh variant + checksum sidecar for a permalink.
	 *
	 * @param Used_CSS $used_css Instance.
	 * @param string   $permalink Permalink.
	 * @return void
	 */
	private function make_variant_fresh( Used_CSS $used_css, string $permalink ): void {
		$path = $used_css->get_used_css_path( $permalink );
		$this->assertNotSame( '', $path );
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup only.
			mkdir( $dir, 0755, true );
		}
		// Track the cache root for cleanup (first created ancestor).
		$this->created_dirs[] = WP_CONTENT_DIR . '/cache';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup only.
		file_put_contents( $path, '.a{color:red}' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup only.
		file_put_contents( $path . '.sha256', 'deadbeef' );
		// Variant mtime (now) is newer than the old modified_gmt used below.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Test fixture setup only.
		touch( $path, time() );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Test fixture setup only.
		touch( $path . '.sha256', time() );
	}

	/**
	 * A repeat call inside the cooldown window queues nothing.
	 */
	public function test_regenerate_all_respects_cooldown(): void {
		$updates  = array();
		$enqueued = array();
		$this->stub_common(
			array( 'file_optimisation' => array( 'removeUnusedCSS' => true ) ),
			time(),
			$updates,
			static function () {
				return false;
			}
		);
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( $hook, $args = array(), $group = '' ) use ( &$enqueued ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature must match as_enqueue_async_action().
				$enqueued[] = $args;
				return 1;
			}
		);

		$used_css = new Used_CSS( array( 'file_optimisation' => array( 'removeUnusedCSS' => true ) ) );
		$this->assertSame( 0, $used_css->regenerate_all() );
		$this->assertSame( array(), $enqueued );
		$this->assertArrayNotHasKey( Used_CSS::LAST_FULL_REGEN_OPTION, $updates );
	}

	/**
	 * $force bypasses the cooldown but still stamps the run.
	 */
	public function test_regenerate_all_force_bypasses_cooldown(): void {
		$updates = array();
		$this->stub_common(
			array( 'file_optimisation' => array( 'removeUnusedCSS' => true ) ),
			time(),
			$updates,
			static function () {
				return false;
			}
		);
		Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );
		$this->stub_wpdb( array() );

		$used_css = new Used_CSS( array( 'file_optimisation' => array( 'removeUnusedCSS' => true ) ) );
		$this->assertSame( 0, $used_css->regenerate_all( true ) );
		$this->assertArrayHasKey( Used_CSS::LAST_FULL_REGEN_OPTION, $updates );
	}

	/**
	 * Fresh posts are skipped; stale posts are queued.
	 */
	public function test_regenerate_all_skips_fresh_posts(): void {
		$updates  = array();
		$enqueued = array();
		$this->stub_common(
			array( 'file_optimisation' => array( 'removeUnusedCSS' => true ) ),
			0,
			$updates,
			static function ( $post_id ) {
				if ( 11 === (int) $post_id ) {
					return 'http://example.com/fresh-post/';
				}
				if ( 12 === (int) $post_id ) {
					return 'http://example.com/stale-post/';
				}
				return false;
			}
		);
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( $hook, $args = array(), $group = '' ) use ( &$enqueued ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature must match as_enqueue_async_action().
				$enqueued[] = $args;
				return 1;
			}
		);
		$this->stub_wpdb(
			array(
				array(
					'ID'                => 11,
					'post_modified_gmt' => '2020-01-01 00:00:00',
				),
				array(
					'ID'                => 12,
					'post_modified_gmt' => '2020-01-01 00:00:00',
				),
			)
		);

		$used_css = new Used_CSS( array( 'file_optimisation' => array( 'removeUnusedCSS' => true ) ) );
		$this->make_variant_fresh( $used_css, 'http://example.com/fresh-post/' );

		$this->assertSame( 1, $used_css->regenerate_all() );
		$this->assertSame( array( array( 'post_id' => 12 ) ), $enqueued );
		$this->assertArrayHasKey( Used_CSS::LAST_FULL_REGEN_OPTION, $updates );
	}

	/**
	 * The requeue_for_post() path skips enqueueing when the variant is already fresh.
	 */
	public function test_requeue_for_post_skips_fresh_variant(): void {
		$options  = array( 'file_optimisation' => array( 'removeUnusedCSS' => true ) );
		$updates  = array();
		$enqueued = array();
		$this->stub_common(
			$options,
			0,
			$updates,
			static function ( $post_id ) {
				if ( 42 === (int) $post_id ) {
					return 'http://example.com/fresh-post/';
				}
				return false;
			}
		);
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( $hook, $args = array(), $group = '' ) use ( &$enqueued ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature must match as_enqueue_async_action().
				$enqueued[] = $args;
				return 1;
			}
		);
		Functions\when( 'get_post' )->alias(
			static function ( $post_id ) {
				if ( 42 === (int) $post_id ) {
					$post                    = new \stdClass();
					$post->post_modified_gmt = '2020-01-01 00:00:00';
					return $post;
				}
				return null;
			}
		);

		$used_css = new Used_CSS( $options );
		$this->make_variant_fresh( $used_css, 'http://example.com/fresh-post/' );

		$this->assertTrue( Used_CSS::requeue_for_post( 42 ) );
		$this->assertSame( array(), $enqueued );
	}

	/**
	 * The requeue_for_post() path still enqueues when no fresh variant exists.
	 */
	public function test_requeue_for_post_enqueues_stale_variant(): void {
		$options  = array( 'file_optimisation' => array( 'removeUnusedCSS' => true ) );
		$updates  = array();
		$enqueued = array();
		$this->stub_common(
			$options,
			0,
			$updates,
			static function () {
				return 'http://example.com/missing-post/';
			}
		);
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( $hook, $args = array(), $group = '' ) use ( &$enqueued ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature must match as_enqueue_async_action().
				$enqueued[] = $args;
				return 1;
			}
		);
		Functions\when( 'get_post' )->alias(
			static function () {
				$post                    = new \stdClass();
				$post->post_modified_gmt = '2020-01-01 00:00:00';
				return $post;
			}
		);

		$this->assertTrue( Used_CSS::requeue_for_post( 43 ) );
		$this->assertSame( array( array( 'post_id' => 43 ) ), $enqueued );
	}
}
