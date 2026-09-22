<?php
/**
 * Regression tests for the REF-002 Settings_Store boundary extraction (issue #1498).
 *
 * Pins verbatim-moved behavior: memo coherence across update/add/delete
 * option hooks, save_settings() write-through, switch_to_blog isolation,
 * snapshot take/restore round-trip, and Util:: facade-proxy equivalence.
 *
 * @package PerformanceOptimise\Tests
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Settings_Store;
use PerformanceOptimise\Inc\Util;

/**
 * Settings_Store boundary tests.
 *
 * @package PerformanceOptimise\Tests
 */
class SettingsStoreTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options store.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Number of times get_option() was called (deserialization proxy).
	 *
	 * @var int
	 */
	private int $option_reads = 0;

	/**
	 * Install get_option/update_option stubs backed by an in-memory store.
	 *
	 * The wppo_settings entry is blog-keyed (wppo_settings:<blog_id>) so
	 * multisite memo isolation can be exercised; all other options use
	 * their bare name.
	 *
	 * @param bool $fail_writes When true, update_option() reports failure without writing.
	 */
	private function install_option_stubs( bool $fail_writes = false ): void {
		$this->options      = array();
		$this->option_reads = 0;

		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				++$this->option_reads;
				if ( 'wppo_settings' === $name ) {
					$bid = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
					$key = 'wppo_settings:' . $bid;
					return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $fallback;
				}
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) use ( $fail_writes ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature must match update_option().
				if ( $fail_writes ) {
					return false;
				}
				if ( 'wppo_settings' === $name ) {
					$bid                                      = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
					$this->options[ 'wppo_settings:' . $bid ] = $value;
					return true;
				}
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'add_action' )->justReturn( true );
	}

	/**
	 * Switch the stubbed current blog ID.
	 *
	 * @param int $blog_id Blog ID to report from get_current_blog_id().
	 */
	private function use_blog( int $blog_id ): void {
		Functions\when( 'get_current_blog_id' )->justReturn( $blog_id );
	}

	/**
	 * The memo must collapse repeated calls to a single option read.
	 */
	public function test_get_settings_memoizes_option_reads(): void {
		$this->install_option_stubs();
		$this->use_blog( 1 );
		$this->options['wppo_settings:1'] = array( 'cache_settings' => array( 'enableCache' => true ) );

		$first  = Settings_Store::get_settings();
		$second = Settings_Store::get_settings();
		$third  = Settings_Store::get_settings();

		$this->assertSame( 1, $this->option_reads, 'get_settings() should deserialize the option once per request.' );
		$this->assertSame( $first, $second );
		$this->assertSame( $first, $third );
	}

	/**
	 * The update-option coherence callback must make later reads observe the new value.
	 */
	public function test_on_settings_update_refreshes_memo(): void {
		$this->install_option_stubs();
		$this->use_blog( 1 );
		$old                              = array( 'cache_settings' => array( 'enableCache' => false ) );
		$new                              = array( 'cache_settings' => array( 'enableCache' => true ) );
		$this->options['wppo_settings:1'] = $old;

		$this->assertSame( $old, Settings_Store::get_settings() );

		// Simulate WP firing update_option_wppo_settings.
		Settings_Store::on_settings_update( $old, $new );

		$this->assertSame( $new, Settings_Store::get_settings() );
		$this->assertSame( 1, $this->option_reads, 'Coherence update must not trigger a fresh option read.' );
	}

	/**
	 * The add-option coherence callback must populate the memo for wppo_settings only.
	 */
	public function test_on_settings_add_populates_memo(): void {
		$this->install_option_stubs();
		$this->use_blog( 1 );

		$value = array( 'database_cleanup' => array( 'revisions' => true ) );

		// Simulate WP firing add_option_wppo_settings.
		Settings_Store::on_settings_add( 'wppo_settings', $value );

		$this->assertSame( $value, Settings_Store::get_settings() );
		$this->assertSame( 0, $this->option_reads, 'Memo should serve the added value without a fresh option read.' );

		Settings_Store::clear_settings_cache();
		Settings_Store::on_settings_add( 'some_other_option', array( 'x' => true ) );

		$this->assertSame( array(), Settings_Store::get_settings(), 'Unrelated options must not populate the settings memo.' );
	}

	/**
	 * Clearing the memo (delete path) forces the next read to re-fetch the option.
	 *
	 * The delete_option_wppo_settings action passes the option name (a
	 * non-int), which must clear all blog entries.
	 */
	public function test_delete_clears_memo_for_all_blogs(): void {
		$this->install_option_stubs();
		$this->use_blog( 1 );
		$this->options['wppo_settings:1'] = array( 'a' => 1 );

		Settings_Store::get_settings();
		$this->options['wppo_settings:1'] = array( 'a' => 2 );

		// Memo still holds the old value.
		$this->assertSame( array( 'a' => 1 ), Settings_Store::get_settings() );

		// Simulate WP firing delete_option_wppo_settings (passes option name).
		Settings_Store::clear_settings_cache( 'wppo_settings' );
		$this->assertSame( array( 'a' => 2 ), Settings_Store::get_settings() );
		$this->assertSame( 2, $this->option_reads );
	}

	/**
	 * Per-blog clear must leave other blogs' memos intact.
	 */
	public function test_clear_by_blog_id_is_scoped(): void {
		$this->install_option_stubs();

		$this->use_blog( 1 );
		$this->options['wppo_settings:1'] = array( 'site' => 1 );
		$this->use_blog( 2 );
		$this->options['wppo_settings:2'] = array( 'site' => 2 );

		$this->use_blog( 1 );
		$this->assertSame( array( 'site' => 1 ), Settings_Store::get_settings() );
		$this->use_blog( 2 );
		$this->assertSame( array( 'site' => 2 ), Settings_Store::get_settings() );
		$reads = $this->option_reads;

		Settings_Store::clear_settings_cache( 1 );

		// Blog 2 memo survives: no fresh read.
		$this->assertSame( array( 'site' => 2 ), Settings_Store::get_settings() );
		$this->assertSame( $reads, $this->option_reads );

		// Blog 1 refetches.
		$this->use_blog( 1 );
		$this->assertSame( array( 'site' => 1 ), Settings_Store::get_settings() );
		$this->assertSame( $reads + 1, $this->option_reads );
	}

	/**
	 * Memos must be isolated per blog under switch_to_blog().
	 */
	public function test_switch_blog_isolation(): void {
		$this->install_option_stubs();

		$this->use_blog( 1 );
		$this->options['wppo_settings:1'] = array( 'site' => 1 );
		$this->use_blog( 2 );
		$this->options['wppo_settings:2'] = array( 'site' => 2 );

		$this->use_blog( 1 );
		$this->assertSame( array( 'site' => 1 ), Settings_Store::get_settings() );

		$this->use_blog( 2 );
		$this->assertSame( array( 'site' => 2 ), Settings_Store::get_settings(), 'Blog 2 must never observe blog 1 memo.' );

		$this->use_blog( 1 );
		$reads = $this->option_reads;
		$this->assertSame( array( 'site' => 1 ), Settings_Store::get_settings(), 'Returning to blog 1 must reuse its memo.' );
		$this->assertSame( $reads, $this->option_reads );
	}

	/**
	 * Save_settings() must write through to the option and refresh the memo.
	 */
	public function test_save_settings_write_through(): void {
		$this->install_option_stubs();
		$this->use_blog( 1 );

		$settings = array( 'cache_settings' => array( 'enableCache' => true ) );

		$this->assertTrue( Settings_Store::save_settings( $settings ) );
		$this->assertSame( $settings, $this->options['wppo_settings:1'], 'Settings must be persisted to the option.' );

		$reads = $this->option_reads;
		$this->assertSame( $settings, Settings_Store::get_settings() );
		$this->assertSame( $reads, $this->option_reads, 'Same-request reads must observe the write without a fresh read.' );
	}

	/**
	 * Save_settings() must fail closed (false) when the option write fails.
	 */
	public function test_save_settings_returns_false_when_write_fails(): void {
		$this->install_option_stubs( true );
		$this->use_blog( 1 );

		$this->assertFalse( Settings_Store::save_settings( array( 'a' => 1 ) ) );
	}

	/**
	 * The settings-side switch_blog handler must not disturb the blog-keyed memo.
	 */
	public function test_on_switch_blog_preserves_memo(): void {
		$this->install_option_stubs();
		$this->use_blog( 1 );
		$this->options['wppo_settings:1'] = array( 'site' => 1 );

		$this->assertSame( array( 'site' => 1 ), Settings_Store::get_settings() );

		Settings_Store::on_switch_blog( 2, 1 );

		$reads = $this->option_reads;
		$this->assertSame( array( 'site' => 1 ), Settings_Store::get_settings() );
		$this->assertSame( $reads, $this->option_reads, 'Blog-keyed memo needs no destructive clear on switch.' );
	}

	/**
	 * Util::on_switch_blog() must delegate the settings part and keep local clears.
	 */
	public function test_util_on_switch_blog_delegates_and_clears_locals(): void {
		$this->install_option_stubs();
		$this->use_blog( 1 );
		$this->options['wppo_settings:1'] = array( 'site' => 1 );

		$this->assertSame( array( 'site' => 1 ), Util::get_settings() );

		Util::on_switch_blog( 2, 1 );

		$reads = $this->option_reads;
		$this->assertSame( array( 'site' => 1 ), Util::get_settings(), 'Settings memo must survive the delegated switch handler.' );
		$this->assertSame( $reads, $this->option_reads );
	}

	/**
	 * Snapshot take/restore round-trip through the new owner.
	 */
	public function test_snapshot_take_restore_round_trip(): void {
		$this->install_option_stubs();
		$this->use_blog( 1 );

		$prior                            = array( 'file_optimisation' => array( 'minifyHTML' => false ) );
		$this->options['wppo_settings:1'] = $prior;
		$this->assertTrue( Settings_Store::take_settings_snapshot( $prior ) );

		$snapshot = Settings_Store::get_settings_snapshot();
		$this->assertIsArray( $snapshot );
		$this->assertSame( $prior, $snapshot['settings'] );
		$this->assertArrayHasKey( 'taken_at', $snapshot );

		$new                              = array( 'file_optimisation' => array( 'minifyHTML' => true ) );
		$this->options['wppo_settings:1'] = $new;
		Settings_Store::clear_settings_cache();

		$restored = Settings_Store::restore_settings_snapshot();
		$this->assertSame( $prior, $restored );
		$this->assertSame( $prior, $this->options['wppo_settings:1'], 'Stored settings must be rolled back to the snapshot.' );
		$this->assertSame( $prior, Settings_Store::get_settings(), 'Settings memo must reflect the restored settings.' );
	}

	/**
	 * Restore must fail open when the option write fails.
	 */
	public function test_snapshot_restore_returns_null_when_write_fails(): void {
		$this->install_option_stubs( true );
		$this->use_blog( 1 );

		$prior = array( 'file_optimisation' => array( 'minifyHTML' => false ) );
		// Seed the snapshot directly: writes fail, so take_settings_snapshot()
		// cannot persist it through the stub.
		$this->options[ Settings_Store::SETTINGS_SNAPSHOT_OPTION ] = array(
			'settings' => $prior,
			'taken_at' => 1234567890,
		);
		$current                          = array( 'file_optimisation' => array( 'minifyHTML' => true ) );
		$this->options['wppo_settings:1'] = $current;

		$this->assertNull( Settings_Store::restore_settings_snapshot() );
		$this->assertSame( $current, $this->options['wppo_settings:1'], 'Current settings must stay intact when the restore write fails.' );
	}

	/**
	 * Util:: proxies must share the single Settings_Store memo (no dual state).
	 */
	public function test_util_proxies_share_single_memo(): void {
		$this->install_option_stubs();
		$this->use_blog( 1 );
		$this->options['wppo_settings:1'] = array( 'a' => 1 );

		// Read through the store, mutate through the proxy.
		$this->assertSame( array( 'a' => 1 ), Settings_Store::get_settings() );
		Util::set_settings_cache( array( 'a' => 2 ) );
		$this->assertSame( array( 'a' => 2 ), Settings_Store::get_settings(), 'Util::set_settings_cache must write the shared memo.' );

		// Write through the proxy, observe through the store without a fresh read.
		$reads = $this->option_reads;
		$this->assertTrue( Util::save_settings( array( 'a' => 3 ) ) );
		$this->assertSame( array( 'a' => 3 ), Settings_Store::get_settings() );
		$this->assertSame( $reads, $this->option_reads );

		// Coherence callbacks through the proxy affect the shared memo.
		Util::on_settings_update( array( 'a' => 3 ), array( 'a' => 4 ) );
		$this->assertSame( array( 'a' => 4 ), Settings_Store::get_settings() );

		Util::on_settings_add( 'wppo_settings', array( 'a' => 5 ) );
		$this->assertSame( array( 'a' => 5 ), Settings_Store::get_settings() );

		// Snapshot helpers through the proxy round-trip the shared snapshot.
		$this->assertTrue( Util::take_settings_snapshot( array( 'a' => 5 ) ) );
		$this->assertSame( array( 'a' => 5 ), Settings_Store::get_settings_snapshot()['settings'] );
		$this->assertSame( Settings_Store::get_settings_snapshot(), Util::get_settings_snapshot() );

		// Clearing through the proxy clears the shared memo.
		Util::clear_settings_cache();
		$this->options['wppo_settings:1'] = array( 'a' => 6 );
		$this->assertSame( array( 'a' => 6 ), Settings_Store::get_settings() );
	}

	/**
	 * The snapshot option constant must stay identical on both classes.
	 */
	public function test_snapshot_option_constant_parity(): void {
		$this->assertSame( 'wppo_settings_snapshot', Settings_Store::SETTINGS_SNAPSHOT_OPTION );
		$this->assertSame( Settings_Store::SETTINGS_SNAPSHOT_OPTION, Util::SETTINGS_SNAPSHOT_OPTION );
	}

	/**
	 * Eager registration must be idempotent on both the owner and the proxy.
	 */
	public function test_register_settings_cache_hooks_is_idempotent(): void {
		$this->install_option_stubs();
		$this->use_blog( 1 );

		Settings_Store::register_settings_cache_hooks();
		Settings_Store::register_settings_cache_hooks();
		Util::register_settings_cache_hooks();
		Util::register_settings_cache_hooks();

		// No exception and no side effects on the memo.
		$this->assertSame( array(), Settings_Store::get_settings() );
		$this->assertSame( array(), Util::get_settings() );
	}
}
