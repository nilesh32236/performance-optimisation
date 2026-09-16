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
	 * Note: Critical_CSS::is_elementor_context() treats any non-empty
	 * _elementor_edit_mode as an Elementor context, so the Main-level strict
	 * check is what keeps stale values from disabling combine.
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
		$method->setAccessible( true );

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
 */
class WPPO_Test_Elementor_Counting_Watcher extends Builder_Purge_Watcher {
	/**
	 * Purged post IDs in call order.
	 *
	 * @var int[]
	 */
	public $purged_posts = array();

	/**
	 * Record instead of touching the filesystem.
	 *
	 * @param int $post_id Post ID whose cache must be purged.
	 */
	protected function purge_post_static_cache( int $post_id ): void {
		$this->purged_posts[] = $post_id;
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
