<?php
/**
 * P3-017 parity tests: Cron + Preload_Buffer_Coordinator call Woo_Detect.
 *
 * Proves the warm-path caller cluster migrated from `Util::is_woo_*()` proxies
 * to the canonical `Woo_Detect` owner while every `Util` proxy stays a
 * compatible one-line shim: per-predicate parity, Cron/PBC warm-skip matrices
 * (safe-mode on/off, Store API, wc-ajax, faceted, dynamic paths, fail-open),
 * and source assertions pinning the retarget.
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Cron;
use PerformanceOptimise\Inc\Google_Fonts;
use PerformanceOptimise\Inc\Image_Optimisation;
use PerformanceOptimise\Inc\Preload_Buffer_Coordinator;
use PerformanceOptimise\Inc\Util;
use PerformanceOptimise\Inc\Woo_Detect;

/**
 * Warm-path Woo_Detect migration parity tests.
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */
class WooDetectParityTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Enqueue calls captured from the injected PBC scheduling port.
	 *
	 * @var array<int, array{0:string,1:array,2:string}>
	 */
	private array $enqueued = array();

	/**
	 * Seed the option-store fixture backing safe-mode resolution.
	 *
	 * @param array $settings Settings array returned by get_option().
	 * @return void
	 */
	private function seed_settings( array $settings ): void {
		Functions\when( 'get_option' )->justReturn( $settings );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		Functions\when( 'has_filter' )->justReturn( false );
		Util::clear_settings_cache();
		unset( $_SERVER['QUERY_STRING'], $_GET['rest_route'] );
	}

	/**
	 * Build a coordinator capturing scheduling-port calls.
	 *
	 * @return Preload_Buffer_Coordinator
	 */
	private function make_coordinator(): Preload_Buffer_Coordinator {
		$options = array();
		return new Preload_Buffer_Coordinator(
			static fn(): array => $options,
			new Image_Optimisation( $options ),
			new Google_Fonts( $options ),
			static function ( array $file_optimisation ): bool {
				unset( $file_optimisation );
				return false;
			},
			static fn(): bool => false,
			function ( string $hook, array $args, string $group ): void {
				$this->enqueued[] = array( $hook, $args, $group );
			}
		);
	}

	/**
	 * Invoke the private Cron warm-path predicate.
	 *
	 * @param string     $url       Absolute URL.
	 * @param bool|null  $woo_safe  Pre-resolved safe-mode flag (null = fast path).
	 * @param array|null $woo_paths Pre-resolved excluded paths (null = fast path).
	 * @return bool Exclusion verdict.
	 */
	private function invoke_cron_excluded_url( string $url, ?bool $woo_safe = null, ?array $woo_paths = null ): bool {
		$cron   = ( new \ReflectionClass( Cron::class ) )->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod( Cron::class, 'is_woo_excluded_url' );
		return (bool) $method->invoke( $cron, $url, $woo_safe, $woo_paths );
	}

	/**
	 * Invoke the private PBC legacy-fallback predicate.
	 *
	 * @param string $url     Candidate permalink.
	 * @param array  $options Resolved settings snapshot.
	 * @return bool True when the URL must not be scheduled.
	 */
	private function invoke_pbc_fallback( string $url, array $options ): bool {
		$method = new \ReflectionMethod( Preload_Buffer_Coordinator::class, 'reject_legacy_woo_dynamic_url' );
		return (bool) $method->invoke( $this->make_coordinator(), $url, $options );
	}

	/**
	 * Every warm-path predicate via Woo_Detect matches the Util proxy.
	 *
	 * Covers safe-mode on/off/malformed, Store API pretty + rest_route forms,
	 * wc-ajax, faceted queries, and dynamic nested/subsite paths.
	 *
	 * @return void
	 */
	public function test_warm_path_predicates_match_util_proxies(): void {
		$this->seed_settings( array() );

		$this->assertSame( Woo_Detect::is_woo_safe_mode_enabled(), Util::is_woo_safe_mode_enabled() );
		$this->assertSame( Woo_Detect::get_woo_excluded_paths(), Util::get_woo_excluded_paths() );
		$this->assertSame( Woo_Detect::is_woo_safe_mode_enabled( array( 'cache_settings' => array( 'wooSafeMode' => false ) ) ), Util::is_woo_safe_mode_enabled( array( 'cache_settings' => array( 'wooSafeMode' => false ) ) ) );
		$this->assertSame( Woo_Detect::is_woo_safe_mode_enabled( array( 'cache_settings' => array( 'wooSafeMode' => array( 'x' ) ) ) ), Util::is_woo_safe_mode_enabled( array( 'cache_settings' => array( 'wooSafeMode' => array( 'x' ) ) ) ) );

		foreach ( array( '/wp-json/wc/store/v1/cart', '/wc/store/v1/cart', '/', '/shop/' ) as $path ) {
			$this->assertSame( Woo_Detect::is_woo_store_api_path( $path ), Util::is_woo_store_api_path( $path ), 'store_api_path: ' . $path );
		}
		foreach ( array(
			array( '/wp-json/wc/store/v1/cart', '', '' ),
			array( '/', 'rest_route=/wc/store/v1/cart', '' ),
			array( '/', '', '/wc/store/v1/cart' ),
			array( '/shop/', 'foo=bar', '' ),
		) as $args ) {
			$this->assertSame( Woo_Detect::is_woo_store_api_request( ...$args ), Util::is_woo_store_api_request( ...$args ), 'store_api_request: ' . implode( '|', $args ) );
		}
		foreach ( array( '/cart/', '/subsite/cart/', '/checkout/', '/shop/', '/' ) as $path ) {
			$this->assertSame( Woo_Detect::is_woo_dynamic_path( $path ), Util::is_woo_dynamic_path( $path ), 'dynamic_path: ' . $path );
		}
		foreach ( array(
			array( '/', 'wc-ajax=get_refreshed_fragments' ),
			array( '/wc-ajax/get_refreshed_fragments/', '' ),
			array( '/shop/', '' ),
		) as $args ) {
			$this->assertSame( Woo_Detect::is_woo_ajax_request( ...$args ), Util::is_woo_ajax_request( ...$args ), 'ajax: ' . implode( '|', $args ) );
		}
		foreach ( array( 'filter_color=blue', 'min_price=10', 'orderby=price', 'foo=bar', '' ) as $query ) {
			$this->assertSame( Woo_Detect::is_woo_faceted_query( $query ), Util::is_woo_faceted_query( $query ), 'faceted: ' . $query );
		}
		foreach ( array(
			'https://example.com/cart/',
			'https://example.com/wp-json/wc/store/v1/cart',
			'https://example.com/?rest_route=/wc/store/v1/cart',
			'https://example.com/?wc-ajax=get_refreshed_fragments',
			'https://example.com/shop/?filter_color=blue',
			'https://example.com/?add-to-cart=123',
			'https://example.com/shop/',
		) as $url ) {
			$this->assertSame( Woo_Detect::is_woo_excluded_url( $url ), Util::is_woo_excluded_url( $url ), 'excluded_url: ' . $url );
		}
	}

	/**
	 * Custom faceted params via the filter match through both layers.
	 *
	 * @return void
	 */
	public function test_faceted_filter_parity(): void {
		$this->seed_settings( array() );
		Functions\when( 'has_filter' )->justReturn( true );
		Functions\when( 'apply_filters' )->justReturn( array( 'custom_param' ) );

		$this->assertTrue( Woo_Detect::is_woo_faceted_query( 'custom_param=1' ) );
		$this->assertSame( Woo_Detect::is_woo_faceted_query( 'custom_param=1' ), Util::is_woo_faceted_query( 'custom_param=1' ) );
		$this->assertFalse( Woo_Detect::is_woo_faceted_query( 'foo=bar' ) );
		$this->assertSame( Woo_Detect::is_woo_faceted_query( 'foo=bar' ), Util::is_woo_faceted_query( 'foo=bar' ) );
	}

	/**
	 * Cron warm-skip matrix via the canonical fast path and the granular branch.
	 *
	 * Safe-mode on skips /cart/; safe-mode off keeps Store API, wc-ajax, and
	 * faceted skips (add-to-cart still skips via the generic query guard).
	 *
	 * @return void
	 */
	public function test_cron_warm_skip_matrix(): void {
		$this->seed_settings( array() );

		$this->assertTrue( $this->invoke_cron_excluded_url( 'https://example.com/cart/' ) );
		$this->assertTrue( $this->invoke_cron_excluded_url( 'https://example.com/shop/?filter_color=blue' ) );
		$this->assertTrue( $this->invoke_cron_excluded_url( 'https://example.com/wp-json/wc/store/v1/cart' ) );
		$this->assertTrue( $this->invoke_cron_excluded_url( 'https://example.com/?add-to-cart=123' ) );
		$this->assertFalse( $this->invoke_cron_excluded_url( 'https://example.com/shop/' ) );

		// Granular branch (pre-resolved batch args) matches the canonical verdict.
		$paths = Woo_Detect::get_woo_excluded_paths();
		$this->assertTrue( $this->invoke_cron_excluded_url( 'https://example.com/cart/', true, $paths ) );
		$this->assertTrue( $this->invoke_cron_excluded_url( 'https://example.com/shop/?filter_color=blue', true, $paths ) );
		$this->assertFalse( $this->invoke_cron_excluded_url( 'https://example.com/shop/', true, $paths ) );
		$this->assertSame(
			Woo_Detect::is_woo_excluded_url( 'https://example.com/cart/' ),
			$this->invoke_cron_excluded_url( 'https://example.com/cart/', true, $paths )
		);

		$this->seed_settings( array( 'cache_settings' => array( 'wooSafeMode' => false ) ) );

		$this->assertFalse( $this->invoke_cron_excluded_url( 'https://example.com/cart/' ) );
		$this->assertTrue( $this->invoke_cron_excluded_url( 'https://example.com/wp-json/wc/store/v1/cart' ) );
		$this->assertTrue( $this->invoke_cron_excluded_url( 'https://example.com/?wc-ajax=get_refreshed_fragments' ) );
		$this->assertTrue( $this->invoke_cron_excluded_url( 'https://example.com/shop/?filter_color=blue' ) );
		$this->assertTrue( $this->invoke_cron_excluded_url( 'https://example.com/?add-to-cart=123' ) );
	}

	/**
	 * PBC scheduling seam skips dynamic URLs and warms plain URLs.
	 *
	 * @return void
	 */
	public function test_pbc_warm_skip_matrix(): void {
		$this->seed_settings( array() );
		Functions\when( 'as_enqueue_async_action' )->justReturn( 0 );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );

		$urls = array(
			1 => 'https://example.com/cart/',
			2 => 'https://example.com/shop/?filter_color=blue',
			3 => 'https://example.com/wp-json/wc/store/v1/cart',
			4 => 'https://example.com/hello-world/',
		);
		Functions\when( 'get_permalink' )->alias(
			static function ( $post_id ) use ( $urls ) {
				return $urls[ (int) $post_id ] ?? 'https://example.com/hello-world/';
			}
		);

		$settings    = array( 'preload_settings' => array( 'preloadSitemap' => true ) );
		$coordinator = $this->make_coordinator();
		$coordinator->queue_crawler_warm_after_cache_invalidation( 1, $settings );
		$coordinator->queue_crawler_warm_after_cache_invalidation( 2, $settings );
		$coordinator->queue_crawler_warm_after_cache_invalidation( 3, $settings );
		$this->assertSame( array(), $this->enqueued, 'Dynamic URLs must not be scheduled' );

		$coordinator->queue_crawler_warm_after_cache_invalidation( 4, $settings );
		$this->assertCount( 1, $this->enqueued );
		$this->assertSame( 'wppo_crawler_warm', $this->enqueued[0][0] );
		$this->assertSame( array( 'https://example.com/hello-world/' ), $this->enqueued[0][1] );
	}

	/**
	 * PBC safe-mode-off matrix: dynamic paths warm, faceted/Store API still skip.
	 *
	 * @return void
	 */
	public function test_pbc_safe_mode_off_matrix(): void {
		$this->seed_settings( array( 'cache_settings' => array( 'wooSafeMode' => false ) ) );
		Functions\when( 'as_enqueue_async_action' )->justReturn( 0 );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/cart/' );

		$settings    = array(
			'preload_settings' => array( 'preloadSitemap' => true ),
			'cache_settings'   => array( 'wooSafeMode' => false ),
		);
		$coordinator = $this->make_coordinator();
		$coordinator->queue_crawler_warm_after_cache_invalidation( 7, $settings );
		$this->assertCount( 1, $this->enqueued, 'Safe-mode-off /cart/ must warm' );

		$this->enqueued = array();
		$this->assertFalse( $this->invoke_pbc_fallback( 'https://example.com/cart/', $settings ) );
		$this->assertTrue( $this->invoke_pbc_fallback( 'https://example.com/shop/?filter_color=blue', $settings ) );
		$this->assertTrue( $this->invoke_pbc_fallback( 'https://example.com/wp-json/wc/store/v1/cart', $settings ) );
		$this->assertTrue( $this->invoke_pbc_fallback( 'https://example.com/?wc-ajax=get_refreshed_fragments', $settings ) );
	}

	/**
	 * Migrated callers invoke Woo_Detect; generic/editor helpers stay on Util.
	 *
	 * @return void
	 */
	public function test_source_uses_woo_detect(): void {
		$cron_path   = __DIR__ . '/../../includes/Scheduler/class-cron.php';
		$pbc_path    = __DIR__ . '/../../includes/Core/class-preload-buffer-coordinator.php';
		$cron_source = implode( '', (array) file( $cron_path ) );
		$pbc_source  = implode( '', (array) file( $pbc_path ) );

		foreach ( array( 'is_woo_excluded_url', 'is_woo_store_api_request', 'is_woo_store_api_path', 'is_woo_ajax_request', 'is_woo_faceted_query', 'is_woo_safe_mode_enabled', 'is_woo_dynamic_path', 'get_woo_excluded_paths' ) as $method ) {
			$this->assertStringContainsString( 'Woo_Detect::' . $method, $cron_source, 'Cron must call Woo_Detect::' . $method );
		}
		foreach ( array( 'is_woo_excluded_url', 'is_woo_store_api_request', 'is_woo_ajax_request', 'is_woo_faceted_query', 'is_woo_safe_mode_enabled', 'is_woo_dynamic_path' ) as $method ) {
			$this->assertStringContainsString( 'Woo_Detect::' . $method, $pbc_source, 'PBC must call Woo_Detect::' . $method );
		}
		$this->assertStringNotContainsString( 'Util::is_woo_excluded_url(', $cron_source );
		$this->assertStringNotContainsString( 'Util::is_woo_excluded_url(', $pbc_source );
		$this->assertStringNotContainsString( "method_exists( 'PerformanceOptimise\\Inc\\Util', 'is_woo_", $cron_source );
		$this->assertStringNotContainsString( "method_exists( 'PerformanceOptimise\\Inc\\Util', 'is_woo_", $pbc_source );

		// Non-Woo helpers stay canonical on Util in both callers.
		$this->assertStringContainsString( 'Util::has_uncacheable_query', $cron_source );
		$this->assertStringContainsString( 'Util::has_uncacheable_query', $pbc_source );
		$this->assertStringContainsString( 'Util::is_editor_preview_url', $cron_source );

		// Util proxies remain public compatibility shims.
		foreach ( array( 'is_woo_excluded_url', 'is_woo_store_api_request', 'is_woo_store_api_path', 'is_woo_ajax_request', 'is_woo_faceted_query', 'is_woo_safe_mode_enabled', 'is_woo_dynamic_path', 'get_woo_excluded_paths' ) as $method ) {
			$this->assertTrue( method_exists( Util::class, $method ), 'Util proxy missing: ' . $method );
			$reflection = new \ReflectionMethod( Util::class, $method );
			$this->assertTrue( $reflection->isPublic() && $reflection->isStatic(), 'Util proxy must stay public static: ' . $method );
		}
	}
}
