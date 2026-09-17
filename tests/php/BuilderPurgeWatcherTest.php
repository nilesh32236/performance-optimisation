<?php
/**
 * Tests for the builder-update purge watcher (issue #907).
 *
 * Covers upgrader-payload routing (single/bulk plugin updates, theme
 * updates, non-builder and non-update payloads), the filter-extensible
 * builder map, scoped builder-directory deletion (never the bare uploads or
 * content base), and the full purge flow (WPPO derived caches, audit log,
 * admin-notice transient, wppo_after_builder_purge action).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Builder_Purge_Watcher;
use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\Critical_CSS;
use PerformanceOptimise\Inc\Used_CSS;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Tests for the builder-update purge watcher.
 *
 * @package PerformanceOptimise\Tests
 */
class BuilderPurgeWatcherTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Set up Brain Monkey and common stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		\PerformanceOptimise\Inc\Util::reset_runtime_caches();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
	}

	/**
	 * Tear down Brain Monkey and the filesystem mock.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		unset( $GLOBALS['wp_filesystem'] );
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The watcher registers all seven hooks with their expected specs.
	 */
	public function test_register_hooks_upgrader_action(): void {
		$calls = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$calls ) {
				$calls[] = array( $hook, $callback, $priority, $args );
			}
		);

		( new Builder_Purge_Watcher() )->register();

		$expected = array(
			array( 'upgrader_process_complete', 10, 2 ),
			array( 'upgrader_process_complete', 20, 2 ),
			array( 'elementor/core/files/clear_cache', 10, 0 ),
			array( 'elementor/editor/after_save', 10, 2 ),
			array( 'elementor/css-file/post/parse_after', 10, 2 ),
			array( Builder_Purge_Watcher::DRIFT_PURGE_HOOK, 10, 0 ),
			array( Builder_Purge_Watcher::UPGRADE_PURGE_HOOK, 10, 1 ),
		);
		// Only count watcher hooks: Util::get_settings() lazily registers its
		// own cache hooks via add_action() in the same process.
		$watcher_calls = array_values(
			array_filter(
				$calls,
				static function ( $call ) use ( $expected ) {
					return in_array( $call[0], array_column( $expected, 0 ), true );
				}
			)
		);
		foreach ( $expected as $spec ) {
			$found = false;
			foreach ( $watcher_calls as $call ) {
				if ( $spec[0] === $call[0] && $spec[1] === $call[2] && $spec[2] === $call[3] ) {
					$found = true;
					break;
				}
			}
			$this->assertTrue( $found, sprintf( 'Expected registration for hook %s.', $spec[0] ) );
		}
		$this->assertCount( 7, $watcher_calls, 'register() must register exactly the seven watcher hooks.' );
	}

	/**
	 * A disabled watcher registers nothing (issue #1288).
	 */
	public function test_register_disabled_is_noop(): void {
		Util::set_settings_cache(
			array(
				'file_optimisation' => array(
					'builderPurgeWatcher' => false,
				),
			)
		);
		$calls = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$calls ) {
				$calls[] = array( $hook, $callback, $priority, $args );
			}
		);

		( new Builder_Purge_Watcher() )->register();

		// Only watcher hooks count: Util::set_settings_cache() lazily
		// registers its own cache hooks via add_action() in the same process.
		$watcher_hooks = array(
			'upgrader_process_complete',
			'elementor/core/files/clear_cache',
			'elementor/editor/after_save',
			Builder_Purge_Watcher::DRIFT_PURGE_HOOK,
		);
		$watcher_calls = array_values(
			array_filter(
				$calls,
				static function ( $call ) use ( $watcher_hooks ) {
					return in_array( $call[0], $watcher_hooks, true );
				}
			)
		);
		$this->assertSame( array(), $watcher_calls, 'Disabled watcher must not register any hook.' );
	}

	/**
	 * The default map covers the four builders.
	 */
	public function test_default_map_covers_four_builders(): void {
		$map = Builder_Purge_Watcher::get_builder_map();
		$this->assertSame( array( 'elementor', 'divi', 'bricks', 'wpbakery' ), array_keys( $map ) );
	}

	/**
	 * Non-builder plugin updates (including WPPO itself) trigger no purge.
	 */
	public function test_non_builder_update_does_nothing(): void {
		$watcher = new WPPO_Test_Builder_Watcher();

		$watcher->on_builder_update(
			null,
			array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => 'akismet/akismet.php',
			)
		);
		$this->assertNull( $watcher->purged );

		// WPPO's own update path must never purge via this watcher.
		$watcher->on_builder_update(
			null,
			array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => 'performance-optimisation/performance-optimisation.php',
			)
		);
		$this->assertNull( $watcher->purged );
	}

	/**
	 * Non-update upgrader actions are ignored even for builder slugs.
	 */
	public function test_non_update_action_ignored(): void {
		$watcher = new WPPO_Test_Builder_Watcher();

		$watcher->on_builder_update(
			null,
			array(
				'action' => 'install',
				'type'   => 'plugin',
				'plugin' => 'elementor/elementor.php',
			)
		);
		$this->assertNull( $watcher->purged );

		$watcher->on_builder_update( null, 'not-an-array' );
		$this->assertNull( $watcher->purged );
	}

	/**
	 * Single Elementor plugin update purges for the elementor builder.
	 */
	public function test_elementor_single_update_purges(): void {
		$watcher = new WPPO_Test_Builder_Watcher();

		$watcher->on_builder_update(
			null,
			array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => 'elementor/elementor.php',
			)
		);
		$this->assertSame( array( 'elementor' ), $watcher->purged );
	}

	/**
	 * Bulk plugin updates match builder slugs inside the plugins array.
	 */
	public function test_bulk_update_matches_builder(): void {
		$watcher = new WPPO_Test_Builder_Watcher();

		$watcher->on_builder_update(
			null,
			array(
				'action'  => 'update',
				'type'    => 'plugin',
				'plugins' => array( 'akismet/akismet.php', 'elementor-pro/elementor-pro.php' ),
			)
		);
		$this->assertSame( array( 'elementor' ), $watcher->purged );
	}

	/**
	 * Divi theme updates (single + bulk) match the divi builder.
	 */
	public function test_divi_theme_update_purges(): void {
		$watcher = new WPPO_Test_Builder_Watcher();

		$watcher->on_builder_update(
			null,
			array(
				'action' => 'update',
				'type'   => 'theme',
				'theme'  => 'Divi',
			)
		);
		$this->assertSame( array( 'divi' ), $watcher->purged );

		$watcher->purged = null;
		$watcher->on_builder_update(
			null,
			array(
				'action' => 'update',
				'type'   => 'theme',
				'themes' => array( 'twentytwentyfive', 'divi' ),
			)
		);
		$this->assertSame( array( 'divi' ), $watcher->purged );
	}

	/**
	 * Bricks theme updates match the bricks builder.
	 */
	public function test_bricks_theme_update_purges(): void {
		$watcher = new WPPO_Test_Builder_Watcher();

		$watcher->on_builder_update(
			null,
			array(
				'action' => 'update',
				'type'   => 'theme',
				'theme'  => 'bricks',
			)
		);
		$this->assertSame( array( 'bricks' ), $watcher->purged );
	}

	/**
	 * WPBakery plugin updates match the wpbakery builder.
	 */
	public function test_wpbakery_update_purges(): void {
		$watcher = new WPPO_Test_Builder_Watcher();

		$watcher->on_builder_update(
			null,
			array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => 'js_composer/js_composer.php',
			)
		);
		$this->assertSame( array( 'wpbakery' ), $watcher->purged );
	}

	/**
	 * Divi Builder plugin updates match the divi builder too.
	 */
	public function test_divi_builder_plugin_update_purges(): void {
		$watcher = new WPPO_Test_Builder_Watcher();

		$watcher->on_builder_update(
			null,
			array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => 'divi-builder/divi-builder.php',
			)
		);
		$this->assertSame( array( 'divi' ), $watcher->purged );
	}

	/**
	 * The wppo_builder_purge_map filter can add builders.
	 */
	public function test_builder_map_filter_can_extend(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'wppo_builder_purge_map' === $tag && is_array( $value ) ) {
					$value['acme'] = array(
						'label'           => 'Acme',
						'plugins'         => array( 'acme/acme.php' ),
						'themes'          => array(),
						'upload_subdirs'  => array(),
						'content_subdirs' => array(),
						'clear_hooks'     => array(),
					);
				}
				return $value;
			}
		);

		$map = Builder_Purge_Watcher::get_builder_map();
		$this->assertArrayHasKey( 'acme', $map );

		$watcher = new WPPO_Test_Builder_Watcher();
		$watcher->on_builder_update(
			null,
			array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => 'acme/acme.php',
			)
		);
		$this->assertSame( array( 'acme' ), $watcher->purged );
	}

	/**
	 * A non-array wppo_builder_purge_map filter result falls back to the
	 * default map.
	 */
	public function test_builder_map_filter_non_array_falls_back(): void {
		Functions\when( 'apply_filters' )->justReturn( 'not-an-array' );

		$map = Builder_Purge_Watcher::get_builder_map();
		$this->assertSame( array( 'elementor', 'divi', 'bricks', 'wpbakery' ), array_keys( $map ) );
	}

	/**
	 * Non-string slugs in a filtered map entry are ignored instead of
	 * fataling, and valid slugs in the same entry still match.
	 */
	public function test_match_builders_ignores_non_string_slugs(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'wppo_builder_purge_map' === $tag && is_array( $value ) ) {
					$value['weird'] = array(
						'label'           => 'Weird',
						'plugins'         => array( array( 'nested' ), 42, 'weird/weird.php' ),
						'themes'          => array(),
						'upload_subdirs'  => array(),
						'content_subdirs' => array(),
						'clear_hooks'     => array(),
					);
				}
				return $value;
			}
		);

		$watcher = new WPPO_Test_Builder_Watcher();
		$watcher->on_builder_update(
			null,
			array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => 'weird/weird.php',
			)
		);
		$this->assertSame( array( 'weird' ), $watcher->purged );
	}

	/**
	 * Builder-directory purge is scoped to the listed subdirectories —
	 * never the bare uploads or content base directories.
	 */
	public function test_purge_scoped_to_builder_subdirs(): void {
		Functions\when( 'WP_Filesystem' )->justReturn( true );
		$fs                       = new WPPO_Builder_FS_Mock();
		$GLOBALS['wp_filesystem'] = $fs;
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => '/tmp/wordpress/wp-content/uploads',
				'baseurl' => 'http://example.com/wp-content/uploads',
			)
		);

		$watcher = new Builder_Purge_Watcher();
		$method  = new \ReflectionMethod( $watcher, 'purge_builder_directories' );

		$deleted = $method->invoke( $watcher, array( 'elementor', 'divi' ), Builder_Purge_Watcher::get_builder_map() );

		$this->assertContains( '/tmp/wordpress/wp-content/uploads/elementor/css', $deleted );
		$this->assertContains( '/tmp/wordpress/wp-content/et-cache', $deleted );

		$uploads_base = wp_normalize_path( '/tmp/wordpress/wp-content/uploads' );
		$content_base = wp_normalize_path( WP_CONTENT_DIR );
		foreach ( $fs->deleted as $dir ) {
			$this->assertNotSame( $uploads_base, $dir, 'Must never delete the bare uploads directory.' );
			$this->assertNotSame( $content_base, $dir, 'Must never delete the bare content directory.' );
		}
		$this->assertNotEmpty( $fs->deleted );
	}

	/**
	 * Dot-only map segments never resolve to a deletable directory.
	 */
	public function test_scoped_dir_rejects_dot_segments(): void {
		$watcher = new Builder_Purge_Watcher();
		$method  = new \ReflectionMethod( $watcher, 'scoped_cache_dir' );

		$base = '/tmp/wordpress/wp-content/uploads';
		$this->assertSame( '', $method->invoke( $watcher, $base, '.' ) );
		$this->assertSame( '', $method->invoke( $watcher, $base, '...' ) );
		$this->assertSame( '', $method->invoke( $watcher, $base, '../x' ) );
		$this->assertSame( '', $method->invoke( $watcher, $base, '' ) );
		$this->assertSame( $base . '/elementor/css', $method->invoke( $watcher, $base, 'elementor/css' ) );
	}

	/**
	 * Directory purge is a no-op when the filesystem is unavailable.
	 */
	public function test_purge_directories_noop_without_filesystem(): void {
		Functions\when( 'WP_Filesystem' )->justReturn( false );
		unset( $GLOBALS['wp_filesystem'] );

		$watcher = new Builder_Purge_Watcher();
		$method  = new \ReflectionMethod( $watcher, 'purge_builder_directories' );

		$this->assertSame( array(), $method->invoke( $watcher, array( 'elementor' ), Builder_Purge_Watcher::get_builder_map() ) );
	}

	/**
	 * Entries flagged css_only (Bricks/WPBakery) delete only top-level
	 * *.css files, leaving other files and subdirectories untouched.
	 */
	public function test_css_only_entries_delete_only_css_files(): void {
		Functions\when( 'WP_Filesystem' )->justReturn( true );
		$fs                       = new WPPO_Builder_FS_Mock();
		$fs->dirlist              = array(
			'style.css' => array( 'type' => 'f' ),
			'photo.jpg' => array( 'type' => 'f' ),
			'nested'    => array( 'type' => 'd' ),
		);
		$GLOBALS['wp_filesystem'] = $fs;
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => '/tmp/wordpress/wp-content/uploads',
				'baseurl' => 'http://example.com/wp-content/uploads',
			)
		);

		$watcher = new Builder_Purge_Watcher();
		$method  = new \ReflectionMethod( $watcher, 'purge_builder_directories' );

		$deleted = $method->invoke( $watcher, array( 'bricks' ), Builder_Purge_Watcher::get_builder_map() );

		$this->assertSame( array( '/tmp/wordpress/wp-content/uploads/bricks/style.css' ), $deleted );
		$this->assertSame( $deleted, $fs->deleted );
	}

	/**
	 * Builder-native hooks fire only when a listener is registered.
	 */
	public function test_builder_hooks_fire_only_with_listener(): void {
		$fired = array();
		Functions\when( 'has_action' )->alias(
			static function ( $hook ) {
				return 'elementor/core/files/clear_cache' === $hook;
			}
		);
		Functions\when( 'do_action' )->alias(
			static function ( $hook ) use ( &$fired ) {
				$fired[] = $hook;
			}
		);

		$watcher = new Builder_Purge_Watcher();
		$method  = new \ReflectionMethod( $watcher, 'fire_builder_regeneration_hooks' );
		$method->invoke( $watcher, array( 'elementor', 'divi' ), Builder_Purge_Watcher::get_builder_map() );

		$this->assertSame( array( 'elementor/core/files/clear_cache' ), $fired );
	}

	/**
	 * Full purge flow: WPPO derived caches, audit log, admin-notice
	 * transient, and the wppo_after_builder_purge action.
	 */
	public function test_full_purge_flow(): void {
		Functions\when( 'WP_Filesystem' )->justReturn( true );
		$fs                       = new WPPO_Builder_FS_Mock();
		$GLOBALS['wp_filesystem'] = $fs;
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => '/tmp/wordpress/wp-content/uploads',
				'baseurl' => 'http://example.com/wp-content/uploads',
			)
		);
		Functions\when( 'has_action' )->justReturn( false );

		$transients = array();
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value, $ttl = 0 ) use ( &$transients ) {
				$transients[ $key ] = array( $value, $ttl );
				return true;
			}
		);
		$actions = array();
		Functions\when( 'do_action' )->alias(
			static function ( $hook, $arg = null ) use ( &$actions ) {
				$actions[] = array( $hook, $arg );
			}
		);

		$watcher = new WPPO_Test_Builder_Watcher_Flow();
		$watcher->on_builder_update(
			null,
			array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => 'elementor/elementor.php',
			)
		);

		// WPPO derived-cache purge + audit log seams ran.
		$this->assertTrue( $watcher->wppo_purged );
		$this->assertSame( array( 'Elementor' ), $watcher->logged );

		// Admin-notice transient staged (multisite-safe key).
		$notice_key = Util::transient_key( Builder_Purge_Watcher::NOTICE_TRANSIENT );
		$this->assertArrayHasKey( $notice_key, $transients );
		$this->assertSame( array( 'Elementor' ), $transients[ $notice_key ][0]['builders'] );

		// Scoped builder directory removed.
		$this->assertContains( '/tmp/wordpress/wp-content/uploads/elementor/css', $fs->deleted );

		// After-purge action fired with the matched builder keys.
		$this->assertContains( array( 'wppo_after_builder_purge', array( 'elementor' ) ), $actions );
	}

	/**
	 * The purge-chain collaborator seams exist (rename guard).
	 *
	 * The full-flow test above stubs purge_wppo_derived_caches(), so this
	 * smoke test locks the real wiring targets: renaming
	 * Cache::clear_cache(), Used_CSS::delete_all_used_css(), or
	 * Critical_CSS::regenerate_all()/clear_all() fails here.
	 */
	public function test_purge_chain_wiring_exists(): void {
		$this->assertTrue( method_exists( Cache::class, 'clear_cache' ) );
		$this->assertTrue( method_exists( Used_CSS::class, 'delete_all_used_css' ) );
		$this->assertTrue( method_exists( Critical_CSS::class, 'regenerate_all' ) );
		$this->assertTrue( method_exists( Critical_CSS::class, 'clear_all' ) );
		$this->assertTrue( method_exists( Builder_Purge_Watcher::class, 'purge_wppo_derived_caches' ) );
	}

	/**
	 * A post-less drift signal defers the heavy purge instead of running it
	 * inline (audit #6).
	 *
	 * Isolated in a separate process so the eval-declared
	 * `as_has_scheduled_action()` stub cannot leak into later test classes
	 * (Patchwork cannot un-declare functions).
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_on_builder_drift_schedules_deferred_purge(): void {
		$this->reset_drift_static_flags();
		Functions\when( 'doing_action' )->justReturn( false );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );

		$enqueued = array();
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( $hook, $args = array(), $group = '' ) use ( &$enqueued ) {
				$enqueued[] = array( $hook, $args, $group );
				return 1;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );

		$watcher = new WPPO_Test_Builder_Watcher_Flow();
		$watcher->on_builder_drift();

		$this->assertFalse( $watcher->wppo_purged, 'Heavy purge must not run inside the originating request.' );
		$this->assertSame(
			array( array( Builder_Purge_Watcher::DRIFT_PURGE_HOOK, array(), 'performance_optimisation' ) ),
			$enqueued
		);
	}

	/**
	 * The deferred purge runs the heavy path only from the background callback.
	 */
	public function test_deferred_drift_purge_runs_heavy_path(): void {
		$watcher = new WPPO_Test_Builder_Watcher_Flow();
		$watcher->run_deferred_drift_purge();
		$this->assertTrue( $watcher->wppo_purged );
	}

	/**
	 * A pending Action Scheduler job is not enqueued twice.
	 *
	 * Isolated in a separate process so the eval-declared
	 * `as_has_scheduled_action()` stub does not leak into later test classes.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_on_builder_drift_respects_pending_action_scheduler_job(): void {
		$this->reset_drift_static_flags();
		Functions\when( 'doing_action' )->justReturn( false );
		Functions\when( 'as_has_scheduled_action' )->justReturn( true );

		$enqueued = 0;
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function () use ( &$enqueued ) {
				++$enqueued;
				return 1;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );

		$watcher = new WPPO_Test_Builder_Watcher_Flow();
		$watcher->on_builder_drift();

		$this->assertSame( 0, $enqueued, 'An already-pending job must not be enqueued again.' );
	}

	/**
	 * The transient lock throttles duplicate enqueues across requests.
	 *
	 * Isolated in a separate process so the eval-declared
	 * `as_has_scheduled_action()` stub does not leak into later test classes.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_on_builder_drift_lock_throttles_duplicate_enqueue(): void {
		$this->reset_drift_static_flags();
		Functions\when( 'doing_action' )->justReturn( false );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );

		$transients = array();
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$transients ) {
				return $transients[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$transients ) {
				$transients[ $key ] = $value;
				return true;
			}
		);
		$enqueued = 0;
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function () use ( &$enqueued ) {
				++$enqueued;
				return 1;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );

		$watcher = new WPPO_Test_Builder_Watcher_Flow();
		$watcher->on_builder_drift();
		// Simulate a second signal in a fresh request: the lock transient
		// survives, the per-request flag does not.
		$this->reset_drift_static_flags();
		$watcher->on_builder_drift();

		$this->assertSame( 1, $enqueued );
	}

	/**
	 * Without Action Scheduler the purge falls back to wp_schedule_single_event().
	 *
	 * Runs in a separate process because an earlier test in this class
	 * eval-declares `as_enqueue_async_action()` via Brain Monkey; Patchwork
	 * cannot un-declare it, so a same-process run would take the Action
	 * Scheduler branch instead of the WP-Cron fallback.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_on_builder_drift_falls_back_to_wp_cron(): void {
		$this->reset_drift_static_flags();
		Functions\when( 'doing_action' )->justReturn( false );

		$scheduled = array();
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function ( $timestamp, $hook ) use ( &$scheduled ) {
				$scheduled[] = array( $timestamp, $hook );
				return true;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );

		$watcher = new WPPO_Test_Builder_Watcher_Flow();
		$watcher->on_builder_drift();

		$this->assertCount( 1, $scheduled );
		$this->assertSame( Builder_Purge_Watcher::DRIFT_PURGE_HOOK, $scheduled[0][1] );
		$this->assertFalse( $watcher->wppo_purged, 'Heavy purge must remain deferred.' );
	}

	/**
	 * Watcher flags fail open when settings carry no keys (issue #1288).
	 */
	public function test_watcher_flags_fail_open_without_keys(): void {
		Util::set_settings_cache( array() );
		$this->assertTrue( Builder_Purge_Watcher::is_watcher_enabled() );
		$this->assertTrue( Builder_Purge_Watcher::is_drift_log_enabled() );
	}

	/**
	 * Watcher flags respect explicit stored values, including corrupted shapes.
	 */
	public function test_watcher_flags_respect_stored_values(): void {
		Util::set_settings_cache(
			array(
				'file_optimisation' => array(
					'builderPurgeWatcher'  => false,
					'builderPurgeDriftLog' => false,
				),
			)
		);
		$this->assertFalse( Builder_Purge_Watcher::is_watcher_enabled() );
		$this->assertFalse( Builder_Purge_Watcher::is_drift_log_enabled() );

		Util::set_settings_cache( array( 'file_optimisation' => 'corrupted' ) );
		$this->assertTrue( Builder_Purge_Watcher::is_watcher_enabled() );
		$this->assertTrue( Builder_Purge_Watcher::is_drift_log_enabled() );
	}

	/**
	 * Resolve_post_url_path() returns the path and rejects plain permalinks.
	 */
	public function test_resolve_post_url_path_edge_cases(): void {
		Functions\when( 'get_permalink' )->alias(
			static function ( $post_id ) {
				if ( 7 === (int) $post_id ) {
					return 'http://example.com/my-page/';
				}
				if ( 8 === (int) $post_id ) {
					return 'http://example.com/?p=8';
				}
				return '';
			}
		);

		$watcher = new Builder_Purge_Watcher();
		$method  = new \ReflectionMethod( $watcher, 'resolve_post_url_path' );

		Util::clear_permalink_cache();
		$this->assertSame( '/my-page/', $method->invoke( $watcher, 7 ) );
		Util::clear_permalink_cache();
		$this->assertSame( '', $method->invoke( $watcher, 8 ), 'Plain permalinks must not heal the homepage.' );
		Util::clear_permalink_cache();
		$this->assertSame( '', $method->invoke( $watcher, 9 ) );
		Util::clear_permalink_cache();
	}

	/**
	 * Purge_post_url_caches() reports false when both coupled stores fail.
	 */
	public function test_purge_post_url_caches_reports_coupled_failure(): void {
		Functions\when( 'get_permalink' )->justReturn( 'http://example.com/my-page/' );
		Util::clear_permalink_cache();

		$watcher = new Builder_Purge_Watcher();
		$method  = new \ReflectionMethod( $watcher, 'purge_post_url_caches' );

		// Cache::clear_cache() returns false with no filesystem; the coupled
		// seam must propagate that instead of reporting success.
		Functions\when( 'WP_Filesystem' )->justReturn( false );
		$this->assertFalse( $method->invoke( $watcher, 7 ) );
		Util::clear_permalink_cache();
	}

	/**
	 * A failed schedule must not latch the per-request dedupe flag (issue #1288).
	 *
	 * Previously the flag was set before the schedule result was known, so a
	 * transient failure (lock hit) suppressed the heal for the rest of the
	 * request with no retry.
	 */
	public function test_on_builder_drift_failed_schedule_remains_retryable(): void {
		$this->reset_drift_static_flags();
		Functions\when( 'doing_action' )->justReturn( false );
		Functions\when( 'do_action' )->justReturn( null );

		$locked   = true;
		$enqueued = 0;
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$locked ) {
				unset( $key );
				return $locked ? 1 : false;
			}
		);
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function () use ( &$enqueued ) {
				++$enqueued;
				return 1;
			}
		);

		$watcher = new WPPO_Test_Builder_Watcher_Flow();
		$watcher->on_builder_drift();
		$this->assertSame( 0, $enqueued, 'Locked schedule must not enqueue.' );

		// The lock clears later in the same request: the heal must retry.
		$locked = false;
		$watcher->on_builder_drift();
		$this->assertSame( 1, $enqueued, 'Failed schedule must leave the dedupe flag unlatched so a retry can heal.' );
	}

	/**
	 * Subdir plain permalinks must not purge the subdir homepage (issue #1288).
	 */
	public function test_resolve_post_url_path_rejects_subdir_plain_permalinks(): void {
		Functions\when( 'get_permalink' )->alias(
			static function ( $post_id ) {
				if ( 11 === (int) $post_id ) {
					return 'http://example.com/subdir/?p=11';
				}
				if ( 12 === (int) $post_id ) {
					return 'http://example.com/subdir/my-page/';
				}
				return '';
			}
		);

		$watcher = new Builder_Purge_Watcher();
		$method  = new \ReflectionMethod( $watcher, 'resolve_post_url_path' );

		Util::clear_permalink_cache();
		$this->assertSame( '', $method->invoke( $watcher, 11 ), 'Subdir plain permalink must be rejected, not mapped to the subdir homepage.' );
		Util::clear_permalink_cache();
		$this->assertSame( '/subdir/my-page/', $method->invoke( $watcher, 12 ) );
		Util::clear_permalink_cache();
	}

	/**
	 * Swap in an insert-recording $wpdb fake so Log::add() can run.
	 *
	 * @return object The fake (recorded inserts in $fake->inserts, previous $wpdb in $fake->prev).
	 */
	private function swap_in_wpdb_recorder() {
		global $wpdb;
		$recorder       = new class() {
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Recorded insert() calls.
			 *
			 * @var array
			 */
			public $inserts = array();

			/**
			 * Previous global $wpdb (restored after the test).
			 *
			 * @var mixed
			 */
			public $prev = null;

			/**
			 * Record an insert.
			 *
			 * @param string $table  Table name.
			 * @param array  $data   Data.
			 * @param array  $format Format.
			 * @return int
			 */
			public function insert( $table, $data, $format = array() ) {
				$this->inserts[] = array( $table, $data, $format );
				return 1;
			}
		};
		$previous       = $wpdb ?? null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wpdb           = $recorder; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$recorder->prev = $previous;
		return $recorder;
	}

	/**
	 * Restore the global $wpdb saved by swap_in_wpdb_recorder().
	 *
	 * @param object $recorder The fake holding the previous instance.
	 * @return void
	 */
	private function restore_wpdb( $recorder ): void {
		global $wpdb;
		$wpdb = $recorder->prev; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Drift-purge audit entries respect the drift-log setting (issue #1288).
	 */
	public function test_write_drift_purge_log_gated_by_setting(): void {
		Functions\when( 'wp_kses_post' )->returnArg();
		$recorder = $this->swap_in_wpdb_recorder();

		Util::set_settings_cache(
			array(
				'file_optimisation' => array(
					'builderPurgeDriftLog' => false,
				),
			)
		);
		$watcher = new Builder_Purge_Watcher();
		$method  = new \ReflectionMethod( $watcher, 'write_drift_purge_log' );
		$method->invoke( $watcher, true );
		$this->assertCount( 0, $recorder->inserts, 'Drift-log=false must skip Log::add().' );

		Util::set_settings_cache(
			array(
				'file_optimisation' => array(
					'builderPurgeDriftLog' => true,
				),
			)
		);
		$method->invoke( $watcher, true );
		$this->assertCount( 1, $recorder->inserts, 'Drift-log=true must write the purge audit entry.' );

		$this->restore_wpdb( $recorder );
	}

	/**
	 * Save-log covers purged+queued, purged-only, and queued-only branches.
	 */
	public function test_write_drift_save_log_branches(): void {
		Functions\when( 'wp_kses_post' )->returnArg();
		$recorder = $this->swap_in_wpdb_recorder();

		Util::set_settings_cache(
			array(
				'file_optimisation' => array(
					'builderPurgeDriftLog' => true,
				),
			)
		);
		$watcher = new Builder_Purge_Watcher();
		$method  = new \ReflectionMethod( $watcher, 'write_drift_save_log' );

		$method->invoke( $watcher, 7, true, true );
		$method->invoke( $watcher, 8, true, false );
		$method->invoke( $watcher, 9, false, true );
		$method->invoke( $watcher, 10, false, false );

		$this->assertCount( 3, $recorder->inserts, 'purged=false+queued=false must write nothing.' );
		$messages = array_column( array_column( $recorder->inserts, 1 ), 'activity' );
		$this->assertStringContainsString( 'regeneration requeued', $messages[0] );
		$this->assertStringContainsString( 'purged for the affected URL', $messages[0] );
		$this->assertStringContainsString( 'purged for the affected URL', $messages[1] );
		$this->assertStringNotContainsString( 'requeued', $messages[1] );
		$this->assertStringContainsString( 'regeneration requeued', $messages[2] );
		$this->assertStringNotContainsString( 'purged', $messages[2] );

		$this->restore_wpdb( $recorder );
	}

	/**
	 * Purge+requeue success writes the log, stages the notice, and fires the action.
	 *
	 * Runs in a separate process because the `as_has_scheduled_action()`
	 * stub would otherwise be eval-declared in the main process and break
	 * later test classes that rely on it being undefined (function_exists
	 * guard), e.g. CronWebVitalsRescanTest.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_on_builder_drift_save_success_path(): void {
		Functions\when( 'wp_kses_post' )->returnArg();
		$recorder = $this->swap_in_wpdb_recorder();

		Util::set_settings_cache(
			array(
				'file_optimisation' => array(
					'builderPurgeWatcher'  => true,
					'builderPurgeDriftLog' => true,
					'removeUnusedCSS'      => true,
				),
			)
		);
		Functions\when( 'as_has_scheduled_action' )->justReturn( true );
		// Declared so Used_CSS::requeue_for_post()'s function_exists guard passes.
		Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );

		$transients = array();
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$transients ) {
				$transients[ $key ] = $value;
				return true;
			}
		);
		$fired = array();
		Functions\when( 'do_action' )->alias(
			static function ( $tag, ...$args ) use ( &$fired ) {
				$fired[] = array( $tag, $args );
			}
		);

		$watcher         = new WPPO_Test_Builder_Watcher_Save();
		$watcher->purged = true;
		$watcher->on_builder_drift_save( 7, array() );

		$this->assertCount( 1, $recorder->inserts, 'Successful drift save must write the audit entry.' );

		$notice_found = false;
		foreach ( $transients as $key => $value ) {
			if ( false !== strpos( (string) $key, Builder_Purge_Watcher::NOTICE_TRANSIENT ) ) {
				$notice_found = true;
				break;
			}
		}
		$this->assertTrue( $notice_found, 'Purged drift save must stage the one-time admin notice.' );

		$action_found = false;
		foreach ( $fired as $entry ) {
			if ( 'wppo_builder_drift_requeue' === $entry[0] && array( 7 ) === $entry[1] ) {
				$action_found = true;
				break;
			}
		}
		$this->assertTrue( $action_found, 'Queued drift save must fire wppo_builder_drift_requeue with the post ID.' );

		$this->restore_wpdb( $recorder );
	}

	/**
	 * Purged-but-not-queued saves log + notice without the requeue action.
	 */
	public function test_on_builder_drift_save_purged_only_skips_action(): void {
		Functions\when( 'wp_kses_post' )->returnArg();
		$recorder = $this->swap_in_wpdb_recorder();

		// removeUnusedCSS off: requeue_for_post() returns false (not queued).
		Util::set_settings_cache(
			array(
				'file_optimisation' => array(
					'builderPurgeWatcher'  => true,
					'builderPurgeDriftLog' => true,
				),
			)
		);

		$fired = array();
		Functions\when( 'do_action' )->alias(
			static function ( $tag, ...$args ) use ( &$fired ) {
				$fired[] = array( $tag, $args );
			}
		);

		$watcher         = new WPPO_Test_Builder_Watcher_Save();
		$watcher->purged = true;
		$watcher->on_builder_drift_save( 7, array() );

		$this->assertCount( 1, $recorder->inserts, 'Purged-only drift save must still write the audit entry.' );
		foreach ( $fired as $entry ) {
			$this->assertNotSame( 'wppo_builder_drift_requeue', $entry[0], 'Unqueued drift save must not fire the requeue action.' );
		}

		$this->restore_wpdb( $recorder );
	}

	/**
	 * Deferred purge writes a distinct entry when the page cache was not cleared.
	 */
	public function test_deferred_drift_purge_logs_failed_attempt(): void {
		Functions\when( 'wp_kses_post' )->returnArg();
		$recorder = $this->swap_in_wpdb_recorder();

		Util::set_settings_cache(
			array(
				'file_optimisation' => array(
					'builderPurgeDriftLog' => true,
				),
			)
		);

		$watcher = new WPPO_Test_Builder_Watcher_Failed_Purge();
		$watcher->run_deferred_drift_purge();

		$this->assertCount( 1, $recorder->inserts, 'Failed deferred purge must still write an audit entry.' );
		$activity = $recorder->inserts[0][1]['activity'] ?? '';
		$this->assertStringContainsString( 'not cleared', (string) $activity );

		$this->restore_wpdb( $recorder );
	}

	/**
	 * Invoke Main::maybe_migrate_builder_watcher() on an instance with seeded options.
	 *
	 * @param array $stored  Persisted wppo_settings row (false = no stored row).
	 * @param array $options In-memory options seed.
	 * @param bool  $updated update_option() return value.
	 * @return array{main: object, writes: array} Instance plus captured writes.
	 */
	private function run_builder_watcher_migration( $stored, array $options = array(), bool $updated = true ): array {
		$writes = array();
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default_value = false ) use ( $stored ) {
				if ( 'wppo_settings' === $key ) {
					return $stored;
				}
				return $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$writes, $updated ) {
				$writes[] = array( $key, $value );
				return $updated;
			}
		);

		$main         = ( new \ReflectionClass( \PerformanceOptimise\Inc\Main::class ) )->newInstanceWithoutConstructor();
		$options_prop = new \ReflectionProperty( \PerformanceOptimise\Inc\Main::class, 'options' );
		$options_prop->setValue( $main, $options );
		$main->maybe_migrate_builder_watcher();

		return array( $main, $writes );
	}

	/**
	 * Migration is idempotent when both keys already exist.
	 */
	public function test_builder_watcher_migration_idempotent(): void {
		$stored           = array(
			'file_optimisation' => array(
				'builderPurgeWatcher'  => false,
				'builderPurgeDriftLog' => false,
			),
		);
		list( , $writes ) = $this->run_builder_watcher_migration( $stored );

		$settings_writes = array_values(
			array_filter(
				$writes,
				static function ( $entry ) {
					return 'wppo_settings' === $entry[0];
				}
			)
		);
		$this->assertSame( array(), $settings_writes, 'Idempotent migration must not rewrite wppo_settings.' );
	}

	/**
	 * Migration heals a single-key row and preserves the explicit value.
	 */
	public function test_builder_watcher_migration_heals_single_key(): void {
		$stored                = array(
			'file_optimisation' => array(
				'builderPurgeWatcher' => false,
			),
		);
		list( $main, $writes ) = $this->run_builder_watcher_migration( $stored );

		$settings_write = null;
		foreach ( $writes as $entry ) {
			if ( 'wppo_settings' === $entry[0] ) {
				$settings_write = $entry[1];
			}
		}
		$this->assertNotNull( $settings_write, 'Single-key row must be healed with a wppo_settings write.' );
		$this->assertFalse( $settings_write['file_optimisation']['builderPurgeWatcher'], 'Explicit false must be preserved.' );
		$this->assertTrue( $settings_write['file_optimisation']['builderPurgeDriftLog'], 'Missing key must backfill to true.' );

		$options_prop = new \ReflectionProperty( \PerformanceOptimise\Inc\Main::class, 'options' );
		$options      = $options_prop->getValue( $main );
		$this->assertTrue( $options['file_optimisation']['builderPurgeDriftLog'] );
	}

	/**
	 * Migration skips fresh installs with no stored row.
	 */
	public function test_builder_watcher_migration_skips_without_stored_row(): void {
		list( , $writes ) = $this->run_builder_watcher_migration( false );

		$settings_writes = array_values(
			array_filter(
				$writes,
				static function ( $entry ) {
					return 'wppo_settings' === $entry[0];
				}
			)
		);
		$this->assertSame( array(), $settings_writes, 'Fresh installs must not get a partial wppo_settings write.' );
	}

	/**
	 * A failed DB write must not poison the in-request memo.
	 */
	public function test_builder_watcher_migration_failed_write_skips_memo(): void {
		$stored                = array(
			'file_optimisation' => array(),
		);
		list( $main, $writes ) = $this->run_builder_watcher_migration( $stored, array(), false );

		$this->assertNotEmpty( $writes, 'Migration must attempt the write.' );
		$options_prop = new \ReflectionProperty( \PerformanceOptimise\Inc\Main::class, 'options' );
		$options      = $options_prop->getValue( $main );
		$file         = isset( $options['file_optimisation'] ) && is_array( $options['file_optimisation'] ) ? $options['file_optimisation'] : array();
		$this->assertArrayNotHasKey( 'builderPurgeWatcher', $file, 'Failed write must not merge into the in-request memo.' );
	}

	/**
	 * Reset the watcher's static re-entrancy flags between assertions.
	 *
	 * @return void
	 */
	private function reset_drift_static_flags(): void {
		foreach ( array( 'drift_suspended', 'drift_handled_this_request' ) as $name ) {
			$property = new \ReflectionProperty( Builder_Purge_Watcher::class, $name );
			$property->setValue( null, false );
		}
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Test-file watcher doubles.

/**
 * Watcher double that records routing instead of purging.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Test_Builder_Watcher extends Builder_Purge_Watcher {
	/**
	 * Matched builder keys passed to purge_for_builders(), null when no purge ran.
	 *
	 * @var array|null
	 */
	public $purged = null;

	/**
	 * Record the purge routing.
	 *
	 * @param string[] $matched Matched builder keys.
	 * @param array    $map     Builder map (unused).
	 * @return void
	 */
	protected function purge_for_builders( array $matched, array $map ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature must match the parent.
		unset( $map );
		$this->purged = $matched;
	}
}

