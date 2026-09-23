<?php
/**
 * Parity tests for the ARCH-011 Rest_Cache/Rest_Settings extraction (issue #1548).
 *
 * Proves the cache+settings handler slice moved from `Rest` to the
 * `PerformanceOptimise\Inc\Rest_Cache` / `Rest_Settings` services without
 * behavior change: the route table keeps every slug/method/permission (only
 * the 12 callback targets re-point to the service instances), the shared
 * permission gate still covers every moved route (`rum_collect` stays the
 * lone public route), throttle gates still 429 with `Retry-After`, the
 * snapshot/restore one-click-undo round-trip survives, sandbox
 * stage/promote/discard survives, clear single/all guards survive, and
 * passwords/API keys are never echoed in responses.
 *
 * `Rest` keeps thin same-signature proxies, so every assertion below goes
 * through the public `Rest` facade exactly as production route callbacks do;
 * a proxy-vs-service agreement test pins the delegation itself.
 *
 * @package PerformanceOptimise\Tests
 *
 * @phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
 */

use PerformanceOptimise\Inc\Rest;
use PerformanceOptimise\Inc\Rest_Cache;
use PerformanceOptimise\Inc\Rest_Settings;
use Brain\Monkey\Functions;

/**
 * ARCH-011 Rest cache+settings parity tests.
 */
class RestCacheSettingsParityTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap {
		setUp as protected wppoSetUp;
		tearDown as protected wppoTearDown;
	}

	/**
	 * REST API handler instance (facade under test).
	 *
	 * @var Rest
	 */
	private Rest $rest;

	/**
	 * Fresh handler per test (mirrors RestTest::setUp()).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->wppoSetUp();
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		$this->rest = new Rest();
	}

	/**
	 * No superglobal leakage into later suites.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		$this->wppoTearDown();
	}

	/**
	 * Reflect the private route table of the facade.
	 *
	 * @return array Route slug => registration config.
	 */
	private function get_routes(): array {
		$reflection = new ReflectionMethod( $this->rest, 'get_routes' );
		return $reflection->invoke( $this->rest );
	}

	/**
	 * Route table keeps every slug with identical methods; only the 12
	 * extracted callbacks re-point from the registrar to the services.
	 *
	 * @return void
	 */
	public function test_route_table_preserves_slugs_methods_and_callback_targets(): void {
		$routes = $this->get_routes();

		// Same route count as the pre-extraction registrar (RestTest pins 47).
		$this->assertCount( 47, $routes, 'REST route count drifted from the documented endpoint count' );

		$moved = array(
			'clear_cache'          => array( Rest_Cache::class, 'clear_cache', 'POST' ),
			'update_settings'      => array( Rest_Settings::class, 'update_settings', 'POST' ),
			'import_settings'      => array( Rest_Settings::class, 'import_settings', 'POST' ),
			'settings_snapshot'    => array( Rest_Settings::class, 'get_settings_snapshot', 'GET' ),
			'restore_settings'     => array( Rest_Settings::class, 'restore_settings', 'POST' ),
			'purge_used_css_cache' => array( Rest_Cache::class, 'purge_used_css_cache', 'POST' ),
			'preload_status'       => array( Rest_Cache::class, 'get_preload_status', 'GET' ),
			'preload_resume'       => array( Rest_Cache::class, 'resume_preload', 'POST' ),
			'sandbox_preview'      => array( Rest_Settings::class, 'get_sandbox_preview', 'GET' ),
			'sandbox_save'         => array( Rest_Settings::class, 'save_sandbox_preview', 'POST' ),
			'sandbox_promote'      => array( Rest_Settings::class, 'promote_sandbox_preview', 'POST' ),
			'sandbox_discard'      => array( Rest_Settings::class, 'discard_sandbox_preview', 'POST' ),
		);

		foreach ( $moved as $slug => $expect ) {
			$this->assertArrayHasKey( $slug, $routes, "Missing route: {$slug}" );
			$this->assertSame( $expect[2], $routes[ $slug ]['methods'], "Methods changed for {$slug}" );
			$callback = $routes[ $slug ]['callback'];
			$this->assertIsArray( $callback, "Callback shape changed for {$slug}" );
			$this->assertInstanceOf( $expect[0], $callback[0], "Callback target changed for {$slug}" );
			$this->assertSame( $expect[1], $callback[1], "Callback method changed for {$slug}" );
		}

		// Every non-moved route still targets the registrar instance itself.
		foreach ( $routes as $slug => $config ) {
			if ( isset( $moved[ $slug ] ) ) {
				continue;
			}
			$callback = $config['callback'];
			$this->assertIsArray( $callback, "Callback shape changed for {$slug}" );
			$this->assertSame( $this->rest, $callback[0], "Callback target changed for {$slug}" );
		}
	}

	/**
	 * Every moved route keeps the shared registrar permission gate, and the
	 * RUM beacon stays the lone public route.
	 *
	 * @return void
	 */
	public function test_moved_routes_keep_shared_permission_gate(): void {
		$routes = $this->get_routes();

		$moved_slugs = array(
			'clear_cache',
			'update_settings',
			'import_settings',
			'settings_snapshot',
			'restore_settings',
			'purge_used_css_cache',
			'preload_status',
			'preload_resume',
			'sandbox_preview',
			'sandbox_save',
			'sandbox_promote',
			'sandbox_discard',
		);

		$public = array();
		foreach ( $routes as $slug => $config ) {
			$permission = $config['permission_callback'] ?? null;
			if ( '__return_true' === $permission ) {
				$public[] = $slug;
				continue;
			}
			$this->assertSame(
				array( $this->rest, 'permission_callback' ),
				$permission,
				"Permission gate changed for {$slug}"
			);
		}

		$this->assertSame( array( 'rum_collect' ), $public, 'rum_collect must stay the only public route' );

		foreach ( $moved_slugs as $slug ) {
			$this->assertArrayHasKey( $slug, $routes, "Missing moved route: {$slug}" );
			$this->assertSame(
				array( $this->rest, 'permission_callback' ),
				$routes[ $slug ]['permission_callback'],
				"Moved route {$slug} lost the shared permission gate"
			);
		}
	}

	/**
	 * The shared gate still fails closed without caps and passes with
	 * manage_options + a valid nonce.
	 *
	 * @return void
	 */
	public function test_permission_callback_gates_moved_routes(): void {
		$request = \Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_header' )->with( 'X-WP-Nonce' )->andReturn( 'test_nonce' );

		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertFalse( $this->rest->permission_callback( $request ) );

		Functions\when( 'current_user_can' )->justReturn( true );
		$this->assertTrue( $this->rest->permission_callback( $request ) );
	}

	/**
	 * Stub an over-limit transient throttle bucket for one endpoint slug.
	 *
	 * The bucket shape mirrors is_endpoint_throttled(): count at the limit
	 * with a fresh window start, so the next hit 429s.
	 *
	 * @param int $count Bucket hit count (must reach the endpoint limit).
	 * @return void
	 */
	private function stub_over_limit_throttle_bucket( int $count ): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_transient' )->alias(
			static function () use ( $count ) {
				return array(
					'count' => $count,
					'start' => time(),
				);
			}
		);
		Functions\when( 'set_transient' )->justReturn( true );
	}

	/**
	 * A throttled clear_cache call 429s with Retry-After through the proxy.
	 *
	 * @return void
	 */
	public function test_throttled_clear_cache_returns_429_with_retry_after(): void {
		$this->stub_over_limit_throttle_bucket( 99 );

		$request  = new WP_REST_Request(
			array(
				'action' => 'clear_cache',
				'path'   => '',
			)
		);
		$response = $this->rest->clear_cache( $request );

		$this->assertSame( 429, $response->get_status() );
		$this->assertFalse( $response->get_data()['success'] );
		$this->assertSame( '60', $response->get_headers()['Retry-After'] );
	}

	/**
	 * A throttled update_settings call 429s with Retry-After through the proxy.
	 *
	 * @return void
	 */
	public function test_throttled_update_settings_returns_429_with_retry_after(): void {
		$this->stub_over_limit_throttle_bucket( 99 );

		$request  = new WP_REST_Request(
			array(
				'tab'      => 'file_optimisation',
				'settings' => array( 'minifyHTML' => true ),
			)
		);
		$response = $this->rest->update_settings( $request );

		$this->assertSame( 429, $response->get_status() );
		$this->assertFalse( $response->get_data()['success'] );
		$this->assertSame( '60', $response->get_headers()['Retry-After'] );
	}

	/**
	 * A throttled sandbox save 429s with Retry-After through the proxy.
	 *
	 * @return void
	 */
	public function test_throttled_sandbox_save_returns_429_with_retry_after(): void {
		$this->stub_over_limit_throttle_bucket( 99 );

		$request  = new WP_REST_Request(
			array( 'settings' => array( 'delayJS' => true ) )
		);
		$response = $this->rest->save_sandbox_preview( $request );

		$this->assertSame( 429, $response->get_status() );
		$this->assertFalse( $response->get_data()['success'] );
		$this->assertSame( '60', $response->get_headers()['Retry-After'] );
	}

	/**
	 * Install an in-memory option store shared by get_option/update_option.
	 *
	 * @param array $store Option store seeded by the caller (by reference).
	 * @return void
	 */
	private function stub_option_store( array &$store ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( &$store ) {
				return array_key_exists( $name, $store ) ? $store[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$store ) {
				$store[ $name ] = $value;
				return true;
			}
		);
	}

	/**
	 * Settings save snapshots the prior settings, the snapshot endpoint
	 * reports availability, and restore returns the prior settings.
	 *
	 * @return void
	 */
	public function test_snapshot_restore_round_trip(): void {
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->alias(
			static fn( $value ): string => trim( preg_replace( '/<[^>]*>/', '', (string) $value ) )
		);

		$prior = array( 'file_optimisation' => array( 'minifyHTML' => false ) );
		$store = array( 'wppo_settings' => $prior );
		$this->stub_option_store( $store );

		$request  = new WP_REST_Request(
			array(
				'tab'      => 'file_optimisation',
				'settings' => array( 'minifyHTML' => true ),
			)
		);
		$response = $this->rest->update_settings( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'wppo_settings_snapshot', $store, 'Prior settings must be snapshotted before the save' );
		$this->assertSame( $prior, $store['wppo_settings_snapshot']['settings'] );
		$this->assertTrue( $store['wppo_settings']['file_optimisation']['minifyHTML'] );

		$snapshot_response = $this->rest->get_settings_snapshot( new WP_REST_Request() );
		$snapshot_data     = $snapshot_response->get_data();
		$this->assertTrue( $snapshot_data['success'] );
		$this->assertTrue( $snapshot_data['data']['has_snapshot'] );
		$this->assertNotEmpty( $snapshot_data['data']['taken_at'] );

		$restore_response = $this->rest->restore_settings( new WP_REST_Request() );
		$this->assertSame( 200, $restore_response->get_status() );
		$this->assertFalse( $store['wppo_settings']['file_optimisation']['minifyHTML'], 'Restore must bring back the prior settings' );
	}

	/**
	 * Restoring with no snapshot available 404s instead of wiping settings.
	 *
	 * @return void
	 */
	public function test_restore_without_snapshot_returns_404(): void {
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		$store = array( 'wppo_settings' => array() );
		$this->stub_option_store( $store );

		$response = $this->rest->restore_settings( new WP_REST_Request() );

		$this->assertSame( 404, $response->get_status() );
		$this->assertFalse( $response->get_data()['success'] );
	}

	/**
	 * Sandbox stage/promote/discard lifecycle survives the extraction.
	 *
	 * @return void
	 */
	public function test_sandbox_stage_promote_discard(): void {
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		// Staging writes are capability-gated inside Sandbox_Preview.
		Functions\when( 'current_user_can' )->justReturn( true );
		$store = array( 'wppo_settings' => array( 'file_optimisation' => array( 'delayJS' => false ) ) );
		$this->stub_option_store( $store );

		$save_response = $this->rest->save_sandbox_preview(
			new WP_REST_Request( array( 'settings' => array( 'delayJS' => true ) ) )
		);
		$save_data     = $save_response->get_data();
		$this->assertTrue( $save_data['success'] );
		$this->assertTrue( ! empty( $save_data['data']['staged']['delayJS'] ) );

		$preview_response = $this->rest->get_sandbox_preview( new WP_REST_Request() );
		$preview_data     = $preview_response->get_data();
		$this->assertTrue( $preview_data['success'] );
		$this->assertTrue( $preview_data['data']['has_staged'] );

		$promote_response = $this->rest->promote_sandbox_preview( new WP_REST_Request() );
		$promote_data     = $promote_response->get_data();
		$this->assertTrue( $promote_data['success'] );
		$this->assertTrue( ! empty( $promote_data['data']['file_optimisation']['delayJS'] ) );
		$this->assertEmpty( $promote_data['data']['file_optimisation']['sandboxStaged'] );

		// Stage again, then discard instead of promoting.
		$this->rest->save_sandbox_preview(
			new WP_REST_Request( array( 'settings' => array( 'delayJS' => true ) ) )
		);
		$discard_response = $this->rest->discard_sandbox_preview( new WP_REST_Request() );
		$discard_data     = $discard_response->get_data();
		$this->assertTrue( $discard_data['success'] );
		$this->assertSame( array(), $discard_data['data']['staged'] );
	}

	/**
	 * Stub the WP environment needed for clear_cache (mirrors the
	 * RestTest fresh-install recipe: missing cache dir, mocked fsdb).
	 *
	 * @return void
	 */
	private function stub_clear_cache_environment(): void {
		$_SERVER['HTTP_HOST']   = 'example.com';
		$_SERVER['REQUEST_URI'] = '/';

		$cache_dir_reflection = new \ReflectionProperty( Rest::class, 'cache_dir' );
		$cache_dir_reflection->setValue( $this->rest, '/tmp/wppo-does-not-exist/cache/wppo/' );

		$GLOBALS['wp_filesystem'] = new WPPO_Filesystem_Mock();

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wpdb'] = new WPPO_WPDB_Mock();
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'WP_Filesystem' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( null );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_kses_post' )->returnArg();
	}

	/**
	 * Clearing all cache succeeds on a fresh install with no cache dir.
	 *
	 * @return void
	 */
	public function test_clear_all_cache_succeeds_when_cache_dir_missing(): void {
		$this->stub_clear_cache_environment();

		$request  = new WP_REST_Request(
			array(
				'action' => 'clear_cache',
				'path'   => '',
			)
		);
		$response = $this->rest->clear_cache( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
	}

	/**
	 * Clearing a traversal path is refused before touching the filesystem.
	 *
	 * @return void
	 */
	public function test_clear_single_page_rejects_traversal_path(): void {
		$this->stub_clear_cache_environment();

		$request  = new WP_REST_Request(
			array(
				'action' => 'clear_single_page_cache',
				'path'   => '../escape',
			)
		);
		$response = $this->rest->clear_cache( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( $response->get_data()['success'] );
	}

	/**
	 * A double-encoded traversal path is refused (bounded decode guard).
	 *
	 * @return void
	 */
	public function test_clear_single_page_rejects_encoded_traversal_path(): void {
		$this->stub_clear_cache_environment();

		$request  = new WP_REST_Request(
			array(
				'action' => 'clear_single_page_cache',
				'path'   => '%252e%252e/escape',
			)
		);
		$response = $this->rest->clear_cache( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( $response->get_data()['success'] );
	}

	/**
	 * Redis passwords and API keys are stored as flags and never echoed.
	 *
	 * @return void
	 */
	public function test_password_and_api_key_never_echoed(): void {
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->alias(
			static fn( $value ): string => trim( preg_replace( '/<[^>]*>/', '', (string) $value ) )
		);

		$store = array(
			'wppo_settings' => array(
				'performance_audit' => array( 'pagespeed_api_key' => 'live-secret' ),
			),
		);
		$this->stub_option_store( $store );

		$request  = new WP_REST_Request(
			array(
				'tab'      => 'object_cache',
				'settings' => array( 'password' => 's3cret' ),
			)
		);
		$response = $this->rest->update_settings( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data()['data'];
		$this->assertArrayNotHasKey( 'password', $data['object_cache'], 'Redis password must never be echoed' );
		$this->assertTrue( $store['wppo_settings']['object_cache']['password_set'] );
		$this->assertArrayNotHasKey( 'password', $store['wppo_settings']['object_cache'], 'Redis password must never be stored' );
		$this->assertArrayNotHasKey( 'pagespeed_api_key', $data['performance_audit'], 'API key must never be echoed' );
		$this->assertSame( 'live-secret', $store['wppo_settings']['performance_audit']['pagespeed_api_key'], 'Stored API key must survive a sibling-tab save' );
	}

	/**
	 * Imported Redis passwords are stored as flags and never echoed.
	 *
	 * @return void
	 */
	public function test_import_settings_redacts_password(): void {
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->alias(
			static fn( $value ): string => trim( preg_replace( '/<[^>]*>/', '', (string) $value ) )
		);

		$store = array( 'wppo_settings' => array() );
		$this->stub_option_store( $store );

		$request  = new WP_REST_Request(
			array(
				'action'   => 'import_settings',
				'settings' => array(
					'object_cache' => array( 'password' => 's3cret' ),
				),
			)
		);
		$response = $this->rest->import_settings( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data()['data'];
		$this->assertArrayNotHasKey( 'password', $data['object_cache'], 'Imported password must never be echoed' );
		$this->assertTrue( $store['wppo_settings']['object_cache']['password_set'] );
	}

	/**
	 * Facade proxies agree with direct service calls (delegation parity).
	 *
	 * @return void
	 */
	public function test_proxies_agree_with_services(): void {
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		$store = array( 'wppo_settings' => array() );
		$this->stub_option_store( $store );

		$cache_service    = new Rest_Cache( $this->rest );
		$settings_service = new Rest_Settings( $this->rest );

		$via_proxy   = $this->rest->get_preload_status( new WP_REST_Request() );
		$via_service = $cache_service->get_preload_status( new WP_REST_Request() );
		$this->assertSame( $via_service->get_data(), $via_proxy->get_data() );

		$via_proxy   = $this->rest->get_settings_snapshot( new WP_REST_Request() );
		$via_service = $settings_service->get_settings_snapshot( new WP_REST_Request() );
		$this->assertSame( $via_service->get_data(), $via_proxy->get_data() );

		$via_proxy   = $this->rest->get_sandbox_preview( new WP_REST_Request() );
		$via_service = $settings_service->get_sandbox_preview( new WP_REST_Request() );
		$this->assertSame( $via_service->get_data(), $via_proxy->get_data() );
	}
}
