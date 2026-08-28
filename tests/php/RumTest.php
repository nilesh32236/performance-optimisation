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
				'token' => $this->valid_token( '/about/' ),
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
		$this->assertArrayHasKey( '/about/', $data[ $today ] );
		$this->assertSame( 1, $data[ $today ]['/about/']['lcp']['n'] );
		$this->assertSame( 2500.5, $data[ $today ]['/about/']['lcp']['sum'] );
		$this->assertSame( 0.08, $data[ $today ]['/about/']['cls']['sum'] );
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

		$today     = 'h_wppo_rum_' . gmdate( 'Ymd' ) . '|/a/';
		$yesterday = 'h_wppo_rum_' . gmdate( 'Ymd', time() - DAY_IN_SECONDS ) . '|/a/';
		$two_days  = 'h_wppo_rum_' . gmdate( 'Ymd', time() - ( 2 * DAY_IN_SECONDS ) ) . '|/a/';

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
}
