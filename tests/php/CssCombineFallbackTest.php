<?php
/**
 * Tests for the safe CSS combine / used-CSS fallback guards.
 *
 * Covers the fail-open paths: stale/empty cached files are invalid,
 * inject_used_css() returns the pristine buffer on no-match or missing
 * head, and the success path injects the used-CSS link.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\Used_CSS;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Tests for safe CSS combine fallback guards.
 *
 * @package PerformanceOptimise\Tests
 */
class CssCombineFallbackTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Invoke a private method on a class without calling its constructor.
	 *
	 * @param string $class_name  Class name.
	 * @param string $method Method name.
	 * @param array  $args   Method arguments (instance is prepended internally).
	 * @return mixed Method result.
	 */
	private function invoke_private( string $class_name, string $method, array $args = array() ) {
		$instance   = ( new \ReflectionClass( $class_name ) )->newInstanceWithoutConstructor();
		$reflection = new \ReflectionMethod( $class_name, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( $instance, $args );
	}

	/**
	 * Build a Used_CSS instance without constructor, with stubbed options.
	 *
	 * @return Used_CSS
	 */
	private function make_used_css(): Used_CSS {
		$instance = ( new \ReflectionClass( Used_CSS::class ) )->newInstanceWithoutConstructor();
		$prop     = new \ReflectionProperty( Used_CSS::class, 'options' );
		$prop->setAccessible( true );
		$prop->setValue( $instance, array( 'file_optimisation' => array() ) );
		return $instance;
	}

	/**
	 * Test that a non-empty readable file is valid combined CSS.
	 */
	public function test_is_combined_css_valid_true_for_non_empty_file(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$file = tempnam( sys_get_temp_dir(), 'wppo-combine-valid-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $file, 'body { color: red; }' );

		$this->assertTrue( $this->invoke_private( Cache::class, 'is_combined_css_valid', array( $file ) ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $file );
	}

	/**
	 * Test that missing, empty, and blank paths are invalid combined CSS.
	 */
	public function test_is_combined_css_valid_false_for_missing_and_empty(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$empty = tempnam( sys_get_temp_dir(), 'wppo-combine-empty-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $empty, '' );

		$this->assertFalse( $this->invoke_private( Cache::class, 'is_combined_css_valid', array( $empty ) ) );
		$this->assertFalse( $this->invoke_private( Cache::class, 'is_combined_css_valid', array( '/no/such/wppo-file.css' ) ) );
		$this->assertFalse( $this->invoke_private( Cache::class, 'is_combined_css_valid', array( '' ) ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $empty );
	}

	/**
	 * Test that an overwritten-then-truncated file is detected (stat cache cleared).
	 */
	public function test_is_combined_css_valid_detects_truncated_overwrite(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$file = tempnam( sys_get_temp_dir(), 'wppo-combine-trunc-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $file, 'body { color: red; }' );
		$this->assertTrue( $this->invoke_private( Cache::class, 'is_combined_css_valid', array( $file ) ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $file, '' );
		$this->assertFalse( $this->invoke_private( Cache::class, 'is_combined_css_valid', array( $file ) ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $file );
	}

	/**
	 * Test that a non-empty readable file is valid used CSS.
	 */
	public function test_is_used_css_valid_true_for_non_empty_file(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$file = tempnam( sys_get_temp_dir(), 'wppo-used-valid-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $file, '.a { color: red; }' );

		$this->assertTrue( $this->invoke_private( Used_CSS::class, 'is_used_css_valid', array( $file ) ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $file );
	}

	/**
	 * Test that missing and empty files are invalid used CSS.
	 */
	public function test_is_used_css_valid_false_for_missing_and_empty(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$empty = tempnam( sys_get_temp_dir(), 'wppo-used-empty-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $empty, '' );

		$this->assertFalse( $this->invoke_private( Used_CSS::class, 'is_used_css_valid', array( $empty ) ) );
		$this->assertFalse( $this->invoke_private( Used_CSS::class, 'is_used_css_valid', array( '/no/such/wppo-used.css' ) ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $empty );
	}

	/**
	 * Set up $wp_styles + WP stubs for inject_used_css() tests.
	 *
	 * @param string $src Stylesheet src URL registered under handle 'theme'.
	 */
	private function stub_wp_styles_for_inject( string $src ): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		// Pretend the fallback-log throttle is armed so Log::add() (which
		// needs $wpdb->insert) is never reached; these tests assert the
		// fail-open buffer behavior, not the logging side effect.
		Functions\when( 'get_transient' )->justReturn( true );
		Functions\when( 'set_transient' )->justReturn( true );

		global $wp_styles;
		$wp_styles             = \Mockery::mock();
		$wp_styles->registered = array(
			'theme' => (object) array(
				'src'  => $src,
				'args' => 'all',
			),
		);
	}

	/**
	 * Test that inject_used_css() returns the pristine buffer when no link matches.
	 */
	public function test_inject_used_css_returns_originals_on_no_match(): void {
		$src = 'http://example.com/wp-content/themes/t/style.css';
		$this->stub_wp_styles_for_inject( $src );

		$buffer   = '<html><head><link rel="stylesheet" href="http://example.com/other.css" media="all"></head><body></body></html>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
		$instance = $this->make_used_css();

		$method = new \ReflectionMethod( Used_CSS::class, 'inject_used_css' );
		$method->setAccessible( true );
		$result = $method->invoke( $instance, $buffer, 'http://example.com/used-css.css?ver=1', array( 'theme' ) );

		$this->assertSame( $buffer, $result );
	}

	/**
	 * Test that inject_used_css() returns the pristine buffer when no head is present.
	 */
	public function test_inject_used_css_returns_originals_on_missing_head(): void {
		$src = 'http://example.com/wp-content/themes/t/style.css';
		$this->stub_wp_styles_for_inject( $src );

		$buffer   = '<html><link rel="stylesheet" href="' . $src . '" media="all"><body></body></html>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
		$instance = $this->make_used_css();

		$method = new \ReflectionMethod( Used_CSS::class, 'inject_used_css' );
		$method->setAccessible( true );
		$result = $method->invoke( $instance, $buffer, 'http://example.com/used-css.css?ver=1', array( 'theme' ) );

		$this->assertSame( $buffer, $result );
	}

	/**
	 * Test that inject_used_css() strips the original and injects the used-CSS link.
	 */
	public function test_inject_used_css_injects_on_match(): void {
		$src = 'http://example.com/wp-content/themes/t/style.css';
		$this->stub_wp_styles_for_inject( $src );

		$buffer   = '<html><head><link rel="stylesheet" href="' . $src . '" media="all"></head><body></body></html>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
		$instance = $this->make_used_css();

		$method = new \ReflectionMethod( Used_CSS::class, 'inject_used_css' );
		$method->setAccessible( true );
		$result = $method->invoke( $instance, $buffer, 'http://example.com/used-css.css?ver=1', array( 'theme' ) );

		$this->assertStringContainsString( 'wppo-used-css', $result );
		// The original URL survives only once — inside the <noscript>
		// fallback — while the original <link> tag itself is stripped.
		$this->assertSame( 1, substr_count( $result, $src ) );
		$this->assertStringNotContainsString( '<link rel="stylesheet" href="' . $src . '" media="all"></head>', $result ); // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
	}

	/**
	 * Invoke inject_used_css() via reflection with the standard stubs.
	 *
	 * @param string $src    Registered stylesheet src.
	 * @param string $buffer HTML buffer.
	 * @return string Result buffer.
	 */
	private function invoke_inject_used_css( string $src, string $buffer ): string {
		$this->stub_wp_styles_for_inject( $src );
		$instance = $this->make_used_css();

		$method = new \ReflectionMethod( Used_CSS::class, 'inject_used_css' );
		$method->setAccessible( true );
		return $method->invoke( $instance, $buffer, 'http://example.com/used-css.css?ver=1', array( 'theme' ) );
	}

	/**
	 * Test that inject_used_css() strips an href-first link tag.
	 */
	public function test_inject_used_css_strips_href_first_link(): void {
		$src = 'http://example.com/wp-content/themes/t/style.css';

		$buffer = '<html><head><link href="' . $src . '" rel="stylesheet" media="all"></head><body></body></html>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
		$result = $this->invoke_inject_used_css( $src, $buffer );

		$this->assertStringContainsString( 'wppo-used-css', $result );
		// The original URL survives only once — inside the <noscript>
		// fallback — while the original <link> tag itself is stripped.
		$this->assertSame( 1, substr_count( $result, $src ) );
	}

	/**
	 * Test that inject_used_css() strips a mixed href-first + rel-first bundle.
	 */
	public function test_inject_used_css_strips_mixed_bundle(): void {
		$src = 'http://example.com/wp-content/themes/t/style.css';

		$buffer  = '<html><head>';
		$buffer .= '<link href="' . $src . '" rel="stylesheet" media="all">'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
		$buffer .= '<link rel="stylesheet" href="' . $src . '?ver=6.2" media="all">'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
		$buffer .= '</head><body></body></html>';
		$result  = $this->invoke_inject_used_css( $src, $buffer );

		$this->assertStringContainsString( 'wppo-used-css', $result );
		// Both original tags stripped; the src survives only in the
		// <noscript> fallback plus the versioned href text is gone.
		$this->assertStringNotContainsString( $src . '?ver=6.2', $result );
		$this->assertSame( 1, substr_count( $result, $src ) );
	}

	/**
	 * Test that inject_used_css() strips a versioned (query-string) href.
	 */
	public function test_inject_used_css_strips_query_string_href(): void {
		$src = 'http://example.com/wp-content/themes/t/style.css';

		$buffer = '<html><head><link rel="stylesheet" href="' . $src . '?ver=6.2" media="all"></head><body></body></html>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
		$result = $this->invoke_inject_used_css( $src, $buffer );

		$this->assertStringContainsString( 'wppo-used-css', $result );
		$this->assertStringNotContainsString( $src . '?ver=6.2', $result );
	}

	/**
	 * Test that preload/preconnect hints with the same URL are preserved.
	 */
	public function test_inject_used_css_preserves_preload_hints(): void {
		$src = 'http://example.com/wp-content/themes/t/style.css';

		$buffer  = '<html><head>';
		$buffer .= '<link rel="preload" href="' . $src . '" as="style">'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
		$buffer .= '<link rel="stylesheet" href="' . $src . '" media="all">'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
		$buffer .= '</head><body></body></html>';
		$result  = $this->invoke_inject_used_css( $src, $buffer );

		$this->assertStringContainsString( 'wppo-used-css', $result );
		$this->assertStringContainsString( 'rel="preload"', $result );
		// The live same-URL stylesheet tag is stripped (the <noscript>
		// fallback still carries the URL; strip it before asserting the
		// live markup). The injected wppo-used-css link itself carries
		// rel="stylesheet", so assert on the original href instead.
		$without_noscript = preg_replace( '#<noscript>.*?</noscript>#s', '', $result );
		$this->assertIsString( $without_noscript );
		$this->assertStringNotContainsString( '<link rel="stylesheet" href="' . $src . '"', $without_noscript ); // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
	}

	/**
	 * Test that href-first tags with whitespace around `=` and a multi-token
	 * rel (e.g. `rel="alternate stylesheet"`) are still stripped.
	 *
	 * @since NEXT
	 */
	public function test_inject_used_css_strips_whitespace_and_multi_token_rel(): void {
		$src = 'http://example.com/wp-content/themes/t/style.css';

		$buffer = '<html><head><link href = "' . $src . '" rel = "alternate stylesheet" media="all"></head><body></body></html>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
		$result = $this->invoke_inject_used_css( $src, $buffer );

		$this->assertStringContainsString( 'wppo-used-css', $result );
		// Original tag stripped; src survives once in the <noscript> fallback.
		$this->assertSame( 1, substr_count( $result, $src ) );
		$this->assertStringNotContainsString( 'alternate stylesheet', $result );
	}

	/**
	 * Test that rel-first tags with whitespace around `=` and a trailing rel
	 * token are still stripped.
	 *
	 * @since NEXT
	 */
	public function test_inject_used_css_strips_rel_first_whitespace_and_extra_token(): void {
		$src = 'http://example.com/wp-content/themes/t/style.css';

		$buffer = '<html><head><link rel = "stylesheet alternate" href = "' . $src . '" media="all"></head><body></body></html>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
		$result = $this->invoke_inject_used_css( $src, $buffer );

		$this->assertStringContainsString( 'wppo-used-css', $result );
		$this->assertSame( 1, substr_count( $result, $src ) );
		$this->assertStringNotContainsString( 'stylesheet alternate', $result );
	}

	/**
	 * Test that a same-URL hint whose rel never contains the `stylesheet` token
	 * (e.g. `rel="preconnect"`) survives stripping.
	 *
	 * @since NEXT
	 */
	public function test_inject_used_css_preserves_preconnect_without_stylesheet_token(): void {
		$src = 'http://example.com/wp-content/themes/t/style.css';

		$buffer  = '<html><head>';
		$buffer .= '<link rel="preconnect" href="' . $src . '" crossorigin>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
		$buffer .= '<link rel="stylesheet" href="' . $src . '" media="all">'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
		$buffer .= '</head><body></body></html>';
		$result  = $this->invoke_inject_used_css( $src, $buffer );

		$this->assertStringContainsString( 'wppo-used-css', $result );
		$this->assertStringContainsString( 'rel="preconnect"', $result );
		// The stylesheet tag itself is gone (the <noscript> fallback still
		// carries the URL; strip it before asserting the live markup).
		$without_noscript = preg_replace( '#<noscript>.*?</noscript>#s', '', $result );
		$this->assertIsString( $without_noscript );
		$this->assertStringNotContainsString( '<link rel="stylesheet" href="' . $src . '" media="all">', $without_noscript ); // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
	}

	/**
	 * Test that the safe fallback is enabled by default.
	 */
	public function test_safe_fallback_enabled_by_default(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->assertTrue( $this->invoke_private( Cache::class, 'is_safe_css_combine_fallback_enabled', array() ) );
		$this->assertTrue( $this->invoke_private( Used_CSS::class, 'is_safe_fallback_enabled', array() ) );
	}

	/**
	 * Set up the environment for combine_css() guard-path tests.
	 *
	 * Configures a Cache instance via reflection (no constructor), a mocked
	 * filesystem (existing combine file never present, get_contents() driven
	 * by $get_contents), and the WP function stubs combine_css() needs on the
	 * fresh-generation branch. Callers assert $dequeued / $enqueued stay empty
	 * to prove the guard failed open without stripping originals.
	 *
	 * @param callable $get_contents Invoked with the stylesheet path; return raw CSS or false.
	 * @param array    $dequeued     Out param, records wp_dequeue_style() calls.
	 * @param array    $enqueued     Out param, records wp_enqueue_style() calls.
	 * @return Cache
	 */
	private function stub_combine_guard_environment( callable $get_contents, array &$dequeued, array &$enqueued ): Cache {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_is_mobile' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'wp_should_load_separate_core_block_assets' )->justReturn( false );
		Functions\when( 'is_multisite' )->justReturn( false );
		// LiteSpeed_Integration::should_disable_wppo_optimizer() reads plugin
		// lists before returning false when LSCWP is absent.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_site_option' )->justReturn( array() );
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'site_url' )->justReturn( 'http://example.com/' );
		Functions\when( 'content_url' )->justReturn( 'http://example.com/wp-content' );
		// Arm the fallback-log throttle so Util::log_css_fallback() never
		// reaches Log::add() (which needs $wpdb) — these tests assert the
		// fail-open behavior, not the logging side effect.
		Functions\when( 'get_transient' )->justReturn( true );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_dequeue_style' )->alias(
			static function ( string $handle ) use ( &$dequeued ): void {
				$dequeued[] = $handle;
			}
		);
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( ...$args ) use ( &$enqueued ): void {
				$enqueued[] = $args;
			}
		);

		global $wp_styles, $wp_filesystem;
		$wp_styles = \Mockery::mock();
		$wp_styles->shouldReceive( 'get_data' )->andReturn( false );
		$wp_styles->queue      = array( 'theme' );
		$wp_styles->registered = array(
			'theme' => (object) array(
				'src'  => 'http://example.com/wp-content/themes/t/style.css',
				'args' => 'all',
			),
		);

		$fs = \Mockery::mock();
		$fs->shouldReceive( 'exists' )->andReturn( false );
		$fs->shouldReceive( 'get_contents' )->andReturnUsing( $get_contents );

		$instance       = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();
		$cache_root_dir = new \ReflectionProperty( Cache::class, 'cache_root_dir' );
		$cache_root_dir->setAccessible( true );
		$cache_root_dir->setValue( $instance, '/tmp/wordpress/wp-content/cache/wppo' );
		$domain = new \ReflectionProperty( Cache::class, 'domain' );
		$domain->setAccessible( true );
		$domain->setValue( $instance, 'example.com' );
		$options = new \ReflectionProperty( Cache::class, 'options' );
		$options->setAccessible( true );
		$options->setValue(
			$instance,
			array(
				'file_optimisation' => array(),
				'cache_settings'    => array(),
			)
		);
		$filesystem = new \ReflectionProperty( Cache::class, 'filesystem' );
		$filesystem->setAccessible( true );
		$filesystem->setValue( $instance, $fs );
		$initialized = new \ReflectionProperty( Cache::class, 'fs_initialized' );
		$initialized->setAccessible( true );
		$initialized->setValue( $instance, true );
		$request_uri = new \ReflectionProperty( Cache::class, 'request_uri' );
		$request_uri->setAccessible( true );
		$request_uri->setValue( $instance, '/' );
		$url_path = new \ReflectionProperty( Cache::class, 'url_path' );
		$url_path->setAccessible( true );
		$url_path->setValue( $instance, '' );

		// Util::prepare_cache_dir() uses the global filesystem, not the
		// memoized instance one.
		$wp_filesystem = \Mockery::mock();
		$wp_filesystem->shouldReceive( 'is_dir' )->andReturn( false );
		$wp_filesystem->shouldReceive( 'mkdir' )->andReturn( false );
		Functions\when( 'WP_Filesystem' )->justReturn( true );

		// Force the pre-6.9 combine path so should_skip_combine_for_inline_budget()
		// short-circuits before the block-theme budget logic.
		$GLOBALS['wp_version'] = '6.8.0';

		return $instance;
	}

	/**
	 * Test that combine_css() fails open (no dequeue, no combined enqueue)
	 * when every stylesheet fetch fails and the payload is empty.
	 */
	public function test_combine_css_fails_open_on_empty_payload(): void {
		$dequeued = array();
		$enqueued = array();
		$instance = $this->stub_combine_guard_environment(
			static function () {
				return false;
			},
			$dequeued,
			$enqueued
		);

		$instance->combine_css();

		$this->assertSame( array(), $dequeued );
		$this->assertSame( array(), $enqueued );
	}

	/**
	 * Test that combine_css() fails open when the payload minifies away to nothing.
	 *
	 * Runs in a separate process: reaching the minify stage loads the real
	 * PerformanceOptimise\Inc\Minify\CSS class, which would break
	 * InlineCssTest's `overload:` mock of the same class in this process.
	 */
	#[RunInSeparateProcess]
	public function test_combine_css_fails_open_when_payload_minifies_to_empty(): void {
		$dequeued = array();
		$enqueued = array();
		$instance = $this->stub_combine_guard_environment(
			static function () {
				// A comment-only payload: non-empty before minify, empty after.
				return '/* stripped */';
			},
			$dequeued,
			$enqueued
		);

		$instance->combine_css();

		$this->assertSame( array(), $dequeued );
		$this->assertSame( array(), $enqueued );
	}

	/**
	 * Test that combine_css() fails open when the cache directory cannot be prepared.
	 *
	 * Runs in a separate process (see the minify-stage test above).
	 */
	#[RunInSeparateProcess]
	public function test_combine_css_fails_open_when_cache_dir_unpreparable(): void {
		$dequeued = array();
		$enqueued = array();
		$instance = $this->stub_combine_guard_environment(
			static function () {
				return 'body { color: red; }';
			},
			$dequeued,
			$enqueued
		);

		$instance->combine_css();

		$this->assertSame( array(), $dequeued );
		$this->assertSame( array(), $enqueued );
	}
}
