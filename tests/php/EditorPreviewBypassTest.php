<?php
/**
 * Tests for the admin/editor-preview static-cache bypass (issue #1097).
 *
 * Locks in that wp-admin / login / admin-ajax paths, core previews, and
 * Elementor/Divi/WPBakery/Bricks edit contexts are never buffered or stored,
 * that preload scheduling skips them, and that the woo self-test endpoint
 * reports the additive editor bypass probes as green.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\Cron;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests for the admin/editor-preview cache bypass.
 *
 * @package PerformanceOptimise\Tests
 */
class EditorPreviewBypassTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Set up Brain Monkey and common stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		\PerformanceOptimise\Inc\Util::reset_cached_home_urls();
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		\PerformanceOptimise\Inc\Util::clear_permalink_cache();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		unset( $_SERVER['QUERY_STRING'], $_SERVER['REQUEST_URI'] );
	}

	/**
	 * Tear down Brain Monkey and superglobal fixtures.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		unset( $_SERVER['QUERY_STRING'], $_SERVER['REQUEST_URI'] );
		foreach ( array( 'elementor-preview', 'et_fb', 'et_pb_preview', 'vc_action', 'vc_editable', 'bricks', 'preview', 'preview_id', 'customize_changeset_uuid', 'customizer', 'rest_route', 'add-to-cart', 'wc-ajax' ) as $key ) {
			unset( $_GET[ $key ] );
		}
		unset( $GLOBALS['wp_filesystem'] );
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a Cache instance without running its constructor.
	 *
	 * @param array  $options     Options to seed.
	 * @param string $request_uri Request URI to seed.
	 * @return Cache
	 */
	private function make_cache( array $options = array(), string $request_uri = '/' ): Cache {
		$cache = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();

		$prop = new \ReflectionProperty( Cache::class, 'options' );
		$prop->setAccessible( true );
		$prop->setValue( $cache, $options );

		$prop = new \ReflectionProperty( Cache::class, 'cache_root_dir' );
		$prop->setAccessible( true );
		$prop->setValue( $cache, WP_CONTENT_DIR . '/cache/wppo' );

		$prop = new \ReflectionProperty( Cache::class, 'domain' );
		$prop->setAccessible( true );
		$prop->setValue( $cache, 'example.com' );

		$prop = new \ReflectionProperty( Cache::class, 'request_uri' );
		$prop->setAccessible( true );
		$prop->setValue( $cache, $request_uri );

		$parsed = wp_parse_url( $request_uri, PHP_URL_PATH );
		$path   = wp_normalize_path( trim( rawurldecode( (string) $parsed ), '/' ) );
		if ( false !== strpos( $path, '..' ) ) {
			$path = '';
		}
		$prop = new \ReflectionProperty( Cache::class, 'url_path' );
		$prop->setAccessible( true );
		$prop->setValue( $cache, $path );

		$prop = new \ReflectionProperty( Cache::class, 'cache_ob_level' );
		$prop->setAccessible( true );
		$prop->setValue( $cache, null );

		return $cache;
	}

	/**
	 * Invoke a private method.
	 *
	 * @param object $target Object instance.
	 * @param string $name   Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private function invoke_private( $target, string $name, array $args = array() ) {
		$method = new \ReflectionMethod( $target, $name );
		$method->setAccessible( true );
		return $method->invokeArgs( $target, $args );
	}

	/**
	 * Stub front-end conditionals to benign guest-HTML defaults.
	 */
	private function stub_front_end_guests(): void {
		Functions\when( 'wp_is_mobile' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'is_wc_endpoint_url' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_preview' )->justReturn( false );
		Functions\when( 'is_customize_preview' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_is_json_request' )->justReturn( false );
	}

	/**
	 * Admin entry-point paths bypass the cache.
	 */
	public function test_is_admin_path(): void {
		foreach ( array( '/wp-admin/', '/wp-admin/post.php', '/subsite/wp-admin/', '/wp-login.php', '/wp-admin/admin-ajax.php' ) as $path ) {
			$this->assertTrue( Util::is_admin_path( $path ), 'Expected admin path: ' . $path );
		}
		foreach ( array( '/', '/hello-world/', '/my-wp-admin-guide/', '/login/' ) as $path ) {
			$this->assertFalse( Util::is_admin_path( $path ), 'Expected non-admin path: ' . $path );
		}
	}

	/**
	 * Builder and core preview query params bypass the cache.
	 */
	public function test_is_editor_preview_path_params(): void {
		foreach ( array( 'elementor-preview=1', 'et_fb=1', 'et_pb_preview=true', 'vc_action=vc_inline', 'vc_editable=true', 'bricks=run', 'preview=true', 'preview_id=1', 'customize_changeset_uuid=abc', 'customizer=true' ) as $query ) {
			$this->assertTrue( Util::is_editor_preview_path( '/', $query ), 'Expected preview: ' . $query );
		}
		$this->assertFalse( Util::is_editor_preview_path( '/', 's=hello' ) );
		$this->assertFalse( Util::is_editor_preview_path( '/hello-world/', '' ) );
		$this->assertTrue( Util::is_editor_preview_path( '/wp-admin/post.php', '' ) );
	}

	/**
	 * Absolute admin/preview URLs are detected for preload exclusion.
	 */
	public function test_is_editor_preview_url(): void {
		$this->assertTrue( Util::is_editor_preview_url( 'http://example.com/wp-admin/post.php?post=1&action=edit' ) );
		$this->assertTrue( Util::is_editor_preview_url( 'http://example.com/?elementor-preview=1' ) );
		$this->assertTrue( Util::is_editor_preview_url( 'http://example.com/?preview=true' ) );
		$this->assertTrue( Util::is_editor_preview_url( 'http://example.com/?bricks=run' ) );
		$this->assertFalse( Util::is_editor_preview_url( 'http://example.com/hello-world/' ) );
		$this->assertFalse( Util::is_editor_preview_url( 'http://example.com/shop/' ) );
	}

	/**
	 * Current-request check honors conditional tags.
	 */
	public function test_is_editor_preview_request_conditionals(): void {
		$this->stub_front_end_guests();
		$_SERVER['REQUEST_URI'] = '/hello-world/';

		$this->assertFalse( Util::is_editor_preview_request() );

		Functions\when( 'is_admin' )->justReturn( true );
		$this->assertTrue( Util::is_editor_preview_request() );
		Functions\when( 'is_admin' )->justReturn( false );

		Functions\when( 'is_preview' )->justReturn( true );
		$this->assertTrue( Util::is_editor_preview_request() );
		Functions\when( 'is_preview' )->justReturn( false );

		Functions\when( 'is_customize_preview' )->justReturn( true );
		$this->assertTrue( Util::is_editor_preview_request() );
	}

	/**
	 * Current-request check honors preview params and admin paths.
	 */
	public function test_is_editor_preview_request_params_and_paths(): void {
		$this->stub_front_end_guests();

		$_SERVER['REQUEST_URI']    = '/hello-world/';
		$_GET['elementor-preview'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test fixture for read-only routing check.
		$this->assertTrue( Util::is_editor_preview_request() );
		unset( $_GET['elementor-preview'] );

		$_SERVER['REQUEST_URI'] = '/wp-admin/post.php?post=1&action=edit';
		$this->assertTrue( Util::is_editor_preview_request() );

		$_SERVER['REQUEST_URI'] = '/hello-world/';
		$this->assertFalse( Util::is_editor_preview_request() );
	}

	/**
	 * Admin and preview requests are not cacheable at serve and store time.
	 */
	public function test_admin_and_preview_uris_are_not_cacheable(): void {
		$this->stub_front_end_guests();

		foreach ( array( '/wp-admin/post.php', '/?elementor-preview=1', '/?preview=true', '/?et_fb=1', '/?bricks=run' ) as $uri ) {
			$_SERVER['REQUEST_URI'] = $uri;
			$parts                  = wp_parse_url( $uri );
			if ( is_array( $parts ) && isset( $parts['query'] ) ) {
				parse_str( (string) $parts['query'], $params );
				foreach ( $params as $k => $v ) {
					$_GET[ (string) $k ] = $v; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test fixture for read-only routing check.
				}
			}
			$cache = $this->make_cache( array(), $uri );
			$this->assertTrue( $this->invoke_private( $cache, 'is_not_cacheable' ), 'Expected not cacheable: ' . $uri );
			$this->assertFalse( $this->invoke_private( $cache, 'maybe_store_cache' ), 'Expected no store: ' . $uri );
			foreach ( array_keys( $_GET ) as $k ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test teardown clearing fixtures set above.
				unset( $_GET[ $k ] );
			}
		}
	}

	/**
	 * Ordinary pages stay cacheable with the bypass in place.
	 */
	public function test_normal_page_remains_cacheable(): void {
		$this->stub_front_end_guests();
		$_SERVER['REQUEST_URI'] = '/hello-world/';

		$cache = $this->make_cache( array(), '/hello-world/' );
		$this->assertFalse( $this->invoke_private( $cache, 'is_not_cacheable' ) );
		$this->assertTrue( $cache->is_page_cacheable() );
	}

	/**
	 * Cron skips admin/preview URLs when preloading.
	 */
	public function test_cron_skips_editor_preview_urls(): void {
		$cron = ( new \ReflectionClass( Cron::class ) )->newInstanceWithoutConstructor();

		$this->assertTrue( $this->invoke_private( $cron, 'is_editor_preview_url', array( 'http://example.com/wp-admin/post.php' ) ) );
		$this->assertTrue( $this->invoke_private( $cron, 'is_editor_preview_url', array( 'http://example.com/?elementor-preview=1' ) ) );
		$this->assertFalse( $this->invoke_private( $cron, 'is_editor_preview_url', array( 'http://example.com/hello-world/' ) ) );
	}

	/**
	 * Self-test reports the additive editor bypass probes as green.
	 */
	public function test_self_test_includes_editor_checks(): void {
		$this->stub_front_end_guests();
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $url ) {
				return rtrim( (string) $url, '/' );
			}
		);

		$result = Util::woo_cache_self_test();

		$this->assertTrue( $result['runnable'] );
		$this->assertArrayHasKey( 'editor_checks', $result );
		$this->assertNotEmpty( $result['editor_checks'] );
		foreach ( $result['editor_checks'] as $check ) {
			$this->assertTrue( $check['bypass'], 'Expected bypass: ' . $check['url'] );
			$this->assertFalse( $check['cacheable'] );
			$this->assertTrue( $check['pass'] );
		}
		$this->assertTrue( $result['all_pass'] );
	}
}
