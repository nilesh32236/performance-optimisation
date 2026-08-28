<?php
/**
 * Tests for N2 Edge HTML Cache Adapter (Cloudflare Workers / Bunny Edge).
 *
 * Covers is_enabled gate, worker template generation, wrangler.toml/bunny template,
 * purge bridge and Util::transient_key lock.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Edge_Cache;
use PerformanceOptimise\Inc\Edge_Purger;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Edge cache tests.
 */
class EdgeCacheTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * In-memory transients.
	 *
	 * @var array
	 */
	private $transients = array();

	/**
	 * Requests recorded by wp_remote_request stub.
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * Install stubs.
	 *
	 * @return void
	 */
	private function install_stubs(): void {
		if ( ! defined( 'WPPO_CLOUDFLARE_API_TOKEN' ) ) {
			define( 'WPPO_CLOUDFLARE_API_TOKEN', 'test-token' );
		}
		if ( ! defined( 'WPPO_BUNNY_API_KEY' ) ) {
			define( 'WPPO_BUNNY_API_KEY', 'bunny-key' );
		}
		if ( ! defined( 'WPPO_PLUGIN_PATH' ) ) {
			define( 'WPPO_PLUGIN_PATH', dirname( __DIR__, 3 ) . '/' );
		}
		Functions\stubs(
			array(
				'get_option',
				'get_transient',
				'set_transient',
				'delete_transient',
				'apply_filters',
				'do_action',
				'is_multisite',
				'get_current_blog_id',
				'home_url',
				'esc_url_raw',
				'sanitize_text_field',
				'sanitize_title',
				'wp_parse_url',
				'wp_json_encode',
				'wp_remote_request',
				'wp_remote_retrieve_response_code',
				'is_wp_error',
			)
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return array_key_exists( $key, $this->transients ) ? $this->transients[ $key ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $exp = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$this->transients[ $key ] = $value;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				// For config filter, return second arg; for enabled filter, return bool as is.
				return $value;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'home_url' )->alias(
			function ( $path = '' ) {
				return 'http://example.com' . $path;
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_title' )->alias(
			function ( $title ) {
				return strtolower( preg_replace( '/[^a-z0-9]+/', '-', $title ) );
			}
		);
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url, $comp = -1 ) {
				$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
				if ( -1 === $comp ) {
					return $parts;
				}
				return $parts[ $comp ] ?? null;
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			function ( $data ) {
				return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
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
		Functions\when( 'is_wp_error' )->justReturn( false );
	}

	/**
	 * Is enabled false by default.
	 *
	 * @return void
	 */
	public function test_is_enabled_false_by_default(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array( 'edge_cache' => array( 'enabled' => false ) );
		Util::clear_settings_cache();
		$this->assertFalse( Edge_Cache::is_enabled() );
	}

	/**
	 * Is enabled true when enabled.
	 *
	 * @return void
	 */
	public function test_is_enabled_true_when_set(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array( 'edge_cache' => array( 'enabled' => true ) );
		Util::clear_settings_cache();
		$this->assertTrue( Edge_Cache::is_enabled() );
	}

	/**
	 * Worker template contains stale-while-revalidate and placeholders replaced.
	 *
	 * @return void
	 */
	public function test_worker_template_has_swr(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'edge_cache' => array(
				'enabled'              => true,
				'ttl'                  => 300,
				'staleWhileRevalidate' => 86400,
			),
		);
		Util::clear_settings_cache();
		$js = Edge_Cache::get_worker_js();
		$this->assertStringContainsString( 'stale-while-revalidate', $js );
		$this->assertStringContainsString( '300', $js );
		$this->assertStringContainsString( '86400', $js );
		$this->assertStringNotContainsString( '{{CACHE_TTL}}', $js );
		$this->assertStringNotContainsString( '{{SWR}}', $js );
	}

	/**
	 * Wrangler toml generator contains name and origin.
	 *
	 * @return void
	 */
	public function test_wrangler_toml_generation(): void {
		$this->install_stubs();
		Util::clear_settings_cache();
		$toml = Edge_Cache::get_wrangler_toml(
			array(
				'origin_url' => 'https://example.com',
				'cache_ttl'  => 600,
				'swr'        => 1000,
				'provider'   => 'cloudflare',
			)
		);
		$this->assertStringContainsString( 'cloudflare-worker.js', $toml );
		$this->assertStringContainsString( 'https://example.com', $toml );
		$this->assertStringContainsString( '600', $toml );
	}

	/**
	 * Bunny edge js contains SWR.
	 *
	 * @return void
	 */
	public function test_bunny_edge_js_has_swr(): void {
		$this->install_stubs();
		Util::clear_settings_cache();
		$js = Edge_Cache::get_bunny_edge_js(
			array(
				'origin_url' => 'https://example.com',
				'cache_ttl'  => 300,
				'swr'        => 86400,
				'provider'   => 'bunny',
			)
		);
		$this->assertStringContainsString( 'stale-while-revalidate', $js );
		$this->assertStringContainsString( '86400', $js );
	}

	/**
	 * Purge is no-op when disabled.
	 *
	 * @return void
	 */
	public function test_purge_noop_when_disabled(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array( 'edge_cache' => array( 'enabled' => false ) );
		Util::clear_settings_cache();
		$result = Edge_Purger::purge_all( 'all', null );
		$this->assertTrue( $result );
		$this->assertCount( 0, $this->requests );
	}

	/**
	 * Purge lock uses Util::transient_key (multisite-safe) and prevents duplicate.
	 *
	 * @return void
	 */
	public function test_purge_lock_is_transient_key_and_blocks_duplicate(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'edge_cache' => array(
				'enabled'          => true,
				'cloudflareZoneId' => 'z123',
				'bunnyPullZoneId'  => '1',
			),
		);
		Util::clear_settings_cache();
		// First purge sets lock.
		Edge_Purger::purge_all( 'all', null );
		$this->assertTrue( Edge_Purger::has_purge_lock() );
		$lock_key = Edge_Purger::get_purge_lock_key();
		$this->assertSame( Util::transient_key( 'wppo_edge_purge_lock' ), $lock_key );
		$this->assertArrayHasKey( $lock_key, $this->transients );

		$count_first = count( $this->requests );
		// Second purge while lock active should not send requests.
		Edge_Purger::purge_all( 'all', null );
		$this->assertCount( $count_first, $this->requests );
	}

	/**
	 * Single page purge does not hit edge (all-or-nothing).
	 *
	 * @return void
	 */
	public function test_purge_single_page_does_not_hit_edge(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'edge_cache' => array(
				'enabled'          => true,
				'cloudflareZoneId' => 'z123',
			),
		);
		Util::clear_settings_cache();
		$result = Edge_Purger::purge_all( 'single_page', '/about/' );
		$this->assertTrue( $result );
		$this->assertCount( 0, $this->requests );
	}

	/**
	 * Purge sends both CF and Bunny when both configured.
	 *
	 * @return void
	 */
	public function test_purge_sends_both_providers(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'edge_cache' => array(
				'enabled'          => true,
				'cloudflareZoneId' => 'z123',
				'bunnyPullZoneId'  => '456',
			),
		);
		Util::clear_settings_cache();
		// Ensure no lock.
		$this->transients = array();
		Edge_Purger::purge_all( 'all', null );
		// Should have 2 requests (CF + Bunny) when both zones present.
		$this->assertCount( 2, $this->requests );
		$this->assertStringContainsString( 'cloudflare', $this->requests[0]['url'] );
		$this->assertStringContainsString( 'bunny', $this->requests[1]['url'] );
	}
}
