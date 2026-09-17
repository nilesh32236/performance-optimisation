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
		RUM::clear_field_lcp_cache();
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
	 * @since 2.0.0
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
	 * @since 2.0.0
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
				'/hero' => array(
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

		$this->assertNull( RUM::get_field_lcp_url( '/hero' ) );

		$this->options[ RUM::OPTION ][ $today ]['/hero']['lcpUrls']['example.com/wp-content/uploads/hero.jpg']['n'] = 20;
		RUM::clear_field_lcp_cache();

		$field = RUM::get_field_lcp_url( '/hero' );
		$this->assertIsArray( $field );
		$this->assertSame( 'https://example.com/wp-content/uploads/hero.jpg', $field['url'] );
		$this->assertSame( 20, $field['n'] );
	}

	/**
	 * Test that a stale field LCP override self-corrects back to the heuristic.
	 *
	 * @since 2.0.0
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
				'/hero' => array(
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

		$this->assertNull( RUM::get_field_lcp_url( '/hero' ) );
	}

	/**
	 * Test get_field_lcp_url honours ai_adaptive.field_lcp_min_samples (issue #1200).
	 *
	 * The canonical resolver prefers the additive ai_adaptive setting over
	 * the legacy image_optimisation key so both read paths share one gate.
	 *
	 * @since NEXT
	 */
	public function test_get_field_lcp_url_prefers_ai_adaptive_min_samples(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit'  => array( 'rum_enabled' => true ),
			'image_optimisation' => array( 'fieldLcpMinSamples' => 20 ),
			'ai_adaptive'        => array( 'field_lcp_min_samples' => 5 ),
		);
		Util::clear_settings_cache();
		$today                        = gmdate( 'Y-m-d' );
		$this->options[ RUM::OPTION ] = array(
			$today => array(
				'/hero' => array(
					'lcpUrls' => array(
						'example.com/wp-content/uploads/hero.jpg' => array(
							'url'      => 'https://example.com/wp-content/uploads/hero.jpg',
							'n'        => 5,
							'lastSeen' => time(),
						),
					),
				),
			),
		);

		$field = RUM::get_field_lcp_url( '/hero' );
		$this->assertIsArray( $field );
		$this->assertSame( 'https://example.com/wp-content/uploads/hero.jpg', $field['url'] );
	}

	/**
	 * Test get_field_lcp_min_samples clamps extreme values to 1-1000.
	 *
	 * A huge admin value (1000000) must not perpetually pin auto-tune to
	 * provisional; zero/negative values fail open to the 20 default.
	 *
	 * @since NEXT
	 */
	public function test_get_field_lcp_min_samples_clamps_extreme_values(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'ai_adaptive' => array( 'field_lcp_min_samples' => 1000000 ),
		);
		Util::clear_settings_cache();
		$this->assertSame( 1000, RUM::get_field_lcp_min_samples() );

		$this->options['wppo_settings'] = array(
			'ai_adaptive' => array( 'field_lcp_min_samples' => 0 ),
		);
		Util::clear_settings_cache();
		$this->assertSame( 20, RUM::get_field_lcp_min_samples() );
	}

	/**
	 * Test that a beacon device/template pair is aggregated into a bounded segment.
	 *
	 * @since 2.0.0
	 */
	public function test_collect_aggregates_device_template_segment(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		Util::clear_settings_cache();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

		$result = RUM::collect(
			array(
				'token'    => $this->valid_token( '/seg' ),
				'path'     => '/seg/',
				'lcp'      => 1800,
				'device'   => 'Mobile',
				'template' => 'Single!',
			)
		);

		$this->assertTrue( $result['ok'] );
		$data  = RUM::get_data();
		$today = gmdate( 'Y-m-d' );
		$this->assertArrayHasKey( 'lcpSeg', $data[ $today ]['/seg'] );
		$seg = $data[ $today ]['/seg']['lcpSeg'];
		// Template is lowercased + allowlisted; device is allowlisted; connection defaults to unknown.
		$this->assertArrayHasKey( 'mobile|single|unknown', $seg );
		$this->assertSame( 1, $seg['mobile|single|unknown']['n'] );
		$this->assertSame( array( 1800.0 ), $seg['mobile|single|unknown']['samples'] );
	}

	/**
	 * Test that an invalid device falls back to unknown without rejecting the sample.
	 *
	 * @since 2.0.0
	 */
	public function test_collect_falls_back_to_unknown_segment(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		Util::clear_settings_cache();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

		$result = RUM::collect(
			array(
				'token'  => $this->valid_token( '/seg' ),
				'path'   => '/seg/',
				'lcp'    => 1800,
				'device' => 'tablet',
			)
		);

		$this->assertTrue( $result['ok'] );
		$data  = RUM::get_data();
		$today = gmdate( 'Y-m-d' );
		$this->assertArrayHasKey( 'unknown|unknown|unknown', $data[ $today ]['/seg']['lcpSeg'] );
	}

	/**
	 * Test that the segmented p75 reader gates on the sample count.
	 *
	 * Under 20 samples no row is returned; at 20 the segment row with the
	 * computed p75 is returned, slowest-first.
	 *
	 * @since 2.0.0
	 */
	public function test_get_field_lcp_p75_by_segment_gates_on_sample_count(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit'  => array( 'rum_enabled' => true ),
			'image_optimisation' => array( 'fieldLcpMinSamples' => 20 ),
		);
		Util::clear_settings_cache();
		$today                        = gmdate( 'Y-m-d' );
		$this->options[ RUM::OPTION ] = array(
			$today => array(
				'/shop' => array(
					'lcpSeg' => array(
						'mobile|single' => array(
							'device'   => 'mobile',
							'template' => 'single',
							'n'        => 19,
							'sum'      => 57000.0,
							'min'      => 3000.0,
							'max'      => 3000.0,
							'samples'  => array_fill( 0, 19, 3000.0 ),
						),
					),
				),
			),
		);

		$this->assertSame( array(), RUM::get_field_lcp_p75_by_segment() );

		$this->options[ RUM::OPTION ][ $today ]['/shop']['lcpSeg']['mobile|single']['n']       = 20;
		$this->options[ RUM::OPTION ][ $today ]['/shop']['lcpSeg']['mobile|single']['samples'] = array_fill( 0, 20, 3000.0 );
		// The aggregate is memoized per request; reset to simulate a fresh
		// request after the underlying data changed.
		RUM::clear_field_lcp_cache();

		$rows = RUM::get_field_lcp_p75_by_segment();
		$this->assertCount( 1, $rows );
		$this->assertSame( '/shop', $rows[0]['path'] );
		$this->assertSame( 'mobile', $rows[0]['device'] );
		$this->assertSame( 'single', $rows[0]['template'] );
		$this->assertSame( 20, $rows[0]['n'] );
		$this->assertSame( 3000.0, $rows[0]['p75'] );
	}

	/**
	 * Test that the segmented p75 read path performs no option/transient writes.
	 *
	 * @since 2.0.0
	 */
	public function test_get_field_lcp_p75_by_segment_makes_no_writes(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		Util::clear_settings_cache();
		$today                        = gmdate( 'Y-m-d' );
		$this->options[ RUM::OPTION ] = array(
			$today => array(
				'/shop' => array(
					'lcpSeg' => array(
						'mobile|single' => array(
							'device'   => 'mobile',
							'template' => 'single',
							'n'        => 20,
							'sum'      => 60000.0,
							'min'      => 3000.0,
							'max'      => 3000.0,
							'samples'  => array_fill( 0, 20, 3000.0 ),
						),
					),
				),
			),
		);
		Functions\when( 'update_option' )->alias(
			static function () {
				throw new \Exception( 'Read path must not write options' );
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function () {
				throw new \Exception( 'Read path must not write transients' );
			}
		);

		$rows = RUM::get_field_lcp_p75_by_segment();
		$this->assertCount( 1, $rows );
		$this->assertSame( 3000.0, $rows[0]['p75'] );
	}

	/**
	 * Test that suspicious lcpUrl values are dropped while numeric data is kept.
	 *
	 * @since 2.0.0
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

	/**
	 * Stub the WP functions used to resolve the PageSpeed fallback candidate.
	 *
	 * @param string $heuristic_url LCP URL the transient lookup should return.
	 */
	private function stub_pagespeed_candidate_environment( string $heuristic_url ): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $url ) {
				return rtrim( (string) $url, '/' );
			}
		);
		Functions\when( 'add_query_arg' )->justReturn( '/hero-page/' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_transient' )->justReturn( $heuristic_url );
		Util::clear_settings_cache();
	}

	/**
	 * Build a RUM aggregate with a single LCP URL entry for a path.
	 *
	 * @param string $path      Page path.
	 * @param string $url       Raw LCP element URL.
	 * @param int    $samples   Observation count.
	 * @param int    $last_seen Last-seen timestamp.
	 * @return array Aggregate option value.
	 */
	private function make_candidate_aggregate( string $path, string $url, int $samples, int $last_seen ): array {
		return array(
			gmdate( 'Y-m-d' ) => array(
				$path => array(
					'lcpUrls' => array(
						'entry' => array(
							'url'      => $url,
							'n'        => $samples,
							'lastSeen' => $last_seen,
						),
					),
				),
			),
		);
	}

	/**
	 * Test that the preload candidate returns the field URL once the sample gate passes.
	 *
	 * @since 2.0.0
	 */
	public function test_get_lcp_preload_candidate_returns_field_url_when_gated(): void {
		$this->install_stubs();
		$this->stub_pagespeed_candidate_environment( 'https://example.com/wp-content/uploads/heuristic.jpg' );
		$this->options['wppo_settings'] = array(
			'performance_audit'  => array( 'rum_enabled' => true ),
			'image_optimisation' => array( 'fieldLcpMinSamples' => 20 ),
		);
		Util::clear_settings_cache();
		$this->options[ RUM::OPTION ] = $this->make_candidate_aggregate( '/hero', 'https://example.com/wp-content/uploads/field.jpg', 20, time() );

		$candidate = RUM::get_lcp_preload_candidate( '/hero' );

		$this->assertIsArray( $candidate );
		$this->assertSame( 'https://example.com/wp-content/uploads/field.jpg', $candidate['url'] );
		$this->assertSame( 20, $candidate['n'] );
	}

	/**
	 * Test that the preload candidate falls back to the PageSpeed URL when samples are insufficient.
	 *
	 * @since 2.0.0
	 */
	public function test_get_lcp_preload_candidate_falls_back_to_pagespeed_when_under_threshold(): void {
		$this->install_stubs();
		$this->stub_pagespeed_candidate_environment( 'https://example.com/wp-content/uploads/heuristic.jpg' );
		$this->options['wppo_settings'] = array(
			'performance_audit'  => array( 'rum_enabled' => true ),
			'image_optimisation' => array( 'fieldLcpMinSamples' => 20 ),
		);
		Util::clear_settings_cache();
		$this->options[ RUM::OPTION ] = $this->make_candidate_aggregate( '/hero', 'https://example.com/wp-content/uploads/field.jpg', 5, time() );

		$candidate = RUM::get_lcp_preload_candidate( '/hero' );

		$this->assertIsArray( $candidate );
		$this->assertSame( 'https://example.com/wp-content/uploads/heuristic.jpg', $candidate['url'] );
		$this->assertSame( 0, $candidate['n'] );
	}

	/**
	 * Test that the preload candidate returns null when neither field nor PageSpeed data exists.
	 *
	 * Callers fall through to the manual preload-image meta / hero path.
	 *
	 * @since 2.0.0
	 */
	public function test_get_lcp_preload_candidate_returns_null_when_no_data(): void {
		$this->install_stubs();
		$this->stub_pagespeed_candidate_environment( '' );
		$this->options['wppo_settings'] = array(
			'performance_audit'  => array( 'rum_enabled' => true ),
			'image_optimisation' => array( 'fieldLcpMinSamples' => 20 ),
		);
		Util::clear_settings_cache();
		$this->options[ RUM::OPTION ] = array();

		$this->assertNull( RUM::get_lcp_preload_candidate( '/hero' ) );
	}

	/**
	 * Test that an explicit path threads through to the PageSpeed transient
	 * tier instead of mixing the field path with current-request context.
	 *
	 * The singular-post-meta tier must not win for an explicit path, and the
	 * transient lookup must use the home_url + path hash for that path.
	 *
	 * @since 2.0.0
	 */
	public function test_get_stored_pagespeed_lcp_url_threads_explicit_path(): void {
		$this->install_stubs();
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 42 );
		Functions\when( 'get_post_meta' )->justReturn( 'https://example.com/wp-content/uploads/post-meta.jpg' );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'untrailingslashit' )->returnArg();
		Functions\when( 'add_query_arg' )->justReturn( '/about/' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		$seen_keys = array();
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$seen_keys ) {
				$seen_keys[] = $key;
				return 'https://example.com/wp-content/uploads/path-hero.jpg';
			}
		);
		Util::clear_settings_cache();

		$this->assertSame(
			'https://example.com/wp-content/uploads/path-hero.jpg',
			RUM::get_stored_pagespeed_lcp_url( '/about' )
		);
		// The transient tier resolved by the explicit path, not the
		// current-request post meta (which would have returned post-meta.jpg).
		$expected_key = 'wppo_lcp_url_mobile_' . md5( 'https://example.com/about' );
		$this->assertContains( $expected_key, $seen_keys );
	}

	/**
	 * Test that a null path still consults the current-request post-meta tier.
	 *
	 * @since 2.0.0
	 */
	public function test_get_stored_pagespeed_lcp_url_uses_post_meta_for_current_request(): void {
		$this->install_stubs();
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 42 );
		Functions\when( 'get_post_meta' )->justReturn( 'https://example.com/wp-content/uploads/post-meta.jpg' );
		Functions\when( 'is_front_page' )->justReturn( false );

		$this->assertSame(
			'https://example.com/wp-content/uploads/post-meta.jpg',
			RUM::get_stored_pagespeed_lcp_url()
		);
	}

	/**
	 * Test that a beacon connection is aggregated into a 3-part segment key.
	 *
	 * @since NEXT
	 */
	public function test_collect_aggregates_connection_segment(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		Util::clear_settings_cache();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

		$result = RUM::collect(
			array(
				'token'      => $this->valid_token( '/seg' ),
				'path'       => '/seg/',
				'lcp'        => 1800,
				'device'     => 'mobile',
				'template'   => 'single',
				'connection' => '4G',
			)
		);

		$this->assertTrue( $result['ok'] );
		$data  = RUM::get_data();
		$today = gmdate( 'Y-m-d' );
		$this->assertArrayHasKey( 'mobile|single|4g', $data[ $today ]['/seg']['lcpSeg'] );
		$this->assertSame( '4g', $data[ $today ]['/seg']['lcpSeg']['mobile|single|4g']['connection'] );
	}

	/**
	 * Test that an invalid connection buckets as unknown without rejecting the sample.
	 *
	 * @since NEXT
	 */
	public function test_collect_falls_back_to_unknown_connection(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		Util::clear_settings_cache();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

		$result = RUM::collect(
			array(
				'token'      => $this->valid_token( '/seg' ),
				'path'       => '/seg/',
				'lcp'        => 1800,
				'connection' => '5g',
			)
		);

		$this->assertTrue( $result['ok'] );
		$data  = RUM::get_data();
		$today = gmdate( 'Y-m-d' );
		$this->assertArrayHasKey( 'unknown|unknown|unknown', $data[ $today ]['/seg']['lcpSeg'] );
	}

	/**
	 * Test that the segmented p75 reader exposes the connection dimension.
	 *
	 * @since NEXT
	 */
	public function test_get_field_lcp_p75_by_segment_exposes_connection(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit'  => array( 'rum_enabled' => true ),
			'image_optimisation' => array( 'fieldLcpMinSamples' => 20 ),
		);
		Util::clear_settings_cache();
		$today                        = gmdate( 'Y-m-d' );
		$this->options[ RUM::OPTION ] = array(
			$today => array(
				'/shop' => array(
					'lcpSeg' => array(
						'mobile|single|4g' => array(
							'device'     => 'mobile',
							'template'   => 'single',
							'connection' => '4g',
							'n'          => 20,
							'sum'        => 60000.0,
							'min'        => 3000.0,
							'max'        => 3000.0,
							'samples'    => array_fill( 0, 20, 3000.0 ),
						),
					),
				),
			),
		);

		$rows = RUM::get_field_lcp_p75_by_segment();
		$this->assertCount( 1, $rows );
		$this->assertSame( '4g', $rows[0]['connection'] );
		$this->assertSame( 3000.0, $rows[0]['p75'] );
	}

	/**
	 * Test that the sample rate defaults to 100 when unset.
	 *
	 * @since NEXT
	 */
	public function test_get_sample_rate_defaults_to_100(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		Util::clear_settings_cache();
		$this->assertSame( 100, RUM::get_sample_rate() );

		$this->options['wppo_settings']['performance_audit']['rum_sample_rate'] = 10;
		Util::clear_settings_cache();
		$this->assertSame( 10, RUM::get_sample_rate() );
	}

	/**
	 * Test that invalid sample rates clamp to 100 (fail-open to unsampled).
	 *
	 * @since NEXT
	 */
	public function test_get_sample_rate_clamps_invalid_values(): void {
		$this->install_stubs();
		foreach ( array( 0, -5, 101, 1000, 'nope', array( 10 ), null ) as $bad ) {
			$this->options['wppo_settings'] = array(
				'performance_audit' => array(
					'rum_enabled'     => true,
					'rum_sample_rate' => $bad,
				),
			);
			Util::clear_settings_cache();
			$this->assertSame( 100, RUM::get_sample_rate() );
		}
	}

	/**
	 * Test that the sampling decision is deterministic for an explicit roll.
	 *
	 * @since NEXT
	 */
	public function test_should_keep_sample_decides_by_roll(): void {
		$this->install_stubs();
		$this->assertTrue( RUM::should_keep_sample( 100, 100 ) );
		$this->assertTrue( RUM::should_keep_sample( 10, 5 ) );
		$this->assertFalse( RUM::should_keep_sample( 10, 50 ) );
		$this->assertTrue( RUM::should_keep_sample( 0, 100 ) );
		$this->assertTrue( RUM::should_keep_sample( 101, 100 ) );
		// Inclusive boundary, matching the JS gate (roll * 100 <= rate).
		$this->assertTrue( RUM::should_keep_sample( 10, 10 ) );
		$this->assertFalse( RUM::should_keep_sample( 10, 11 ) );
	}

	/**
	 * Test that a sampled-out beacon is dropped lossily but still acknowledged.
	 *
	 * The wp_rand() stub returns 2, so a rate of 1 deterministically drops.
	 *
	 * @since NEXT
	 */
	public function test_collect_drops_sample_when_rate_minimal(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array(
				'rum_enabled'     => true,
				'rum_sample_rate' => 1,
			),
		);
		Util::clear_settings_cache();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

		$result = RUM::collect(
			array(
				'token' => $this->valid_token(),
				'path'  => '/',
				'lcp'   => 1000,
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( array(), RUM::get_data() );
	}

	/**
	 * Test that the high-traffic auto-throttle halves the effective rate.
	 *
	 * @since NEXT
	 */
	public function test_effective_rate_throttles_under_high_traffic(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array(
				'rum_enabled'     => true,
				'rum_sample_rate' => 100,
			),
		);
		Util::clear_settings_cache();

		// Below the threshold the configured rate passes through untouched.
		$this->assertSame( 100, RUM::get_effective_sample_rate() );

		// At/above the threshold the rate halves (floored at 1).
		$this->transients[ Util::transient_key( 'wppo_rum_global' ) ] = array(
			'count' => RUM::RUM_THROTTLE_THRESHOLD_DEFAULT,
			'start' => time(),
		);
		$this->assertSame( 50, RUM::get_effective_sample_rate() );
	}

	/**
	 * Test that a non-numeric throttle-threshold filter falls back to default.
	 *
	 * @since NEXT
	 */
	public function test_effective_rate_ignores_non_numeric_threshold_filter(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array(
				'rum_enabled'     => true,
				'rum_sample_rate' => 100,
			),
		);
		Util::clear_settings_cache();
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wppo_rum_throttle_threshold' === $hook ) {
					return array( 'evil' );
				}
				return $value;
			}
		);
		// Count 1 would throttle under the legacy (int)-cast of an array
		// (threshold 1); the guarded fallback (60) must not throttle.
		$this->transients[ Util::transient_key( 'wppo_rum_global' ) ] = array(
			'count' => 1,
			'start' => time(),
		);
		$this->assertSame( 100, RUM::get_effective_sample_rate() );
	}

	/**
	 * Test that aggregate bounds hold under a 10x traffic replay.
	 *
	 * @since NEXT
	 */
	public function test_aggregate_bounds_hold_at_replay(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		Util::clear_settings_cache();

		$queue = array();
		for ( $i = 0; $i < 600; $i++ ) {
			$queue[] = array(
				'path' => '/replay-' . $i,
				'lcp'  => 1000 + ( $i % 500 ),
				'_ts'  => time(),
			);
		}
		$this->transients[ Util::transient_key( 'wppo_rum_queue' ) ] = $queue;

		RUM::flush_queue();
		$data = RUM::get_data();

		$total_paths = 0;
		foreach ( $data as $day_bucket ) {
			$total_paths += count( is_array( $day_bucket ) ? $day_bucket : array() );
		}
		$this->assertLessThanOrEqual( RUM::MAX_TOTAL_PATHS, $total_paths );
		$this->assertLessThanOrEqual( RUM::MAX_OPTION_BYTES, strlen( (string) wp_json_encode( $data ) ) );
	}

	/**
	 * Test that print_config() emits the sampling gate for the beacon.
	 *
	 * @since NEXT
	 */
	public function test_print_config_emits_sample_rate(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array(
				'rum_enabled'     => true,
				'rum_sample_rate' => 10,
			),
		);
		Util::clear_settings_cache();
		Functions\when( 'rest_url' )->justReturn( 'http://example.com/wp-json/performance-optimisation/v1/rum_collect' );

		$printed = array();
		Functions\when( 'wp_print_inline_script_tag' )->alias(
			static function ( $javascript, $attributes = array() ) use ( &$printed ) {
				$printed[] = array( $javascript, $attributes );
			}
		);

		$_SERVER['REQUEST_URI'] = '/sample/';

		RUM::print_config();

		$this->assertCount( 1, $printed );
		$this->assertStringContainsString( 'sampleRate', $printed[0][0] );
		// Pin the emitted value, not just the key, so a wrong rate fails.
		$this->assertStringContainsString( 'sampleRate":10', $printed[0][0] );
	}

	/**
	 * Test that a held flush lock makes flush_queue() return early.
	 *
	 * The queued samples must be left untouched for the lock holder.
	 *
	 * @since NEXT
	 */
	public function test_flush_returns_early_when_lock_held(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		Util::clear_settings_cache();
		// Exercise the transient lock path (no external object cache).
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );

		$this->transients[ Util::transient_key( 'wppo_rum_flush_lock' ) ] = 1;
		$this->transients[ Util::transient_key( 'wppo_rum_queue' ) ]      = array(
			array(
				'path' => '/locked',
				'lcp'  => 1000,
				'_ts'  => time(),
			),
		);

		RUM::flush_queue();

		// Queue untouched, nothing aggregated, lock still held.
		$this->assertCount( 1, $this->transients[ Util::transient_key( 'wppo_rum_queue' ) ] );
		$this->assertSame( array(), RUM::get_aggregate_readonly() );
		$this->assertSame( 1, $this->transients[ Util::transient_key( 'wppo_rum_flush_lock' ) ] );
	}

	/**
	 * Test that flush_queue() always clears the lock.
	 *
	 * @since NEXT
	 */
	public function test_flush_clears_lock(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		Util::clear_settings_cache();

		$this->transients[ Util::transient_key( 'wppo_rum_queue' ) ] = array(
			array(
				'path' => '/unlocked',
				'lcp'  => 1000,
				'_ts'  => time(),
			),
		);

		RUM::flush_queue();

		$this->assertArrayNotHasKey( Util::transient_key( 'wppo_rum_flush_lock' ), $this->transients );
		$this->assertArrayNotHasKey( Util::transient_key( 'wppo_rum_queue' ), $this->transients );
	}

	/**
	 * Test that the low-traffic cron is scheduled only on the 0-to-1 transition.
	 *
	 * The wp_rand() stub returns 2 so the random-flush branch never fires;
	 * the first beacon schedules the flush event and the second must not
	 * issue another wp_next_scheduled() query.
	 *
	 * @since NEXT
	 */
	public function test_cron_scheduled_only_on_empty_to_nonempty_transition(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		Util::clear_settings_cache();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

		$scheduled_count      = 0;
		$next_scheduled_count = 0;
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function () use ( &$scheduled_count ) {
				++$scheduled_count;
				return true;
			}
		);
		Functions\when( 'wp_next_scheduled' )->alias(
			static function () use ( &$next_scheduled_count ) {
				++$next_scheduled_count;
				return false;
			}
		);

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
				'lcp'   => 2000,
			)
		);

		$this->assertSame( 1, $scheduled_count );
		$this->assertSame( 1, $next_scheduled_count );
	}

	/**
	 * Test that the transient fallback queue stays capped at QUEUE_MAX (100).
	 *
	 * @since NEXT
	 */
	public function test_queue_capped_at_max_without_object_cache(): void {
		$this->install_stubs();
		// Exercise the transient fallback path (no external object cache).
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );

		$method = new \ReflectionMethod( RUM::class, 'append_to_queue_atomic' );
		$method->setAccessible( true );

		$result = array( true, 0 );
		for ( $i = 0; $i < 105; $i++ ) {
			$result = $method->invoke(
				null,
				array(
					'path' => '/capped',
					'lcp'  => 1000 + $i,
					'_ts'  => time(),
				)
			);
		}

		// First append reports the 0-to-1 transition; the queue stays bounded.
		$this->assertSame( 100, $result[1] );
		$queue = $this->transients[ Util::transient_key( 'wppo_rum_queue' ) ];
		$this->assertCount( 100, $queue );
	}

	/**
	 * Test that the CAS path preserves every sample with zero loss.
	 *
	 * Simulates 20 sequential appends through wp_cache_add/wp_cache_get
	 * semantics (atomic add) and asserts all samples survive.
	 *
	 * @since NEXT
	 */
	public function test_atomic_append_preserves_all_samples_with_object_cache(): void {
		$this->install_stubs();
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );

		// Array-backed object cache so the real wp_cache_* functions (loaded
		// from templates/object-cache.php at bootstrap, unstubbable via
		// Brain Monkey) exercise the atomic-add CAS path with true add
		// (SET NX) semantics.
		$original_cache = $GLOBALS['wp_object_cache'] ?? null;
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_object_cache'] = new class() {
			/**
			 * In-memory cache store keyed by group + key.
			 *
			 * @var array
			 */
			public $store = array();

			/**
			 * Get a cached value with found flag.
			 *
			 * @param int|string $key   Cache key.
			 * @param string     $group Cache group.
			 * @param bool       $force Whether to force.
			 * @param bool|null  $found Whether the value was found.
			 * @return mixed
			 */
			public function get( $key, $group = 'default', $force = false, &$found = null ) {
				$slot  = $group . '|' . $key;
				$found = array_key_exists( $slot, $this->store );
				return $found ? $this->store[ $slot ] : false;
			}

			/**
			 * Add a value only when the slot is empty (atomic SET NX).
			 *
			 * @param int|string $key    Cache key.
			 * @param mixed      $data   Cache data.
			 * @param string     $group  Cache group.
			 * @param int        $expire Expiration in seconds.
			 * @return bool
			 */
			public function add( $key, $data, $group = 'default', $expire = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$slot = $group . '|' . $key;
				if ( array_key_exists( $slot, $this->store ) ) {
					return false;
				}
				$this->store[ $slot ] = $data;
				return true;
			}

			/**
			 * Overwrite a cached value.
			 *
			 * @param int|string $key    Cache key.
			 * @param mixed      $data   Cache data.
			 * @param string     $group  Cache group.
			 * @param int        $expire Expiration in seconds.
			 * @return bool
			 */
			public function set( $key, $data, $group = 'default', $expire = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$this->store[ $group . '|' . $key ] = $data;
				return true;
			}

			/**
			 * Delete a cached value.
			 *
			 * @param int|string $key   Cache key.
			 * @param string     $group Cache group.
			 * @return bool
			 */
			public function delete( $key, $group = 'default' ) {
				unset( $this->store[ $group . '|' . $key ] );
				return true;
			}
		};
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

		try {
			$method = new \ReflectionMethod( RUM::class, 'append_to_queue_atomic' );
			$method->setAccessible( true );

			$first = $method->invoke(
				null,
				array(
					'path' => '/cas',
					'lcp'  => 1000,
					'_ts'  => time(),
				)
			);
			$this->assertTrue( $first[0] );
			$this->assertSame( 1, $first[1] );

			for ( $i = 1; $i < 20; $i++ ) {
				$result = $method->invoke(
					null,
					array(
						'path' => '/cas',
						'lcp'  => 1000 + $i,
						'_ts'  => time(),
					)
				);
				$this->assertFalse( $result[0] );
				$this->assertSame( $i + 1, $result[1] );
			}

			$queue = $GLOBALS['wp_object_cache']->store[ 'wppo|' . Util::transient_key( 'wppo_rum_queue' ) ];
			$this->assertCount( 20, $queue );
		} finally {
			// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
			if ( null === $original_cache ) {
				unset( $GLOBALS['wp_object_cache'] );
			} else {
				$GLOBALS['wp_object_cache'] = $original_cache;
			}
			// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	/**
	 * Stub the WP functions needed for beacon intake URL gates.
	 */
	private function stub_attribution_environment(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	}

	/**
	 * Test that a valid selector + slow-resource audit are stored (issue #1311).
	 *
	 * The shaped `type` key and the raw ResourceTiming `initiatorType` key
	 * must both pass intake (client/server parity).
	 *
	 * @since NEXT
	 */
	public function test_collect_stores_lcp_selector_and_slow_resources(): void {
		$this->install_stubs();
		$this->stub_attribution_environment();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		Util::clear_settings_cache();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

		$result = RUM::collect(
			array(
				'token'         => $this->valid_token( '/attr' ),
				'path'          => '/attr/',
				'lcp'           => 1800,
				'lcpSelector'   => 'img#hero-image',
				'slowResources' => array(
					array(
						'name'          => 'https://example.com/app.js',
						'initiatorType' => 'script',
						'duration'      => 900,
					),
					array(
						'url'      => 'https://example.com/style.css',
						'type'     => 'css',
						'duration' => 700,
					),
				),
			)
		);

		$this->assertTrue( $result['ok'] );
		$data  = RUM::get_data();
		$today = gmdate( 'Y-m-d' );
		$this->assertArrayHasKey( 'lcpSelectors', $data[ $today ]['/attr'] );
		$this->assertSame( 1, $data[ $today ]['/attr']['lcpSelectors']['img#hero-image']['n'] );
		$this->assertArrayHasKey( 'slowResources', $data[ $today ]['/attr'] );
		$this->assertCount( 2, $data[ $today ]['/attr']['slowResources'] );
	}

	/**
	 * Test that malicious selectors are dropped while numerics are kept.
	 *
	 * Markup, child combinators (`>`, rejected server-side), double quotes
	 * and `javascript:` must never reach the aggregate.
	 *
	 * @since NEXT
	 */
	public function test_collect_drops_malicious_lcp_selector(): void {
		$this->install_stubs();
		$this->stub_attribution_environment();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		Util::clear_settings_cache();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

		foreach ( array( '<script>alert(1)</script>', 'div > img', 'img[src="hero.jpg"]', 'javascript:alert(1)' ) as $selector ) {
			$result = RUM::collect(
				array(
					'token'       => $this->valid_token( '/attr' ),
					'path'        => '/attr/',
					'lcp'         => 1800,
					'lcpSelector' => $selector,
				)
			);
			$this->assertTrue( $result['ok'] );
		}

		$data  = RUM::get_data();
		$today = gmdate( 'Y-m-d' );
		$this->assertSame( 4, $data[ $today ]['/attr']['lcp']['n'] );
		$this->assertArrayNotHasKey( 'lcpSelectors', $data[ $today ]['/attr'] );
	}

	/**
	 * Test the slow-resource intake gates (issue #1311).
	 *
	 * Cross-origin URLs, disallowed types and non-numeric durations are
	 * dropped; extreme durations are clamped to 0–60000ms, never rejected.
	 *
	 * @since NEXT
	 */
	public function test_collect_sanitizes_slow_resources(): void {
		$this->install_stubs();
		$this->stub_attribution_environment();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		Util::clear_settings_cache();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

		$result = RUM::collect(
			array(
				'token'         => $this->valid_token( '/attr' ),
				'path'          => '/attr/',
				'lcp'           => 1800,
				'slowResources' => array(
					array(
						'name'          => 'https://example.com/app.js',
						'initiatorType' => 'script',
						'duration'      => 999999,
					),
					array(
						'name'          => 'https://cdn.evil.com/x.js',
						'initiatorType' => 'script',
						'duration'      => 900,
					),
					array(
						'name'          => 'https://example.com/clip.mp4',
						'initiatorType' => 'video',
						'duration'      => 900,
					),
					array(
						'name'          => 'https://example.com/bad.js',
						'initiatorType' => 'script',
						'duration'      => 'fast',
					),
				),
			)
		);

		$this->assertTrue( $result['ok'] );
		$data  = RUM::get_data();
		$today = gmdate( 'Y-m-d' );
		$slow  = $data[ $today ]['/attr']['slowResources'];
		$this->assertCount( 1, $slow );
		$row = reset( $slow );
		$this->assertSame( 'https://example.com/app.js', $row['url'] );
		$this->assertSame( 'script', $row['type'] );
		$this->assertSame( 60000.0, $row['totalDuration'] );
		$this->assertSame( 60000.0, $row['maxDuration'] );
	}

	/**
	 * Test per-selector aggregation in get_top_lcp_selector (issue #1311).
	 *
	 * Counts must aggregate per selector first: unrelated selectors must
	 * neither inflate the sample gate nor share freshness.
	 *
	 * @since NEXT
	 */
	public function test_get_top_lcp_selector_aggregates_per_selector(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		Util::clear_settings_cache();
		$today                        = gmdate( 'Y-m-d' );
		$yesterday                    = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		$this->options[ RUM::OPTION ] = array(
			$today     => array(
				'/mix' => array(
					'lcpSelectors' => array(
						'img#hero' => array(
							'n'        => 12,
							'lastSeen' => time(),
						),
						'div.lead' => array(
							'n'        => 10,
							'lastSeen' => time(),
						),
					),
				),
			),
			$yesterday => array(
				'/mix' => array(
					'lcpSelectors' => array(
						'img#hero' => array(
							'n'        => 13,
							'lastSeen' => time(),
						),
					),
				),
			),
		);

		// Per-selector counts: img#hero = 25, div.lead = 10. The winner
		// carries its own count, not the 35-way cross-selector sum.
		$top = RUM::get_top_lcp_selector( '/mix', 20 );
		$this->assertIsArray( $top );
		$this->assertSame( 'img#hero', $top['selector'] );
		$this->assertSame( 25, $top['n'] );

		// Neither selector alone reaches 30: no cross-selector inflation.
		RUM::clear_field_lcp_cache();
		$this->assertNull( RUM::get_top_lcp_selector( '/mix', 30 ) );
	}

	/**
	 * Test that a stale selector winner is not kept alive by others.
	 *
	 * Freshness is per-selector: a fresh unrelated selector must not rescue
	 * a stale winner through a shared max() lastSeen.
	 *
	 * @since NEXT
	 */
	public function test_get_top_lcp_selector_freshness_is_per_selector(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		Util::clear_settings_cache();
		$today                        = gmdate( 'Y-m-d' );
		$this->options[ RUM::OPTION ] = array(
			$today => array(
				'/mix' => array(
					'lcpSelectors' => array(
						'img#hero' => array(
							'n'        => 100,
							'lastSeen' => time() - ( 2 * DAY_IN_SECONDS ),
						),
						'div.lead' => array(
							'n'        => 5,
							'lastSeen' => time(),
						),
					),
				),
			),
		);

		$this->assertNull( RUM::get_top_lcp_selector( '/mix', 5 ) );
	}

	/**
	 * Test slow-resource ranking, limit and same-origin filtering.
	 *
	 * Rows rank by observed count then average duration; cross-origin rows
	 * are excluded even when seeded directly into the aggregate.
	 *
	 * @since NEXT
	 */
	public function test_get_top_slow_resources_ranks_and_filters(): void {
		$this->install_stubs();
		$this->stub_attribution_environment();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		Util::clear_settings_cache();
		$today                        = gmdate( 'Y-m-d' );
		$this->options[ RUM::OPTION ] = array(
			$today => array(
				'/a' => array(
					'slowResources' => array(
						'example.com/slow.js'   => array(
							'url'           => 'https://example.com/slow.js',
							'type'          => 'script',
							'n'             => 5,
							'totalDuration' => 5000.0,
							'maxDuration'   => 1200.0,
							'lastSeen'      => time(),
						),
						'example.com/other.css' => array(
							'url'           => 'https://example.com/other.css',
							'type'          => 'css',
							'n'             => 5,
							'totalDuration' => 2500.0,
							'maxDuration'   => 600.0,
							'lastSeen'      => time(),
						),
						'evil.com/x.js'         => array(
							'url'           => 'https://evil.com/x.js',
							'type'          => 'script',
							'n'             => 99,
							'totalDuration' => 99000.0,
							'maxDuration'   => 1000.0,
							'lastSeen'      => time(),
						),
					),
				),
			),
		);

		$rows = RUM::get_top_slow_resources( 3 );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'https://example.com/slow.js', $rows[0]['url'] );
		$this->assertSame( 1000.0, $rows[0]['avgDuration'] );
		$this->assertSame( 'https://example.com/other.css', $rows[1]['url'] );

		$limited = RUM::get_top_slow_resources( 1 );
		$this->assertCount( 1, $limited );
		$this->assertSame( 'https://example.com/slow.js', $limited[0]['url'] );
	}
}
