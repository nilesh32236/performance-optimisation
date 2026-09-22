<?php
/**
 * Tests for LiteSpeed_Crawler throttler + sitemap + blacklist + variant matrix.
 *
 * @package PerformanceOptimise\Tests
 * @since 2.0.0
 */

// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.VariableComment.Missing,Generic.CodeAnalysis.UnusedFunctionParameter,Generic.Commenting.Todo,Squiz.Commenting.InlineComment

use PerformanceOptimise\Inc\LiteSpeed_Crawler;
use PerformanceOptimise\Inc\Cron;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

if ( ! class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Crawler' ) ) {
	require_once __DIR__ . '/../../includes/class-util.php';
	require_once __DIR__ . '/../../includes/class-cron.php';
	require_once __DIR__ . '/../../includes/class-litespeed-crawler.php';
}

/**
 * Crawler tests.
 */
class LiteSpeedCrawlerTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	protected function tearDown(): void {
		// Reset patchwork redefinitions if any.
		if ( class_exists( '\Patchwork' ) && method_exists( '\Patchwork', 'restoreAll' ) ) {
			try {
				\Patchwork\restoreAll();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		parent::tearDown();
	}

	public function test_get_load_limit_default(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// Ensure shell_exec not causing fallback to nproc if nproc exists; limit should be 4.0 when filtered returnArg.
		$limit = LiteSpeed_Crawler::get_load_limit();
		$this->assertSame( 4.0, $limit );
	}

	public function test_get_load_limit_respects_filter(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'wppo_crawler_load_limit' === $tag ) {
					return 2.5;
				}
				return $value;
			}
		);
		$limit = LiteSpeed_Crawler::get_load_limit();
		$this->assertSame( 2.5, $limit );
	}

	public function test_blacklist_threshold_and_skip(): void {
		$store = array();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) {
				return $fallback;
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$store ) {
				if ( false !== strpos( $key, 'wppo_crawler_server_ip' ) ) {
					return '1.2.3.4';
				}
				return $store[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value, $exp ) use ( &$store ) {
				$store[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( $key ) use ( &$store ) {
				unset( $store[ $key ] );
				return true;
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_remote_get' )->justReturn( array( 'response' => array( 'code' => 500 ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_http_validate_url' )->returnArg();
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $v ) {
				return rtrim( (string) $v, '/' );
			}
		);

		$url = 'https://example.com/a';
		// Record 3 failures => blacklisted.
		LiteSpeed_Crawler::record_failure( $url );
		LiteSpeed_Crawler::record_failure( $url );
		LiteSpeed_Crawler::record_failure( $url );
		$this->assertTrue( LiteSpeed_Crawler::is_blacklisted( $url ) );

		// crawl_batch should skip blacklisted URL.
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		// Force wp_remote fallback to avoid curl.
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'wppo_crawler_disable_curl' === $tag ) {
					return true;
				}
				if ( 'wppo_crawler_load_limit' === $tag ) {
					return 100.0;
				}
				if ( 'wppo_crawler_concurrency' === $tag || 'wppo_crawler_blacklist_threshold' === $tag ) {
					return $value;
				}
				if ( 'wppo_crawler_variants' === $tag ) {
					return $value;
				}
				return $value;
			}
		);
		$result = LiteSpeed_Crawler::crawl_batch( array( $url, 'https://example.com/b' ) );
		// First URL blacklisted => skipped count 1, second URL should be attempted (failed due to 500).
		$this->assertSame( 1, $result['skipped'] );
	}

	public function test_get_variants_to_warm_single_primary(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'litespeed_integration' => array(
					'varyGroups' => array(
						'guest'  => false,
						'mobile' => false,
						'webp'   => false,
					),
				),
				'cache_settings'        => array( 'enableLoggedInCache' => false ),
			)
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'esc_url_raw' )->returnArg();
		$variants = LiteSpeed_Crawler::get_variants_to_warm( 'https://example.com/a' );
		$this->assertCount( 1, $variants );
	}

	public function test_get_variants_to_warm_four_when_mobile_webp(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'litespeed_integration' => array(
					'varyGroups' => array(
						'guest'  => false,
						'mobile' => true,
						'webp'   => true,
					),
				),
				'cache_settings'        => array( 'enableLoggedInCache' => false ),
			)
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'esc_url_raw' )->returnArg();
		$variants = LiteSpeed_Crawler::get_variants_to_warm( 'https://example.com/a' );
		$this->assertCount( 4, $variants );
	}

	public function test_get_variants_to_warm_eight_when_all(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'litespeed_integration' => array(
					'varyGroups' => array(
						'guest'  => true,
						'mobile' => true,
						'webp'   => true,
					),
				),
				'cache_settings'        => array( 'enableLoggedInCache' => false ),
			)
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'esc_url_raw' )->returnArg();
		$variants = LiteSpeed_Crawler::get_variants_to_warm( 'https://example.com/a' );
		// With guest true, mobile true, webp true => 2*2*2=8 (guest dimension covers role)
		$this->assertCount( 8, $variants );
	}

	public function test_crawl_batch_overload_defers(): void {
		$scheduled = array();
		global $wpdb;
		$orig_wwpdb = $wpdb;
		$wpdb       = new class() {
			public $prefix = 'wp_';
			public function insert( $table, $data ) {
				return true;
			}
		};
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function ( $ts, $hook, $args ) use ( &$scheduled ) {
				$scheduled[] = $hook;
				return true;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'wppo_crawler_is_overloaded' === $tag ) {
					return true;
				}
				if ( 'wppo_crawler_load_limit' === $tag ) {
					return 100.0;
				}
				return $value;
			}
		);
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'current_time' )->justReturn( '2025-01-01 00:00:00' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) {
				if ( 'wppo_settings' === $name ) {
					return array();
				}
				return $fallback;
			}
		);
		// Re-apply apply_filters after get_option override (Brain Monkey last alias wins, so need to re-set).
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'wppo_crawler_is_overloaded' === $tag ) {
					return true;
				}
				if ( 'wppo_crawler_load_limit' === $tag ) {
					return 100.0;
				}
				return $value;
			}
		);

		$result = LiteSpeed_Crawler::crawl_batch( array( 'https://example.com/a', 'https://example.com/b' ) );
		$wpdb   = $orig_wwpdb;
		$this->assertSame( 2, $result['skipped'] );
		$this->assertContains( 'wppo_litespeed_crawler_batch', $scheduled );
	}

	public function test_get_urls_to_crawl_caps_at_500(): void {
		// Mock sitemap 600 URLs via filter wppo_crawler_sitemap_urls.
		$mock_urls = array();
		for ( $i = 0; $i < 600; $i++ ) {
			$mock_urls[] = 'https://example.com/page-' . $i . '/';
		}
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) {
				if ( 'wppo_settings' === $name ) {
					return array(
						'preload_settings'      => array( 'preloadSitemap' => true ),
						'litespeed_integration' => array( 'varyGroups' => array() ),
						'cache_settings'        => array(),
					);
				}
				return $fallback;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) use ( $mock_urls ) {
				if ( 'wppo_crawler_sitemap_urls' === $tag ) {
					return $mock_urls;
				}
				return $value;
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_post_types' )->justReturn( array( 'post' => 'post' ) );
		Functions\when( 'get_posts' )->justReturn( array() );
		// Mock $wpdb to avoid DB query.
		global $wpdb;
		$orig_wwpdb = $wpdb;
		$wpdb       = new class() {
			public $posts = 'wp_posts';
			public function get_col( $query ) {
				return array();
			}
			public function prepare( $query, ...$args ) {
				return $query;
			}
		};
		// Need Util::cached_home_url stable.
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $v ) {
				return rtrim( (string) $v, '/' );
			}
		);
		Functions\when( 'wp_normalize_path' )->alias(
			static function ( $p ) {
				return str_replace( '\\', '/', (string) $p );
			}
		);
		// Ensure get_sitemap_urls via Cron won't add more (stub wp_remote_get to 500).
		Functions\when( 'wp_remote_get' )->justReturn( array( 'response' => array( 'code' => 500 ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		$urls = LiteSpeed_Crawler::get_urls_to_crawl( 500 );
		// Should be capped at 500 even though mock returned 600.
		$this->assertCount( 500, $urls );
		$wpdb = $orig_wwpdb;
	}

	public function test_crawl_batch_fallback_when_curl_disabled(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_http_validate_url' )->returnArg();
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $v ) {
				return rtrim( (string) $v, '/' );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		$called = 0;
		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url, $args ) use ( &$called ) {
				++$called;
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'wppo_crawler_disable_curl' === $tag ) {
					return true;
				}
				if ( 'wppo_crawler_load_limit' === $tag ) {
					return 100.0;
				}
				if ( 'wppo_crawler_is_overloaded' === $tag ) {
					return false;
				}
				return $value;
			}
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		$result = LiteSpeed_Crawler::crawl_batch( array( 'https://example.com/a', 'https://example.com/b' ) );
		// With fallback, each URL => primary variant only => 2 wp_remote_get calls.
		$this->assertSame( 2, $called );
		$this->assertSame( 2, $result['success'] );
	}

	public function test_crawl_batch_rejects_off_host_initial_url(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_http_validate_url' )->returnArg();
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $v ) {
				return rtrim( (string) $v, '/' );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		$fetched = array();
		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url, $args ) use ( &$fetched ) {
				$fetched[] = $url;
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'wppo_crawler_disable_curl' === $tag ) {
					return true;
				}
				if ( 'wppo_crawler_load_limit' === $tag ) {
					return 100.0;
				}
				if ( 'wppo_crawler_is_overloaded' === $tag ) {
					return false;
				}
				return $value;
			}
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		// Audit #1490: the off-host URL must never enter the fetch queue.
		$result = LiteSpeed_Crawler::crawl_batch( array( 'https://example.com/a', 'https://evil.example.net/x' ) );
		$this->assertSame( array( 'https://example.com/a' ), $fetched );
		$this->assertSame( 1, $result['success'] );
		$this->assertSame( 1, $result['failed'] );
	}

	public function test_crawl_batch_fallback_drops_off_host_redirect(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_http_validate_url' )->returnArg();
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $v ) {
				return rtrim( (string) $v, '/' );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $resp ) {
				return isset( $resp['response']['code'] ) ? (int) $resp['response']['code'] : 0;
			}
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			static function ( $resp, $name ) {
				if ( 'location' === strtolower( (string) $name ) && isset( $resp['headers']['location'] ) ) {
					return $resp['headers']['location'];
				}
				return '';
			}
		);
		$fetched = array();
		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url, $args ) use ( &$fetched ) {
				$fetched[] = $url;
				// Same-host response bouncing to a link-local target.
				return array(
					'response' => array( 'code' => 302 ),
					'headers'  => array( 'location' => 'http://169.254.169.254/latest/meta-data/' ),
				);
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'wppo_crawler_disable_curl' === $tag ) {
					return true;
				}
				if ( 'wppo_crawler_load_limit' === $tag ) {
					return 100.0;
				}
				if ( 'wppo_crawler_is_overloaded' === $tag ) {
					return false;
				}
				return $value;
			}
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		// Audit #1490: the link-local hop must be dropped without fetching.
		$result = LiteSpeed_Crawler::crawl_batch( array( 'https://example.com/a' ) );
		$this->assertSame( array( 'https://example.com/a' ), $fetched );
		$this->assertSame( 0, $result['success'] );
		$this->assertSame( 1, $result['failed'] );
	}

	public function test_resolve_validated_redirect_allows_same_host_only(): void {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_http_validate_url' )->returnArg();
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $v ) {
				return rtrim( (string) $v, '/' );
			}
		);
		$method = new \ReflectionMethod( LiteSpeed_Crawler::class, 'resolve_validated_redirect' );
		// Same-host absolute + root-relative hops pass.
		$this->assertSame( 'https://example.com/b', $method->invoke( null, 'https://example.com/b', 'https://example.com/a' ) );
		$this->assertSame( 'https://example.com/b', $method->invoke( null, '/b', 'https://example.com/a' ) );
		// DNS is case-insensitive; the FQDN trailing-dot form is the same host.
		$this->assertSame( 'https://EXAMPLE.com/b', $method->invoke( null, 'https://EXAMPLE.com/b', 'https://example.com/a' ) );
		$this->assertSame( 'https://example.com./b', $method->invoke( null, 'https://example.com./b', 'https://example.com/a' ) );
		// Off-host, link-local, and empty hops are dropped.
		$this->assertFalse( $method->invoke( null, 'https://evil.example.net/x', 'https://example.com/a' ) );
		$this->assertFalse( $method->invoke( null, 'http://169.254.169.254/latest/meta-data/', 'https://example.com/a' ) );
		$this->assertFalse( $method->invoke( null, '', 'https://example.com/a' ) );
	}

	/**
	 * Shared stubs for single/fetch-loop crawler tests.
	 *
	 * @param array    $fetched   Out-param collecting wp_remote_get() calls.
	 * @param callable $responder Callback returning a fake response per URL.
	 */
	private function stub_crawler_fetch_functions( array &$fetched, callable $responder ): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_http_validate_url' )->returnArg();
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $v ) {
				return rtrim( (string) $v, '/' );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $resp ) {
				return isset( $resp['response']['code'] ) ? (int) $resp['response']['code'] : 0;
			}
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			static function ( $resp, $name ) {
				if ( 'location' === strtolower( (string) $name ) && isset( $resp['headers']['location'] ) ) {
					return $resp['headers']['location'];
				}
				return '';
			}
		);
		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url, $args = array() ) use ( &$fetched, $responder ) {
				$fetched[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return $responder( $url, $args );
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'wppo_crawler_disable_curl' === $tag ) {
					return true;
				}
				if ( 'wppo_crawler_load_limit' === $tag ) {
					return 100.0;
				}
				if ( 'wppo_crawler_is_overloaded' === $tag ) {
					return false;
				}
				return $value;
			}
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
	}

	public function test_crawl_single_rejects_off_host_url(): void {
		$fetched = array();
		$this->stub_crawler_fetch_functions(
			$fetched,
			static function ( $url, $args ) {
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		// Audit #1490 review: the off-host entry URL must never be fetched.
		LiteSpeed_Crawler::crawl_single( 'https://evil.example.net/x' );
		$this->assertSame( array(), $fetched );
	}

	public function test_crawl_single_follows_same_host_redirect_without_auto_follow(): void {
		$fetched = array();
		$this->stub_crawler_fetch_functions(
			$fetched,
			static function ( $url, $args ) {
				if ( 'https://example.com/a' === $url ) {
					return array(
						'response' => array( 'code' => 302 ),
						'headers'  => array( 'location' => 'https://example.com/b' ),
					);
				}
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		LiteSpeed_Crawler::crawl_single( 'https://example.com/a' );
		$this->assertSame( array( 'https://example.com/a', 'https://example.com/b' ), array_column( $fetched, 'url' ) );
		// Neither hop auto-follows redirects server-side.
		foreach ( $fetched as $call ) {
			$this->assertSame( 0, $call['args']['redirection'] );
		}
	}

	public function test_crawl_single_drops_link_local_redirect(): void {
		$fetched = array();
		$this->stub_crawler_fetch_functions(
			$fetched,
			static function ( $url, $args ) {
				return array(
					'response' => array( 'code' => 302 ),
					'headers'  => array( 'location' => 'http://169.254.169.254/latest/meta-data/' ),
				);
			}
		);
		// The link-local hop must be dropped without ever fetching it.
		LiteSpeed_Crawler::crawl_single( 'https://example.com/a' );
		$this->assertSame( array( 'https://example.com/a' ), array_column( $fetched, 'url' ) );
	}

	public function test_crawl_batch_fallback_location_less_3xx_is_failure(): void {
		$fetched = array();
		$this->stub_crawler_fetch_functions(
			$fetched,
			static function ( $url, $args ) {
				// 3xx with no Location warmed nothing.
				return array( 'response' => array( 'code' => 302 ) );
			}
		);
		$result = LiteSpeed_Crawler::crawl_batch( array( 'https://example.com/a' ) );
		$this->assertSame( 0, $result['success'] );
		$this->assertSame( 1, $result['failed'] );
	}

	public function test_crawl_batch_fallback_hop_cap_exhaustion_is_failure(): void {
		$fetched = array();
		$this->stub_crawler_fetch_functions(
			$fetched,
			static function ( $url, $args ) {
				// Endless same-host chain: every hop redirects again.
				return array(
					'response' => array( 'code' => 302 ),
					'headers'  => array( 'location' => 'https://example.com/loop' ),
				);
			}
		);
		$result = LiteSpeed_Crawler::crawl_batch( array( 'https://example.com/a' ) );
		// The terminal page was never warmed: failure, and the chain stops
		// at the hop cap (initial fetch + 5 hops).
		$this->assertSame( 0, $result['success'] );
		$this->assertSame( 1, $result['failed'] );
		$this->assertCount( 6, $fetched );
	}

	public function test_resolve_validated_redirect_normalizes_and_pins_ports(): void {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_http_validate_url' )->returnArg();
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $v ) {
				return rtrim( (string) $v, '/' );
			}
		);
		Util::reset_cached_home_urls();
		$method = new \ReflectionMethod( LiteSpeed_Crawler::class, 'resolve_validated_redirect' );
		// Audit #1490 review: dot-segments resolve canonically instead of
		// fetching a literal `/subdir/../other` path.
		$this->assertSame( 'https://example.com/other', $method->invoke( null, '../other', 'https://example.com/sub/dir/' ) );
		$this->assertSame( 'https://example.com/b', $method->invoke( null, '/a/../b', 'https://example.com/a' ) );
		$this->assertSame( 'https://example.com/b?x=1', $method->invoke( null, '/a/../b?x=1', 'https://example.com/a' ) );
		// Query-only keeps the current path; fragment-only re-resolves to
		// the same canonical page.
		$this->assertSame( 'https://example.com/a?x=1', $method->invoke( null, '?x=1', 'https://example.com/a' ) );
		$this->assertSame( 'https://example.com/a#sec', $method->invoke( null, '#sec', 'https://example.com/a' ) );
		// Port pinning (home is http://example.com, effective port 80):
		// nonstandard-port hops are dropped, standard ports pass.
		$this->assertFalse( $method->invoke( null, 'https://example.com:8443/x', 'https://example.com/a' ) );
		$this->assertFalse( $method->invoke( null, 'http://example.com:8080/x', 'http://example.com/a' ) );
		$this->assertSame( 'https://example.com:443/x', $method->invoke( null, 'https://example.com:443/x', 'https://example.com/a' ) );
		$this->assertSame( 'http://example.com:80/x', $method->invoke( null, 'http://example.com:80/x', 'http://example.com/a' ) );
	}
}
