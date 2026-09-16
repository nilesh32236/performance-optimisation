<?php
/**
 * Tests for the generic upgrade auto-purge (issue #1276).
 *
 * Covers on_any_upgrade() routing (generic plugin/theme/core purges,
 * builder updates skipped via the priority-10 handler, non-update payloads
 * ignored), the per-request dedupe, the SPA-visible last-purge record, and
 * the safe-mode preview URL (?wppo_nocache=1 bypass).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Builder_Purge_Watcher;
use Brain\Monkey\Functions;

/**
 * Tests for the generic upgrade auto-purge.
 *
 * @package PerformanceOptimise\Tests
 */
class UpgradePurgeWatcherTest extends \PHPUnit\Framework\TestCase {
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
		$this->reset_upgrade_flag();
	}

	/**
	 * Tear down Brain Monkey.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$this->reset_upgrade_flag();
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Reset the per-request upgrade dedupe flag.
	 */
	private function reset_upgrade_flag(): void {
		$property = new \ReflectionProperty( Builder_Purge_Watcher::class, 'upgrade_purged_this_request' );
		$property->setAccessible( true );
		$property->setValue( null, false );
	}

	/**
	 * Both upgrader callbacks are registered (builder at 10, generic at 20).
	 */
	public function test_register_hooks_both_upgrader_callbacks(): void {
		$calls = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$calls ) {
				$calls[] = array( $hook, $callback, $priority, $args );
			}
		);

		( new Builder_Purge_Watcher() )->register();

		$builder = false;
		$generic = false;
		foreach ( $calls as $call ) {
			if ( 'upgrader_process_complete' === $call[0] && 2 === $call[3] && is_array( $call[1] ) ) {
				if ( 'on_builder_update' === $call[1][1] && 10 === $call[2] ) {
					$builder = true;
				}
				if ( 'on_any_upgrade' === $call[1][1] && 20 === $call[2] ) {
					$generic = true;
				}
			}
		}
		$this->assertTrue( $builder, 'Expected on_builder_update at priority 10.' );
		$this->assertTrue( $generic, 'Expected on_any_upgrade at priority 20.' );
	}

	/**
	 * A generic plugin update purges derived caches and records the reason.
	 */
	public function test_generic_plugin_update_purges_and_records(): void {
		$options = array();
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$options ) {
				$options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );

		$watcher = new WPPO_Test_Upgrade_Watcher();
		$watcher->on_any_upgrade(
			null,
			array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => 'akismet/akismet.php',
			)
		);

		$this->assertTrue( $watcher->wppo_purged );
		$this->assertTrue( $watcher->bumped );
		$this->assertArrayHasKey( Builder_Purge_Watcher::LAST_PURGE_OPTION, $options );
		$this->assertStringContainsString( 'akismet', $options[ Builder_Purge_Watcher::LAST_PURGE_OPTION ]['reason'] );
	}

	/**
	 * Core updates purge with a generic reason.
	 */
	public function test_core_update_purges(): void {
		Functions\when( 'do_action' )->justReturn( null );
		$watcher = new WPPO_Test_Upgrade_Watcher();
		$watcher->on_any_upgrade(
			null,
			array(
				'action' => 'update',
				'type'   => 'core',
			)
		);
		$this->assertTrue( $watcher->wppo_purged );
	}

	/**
	 * Builder updates are skipped by the generic handler (already purged).
	 */
	public function test_builder_update_skipped_by_generic_handler(): void {
		$watcher = new WPPO_Test_Upgrade_Watcher();
		$watcher->on_any_upgrade(
			null,
			array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => 'elementor/elementor.php',
			)
		);
		$this->assertFalse( $watcher->wppo_purged );
	}

	/**
	 * Non-update actions and non-array payloads are ignored.
	 */
	public function test_non_update_payloads_ignored(): void {
		$watcher = new WPPO_Test_Upgrade_Watcher();
		$watcher->on_any_upgrade(
			null,
			array(
				'action' => 'install',
				'type'   => 'plugin',
				'plugin' => 'akismet/akismet.php',
			)
		);
		$this->assertFalse( $watcher->wppo_purged );

		$watcher->on_any_upgrade( null, 'not-an-array' );
		$this->assertFalse( $watcher->wppo_purged );
	}

	/**
	 * The manual purge entry point purges, bumps, and records.
	 */
	public function test_manual_purge_derived_caches(): void {
		$options = array();
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$options ) {
				$options[ $key ] = $value;
				return true;
			}
		);
		$watcher = new WPPO_Test_Upgrade_Watcher();
		$watcher->purge_derived_caches( 'manual purge' );
		$this->assertTrue( $watcher->wppo_purged );
		$this->assertTrue( $watcher->bumped );
		$this->assertSame( 'manual purge', $options[ Builder_Purge_Watcher::LAST_PURGE_OPTION ]['reason'] );
	}

	/**
	 * get_last_purge() fails open to an empty record on malformed data.
	 */
	public function test_get_last_purge_fail_open(): void {
		Functions\when( 'get_option' )->justReturn( 'not-an-array' );
		$this->assertSame(
			array(
				'reason' => '',
				'time'   => 0,
			),
			Builder_Purge_Watcher::get_last_purge()
		);
	}

	/**
	 * The safe preview URL carries the nocache bypass that skips minify.
	 */
	public function test_safe_preview_url_bypasses_minify(): void {
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.com' . (string) $path;
			}
		);
		$url = Builder_Purge_Watcher::get_safe_preview_url();
		$this->assertStringContainsString( 'wppo_nocache=1', $url );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Test-file watcher double.

/**
 * Watcher double recording the generic purge path.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Test_Upgrade_Watcher extends Builder_Purge_Watcher {
	/**
	 * Whether purge_wppo_derived_caches() ran.
	 *
	 * @var bool
	 */
	public $wppo_purged = false;

	/**
	 * Whether bump_combined_asset_versions() ran.
	 *
	 * @var bool
	 */
	public $bumped = false;

	/**
	 * Record the derived-cache purge.
	 *
	 * @return void
	 */
	protected function purge_wppo_derived_caches(): void {
		$this->wppo_purged = true;
	}

	/**
	 * Record the version bump.
	 *
	 * @return void
	 */
	protected function bump_combined_asset_versions(): void {
		$this->bumped = true;
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
