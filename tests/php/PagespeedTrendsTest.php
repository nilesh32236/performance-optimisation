<?php
/**
 * Tests for Pagespeed trend history recording and retrieval.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Pagespeed;
use Brain\Monkey\Functions;

/**
 * Tests the Web Vitals trend storage on the Pagespeed class.
 *
 * @package PerformanceOptimise\Tests
 */
class PagespeedTrendsTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory option store shared by get_option/update_option stubs.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Install get_option/update_option stubs backed by $this->options.
	 */
	private function install_option_stubs(): void {
		Functions\stubs(
			array(
				'get_option',
				'update_option',
				'sanitize_text_field',
				'current_time',
				'esc_url_raw',
				'sanitize_key',
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
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'current_time' )->justReturn( '2026-08-10 12:00:00' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
			}
		);
	}

	/**
	 * Build a prepared PageSpeed result array.
	 *
	 * @param int $performance Performance score.
	 * @return array Prepared result array.
	 */
	private function prepared_result( int $performance ): array {
		return array(
			'scores'     => array( 'performance' => $performance ),
			'vitals'     => array(
				'lcp' => array( 'value' => 2.5 ),
				'cls' => array( 'value' => 0.05 ),
				'tbt' => array( 'value' => 120.0 ),
			),
			'fetched_at' => '2026-08-10 12:00:00',
		);
	}

	/**
	 * Test that record_trend stores a snapshot keyed by URL + strategy.
	 */
	public function test_record_trend_stores_snapshot(): void {
		$this->install_option_stubs();

		Pagespeed::record_trend( 'http://example.com/', $this->prepared_result( 82 ), 'mobile' );

		$this->assertArrayHasKey( Pagespeed::TREND_OPTION, $this->options );
		$trends = $this->options[ Pagespeed::TREND_OPTION ];
		$this->assertCount( 1, $trends );

		$key      = array_key_first( $trends );
		$snapshot = $trends[ $key ][0];
		$this->assertSame( 82, $snapshot['performance'] );
		$this->assertSame( 2.5, $snapshot['lcp'] );
		$this->assertArrayHasKey( 'fetched_at', $snapshot );
	}

	/**
	 * Test that record_trend appends per URL+strategy and caps at TREND_LIMIT.
	 */
	public function test_record_trend_caps_history(): void {
		$this->install_option_stubs();

		$preset = array();
		$key    = md5( 'http://example.com/' ) . '_mobile';
		for ( $i = 0; $i < Pagespeed::TREND_LIMIT; $i++ ) {
			$preset[ $key ][] = array(
				'fetched_at'  => '2026-08-' . str_pad( (string) ( $i + 1 ), 2, '0', STR_PAD_LEFT ) . ' 00:00:00',
				'performance' => 60,
				'lcp'         => 2.5,
				'cls'         => 0.05,
				'tbt'         => 120.0,
			);
		}
		$this->options[ Pagespeed::TREND_OPTION ] = $preset;

		Pagespeed::record_trend( 'http://example.com/', $this->prepared_result( 95 ), 'mobile' );

		$trends = $this->options[ Pagespeed::TREND_OPTION ];
		$this->assertSame( Pagespeed::TREND_LIMIT, count( $trends[ $key ] ) );
		$this->assertSame( 95, end( $trends[ $key ] )['performance'] );
	}

	/**
	 * Test that record_trend keeps strategies separate.
	 */
	public function test_record_trend_separates_strategies(): void {
		$this->install_option_stubs();

		Pagespeed::record_trend( 'http://example.com/', $this->prepared_result( 70 ), 'mobile' );
		Pagespeed::record_trend( 'http://example.com/', $this->prepared_result( 88 ), 'desktop' );

		$trends = $this->options[ Pagespeed::TREND_OPTION ];
		$this->assertCount( 2, $trends );
	}

	/**
	 * Test that get_trends returns an empty array when nothing is stored.
	 */
	public function test_get_trends_returns_empty_when_empty(): void {
		$this->install_option_stubs();

		$this->assertSame( array(), Pagespeed::get_trends() );
	}
}
