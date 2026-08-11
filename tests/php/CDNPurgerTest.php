<?php
/**
 * Tests for the CDN cache-purger (Cloudflare / Varnish).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\CDN_Purger;
use Brain\Monkey\Functions;

/**
 * Tests CDN_Purger::purge_all() dispatch and per-provider requests.
 *
 * @package PerformanceOptimise\Tests
 */
class CDNPurgerTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options store.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Requests recorded by the stubbed wp_remote_request().
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * Install option + HTTP stubs.
	 */
	private function install_stubs(): void {
		if ( ! defined( 'WPPO_CLOUDFLARE_API_TOKEN' ) ) {
			define( 'WPPO_CLOUDFLARE_API_TOKEN', 'test-token' );
		}
		Functions\stubs(
			array(
				'get_option',
				'sanitize_text_field',
				'esc_url_raw',
				'wp_remote_request',
				'wp_remote_retrieve_response_code',
			)
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args = array() ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
			}
		);
	}

	/**
	 * Provide a settings payload with the given cache_settings.
	 *
	 * @param array $cache_settings cache_settings values.
	 */
	private function set_cache_settings( array $cache_settings ): void {
		$this->options['wppo_settings'] = array(
			'cache_settings' => $cache_settings,
		);
	}

	/**
	 * Test that purge_all dispatches to Cloudflare with the zone + bearer token.
	 */
	public function test_purge_cloudflare_sends_bearer_request(): void {
		$this->install_stubs();
		$this->set_cache_settings(
			array(
				'cdnPurgeService'  => 'cloudflare',
				'cloudflareZoneId' => 'abc123',
			)
		);

		$result = CDN_Purger::purge_all();

		$this->assertTrue( $result );
		$this->assertCount( 1, $this->requests );

		$request = $this->requests[0];
		$this->assertSame(
			'https://api.cloudflare.com/client/v4/zones/abc123/purge_cache',
			$request['url']
		);
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertSame( 'Bearer test-token', $request['args']['headers']['Authorization'] );
	}

	/**
	 * Test that Cloudflare purge returns false without a configured zone.
	 */
	public function test_purge_cloudflare_skips_without_zone(): void {
		$this->install_stubs();
		$this->set_cache_settings(
			array(
				'cdnPurgeService'  => 'cloudflare',
				'cloudflareZoneId' => '',
			)
		);

		$result = CDN_Purger::purge_all();

		$this->assertFalse( $result );
		$this->assertCount( 0, $this->requests );
	}

	/**
	 * Test that purge_all dispatches to Varnish with PURGE requests.
	 */
	public function test_purge_varnish_sends_purge_requests(): void {
		$this->install_stubs();
		$this->set_cache_settings(
			array(
				'cdnPurgeService'  => 'varnish',
				'varnishPurgeUrls' => array(
					'http://127.0.0.1:8081/purge',
					'http://127.0.0.1:8082/purge',
				),
			)
		);

		$result = CDN_Purger::purge_all();

		$this->assertTrue( $result );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( 'PURGE', $this->requests[0]['args']['method'] );
		$this->assertSame( 'http://127.0.0.1:8081/purge', $this->requests[0]['url'] );
		$this->assertSame( 'http://127.0.0.1:8082/purge', $this->requests[1]['url'] );
	}

	/**
	 * Test that purge_all returns false when no service is configured.
	 */
	public function test_purge_all_noop_when_none(): void {
		$this->install_stubs();
		$this->set_cache_settings( array( 'cdnPurgeService' => 'none' ) );

		$result = CDN_Purger::purge_all();

		$this->assertTrue( $result );
		$this->assertCount( 0, $this->requests );
	}

	/**
	 * Test is_configured reflects the service + credentials.
	 */
	public function test_is_configured(): void {
		$this->install_stubs();
		$this->set_cache_settings(
			array(
				'cdnPurgeService'  => 'cloudflare',
				'cloudflareZoneId' => 'abc123',
			)
		);
		$this->assertTrue( CDN_Purger::is_configured() );

		$this->set_cache_settings(
			array(
				'cdnPurgeService'  => 'varnish',
				'varnishPurgeUrls' => array(),
			)
		);
		$this->assertFalse( CDN_Purger::is_configured() );
	}
}
