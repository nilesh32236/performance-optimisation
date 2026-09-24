<?php
/**
 * Tests for the P3-008 Settings_Command write-orchestration seam (issue #1580).
 *
 * Pins behavior parity for every path routed through the command: partial
 * tab saves preserve sibling keys, no-op saves skip the snapshot, changed
 * saves snapshot the prior settings then write via Settings_Store, import
 * merge keeps the no-op 200 vs 500-fail branches, safe-mode enable/disable
 * transitions keep their snapshot rule, restore delegates to the Store, the
 * Store memo observes command writes, and no direct
 * `update_option('wppo_settings')` write survives outside Settings_Store.
 *
 * @package PerformanceOptimise\Tests
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Settings_Command;
use PerformanceOptimise\Inc\Settings_Store;

/**
 * Settings_Command behavior + source-boundary tests.
 *
 * @package PerformanceOptimise\Tests
 */
class SettingsCommandTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options store.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Names passed to update_option() (write probe).
	 *
	 * @var string[]
	 */
	private array $write_log = array();

	/**
	 * When true, update_option() reports failure without writing.
	 *
	 * @var bool
	 */
	private bool $fail_writes = false;

	/**
	 * Option name for which update_option() must fail (null = no targeted failure).
	 *
	 * @var string|null
	 */
	private ?string $fail_option = null;

	/**
	 * Install get_option/update_option stubs backed by an in-memory store.
	 */
	private function install_option_stubs(): void {
		$this->options     = array();
		$this->write_log   = array();
		$this->fail_writes = false;
		$this->fail_option = null;
		Settings_Store::clear_settings_cache();

		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature must match update_option().
				$this->write_log[] = $name;
				if ( $this->fail_writes ) {
					return false;
				}
				if ( null !== $this->fail_option && $name === $this->fail_option ) {
					return false;
				}
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
	}

	/**
	 * Partial tab saves must merge over the prior tab, not replace it.
	 */
	public function test_save_tab_merges_partial_without_dropping_siblings(): void {
		$this->install_option_stubs();
		$current = array(
			'preload_settings' => array(
				'enablePreloadCache' => true,
				'preloadFontsUrls'   => "https://example.com/font.woff2\n",
			),
		);

		list( $options, $saved ) = Settings_Command::save_tab( $current, 'preload_settings', array( 'autoLcpPreload' => true ) );

		$this->assertTrue( $saved );
		$this->assertTrue( $options['preload_settings']['autoLcpPreload'] );
		$this->assertTrue( $options['preload_settings']['enablePreloadCache'], 'Partial save must preserve sibling keys.' );
		$this->assertSame( "https://example.com/font.woff2\n", $options['preload_settings']['preloadFontsUrls'] );
		$this->assertSame( $options, $this->options['wppo_settings'], 'Merged options must be persisted.' );
	}

	/**
	 * A new tab with no prior value must be stored as the sanitized slice.
	 */
	public function test_save_tab_creates_missing_tab(): void {
		$this->install_option_stubs();

		list( $options, $saved ) = Settings_Command::save_tab( array(), 'cache_settings', array( 'enableCache' => true ) );

		$this->assertTrue( $saved );
		$this->assertSame( array( 'cache_settings' => array( 'enableCache' => true ) ), $options );
	}

	/**
	 * No-op tab saves must not churn the single-slot snapshot.
	 */
	public function test_save_tab_noop_skips_snapshot(): void {
		$this->install_option_stubs();
		$current = array( 'cache_settings' => array( 'enableCache' => true ) );

		list( $options, $saved ) = Settings_Command::save_tab( $current, 'cache_settings', array( 'enableCache' => true ) );

		$this->assertTrue( $saved );
		$this->assertSame( $current, $options );
		$this->assertArrayNotHasKey( Settings_Store::SETTINGS_SNAPSHOT_OPTION, $this->options, 'No-op save must not take a snapshot.' );
	}

	/**
	 * Changed tab saves must snapshot the prior settings before writing.
	 */
	public function test_save_tab_changed_snapshots_prior_and_writes(): void {
		$this->install_option_stubs();
		$current = array( 'cache_settings' => array( 'enableCache' => false ) );

		list( $options, $saved ) = Settings_Command::save_tab( $current, 'cache_settings', array( 'enableCache' => true ) );

		$this->assertTrue( $saved );
		$this->assertTrue( $options['cache_settings']['enableCache'] );
		$this->assertArrayHasKey( Settings_Store::SETTINGS_SNAPSHOT_OPTION, $this->options, 'Changed save must snapshot the prior settings.' );
		$this->assertSame( $current, $this->options[ Settings_Store::SETTINGS_SNAPSHOT_OPTION ]['settings'] );
		$this->assertSame( $options, $this->options['wppo_settings'] );
	}

	/**
	 * Snapshot failure must never block the save (fail-open).
	 */
	public function test_save_tab_snapshot_failure_is_fail_open(): void {
		$this->install_option_stubs();
		$this->fail_option = Settings_Store::SETTINGS_SNAPSHOT_OPTION;
		$current           = array( 'cache_settings' => array( 'enableCache' => false ) );

		list( $options, $saved ) = Settings_Command::save_tab( $current, 'cache_settings', array( 'enableCache' => true ) );

		$this->assertTrue( $saved, 'Save must succeed even when the snapshot write fails.' );
		$this->assertTrue( $options['cache_settings']['enableCache'] );
		$this->assertSame( $options, $this->options['wppo_settings'] );
	}

	/**
	 * Same-request reads must observe command writes via the Store memo.
	 */
	public function test_save_tab_memo_observes_write(): void {
		$this->install_option_stubs();
		$current = array( 'cache_settings' => array( 'enableCache' => false ) );

		list( $options ) = Settings_Command::save_tab( $current, 'cache_settings', array( 'enableCache' => true ) );

		$this->assertSame( $options, Settings_Store::get_settings(), 'Store memo must reflect the command write without a fresh read.' );
	}

	/**
	 * No-op imports must skip both snapshot and write yet report success.
	 */
	public function test_save_merged_noop_skips_snapshot_and_write(): void {
		$this->install_option_stubs();
		$existing = array( 'cache_settings' => array( 'enableCache' => true ) );

		list( $merged, $saved ) = Settings_Command::save_merged( $existing, array( 'cache_settings' => array( 'enableCache' => true ) ) );

		$this->assertTrue( $saved );
		$this->assertSame( $existing, $merged );
		$this->assertArrayNotHasKey( Settings_Store::SETTINGS_SNAPSHOT_OPTION, $this->options, 'No-op import must not snapshot.' );
		$this->assertArrayNotHasKey( 'wppo_settings', $this->options, 'No-op import must not write.' );
		$this->assertSame( array(), $this->write_log, 'No-op import must issue zero option writes.' );
	}

	/**
	 * Changed imports must snapshot the prior settings, merge recursively, and write.
	 */
	public function test_save_merged_changed_snapshots_and_writes(): void {
		$this->install_option_stubs();
		$existing = array(
			'cache_settings' => array(
				'enableCache' => true,
				'cacheTTL'    => 3600,
			),
		);

		list( $merged, $saved ) = Settings_Command::save_merged( $existing, array( 'cache_settings' => array( 'enableCache' => false ) ) );

		$this->assertTrue( $saved );
		$this->assertFalse( $merged['cache_settings']['enableCache'] );
		$this->assertSame( 3600, $merged['cache_settings']['cacheTTL'], 'Recursive merge must preserve untouched keys.' );
		$this->assertSame( $existing, $this->options[ Settings_Store::SETTINGS_SNAPSHOT_OPTION ]['settings'] );
		$this->assertSame( $merged, $this->options['wppo_settings'] );
	}

	/**
	 * A failed canonical write must surface false so callers keep the 500 branch.
	 */
	public function test_save_merged_write_failure_returns_false(): void {
		$this->install_option_stubs();
		$this->fail_writes = true;
		$existing          = array( 'cache_settings' => array( 'enableCache' => true ) );

		list( $merged, $saved ) = Settings_Command::save_merged( $existing, array( 'cache_settings' => array( 'enableCache' => false ) ) );

		$this->assertFalse( $saved, 'Write failure must surface so the REST caller returns 500.' );
		$this->assertFalse( $merged['cache_settings']['enableCache'] );
	}

	/**
	 * Safe-mode enable must snapshot (when previously off) and set the flag.
	 */
	public function test_toggle_safe_mode_enable_sets_flag_and_snapshots(): void {
		$this->install_option_stubs();
		$options = array( 'file_optimisation' => array( 'safeMode' => false ) );

		$result = Settings_Command::toggle_safe_mode( $options, 'enable' );

		$this->assertTrue( ! empty( $result['file_optimisation']['safeMode'] ), 'Enable must set safeMode.' );
		$this->assertArrayHasKey( Settings_Store::SETTINGS_SNAPSHOT_OPTION, $this->options, 'Enable while off must snapshot.' );
		$this->assertSame( $options, $this->options[ Settings_Store::SETTINGS_SNAPSHOT_OPTION ]['settings'] );
		$this->assertSame( $result, $this->options['wppo_settings'] );
	}

	/**
	 * Enabling safe mode when already on must not churn the snapshot.
	 */
	public function test_toggle_safe_mode_enable_when_already_safe_skips_snapshot(): void {
		$this->install_option_stubs();
		$options = array( 'file_optimisation' => array( 'safeMode' => true ) );

		$result = Settings_Command::toggle_safe_mode( $options, 'enable' );

		$this->assertTrue( ! empty( $result['file_optimisation']['safeMode'] ) );
		$this->assertArrayNotHasKey( Settings_Store::SETTINGS_SNAPSHOT_OPTION, $this->options, 'Re-enable must not overwrite the pre-enable snapshot.' );
	}

	/**
	 * Safe-mode disable must clear the flag without snapshotting (preserves undo).
	 */
	public function test_toggle_safe_mode_disable_clears_flag_without_snapshot(): void {
		$this->install_option_stubs();
		$options = array( 'file_optimisation' => array( 'safeMode' => true ) );

		$result = Settings_Command::toggle_safe_mode( $options, 'disable' );

		$this->assertFalse( $result['file_optimisation']['safeMode'], 'Disable must clear safeMode.' );
		$this->assertArrayNotHasKey( Settings_Store::SETTINGS_SNAPSHOT_OPTION, $this->options, 'Disable must not overwrite the pre-enable undo snapshot.' );
		$this->assertSame( $result, $this->options['wppo_settings'] );
	}

	/**
	 * The injected enable builder must receive the slice and own the payload.
	 */
	public function test_toggle_safe_mode_enable_uses_injected_builder(): void {
		$this->install_option_stubs();
		$options = array(
			'file_optimisation' => array(
				'safeMode' => false,
				'delayJS'  => true,
			),
		);
		$seen    = null;
		$builder = static function ( array $tab ) use ( &$seen ): array {
			$seen             = $tab;
			$tab['safeMode']  = true;
			$tab['built_by']  = 'test';
			return $tab;
		};

		$result = Settings_Command::toggle_safe_mode( $options, 'enable', $builder );

		$this->assertSame( $options['file_optimisation'], $seen, 'Builder must receive the pre-enable slice.' );
		$this->assertTrue( $result['file_optimisation']['safeMode'] );
		$this->assertTrue( $result['file_optimisation']['delayJS'], 'Builder payload must preserve sibling keys.' );
		$this->assertSame( 'test', $result['file_optimisation']['built_by'] );
		$this->assertSame( $result, $this->options['wppo_settings'] );
	}

	/**
	 * A throwing builder must fail open to safeMode = true.
	 */
	public function test_toggle_safe_mode_enable_builder_failure_falls_back(): void {
		$this->install_option_stubs();
		$options = array( 'file_optimisation' => array( 'safeMode' => false ) );
		$builder = static function (): array {
			throw new \RuntimeException( 'builder exploded' );
		};

		$result = Settings_Command::toggle_safe_mode( $options, 'enable', $builder );

		$this->assertTrue( $result['file_optimisation']['safeMode'], 'Builder failure must fall back to safeMode = true.' );
		$this->assertSame( $result, $this->options['wppo_settings'] );
	}

	/**
	 * Safe-mode toggle must create the tab when absent.
	 */
	public function test_toggle_safe_mode_creates_missing_tab(): void {
		$this->install_option_stubs();

		$result = Settings_Command::toggle_safe_mode( array(), 'enable' );

		$this->assertTrue( ! empty( $result['file_optimisation']['safeMode'] ) );
		$this->assertArrayHasKey( Settings_Store::SETTINGS_SNAPSHOT_OPTION, $this->options );
	}

	/**
	 * Restore must return null when no snapshot exists (fail-open).
	 */
	public function test_restore_without_snapshot_returns_null(): void {
		$this->install_option_stubs();

		$this->assertNull( Settings_Command::restore(), 'Restore with no snapshot must return null.' );
	}

	/**
	 * Restore must round-trip the snapshotted settings through the Store.
	 */
	public function test_restore_round_trip(): void {
		$this->install_option_stubs();
		$prior   = array( 'cache_settings' => array( 'enableCache' => false ) );
		$current = array( 'cache_settings' => array( 'enableCache' => true ) );

		Settings_Store::take_settings_snapshot( $prior );
		$this->options['wppo_settings'] = $current;
		Settings_Store::clear_settings_cache();

		$restored = Settings_Command::restore();

		$this->assertSame( $prior, $restored );
		$this->assertSame( $prior, $this->options['wppo_settings'] );
		$this->assertSame( $prior, Settings_Store::get_settings() );
	}

	/**
	 * Only Settings_Store may issue a direct `update_option('wppo_settings')` write.
	 */
	public function test_direct_settings_writes_exist_only_in_store(): void {
		$plugin_root = dirname( __DIR__, 2 );
		$store_file  = $plugin_root . '/includes/Settings/class-settings-store.php';
		$hits        = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $plugin_root . '/includes', \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			// Strip comments/docblocks first so prose mentions (e.g. hook
			// docs describing which option fires them) never count as writes.
			$code = '';
			foreach ( \PhpToken::tokenize( (string) file_get_contents( $file->getPathname() ) ) as $token ) {
				if ( $token->is( array( T_COMMENT, T_DOC_COMMENT ) ) ) {
					continue;
				}
				$code .= $token->text;
			}
			if ( preg_match( "/update_option\(\s*['\"]wppo_settings['\"]/", $code ) ) {
				$hits[] = $file->getPathname();
			}
		}

		$this->assertSame(
			array( $store_file ),
			$hits,
			'Direct update_option(wppo_settings) writes must exist only in Settings_Store; all other paths route through Settings_Command.'
		);
	}

	/**
	 * The command must expose the full write seam with Store-compatible returns.
	 */
	public function test_command_exposes_full_write_seam(): void {
		$this->assertTrue( method_exists( Settings_Command::class, 'save_tab' ) );
		$this->assertTrue( method_exists( Settings_Command::class, 'save_merged' ) );
		$this->assertTrue( method_exists( Settings_Command::class, 'toggle_safe_mode' ) );
		$this->assertTrue( method_exists( Settings_Command::class, 'restore' ) );

		$save_tab = new \ReflectionMethod( Settings_Command::class, 'save_tab' );
		$this->assertTrue( $save_tab->isStatic() );
		$this->assertSame( 'array', (string) $save_tab->getReturnType() );

		$save_merged = new \ReflectionMethod( Settings_Command::class, 'save_merged' );
		$this->assertTrue( $save_merged->isStatic() );
		$this->assertSame( 'array', (string) $save_merged->getReturnType() );

		$restore = new \ReflectionMethod( Settings_Command::class, 'restore' );
		$this->assertTrue( $restore->isStatic() );
	}
}
