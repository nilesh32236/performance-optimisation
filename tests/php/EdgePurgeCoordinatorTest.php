<?php
/**
 * Behaviour tests for the shared edge/CDN purge coordinator (issue #1589).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Edge_Purge_Coordinator;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Test fixture is co-located by convention.
// phpcs:disable WordPress.Files.FileName -- Declares a uniquely-named WP_Error stand-in for the failure seam.

if ( ! class_exists( 'EdgePurgeCoordinatorWPError' ) ) {
	/**
	 * Minimal WP_Error stand-in for the coordinator failure path.
	 */
	class EdgePurgeCoordinatorWPError {

		/**
		 * Failure message returned by the stand-in.
		 *
		 * @var string
		 */
		private string $message;

		/**
		 * Build a failure response.
		 *
		 * @param string $message Failure message.
		 */
		public function __construct( string $message ) {
			$this->message = $message;
		}

		/**
		 * Return the failure message.
		 *
		 * @return string Failure message.
		 */
		public function get_error_message() {
			return $this->message;
		}
	}
}

/**
 * Coordinator tests for one cache-clear event across legacy and edge settings.
 */
class EdgePurgeCoordinatorTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Requests that reached the HTTP transport.
	 *
	 * @var array
	 */
	private array $requests = array();

	/**
	 * In-memory option and transient stores.
	 *
	 * @var array<string,mixed>
	 */
	private array $state = array();

	/**
	 * Response returned by the next real HTTP transport request.
	 *
	 * @var mixed
	 */
	private $transport_response = null;

	/**
	 * Debug log messages recorded during one event.
	 *
	 * @var string[]
	 */
	private array $debug_log = array();

	/**
	 * Action hooks observed during one event.
	 *
	 * @var string[]
	 */
	private array $actions = array();

	/**
	 * Installed pre_http_request callback.
	 *
	 * @var callable|null
	 */
	private $pre_http_callback = null;

	/**
	 * Whether add_filter() installed the callback.
	 *
	 * @var bool
	 */
	private bool $pre_http_added = false;

	/**
	 * Whether remove_filter() removed the callback.
	 *
	 * @var bool
	 */
	private bool $pre_http_removed = false;

	/**
	 * Installed http_api_debug callback.
	 *
	 * @var callable|null
	 */
	private $http_debug_callback = null;

	/**
	 * Whether add_action() installed the HTTP response recorder.
	 *
	 * @var bool
	 */
	private bool $http_debug_added = false;

	/**
	 * Whether remove_action() removed the HTTP response recorder.
	 *
	 * @var bool
	 */
	private bool $http_debug_removed = false;

	/**
	 * Install the WordPress/HTTP seams used by both provider adapters.
	 */
	private function install_stubs(): void {
		if ( ! defined( 'WPPO_CLOUDFLARE_API_TOKEN' ) ) {
			define( 'WPPO_CLOUDFLARE_API_TOKEN', 'test-token' );
		}
		if ( ! defined( 'WPPO_BUNNY_API_KEY' ) ) {
			define( 'WPPO_BUNNY_API_KEY', 'test-bunny-key' );
		}

		$this->requests            = array();
		$this->state               = array();
		$this->transport_response  = array( 'response' => array( 'code' => 200 ) );
		$this->debug_log           = array();
		$this->actions             = array();
		$this->pre_http_callback   = null;
		$this->pre_http_added      = false;
		$this->pre_http_removed    = false;
		$this->http_debug_callback = null;
		$this->http_debug_added    = false;
		$this->http_debug_removed  = false;

		Functions\stubs(
			array(
				'add_action',
				'add_filter',
				'remove_action',
				'remove_filter',
				'get_option',
				'get_transient',
				'set_transient',
				// Edge_Purger releases the coalescing window when a purge
				// fails, so a deduplicated failure path now reaches it.
				'delete_transient',
				'has_filter',
				'is_wp_error',
				'wp_remote_request',
				'wp_remote_retrieve_response_code',
			)
		);
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Test mock signature must match add_filter().
				if ( 'pre_http_request' === $hook ) {
					$this->pre_http_callback = $callback;
					$this->pre_http_added    = true;
				}
				return true;
			}
		);
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Test mock signature must match add_filter().
				if ( 'http_api_debug' === $hook ) {
					$this->http_debug_callback = $callback;
					$this->http_debug_added    = true;
				}
				return true;
			}
		);
		Functions\when( 'remove_filter' )->alias(
			function ( $hook, $callback ) {
				if ( 'pre_http_request' === $hook && $this->pre_http_callback === $callback ) {
					$this->pre_http_callback = null;
					$this->pre_http_removed  = true;
				}
				return true;
			}
		);
		Functions\when( 'remove_action' )->alias(
			function ( $hook, $callback ) {
				if ( 'http_api_debug' === $hook && $this->http_debug_callback === $callback ) {
					$this->http_debug_callback = null;
					$this->http_debug_removed  = true;
				}
				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->state ) ? $this->state[ $name ] : $fallback;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return $this->state[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $expiration = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Test mock signature must match set_transient().
				$this->state[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Test mock signature must match delete_transient().
				unset( $this->state[ $key ] );
				return true;
			}
		);
		Functions\when( 'has_filter' )->justReturn( true );
		Functions\when( 'is_wp_error' )->alias(
			static function ( $value ) {
				return $value instanceof \EdgePurgeCoordinatorWPError;
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'do_action' )->alias(
			function ( $hook, $arg_1 = null, $arg_2 = null, $arg_3 = null, $arg_4 = null, $arg_5 = null ) {
				$this->actions[] = (string) $hook;
				if ( 'http_api_debug' === $hook && is_callable( $this->http_debug_callback ) ) {
					call_user_func( $this->http_debug_callback, $arg_1, $arg_2, $arg_3, $arg_4, $arg_5 );
				} elseif ( 'wppo_debug_log' === $hook ) {
					$this->debug_log[] = (string) $arg_1;
				}
				return null;
			}
		);
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'http://example.com' . (string) $path;
			}
		);
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args = array() ) {
				$pre_response = false;
				if ( is_callable( $this->pre_http_callback ) ) {
					$pre_response = call_user_func( $this->pre_http_callback, false, $args, $url );
				}
				if ( false !== $pre_response ) {
					return $pre_response;
				}
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				$response         = $this->transport_response;
				do_action( 'http_api_debug', $response, 'response', '', $args, $url );
				return $response;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
			}
		);
	}

	/**
	 * Seed settings and clear the per-request settings memo.
	 *
	 * @param array $settings Plugin settings.
	 */
	private function set_settings( array $settings ): void {
		$this->state['wppo_settings'] = $settings;
		Util::clear_settings_cache();
	}

	/**
	 * One overlapping Cloudflare configuration performs one transport request.
	 */
	public function test_overlapping_cloudflare_is_deduplicated_for_one_event(): void {
		$this->install_stubs();
		$this->set_settings(
			array(
				'cache_settings' => array(
					'cdnPurgeService'  => 'cloudflare',
					'cloudflareZoneId' => 'shared-zone',
				),
				'edge_cache'     => array(
					'enabled'          => true,
					'cloudflareZoneId' => 'shared-zone',
				),
			)
		);

		$this->assertTrue( Edge_Purge_Coordinator::purge_after_cache_clear( 'all' ) );
		$this->assertCount( 1, $this->requests );
		$this->assertSame(
			'https://api.cloudflare.com/client/v4/zones/shared-zone/purge_cache',
			$this->requests[0]['url']
		);
		$this->assertTrue( $this->pre_http_added );
		$this->assertTrue( $this->pre_http_removed );
		$this->assertTrue( $this->http_debug_added );
		$this->assertTrue( $this->http_debug_removed );
	}

	/**
	 * Cloudflare and Bunny each retain their own full-cache transport path.
	 */
	public function test_cloudflare_and_bunny_each_run_once(): void {
		$this->install_stubs();
		$this->set_settings(
			array(
				'cache_settings' => array(
					'cdnPurgeService'  => 'cloudflare',
					'cloudflareZoneId' => 'shared-zone',
				),
				'edge_cache'     => array(
					'enabled'          => true,
					'cloudflareZoneId' => 'shared-zone',
					'bunnyPullZoneId'  => 'bunny-zone',
				),
			)
		);

		$this->assertTrue( Edge_Purge_Coordinator::purge_after_cache_clear( 'all' ) );
		$this->assertCount( 2, $this->requests );
		$this->assertSame(
			array(
				'https://api.cloudflare.com/client/v4/zones/shared-zone/purge_cache',
				'https://api.bunny.net/pullzone/bunny-zone/purgeCache',
			),
			array_column( $this->requests, 'url' )
		);
	}

	/**
	 * Legacy Varnish and edge Cloudflare remain independent provider paths.
	 */
	public function test_varnish_and_edge_cloudflare_are_not_collapsed(): void {
		$this->install_stubs();
		$this->set_settings(
			array(
				'cache_settings' => array(
					'cdnPurgeService'  => 'varnish',
					'varnishPurgeUrls' => array( 'http://127.0.0.1:8081/purge' ),
				),
				'edge_cache'     => array(
					'enabled'          => true,
					'cloudflareZoneId' => 'edge-zone',
				),
			)
		);

		$this->assertTrue( Edge_Purge_Coordinator::purge_after_cache_clear( 'all' ) );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( 'PURGE', $this->requests[0]['args']['method'] );
		$this->assertStringContainsString( 'edge-zone/purge_cache', $this->requests[1]['url'] );
	}

	/**
	 * The unchanged LiteSpeed integration still receives the full-clear sync.
	 */
	public function test_litespeed_sync_remains_once_for_all_clear(): void {
		$this->install_stubs();
		$_SERVER['SERVER_SOFTWARE']    = 'LiteSpeed';
		$this->state['active_plugins'] = array( 'litespeed-cache/litespeed-cache.php' );
		$this->set_settings(
			array(
				'cache_settings'        => array( 'cdnPurgeService' => 'none' ),
				'edge_cache'            => array( 'enabled' => false ),
				'litespeed_integration' => array( 'purgeSync' => true ),
			)
		);
		\PerformanceOptimise\Inc\LiteSpeed_Integration::reset_cache();

		$this->assertTrue( Edge_Purge_Coordinator::purge_after_cache_clear( 'all' ) );
		$this->assertCount( 1, array_keys( $this->actions, 'litespeed_purge_all', true ) );
		unset( $_SERVER['SERVER_SOFTWARE'] );
	}

	/**
	 * A single-page event keeps the edge URL-scoped Cloudflare request.
	 */
	public function test_single_page_keeps_url_scoped_cloudflare_scope(): void {
		$this->install_stubs();
		$this->set_settings(
			array(
				'cache_settings' => array(
					'cdnPurgeService'  => 'cloudflare',
					'cloudflareZoneId' => 'shared-zone',
				),
				'edge_cache'     => array(
					'enabled'          => true,
					'cloudflareZoneId' => 'shared-zone',
				),
			)
		);

		$this->assertTrue( Edge_Purge_Coordinator::purge_after_cache_clear( 'single_page', '/about/' ) );
		$this->assertCount( 1, $this->requests );
		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertSame( array( 'http://example.com/about/' ), $body['files'] );
	}

	/**
	 * A reused Cloudflare error retains both provider-specific debug messages.
	 */
	public function test_deduplicated_cloudflare_failure_preserves_provider_logging(): void {
		$this->install_stubs();
		$this->transport_response = new \EdgePurgeCoordinatorWPError( 'connection timed out' );
		$this->set_settings(
			array(
				'cache_settings' => array(
					'cdnPurgeService'  => 'cloudflare',
					'cloudflareZoneId' => 'shared-zone',
				),
				'edge_cache'     => array(
					'enabled'          => true,
					'cloudflareZoneId' => 'shared-zone',
				),
			)
		);

		$this->assertFalse( Edge_Purge_Coordinator::purge_after_cache_clear( 'all' ) );
		$this->assertCount( 1, $this->requests );
		$this->assertCount( 2, $this->debug_log );
		$this->assertStringContainsString( 'CDN purge failed [cloudflare]', $this->debug_log[0] );
		$this->assertStringContainsString( 'Edge purge failed [cloudflare-edge]', $this->debug_log[1] );
	}

	/**
	 * The edge lock still applies within later cache-clear events.
	 */
	public function test_edge_lock_preserved_across_events(): void {
		$this->install_stubs();
		$this->set_settings(
			array(
				'cache_settings' => array(
					'cdnPurgeService'  => 'cloudflare',
					'cloudflareZoneId' => 'shared-zone',
				),
				'edge_cache'     => array(
					'enabled'          => true,
					'cloudflareZoneId' => 'shared-zone',
				),
			)
		);

		$this->assertTrue( Edge_Purge_Coordinator::purge_after_cache_clear( 'all' ) );
		$this->assertTrue( Edge_Purge_Coordinator::purge_after_cache_clear( 'all' ) );
		$this->assertCount( 2, $this->requests );
	}

	/**
	 * Invalid hook payloads retain the legacy CDN TypeError and unhook the seams.
	 */
	public function test_invalid_payload_unhooks_temporary_http_callbacks(): void {
		$this->install_stubs();
		$this->set_settings( array() );

		try {
			Edge_Purge_Coordinator::purge_after_cache_clear( array( 'invalid' ) );
			$this->fail( 'Expected the legacy CDN string type contract to reject an array payload.' );
		} catch ( \TypeError $error ) {
			unset( $error );
		}

		$this->assertTrue( $this->pre_http_removed );
		$this->assertTrue( $this->http_debug_removed );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * No configured providers preserves the successful no-data result.
	 */
	public function test_no_provider_data_is_a_successful_noop(): void {
		$this->install_stubs();
		$this->set_settings(
			array(
				'cache_settings' => array( 'cdnPurgeService' => 'none' ),
				'edge_cache'     => array( 'enabled' => false ),
			)
		);

		$this->assertTrue( Edge_Purge_Coordinator::purge_after_cache_clear( 'all' ) );
		$this->assertSame( array(), $this->requests );
		$this->assertTrue( $this->pre_http_removed );
	}
}
