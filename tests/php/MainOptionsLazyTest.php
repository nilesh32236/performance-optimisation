<?php
/**
 * Tests for Main::get_options() lazy resolution (REF-007).
 *
 * Characterization suite for the constructor-I/O refactor: resolution
 * (stored `wppo_settings` over the canonical
 * `Util::get_default_settings()` defaults plus the historical
 * in-memory backfills) moved from `Main::__construct()` into the lazy
 * `get_options()` accessor verbatim. Asserts snapshot parity with
 * `Util::get_default_settings()`, backfill parity for legacy stored
 * settings, accessor-lazy semantics (no `get_option()` before the first
 * read, memoized afterwards), and `switch_to_blog()` re-resolution.
 *
 * Scope note: the constructor still resolves exactly once eagerly for its
 * collaborators (option-gated hook setup), so this suite characterizes the
 * accessor contract only — it makes no boot-I/O perf claim. The
 * accessor-lazy test below bypasses the constructor deliberately via
 * `newInstanceWithoutConstructor()`.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests the lazy Main options accessor.
 *
 * @package PerformanceOptimise\Tests
 */
class MainOptionsLazyTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Build a Main instance without running the constructor.
	 *
	 * Bypassing the constructor is what makes the lazy contract
	 * observable: no resolution (and therefore no `get_option()`) may
	 * happen before the first `get_options()` call.
	 *
	 * @return Main
	 */
	private function make_main(): Main {
		$reflection = new \ReflectionClass( Main::class );
		return $reflection->newInstanceWithoutConstructor();
	}

	/**
	 * Stub get_option() for `wppo_settings` with a per-blog map and a call counter.
	 *
	 * @param array $stored_by_blog Stored settings keyed by blog ID.
	 * @param int   $calls          Call counter (by reference).
	 * @return void
	 */
	private function stub_settings_by_blog( array $stored_by_blog, &$calls ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $option, $fallback = array() ) use ( &$calls, $stored_by_blog ) {
				$calls++;
				if ( 'wppo_settings' === $option ) {
					$bid = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
					return array_key_exists( $bid, $stored_by_blog ) ? $stored_by_blog[ $bid ] : $fallback;
				}
				return $fallback;
			}
		);
	}

	/**
	 * Empty stored settings resolve to the canonical defaults verbatim.
	 */
	public function test_empty_stored_resolves_to_canonical_defaults(): void {
		$calls = 0;
		$this->stub_settings_by_blog( array( 1 => array() ), $calls );

		$main     = $this->make_main();
		$resolved = $main->get_options();

		$this->assertGreaterThan( 0, $calls, 'First read must resolve via get_option().' );
		$this->assertEquals( Util::get_default_settings(), $resolved, 'Lazy snapshot must equal the canonical defaults.' );

		// Spot-check historically backfilled keys, including the two dynamic defaults.
		$expected_od    = class_exists( 'OD_URL_Metric' ) || function_exists( 'od_get_url_metrics' );
		$expected_block = function_exists( 'wp_load_classic_theme_block_styles_on_demand' );
		$this->assertSame( true, $resolved['cache_settings']['wooSafeMode'] );
		$this->assertSame( true, $resolved['image_optimisation']['lazyLoadNative'] );
		$this->assertSame( true, $resolved['preload_settings']['speculationRumGating'] );
		$this->assertSame( 20480, $resolved['file_optimisation']['ccssMaxSize'] );
		$this->assertSame( $expected_od, $resolved['od_integration']['enabled'] );
		$this->assertSame( $expected_block, $resolved['file_optimisation']['blockAssetsOnDemand'] );
	}

	/**
	 * Stored values win; missing legacy keys are backfilled; invalid preset normalizes.
	 */
	public function test_stored_settings_win_and_backfills_apply(): void {
		$calls  = 0;
		$stored = array(
			'cache_settings'    => array( 'enableCache' => false ),
			'file_optimisation' => array( 'delayJSPreset' => 'bogus-level' ),
		);
		$this->stub_settings_by_blog( array( 1 => $stored ), $calls );

		$resolved = $this->make_main()->get_options();

		// Explicit stored values are preserved untouched.
		$this->assertSame( false, $resolved['cache_settings']['enableCache'] );
		// Missing legacy keys inherit the in-memory backfill defaults.
		$this->assertSame( true, $resolved['cache_settings']['wooSafeMode'] );
		$this->assertSame( true, $resolved['file_optimisation']['delayJSSafeMode'] );
		$this->assertSame( true, $resolved['image_optimisation']['lazyLoadNative'] );
		$this->assertSame( 2, $resolved['preload_settings']['speculationTopUrlsLimit'] );
		// Invalid preset values normalize to the safe default.
		$this->assertSame( 'safe', $resolved['file_optimisation']['delayJSPreset'] );
	}

	/**
	 * Explicit valid stored values (including opt-outs) are never overridden.
	 */
	public function test_explicit_stored_values_preserved(): void {
		$calls  = 0;
		$stored = array(
			'cache_settings'     => array(
				'enableCache' => true,
				'wooSafeMode' => false,
			),
			'file_optimisation'  => array( 'delayJSPreset' => 'aggressive' ),
			'image_optimisation' => array( 'lazyLoadNative' => false ),
		);
		$this->stub_settings_by_blog( array( 1 => $stored ), $calls );

		$resolved = $this->make_main()->get_options();

		$this->assertSame( false, $resolved['cache_settings']['wooSafeMode'] );
		$this->assertSame( 'aggressive', $resolved['file_optimisation']['delayJSPreset'] );
		$this->assertSame( false, $resolved['image_optimisation']['lazyLoadNative'] );
	}

	/**
	 * Accessor-lazy, not constructor-lazy: no I/O before the first read; the snapshot memoizes afterwards.
	 *
	 * Uses `newInstanceWithoutConstructor()` deliberately: the real
	 * constructor still resolves exactly once eagerly for its collaborators
	 * (see `Main::__construct()`), so this proves the accessor contract
	 * only — no boot perf gain is implied.
	 */
	public function test_accessor_lazy_not_eager_and_memoized(): void {
		$calls = 0;
		$this->stub_settings_by_blog( array( 1 => array() ), $calls );

		$main = $this->make_main();
		$this->assertSame( 0, $calls, 'Construction must not touch get_option().' );

		$first = $main->get_options();
		$this->assertGreaterThan( 0, $calls, 'First read must resolve.' );
		$calls_after_first = $calls;

		$second = $main->get_options();
		$this->assertSame( $calls_after_first, $calls, 'Second read must be served from the memo.' );
		$this->assertSame( $first, $second );
	}

	/**
	 * Switching blogs re-resolves so no site reuses another site's snapshot.
	 */
	public function test_switch_to_blog_reresolves(): void {
		$calls        = 0;
		$blog1_stored = array( 'cache_settings' => array( 'enableCache' => false ) );
		$blog2_stored = array( 'cache_settings' => array( 'enableCache' => true ) );
		$this->stub_settings_by_blog(
			array(
				1 => $blog1_stored,
				2 => $blog2_stored,
			),
			$calls
		);

		$main = $this->make_main();

		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		$blog1 = $main->get_options();
		$this->assertSame( false, $blog1['cache_settings']['enableCache'] );

		Functions\when( 'get_current_blog_id' )->justReturn( 2 );
		$blog2 = $main->get_options();
		$this->assertSame( true, $blog2['cache_settings']['enableCache'], 'Switched blog must re-resolve its own stored settings.' );
		$this->assertSame( true, $blog2['cache_settings']['wooSafeMode'], 'Backfills must apply on the re-resolved snapshot too.' );

		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		$back = $main->get_options();
		$this->assertSame( false, $back['cache_settings']['enableCache'], 'Switching back must restore the original site snapshot.' );
	}

	/**
	 * Main memo dropped by refresh_options() so post-save reads re-resolve.
	 */
	public function test_refresh_options_drops_memo_and_reresolves(): void {
		$calls = 0;
		$this->stub_settings_by_blog( array( 1 => array( 'cache_settings' => array( 'enableCache' => false ) ) ), $calls );

		$main  = $this->make_main();
		$first = $main->get_options();
		$this->assertSame( false, $first['cache_settings']['enableCache'] );

		// Simulate a same-request save: the update hook refreshes the
		// Settings_Store memo in production; mirror that here, then drop
		// the Main memo and re-read.
		Util::set_settings_cache( array( 'cache_settings' => array( 'enableCache' => true ) ) );
		$main->refresh_options();

		$second = $main->get_options();
		$this->assertSame( true, $second['cache_settings']['enableCache'], 'Post-save reads must observe the write.' );
		$this->assertSame( true, $second['cache_settings']['wooSafeMode'], 'Backfills must apply on the re-resolved snapshot too.' );
	}

	/**
	 * A settings write invalidates the singleton memo (same-request coherence).
	 *
	 * Old and new values are identical so no cache-clear/htaccess side
	 * effects fire — only the memo invalidation at the top of
	 * `Main::on_settings_update()` is exercised.
	 */
	public function test_on_settings_update_invalidates_singleton_memo(): void {
		$calls = 0;
		$this->stub_settings_by_blog( array( 1 => array() ), $calls );

		$main = $this->make_main();
		$main->get_options();

		$reflection    = new \ReflectionClass( Main::class );
		$instance_prop = $reflection->getProperty( 'instance' );
		$instance_prop->setValue( null, $main );
		try {
			$same = array( 'cache_settings' => array( 'enableCache' => true ) );
			Main::on_settings_update( $same, $same );

			$options_prop = $reflection->getProperty( 'options' );
			$this->assertNull( $options_prop->getValue( $main ), 'Settings write must drop the Main options memo.' );
		} finally {
			Main::reset_instance();
		}
	}

	/**
	 * Directly injected snapshots (tests/back-compat) are returned verbatim.
	 */
	public function test_directly_injected_options_returned_verbatim(): void {
		$calls = 0;
		$this->stub_settings_by_blog( array( 1 => array() ), $calls );

		$main       = $this->make_main();
		$reflection = new \ReflectionClass( Main::class );
		$property   = $reflection->getProperty( 'options' );
		$injected   = array( 'cache_settings' => array( 'enableCache' => true ) );
		$property->setValue( $main, $injected );

		$this->assertSame( $injected, $main->get_options(), 'Injected snapshots must not be re-resolved.' );
		$this->assertSame( 0, $calls, 'Injected snapshots must not trigger I/O.' );
	}
}
