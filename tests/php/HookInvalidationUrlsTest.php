<?php
/**
 * Tests for wppo_invalidation_urls hook (Phase3 PR-C).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use Brain\Monkey\Functions;

/**
 * Verifies wppo_invalidation_urls extends purge list, sanitizes .., dedupes.
 *
 * @since NEXT
 */
class HookInvalidationUrlsTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Tear down after each test — restore global filesystem mock.
	 */
	protected function tearDown(): void {
		global $wp_filesystem;
		$wp_filesystem = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tearDown();
	}

	/**
	 * Build cache with mocked filesystem that records deletions.
	 *
	 * @param array $deleted Reference to deleted paths.
	 * @return Cache
	 */
	private function make_cache( array &$deleted ): Cache {
		$fs = \Mockery::mock();
		$fs->shouldReceive( 'exists' )->andReturn( true );
		$fs->shouldReceive( 'delete' )->andReturnUsing(
			static function ( $path ) use ( &$deleted ) {
				$deleted[] = $path;
				return true;
			}
		);
		$fs->shouldReceive( 'is_dir' )->andReturn( true );
		$fs->shouldReceive( 'dirlist' )->andReturn( array() );

		global $wp_filesystem;
		$wp_filesystem = $fs;

		Functions\when( 'wp_normalize_path' )->alias(
			static function ( $path ) {
				$path = str_replace( '\\', '/', (string) $path );
				$path = preg_replace( '|(?<=.)/+|', '/', $path );
				return $path;
			}
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_permalink' )->justReturn( 'http://example.com/sample-post/' );
		Functions\when( 'wp_make_link_relative' )->returnArg();
		Functions\when( 'get_option' )->justReturn( 'posts' );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_post_type_archive_link' )->justReturn( false );
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );
		Functions\when( 'wp_get_object_terms' )->justReturn( array() );
		Functions\when( 'wp_next_scheduled' )->justReturn( true );
		Functions\when( 'wp_rand' )->justReturn( 1 );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'trailingslashit' )->alias( static function ( $s ) { return rtrim( $s, '/' ) . '/'; } );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );

		$cache = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();
		foreach ( array( 'domain' => 'example.com', 'cache_root_dir' => '/tmp/wordpress/wp-content/cache/wppo', 'request_uri' => '/sample-post/', 'url_path' => 'sample-post', 'options' => array() ) as $k => $v ) {
			$prop = new \ReflectionProperty( Cache::class, $k );
			$prop->setAccessible( true );
			$prop->setValue( $cache, $v );
		}
		$fs_prop = new \ReflectionProperty( Cache::class, 'filesystem' );
		$fs_prop->setAccessible( true );
		$fs_prop->setValue( $cache, $fs );
		$init_prop = new \ReflectionProperty( Cache::class, 'fs_initialized' );
		$init_prop->setAccessible( true );
		$init_prop->setValue( $cache, true );

		return $cache;
	}

	/**
	 * Filter extends purge list with custom URL.
	 */
	public function test_filter_extends_purge_list(): void {
		$deleted = array();
		$cache   = $this->make_cache( $deleted );

		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'wppo_invalidation_urls' === $tag ) {
					$value[] = '/custom-feed/';
					return $value;
				}
				return $value;
			}
		);

		$cache->invalidate_dynamic_static_html( 123 );
		$joined = implode( ' ', $deleted );
		$this->assertStringContainsString( 'custom-feed', $joined );
	}

	/**
	 * Malicious ../ path is sanitized (no deletion outside cache root).
	 */
	public function test_traversal_sanitized(): void {
		$deleted = array();
		$cache   = $this->make_cache( $deleted );

		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'wppo_invalidation_urls' === $tag ) {
					return array( '../etc/passwd', '/about/' );
				}
				return $value;
			}
		);

		$cache->invalidate_dynamic_static_html( 123 );
		$joined = implode( ' ', $deleted );
		$this->assertStringNotContainsString( 'etc/passwd', $joined );
		$this->assertStringContainsString( 'about', $joined );
	}

	/**
	 * Duplicate URLs are deduped.
	 */
	public function test_dedupe(): void {
		$deleted = array();
		$cache   = $this->make_cache( $deleted );

		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'wppo_invalidation_urls' === $tag ) {
					return array( '/about/', '/about/', '/about/' );
				}
				return $value;
			}
		);

		$cache->invalidate_dynamic_static_html( 123 );
		// Count about deletions (html + maybe marker/role) — at least 1 but not 3* same html path repeatedly? We dedupe so only one html path.
		$counts = array_count_values( $deleted );
		$about_html = '/tmp/wordpress/wp-content/cache/wppo/example.com/about/index.html';
		$this->assertSame( 1, $counts[ $about_html ] ?? 0 );
	}
}
