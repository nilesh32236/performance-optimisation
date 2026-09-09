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
	 * Test that the safe fallback is enabled by default.
	 */
	public function test_safe_fallback_enabled_by_default(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->assertTrue( $this->invoke_private( Cache::class, 'is_safe_css_combine_fallback_enabled', array() ) );
		$this->assertTrue( $this->invoke_private( Used_CSS::class, 'is_safe_fallback_enabled', array() ) );
	}
}
