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
		// The wp_cache_add()/wp_cache_delete() functions are declared as real
		// PHP functions by the object-cache drop-in loaded in bootstrap.php and
		// route through $GLOBALS['wp_object_cache']. Install a pristine store so
		// the lock is always available unless a test swaps it for a full one.
		$this->install_object_cache();
	}

	/**
	 * Install an in-memory object cache store whose add() can be forced to fail.
	 *
	 * The wp_cache_add()/wp_cache_delete() functions are declared as real
	 * PHP functions by the object-cache drop-in loaded in bootstrap.php, so
	 * Patchwork cannot redefine them. The lock behaviour is therefore
	 * controlled through the underlying store instead.
	 *
	 * @param bool $lock_free Whether add() reports the lock as acquired.
	 */
	private function install_object_cache( bool $lock_free = true ): void {
		global $wp_object_cache;

		$acquired        = $lock_free;
		$wp_object_cache = new class( $acquired ) {
			/**
			 * Lock-add result.
			 *
			 * @var bool
			 */
			private $lock_free;

			/**
			 * Constructor.
			 *
			 * @param bool $lock_free Whether add() succeeds.
			 */
			public function __construct( bool $lock_free ) {
				$this->lock_free = $lock_free;
			}

			/**
			 * Mimic WP_Object_Cache::add() with configurable lock result.
			 *
			 * @param int|string $key   Cache key.
			 * @param mixed      $data  Cache data.
			 * @param string     $group Cache group.
			 * @param int        $expire Expiration in seconds.
			 * @return bool
			 */
			public function add( $key, $data, $group = '', $expire = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				return $this->lock_free;
			}

			/**
			 * Mimic WP_Object_Cache::delete() as a no-op.
			 *
			 * @param int|string $key   Cache key.
			 * @param string     $group Cache group.
			 * @return true
			 */
			public function delete( $key, $group = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				return true;
			}

			/**
			 * Mimic WP_Object_Cache::get() returning a cache miss.
			 *
			 * @param int|string $key   Cache key.
			 * @param string     $group Cache group.
			 * @param bool       $force Whether to force.
			 * @param bool|null  $found Whether the value was found.
			 * @return false
			 */
			public function get( $key, $group = '', $force = false, &$found = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
				$found = false;
				return false;
			}

			/**
			 * Mimic WP_Object_Cache::set() accepting any write.
			 *
			 * @param int|string $key    Cache key.
			 * @param mixed      $data   Cache data.
			 * @param string     $group  Cache group.
			 * @param int        $expire Expiration in seconds.
			 * @return true
			 */
			public function set( $key, $data, $group = '', $expire = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				return true;
			}
		};
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

	/**
	 * Test that a held lock causes record_trend to skip the write.
	 *
	 * Simulates a concurrent worker owning the shared cache lock so the
	 * read-modify-write is not duplicated for the same run.
	 */
	public function test_record_trend_skips_when_lock_held(): void {
		$this->install_option_stubs();
		$this->options[ Pagespeed::TREND_OPTION ] = array(
			'existing_mobile' => array(),
		);
		// Simulate a concurrent worker already owning the shared cache lock:
		// wp_cache_add() reports the lock as unavailable, so the write is skipped.
		$this->install_object_cache( false );

		Pagespeed::record_trend( 'http://example.com/', $this->prepared_result( 80 ), 'mobile' );

		// The option must be untouched by the blocked worker.
		$this->assertSame(
			array( 'existing_mobile' => array() ),
			$this->options[ Pagespeed::TREND_OPTION ]
		);
	}

	/**
	 * Test that the full map does not grow past TREND_MAX_KEYS.
	 *
	 * Fills the map to the global cap, records a brand-new URL, and verifies the
	 * oldest key is pruned while the new snapshot is retained.
	 */
	public function test_record_trend_enforces_global_retention(): void {
		$this->install_option_stubs();

		$preset = array();
		for ( $i = 1; $i <= Pagespeed::TREND_MAX_KEYS; $i++ ) {
			$key            = md5( 'http://example.com/page-' . $i . '/' ) . '_mobile';
			$preset[ $key ] = array(
				array(
					'fetched_at'  => sprintf( '2020-01-%02d 00:00:00', $i ),
					'performance' => 40,
					'lcp'         => 3.0,
					'cls'         => 0.1,
					'tbt'         => 200.0,
				),
			);
		}
		$this->options[ Pagespeed::TREND_OPTION ] = $preset;

		$oldest_key = md5( 'http://example.com/page-1/' ) . '_mobile';
		Pagespeed::record_trend( 'http://example.com/new/', $this->prepared_result( 90 ), 'mobile' );

		$trends  = $this->options[ Pagespeed::TREND_OPTION ];
		$new_key = md5( 'http://example.com/new/' ) . '_mobile';

		$this->assertCount( Pagespeed::TREND_MAX_KEYS, $trends );
		$this->assertArrayNotHasKey( $oldest_key, $trends );
		$this->assertArrayHasKey( $new_key, $trends );
	}
}
