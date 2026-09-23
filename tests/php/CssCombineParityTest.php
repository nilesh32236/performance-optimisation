<?php
/**
 * Parity tests for the ARCH-006 Css_Combine extraction (issue #1538).
 *
 * Proves the CSS-combine cluster moved from `Cache` to
 * `PerformanceOptimise\Inc\Css_Combine` without behavior change:
 * combined-file bytes + handles journal agree between the `Cache` proxies
 * and the new owner, inline-budget branches, safe-mode fallback on/off,
 * sandbox-effective variants, Elementor bypass, exclusion lists,
 * preload-hint emission, the `wppo_inline_combined_css` / fetchpriority
 * filter contracts, and multisite (domain-isolated) cache paths.
 *
 * Instance state stays on `Cache` (Option A); the filesystem mock delegates
 * to the real disk inside an isolated temp tree so the safe-fallback
 * validity gates (`is_file()`/`filesize()`) exercise real files.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\Css_Combine;
use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Sandbox_Preview;
use Brain\Monkey\Functions;

/**
 * ARCH-006 Css_Combine parity tests.
 *
 * @package PerformanceOptimise\Tests
 */
class CssCombineParityTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap {
		setUp as protected wppoSetUp;
		tearDown as protected wppoTearDown;
	}

	/**
	 * Isolated temp root for cache output.
	 *
	 * @var string
	 */
	private string $tmp_root = '';

	/**
	 * Fixture CSS directory under WP_CONTENT_DIR (so URL->path mapping resolves).
	 *
	 * @var string
	 */
	private string $src_dir = '';

	/**
	 * Public URL base matching $src_dir.
	 *
	 * @var string
	 */
	private string $src_url_base = '';

	/**
	 * Per-test apply_filters() overrides: tag => value or callable.
	 *
	 * @var array<string,mixed>
	 */
	private array $filter_map = array();

	/**
	 * Tags for which has_filter() reports true.
	 *
	 * @var string[]
	 */
	private array $has_filters = array();

	/**
	 * Handles passed to wp_dequeue_style().
	 *
	 * @var string[]
	 */
	private array $dequeued = array();

	/**
	 * Argument lists passed to wp_enqueue_style().
	 *
	 * @var array[]
	 */
	private array $enqueued = array();

	/**
	 * Arguments passed to wp_style_add_data().
	 *
	 * @var array[]
	 */
	private array $style_data_calls = array();

	/**
	 * Final combined payloads captured by the filesystem mock (path => bytes).
	 *
	 * @var array<string,string>
	 */
	private array $written = array();

	/**
	 * get_contents() call arguments recorded by the filesystem mock.
	 *
	 * @var string[]
	 */
	private array $read_paths = array();

	/**
	 * exists() call arguments recorded by the filesystem mock.
	 *
	 * @var string[]
	 */
	private array $stat_paths = array();

	/**
	 * Fresh stubs + isolated temp tree per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->wppoSetUp();
		$this->filter_map       = array();
		$this->has_filters      = array();
		$this->dequeued         = array();
		$this->enqueued         = array();
		$this->style_data_calls = array();
		$this->written          = array();
		$this->read_paths       = array();
		$this->stat_paths       = array();

		$GLOBALS['wp_version'] = '6.8.0';

		$test = $this;
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) use ( $test ) {
				return $test->filter_value( $tag, $value );
			}
		);
		Functions\when( 'has_filter' )->alias(
			static function ( $tag ) use ( $test ) {
				return $test->has_filter_tag( $tag );
			}
		);
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_is_mobile' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'wp_should_load_separate_core_block_assets' )->justReturn( false );
		Functions\when( 'wp_is_block_theme' )->justReturn( false );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				return 'wppo_settings' === $name ? array() : $default;
			}
		);
		Functions\when( 'get_site_option' )->justReturn( array() );
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'site_url' )->justReturn( 'http://example.com/' );
		Functions\when( 'content_url' )->justReturn( 'http://example.com/wp-content' );
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) {
				// Arm the CSS-fallback log throttle so fallback tests assert
				// the fail-open behavior, not the Log::add() side effect.
				if ( is_string( $key ) && false !== strpos( $key, 'fallback' ) ) {
					return true;
				}
				return false;
			}
		);
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'wp_is_json_request' )->justReturn( false );
		Functions\when( 'WP_Filesystem' )->justReturn( true );
		Functions\when( 'wp_dequeue_style' )->alias(
			function ( string $handle ) {
				$this->dequeued[] = $handle;
			}
		);
		Functions\when( 'wp_enqueue_style' )->alias(
			function ( ...$args ) {
				$this->enqueued[] = $args;
			}
		);
		Functions\when( 'wp_style_add_data' )->alias(
			function ( ...$args ) {
				$this->style_data_calls[] = $args;
			}
		);

		$this->tmp_root     = WP_CONTENT_DIR . '/wppo-combine-parity-' . uniqid();
		$this->src_dir      = $this->tmp_root . '/src';
		$this->src_url_base = 'http://example.com/wp-content/' . basename( $this->tmp_root ) . '/src';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test-only fixture tree.
		mkdir( $this->tmp_root . '/cache', 0777, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test-only fixture tree.
		mkdir( $this->src_dir, 0777, true );

		global $wp_filesystem;
		$wp_filesystem = \Mockery::mock();
		$wp_filesystem->shouldReceive( 'is_dir' )->andReturn( true );
		$wp_filesystem->shouldReceive( 'mkdir' )->andReturn( true );
	}

	/**
	 * No memo, superglobal, or temp-file leakage into later suites.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp_styles'] );
		unset( $GLOBALS['wp_filesystem'] );
		unset( $_GET['wppo_preview'], $_GET['_wppo_preview_nonce'], $_GET['elementor-preview'] );
		unset( $GLOBALS['wp_version'] );
		Sandbox_Preview::reset_memo();
		Main::reset_elementor_memo();
		$prop = new \ReflectionProperty( Cache::class, 'inline_drift_logged' );
		$prop->setValue( null, false );
		$this->remove_dir( $this->tmp_root );
		$this->remove_dir( $this->src_dir );
		$this->wppoTearDown();
	}

	/**
	 * Resolve a stubbed filter value (override map, else passthrough).
	 *
	 * @param string $tag   Filter tag.
	 * @param mixed  $value Incoming value.
	 * @return mixed Filtered value.
	 */
	public function filter_value( $tag, $value = null ) {
		if ( array_key_exists( $tag, $this->filter_map ) ) {
			$override = $this->filter_map[ $tag ];
			if ( $override instanceof \Closure ) {
				return $override( $value );
			}
			return $override;
		}
		return $value;
	}

	/**
	 * Whether a filter tag is registered for this test.
	 *
	 * @param string $tag Filter tag.
	 * @return bool True when explicitly registered.
	 */
	public function has_filter_tag( $tag ): bool {
		return in_array( $tag, $this->has_filters, true );
	}

	/**
	 * Recursively remove a temp directory.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function remove_dir( string $dir ): void {
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		foreach ( $items ? $items : array() as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->remove_dir( $path );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only cleanup.
				unlink( $path );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test-only cleanup.
		rmdir( $dir );
	}

	/**
	 * Write a fixture stylesheet and return its public URL.
	 *
	 * @param string $name Stylesheet filename.
	 * @param string $css  Stylesheet content.
	 * @return string Public URL of the fixture.
	 */
	private function write_source( string $name, string $css ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only fixture.
		file_put_contents( $this->src_dir . '/' . $name, $css );
		return $this->src_url_base . '/' . $name;
	}

	/**
	 * Filesystem mock delegating to the real disk while capturing writes.
	 *
	 * @return object Mockery filesystem double.
	 */
	private function make_filesystem() {
		$test = $this;
		$fs   = \Mockery::mock();
		$fs->shouldReceive( 'exists' )->andReturnUsing(
			function ( $path ) use ( $test ) {
				$test->stat_paths[] = $path;
				return file_exists( $path );
			}
		);
		$fs->shouldReceive( 'mtime' )->andReturnUsing(
			static function ( $path ) {
				return file_exists( $path ) ? filemtime( $path ) : false;
			}
		);
		$fs->shouldReceive( 'size' )->andReturnUsing(
			static function ( $path ) {
				return file_exists( $path ) ? filesize( $path ) : false;
			}
		);
		$fs->shouldReceive( 'get_contents' )->andReturnUsing(
			function ( $path ) use ( $test ) {
				$test->read_paths[] = $path;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Test-only read.
				return file_exists( $path ) ? file_get_contents( $path ) : false;
			}
		);
		$tmp_contents = array();
		$fs->shouldReceive( 'put_contents' )->andReturnUsing(
			static function ( $path, $contents ) use ( &$tmp_contents ) {
				$tmp_contents[ $path ] = $contents;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only write.
				file_put_contents( $path, $contents );
				return true;
			}
		);
		$fs->shouldReceive( 'move' )->andReturnUsing(
			function ( $from, $to ) use ( $test, &$tmp_contents ) {
				if ( isset( $tmp_contents[ $from ] ) ) {
					$test->written[ $to ] = $tmp_contents[ $from ];
					unset( $tmp_contents[ $from ] );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Test-only atomic move.
				rename( $from, $to );
				return true;
			}
		);
		$fs->shouldReceive( 'delete' )->andReturnUsing(
			static function ( $path ) {
				if ( file_exists( $path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only cleanup.
					unlink( $path );
				}
				return true;
			}
		);
		return $fs;
	}

	/**
	 * Build a Cache with reflection-set state (mirrors CssCombineFallbackTest).
	 *
	 * @param array  $file_opt File-optimisation slice.
	 * @param object $fs       Filesystem double.
	 * @param string $domain   Cache domain (multisite isolation).
	 * @return Cache Cache instance.
	 */
	private function make_cache( array $file_opt, $fs, string $domain = 'example.com' ): Cache {
		$instance = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();
		$props    = array(
			'cache_root_dir' => $this->tmp_root . '/cache',
			'cache_root_url' => 'http://example.com/wp-content/cache/wppo',
			'domain'         => $domain,
			'options'        => array(
				'file_optimisation' => $file_opt,
				'cache_settings'    => array(),
			),
			'filesystem'     => $fs,
			'fs_initialized' => true,
			'request_uri'    => '/',
			'url_path'       => '',
		);
		foreach ( $props as $name => $value ) {
			$prop = new \ReflectionProperty( Cache::class, $name );
			$prop->setValue( $instance, $value );
		}
		// The write path enforces realpath containment: the domain directory
		// must exist on disk (production creates it via WP_Filesystem).
		$domain_dir = $this->tmp_root . '/cache/' . $domain;
		if ( ! is_dir( $domain_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test-only fixture tree.
			mkdir( $domain_dir, 0777, true );
		}
		return $instance;
	}

	/**
	 * Populate the global $wp_styles registry mock.
	 *
	 * @param array $sources Map of handle => public CSS URL.
	 * @param mixed $path_data Value returned by get_data( $handle, 'path' ).
	 * @return void
	 */
	private function make_styles( array $sources, $path_data = false ): void {
		$registered = array();
		foreach ( $sources as $handle => $src ) {
			$registered[ $handle ] = (object) array(
				'src'  => $src,
				'args' => 'all',
			);
		}
		global $wp_styles;
		$wp_styles = \Mockery::mock();
		$wp_styles->shouldReceive( 'get_data' )->andReturn( $path_data );
		$wp_styles->queue      = array_keys( $sources );
		$wp_styles->registered = $registered;
	}

	/**
	 * Invoke a private method on an instance via reflection.
	 *
	 * @param object $instance Instance.
	 * @param string $name     Method name.
	 * @param array  $args     Arguments.
	 * @return mixed Result.
	 */
	private function invoke_private( $instance, string $name, array $args = array() ) {
		$method = new \ReflectionMethod( $instance, $name );
		return $method->invokeArgs( $instance, $args );
	}

	/**
	 * Combined-file path for the default (empty-variant) request.
	 *
	 * @param Cache  $cache   Cache instance.
	 * @param string $variant Variant suffix.
	 * @return string Absolute combined-file path.
	 */
	private function combined_path( Cache $cache, string $variant = '' ): string {
		return $this->invoke_private( $cache, 'get_cache_file_path', array( 'css', '', $variant ) );
	}

	/**
	 * Combined bytes are byte-identical and the journal records the handle set.
	 *
	 * @return void
	 */
	public function test_combined_output_bytes_and_journal(): void {
		$a = $this->write_source( 'a.css', 'body { color: red; }' );
		$b = $this->write_source( 'b.css', 'h1 { margin: 0; }' );
		$this->make_styles( array( 'a' => $a, 'b' => $b ) );
		$cache = $this->make_cache( array(), $this->make_filesystem() );

		$cache->combine_css();

		$this->assertSame( array( 'a', 'b' ), $this->dequeued );
		$this->assertCount( 1, $this->enqueued );
		$this->assertSame( 'wppo-combine-css', $this->enqueued[0][0] );

		$path = $this->combined_path( $cache );
		$this->assertArrayHasKey( $path, $this->written );
		$this->assertStringContainsString( 'color:red', $this->written[ $path ] );
		$this->assertStringContainsString( 'margin:0', $this->written[ $path ] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Test-only read.
		$this->assertSame( '["a","b"]', file_get_contents( $path . '.handles' ) );

		// Preload state carries the versioned combined URL.
		$prop = new \ReflectionProperty( Cache::class, 'combine_css_preload_url' );
		$this->assertStringContainsString( '?ver=', $prop->getValue( $cache ) );
	}

	/**
	 * Proxy and service agree byte-for-byte on identical inputs.
	 *
	 * @return void
	 */
	public function test_proxy_service_output_parity(): void {
		$a = $this->write_source( 'a.css', 'body { color: red; }' );
		$b = $this->write_source( 'b.css', 'h1 { margin: 0; }' );

		$this->make_styles( array( 'a' => $a, 'b' => $b ) );
		$cache_one = $this->make_cache( array(), $this->make_filesystem() );
		$cache_one->combine_css();
		$bytes_one = $this->written;

		$this->written = array();
		$this->make_styles( array( 'a' => $a, 'b' => $b ) );
		$cache_two = $this->make_cache( array(), $this->make_filesystem() );
		( new Css_Combine( $cache_two ) )->combine_css();

		$path_one = $this->combined_path( $cache_one );
		$path_two = $this->combined_path( $cache_two );
		$this->assertArrayHasKey( $path_one, $bytes_one );
		$this->assertArrayHasKey( $path_two, $this->written );
		$this->assertSame( $bytes_one[ $path_one ], $this->written[ $path_two ] );
	}

	/**
	 * A fresh combined file is reused with zero steady-state writes.
	 *
	 * @return void
	 */
	public function test_cached_reuse_skips_write_and_fetch(): void {
		$a = $this->write_source( 'a.css', 'body { color: red; }' );
		$b = $this->write_source( 'b.css', 'h1 { margin: 0; }' );
		$this->make_styles( array( 'a' => $a, 'b' => $b ) );
		$cache = $this->make_cache( array(), $this->make_filesystem() );
		$cache->combine_css();
		$this->assertNotEmpty( $this->written );

		// Second request, same inputs: reuse must not write or re-fetch sources.
		$this->written    = array();
		$this->read_paths = array();
		$this->dequeued   = array();
		$this->enqueued   = array();
		$this->make_styles( array( 'a' => $a, 'b' => $b ) );
		$cache_two = $this->make_cache( array(), $this->make_filesystem() );
		$cache_two->combine_css();

		$this->assertSame( array(), $this->written );
		foreach ( $this->read_paths as $read ) {
			$this->assertStringNotContainsString( 'a.css', $read );
			$this->assertStringNotContainsString( 'b.css', $read );
		}
		$this->assertSame( array(), $this->dequeued );
		$this->assertCount( 1, $this->enqueued );
		$this->assertSame( 'wppo-combine-css', $this->enqueued[0][0] );
	}

	/**
	 * A journal mismatch regenerates the combined file.
	 *
	 * @return void
	 */
	public function test_journal_mismatch_regenerates(): void {
		$a = $this->write_source( 'a.css', 'body { color: red; }' );
		$b = $this->write_source( 'b.css', 'h1 { margin: 0; }' );
		$this->make_styles( array( 'a' => $a, 'b' => $b ) );
		$cache = $this->make_cache( array(), $this->make_filesystem() );
		$cache->combine_css();

		// Simulate a stale sidecar (built for a smaller handle set).
		$path = $this->combined_path( $cache );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only fixture.
		file_put_contents( $path . '.handles', '["a"]' );
		touch( $path, time() + 60 );
		touch( $path . '.handles', time() + 60 );

		$this->written    = array();
		$this->dequeued   = array();
		$this->enqueued   = array();
		$this->make_styles( array( 'a' => $a, 'b' => $b ) );
		$cache_two = $this->make_cache( array(), $this->make_filesystem() );
		$cache_two->combine_css();

		$this->assertArrayHasKey( $path, $this->written );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Test-only read.
		$this->assertSame( '["a","b"]', file_get_contents( $path . '.handles' ) );
		$this->assertSame( array( 'a', 'b' ), $this->dequeued );
	}

	/**
	 * Exact-handle and URL-fragment exclusions stay out of the combined file.
	 *
	 * @return void
	 */
	public function test_exclusion_lists(): void {
		$a = $this->write_source( 'alpha.css', 'body { color: red; }' );
		$b = $this->write_source( 'keepout-handle.css', 'h1 { margin: 0; }' );
		$c = $this->write_source( 'zz-excluded-zz.css', 'p { padding: 0; }' );
		$this->make_styles( array( 'alpha' => $a, 'keepout-handle' => $b, 'themer' => $c ) );
		$cache = $this->make_cache(
			array( 'excludeCombineCSS' => "keepout-handle\nzz-excluded-zz" ),
			$this->make_filesystem()
		);

		$cache->combine_css();

		$this->assertSame( array( 'alpha' ), $this->dequeued );
		$path = $this->combined_path( $cache );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Test-only read.
		$this->assertSame( '["alpha"]', file_get_contents( $path . '.handles' ) );
		$this->assertStringNotContainsString( 'margin:0', $this->written[ $path ] );
	}

	/**
	 * A falsy wppo_inline_combined_css filter keeps the file external.
	 *
	 * @return void
	 */
	public function test_inline_combined_css_falsy_disables_inlining(): void {
		$a = $this->write_source( 'a.css', 'body { color: red; }' );
		$this->make_styles( array( 'a' => $a ) );
		$this->filter_map['wppo_inline_combined_css'] = false;
		$cache = $this->make_cache( array(), $this->make_filesystem() );

		$cache->combine_css();

		// The combined file is still built, but no `path` data is registered.
		$path = $this->combined_path( $cache );
		$this->assertArrayHasKey( $path, $this->written );
		$this->assertSame( array(), $this->style_data_calls );

		// Truthy control registers `path` data for core's inline pass.
		$this->filter_map['wppo_inline_combined_css'] = true;
		$this->written          = array();
		$this->style_data_calls = array();
		$this->make_styles( array( 'a' => $a ) );
		$cache_two = $this->make_cache( array(), $this->make_filesystem() );
		$cache_two->combine_css();

		$this->assertNotEmpty( $this->style_data_calls );
		$this->assertSame( 'wppo-combine-css', $this->style_data_calls[0][0] );
		$this->assertSame( 'path', $this->style_data_calls[0][1] );
	}

	/**
	 * Preload hint carries the versioned URL and honors the fetchpriority filter.
	 *
	 * @return void
	 */
	public function test_preload_hint_emission_and_fetchpriority(): void {
		$a = $this->write_source( 'a.css', 'body { color: red; }' );
		$this->make_styles( array( 'a' => $a ) );
		$cache = $this->make_cache( array(), $this->make_filesystem() );
		$cache->combine_css();

		ob_start();
		$cache->maybe_preload_combine_css();
		$tag = (string) ob_get_clean();

		$this->assertStringContainsString( 'rel="preload"', $tag );
		$this->assertStringContainsString( 'as="style"', $tag );
		$this->assertStringContainsString( 'fetchpriority="high"', $tag );

		// Second request reuses the cached file and re-arms the hint.
		$this->filter_map['wppo_combine_preload_fetchpriority'] = 'low';
		$this->make_styles( array( 'a' => $a ) );
		$cache_two = $this->make_cache( array(), $this->make_filesystem() );
		$cache_two->combine_css();

		ob_start();
		$cache_two->maybe_preload_combine_css();
		$tag_low = (string) ob_get_clean();
		$this->assertStringContainsString( 'fetchpriority="low"', $tag_low );

		// Invalid values suppress the attribute instead of emitting garbage.
		$this->filter_map['wppo_combine_preload_fetchpriority'] = 'bogus';
		$this->make_styles( array( 'a' => $a ) );
		$cache_three = $this->make_cache( array(), $this->make_filesystem() );
		$cache_three->combine_css();

		ob_start();
		$cache_three->maybe_preload_combine_css();
		$tag_bogus = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'fetchpriority', $tag_bogus );
	}

	/**
	 * No preload hint is emitted when nothing was combined.
	 *
	 * @return void
	 */
	public function test_maybe_preload_noop_without_combine(): void {
		$cache = $this->make_cache( array(), $this->make_filesystem() );

		ob_start();
		$cache->maybe_preload_combine_css();
		$tag = (string) ob_get_clean();

		$this->assertSame( '', $tag );
	}

	/**
	 * Small block-theme bundles skip the combined file for core inlining.
	 *
	 * @return void
	 */
	public function test_inline_budget_skip_on_small_block_theme(): void {
		$GLOBALS['wp_version'] = '6.9';
		Functions\when( 'wp_is_block_theme' )->justReturn( true );
		$a = $this->write_source( 'a.css', 'body { color: red; }' );
		$this->make_styles( array( 'a' => $a ) );
		$cache = $this->make_cache( array(), $this->make_filesystem() );

		$cache->combine_css();

		$this->assertSame( array(), $this->dequeued );
		$this->assertSame( array(), $this->enqueued );
		$this->assertSame( array(), $this->written );

		// The skip filter restores combining for operators who opt out.
		$this->filter_map['wppo_skip_combine_on_small_block_theme'] = false;
		$this->make_styles( array( 'a' => $a ) );
		$cache_two = $this->make_cache( array(), $this->make_filesystem() );
		$cache_two->combine_css();

		$this->assertNotEmpty( $this->enqueued );
	}

	/**
	 * Inline-budget prediction inlines small path-data styles, not large ones.
	 *
	 * @return void
	 */
	public function test_core_will_inline_branches(): void {
		$small = $this->write_source( 'small.css', 'body { color: red; }' );
		$large = $this->write_source( 'large.css', str_repeat( 'a{}', 9000 ) );
		global $wp_styles;
		$wp_styles             = \Mockery::mock();
		$wp_styles->queue      = array( 'small', 'large' );
		$wp_styles->registered = array(
			'small' => (object) array( 'src' => $small, 'args' => 'all' ),
			'large' => (object) array( 'src' => $large, 'args' => 'all' ),
		);
		$paths                 = array(
			'small' => $this->src_dir . '/small.css',
			'large' => $this->src_dir . '/large.css',
		);
		$wp_styles->shouldReceive( 'get_data' )->andReturnUsing(
			static function ( $handle ) use ( $paths ) {
				return 'path' === func_get_arg( 1 ) ? ( $paths[ $handle ] ?? null ) : null;
			}
		);
		$cache   = $this->make_cache( array(), $this->make_filesystem() );
		$service = new Css_Combine( $cache );

		$this->assertTrue( $service->core_will_inline( 'small' ) );
		$this->assertFalse( $service->core_will_inline( 'large' ) );
	}

	/**
	 * Safe fallback off serves the stale cached file; on regenerates it.
	 *
	 * @return void
	 */
	public function test_safe_fallback_off_serves_stale_cached_file(): void {
		$a = $this->write_source( 'a.css', 'body { color: red; }' );
		$this->make_styles( array( 'a' => $a ) );
		$cache = $this->make_cache( array(), $this->make_filesystem() );

		// Seed a stale (empty) cached file with a matching journal.
		$path = $this->combined_path( $cache );
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test-only fixture tree.
			mkdir( $dir, 0777, true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only fixture.
		file_put_contents( $path, '' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only fixture.
		file_put_contents( $path . '.handles', '["a"]' );

		$this->filter_map['wppo_safe_css_combine_fallback'] = false;
		$cache->combine_css();

		$this->assertCount( 1, $this->enqueued );
		$this->assertSame( array(), $this->written );

		// Fallback on: the empty file is invalid, so the pipeline regenerates.
		$this->filter_map['wppo_safe_css_combine_fallback'] = true;
		$this->enqueued = array();
		$this->written  = array();
		$this->make_styles( array( 'a' => $a ) );
		$cache_two = $this->make_cache( array(), $this->make_filesystem() );
		$cache_two->combine_css();

		$this->assertArrayHasKey( $path, $this->written );
		$this->assertStringContainsString( 'color:red', $this->written[ $path ] );
		$this->assertCount( 1, $this->enqueued );
	}

	/**
	 * Staged combineCSS=off disables combining in preview only.
	 *
	 * @return void
	 */
	public function test_sandbox_staged_combine_off_disables_preview(): void {
		Sandbox_Preview::reset_memo();
		$_GET['wppo_preview']        = 'assets';
		$_GET['_wppo_preview_nonce'] = 'valid-nonce';
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				if ( 'wppo_settings' === $name ) {
					return array(
						'file_optimisation' => array(
							'combineCSS'     => true,
							'sandboxStaged' => array( 'combineCSS' => false ),
						),
					);
				}
				return array();
			}
		);

		$a = $this->write_source( 'a.css', 'body { color: red; }' );
		$this->make_styles( array( 'a' => $a ) );
		$cache = $this->make_cache( array( 'combineCSS' => true ), $this->make_filesystem() );
		$cache->combine_css();

		$this->assertSame( array(), $this->enqueued );
		$this->assertSame( array(), $this->written );
	}

	/**
	 * Preview combining writes the isolated preview variant, never production.
	 *
	 * @return void
	 */
	public function test_sandbox_preview_writes_isolated_variant(): void {
		Sandbox_Preview::reset_memo();
		$_GET['wppo_preview']        = 'assets';
		$_GET['_wppo_preview_nonce'] = 'valid-nonce';
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

		$a = $this->write_source( 'a.css', 'body { color: red; }' );
		$this->make_styles( array( 'a' => $a ) );
		$cache = $this->make_cache( array( 'combineCSS' => true ), $this->make_filesystem() );
		$cache->combine_css();

		$preview_seen = false;
		foreach ( $this->stat_paths as $checked ) {
			if ( false !== strpos( $checked, '-preview' ) ) {
				$preview_seen = true;
				break;
			}
		}
		$this->assertTrue( $preview_seen, 'Expected a preview-variant cache path to be probed.' );
		$production = $this->combined_path( $cache );
		$this->assertArrayNotHasKey( $production, $this->written );
		$this->assertCount( 1, $this->enqueued );
	}

	/**
	 * Elementor-safe mode skips combining on builder-built pages.
	 *
	 * @return void
	 */
	public function test_elementor_bypass_skips_combine(): void {
		Main::reset_elementor_memo();
		$_GET['elementor-preview'] = '1';
		$this->has_filters[]       = 'wppo_is_elementor_page';
		$this->filter_map['wppo_is_elementor_page'] = true;

		$a = $this->write_source( 'a.css', 'body { color: red; }' );
		$this->make_styles( array( 'a' => $a ) );
		$cache = $this->make_cache( array(), $this->make_filesystem() );
		$cache->combine_css();

		$this->assertSame( array(), $this->enqueued );
		$this->assertSame( array(), $this->written );

		// Explicit elementorSafeMode=off restores combining on the same page.
		Main::reset_elementor_memo();
		$this->filter_map   = array();
		$this->has_filters  = array();
		$this->make_styles( array( 'a' => $a ) );
		$cache_two = $this->make_cache( array( 'elementorSafeMode' => false ), $this->make_filesystem() );
		$cache_two->combine_css();

		$this->assertCount( 1, $this->enqueued );
	}

	/**
	 * Combined paths are isolated per domain (multisite cache-dir isolation).
	 *
	 * @return void
	 */
	public function test_multisite_domain_isolation(): void {
		$fs     = $this->make_filesystem();
		$cache_a = $this->make_cache( array(), $fs, 'site-one.example.com' );
		$cache_b = $this->make_cache( array(), $fs, 'site-two.example.com' );

		$path_a = $this->invoke_private( $cache_a, 'combine_cache_file_path', array( '' ) );
		$path_b = $this->invoke_private( $cache_b, 'combine_cache_file_path', array( '' ) );

		$this->assertNotSame( $path_a, $path_b );
		$this->assertStringContainsString( 'site-one.example.com', $path_a );
		$this->assertStringContainsString( 'site-two.example.com', $path_b );
	}
}
