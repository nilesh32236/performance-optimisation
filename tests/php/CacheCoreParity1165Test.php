<?php
/**
 * Core-parity tests for issue #1165.
 *
 * Locks the `combine_css` + `setup_hooks` wiring honoring core on-demand
 * block styles and the filtered `styles_inline_size_limit` budget:
 * - 6.9-active vs legacy matrix for `wp-block-*` exclusion.
 * - Combined-monolith escape hatch (`loadAllCoreBlockAssets` /
 *   `blockAssetsOnDemand` off) forces `wp-block-*` back into the bundle.
 * - Inline threshold honors the `styles_inline_size_limit` filter boundary.
 * - Falsy `wppo_inline_combined_css` keeps the combined file.
 * - Classic-theme no-FOUC: combined holds only non-block handles core never emits.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use Brain\Monkey\Functions;

/**
 * Core-parity tests for on-demand block styles + inline budget.
 *
 * @package PerformanceOptimise\Tests
 */
class CacheCoreParity1165Test extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Set up Brain Monkey and common stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		\PerformanceOptimise\Inc\Util::reset_runtime_caches();
		\PerformanceOptimise\Inc\Util::reset_html_processor_memo();
		\PerformanceOptimise\Inc\CDN::reset_cache();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( '0' );
		Functions\when( 'update_option' )->justReturn( true );
		unset( $GLOBALS['wp_version'] );
	}

	/**
	 * Tear down Brain Monkey.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		unset( $GLOBALS['wp_version'] );
		unset( $GLOBALS['wp_styles'] );
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a Cache instance without running its constructor.
	 *
	 * @param array $options Options to seed.
	 * @return Cache
	 */
	private function make_cache( array $options = array() ): Cache {
		$cache = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();
		$prop  = new \ReflectionProperty( Cache::class, 'options' );
		$prop->setValue( $cache, $options );
		return $cache;
	}

	/**
	 * Invoke a private Cache method.
	 *
	 * @param Cache  $cache Cache instance.
	 * @param string $name  Method name.
	 * @param array  $args  Arguments.
	 * @return mixed
	 */
	private function invoke_private( Cache $cache, string $name, array $args = array() ) {
		$method = new \ReflectionMethod( $cache, $name );
		return $method->invokeArgs( $cache, $args );
	}

	/**
	 * Queue fixture styles with real files behind Util::get_local_path().
	 *
	 * @param string[] $handles Handle names.
	 * @param int[]    $sizes   File size per handle.
	 */
	private function queue_styles( array $handles, array $sizes ): void {
		$dir = ABSPATH . 'wp-content/cache-test-fixtures-1165';
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			mkdir( $dir, 0777, true );
		}
		global $wp_styles;
		$registered = array();
		foreach ( $handles as $i => $handle ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/' . $handle . '.css', str_repeat( 'a', $sizes[ $i ] ?? 100 ) );
			$registered[ $handle ] = (object) array(
				'src'   => 'http://example.com/wp-content/cache-test-fixtures-1165/' . $handle . '.css',
				'args'  => 'all',
				'extra' => array(),
			);
		}
		$wp_styles             = \Mockery::mock();
		$wp_styles->registered = $registered;
		$wp_styles->queue      = $handles;
		$wp_styles->shouldReceive( 'get_data' )->with( \Mockery::any(), 'path' )->andReturn( null );
	}

	/**
	 * Remove the fixture directory.
	 */
	private function cleanup_fixtures(): void {
		$dir = ABSPATH . 'wp-content/cache-test-fixtures-1165';
		if ( is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '/*.css' ) as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $file );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Cleanup of temp fixture dir.
			rmdir( $dir );
		}
	}

	/**
	 * On 6.9+ with separate assets active, core block handles are never combined.
	 */
	public function test_separate_assets_excludes_core_block_handles(): void {
		$GLOBALS['wp_version'] = '6.9';
		Functions\when( 'wp_should_load_separate_core_block_assets' )->justReturn( true );
		$this->queue_styles( array( 'wp-block-library', 'wp-block-cover', 'theme-style' ), array( 100, 100, 100 ) );

		$cache    = $this->make_cache( array( 'file_optimisation' => array( 'blockAssetsOnDemand' => true ) ) );
		$eligible = $this->invoke_private( $cache, 'get_combined_handles', array( array( 'wp-block-library', 'wp-block-cover', 'theme-style' ), array() ) );

		$this->assertSame( array( 'theme-style' ), array_values( $eligible ) );
		$this->cleanup_fixtures();
	}

	/**
	 * Below 6.9 the legacy monolith path still combines core block handles.
	 */
	public function test_legacy_core_combines_block_handles(): void {
		$GLOBALS['wp_version'] = '6.8.2';
		$this->queue_styles( array( 'wp-block-library', 'theme-style' ), array( 100, 100 ) );

		$cache    = $this->make_cache( array( 'file_optimisation' => array( 'blockAssetsOnDemand' => true ) ) );
		$eligible = $this->invoke_private( $cache, 'get_combined_handles', array( array( 'wp-block-library', 'theme-style' ), array() ) );

		$this->assertContains( 'wp-block-library', $eligible );
		$this->assertContains( 'theme-style', $eligible );
		$this->cleanup_fixtures();
	}

	/**
	 * The combined-monolith escape hatch wins over separate assets on 6.9+.
	 *
	 * @dataProvider monolith_option_provider
	 * @param array $file_opt File-optimisation options forcing the monolith.
	 */
	public function test_monolith_escape_hatch_forces_block_handles( array $file_opt ): void {
		$GLOBALS['wp_version'] = '6.9';
		Functions\when( 'wp_should_load_separate_core_block_assets' )->justReturn( true );
		$this->queue_styles( array( 'wp-block-library', 'theme-style' ), array( 100, 100 ) );

		$cache    = $this->make_cache( array( 'file_optimisation' => $file_opt ) );
		$eligible = $this->invoke_private( $cache, 'get_combined_handles', array( array( 'wp-block-library', 'theme-style' ), array() ) );

		$this->assertContains( 'wp-block-library', $eligible );
		$this->cleanup_fixtures();
	}

	/**
	 * Provide monolith-forcing option sets.
	 *
	 * @return array[]
	 */
	public static function monolith_option_provider(): array {
		return array(
			'load-all toggle'  => array(
				array(
					'blockAssetsOnDemand'    => true,
					'loadAllCoreBlockAssets' => true,
				),
			),
			'on-demand off'    => array(
				array( 'blockAssetsOnDemand' => false ),
			),
			'legacy no-toggle' => array( array() ),
		);
	}

	/**
	 * The inline threshold honors the `styles_inline_size_limit` filter.
	 */
	public function test_inline_threshold_honors_filter(): void {
		$GLOBALS['wp_version'] = '6.9';
		Functions\when( 'wp_is_block_theme' )->justReturn( true );
		// Custom budget of 150 bytes: a 100-byte bundle fits, a 200-byte one does not.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, ...$args ) {
				unset( $args );
				if ( 'styles_inline_size_limit' === $hook ) {
					return 150;
				}
				return $value;
			}
		);
		$this->queue_styles( array( 'small' ), array( 100 ) );

		$cache = $this->make_cache( array( 'file_optimisation' => array() ) );
		$this->assertTrue( $this->invoke_private( $cache, 'should_skip_combine_for_inline_budget', array( array( 'small' ) ) ) );
		$this->assertSame( 150, $this->invoke_private( $cache, 'get_styles_inline_limit' ) );

		$this->cleanup_fixtures();
		$this->queue_styles( array( 'big' ), array( 200 ) );
		$this->assertFalse( $this->invoke_private( $cache, 'should_skip_combine_for_inline_budget', array( array( 'big' ) ) ) );
		$this->cleanup_fixtures();
	}

	/**
	 * A falsy `wppo_inline_combined_css` filter keeps the combined file.
	 */
	public function test_falsy_inline_filter_keeps_combined_file(): void {
		$GLOBALS['wp_version'] = '6.9';
		Functions\when( 'wp_is_block_theme' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, ...$args ) {
				unset( $args );
				if ( 'wppo_inline_combined_css' === $hook ) {
					return false;
				}
				return $value;
			}
		);
		$this->queue_styles( array( 'small' ), array( 100 ) );

		$cache = $this->make_cache( array( 'file_optimisation' => array() ) );
		$this->assertFalse( $this->invoke_private( $cache, 'should_skip_combine_for_inline_budget', array( array( 'small' ) ) ) );
		$this->cleanup_fixtures();
	}

	/**
	 * Classic-theme no-FOUC: combined holds only non-block handles core never emits.
	 */
	public function test_classic_theme_no_fouc_combines_only_non_block(): void {
		$GLOBALS['wp_version'] = '6.9';
		Functions\when( 'wp_should_load_separate_core_block_assets' )->justReturn( true );
		Functions\when( 'wp_is_block_theme' )->justReturn( false );
		$this->queue_styles( array( 'wp-block-library', 'wp-block-cover', 'theme-style' ), array( 100, 100, 100 ) );

		$cache    = $this->make_cache( array( 'file_optimisation' => array( 'blockAssetsOnDemand' => true ) ) );
		$eligible = $this->invoke_private( $cache, 'get_combined_handles', array( array( 'wp-block-library', 'wp-block-cover', 'theme-style' ), array() ) );

		// No duplicate output per block handle: core hoists them, combine ignores them.
		$this->assertSame( array( 'theme-style' ), array_values( $eligible ) );
		// Classic themes stay on the combine path (skip is block-theme-only).
		$this->assertFalse( $this->invoke_private( $cache, 'should_skip_combine_for_inline_budget', array( $eligible ) ) );
		$this->cleanup_fixtures();
	}
}
