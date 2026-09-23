<?php
/**
 * Characterization tests for the REF-011 settings-schema ownership move (issue #1520).
 *
 * Pins the allowlist snapshot (14 keys, no `core_tweaks`), the per-tab
 * sanitizer map, sanitizer vectors (branch order, exclude/preload/delay/list
 * precedence, XSS payloads), and `Util::` vs `Settings_Store::` proxy
 * equivalence. `Settings_Store` is the canonical owner; `Util::` keeps thin
 * facade proxies/aliases so existing callers keep working untouched.
 *
 * @package PerformanceOptimise\Tests
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Settings_Store;
use PerformanceOptimise\Inc\Util;

/**
 * Settings schema allowlist + sanitizer characterization tests.
 *
 * @package PerformanceOptimise\Tests
 */
class SettingsSchemaTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Stub the WP sanitizer/filter APIs with minimal realistic behavior.
	 *
	 * Both classes under test share these stubs, so proxy-equivalence
	 * assertions stay meaningful while XSS vectors still exercise the
	 * tag/quote stripping branches.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) );
			}
		);
		Functions\when( 'sanitize_textarea_field' )->alias(
			static function ( $value ) {
				return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) );
			}
		);
		Functions\when( 'esc_url_raw' )->alias(
			static function ( $url ) {
				return preg_replace( '/[\s<>"\']/', '', (string) $url );
			}
		);
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	/**
	 * Expected allowlist snapshot (order-sensitive; `core_tweaks` narrowed out).
	 *
	 * @return string[]
	 */
	private static function expected_allowlist(): array {
		return array(
			'file_optimisation',
			'preload_settings',
			'image_optimisation',
			'database_cleanup',
			'object_cache',
			'performance_audit',
			'cache_settings',
			'litespeed_integration',
			'llms_txt',
			'od_integration',
			'bfcache',
			'perf_translations',
			'ai_adaptive',
			'edge_cache',
		);
	}

	/**
	 * Expected sanitizer map snapshot.
	 *
	 * @return array<string,string>
	 */
	private static function expected_sanitizer_map(): array {
		return array(
			'cache_settings'        => 'sanitize_cache_settings',
			'file_optimisation'     => 'sanitize_file_optimisation',
			'preload_settings'      => 'sanitize_scalar_setting',
			'image_optimisation'    => 'sanitize_scalar_setting',
			'performance_audit'     => 'sanitize_scalar_setting',
			'database_cleanup'      => 'sanitize_scalar_setting',
			'object_cache'          => 'sanitize_scalar_setting',
			'litespeed_integration' => 'sanitize_mode_value',
			'llms_txt'              => 'sanitize_scalar_setting',
			'od_integration'        => 'sanitize_scalar_setting',
			'bfcache'               => 'sanitize_scalar_setting',
			'perf_translations'     => 'sanitize_scalar_setting',
			'ai_adaptive'           => 'sanitize_scalar_setting',
			'edge_cache'            => 'sanitize_scalar_setting',
		);
	}

	/**
	 * The allowlist snapshot must be byte-identical on the owner and the alias.
	 */
	public function test_allowlist_snapshot_and_parity(): void {
		$this->assertSame( self::expected_allowlist(), Settings_Store::ALLOWED_SETTINGS_KEYS );
		$this->assertSame( Settings_Store::ALLOWED_SETTINGS_KEYS, Util::ALLOWED_SETTINGS_KEYS );
		$this->assertSame( Settings_Store::get_allowed_settings_keys(), Util::get_allowed_settings_keys() );
		$this->assertNotContains( 'core_tweaks', Settings_Store::ALLOWED_SETTINGS_KEYS );
		$this->assertNotContains( 'core_tweaks', Util::ALLOWED_SETTINGS_KEYS );
	}

	/**
	 * The tabs alias must mirror the keys on both classes.
	 */
	public function test_tabs_alias_parity(): void {
		$this->assertSame( Settings_Store::ALLOWED_SETTINGS_KEYS, Settings_Store::ALLOWED_SETTINGS_TABS );
		$this->assertSame( Settings_Store::ALLOWED_SETTINGS_TABS, Util::ALLOWED_SETTINGS_TABS );
	}

	/**
	 * The sanitizer map snapshot must be identical on the owner and the proxy.
	 */
	public function test_sanitizer_map_snapshot_and_parity(): void {
		$this->assertSame( self::expected_sanitizer_map(), Settings_Store::get_settings_sanitizer_map() );
		$this->assertSame( Settings_Store::get_settings_sanitizer_map(), Util::get_settings_sanitizer_map() );
	}

	/**
	 * Branch-order vectors: pinned branches win over the generic scalar rules.
	 */
	public function test_recursive_branch_order_vectors(): void {
		$input = array(
			'mode'                         => 'evil',
			'ttlOverrides'                 => array(
				'post' => 6,
				'evil' => 99,
			),
			'cdnMapping'                   => array(
				array(
					'cdn_url' => 'https://cdn.example.com',
				),
			),
			'outage_bypassed'              => 'false',
			'speculationRumGating'         => 'banana',
			'autoLcpPreload'               => 'false',
			'speculationPrerenderList'     => 'false',
			'wooSafeMode'                  => 'banana',
			'delayJSPreset'                => 'banana',
			'delayJSExternalOnly'          => '1',
			'delayJSBuilderPreset'         => 'banana',
			'lazyRenderBelowFold'          => '0',
			'lazyRenderExcludeBuilders'    => 'banana',
			'unusedCSSRegressionThreshold' => 500,
			'usedCSSDeliveryMode'          => 'banana',
			'maxLongestEdgePx'             => '',
			'ccssGenTimeout'               => 0,
			'ccssInlineBudgetKb'           => 500,
			'ccssCommerceExclude'          => '0',
			'speculationTopUrlsLimit'      => 99,
			'rum_sample_rate'              => 200,
			'field_lcp_min_samples'        => -5,
			'speculation_max_urls'         => 99,
			'anomaly_tolerance_pct'        => 500.0,
			'anomaly_tolerance_abs'        => 5.0,
			'delayJSExcludeUrls'           => "https://example.com/a\nhttps://example.com/b",
			'elementorSafeMode'            => 'banana',
			'safeMode'                     => 'false',
			'ccssMaxRetries'               => 99,
			'nested'                       => array(
				'mode' => 'wppo',
			),
			'!!!'                          => 'dropped',
		);

		$clean = Settings_Store::sanitize_settings_recursively( $input );

		$this->assertSame( 'auto', $clean['mode'] );
		$this->assertSame( array( 'post' => 6 ), $clean['ttlOverrides'] );
		$this->assertSame( 'https://cdn.example.com', $clean['cdnMapping'][0]['cdn_url'] );
		$this->assertFalse( $clean['outage_bypassed'] );
		$this->assertTrue( $clean['speculationRumGating'] );
		$this->assertFalse( $clean['autoLcpPreload'] );
		// Pinned bool before the generic stripos 'list' textarea branch.
		$this->assertFalse( $clean['speculationPrerenderList'] );
		$this->assertTrue( $clean['wooSafeMode'] );
		$this->assertSame( 'safe', $clean['delayJSPreset'] );
		$this->assertTrue( $clean['delayJSExternalOnly'] );
		$this->assertTrue( $clean['delayJSBuilderPreset'] );
		$this->assertFalse( $clean['lazyRenderBelowFold'] );
		$this->assertTrue( $clean['lazyRenderExcludeBuilders'] );
		$this->assertSame( 20, $clean['unusedCSSRegressionThreshold'] );
		$this->assertSame( 'file', $clean['usedCSSDeliveryMode'] );
		$this->assertSame( 2560, $clean['maxLongestEdgePx'] );
		$this->assertSame( 25, $clean['ccssGenTimeout'] );
		$this->assertSame( 14, $clean['ccssInlineBudgetKb'] );
		$this->assertFalse( $clean['ccssCommerceExclude'] );
		$this->assertSame( 2, $clean['speculationTopUrlsLimit'] );
		$this->assertSame( 100, $clean['rum_sample_rate'] );
		$this->assertSame( 1, $clean['field_lcp_min_samples'] );
		$this->assertSame( 5, $clean['speculation_max_urls'] );
		$this->assertSame( 50.0, $clean['anomaly_tolerance_pct'] );
		$this->assertSame( 1.0, $clean['anomaly_tolerance_abs'] );
		// Pinned textarea before the generic `url` esc_url_raw branch.
		$this->assertSame( "https://example.com/a\nhttps://example.com/b", $clean['delayJSExcludeUrls'] );
		$this->assertTrue( $clean['elementorSafeMode'] );
		$this->assertFalse( $clean['safeMode'] );
		$this->assertSame( 5, $clean['ccssMaxRetries'] );
		$this->assertSame( 'wppo', $clean['nested']['mode'] );
		$this->assertArrayNotHasKey( '', $clean );
		$this->assertArrayNotHasKey( '!!!', $clean );

		$this->assertSame( $clean, Util::sanitize_settings_recursively( $input ), 'Util:: proxy must be byte-identical to the owner.' );
	}

	/**
	 * Scalar heuristic vectors: bool/numeric pins, key-name branches, fallback.
	 */
	public function test_scalar_heuristic_vectors(): void {
		$this->assertTrue( Settings_Store::sanitize_scalar_setting( 'anything', true ) );
		$this->assertSame( 42, Settings_Store::sanitize_scalar_setting( 'anything', '42' ) );
		$this->assertSame( 'secret', Settings_Store::sanitize_scalar_setting( 'pagespeed_api_key', 'secret' ) );
		// exclude/preload/delay/list branch (textarea).
		$this->assertSame( 'a b', Settings_Store::sanitize_scalar_setting( 'excludeScripts', 'a b' ) );
		// url/cdn/origin branch (esc_url_raw strips the injected quote/bracket).
		$this->assertSame( 'https://example.com/x', Settings_Store::sanitize_scalar_setting( 'someUrl', 'https://example.com/x' ) );
		$this->assertSame( 'plain', Settings_Store::sanitize_scalar_setting( 'plainKey', 'plain' ) );

		$this->assertSame(
			Settings_Store::sanitize_scalar_setting( 'excludeScripts', 'a b' ),
			Util::sanitize_scalar_setting( 'excludeScripts', 'a b' )
		);
	}

	/**
	 * Mode allowlist vectors.
	 */
	public function test_mode_value_vectors(): void {
		foreach ( array( 'auto', 'wppo', 'litespeed', 'standalone' ) as $valid ) {
			$this->assertSame( $valid, Settings_Store::sanitize_mode_value( $valid ) );
			$this->assertSame( $valid, Util::sanitize_mode_value( $valid ) );
		}
		$this->assertSame( 'auto', Settings_Store::sanitize_mode_value( 'evil' ) );
		$this->assertSame( 'auto', Util::sanitize_mode_value( 'evil' ) );
	}

	/**
	 * TTL-override vectors: per-type + hours allowlists.
	 */
	public function test_ttl_overrides_vectors(): void {
		$input    = array(
			'post'       => 6,
			'page'       => '24',
			'product'    => 7,
			'evil'       => 6,
			'attachment' => 6,
		);
		$expected = array(
			'post' => 6,
			'page' => 24,
		);
		$this->assertSame( $expected, Settings_Store::sanitize_ttl_overrides( $input ) );
		$this->assertSame( $expected, Util::sanitize_ttl_overrides( $input ) );
	}

	/**
	 * CDN-mapping vectors: entry sanitization, legacy keys, max cap.
	 */
	public function test_cdn_mapping_vectors(): void {
		$entries = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$entries[] = array(
				'cdn_url'      => 'https://cdn' . $i . '.example.com',
				'include_dirs' => 'wp-content|wp-includes',
			);
		}
		$entries[] = array( 'ori' => 'https://example.com' );

		$store = Settings_Store::sanitize_cdn_mapping( $entries );
		$this->assertCount( 5, $store, 'Mapping must be capped at the default max of 5.' );
		$this->assertSame( 'https://cdn0.example.com', $store[0]['cdn_url'] );

		$legacy = Settings_Store::sanitize_cdn_mapping(
			array(
				array(
					'cdn_url' => 'https://cdn.example.com',
					'cdns'    => array( 'https://cdn2.example.com' ),
				),
			)
		);
		$this->assertSame( array( 'https://cdn2.example.com' ), $legacy[0]['cdn_urls'] );

		$this->assertSame( $store, Util::sanitize_cdn_mapping( $entries ) );
	}

	/**
	 * Per-tab entry points delegate to the recursive sanitizer on both classes.
	 */
	public function test_per_tab_entry_points(): void {
		$input = array( 'mode' => 'evil' );
		$this->assertSame( array( 'mode' => 'auto' ), Settings_Store::sanitize_cache_settings( $input ) );
		$this->assertSame( array( 'mode' => 'auto' ), Settings_Store::sanitize_file_optimisation( $input ) );
		$this->assertSame( Settings_Store::sanitize_cache_settings( $input ), Util::sanitize_cache_settings( $input ) );
		$this->assertSame( Settings_Store::sanitize_file_optimisation( $input ), Util::sanitize_file_optimisation( $input ) );
	}

	/**
	 * XSS payloads must be neutralized identically by owner and proxy.
	 */
	public function test_xss_payload_vectors(): void {
		$input = array(
			'heartbeatControl' => '<script>alert(1)</script>60s',
			'someUrl'          => 'https://example.com/"><svg onload=alert(1)>',
			'excludeScripts'   => "<script>alert(1)</script>\nfoo bar",
		);

		$store = Settings_Store::sanitize_settings_recursively( $input );

		$this->assertStringNotContainsString( '<script>', $store['heartbeatControl'] );
		$this->assertStringContainsString( '60s', $store['heartbeatControl'] );
		$this->assertStringNotContainsString( '<', $store['someUrl'] );
		$this->assertStringNotContainsString( '"', $store['someUrl'] );
		$this->assertStringNotContainsString( '<script>', $store['excludeScripts'] );

		$this->assertSame( $store, Util::sanitize_settings_recursively( $input ) );
	}
}
