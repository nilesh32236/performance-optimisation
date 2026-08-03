<?php
/**
 * Tests for Cache class.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use Brain\Monkey\Functions;

/**
 * Tests for Cache class.
 *
 * @package PerformanceOptimise\Tests
 */
class CacheTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Test that constructor sets domain from server variables.
	 */
	public function test_constructor_sets_domain_from_server(): void {
		$_SERVER['HTTP_HOST']   = 'example.com';
		$_SERVER['REQUEST_URI'] = '/test-page/';
		Functions\stubs(
			array(
				'wp_normalize_path',
				'wp_parse_url',
				'sanitize_text_field',
				'wp_unslash',
				'get_option',
				'trailingslashit',
			)
		);
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'wp_parse_url' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );

		$cache = new Cache();
		$this->assertNotNull( $cache );
	}

	/**
	 * Test that constructor rejects invalid domain names.
	 */
	public function test_constructor_rejects_invalid_domain(): void {
		$_SERVER['HTTP_HOST']   = 'invalid..domain';
		$_SERVER['REQUEST_URI'] = '/';
		Functions\stubs(
			array(
				'wp_normalize_path',
				'wp_parse_url',
				'sanitize_text_field',
				'wp_unslash',
				'get_option',
				'trailingslashit',
			)
		);
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'wp_parse_url' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );

		$cache = new Cache();
		$this->assertNotNull( $cache );
	}

	/**
	 * Test that clear_cache static method exists.
	 */
	public function test_clear_cache_static_method_exists(): void {
		$this->assertTrue( method_exists( Cache::class, 'clear_cache' ) );
	}

	/**
	 * Test that flush_runtime method exists.
	 */
	public function test_flush_runtime_method_exists(): void {
		$this->assertTrue( method_exists( Cache::class, 'flush_runtime' ) );
	}

	/**
	 * Test that flush_group method exists.
	 */
	public function test_flush_group_method_exists(): void {
		$this->assertTrue( method_exists( Cache::class, 'flush_group' ) );
	}

	/**
	 * Test that get_cache_size method exists.
	 */
	public function test_get_cache_size_method_exists(): void {
		$this->assertTrue( method_exists( Cache::class, 'get_cache_size' ) );
	}

	/**
	 * Test that maybe_apply_cdn method exists.
	 */
	public function test_maybe_apply_cdn_method_exists(): void {
		$this->assertTrue( method_exists( Cache::class, 'maybe_apply_cdn' ) );
	}

	/**
	 * Test that process_buffer_for_cache method exists.
	 */
	public function test_process_buffer_for_cache_method_exists(): void {
		$this->assertTrue( method_exists( Cache::class, 'process_buffer_for_cache' ) );
	}

	/**
	 * Data provider for should_inline_combined_css cases.
	 *
	 * @return array<string,array{options:array,inline_fn:bool,filter:bool|null,expected:bool}>
	 */
	public static function data_provider_should_inline_combined_css(): array {
		return array(
			'removeUnusedCSS enabled disables inlining' => array(
				'options'   => array( 'file_optimisation' => array( 'removeUnusedCSS' => true ) ),
				'inline_fn' => true,
				'filter'    => null,
				'expected'  => false,
			),
			'core inline function missing disables inlining' => array(
				'options'   => array( 'file_optimisation' => array() ),
				'inline_fn' => false,
				'filter'    => null,
				'expected'  => false,
			),
			'opt-out filter disables inlining'          => array(
				'options'   => array( 'file_optimisation' => array() ),
				'inline_fn' => true,
				'filter'    => false,
				'expected'  => false,
			),
			'default enables inlining'                  => array(
				'options'   => array( 'file_optimisation' => array() ),
				'inline_fn' => true,
				'filter'    => true,
				'expected'  => true,
			),
		);
	}

	/**
	 * Test the should_inline_combined_css decision helper.
	 *
	 * @dataProvider data_provider_should_inline_combined_css
	 *
	 * @param array     $options   Plugin options to construct Cache with.
	 * @param bool      $inline_fn Whether wp_maybe_inline_styles should exist.
	 * @param bool|null $filter    Value the wppo_inline_combined_css filter returns (null = default true).
	 * @param bool      $expected  Expected result of the helper.
	 */
	public function test_should_inline_combined_css( array $options, bool $inline_fn, ?bool $filter, bool $expected ): void {
		$_SERVER['HTTP_HOST']   = 'example.com';
		$_SERVER['REQUEST_URI'] = '/test-page/';
		Functions\stubs(
			array(
				'wp_normalize_path',
				'wp_parse_url',
				'sanitize_text_field',
				'wp_unslash',
				'trailingslashit',
			)
		);
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'wp_parse_url' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		if ( $inline_fn ) {
			Functions\when( 'wp_maybe_inline_styles' )->justReturn( '' );
		}

		if ( null === $filter ) {
			Functions\when( 'apply_filters' )->returnArg( 2 );
		} else {
			Functions\when( 'apply_filters' )->justReturn( $filter );
		}

		$cache      = new Cache( $options );
		$reflection = new ReflectionMethod( Cache::class, 'should_inline_combined_css' );
		$reflection->setAccessible( true );
		$result = $reflection->invoke( $cache );

		$this->assertSame( $expected, $result );
	}

	/**
	 * Data provider for styles_inline_size_limit cases.
	 *
	 * @return array<string,array{wv:string,filter:bool,expected:int}>
	 */
	public static function data_provider_styles_inline_size_limit(): array {
		return array(
			'WP 6.8 defaults to 20KB' => array( '6.8', false, 20000 ),
			'WP 6.9 defaults to 40KB' => array( '6.9', false, 40000 ),
			'WP 7.0 defaults to 40KB' => array( '7.0', false, 40000 ),
			'filter override wins'    => array( '7.0', true, 50000 ),
		);
	}

	/**
	 * Test the styles_inline_size_limit helper mirrors core's budget.
	 *
	 * @dataProvider data_provider_styles_inline_size_limit
	 *
	 * @param string $wp_version The WP version get_bloginfo returns.
	 * @param bool   $override   Whether a styles_inline_size_limit filter is active.
	 * @param int    $expected   Expected inline size limit.
	 */
	public function test_styles_inline_size_limit( string $wp_version, bool $override, int $expected ): void {
		$_SERVER['HTTP_HOST']   = 'example.com';
		$_SERVER['REQUEST_URI'] = '/test-page/';
		Functions\stubs(
			array(
				'wp_normalize_path',
				'wp_parse_url',
				'sanitize_text_field',
				'wp_unslash',
				'trailingslashit',
			)
		);
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'wp_parse_url' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( $wp_version );

		if ( $override ) {
			Functions\when( 'apply_filters' )->justReturn( 50000 );
		} else {
			Functions\when( 'apply_filters' )->returnArg( 2 );
		}

		$cache      = new Cache( array() );
		$reflection = new ReflectionMethod( Cache::class, 'styles_inline_size_limit' );
		$reflection->setAccessible( true );
		$result = $reflection->invoke( $cache );

		$this->assertSame( $expected, $result );
	}
}
