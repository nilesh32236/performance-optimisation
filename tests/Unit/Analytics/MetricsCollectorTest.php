<?php
/**
 * Unit tests for MetricsCollector class.
 *
 * @package PerformanceOptimisation\Tests\Unit\Analytics
 */

namespace PerformanceOptimisation\Tests\Unit\Analytics;

use PHPUnit\Framework\TestCase;
use PerformanceOptimisation\Core\Analytics\MetricsCollector;

/**
 * Test class for MetricsCollector.
 */
class MetricsCollectorTest extends TestCase {

	/**
	 * MetricsCollector instance.
	 *
	 * @var MetricsCollector
	 */
	private MetricsCollector $metrics_collector;

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Mock WordPress functions
		if ( ! function_exists( 'current_time' ) ) {
			function current_time( $type ) {
				return date( 'Y-m-d H:i:s' );
			}
		}

		if ( ! function_exists( 'get_current_user_id' ) ) {
			function get_current_user_id() {
				return 1;
			}
		}

		$this->metrics_collector = new MetricsCollector();
	}

	/**
	 * Test metric recording.
	 *
	 * @return void
	 */
	public function test_record_metric(): void {
		// Mock global $wpdb
		global $wpdb;
		$wpdb = $this->createMock( \wpdb::class );
		$wpdb->expects( $this->once() )
			->method( 'insert' )
			->willReturn( 1 );

		$result = $this->metrics_collector->record_metric( 'test_metric', 100, array( 'tag' => 'value' ) );

		$this->assertTrue( $result );
	}

	/**
	 * Test counter increment.
	 *
	 * @return void
	 */
	public function test_increment_counter(): void {
		// Mock global $wpdb
		global $wpdb;
		$wpdb = $this->createMock( \wpdb::class );
		$wpdb->expects( $this->once() )
			->method( 'insert' )
			->willReturn( 1 );

		$result = $this->metrics_collector->increment_counter( 'test_counter' );

		$this->assertTrue( $result );
	}

	/**
	 * Test timing metric recording.
	 *
	 * @return void
	 */
	public function test_record_timing(): void {
		// Mock global $wpdb
		global $wpdb;
		$wpdb = $this->createMock( \wpdb::class );
		$wpdb->expects( $this->once() )
			->method( 'insert' )
			->willReturn( 1 );

		$result = $this->metrics_collector->record_timing( 'page_load_time', 1500.5 );

		$this->assertTrue( $result );
	}

	/**
	 * Test metrics retrieval.
	 *
	 * @return void
	 */
	public function test_get_metrics(): void {
		// Mock global $wpdb
		global $wpdb;
		$wpdb = $this->createMock( \wpdb::class );
		$wpdb->expects( $this->once() )
			->method( 'prepare' )
			->willReturn( 'SELECT * FROM metrics WHERE ...' );
		$wpdb->expects( $this->once() )
			->method( 'get_results' )
			->willReturn(
				array(
					array(
						'metric_name'  => 'test_metric',
						'metric_value' => '100',
						'recorded_at'  => '2024-01-01 12:00:00',
					),
				)
			);

		$result = $this->metrics_collector->get_metrics(
			'test_metric',
			'2024-01-01 00:00:00',
			'2024-01-01 23:59:59'
		);

		$this->assertIsArray( $result );
		$this->assertEquals( 'test_metric', $result['metric_name'] );
		$this->assertEquals( 1, $result['count'] );
	}

	/**
	 * Test aggregated metrics retrieval.
	 *
	 * @return void
	 */
	public function test_get_aggregated_metrics(): void {
		// Mock global $wpdb
		global $wpdb;
		$wpdb = $this->createMock( \wpdb::class );
		$wpdb->expects( $this->once() )
			->method( 'prepare' )
			->willReturn( 'SELECT * FROM aggregated_metrics WHERE ...' );
		$wpdb->expects( $this->once() )
			->method( 'get_results' )
			->willReturn(
				array(
					array(
						'metric_name'      => 'test_metric',
						'aggregation_type' => 'AVG',
						'aggregated_value' => '150.5',
						'sample_count'     => '10',
						'period_start'     => '2024-01-01 00:00:00',
					),
				)
			);

		$result = $this->metrics_collector->get_aggregated_metrics(
			'test_metric',
			'day',
			'2024-01-01 00:00:00',
			'2024-01-01 23:59:59'
		);

		$this->assertIsArray( $result );
		$this->assertEquals( 'test_metric', $result['metric_name'] );
		$this->assertEquals( 'day', $result['period'] );
		$this->assertCount( 1, $result['data'] );
	}

	/**
	 * Test database table creation.
	 *
	 * @return void
	 */
	public function test_create_tables(): void {
		// Mock WordPress functions
		if ( ! function_exists( 'dbDelta' ) ) {
			function dbDelta( $queries ) {
				return array( 'created' => 2 );
			}
		}

		// Mock global $wpdb
		global $wpdb;
		$wpdb = $this->createMock( \wpdb::class );
		$wpdb->method( 'get_charset_collate' )->willReturn( 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
		$wpdb->prefix = 'wp_';

		// This should not throw any exceptions
		$this->metrics_collector->create_tables();

		// If we get here without exceptions, the test passes
		$this->assertTrue( true );
	}

	/**
	 * Test client IP detection.
	 *
	 * @return void
	 */
	public function test_get_client_ip(): void {
		// Set up server variables
		$_SERVER['REMOTE_ADDR'] = '192.168.1.1';

		// Use reflection to access private method
		$reflection = new \ReflectionClass( $this->metrics_collector );
		$method     = $reflection->getMethod( 'get_client_ip' );
		$method->setAccessible( true );

		$ip = $method->invoke( $this->metrics_collector );

		$this->assertEquals( '192.168.1.1', $ip );
	}

	/**
	 * Test page type detection.
	 *
	 * @return void
	 */
	public function test_get_page_type(): void {
		// Mock WordPress functions
		if ( ! function_exists( 'is_admin' ) ) {
			function is_admin() {
				return false;
			}
		}
		if ( ! function_exists( 'is_front_page' ) ) {
			function is_front_page() {
				return true;
			}
		}

		// Use reflection to access private method
		$reflection = new \ReflectionClass( $this->metrics_collector );
		$method     = $reflection->getMethod( 'get_page_type' );
		$method->setAccessible( true );

		$page_type = $method->invoke( $this->metrics_collector );

		$this->assertEquals( 'home', $page_type );
	}

	/**
	 * Test cache status detection.
	 *
	 * @return void
	 */
	public function test_get_cache_status(): void {
		// Define cache constants
		define( 'WPPO_CACHE_HIT', true );

		// Use reflection to access private method
		$reflection = new \ReflectionClass( $this->metrics_collector );
		$method     = $reflection->getMethod( 'get_cache_status' );
		$method->setAccessible( true );

		$cache_status = $method->invoke( $this->metrics_collector );

		$this->assertEquals( 'hit', $cache_status );
	}

	/**
	 * Test directory statistics calculation.
	 *
	 * @return void
	 */
	public function test_get_directory_stats(): void {
		// Create a temporary directory for testing
		$temp_dir = sys_get_temp_dir() . '/wppo_test_' . uniqid();
		mkdir( $temp_dir );

		// Create a test file
		file_put_contents( $temp_dir . '/test.txt', 'test content' );

		// Use reflection to access private method
		$reflection = new \ReflectionClass( $this->metrics_collector );
		$method     = $reflection->getMethod( 'get_directory_stats' );
		$method->setAccessible( true );

		$stats = $method->invoke( $this->metrics_collector, $temp_dir );

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'files', $stats );
		$this->assertArrayHasKey( 'size', $stats );
		$this->assertEquals( 1, $stats['files'] );
		$this->assertGreaterThan( 0, $stats['size'] );

		// Clean up
		unlink( $temp_dir . '/test.txt' );
		rmdir( $temp_dir );
	}

	/**
	 * Test failed metric recording.
	 *
	 * @return void
	 */
	public function test_record_metric_failure(): void {
		// Mock global $wpdb to return false (failure)
		global $wpdb;
		$wpdb = $this->createMock( \wpdb::class );
		$wpdb->expects( $this->once() )
			->method( 'insert' )
			->willReturn( false );
		$wpdb->last_error = 'Database error';

		$result = $this->metrics_collector->record_metric( 'test_metric', 100 );

		$this->assertFalse( $result );
	}
}
