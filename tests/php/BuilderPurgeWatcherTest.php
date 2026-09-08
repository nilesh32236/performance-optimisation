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
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

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
		\PerformanceOptimise\Inc\Util::reset_cached_home_urls();
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		\PerformanceOptimise\Inc\Util::clear_permalink_cache();
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
	 * The watcher registers on upgrader_process_complete with two args.
	 */
	public function test_register_hooks_upgrader_action(): void {
		$calls = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$calls ) {
				$calls[] = array( $hook, $callback, $priority, $args );
			}
		);

		( new Builder_Purge_Watcher() )->register();

		$found = false;
		foreach ( $calls as $call ) {
			if ( 'upgrader_process_complete' === $call[0] && 10 === $call[2] && 2 === $call[3] ) {
				$found = true;
			}
		}
		$this->assertTrue( $found, 'Expected upgrader_process_complete registration with 2 args.' );
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
		$method->setAccessible( true );

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
		$method->setAccessible( true );

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
		$method->setAccessible( true );

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
		$method->setAccessible( true );

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
		$method->setAccessible( true );
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
	 * @return void
	 */
	protected function purge_wppo_derived_caches(): void {
		$this->wppo_purged = true;
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
