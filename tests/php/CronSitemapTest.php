<?php
/**
 * Tests for Cron sitemap-aware preloading.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cron;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests the sitemap discovery and URL preload methods on Cron.
 *
 * @package PerformanceOptimise\Tests
 */
class CronSitemapTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Build a Cron instance without invoking the constructor.
	 *
	 * @return Cron
	 */
	private function make_cron(): Cron {
		$reflection = new \ReflectionClass( Cron::class );
		return $reflection->newInstanceWithoutConstructor();
	}

	/**
	 * Invoke a private method on a Cron instance.
	 *
	 * @param Cron   $cron  Cron instance.
	 * @param string $name  Method name.
	 * @param mixed  ...$args Arguments.
	 * @return mixed Result of the method call.
	 */
	private function invoke_private( Cron $cron, string $name, ...$args ) {
		$reflection = new ReflectionMethod( Cron::class, $name );
		$reflection->setAccessible( true );
		return $reflection->invoke( $cron, ...$args );
	}

	/**
	 * Test that get_sitemap_urls collects URLs from a sitemap.
	 */
	public function test_get_sitemap_urls_collects_locations(): void {
		$cron = $this->make_cron();

		Functions\stubs(
			array(
				'wp_parse_url',
				'home_url',
				'wp_remote_get',
				'wp_remote_retrieve_body',
				'wp_remote_retrieve_response_code',
				'esc_url_raw',
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => 'ok' ) );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			'<?xml version="1.0"?><urlset><url><loc>http://example.com/about/</loc></url><url><loc>http://example.com/contact/</loc></url></urlset>'
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'esc_url_raw' )->returnArg();

		$urls = $this->invoke_private( $cron, 'get_sitemap_urls', 500 );

		$this->assertContains( 'http://example.com/about/', $urls );
		$this->assertContains( 'http://example.com/contact/', $urls );
	}

	/**
	 * Test that get_sitemap_urls filters out off-site URLs.
	 */
	public function test_get_sitemap_urls_filters_offsite(): void {
		$cron = $this->make_cron();

		Functions\stubs(
			array(
				'wp_parse_url',
				'home_url',
				'wp_remote_get',
				'wp_remote_retrieve_body',
				'wp_remote_retrieve_response_code',
				'esc_url_raw',
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => 'ok' ) );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			'<urlset><url><loc>http://example.com/about/</loc></url><url><loc>https://external.example.net/evil/</loc></url></urlset>'
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'esc_url_raw' )->returnArg();

		$urls = $this->invoke_private( $cron, 'get_sitemap_urls', 500 );

		$this->assertContains( 'http://example.com/about/', $urls );
		$this->assertNotContains( 'https://external.example.net/evil/', $urls );
	}

	/**
	 * Test that get_sitemap_urls returns empty when the sitemap fetch fails.
	 */
	public function test_get_sitemap_urls_returns_empty_on_failure(): void {
		$cron = $this->make_cron();

		Functions\stubs(
			array(
				'wp_parse_url',
				'home_url',
				'wp_remote_get',
				'wp_remote_retrieve_body',
				'wp_remote_retrieve_response_code',
				'esc_url_raw',
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'wp_remote_get' )->justReturn( new WPPO_WP_Error() );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
		Functions\when( 'esc_url_raw' )->returnArg();

		$urls = $this->invoke_private( $cron, 'get_sitemap_urls', 500 );

		$this->assertSame( array(), $urls );
	}

	/**
	 * Test that schedule_sitemap_url_jobs skips excluded URLs.
	 */
	public function test_schedule_sitemap_url_jobs_skips_excluded(): void {
		$cron = $this->make_cron();

		Functions\stubs(
			array(
				'wp_parse_url',
				'home_url',
				'wp_remote_get',
				'wp_remote_retrieve_body',
				'wp_remote_retrieve_response_code',
				'esc_url_raw',
				'wp_next_scheduled',
				'wp_schedule_single_event',
				'wp_rand',
				'get_current_blog_id',
				'untrailingslashit',
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => 'ok' ) );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			'<urlset><url><loc>http://example.com/about/</loc></url><url><loc>http://example.com/cart/</loc></url></urlset>'
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_rand' )->justReturn( 100 );

		$scheduled = array();
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function ( $timestamp, $hook, $args = array() ) use ( &$scheduled ) {
				$scheduled[] = $args[0] ?? '';
				return true;
			}
		);

		// Exclude /cart/ so only /about/ should be scheduled.
		$this->invoke_private(
			$cron,
			'schedule_sitemap_url_jobs',
			array( 'http://example.com/cart' )
		);

		$this->assertContains( 'http://example.com/about/', $scheduled );
		$this->assertNotContains( 'http://example.com/cart/', $scheduled );
	}

	/**
	 * Test that process_url performs a remote GET for a valid URL.
	 */
	public function test_process_url_requests_valid_url(): void {
		$cron = $this->make_cron();

		Functions\stubs(
			array(
				'esc_url_raw',
				'wp_remote_retrieve_response_code',
			)
		);
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );

		Functions\expect( 'wp_remote_get' )->once()->andReturn(
			array( 'response' => array( 'code' => 200 ) )
		);

		$cron->process_url( 'http://example.com/about/' );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test that process_url ignores empty and non-string input.
	 */
	public function test_process_url_ignores_empty_input(): void {
		$cron = $this->make_cron();

		Functions\stubs(
			array(
				'esc_url_raw',
			)
		);
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\expect( 'wp_remote_get' )->never();

		$cron->process_url( '' );
		$cron->process_url( '   ' );

		$this->addToAssertionCount( 1 );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile

/**
 * Minimal WP_Error stand-in for Cron sitemap tests.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_WP_Error {
	/**
	 * Error code.
	 *
	 * @var string
	 */
	public $code = 'http_request_failed';

	/**
	 * Error message.
	 *
	 * @var string
	 */
	public $message = 'Failed';

	/**
	 * Return the error message.
	 *
	 * @return string
	 */
	public function get_error_message(): string {
		return $this->message;
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
