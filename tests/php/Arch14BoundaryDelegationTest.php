<?php
/**
 * Regression tests for ARCH-014 boundary-internal Util:: caller migration (issue #1554).
 *
 * Pins that the boundary classes call their owning boundaries directly
 * (Filesystem/Scheduler/Woo_Detect -> Settings_Store/Cache_Key/Url) with
 * byte-identical behavior to the pre-migration Util:: facade path:
 *
 * - Filesystem::is_purge_fallback_enabled() reads via Settings_Store.
 * - Filesystem::purge_fallback_should_log()/log_css_fallback() key via Cache_Key.
 * - Woo_Detect::is_woo_safe_mode_enabled() reads via Settings_Store.
 * - Woo_Detect::woo_cache_self_test() builds URLs via Url (result identical
 *   to the Util:: facade proxy).
 * - Scheduler::is_stampede_guard_enabled()/stampede_lock_ttl() read via Settings_Store.
 * - Multisite memo parity: Settings_Store::get_settings() and
 *   Cache_Key::transient_key() stay blog-isolated, proxy or direct.
 * - STAY-canonical edges still route to Util:: (compute_css_checksum,
 *   has_uncacheable_query, is_editor_preview_url).
 *
 * @package PerformanceOptimise\Tests
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Cache_Key;
use PerformanceOptimise\Inc\Filesystem;
use PerformanceOptimise\Inc\Scheduler;
use PerformanceOptimise\Inc\Settings_Store;
use PerformanceOptimise\Inc\Url;
use PerformanceOptimise\Inc\Util;
use PerformanceOptimise\Inc\Woo_Detect;

/**
 * ARCH-014 boundary delegation tests.
 *
 * @package PerformanceOptimise\Tests
 */
