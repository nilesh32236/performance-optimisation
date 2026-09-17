<?php
/**
 * Tests for Elementor-safe mode (issue #1259).
 *
 * Covers safe-mode defaults (absent key = enabled), the strict
 * `_elementor_edit_mode === 'builder'` check in both detection branches,
 * the centralized native pre-gate, numeric/object post-ID resolution
 * (floats rejected), the per-request purge dedupe shared by both handlers,
 * and the bool normalization of `elementorSafeMode` in the generic
 * sanitizer.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Builder_Purge_Watcher;
use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests for Elementor-safe mode.
 *
 * @package PerformanceOptimise\Tests
 */
class ElementorSafeModeTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Set up Brain Monkey and common stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		Main::reset_elementor_memo();
		Builder_Purge_Watcher::reset_elementor_purge_memo();
		Builder_Purge_Watcher::reset_drift_state();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 0 );
		Functions\when( 'get_the_ID' )->justReturn( false );
		// Util::get_settings() registers cache hooks on first read; stub so
		// the test is order-independent (add_action may not exist yet when
		// this file runs before suites that stub it).
		Functions\when( 'add_action' )->justReturn( true );
	}

	/**
	 * Tear down Brain Monkey and per-request memos.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		unset( $_GET['elementor-preview'] );
		Main::reset_elementor_memo();
		Builder_Purge_Watcher::reset_elementor_purge_memo();
		Builder_Purge_Watcher::reset_drift_state();
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stub get_post_meta() for a fixed builder-meta fixture.
	 *
	 * @param string $data      Value for _elementor_data.
	 * @param string $edit_mode Value for _elementor_edit_mode.
	 */
	private function stub_builder_meta( string $data, string $edit_mode ): void {
		Functions\when( 'get_post_meta' )->alias(
			static function ( $post_id, $key, $single ) use ( $data, $edit_mode ) {
				unset( $post_id, $single );
				if ( '_elementor_data' === $key ) {
					return $data;
				}
				if ( '_elementor_edit_mode' === $key ) {
					return $edit_mode;
				}
				return '';
			}
		);
	}

	/**
	 * Absent key means default-on; explicit false stays off.
	 */
	public function test_safe_mode_defaults_on_for_absent_key(): void {
		$this->assertTrue( Main::is_elementor_safe_mode_active( array( 'elementorSafeMode' => true ) ) );
		$this->assertFalse( Main::is_elementor_safe_mode_active( array( 'elementorSafeMode' => false ) ) );
		// Absent key with empty stored settings must read as enabled.
		$this->assertTrue( Main::is_elementor_safe_mode_active( array() ) );
	}

	/**
	 * Strict 'builder' comparison: stale non-builder edit_mode values must not bypass.
	 *
	 * Note: Critical_CSS::is_elementor_context() delegates meta verdicts to
	 * the memoized Main detector (with a strict minimal-boot fallback), so
	 * both pipelines share this predicate and cannot drift.
	 */
	public function test_strict_builder_meta_check(): void {
		$this->stub_builder_meta( '', 'yes' );
		$this->assertFalse( Main::is_elementor_built_page( 42 ), 'Stale non-builder edit_mode must not bypass.' );

		Main::reset_elementor_memo();
		$this->stub_builder_meta( '', 'builder' );
		$this->assertTrue( Main::is_elementor_built_page( 42 ), 'Strict builder edit_mode must bypass.' );

		Main::reset_elementor_memo();
		$this->stub_builder_meta( '[{"id":"abc"}]', '' );
		$this->assertTrue( Main::is_elementor_built_page( 42 ), 'Non-empty _elementor_data must bypass.' );
	}

	/**
	 * The combine-skip helper threads an explicit post ID.
	 */
	public function test_should_skip_threads_post_id(): void {
		$this->stub_builder_meta( '', 'builder' );
		$this->assertTrue( Main::should_skip_combine_for_elementor( array( 'elementorSafeMode' => true ), 42 ) );
		$this->assertFalse( Main::should_skip_combine_for_elementor( array( 'elementorSafeMode' => false ), 42 ) );

		Main::reset_elementor_memo();
		$this->stub_builder_meta( '', '' );
		$this->assertFalse( Main::should_skip_combine_for_elementor( array( 'elementorSafeMode' => true ), 42 ) );
	}

	/**
	 * Native pre-gate is false by default and true for preview query vars.
	 */
	public function test_looks_like_elementor_request(): void {
		$this->assertFalse( Main::looks_like_elementor_request() );

		$_GET['elementor-preview'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test fixture for read-only routing check.
		$this->assertTrue( Main::looks_like_elementor_request() );
		unset( $_GET['elementor-preview'] );
	}

	/**
	 * Bare preview query vars require Elementor markers (no perf kill-switch).
	 */
	public function test_preview_query_requires_plugin_markers(): void {
		$_GET['elementor-preview'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test fixture for read-only routing check.
		$this->assertFalse( Main::is_elementor_built_page( null ), 'Bare preview on a non-Elementor site must not disable combine.' );
		unset( $_GET['elementor-preview'] );
	}

	/**
	 * Memoized verdicts are stable per request and resettable.
	 */
	public function test_built_page_memo(): void {
		$this->stub_builder_meta( '', 'builder' );
		$this->assertTrue( Main::is_elementor_built_page( 42 ) );
		// A second call with the same post must reuse the memo (no fatal even
		// when stubs are unchanged).
		$this->assertTrue( Main::is_elementor_built_page( 42 ) );
		Main::reset_elementor_memo();
		$this->assertTrue( Main::is_elementor_built_page( 42 ) );
	}

	/**
	 * Post-ID resolution accepts ints, digit strings, and objects; rejects floats.
	 */
	public function test_resolve_elementor_post_id(): void {
		$watcher = new Builder_Purge_Watcher();
		$method  = new \ReflectionMethod( Builder_Purge_Watcher::class, 'resolve_elementor_post_id' );

		$this->assertSame( 42, $method->invoke( $watcher, 42 ) );
		$this->assertSame( 42, $method->invoke( $watcher, '42' ) );
		$this->assertSame( 42, $method->invoke( $watcher, ' 42 ' ) );
		$this->assertSame( 0, $method->invoke( $watcher, '12.9' ), 'Float-like strings must not truncate to a wrong post.' );
		$this->assertSame( 0, $method->invoke( $watcher, 12.9 ), 'Floats must not truncate to a wrong post.' );
		$this->assertSame( 0, $method->invoke( $watcher, 0 ) );
		$this->assertSame( 0, $method->invoke( $watcher, -5 ) );
		$this->assertSame( 0, $method->invoke( $watcher, null ) );
		$this->assertSame( 0, $method->invoke( $watcher, array( 42 ) ) );

		$css_file = new WPPO_Test_Elementor_Css_File( 77 );
		$this->assertSame( 77, $method->invoke( $watcher, $css_file ) );

		// Second action payload is the fallback when the first is unresolvable.
		$this->assertSame( 77, $method->invoke( $watcher, null, 77 ) );
		$this->assertSame( 77, $method->invoke( $watcher, null, '77' ) );
		$this->assertSame( 0, $method->invoke( $watcher, null, '12.9' ) );
		// First payload wins when both resolve.
		$this->assertSame( 42, $method->invoke( $watcher, 42, 77 ) );
	}

	/**
	 * Object get_post_id() values route through the same float rejection.
	 */
	public function test_resolve_rejects_float_object_post_id(): void {
		$watcher = new Builder_Purge_Watcher();
		$method  = new \ReflectionMethod( Builder_Purge_Watcher::class, 'resolve_elementor_post_id' );

		$float_css = new class() {
			/**
			 * Float post ID fixture mirroring a malformed Elementor payload.
			 *
			 * @return float Post ID that must never truncate to a real post.
			 */
			public function get_post_id(): float {
				return 12.9;
			}
		};
		$this->assertSame( 0, $method->invoke( $watcher, $float_css ), 'Float object post IDs must not truncate to a wrong post.' );

		$string_float_css = new class() {
			/**
			 * Float-like string post ID fixture.
			 *
			 * @return string Post ID that must never truncate to a real post.
			 */
			public function get_post_id(): string {
				return '12.9';
			}
		};
		$this->assertSame( 0, $method->invoke( $watcher, $string_float_css ), 'Float-like string object post IDs must not truncate.' );
	}

	/**
	 * Bulk regen coalesces past the distinct-post threshold.
	 */
	public function test_bulk_regen_coalesces_after_threshold(): void {
		$watcher = new WPPO_Test_Elementor_Counting_Watcher();

		for ( $post_id = 101; $post_id <= 106; $post_id++ ) {
			$watcher->on_elementor_css_regen( $post_id );
		}
		$this->assertSame( array( 101, 102, 103, 104, 105, 106 ), $watcher->purged_posts );

		$flag = new \ReflectionProperty( Builder_Purge_Watcher::class, 'bulk_regen_coalesced' );
		$this->assertTrue( $flag->getValue(), 'Six distinct posts must trip bulk-regen coalescing (archive fan-out + targeted regen).' );
	}

	/**
	 * Unresolvable regen payloads fall back to the deferred drift purge.
	 */
	public function test_unresolvable_regen_falls_back_to_drift(): void {
		$watcher = new WPPO_Test_Elementor_Counting_Watcher();
		$watcher->on_elementor_css_regen( null );
		$this->assertSame( array(), $watcher->purged_posts, 'Unresolvable payloads must not purge a wrong URL.' );

		$drifted = new \ReflectionProperty( Builder_Purge_Watcher::class, 'drift_handled_this_request' );
		$this->assertTrue( $drifted->getValue(), 'Unresolvable regen must schedule the deferred full purge.' );
		Builder_Purge_Watcher::reset_drift_state();
		$this->assertFalse( $drifted->getValue(), 'Named drift reset must clear the flag without reflection writes.' );
	}

	/**
	 * Posts past the bulk threshold skip per-post file I/O (deferred owns them).
	 */
	public function test_coalesced_posts_skip_per_post_io(): void {
		$watcher = new WPPO_Test_Elementor_Counting_Watcher();

		for ( $post_id = 201; $post_id <= 206; $post_id++ ) {
			$watcher->on_elementor_css_regen( $post_id );
		}
		$this->assertSame( array( 201, 202, 203, 204, 205, 206 ), $watcher->purged_posts );

		// Post 7+: deferred full purge already scheduled — no more file I/O,
		// but the dedupe set still records the post.
		$watcher->on_elementor_css_regen( 207 );
		$this->assertSame( array( 201, 202, 203, 204, 205, 206 ), $watcher->purged_posts, 'Coalesced posts must skip per-post file I/O.' );

		$watcher->on_builder_drift_save( 208, array() );
		$this->assertSame( array( 201, 202, 203, 204, 205, 206 ), $watcher->purged_posts, 'Coalesced editor saves must skip per-post file I/O.' );
	}

	/**
	 * The shared Cache bypass choke point skips combine on builder pages only.
	 *
	 * Note: the native pre-gate (no Elementor class/constant on the test
	 * bench) short-circuits non-Elementor requests before any meta read, so
	 * the positive case rides the `?elementor-preview` pre-gate signal plus
	 * strict per-post builder meta — the same combination a real preview
	 * request on an Elementor site presents.
	 */
	public function test_cache_bypass_choke_point(): void {
		$cache   = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();
		$options = new \ReflectionProperty( Cache::class, 'options' );
		$options->setValue( $cache, array( 'file_optimisation' => array( 'elementorSafeMode' => true ) ) );
		$method = new \ReflectionMethod( Cache::class, 'should_bypass_combine_for_elementor' );

		// Non-Elementor request: no bypass (pre-gate short-circuits, no meta reads).
		$this->assertFalse( $method->invoke( $cache, array( 'elementorSafeMode' => true ) ) );

		// Bare preview query var on a non-builder post: still no bypass
		// (preview bypass is gated on per-post builder meta).
		$this->stub_builder_meta( '', '' );
		Functions\when( 'get_queried_object_id' )->justReturn( 42 );
		$_GET['elementor-preview'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test fixture for read-only routing check.
		Main::reset_elementor_memo();
		$this->assertFalse( $method->invoke( $cache, array( 'elementorSafeMode' => true ) ), 'Bare preview on a non-builder post must not bypass.' );

		// Same preview signal on a builder-built post with safe mode on: bypass.
		$this->stub_builder_meta( '', 'builder' );
		Main::reset_elementor_memo();
		$this->assertTrue( $method->invoke( $cache, array( 'elementorSafeMode' => true ) ), 'Builder-built pages must bypass combine.' );

		// Same builder page with safe mode off: no bypass.
		Main::reset_elementor_memo();
		$this->assertFalse( $method->invoke( $cache, array( 'elementorSafeMode' => false ) ), 'Safe mode off must not bypass.' );

		unset( $_GET['elementor-preview'] );
		Functions\when( 'get_queried_object_id' )->justReturn( 0 );
		Main::reset_elementor_memo();
	}

	/**
	 * Loop fallback applies on singular views only, never on archives/home.
	 */
	public function test_loop_fallback_is_singular_only(): void {
		$this->stub_builder_meta( '', 'builder' );
		Functions\when( 'get_the_ID' )->justReturn( 99 );
		Functions\when( 'is_singular' )->justReturn( false );

		$this->assertFalse( Main::is_elementor_built_page( null ), 'Archives/home must not inherit a loop member builder verdict.' );

		Main::reset_elementor_memo();
		Functions\when( 'is_singular' )->justReturn( true );
		$this->assertTrue( Main::is_elementor_built_page( null ), 'Singular loop fallback must still bypass for builder posts.' );
	}

	/**
	 * Staged elementorSafeMode fail-safes to true like production.
	 */
	public function test_sandbox_staged_safe_mode_fails_safe_true(): void {
		$garbage = \PerformanceOptimise\Inc\Sandbox_Preview::sanitize_staged( array( 'elementorSafeMode' => 'maybe' ) );
		$this->assertTrue( $garbage['elementorSafeMode'], 'Garbage staged values must preview with protection ON.' );

		$off = \PerformanceOptimise\Inc\Sandbox_Preview::sanitize_staged( array( 'elementorSafeMode' => 'false' ) );
		$this->assertFalse( $off['elementorSafeMode'] );
	}

	/**
	 * Regen + editor-save share one per-request dedupe set.
	 */
	public function test_elementor_purge_dedupe_shared_across_handlers(): void {
		$watcher = new WPPO_Test_Elementor_Counting_Watcher();

		$watcher->on_elementor_css_regen( 42 );
		$this->assertSame( array( 42 ), $watcher->purged_posts );

		// Bulk regen for the same post is skipped.
		$watcher->on_elementor_css_regen( 42 );
		$this->assertSame( array( 42 ), $watcher->purged_posts );

		// Editor save for the same post shares the dedupe set.
		$watcher->on_builder_drift_save( 42, array() );
		$this->assertSame( array( 42 ), $watcher->purged_posts );

		// A different post still purges.
		$watcher->on_elementor_css_regen( 43 );
		$this->assertSame( array( 42, 43 ), $watcher->purged_posts );
	}

	/**
	 * The watcher registers parse_after with two accepted args.
	 */
	public function test_parse_after_registered_with_two_args(): void {
		$calls = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$calls ) {
				$calls[] = array( $hook, $args );
			}
		);
		( new Builder_Purge_Watcher() )->register();

		$found = false;
		foreach ( $calls as $call ) {
			if ( 'elementor/css-file/post/parse_after' === $call[0] && 2 === $call[1] ) {
				$found = true;
			}
		}
		$this->assertTrue( $found, 'Expected elementor/css-file/post/parse_after registration with 2 args.' );
	}

	/**
	 * Generic sanitizer normalizes form-encoded elementorSafeMode strings.
	 */
	public function test_sanitize_elementor_safe_mode_bool(): void {
		$off = Util::sanitize_settings_recursively( array( 'elementorSafeMode' => 'false' ) );
		$this->assertFalse( $off['elementorSafeMode'] );

		$on = Util::sanitize_settings_recursively( array( 'elementorSafeMode' => 'true' ) );
		$this->assertTrue( $on['elementorSafeMode'] );

		$bool_off = Util::sanitize_settings_recursively( array( 'elementorSafeMode' => false ) );
		$this->assertFalse( $bool_off['elementorSafeMode'] );

		// Unrecognized values fail-safe to ON (absent key = enabled).
		$garbage = Util::sanitize_settings_recursively( array( 'elementorSafeMode' => 'maybe' ) );
		$this->assertTrue( $garbage['elementorSafeMode'] );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Test-file Elementor doubles.

/**
 * Minimal Elementor CSS-file double exposing get_post_id().
 */
class WPPO_Test_Elementor_Css_File {
	/**
	 * Post ID fixture.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Constructor.
	 *
	 * @param int $post_id Post ID fixture.
	 */
	public function __construct( int $post_id ) {
		$this->post_id = $post_id;
	}

	/**
	 * Post ID accessor mirroring Elementor's CSS-file API.
	 *
	 * @return int Post ID.
	 */
	public function get_post_id(): int {
		return $this->post_id;
	}
}

/**
 * Counting watcher double: records purge_post_static_cache() calls.
 *
 * The Used-CSS fan-out is stubbed to a recorded no-op so scheduler writes
 * are never exercised (unit scope stops at the watcher seam).
 */
class WPPO_Test_Elementor_Counting_Watcher extends Builder_Purge_Watcher {
	/**
	 * Purged post IDs in call order.
	 *
	 * @var int[]
	 */
	public $purged_posts = array();

	/**
	 * Requeued post IDs in call order.
	 *
	 * @var int[]
	 */
	public $requeued_posts = array();

	/**
	 * Record instead of touching the filesystem.
	 *
	 * @param int  $post_id Post ID whose cache must be purged.
	 * @param bool $bump_stats Whether stats would be bumped (unused).
	 */
	protected function purge_post_static_cache( int $post_id, bool $bump_stats = true ): void {
		unset( $bump_stats );
		$this->purged_posts[] = $post_id;
	}

	/**
	 * Record instead of touching the scheduler.
	 *
	 * @param int $post_id Post ID whose Used-CSS would be requeued.
	 * @return bool Always true (job recorded as queued).
	 */
	protected function requeue_used_css_for_elementor_post( int $post_id ): bool {
		$this->requeued_posts[] = $post_id;
		return true;
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
