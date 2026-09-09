<?php
/**
 * Tests for real-user Web Vitals (RUM) collection and storage.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\RUM;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests the RUM beacon validation, rate limiting and aggregation.
 *
 * @package PerformanceOptimise\Tests
 */
class RumTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options store.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * In-memory transients store.
	 *
	 * @var array
	 */
	private $transients = array();

	/**
	 * Install get_option/update_option/transient/wp_hash stubs.
	 */
	private function install_stubs(): void {
		Functions\stubs(
			array(
				'get_option',
				'update_option',
				'get_transient',
				'set_transient',
				'delete_transient',
				'wp_hash',
				'sanitize_text_field',
				'esc_url_raw',
				'wp_unslash',
				'is_multisite',
				'wp_next_scheduled',
				'wp_schedule_single_event',
				'wp_rand',
			)
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return array_key_exists( $key, $this->transients ) ? $this->transients[ $key ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $expiration = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$this->transients[ $key ] = $value;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'wp_rand' )->justReturn( 2 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		// Deterministic token derivation so tests can predict the valid token.
		Functions\when( 'wp_hash' )->alias(
			static function ( $data ) {
				return 'h_' . $data;
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'is_multisite' )->justReturn( false );
	}

	/**
	 * Valid RUM token for the current day under the stubbed wp_hash.
	 *
	 * @param string $path Page path the token is minted for.
	 * @return string
	 */
	private function valid_token( string $path = '/' ): string {
		return 'h_wppo_rum_' . gmdate( 'Ymd' ) . '|' . $path;
	}

	/**
	 * Test that is_enabled reflects the performance_audit.rum_enabled setting.
	 */
	public function test_is_enabled_reflects_setting(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		Util::clear_settings_cache();
		$this->assertTrue( RUM::is_enabled() );

		$this->options['wppo_settings']['performance_audit']['rum_enabled'] = false;
		Util::clear_settings_cache();
		$this->assertFalse( RUM::is_enabled() );
	}

	/**
	 * Test that a valid beacon stores an aggregated sample per day/path.
	 */
	public function test_collect_stores_aggregated_sample(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		$_SERVER['REMOTE_ADDR']         = '203.0.113.5';

		$result = RUM::collect(
			array(
				'token' => $this->valid_token( '/about' ),
				'path'  => '/about/',
				'lcp'   => 2500.5,
				'cls'   => 0.08,
				'ttfb'  => 400,
			)
		);

		$this->assertTrue( $result['ok'] );

		$data  = RUM::get_data();
		$today = gmdate( 'Y-m-d' );
		$this->assertArrayHasKey( $today, $data );
		$this->assertArrayHasKey( '/about', $data[ $today ] );
		$this->assertSame( 1, $data[ $today ]['/about']['lcp']['n'] );
		$this->assertSame( 2500.5, $data[ $today ]['/about']['lcp']['sum'] );
		$this->assertSame( 0.08, $data[ $today ]['/about']['cls']['sum'] );
	}

	/**
	 * Test that a second sample for the same day/path accumulates.
	 */
	public function test_collect_accumulates_samples(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		$_SERVER['REMOTE_ADDR']         = '203.0.113.5';

		RUM::collect(
			array(
				'token' => $this->valid_token(),
				'path'  => '/',
				'lcp'   => 1000,
			)
		);
		RUM::collect(
			array(
				'token' => $this->valid_token(),
				'path'  => '/',
				'lcp'   => 3000,
			)
		);

		$data  = RUM::get_data();
		$today = gmdate( 'Y-m-d' );
		$this->assertSame( 2, $data[ $today ]['/']['lcp']['n'] );
		$this->assertSame( 4000.0, $data[ $today ]['/']['lcp']['sum'] );
		$this->assertSame( 1000.0, $data[ $today ]['/']['lcp']['min'] );
		$this->assertSame( 3000.0, $data[ $today ]['/']['lcp']['max'] );
	}

	/**
	 * Test that collection is rejected when the RUM setting is off.
	 */
	public function test_collect_rejects_when_disabled(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => false ),
		);
		$_SERVER['REMOTE_ADDR']         = '203.0.113.5';

		$result = RUM::collect(
			array(
				'token' => $this->valid_token(),
				'path'  => '/',
				'lcp'   => 1000,
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 400, $result['status'] );
	}

	/**
	 * Test that an invalid token is rejected.
	 */
	public function test_collect_rejects_invalid_token(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		$_SERVER['REMOTE_ADDR']         = '203.0.113.5';

		$result = RUM::collect(
			array(
				'token' => 'forged-token',
				'path'  => '/',
				'lcp'   => 1000,
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 401, $result['status'] );
	}

	/**
	 * Test that a token minted for one path is rejected for another page.
	 */
	public function test_collect_rejects_token_minted_for_other_path(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		$_SERVER['REMOTE_ADDR']         = '203.0.113.5';

		$result = RUM::collect(
			array(
				'token' => $this->valid_token( '/a/' ),
				'path'  => '/b/',
				'lcp'   => 1000,
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 401, $result['status'] );

		// Nothing may be stored for either path.
		$this->assertSame( array(), RUM::get_data() );
	}

	/**
	 * Test that a payload whose path does not match the token fails closed.
	 */
	public function test_collect_rejects_payload_path_token_mismatch(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		$_SERVER['REMOTE_ADDR']         = '203.0.113.5';

		$result = RUM::collect(
			array(
				'token' => $this->valid_token( '/' ),
				'path'  => '/checkout/',
				'lcp'   => 1000,
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 401, $result['status'] );
	}

	/**
	 * Test that is_valid_token accepts today + yesterday for the same path
	 * and rejects other paths or empty tokens.
	 */
	public function test_is_valid_token_scopes_to_path_and_day(): void {
		$this->install_stubs();

		$method = new \ReflectionMethod( RUM::class, 'is_valid_token' );
		$method->setAccessible( true );

		$today     = 'h_wppo_rum_' . gmdate( 'Ymd' ) . '|/a';
		$yesterday = 'h_wppo_rum_' . gmdate( 'Ymd', time() - DAY_IN_SECONDS ) . '|/a';
		$two_days  = 'h_wppo_rum_' . gmdate( 'Ymd', time() - ( 2 * DAY_IN_SECONDS ) ) . '|/a';

		$this->assertTrue( $method->invoke( null, $today, '/a/' ) );
		$this->assertTrue( $method->invoke( null, $yesterday, '/a/' ) );
		$this->assertFalse( $method->invoke( null, $today, '/b/' ) );
		$this->assertFalse( $method->invoke( null, $two_days, '/a/' ) );
		$this->assertFalse( $method->invoke( null, '', '/a/' ) );
	}

	/**
	 * Test that values outside the sane ranges are clamped, not stored raw.
	 */
	public function test_collect_clamps_out_of_range_values(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		$_SERVER['REMOTE_ADDR']         = '203.0.113.5';

		$result = RUM::collect(
			array(
				'token' => $this->valid_token(),
				'path'  => '/',
				'lcp'   => 999999999,
				'cls'   => -5,
			)
		);

		$this->assertTrue( $result['ok'] );
		$data  = RUM::get_data();
		$today = gmdate( 'Y-m-d' );
		$this->assertSame( 60000.0, $data[ $today ]['/']['lcp']['sum'] );
		$this->assertSame( 0.0, $data[ $today ]['/']['cls']['sum'] );
	}

	/**
	 * Test that a missing path yields a failed collection.
	 */
	public function test_collect_rejects_missing_path(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		$_SERVER['REMOTE_ADDR']         = '203.0.113.5';

		$result = RUM::collect(
			array(
				'token' => $this->valid_token(),
				'lcp'   => 1000,
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 400, $result['status'] );
	}

	/**
	 * Test that a rate-limited IP is rejected with a 429.
	 */
	public function test_collect_rate_limits_by_ip(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		$_SERVER['REMOTE_ADDR']         = '203.0.113.9';

		$key                      = Util::transient_key( 'wppo_rum_ratelimit_' . md5( '203.0.113.9' ) );
		$this->transients[ $key ] = RUM::RATE_LIMIT_PER_HOUR;

		$result = RUM::collect(
			array(
				'token' => $this->valid_token(),
				'path'  => '/',
				'lcp'   => 1000,
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 429, $result['status'] );
	}

	/**
	 * Test that print_config() emits the beacon config through
	 * wp_print_inline_script_tag() with tag-safe JSON — a crafted REQUEST_URI
	 * path must never split out of the <script> element (audit #888 finding 9).
	 */
	public function test_print_config_escapes_script_breakout(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		Functions\when( 'rest_url' )->justReturn( 'http://example.com/wp-json/performance-optimisation/v1/rum_collect' );

		$printed = array();
		Functions\when( 'wp_print_inline_script_tag' )->alias(
			static function ( $javascript, $attributes = array() ) use ( &$printed ) {
				$printed[] = array( $javascript, $attributes );
			}
		);

		$_SERVER['REQUEST_URI'] = '/a</script><script>alert(1)</script>b';

		RUM::print_config();

		$this->assertCount( 1, $printed );
		[ $javascript, $attributes ] = $printed[0];
		$this->assertStringStartsWith( 'window.wppoRum=', $javascript );
		// Tag characters must be hex-escaped so the JSON cannot terminate the
		// inline <script> element.
		$this->assertStringNotContainsString( '</', $javascript );
		$this->assertStringNotContainsString( '<script', $javascript );
		$this->assertStringContainsString( '\u003C', $javascript );
		$this->assertSame( array( 'id' => 'wppo-rum-config' ), $attributes );
	}

	/**
	 * Test that the aggregate option is hard-capped on write: preloaded days
	 * beyond the total-bucket budget are dropped oldest-first (audit #888
	 * finding 10).
	 */
	public function test_flush_caps_total_path_buckets(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		$_SERVER['REMOTE_ADDR']         = '203.0.113.7';

		$metric    = array(
			'n'   => 1,
			'sum' => 100.0,
			'min' => 100.0,
			'max' => 100.0,
		);
		$preloaded = array();
		for ( $day_offset = 13; $day_offset >= 1; $day_offset-- ) {
			$date               = gmdate( 'Y-m-d', time() - ( $day_offset * DAY_IN_SECONDS ) );
			$preloaded[ $date ] = array();
			for ( $p = 0; $p < 52; $p++ ) {
				$preloaded[ $date ][ "/old-{$day_offset}-{$p}/" ] = array( 'lcp' => $metric );
			}
		}
		$this->options[ RUM::OPTION ] = $preloaded;

		RUM::collect(
			array(
				'token' => $this->valid_token( '/trigger' ),
				'path'  => '/trigger/',
				'lcp'   => 1200,
			)
		);

		$data = RUM::get_data();

		$total_paths = 0;
		foreach ( $data as $day_bucket ) {
			$total_paths += count( $day_bucket );
		}
		$this->assertLessThanOrEqual( RUM::MAX_TOTAL_PATHS, $total_paths );
		// The newest preloaded day must survive; the oldest day is dropped.
		$newest = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		$this->assertArrayHasKey( $newest, $data );
		$this->assertArrayNotHasKey( gmdate( 'Y-m-d', time() - ( 13 * DAY_IN_SECONDS ) ), $data );
	}

	/**
	 * Test that a beacon lcpUrl is aggregated per path.
	 *
	 * @since NEXT
	 */
	public function test_collect_aggregates_lcp_url(): void {
		$this->install_stubs();
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		Util::clear_settings_cache();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

		$result = RUM::collect(
			array(
				'token'  => $this->valid_token( '/hero' ),
				'path'   => '/hero/',
				'lcp'    => 1800,
				'lcpUrl' => 'https://example.com/wp-content/uploads/hero.jpg',
			)
		);

		$this->assertTrue( $result['ok'] );
		$data  = RUM::get_data();
		$today = gmdate( 'Y-m-d' );
		$this->assertArrayHasKey( '/hero', $data[ $today ] );
		$this->assertArrayHasKey( 'lcpUrls', $data[ $today ]['/hero'] );
		$urls = $data[ $today ]['/hero']['lcpUrls'];
		$this->assertCount( 1, $urls );
		$entry = reset( $urls );
		$this->assertSame( 'https://example.com/wp-content/uploads/hero.jpg', $entry['url'] );
		$this->assertSame( 1, $entry['n'] );
	}

	/**
	 * Test that the field LCP reader gates on the sample count.
	 *
	 * Under 20 samples the heuristic wins (null); at 20 the URL is returned.
	 *
	 * @since NEXT
	 */
	public function test_get_field_lcp_url_gates_on_sample_count(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit'  => array( 'rum_enabled' => true ),
			'image_optimisation' => array( 'fieldLcpMinSamples' => 20 ),
		);
		Util::clear_settings_cache();
		$today                        = gmdate( 'Y-m-d' );
		$this->options[ RUM::OPTION ] = array(
			$today => array(
				'/hero/' => array(
					'lcpUrls' => array(
						'example.com/wp-content/uploads/hero.jpg' => array(
							'url'      => 'https://example.com/wp-content/uploads/hero.jpg',
							'n'        => 19,
							'lastSeen' => time(),
						),
					),
				),
			),
		);

		$this->assertNull( RUM::get_field_lcp_url( '/hero/' ) );

		$this->options[ RUM::OPTION ][ $today ]['/hero/']['lcpUrls']['example.com/wp-content/uploads/hero.jpg']['n'] = 20;

		$field = RUM::get_field_lcp_url( '/hero/' );
		$this->assertIsArray( $field );
		$this->assertSame( 'https://example.com/wp-content/uploads/hero.jpg', $field['url'] );
		$this->assertSame( 20, $field['n'] );
	}

	/**
	 * Test that a stale field LCP override self-corrects back to the heuristic.
	 *
	 * @since NEXT
	 */
	public function test_get_field_lcp_url_self_corrects_when_stale(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit'  => array( 'rum_enabled' => true ),
			'image_optimisation' => array( 'fieldLcpMinSamples' => 20 ),
		);
		Util::clear_settings_cache();
		$today                        = gmdate( 'Y-m-d' );
		$this->options[ RUM::OPTION ] = array(
			$today => array(
				'/hero/' => array(
					'lcpUrls' => array(
						'example.com/wp-content/uploads/old-hero.jpg' => array(
							'url'      => 'https://example.com/wp-content/uploads/old-hero.jpg',
							'n'        => 25,
							'lastSeen' => time() - ( 2 * DAY_IN_SECONDS ),
						),
					),
				),
			),
		);

		$this->assertNull( RUM::get_field_lcp_url( '/hero/' ) );
	}

	/**
	 * Test that suspicious lcpUrl values are dropped while numeric data is kept.
	 *
	 * @since NEXT
	 */
	public function test_collect_drops_suspicious_lcp_url(): void {
		$this->install_stubs();
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		Util::clear_settings_cache();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

		$result = RUM::collect(
			array(
				'token'  => $this->valid_token( '/hero' ),
				'path'   => '/hero/',
				'lcp'    => 1800,
				'lcpUrl' => 'data:image/png;base64,AAAA',
			)
		);

		$this->assertTrue( $result['ok'] );
		$data  = RUM::get_data();
		$today = gmdate( 'Y-m-d' );
		$this->assertSame( 1, $data[ $today ]['/hero']['lcp']['n'] );
		$this->assertArrayNotHasKey( 'lcpUrls', $data[ $today ]['/hero'] );
	}
}
