<?php
/**
 * Tests for minify placeholder randomization + path-guard hardening (issue #1344).
 *
 * Pins the residual gaps left by issues #992 (randomized placeholder
 * namespaces + strict restore) and #1179 (realpath() containment for
 * minify/combine reads):
 *
 * - Two consecutive minify runs mint different placeholder namespaces while
 *   producing identical output HTML after restoration.
 * - A mid-minify failure still restores preserved scripts (fail-open to
 *   unoptimised HTML, never leaked tokens).
 * - Out-of-content-dir / traversal stylesheet paths are skipped by
 *   Main::resolve_local_stylesheet_path() and Main::is_file_minified(), and
 *   symlink escapes are refused by Util::get_local_path().
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */

use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Minify\HTML;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Minify HTML hardening tests for issue #1344.
 *
 * @since NEXT
 */
class MinifyHtmlHardening1344Test extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Stylesheet fixture inside WP_CONTENT_DIR.
	 *
	 * @var string
	 */
	private $fixture_css = '';

	/**
	 * Outside-root secret file a symlink points at.
	 *
	 * @var string
	 */
	private $outside_file = '';

	/**
	 * Scratch dir holding the outside-root secret (unique per test run).
	 *
	 * @var string
	 */
	private $outside_dir = '';

	/**
	 * Symlink inside WP_CONTENT_DIR pointing outside the allowed roots.
	 *
	 * @var string
	 */
	private $escape_link = '';

	/**
	 * Set up fixtures and common stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		Util::reset_runtime_caches();
		Functions\when( 'untrailingslashit' )->returnArg();
		Functions\when( 'site_url' )->justReturn( 'http://example.com' );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'has_filter' )->justReturn( false );

		$content_dir = defined( 'WP_CONTENT_DIR' ) && is_string( WP_CONTENT_DIR ) && '' !== WP_CONTENT_DIR ? rtrim( WP_CONTENT_DIR, '/' ) : '/tmp/wordpress/wp-content';
		if ( ! is_dir( $content_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixtures use native filesystem.
			mkdir( $content_dir, 0755, true );
		}

		$this->fixture_css = $content_dir . '/wppo-1344-fixture.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $this->fixture_css, ".wppo-1344 {\n\tcolor: red;\n}\n" );

		// Unique per-run scratch dir under the system temp dir: parallel CI
		// workers never collide, and the location never diverges from the
		// WP_CONTENT_DIR constant above.
		$this->outside_dir = sys_get_temp_dir() . '/wppo-1344-outside-' . uniqid();
		if ( ! is_dir( $this->outside_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixtures use native filesystem.
			mkdir( $this->outside_dir, 0755, true );
		}
		$this->outside_file = $this->outside_dir . '/secret-1344.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $this->outside_file, ".secret-1344 { color: black; }\n" );

		$this->escape_link = $content_dir . '/wppo-1344-escape.css';
		if ( file_exists( $this->escape_link ) || is_link( $this->escape_link ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			unlink( $this->escape_link );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_symlink -- Test fixture.
		$linked = @symlink( $this->outside_file, $this->escape_link );
		if ( ! $linked || ! is_link( $this->escape_link ) ) {
			$this->markTestSkipped( 'Symlink creation not supported in this environment.' );
		}
	}

	/**
	 * Remove fixtures.
	 */
	protected function tearDown(): void {
		foreach ( array( $this->fixture_css, $this->escape_link, $this->outside_file ) as $file ) {
			if ( is_string( $file ) && '' !== $file && ( file_exists( $file ) || is_link( $file ) ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
				unlink( $file );
			}
		}
		if ( is_string( $this->outside_dir ) && '' !== $this->outside_dir && is_dir( $this->outside_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
			rmdir( $this->outside_dir );
		}
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Read a private property off an instance.
	 *
	 * @param object $instance Instance.
	 * @param string $prop     Property name.
	 * @return mixed
	 */
	private function read_prop( $instance, string $prop ): mixed {
		$reflection = new \ReflectionProperty( $instance, $prop );
		return $reflection->getValue( $instance );
	}

	/**
	 * Invoke a private method on an instance.
	 *
	 * @param object $instance Instance.
	 * @param string $name     Method name.
	 * @param mixed  ...$args Args.
	 * @return mixed
	 */
	private function invoke_private( $instance, string $name, ...$args ): mixed {
		$reflection = new \ReflectionMethod( $instance, $name );
		return $reflection->invoke( $instance, ...$args );
	}

	/**
	 * Build a Main instance without running the heavy constructor.
	 *
	 * @return Main
	 */
	private function make_main(): Main {
		return ( new \ReflectionClass( Main::class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * Consecutive runs mint different namespaces but identical output HTML.
	 */
	public function test_consecutive_runs_differ_in_namespace_but_match_in_output(): void {
		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for placeholder restore tests.
		$original = '<script type="text/x-custom">var wppo_1344 = 1;</script>';
		$html     = '<html><head></head><body>' . $original . '</body></html>';
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript

		$a = new HTML( $html, array( 'file_optimisation' => array() ) );
		$b = new HTML( $html, array( 'file_optimisation' => array() ) );

		$this->assertNotSame( $this->read_prop( $a, 'preserve_namespace' ), $this->read_prop( $b, 'preserve_namespace' ) );
		$this->assertSame( $a->get_minified_html(), $b->get_minified_html() );
		$this->assertStringContainsString( $original, $a->get_minified_html() );
		$this->assertStringNotContainsString( 'data-wppo-preserve', $a->get_minified_html() );
	}

	/**
	 * Restore applied to a placeholder-carrying buffer recovers every script.
	 *
	 * Simulates the post-failure buffer shape: the pre-minify HTML (which
	 * still carries namespaced placeholders) must restore to the original
	 * scripts even when the minify engine never ran.
	 */
	public function test_failed_minify_buffer_still_restores_placeholders(): void {
		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for placeholder restore tests.
		$first  = '<script type="text/x-custom">var wppo_first_1344 = 1;</script>';
		$second = '<script type="text/x-custom">var wppo_second_1344 = 2;</script>';
		$html   = '<html><head></head><body>' . $first . $second . '</body></html>';
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript

		$instance = new HTML( '<html><head></head><body><p>hi</p></body></html>', array( 'file_optimisation' => array() ) );

		$extracted = $this->invoke_private( $instance, 'extract_and_preserve_scripts_template', $html );
		$this->assertCount( 2, $extracted[1] );

		// Engine "failed": buffer passes through untouched, then restore runs.
		$restored = $this->invoke_private( $instance, 'restore_preserved_scripts_template', $extracted[0], $extracted[1] );

		$this->assertStringContainsString( $first, $restored );
		$this->assertStringContainsString( $second, $restored );
		$this->assertStringNotContainsString( 'data-wppo-preserve', $restored );
	}

	/**
	 * End-to-end minify_html() pipeline restores every preserved script.
	 *
	 * Unlike the buffer-shape test above, this exercises the claimed
	 * $pre_minify fallback assignment plus unconditional restore inside
	 * minify_html(): a regression moving restore back inside the guarded
	 * try (or dropping the fallback) fails here.
	 */
	public function test_minify_html_pipeline_restores_all_scripts_without_tokens(): void {
		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for placeholder restore tests.
		$first  = '<script type="text/x-custom">var wppo_pipe_first_1344 = 1;</script>';
		$second = '<script type="text/x-custom">var wppo_pipe_second_1344 = 2;</script>';
		$html   = '<html><head><link rel="canonical" href="http://example.com/page-1344/"></head><body>' . $first . $second . '</body></html>';
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript

		$instance = new HTML( '<html><head></head><body><p>hi</p></body></html>', array( 'file_optimisation' => array() ) );

		$out = $this->invoke_private( $instance, 'minify_html', $html );

		$this->assertStringContainsString( $first, $out );
		$this->assertStringContainsString( $second, $out );
		$this->assertStringNotContainsString( 'data-wppo-preserve', $out );
		$this->assertStringNotContainsString( 'wppo-href', $out );
		$this->assertStringContainsString( 'href="http://example.com/page-1344/"', $out );
	}

	/**
	 * Stylesheet resolver accepts a legitimate in-root file by realpath.
	 */
	public function test_resolve_local_stylesheet_accepts_legitimate(): void {
		$main = $this->make_main();

		$resolved = $this->invoke_private( $main, 'resolve_local_stylesheet_path', 'http://example.com/wp-content/wppo-1344-fixture.css' );

		$this->assertSame( realpath( $this->fixture_css ), $resolved );
	}

	/**
	 * Stylesheet resolver skips traversal, NUL, and out-of-root paths.
	 */
	public function test_resolve_local_stylesheet_skips_confined_out_paths(): void {
		$main = $this->make_main();

		$this->assertSame( '', $this->invoke_private( $main, 'resolve_local_stylesheet_path', 'http://example.com/wp-content/../wp-config.css' ) );
		$this->assertSame( '', $this->invoke_private( $main, 'resolve_local_stylesheet_path', "http://example.com/wp-content/a\0b.css" ) );
		$this->assertSame( '', $this->invoke_private( $main, 'resolve_local_stylesheet_path', 'http://example.com/etc/passwd.css' ) );
		$this->assertSame( '', $this->invoke_private( $main, 'resolve_local_stylesheet_path', 'http://example.com/wp-content/does-not-exist-1344.css' ) );
	}

	/**
	 * Stylesheet resolver refuses a symlink escaping the content dir.
	 */
	public function test_resolve_local_stylesheet_rejects_symlink_escape(): void {
		$main = $this->make_main();

		if ( ! is_link( $this->escape_link ) ) {
			$this->markTestSkipped( 'Symlink creation not supported in this environment.' );
		}
		$this->assertSame( '', $this->invoke_private( $main, 'resolve_local_stylesheet_path', 'http://example.com/wp-content/wppo-1344-escape.css' ) );
	}

	/**
	 * Main::is_file_minified() refuses symlink escapes and outside-root paths.
	 *
	 * Non-symlink hostile asserts run unconditionally so coverage survives
	 * on filesystems without symlink support; only the escape-link assert
	 * is symlink-gated.
	 */
	public function test_is_file_minified_rejects_escape_and_outside(): void {
		$main = $this->make_main();

		$content_dir = defined( 'WP_CONTENT_DIR' ) && is_string( WP_CONTENT_DIR ) && '' !== WP_CONTENT_DIR ? rtrim( WP_CONTENT_DIR, '/' ) : '/tmp/wordpress/wp-content';
		// Fail-open verdict (true = "already minified, skip") for hostile input.
		$this->assertTrue( $this->invoke_private( $main, 'is_file_minified', '/etc/hosts', 'css' ) );
		$this->assertTrue( $this->invoke_private( $main, 'is_file_minified', $content_dir . "/a\0b.css", 'css' ) );
		$this->assertTrue( $this->invoke_private( $main, 'is_file_minified', 'php://filter/convert.base64-encode/resource=' . $content_dir . '/x.css', 'css' ) );
		$this->assertTrue( $this->invoke_private( $main, 'is_file_minified', $this->outside_file, 'css' ) );
		if ( ! is_link( $this->escape_link ) ) {
			$this->markTestSkipped( 'Symlink creation not supported in this environment.' );
		}
		$this->assertTrue( $this->invoke_private( $main, 'is_file_minified', $this->escape_link, 'css' ) );
	}

	/**
	 * Main::is_file_minified() still classifies a legitimate non-minified file.
	 */
	public function test_is_file_minified_accepts_legitimate(): void {
		$main = $this->make_main();

		$this->assertFalse( $this->invoke_private( $main, 'is_file_minified', $this->fixture_css, 'css' ) );
	}

	/**
	 * Util::get_local_path() refuses a symlink escaping ABSPATH but keeps benign mapping.
	 *
	 * The benign-mapping assert runs unconditionally so non-symlink
	 * filesystems still pin coverage; only the escape-link assert is
	 * symlink-gated.
	 */
	public function test_get_local_path_rejects_symlink_escape(): void {
		$content_dir = defined( 'WP_CONTENT_DIR' ) && is_string( WP_CONTENT_DIR ) && '' !== WP_CONTENT_DIR ? rtrim( WP_CONTENT_DIR, '/' ) : '/tmp/wordpress/wp-content';
		$this->assertSame(
			$content_dir . '/themes/my-theme/style.css',
			Util::get_local_path( 'http://example.com/wp-content/themes/my-theme/style.css' )
		);
		if ( ! is_link( $this->escape_link ) ) {
			$this->markTestSkipped( 'Symlink creation not supported in this environment.' );
		}
		$this->assertSame( '', Util::get_local_path( 'http://example.com/wp-content/wppo-1344-escape.css' ) );
	}
}
