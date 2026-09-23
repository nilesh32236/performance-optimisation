<?php
/**
 * Parity tests for the ARCH-005 Script_Strategy extraction (issue #1536).
 *
 * Proves the defer/delay cluster moved from `Main` to
 * `PerformanceOptimise\Inc\Script_Strategy` without behavior change:
 * hook-callback identity stays on `Main` (facade proxies), tag output and
 * exclusion decisions agree between the proxies and the new owner,
 * preset-data providers are identical, the shared delay-context memo
 * hits/misses/resets through either entry point, and the blog-keyed
 * request signature (multisite correction) prevents cross-site verdict
 * leakage for the same URI on different blogs.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Loader_Map;
use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Script_Strategy;
use PerformanceOptimise\Inc\LiteSpeed_Integration;
use Brain\Monkey\Functions;

/**
 * ARCH-005 Script_Strategy parity tests.
 *
 * @package PerformanceOptimise\Tests
 */
class ScriptStrategyParityTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap {
		setUp as protected wppoSetUp;
		tearDown as protected wppoTearDown;
	}

	/**
	 * Fresh memo + LiteSpeed state per test (mirrors MainDelayDeferTest).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->wppoSetUp();
		Main::reset_delay_context_memo();
		LiteSpeed_Integration::reset_cache();
	}

	/**
	 * No memo or superglobal leakage into later suites.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Main::reset_delay_context_memo();
		LiteSpeed_Integration::reset_cache();
		unset( $_SERVER['REQUEST_URI'], $_SERVER['QUERY_STRING'] );
		unset( $_GET['wc-ajax'], $_GET['add-to-cart'] );
		unset( $GLOBALS['wp_version'] );
		$this->wppoTearDown();
	}

	/**
	 * Stub the WP environment needed to construct Main (mirrors
	 * MainDelayDeferTest::stub_main_construction()).
	 *
	 * @param array $file_overrides file_optimisation option overrides.
	 * @return void
	 */
	private function stub_main_construction( array $file_overrides ): void {
		Functions\stubs(
			array(
				'WP_Filesystem'       => false,
				'sanitize_text_field' => '',
				'wp_unslash'          => '',
				'is_user_logged_in'   => false,
			)
		);
		Functions\when( 'absint' )->alias(
			static function ( $maybeint ) {
				return (int) $maybeint;
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( $option, $default_value = false ) use ( $file_overrides ) {
				if ( 'wppo_settings' === $option && is_array( $default_value ) ) {
					$default_value['file_optimisation'] = array_merge( $default_value['file_optimisation'] ?? array(), $file_overrides );
				}
				return $default_value;
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'content_url' )->returnArg();
		Functions\when( 'trailingslashit' )->returnArg();
		Functions\when( 'wp_is_block_theme' )->justReturn( false );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'wppo_litespeed_should_disable_optimizer' === $hook ) {
					return false;
				}
				return $value;
			}
		);
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'get_the_ID' )->justReturn( 0 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'has_block' )->justReturn( false );
		Functions\when( 'is_wc_endpoint_url' )->justReturn( false );
		Functions\when( 'function_exists' )->alias(
			static function ( $function_name ) {
				if ( 'WP_Filesystem' === $function_name || 'wp_is_block_theme' === $function_name ) {
					return true;
				}
				return \function_exists( $function_name );
			}
		);
	}

	/**
	 * Passthrough request sanitizers for signature/memo tests.
	 *
	 * @return void
	 */
	private function stub_guard_request_env(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
	}

	/**
	 * Hook-registered callbacks keep their identity on Main (facade rule).
	 *
	 * @return void
	 */
	public function test_hook_callbacks_stay_on_main(): void {
		foreach ( array(
			'add_defer_strategy',
			'add_defer_attribute',
			'add_defer_attribute_legacy',
			'add_fetchpriority_to_deferred',
			'apply_per_page_delay_config',
			'remove_woocommerce_scripts',
		) as $method ) {
			$this->assertTrue( method_exists( Main::class, $method ), "Main::{$method}() must exist for Hook_Registry" );
		}
		foreach ( array(
			'is_delay_excluded_context',
			'reset_delay_context_memo',
			'reset_delay_third_party_auto_cache',
			'should_use_core_template_buffer',
			'supports_native_defer_strategy',
			'supports_native_script_fetchpriority',
		) as $method ) {
			$this->assertTrue( method_exists( Main::class, $method ), "Main::{$method}() proxy must exist" );
			$this->assertTrue( method_exists( Script_Strategy::class, $method ), "Script_Strategy::{$method}() must exist" );
		}
	}

	/**
	 * Loader_Map resolves the new owner through the single loader boundary.
	 *
	 * @return void
	 */
	public function test_loader_map_resolves_script_strategy(): void {
		$map = Loader_Map::fallback_map();
		$this->assertSame( 'class-script-strategy.php', $map['Script_Strategy'] );
		$this->assertFileExists( (string) Loader_Map::path_for( 'Script_Strategy' ) );
		$this->assertFileExists( (string) Loader_Map::path_for( 'PerformanceOptimise\Inc\Script_Strategy' ) );
	}

	/**
	 * Defer tag output agrees between Main proxies and the new owner,
	 * on both the delay path and the legacy (WP 6.2) path.
	 *
	 * @return void
	 */
	public function test_defer_tag_output_parity(): void {
		$this->stub_main_construction(
			array(
				'deferJS' => true,
				'delayJS' => true,
			)
		);

		$main     = new Main();
		$strategy = new Script_Strategy( $main );

		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for defer parity tests.
		$tags = array(
			'plain'      => '<script src="https://example.com/app.js" id="app-js"></script>',
			'deferred'   => '<script src="https://example.com/app.js" defer></script>',
			'data-block' => '<script type="application/json" src="https://example.com/data.js"></script>',
		);
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript

		foreach ( $tags as $label => $tag ) {
			$this->assertSame( $strategy->add_defer_attribute( $tag, 'app' ), $main->add_defer_attribute( $tag, 'app' ), "delay path parity: {$label}" );
			$this->assertSame( $strategy->add_defer_attribute_legacy( $tag, 'app' ), $main->add_defer_attribute_legacy( $tag, 'app' ), "legacy path parity: {$label}" );
		}

		// Legacy stamps exactly one defer on a plain tag; the delay path
		// rewrites it for lazy loading instead.
		$this->assertSame( 1, substr_count( $main->add_defer_attribute_legacy( $tags['plain'], 'app' ), ' defer' ) );
		$this->assertStringContainsString( 'wppo-src=', $main->add_defer_attribute( $tags['plain'], 'app' ) );
	}

	/**
	 * Delay exclusion decisions agree between the Main proxy surface and
	 * the new owner.
	 *
	 * @return void
	 */
	public function test_delay_exclusion_decisions_parity(): void {
		$this->stub_main_construction(
			array(
				'delayJS'         => true,
				'excludeDelayJS'  => 'my-excluded-handle',
				'delayJSIdleList' => 'idle-handle',
			)
		);

		$main     = new Main();
		$strategy = new Script_Strategy( $main );

		$probe = new \ReflectionMethod( Main::class, 'is_delay_excluded_handle' );
		foreach ( array( 'my-excluded-handle', 'my-excluded-handle-child', 'idle-handle', 'unrelated-handle' ) as $handle ) {
			$this->assertSame(
				$strategy->is_delay_excluded_handle( $handle ),
				$probe->invoke( $main, $handle ),
				"exclusion parity: {$handle}"
			);
		}

		$strategy_probe = new \ReflectionMethod( Main::class, 'get_delay_strategy_for_handle' );
		foreach ( array( 'idle-handle', 'plain-handle' ) as $handle ) {
			$this->assertSame(
				$strategy->get_delay_strategy_for_handle( $handle ),
				$strategy_probe->invoke( $main, $handle ),
				"strategy parity: {$handle}"
			);
		}
	}

	/**
	 * Curated preset/exclusion data is identical through both entry points.
	 *
	 * @return void
	 */
	public function test_preset_data_identity(): void {
		$this->assertSame( Script_Strategy::get_delay_js_builder_exclusions(), Main::get_delay_js_builder_exclusions() );
		$this->assertSame( Script_Strategy::get_delay_js_commerce_exclusions(), Main::get_delay_js_commerce_exclusions() );
		$this->assertSame( Script_Strategy::get_delay_js_slider_exclusions(), Main::get_delay_js_slider_exclusions() );
		$this->assertSame( Script_Strategy::get_delay_js_interaction_exclusions(), Main::get_delay_js_interaction_exclusions() );
		$this->assertSame( Script_Strategy::get_delay_js_preset_levels(), Main::get_delay_js_preset_levels() );
		$this->assertSame( Script_Strategy::get_delay_js_consent_exclusions(), Main::get_delay_js_consent_exclusions() );
		$this->assertSame( Script_Strategy::get_delay_js_analytics_exclusions(), Main::get_delay_js_analytics_exclusions() );
		$this->assertSame( Script_Strategy::get_delay_js_gallery_exclusions(), Main::get_delay_js_gallery_exclusions() );
		$this->assertSame( Script_Strategy::get_delay_js_jquery_exclusions(), Main::get_delay_js_jquery_exclusions() );
		$this->assertSame( Script_Strategy::get_delay_js_compat_preset_map(), Main::get_delay_js_compat_preset_map() );
		$this->assertSame( Script_Strategy::get_delay_js_third_party_denylist(), Main::get_delay_js_third_party_denylist() );
		$this->assertSame( Script_Strategy::get_delay_js_base_preset_exclusions(), Main::get_delay_js_base_preset_exclusions() );
		$this->assertSame( Script_Strategy::get_delay_js_third_party_auto_categories(), Main::get_delay_js_third_party_auto_categories() );
		$this->assertSame( Script_Strategy::get_delay_js_third_party_auto_patterns(), Main::get_delay_js_third_party_auto_patterns() );
		foreach ( Script_Strategy::get_delay_js_preset_levels() as $level ) {
			$this->assertSame( Script_Strategy::get_delay_js_preset_level_settings( $level ), Main::get_delay_js_preset_level_settings( $level ), "preset settings: {$level}" );
			$this->assertSame( Script_Strategy::get_delay_js_preset_level_exclusions( $level ), Main::get_delay_js_preset_level_exclusions( $level ), "preset exclusions: {$level}" );
		}
		foreach ( Script_Strategy::get_delay_js_compat_preset_map() as $slug ) {
			$this->assertSame( Script_Strategy::get_delay_js_compat_preset_exclusions( (string) $slug ), Main::get_delay_js_compat_preset_exclusions( (string) $slug ), "compat preset: {$slug}" );
		}
	}

	/**
	 * The delay-context memo is shared state: hits, signature misses, and
	 * resets are visible through either entry point.
	 *
	 * @return void
	 */
	public function test_context_memo_hit_miss_reset(): void {
		$this->stub_main_construction( array( 'delayJS' => true ) );
		$this->stub_guard_request_env();
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );

		$_SERVER['REQUEST_URI'] = '/plain-page/';

		// Miss then hit: same signature reuses the verdict.
		$this->assertFalse( Main::is_delay_excluded_context() );
		$this->assertFalse( Script_Strategy::is_delay_excluded_context() );

		// Signature change forces recompute (query-string signal).
		$_SERVER['REQUEST_URI'] = '/plain-page/?wc-ajax=get_refreshed_fragments';
		$_GET['wc-ajax']        = 'get_refreshed_fragments';
		$this->assertTrue( Main::is_delay_excluded_context() );

		// Reset through the new owner clears the Main-observed memo.
		Script_Strategy::reset_delay_context_memo();
		unset( $_GET['wc-ajax'] );
		$_SERVER['REQUEST_URI'] = '/plain-page/';
		$this->assertFalse( Main::is_delay_excluded_context() );

		// Reset through the Main proxy clears the strategy-observed memo.
		$_SERVER['REQUEST_URI'] = '/plain-page/?wc-ajax=get_refreshed_fragments';
		$_GET['wc-ajax']        = 'get_refreshed_fragments';
		$this->assertTrue( Script_Strategy::is_delay_excluded_context() );
		Main::reset_delay_context_memo();
		unset( $_GET['wc-ajax'] );
		$_SERVER['REQUEST_URI'] = '/plain-page/';
		$this->assertFalse( Script_Strategy::is_delay_excluded_context() );
	}

	/**
	 * Multisite correction: the same URI on different blogs must not share
	 * a delay-context verdict (blog-keyed request signature).
	 *
	 * @return void
	 */
	public function test_multisite_same_uri_different_blog(): void {
		$this->stub_main_construction( array( 'delayJS' => true ) );
		$this->stub_guard_request_env();

		$signature = new \ReflectionMethod( Script_Strategy::class, 'delay_context_request_signature' );

		$_SERVER['REQUEST_URI'] = '/shared-slug/';
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		$sig_blog_1 = $signature->invoke( null );
		Functions\when( 'get_current_blog_id' )->justReturn( 2 );
		$sig_blog_2 = $signature->invoke( null );
		$this->assertNotSame( $sig_blog_1, $sig_blog_2, 'signature must differ across blogs for the same URI' );

		// Blog 1 sees a cart context (excluded); blog 2 serves the same URI
		// as a plain page. Without a reset between the two logical requests,
		// the verdict must still be recomputed per blog — no cross-site leak.
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'is_cart' )->justReturn( true );
		$this->assertTrue( Main::is_delay_excluded_context() );

		Functions\when( 'get_current_blog_id' )->justReturn( 2 );
		Functions\when( 'is_cart' )->justReturn( false );
		$this->assertFalse( Main::is_delay_excluded_context(), 'blog-2 verdict must recompute, not reuse blog-1 memo' );
		$this->assertFalse( Script_Strategy::is_delay_excluded_context() );
	}
}
