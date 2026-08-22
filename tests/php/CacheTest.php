<?php
/**
 * Tests for Cache class.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

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
	 * Data provider for register_combine_css_path cases.
	 *
	 * @return array<string,array{options:array,filter:bool|null,expected:bool}>
	 */
	public static function data_provider_register_combine_css_path(): array {
		return array(
			'removeUnusedCSS enabled disables inlining' => array(
				'options'  => array( 'file_optimisation' => array( 'removeUnusedCSS' => true ) ),
				'filter'   => null,
				'expected' => false,
			),
			'opt-out filter disables inlining'          => array(
				'options'  => array( 'file_optimisation' => array() ),
				'filter'   => false,
				'expected' => false,
			),
			'default enables inlining'                  => array(
				'options'  => array( 'file_optimisation' => array() ),
				'filter'   => true,
				'expected' => true,
			),
		);
	}

	/**
	 * Test the register_combine_css_path decision helper.
	 *
	 * @param array     $options  Plugin options to construct Cache with.
	 * @param bool|null $filter   Value the wppo_inline_combined_css filter returns (null = default true).
	 * @param bool      $expected Whether wp_style_add_data is expected to be called.
	 */
	#[DataProvider( 'data_provider_register_combine_css_path' )]
	public function test_register_combine_css_path_respects_inline_guards( array $options, ?bool $filter, bool $expected ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$temp_file = tempnam( sys_get_temp_dir(), 'wppo-combine-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $temp_file, 'body { color: red; }' );

		if ( false === $filter ) {
			\Brain\Monkey\Filters\expectApplied( 'wppo_inline_combined_css' )->once()->andReturn( false );
		}

		if ( $expected ) {
			Functions\expect( 'wp_style_add_data' )->once()->with( 'wppo-combine-css', 'path', $temp_file );
		} else {
			Functions\expect( 'wp_style_add_data' )->never();
		}

		$cache = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();

		$options_prop = new \ReflectionProperty( Cache::class, 'options' );
		$options_prop->setAccessible( true );
		$options_prop->setValue( $cache, $options );

		$reflection = new ReflectionMethod( Cache::class, 'register_combine_css_path' );
		$reflection->setAccessible( true );
		$reflection->invoke( $cache, $temp_file );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $temp_file );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Data provider for get_styles_inline_limit cases.
	 *
	 * @return array<string,array{wp_version:string,filter:bool,expected:int}>
	 */
	public static function data_provider_get_styles_inline_limit(): array {
		return array(
			'WP 6.8 defaults to 20KB'          => array( '6.8', false, 20000 ),
			'WP 6.9 defaults to 40KB'          => array( '6.9', false, 40000 ),
			'WP 7.0 defaults to 40KB'          => array( '7.0', false, 40000 ),
			'unknown version defaults to 40KB' => array( '', false, 40000 ),
			'filter override wins'             => array( '7.0', true, 50000 ),
		);
	}

	/**
	 * Test the get_styles_inline_limit helper mirrors core's budget.
	 *
	 * @param string $wp_version The WP version in $GLOBALS['wp_version'] ('' = unset).
	 * @param bool   $override   Whether a styles_inline_size_limit filter is active.
	 * @param int    $expected   Expected inline size limit.
	 */
	#[DataProvider( 'data_provider_get_styles_inline_limit' )]
	public function test_get_styles_inline_limit( string $wp_version, bool $override, int $expected ): void {
		if ( $override ) {
			Functions\when( 'apply_filters' )->justReturn( 50000 );
		} else {
			Functions\when( 'apply_filters' )->returnArg( 2 );
		}

		if ( '' === $wp_version ) {
			unset( $GLOBALS['wp_version'] );
		} else {
			$GLOBALS['wp_version'] = $wp_version;
		}

		$cache      = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();
		$reflection = new ReflectionMethod( Cache::class, 'get_styles_inline_limit' );
		$reflection->setAccessible( true );
		$result = $reflection->invoke( $cache );

		$this->assertSame( $expected, $result );
	}

	/**
	 * Test that maybe_preload_combine_css outputs nothing when no preload is set.
	 */
	public function test_maybe_preload_combine_css_outputs_nothing_when_empty(): void {
		$cache = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();

		ob_start();
		$cache->maybe_preload_combine_css();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Test that maybe_preload_combine_css emits a style preload and resets the URL.
	 */
	public function test_maybe_preload_combine_css_outputs_preload_link(): void {
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'wp_kses' )->returnArg( 1 );

		$cache = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();
		$prop  = new \ReflectionProperty( Cache::class, 'combine_css_preload_url' );
		$prop->setAccessible( true );
		$prop->setValue( $cache, 'http://example.com/wp-content/cache/wppo/example.com/index.css?ver=123456' );

		ob_start();
		$cache->maybe_preload_combine_css();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'rel="preload"', $output );
		$this->assertStringContainsString( 'as="style"', $output );
		$this->assertStringContainsString( 'http://example.com/wp-content/cache/wppo/example.com/index.css?ver=123456', $output );

		// The URL is consumed after emission so a second call is a no-op.
		$this->assertSame( '', $prop->getValue( $cache ) );
	}

	/**
	 * Test that delete_cache_files() removes both the HTML and its gzip variant,
	 * covering static pages that speculative prerendering may have requested and
	 * cached (they are served from the same per-URL files).
	 */
	public function test_delete_cache_files_removes_html_and_gzip(): void {
		$deleted = array();
		$fs      = \Mockery::mock();
		$fs->shouldReceive( 'exists' )->andReturn( true );
		$fs->shouldReceive( 'delete' )->andReturnUsing(
			static function ( $path ) use ( &$deleted ) {
				$deleted[] = $path;
				return true;
			}
		);
		$fs->shouldReceive( 'is_dir' )->andReturn( false );
		$fs->shouldReceive( 'dirlist' )->andReturn( array() );

		global $wp_filesystem;
		$wp_filesystem = $fs;

		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'get_option' )->justReturn( '' );

		$cache = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();

		$root_prop = new \ReflectionProperty( Cache::class, 'cache_root_dir' );
		$root_prop->setAccessible( true );
		$root_prop->setValue( $cache, '/tmp/wordpress/wp-content/cache/wppo' );

		$domain_prop = new \ReflectionProperty( Cache::class, 'domain' );
		$domain_prop->setAccessible( true );
		$domain_prop->setValue( $cache, 'example.com' );

		$fs_prop = new \ReflectionProperty( Cache::class, 'filesystem' );
		$fs_prop->setAccessible( true );
		$fs_prop->setValue( $cache, $fs );

		$initialized_prop = new \ReflectionProperty( Cache::class, 'fs_initialized' );
		$initialized_prop->setAccessible( true );
		$initialized_prop->setValue( $cache, true );

		$method = new ReflectionMethod( Cache::class, 'delete_cache_files' );
		$method->setAccessible( true );
		$result = $method->invoke( $cache, '/tmp/wordpress/wp-content/cache/wppo/example.com/about/index.html' );

		$this->assertTrue( $result );
		$this->assertContains( '/tmp/wordpress/wp-content/cache/wppo/example.com/about/index.html', $deleted );
		$this->assertContains( '/tmp/wordpress/wp-content/cache/wppo/example.com/about/index.html.gz', $deleted );
	}

	/**
	 * Test that delete_all_cache_files scopes the min cache directory to the
	 * current blog and never deletes the shared min root.
	 */
	public function test_delete_all_cache_files_scopes_min_dir_to_current_blog(): void {
		$deleted = array();
		$fs      = \Mockery::mock();
		$fs->shouldReceive( 'is_dir' )->andReturn( true );
		$fs->shouldReceive( 'delete' )->andReturnUsing(
			static function ( $path ) use ( &$deleted ) {
				$deleted[] = $path;
				return true;
			}
		);
		$fs->shouldReceive( 'dirlist' )->andReturn( array() );

		global $wp_filesystem;
		$wp_filesystem = $fs;

		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );

		$cache = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();

		$root_prop = new \ReflectionProperty( Cache::class, 'cache_root_dir' );
		$root_prop->setAccessible( true );
		$root_prop->setValue( $cache, '/tmp/wordpress/wp-content/cache/wppo' );

		$domain_prop = new \ReflectionProperty( Cache::class, 'domain' );
		$domain_prop->setAccessible( true );
		$domain_prop->setValue( $cache, 'example.com' );

		$fs_prop = new \ReflectionProperty( Cache::class, 'filesystem' );
		$fs_prop->setAccessible( true );
		$fs_prop->setValue( $cache, $fs );

		$initialized_prop = new \ReflectionProperty( Cache::class, 'fs_initialized' );
		$initialized_prop->setAccessible( true );
		$initialized_prop->setValue( $cache, true );

		$method = new ReflectionMethod( Cache::class, 'delete_all_cache_files' );
		$method->setAccessible( true );
		$result = $method->invoke( $cache );

		$this->assertTrue( $result );
		$this->assertContains( '/tmp/wordpress/wp-content/cache/wppo/example.com', $deleted );
		$this->assertContains( '/tmp/wordpress/wp-content/cache/wppo/min/1', $deleted );
		$this->assertContains( '/tmp/wordpress/wp-content/cache/wppo/min/css', $deleted );
		$this->assertContains( '/tmp/wordpress/wp-content/cache/wppo/min/js', $deleted );
		$this->assertNotContains( '/tmp/wordpress/wp-content/cache/wppo/min', $deleted );
	}

	/**
	 * Data provider for get_combined_handles separate-assets cases.
	 *
	 * @return array<string,array{separate:bool,expected:array<string>}>
	 */
	public static function data_provider_get_combined_handles_separate_assets(): array {
		return array(
			'separate assets active excludes core block handles' => array(
				'separate' => true,
				'expected' => array( 'theme', 'plugin-css' ),
			),
			'separate assets off keeps wp-block handles' => array(
				'separate' => false,
				'expected' => array( 'wp-block-library', 'wp-block-cover', 'theme', 'plugin-css' ),
			),
		);
	}

	/**
	 * Test that get_combined_handles excludes the whole core block-asset family
	 * when WP 6.9+ separate assets are active, and keeps them when they are not.
	 *
	 * The sidecar produced from this list must match what the combine generation
	 * loop folds into the file, so the same gate is asserted here that the mtime
	 * scan and generation loops use.
	 *
	 * @param bool          $separate Whether core loads separate block assets on demand.
	 * @param array<string> $expected The handles expected in the combined list.
	 */
	#[DataProvider( 'data_provider_get_combined_handles_separate_assets' )]
	public function test_get_combined_handles_honors_separate_block_assets( bool $separate, array $expected ): void {
		Functions\when( 'wp_should_load_separate_core_block_assets' )->justReturn( $separate );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		global $wp_styles;
		$wp_styles             = \Mockery::mock();
		$wp_styles->registered = array(
			'wp-block-library' => (object) array(
				'src'  => 'http://example.com/wp-includes/css/dist/block-library/style.css',
				'args' => 'all',
			),
			'wp-block-cover'   => (object) array(
				'src'  => 'http://example.com/wp-includes/css/dist/block-library/style.css',
				'args' => 'all',
			),
			'theme'            => (object) array(
				'src'  => 'http://example.com/wp-content/themes/t/style.css',
				'args' => 'all',
			),
			'plugin-css'       => (object) array(
				'src'  => 'http://example.com/wp-content/plugins/p/style.css',
				'args' => 'all',
			),
		);
		$wp_styles->queue      = array( 'wp-block-library', 'wp-block-cover', 'theme', 'plugin-css' );
		$wp_styles->shouldReceive( 'get_data' )->with( \Mockery::any(), 'path' )->andReturn( false );

		$cache      = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();
		$reflection = new ReflectionMethod( Cache::class, 'get_combined_handles' );
		$reflection->setAccessible( true );
		$result = $reflection->invoke( $cache, $wp_styles->queue, array() );

		$this->assertSame( $expected, $result );
	}

	/**
	 * Test that the separate-assets gate resolves to true when core exposes it.
	 */
	public function test_block_assets_are_separate_true_when_core_gate_active(): void {
		Functions\when( 'wp_should_load_separate_core_block_assets' )->justReturn( true );

		$cache      = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();
		$reflection = new ReflectionMethod( Cache::class, 'block_assets_are_separate' );
		$reflection->setAccessible( true );

		$this->assertTrue( $reflection->invoke( $cache ) );
	}

	/**
	 * Test that the separate-assets gate stays false when core reports it off.
	 */
	public function test_block_assets_are_separate_false_when_core_gate_off(): void {
		Functions\when( 'wp_should_load_separate_core_block_assets' )->justReturn( false );

		$cache      = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();
		$reflection = new ReflectionMethod( Cache::class, 'block_assets_are_separate' );
		$reflection->setAccessible( true );

		$this->assertFalse( $reflection->invoke( $cache ) );
	}

	/**
	 * Test that the separate-assets gate stays false on pre-6.9 cores that have
	 * no wp_should_load_separate_core_block_assets() function.
	 */
	public function test_block_assets_are_separate_false_on_pre_69(): void {
		Functions\expect( 'function_exists' )->with( 'wp_should_load_separate_core_block_assets' )->andReturn( false );

		$cache      = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();
		$reflection = new ReflectionMethod( Cache::class, 'block_assets_are_separate' );
		$reflection->setAccessible( true );

		$this->assertFalse( $reflection->invoke( $cache ) );
	}
}