class Arch14BoundaryDelegationTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Set up Brain Monkey and common stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->register_common_function_stubs();
		// Own setUp() shadows the trait setUp(): reset memos explicitly
		// (same pattern as WooFacetedSelfTestProofTest).
		Util::reset_runtime_caches();
		Settings_Store::clear_settings_cache();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				return $value;
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $url ) {
				return rtrim( (string) $url, '/' );
			}
		);
		Url::reset_cached_home_urls();
		unset( $_SERVER['QUERY_STRING'] );
	}

	/**
	 * Tear down Brain Monkey and superglobal fixtures.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		unset( $_SERVER['QUERY_STRING'] );
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Filesystem purge-fallback gate reads settings via Settings_Store.
	 */
	public function test_filesystem_purge_fallback_reads_settings_store(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $option, $fallback = array() ) {
				if ( 'wppo_settings' === $option ) {
					return array( 'file_optimisation' => array( 'purgeFallbackEnabled' => true ) );
				}
				return $fallback;
			}
		);
		Filesystem::clear_purge_fallback_memo();
		Settings_Store::clear_settings_cache();

		$this->assertTrue( Filesystem::is_purge_fallback_enabled() );
		$this->assertSame( Settings_Store::get_settings(), Util::get_settings() );

		Functions\when( 'get_option' )->alias(
			static function ( $option, $fallback = array() ) {
				if ( 'wppo_settings' === $option ) {
					return array( 'file_optimisation' => array( 'purgeFallbackEnabled' => false ) );
				}
				return $fallback;
			}
		);
		Filesystem::clear_purge_fallback_memo();
		Settings_Store::clear_settings_cache();

		$this->assertFalse( Filesystem::is_purge_fallback_enabled() );
	}

	/**
	 * Filesystem transient keys match Cache_Key output, multisite-prefixed.
	 */
	public function test_filesystem_transient_key_matches_cache_key_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 2 );
		Functions\when( 'get_option' )->justReturn( array() );
		$store = array();
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$store ) {
				$k = (string) $key;
				return array_key_exists( $k, $store ) ? $store[ $k ][0] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value, $expiration = 0 ) use ( &$store ) {
				$store[ (string) $key ] = array( $value, (int) $expiration );
				return true;
			}
		);

		$this->assertTrue( Filesystem::purge_fallback_should_log() );
		$this->assertFalse( Filesystem::purge_fallback_should_log() );

		$expected = Cache_Key::transient_key( 'wppo_purge_fallback_served' );
		$this->assertSame( '2_wppo_purge_fallback_served', $expected );
		$this->assertSame( $expected, Util::transient_key( 'wppo_purge_fallback_served' ) );
		$this->assertArrayHasKey( $expected, $store );
	}

	/**
	 * Woo safe-mode gate reads settings via Settings_Store.
	 */
	public function test_woo_safe_mode_reads_settings_store(): void {
		Functions\when( 'get_option' )->justReturn( array( 'cache_settings' => array( 'wooSafeMode' => false ) ) );
		Settings_Store::clear_settings_cache();

		$this->assertFalse( Woo_Detect::is_woo_safe_mode_enabled() );
		$this->assertFalse( Woo_Detect::is_woo_safe_mode_enabled( Util::get_settings() ) );

		Functions\when( 'get_option' )->justReturn( array() );
		Settings_Store::clear_settings_cache();

		$this->assertTrue( Woo_Detect::is_woo_safe_mode_enabled() );
	}

	/**
	 * Woo self-test via the boundary matches the Util facade proxy exactly.
	 *
	 * Exercises every migrated Url::cached_home_url edge (cart/checkout,
	 * fragments, editor probes, preload probes, guest-cart probes) plus the
	 * Util-canonical has_uncacheable_query/is_editor_preview_url STAY edges.
	 */
	public function test_woo_self_test_boundary_matches_facade(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Settings_Store::clear_settings_cache();
		Url::reset_cached_home_urls();

		$direct = Woo_Detect::woo_cache_self_test();

		Url::reset_cached_home_urls();
		$via_facade = Util::woo_cache_self_test();

		$this->assertSame( $via_facade, $direct );
		$this->assertTrue( $direct['all_pass'] );
		$this->assertFalse( $direct['force_exclude'] );

		foreach ( array( 'checks', 'fragment_checks', 'editor_checks', 'preload_checks', 'cart_checks' ) as $group ) {
			$this->assertNotEmpty( $direct[ $group ], $group . ' must be populated.' );
			foreach ( $direct[ $group ] as $check ) {
				$this->assertStringStartsWith( 'http://example.com', (string) $check['url'], $group . ' URL must use the home URL.' );
			}
		}
	}

	/**
	 * Scheduler guard toggle and lock TTL read settings via Settings_Store.
	 */
	public function test_scheduler_guard_and_ttl_read_settings_store(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'cache_settings' => array(
					'stampedeGuard'   => false,
					'stampedeLockTtl' => 3,
				),
			)
		);
		Settings_Store::clear_settings_cache();

		$this->assertFalse( Scheduler::is_stampede_guard_enabled() );
		$this->assertSame( 3, Scheduler::stampede_lock_ttl() );

		Functions\when( 'get_option' )->justReturn( array() );
		Settings_Store::clear_settings_cache();

		$this->assertTrue( Scheduler::is_stampede_guard_enabled() );
		$this->assertSame( 5, Scheduler::stampede_lock_ttl() );
	}

	/**
	 * Settings memo and transient keys stay blog-isolated, direct or via proxy.
	 */
	public function test_settings_and_transient_key_multisite_parity(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			static function ( $option, $fallback = array() ) {
				if ( 'wppo_settings' === $option ) {
					return array( 'blog' => get_current_blog_id() );
				}
				return $fallback;
			}
		);

		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		$this->assertSame( array( 'blog' => 1 ), Settings_Store::get_settings() );
		$this->assertSame( Settings_Store::get_settings(), Util::get_settings() );
		$this->assertSame( '1_wppo_x', Cache_Key::transient_key( 'wppo_x' ) );
		$this->assertSame( Cache_Key::transient_key( 'wppo_x' ), Util::transient_key( 'wppo_x' ) );

		Functions\when( 'get_current_blog_id' )->justReturn( 2 );
		$this->assertSame( array( 'blog' => 2 ), Settings_Store::get_settings() );
		$this->assertSame( Settings_Store::get_settings(), Util::get_settings() );
		$this->assertSame( '2_wppo_x', Cache_Key::transient_key( 'wppo_x' ) );
		$this->assertSame( Cache_Key::transient_key( 'wppo_x' ), Util::transient_key( 'wppo_x' ) );
	}

	/**
	 * Util-canonical STAY edges still execute through Util.
	 */
	public function test_util_canonical_stays(): void {
		$this->assertSame( hash( 'sha256', 'body' ), Util::compute_css_checksum( 'body' ) );
		$this->assertSame( '', Util::compute_css_checksum( '' ) );

		$this->assertTrue( Util::has_uncacheable_query( 's=hello' ) );
		$this->assertFalse( Util::has_uncacheable_query( 'utm_source=x' ) );
		$this->assertTrue( Util::is_editor_preview_url( 'http://example.com/?preview=true' ) );

		// STAY call site: generic query guard inside Woo_Detect.
		$this->assertTrue( Woo_Detect::is_woo_excluded_url( 'http://example.com/?s=hello', 's=hello' ) );
	}
}