/**
 * Watcher double that runs the real flow except the WPPO derived-cache
 * purge and the audit log (both touch static collaborators).
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Test_Builder_Watcher_Flow extends Builder_Purge_Watcher {
	/**
	 * Whether purge_wppo_derived_caches() ran.
	 *
	 * @var bool
	 */
	public $wppo_purged = false;

	/**
	 * Labels passed to write_purge_log().
	 *
	 * @var array|null
	 */
	public $logged = null;

	/**
	 * Record the derived-cache purge.
	 *
	 * @return bool
	 */
	protected function purge_wppo_derived_caches(): bool {
		$this->wppo_purged = true;
		return true;
	}

	/**
	 * Record the audit log entry.
	 *
	 * @param string[] $labels Human-readable builder names.
	 * @return void
	 */
	protected function write_purge_log( array $labels ): void {
		$this->logged = $labels;
	}
}

/**
 * Watcher double with a scripted URL-scoped purge result for drift-save tests.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Test_Builder_Watcher_Save extends Builder_Purge_Watcher {
	/**
	 * Scripted purge_post_url_caches() result.
	 *
	 * @var bool
	 */
	public $purged = false;

	/**
	 * Return the scripted purge result.
	 *
	 * @param int $post_id Post ID (unused).
	 * @return bool
	 */
	protected function purge_post_url_caches( int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return $this->purged;
	}
}

