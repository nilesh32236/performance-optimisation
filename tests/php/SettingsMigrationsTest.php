<?php
/**
 * Regression tests for Settings_Migrations (ARCH-004).
 *
 * Pins the pre/post migration behavior of every routine relocated verbatim
 * from `Main::maybe_migrate_*()` into `Settings_Migrations::migrate_*()`:
 * absent keys backfill to defaults (persisted + memo-synced), explicit
 * values are preserved verbatim, fresh installs (no stored row) perform
 * zero writes, and re-runs are no-ops. Also covers the multisite same-blog
 * isolation, the capability-gated routines, and the `Main` facade proxies
 * (hook-callback identity unchanged for `Hook_Registry`).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Settings_Migrations;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Behavioral coverage for the settings-migration cluster (issue #1534).
 *
 * @package PerformanceOptimise\Tests
 */
class SettingsMigrationsTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Original global $wpdb before it is swapped for the test fake.
	 *
	 * @var object
	 */
	private $original_wpdb;

	/**
	 * Set up BrainMonkey and swap in a fake $wpdb so Log::add() can run.
	 */
	protected function setUp(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		parent::setUp();
		// Re-register the shared stubs: this setUp() shadows the trait
		// method, and Brain Monkey eval-declared functions persist per
		// process (see PreloadAutoMigrationTest).
		$this->register_common_function_stubs();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		global $wpdb;
		$this->original_wpdb = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wpdb                = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Record an insert into the activity log table.
			 *
			 * @param string $table  Table name.
			 * @param array  $data   Data to insert.
			 * @param array  $format Format array.
			 * @return int
			 */
			public function insert( $table, $data, $format = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
				return 1;
			}
		};
	}

	/**
	 * Restore the original $wpdb and tear down BrainMonkey.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		global $wpdb;
		$wpdb = $this->original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tearDown();
	}

	/**
	 * Build a migration runner bound to a Main with a seeded options memo.
	 *
	 * @param array $memo In-memory options memo to seed on the Main instance.
	 * @return array Tuple of (Settings_Migrations runner, Main instance).
	 */
	private function make_runner( array $memo = array() ): array {
		$main = ( new ReflectionClass( Main::class ) )->newInstanceWithoutConstructor();

		$options_prop = new ReflectionProperty( Main::class, 'options' );
		$options_prop->setValue( $main, $memo );

		return array( new Settings_Migrations( $main ), $main );
	}

	/**
	 * Read the seeded options memo back off a Main instance.
	 *
	 * @param Main $main Main instance.
	 * @return mixed Memo value.
	 */
	private function read_memo( Main $main ) {
		$options_prop = new ReflectionProperty( Main::class, 'options' );
		return $options_prop->getValue( $main );
	}

	/**
	 * Stub the settings option with write-through so re-runs observe writes.
	 *
	 * @param mixed $stored        Stored wppo_settings value (or false for no row).
	 * @param array $writes        Out-param collecting [key, value] writes.
	 * @param bool  $update_return update_option() return value.
	 * @return void
	 */
	private function stub_store( &$stored, &$writes, bool $update_return = true ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default_value = false ) use ( &$stored ) {
				return 'wppo_settings' === $key ? $stored : $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$stored, &$writes, $update_return ) {
				$writes[] = array( $key, $value );
				if ( 'wppo_settings' === $key && $update_return ) {
					$stored = $value;
				}
				return $update_return;
			}
		);
		Functions\when( 'wp_kses_post' )->returnArg();
	}

	/**
	 * Filter collected writes down to wppo_settings persists.
	 *
	 * Log::add() may bump its own salt/cache-version option; only
	 * wppo_settings writes count as migration writes.
	 *
	 * @param array $writes Collected [key, value] writes.
	 * @return array wppo_settings writes only.
	 */
	private function settings_writes( array $writes ): array {
		return array_values(
			array_filter(
				$writes,
				static function ( $write ) {
					return 'wppo_settings' === $write[0];
				}
			)
		);
	}

	/**
	 * Generic routine matrix: method, stored group, defaulted keys,
	 * explicit (non-default) values proving verbatim preservation, a
	 * sibling key proving non-target keys survive, and whether the
	 * routine is capability-gated (needs an admin stub).
	 *
	 * @return array<string, array{string, string, array, array, array, bool}>
	 */
	public static function routine_provider(): array {
		return array(
			'ccss max size'              => array(
				'migrate_ccss_max_size',
				'file_optimisation',
				array( 'ccssMaxSize' => 20480 ),
				array( 'ccssMaxSize' => 1024 ),
				array( 'minifyJS' => true ),
				false,
			),
			'ccss safelist'              => array(
				'migrate_ccss_safelist',
				'file_optimisation',
				array( 'ccssSafelistExtra' => '' ),
				array( 'ccssSafelistExtra' => '.keep-me' ),
				array( 'minifyJS' => true ),
				false,
			),
			'css queue defaults'         => array(
				'migrate_css_queue_defaults',
				'file_optimisation',
				array(
					'ccssQueueCap'         => 5,
					'usedCssQueueCap'      => 50,
					'ccssViewportVariants' => false,
					'usedCSSDeliveryMode'  => 'file',
					'ccssGenTimeout'       => 25,
					'ccssInlineBudgetKb'   => 14,
					'ccssCommerceExclude'  => true,
					'ccssChecksumRegen'    => true,
				),
				array(
					'ccssQueueCap'         => 9,
					'usedCssQueueCap'      => 7,
					'ccssViewportVariants' => true,
					'usedCSSDeliveryMode'  => 'inline',
					'ccssGenTimeout'       => 60,
					'ccssInlineBudgetKb'   => 20,
					'ccssCommerceExclude'  => false,
					'ccssChecksumRegen'    => false,
				),
				array( 'minifyJS' => true ),
				false,
			),
			'speculation top urls'       => array(
				'migrate_speculation_top_urls',
				'preload_settings',
				array( 'speculationTopUrlsLimit' => 2 ),
				array( 'speculationTopUrlsLimit' => 5 ),
				array( 'enablePreloadCache' => true ),
				false,
			),
			'speculation prerender list' => array(
				'migrate_speculation_prerender_list',
				'preload_settings',
				array( 'speculationPrerenderList' => false ),
				array( 'speculationPrerenderList' => true ),
				array( 'enablePreloadCache' => true ),
				true,
			),
			'rum sample rate'            => array(
				'migrate_rum_sample_rate',
				'performance_audit',
				array( 'rum_sample_rate' => 100 ),
				array( 'rum_sample_rate' => 10 ),
				array( 'autoRescan' => 'weekly' ),
				false,
			),
			'image alt edge defaults'    => array(
				'migrate_image_alt_edge_defaults',
				'image_optimisation',
				array(
					'autoAltText'      => false,
					'maxLongestEdgePx' => 2560,
				),
				array(
					'autoAltText'      => true,
					'maxLongestEdgePx' => 1024,
				),
				array( 'lazyLoadImages' => true ),
				false,
			),
			'safe mode'                  => array(
				'migrate_safe_mode',
				'file_optimisation',
				array( 'safeMode' => false ),
				array( 'safeMode' => true ),
				array( 'minifyJS' => true ),
				false,
			),
			'elementor safe mode'        => array(
				'migrate_elementor_safe_mode',
				'file_optimisation',
				array( 'elementorSafeMode' => true ),
				array( 'elementorSafeMode' => false ),
				array( 'minifyJS' => true ),
				false,
			),
			'preload auto defaults'      => array(
				'migrate_preload_auto_defaults',
				'preload_settings',
				array(
					'autoLcpPreload'    => false,
					'autoDiscoverFonts' => false,
				),
				array(
					'autoLcpPreload'    => true,
					'autoDiscoverFonts' => true,
				),
				array( 'enablePreloadCache' => true ),
				false,
			),
			'object cache outage flag'   => array(
				'migrate_object_cache_outage_flag',
				'object_cache',
				array( 'outage_bypassed' => false ),
				array( 'outage_bypassed' => true ),
				array( 'host' => '127.0.0.1' ),
				false,
			),
			'ai speculation autotune'    => array(
				'migrate_ai_speculation_autotune',
				'ai_adaptive',
				array(
					'speculation_autotune_enabled' => false,
					'speculation_min_samples'      => 20,
					'speculation_max_urls'         => 5,
				),
				array(
					'speculation_autotune_enabled' => true,
					'speculation_min_samples'      => 50,
					'speculation_max_urls'         => 9,
				),
				array( 'enabled' => true ),
				false,
			),
			'ai anomaly v2'              => array(
				'migrate_ai_anomaly_v2',
				'ai_adaptive',
				array(
					'anomaly_band_window'   => 10,
					'anomaly_recovery_days' => 3,
					'deploy_notes'          => array(),
				),
				array(
					'anomaly_band_window'   => 15,
					'anomaly_recovery_days' => 7,
					'deploy_notes'          => array( 'note' ),
				),
				array( 'enabled' => true ),
				false,
			),
			'comment image hardening'    => array(
				'migrate_comment_image_hardening',
				'image_optimisation',
				array( 'hardenCommentImages' => true ),
				array( 'hardenCommentImages' => false ),
				array( 'lazyLoadImages' => true ),
				true,
			),
			'builder watcher'            => array(
				'migrate_builder_watcher',
				'file_optimisation',
				array(
					'builderPurgeWatcher'  => true,
					'builderPurgeDriftLog' => true,
				),
				array(
					'builderPurgeWatcher'  => false,
					'builderPurgeDriftLog' => false,
				),
				array( 'minifyJS' => true ),
				false,
			),
			'third party auto'           => array(
				'migrate_third_party_auto',
				'file_optimisation',
				array(
					'delayJSThirdPartyAuto' => false,
					'delayJSPreset'         => 'safe',
				),
				array(
					'delayJSThirdPartyAuto' => true,
					'delayJSPreset'         => 'aggressive',
				),
				array( 'minifyJS' => true ),
				true,
			),
		);
	}

	/**
	 * Absent keys backfill to defaults: persisted, siblings preserved,
	 * and the in-memory memo synced for the current request.
	 *
	 * @param string $method      Migration method under test.
	 * @param string $group       Stored settings group.
	 * @param array  $defaults    Expected backfilled defaults.
	 * @param array  $explicit    Explicit values proving preservation.
	 * @param array  $sibling     Untouched sibling keys.
	 * @param bool   $needs_admin Whether the routine is capability-gated.*/
	#[DataProvider( 'routine_provider' )]
	public function test_absent_keys_backfill_to_defaults( string $method, string $group, array $defaults, array $explicit, array $sibling, bool $needs_admin ): void {
		unset( $explicit );
		if ( $needs_admin ) {
			Functions\when( 'current_user_can' )->justReturn( true );
		}
		if ( 'migrate_comment_image_hardening' === $method ) {
			Functions\when( 'do_action' )->justReturn( null );
		}

		$stored = array( $group => $sibling );
		$writes = array();
		$this->stub_store( $stored, $writes );

		list( $runner, $main ) = $this->make_runner( array() );
		$runner->$method();

		$persisted = $this->settings_writes( $writes );
		$this->assertCount( 1, $persisted, $method . ': absent keys must persist exactly one wppo_settings write' );
		foreach ( $defaults as $key => $default ) {
			$this->assertSame( $default, $persisted[0][1][ $group ][ $key ], $method . ": persisted {$key} must equal the default" );
		}
		foreach ( $sibling as $key => $value ) {
			$this->assertSame( $value, $persisted[0][1][ $group ][ $key ], $method . ": sibling {$key} must survive the backfill" );
		}

		$memo = $this->read_memo( $main );
		foreach ( $defaults as $key => $default ) {
			$this->assertSame( $default, $memo[ $group ][ $key ], $method . ": memo {$key} must sync for the current request" );
		}
	}

	/**
	 * Explicit stored values (even non-defaults) are preserved verbatim
	 * with zero writes.
	 *
	 * @param string $method      Migration method under test.
	 * @param string $group       Stored settings group.
	 * @param array  $defaults    Expected backfilled defaults.
	 * @param array  $explicit    Explicit values proving preservation.
	 * @param array  $sibling     Untouched sibling keys.
	 * @param bool   $needs_admin Whether the routine is capability-gated.*/
	#[DataProvider( 'routine_provider' )]
	public function test_explicit_values_are_preserved( string $method, string $group, array $defaults, array $explicit, array $sibling, bool $needs_admin ): void {
		unset( $defaults );
		if ( $needs_admin ) {
			Functions\when( 'current_user_can' )->justReturn( true );
		}
		if ( 'migrate_comment_image_hardening' === $method ) {
			Functions\when( 'do_action' )->justReturn( null );
		}

		$stored = array( $group => $explicit + $sibling );
		$writes = array();
		$this->stub_store( $stored, $writes );

		list( $runner, $_main ) = $this->make_runner( array( $group => $explicit ) );
		$runner->$method();

		$this->assertSame( array(), $this->settings_writes( $writes ), $method . ': explicit values must trigger zero writes' );
	}

	/**
	 * Fresh installs (no stored row) are skipped with zero writes.
	 *
	 * @param string $method      Migration method under test.
	 * @param string $group       Stored settings group.
	 * @param array  $defaults    Expected backfilled defaults.
	 * @param array  $explicit    Explicit values proving preservation.
	 * @param array  $sibling     Untouched sibling keys.
	 * @param bool   $needs_admin Whether the routine is capability-gated.*/
	#[DataProvider( 'routine_provider' )]
	public function test_fresh_install_writes_nothing( string $method, string $group, array $defaults, array $explicit, array $sibling, bool $needs_admin ): void {
		unset( $group, $defaults, $explicit, $sibling );
		if ( $needs_admin ) {
			Functions\when( 'current_user_can' )->justReturn( true );
		}

		$stored = false;
		$writes = array();
		$this->stub_store( $stored, $writes );

		list( $runner, $_main ) = $this->make_runner( array() );
		$runner->$method();

		$this->assertSame( array(), $writes, $method . ': fresh installs must allocate zero rows' );
	}

	/**
	 * Idempotent re-run: once migrated, a second run performs zero writes.
	 *
	 * @param string $method      Migration method under test.
	 * @param string $group       Stored settings group.
	 * @param array  $defaults    Expected backfilled defaults.
	 * @param array  $explicit    Explicit values proving preservation.
	 * @param array  $sibling     Untouched sibling keys.
	 * @param bool   $needs_admin Whether the routine is capability-gated.*/
	#[DataProvider( 'routine_provider' )]
	public function test_rerun_after_migration_is_noop( string $method, string $group, array $defaults, array $explicit, array $sibling, bool $needs_admin ): void {
		unset( $explicit );
		if ( $needs_admin ) {
			Functions\when( 'current_user_can' )->justReturn( true );
		}
		if ( 'migrate_comment_image_hardening' === $method ) {
			Functions\when( 'do_action' )->justReturn( null );
		}

		$stored = array( $group => $sibling );
		$writes = array();
		$this->stub_store( $stored, $writes );

		list( $runner, $_main ) = $this->make_runner( array() );
		$runner->$method();
		$this->assertCount( 1, $this->settings_writes( $writes ), $method . ': first run must migrate' );

		// Write-through stub above already fed the persist back into $stored.
		$writes = array();
		$runner->$method();
		$this->assertSame( array(), $this->settings_writes( $writes ), $method . ': steady-state re-run must perform zero writes' );
	}

	/**
	 * Pre-6.9 core: the block-assets migration is a no-op without even
	 * reading the option.
	 */
	public function test_block_assets_pre_69_core_is_noop(): void {
		$reads  = array();
		$writes = array();

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default_value = false ) use ( &$reads ) {
				$reads[] = $key;
				return $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$writes ) {
				$writes[] = array( $key, $value );
				return true;
			}
		);

		list( $runner, $_main ) = $this->make_runner( array() );
		$runner->migrate_block_assets_setting( false );

		$this->assertSame( array(), $reads );
		$this->assertSame( array(), $writes );
	}

	/**
	 * Block assets on 6.9+: missing key defaults to true, persisted, synced.
	 */
	public function test_block_assets_missing_key_defaults_true(): void {
		$stored = array( 'file_optimisation' => array( 'minifyJS' => true ) );
		$writes = array();
		$this->stub_store( $stored, $writes );

		list( $runner, $main ) = $this->make_runner( array() );
		$runner->migrate_block_assets_setting( true );

		$persisted = $this->settings_writes( $writes );
		$this->assertCount( 1, $persisted );
		$this->assertTrue( $persisted[0][1]['file_optimisation']['blockAssetsOnDemand'] );
		$this->assertTrue( $persisted[0][1]['file_optimisation']['minifyJS'] );

		$memo = $this->read_memo( $main );
		$this->assertTrue( $memo['file_optimisation']['blockAssetsOnDemand'] );
	}

	/**
	 * Third-party auto heals invalid stored shapes (non-bool flag, unknown
	 * preset string) back to the fail-open defaults.
	 */
	public function test_third_party_auto_heals_invalid_shapes(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$stored = array(
			'file_optimisation' => array(
				'delayJSThirdPartyAuto' => 'yes',
				'delayJSPreset'         => 'turbo',
				'minifyJS'              => true,
			),
		);
		$writes = array();
		$this->stub_store( $stored, $writes );

		list( $runner, $main ) = $this->make_runner( array() );
		$runner->migrate_third_party_auto();

		$persisted = $this->settings_writes( $writes );
		$this->assertCount( 1, $persisted );
		$this->assertFalse( $persisted[0][1]['file_optimisation']['delayJSThirdPartyAuto'] );
		$this->assertSame( 'safe', $persisted[0][1]['file_optimisation']['delayJSPreset'] );
		$this->assertTrue( $persisted[0][1]['file_optimisation']['minifyJS'] );

		$memo = $this->read_memo( $main );
		$this->assertFalse( $memo['file_optimisation']['delayJSThirdPartyAuto'] );
		$this->assertSame( 'safe', $memo['file_optimisation']['delayJSPreset'] );
	}

	/**
	 * Builder watcher: a failed persist must not sync the in-memory memo.
	 */
	public function test_builder_watcher_save_failure_skips_memo_sync(): void {
		$stored = array( 'file_optimisation' => array( 'minifyJS' => true ) );
		$writes = array();
		$this->stub_store( $stored, $writes, false );

		list( $runner, $main ) = $this->make_runner( array() );
		$runner->migrate_builder_watcher();

		$this->assertCount( 1, $writes, 'failed persist must still attempt the write' );

		$memo = $this->read_memo( $main );
		$this->assertSame( array(), $memo, 'failed persist must not sync the memo' );
	}

	/**
	 * Memo early-return: a completed outage-flag migration avoids the
	 * extra option read entirely on subsequent admin pages.
	 */
	public function test_outage_flag_memo_early_return_skips_read(): void {
		$reads = array();

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default_value = false ) use ( &$reads ) {
				$reads[] = $key;
				return $default_value;
			}
		);

		list( $runner, $_main ) = $this->make_runner(
			array( 'object_cache' => array( 'outage_bypassed' => false ) )
		);
		$runner->migrate_object_cache_outage_flag();

		$this->assertNotContains( 'wppo_settings', $reads );
	}

	/**
	 * Capability-gated routines must not write for non-administrators.
	 *
	 * @return void
	 * @param string $method Migration method under test.
	 * @param string $group  Stored settings group.*/
	#[DataProvider( 'capability_gated_provider' )]
	public function test_capability_gated_routines_skip_for_editors( string $method, string $group ): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		if ( 'migrate_comment_image_hardening' === $method ) {
			Functions\when( 'do_action' )->justReturn( null );
		}

		$stored = array( $group => array() );
		$writes = array();
		$this->stub_store( $stored, $writes );

		list( $runner, $_main ) = $this->make_runner( array() );
		$runner->$method();

		$this->assertSame( array(), $this->settings_writes( $writes ), $method . ': editors must not trigger migration writes' );
	}

	/**
	 * Capability-gated routine matrix.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function capability_gated_provider(): array {
		return array(
			'speculation prerender list' => array( 'migrate_speculation_prerender_list', 'preload_settings' ),
			'comment image hardening'    => array( 'migrate_comment_image_hardening', 'image_optimisation' ),
			'third party auto'           => array( 'migrate_third_party_auto', 'file_optimisation' ),
		);
	}

	/**
	 * Multisite: per-site get_option() keeps migrations isolated — one
	 * site's stored values never leak into another site's migration.
	 */
	public function test_multisite_same_blog_isolation(): void {
		$stored_by_blog = array(
			1 => array( 'file_optimisation' => array( 'minifyJS' => true ) ),
			2 => array(
				'file_optimisation' => array(
					'minifyJS' => true,
					'safeMode' => true,
				),
			),
		);
		$blog           = 1;
		$writes         = array();

		Functions\when( 'get_current_blog_id' )->alias(
			static function () use ( &$blog ) {
				return $blog;
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default_value = false ) use ( &$stored_by_blog, &$blog ) {
				if ( 'wppo_settings' === $key ) {
					return $stored_by_blog[ $blog ] ?? $default_value;
				}
				return $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$stored_by_blog, &$blog, &$writes ) {
				$writes[] = array( $blog, $key, $value );
				if ( 'wppo_settings' === $key ) {
					$stored_by_blog[ $blog ] = $value;
				}
				return true;
			}
		);
		Functions\when( 'wp_kses_post' )->returnArg();

		// Blog 1 predates the key: backfilled to the off default.
		list( $runner1, $main1 ) = $this->make_runner( array() );
		$runner1->migrate_safe_mode();
		$this->assertFalse( $stored_by_blog[1]['file_optimisation']['safeMode'] );
		$memo1 = $this->read_memo( $main1 );
		$this->assertFalse( $memo1['file_optimisation']['safeMode'] );

		// Blog 2 already stores explicit true: preserved, zero new writes.
		$blog                     = 2;
		list( $runner2, $_main2 ) = $this->make_runner(
			array( 'file_optimisation' => array( 'safeMode' => true ) )
		);
		$writes_before            = count( $writes );
		$runner2->migrate_safe_mode();
		$this->assertSame( $writes_before, count( $writes ) );
		$this->assertTrue( $stored_by_blog[2]['file_optimisation']['safeMode'] );
		// Blog 1's backfill never leaked into blog 2's row.
		$this->assertTrue( $stored_by_blog[2]['file_optimisation']['minifyJS'] );
	}

	/**
	 * Main keeps all 17 public proxies and Hook_Registry still binds
	 * array($main, ...) callbacks — no hook churn.
	 */
	public function test_main_proxies_preserve_callback_identity(): void {
		$proxies = array(
			'maybe_migrate_block_assets_setting',
			'maybe_migrate_ccss_max_size',
			'maybe_migrate_ccss_safelist',
			'maybe_migrate_css_queue_defaults',
			'maybe_migrate_speculation_top_urls',
			'maybe_migrate_speculation_prerender_list',
			'maybe_migrate_rum_sample_rate',
			'maybe_migrate_safe_mode',
			'maybe_migrate_elementor_safe_mode',
			'maybe_migrate_image_alt_edge_defaults',
			'maybe_migrate_preload_auto_defaults',
			'maybe_migrate_object_cache_outage_flag',
			'maybe_migrate_ai_speculation_autotune',
			'maybe_migrate_ai_anomaly_v2',
			'maybe_migrate_comment_image_hardening',
			'maybe_migrate_builder_watcher',
			'maybe_migrate_third_party_auto',
		);

		foreach ( $proxies as $proxy ) {
			$this->assertTrue( method_exists( Main::class, $proxy ), "Main::{$proxy} must exist for Hook_Registry" );
			$method = new ReflectionMethod( Main::class, $proxy );
			$this->assertTrue( $method->isPublic(), "Main::{$proxy} must stay public (Hook_Registry binds it)" );
		}

		foreach ( $proxies as $proxy ) {
			$delegate = (string) preg_replace( '/^maybe_migrate_/', 'migrate_', $proxy );
			$this->assertTrue( method_exists( Settings_Migrations::class, $delegate ), "Settings_Migrations::{$delegate} must own the logic" );
		}

		$registry = file_get_contents( dirname( __DIR__, 2 ) . '/includes/Core/class-hook-registry.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local file scan (no remote URL, no WP_Filesystem in unit tests).
		$this->assertNotFalse( $registry );
		foreach ( $proxies as $proxy ) {
			$this->assertStringContainsString(
				"array( \$main, '{$proxy}' )",
				$registry,
				"Hook_Registry must still bind Main::{$proxy} (no hook churn)"
			);
		}
	}

	/**
	 * Spot-check delegation: Main proxies produce the same persist + memo
	 * sync as direct runner calls.
	 */
	public function test_main_proxy_delegates_to_runner(): void {
		$stored = array( 'file_optimisation' => array( 'minifyJS' => true ) );
		$writes = array();
		$this->stub_store( $stored, $writes );

		$main         = ( new ReflectionClass( Main::class ) )->newInstanceWithoutConstructor();
		$options_prop = new ReflectionProperty( Main::class, 'options' );
		$options_prop->setValue( $main, array() );

		$main->maybe_migrate_ccss_max_size();

		$persisted = $this->settings_writes( $writes );
		$this->assertCount( 1, $persisted );
		$this->assertSame( 20480, $persisted[0][1]['file_optimisation']['ccssMaxSize'] );

		$memo = $options_prop->getValue( $main );
		$this->assertSame( 20480, $memo['file_optimisation']['ccssMaxSize'] );

		// Block-assets proxy forwards the core probe (false in unit tests:
		// no-op, zero writes).
		$writes = array();
		$main->maybe_migrate_block_assets_setting();
		$this->assertSame( array(), $this->settings_writes( $writes ) );
	}
}