/**
 * Watcher double whose derived-cache purge always fails.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Test_Builder_Watcher_Failed_Purge extends Builder_Purge_Watcher {
	/**
	 * Report a failed purge.
	 *
	 * @return bool
	 */
	protected function purge_wppo_derived_caches(): bool {
		return false;
	}
}

/**
 * Filesystem double recording builder-directory deletes.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Builder_FS_Mock {
	/**
	 * Deleted directory paths.
	 *
	 * @var string[]
	 */
	public $deleted = array();

	/**
	 * Simulate directory check (everything exists).
	 *
	 * @param string $path Path (unused).
	 * @return bool
	 */
	public function is_dir( $path ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return true;
	}

	/**
	 * Directory listing returned by dirlist().
	 *
	 * @var array
	 */
	public $dirlist = array();

	/**
	 * Simulate a directory listing.
	 *
	 * @param string $path Path (unused).
	 * @return array
	 */
	public function dirlist( $path ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return $this->dirlist;
	}

	/**
	 * Record a delete call.
	 *
	 * @param string $path      Path deleted.
	 * @param bool   $recursive Recursive (unused).
	 * @param string $type      Type (unused).
	 * @return bool
	 */
	public function delete( $path, $recursive = false, $type = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->deleted[] = $path;
		return true;
	}

	/**
	 * Simulate file existence.
	 *
	 * @param string $path File path (unused).
	 * @return bool
	 */
	public function exists( $path ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return true;
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
