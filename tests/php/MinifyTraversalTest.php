<?php
/**
 * Tests for minify/combine path traversal hardening (issue #1179).
 *
 * Covers the traversal payload matrix (../, NUL, stream wrappers, .php),
 * symlink-escape rejection via realpath() containment, the
 * `wppo_minify_allowed_roots` filter contract, and byte-identical acceptance
 * of legitimate in-root files through Util::validate_minify_path() and the
 * Minify\CSS / Minify\JS constructors.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Minify\CSS;
use PerformanceOptimise\Inc\Minify\JS;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Minify traversal hardening tests.
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */
class MinifyTraversalTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Fixture CSS file inside WP_CONTENT_DIR.
	 *
	 * @var string
	 */
	private $fixture_css = '';

	/**
	 * Fixture JS file inside WP_CONTENT_DIR.
	 *
	 * @var string
	 */
	private $fixture_js = '';

	/**
	 * Symlink inside WP_CONTENT_DIR pointing outside the allowed roots.
	 *
	 * @var string
	 */
	private $escape_link = '';

	/**
	 * Outside-root secret file the symlink points at.
	 *
	 * @var string
	 */
	private $outside_file = '';

	/**
	 * Set up fixtures and common stubs.
	 *
	 * Note: this setUp() shadows the trait's setUp(), so Brain Monkey and
	 * the common WP function stubs are registered explicitly (same pattern
	 * as CacheTraversalTest).
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		Util::reset_runtime_caches();
		Functions\when( 'untrailingslashit' )->returnArg();
		Functions\when( 'wp_upload_dir' )->alias(
			static function () {
				return array(
					'basedir' => '/tmp/wordpress/wp-content/uploads',
					'baseurl' => 'http://example.com/wp-content/uploads',
				);
			}
		);

		$content_dir = '/tmp/wordpress/wp-content';
		if ( ! is_dir( $content_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixtures use native filesystem.
			mkdir( $content_dir, 0755, true );
		}

		$this->fixture_css = $content_dir . '/wppo-traversal-fixture.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $this->fixture_css, ".wppo-fixture {\n\tcolor: red;\n}\n" );

		$this->fixture_js = $content_dir . '/wppo-traversal-fixture.js';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $this->fixture_js, "var wppoFixture = 1;\n" );

		$outside_dir = '/tmp/wppo-minify-outside';
		if ( ! is_dir( $outside_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixtures use native filesystem.
			mkdir( $outside_dir, 0755, true );
		}
		$this->outside_file = $outside_dir . '/secret.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $this->outside_file, ".secret { color: black; }\n" );

		$this->escape_link = $content_dir . '/wppo-escape-link.css';
		if ( file_exists( $this->escape_link ) || is_link( $this->escape_link ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			unlink( $this->escape_link );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_symlink -- Test fixture.
		symlink( $this->outside_file, $this->escape_link );
	}

	/**
	 * Remove fixtures.
	 */
	protected function tearDown(): void {
		foreach ( array( $this->fixture_css, $this->fixture_js, $this->escape_link, $this->outside_file ) as $file ) {
			if ( is_string( $file ) && '' !== $file && ( file_exists( $file ) || is_link( $file ) ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
				unlink( $file );
			}
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
	private function read_prop( $instance, string $prop ) {
		$reflection = new \ReflectionProperty( $instance, $prop );
		$reflection->setAccessible( true );
		return $reflection->getValue( $instance );
	}

	/**
	 * Default allow-roots contain ABSPATH and WP_CONTENT_DIR.
	 */
	public function test_allowed_roots_defaults(): void {
		Functions\when( 'has_filter' )->justReturn( false );

		$roots = Util::get_minify_allowed_roots();

		$this->assertContains( '/tmp/wordpress/', $roots );
		$this->assertContains( '/tmp/wordpress/wp-content', $roots );
		$this->assertContains( '/tmp/wordpress/wp-content/uploads', $roots );
	}

	/**
	 * The allow-roots filter is honoured when a listener is present.
	 */
	public function test_allowed_roots_filter_honoured(): void {
		Functions\when( 'has_filter' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wppo_minify_allowed_roots' === $hook ) {
					return array( '/srv/shared-assets' );
				}
				return $value;
			}
		);

		$this->assertSame( array( '/srv/shared-assets' ), Util::get_minify_allowed_roots() );
	}

	/**
	 * Invalid filtered roots fall back to the defaults.
	 */
	public function test_allowed_roots_invalid_filter_falls_back(): void {
		Functions\when( 'has_filter' )->justReturn( true );

		foreach ( array( array(), '', null, array( '', 123 ) ) as $bad ) {
			Functions\when( 'apply_filters' )->alias(
				static function ( $hook, $value ) use ( $bad ) {
					if ( 'wppo_minify_allowed_roots' === $hook ) {
						return $bad;
					}
					return $value;
				}
			);

			$roots = Util::get_minify_allowed_roots();
			$this->assertNotEmpty( $roots );
			$this->assertContains( '/tmp/wordpress/wp-content', $roots );
		}
	}

	/**
	 * Hostile payloads are rejected without file bytes.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function hostile_minify_provider(): array {
		return array(
			'empty'               => array( '' ),
			'non-string'          => array( array( 'x' ) ),
			'plain dotdot'        => array( '/tmp/wordpress/wp-content/../wp-config.php' ),
			'nested dotdot'       => array( '/tmp/wordpress/wp-content/foo/../../../etc/passwd' ),
			'nul byte'            => array( "/tmp/wordpress/wp-content/a\0b.css" ),
			'php wrapper'         => array( 'php://filter/convert.base64-encode/resource=/tmp/wordpress/wp-content/x.css' ),
			'file wrapper'        => array( 'file:///etc/passwd' ),
			'expect wrapper'      => array( 'expect://id' ),
			'phar wrapper'        => array( 'phar:///tmp/wordpress/wp-content/x.phar/x.css' ),
			'data uri'            => array( 'data:text/css,body{}' ),
			'php target'          => array( '/tmp/wordpress/wp-config.php' ),
			'php target mixed'    => array( '/tmp/wordpress/wp-content/theme/functions.PHP' ),
			'outside root'        => array( '/etc/hosts' ),
			'nonexistent in-root' => array( '/tmp/wordpress/wp-content/does-not-exist-wppo.css' ),
		);
	}

	/**
	 * The shared gate rejects the hostile matrix.
	 *
	 * @param mixed $payload Hostile input.
	 * @dataProvider hostile_minify_provider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'hostile_minify_provider' )]
	public function test_hostile_matrix_rejected( $payload ): void {
		Functions\when( 'has_filter' )->justReturn( false );

		$this->assertFalse( Util::is_minify_path_allowed( $payload ) );
		$this->assertSame( '', Util::validate_minify_path( $payload ) );
	}

	/**
	 * A symlink inside the content dir pointing outside is rejected after realpath.
	 */
	public function test_symlink_escape_rejected(): void {
		Functions\when( 'has_filter' )->justReturn( false );

		$this->assertTrue( is_link( $this->escape_link ) );
		$this->assertFalse( Util::is_minify_path_allowed( $this->escape_link ) );
		$this->assertSame( '', Util::validate_minify_path( $this->escape_link ) );
	}

	/**
	 * A legitimate in-root file is accepted and resolves to its realpath.
	 */
	public function test_legitimate_file_allowed_byte_identical(): void {
		Functions\when( 'has_filter' )->justReturn( false );

		$expected = realpath( $this->fixture_css );
		$this->assertNotFalse( $expected );
		$this->assertTrue( Util::is_minify_path_allowed( $this->fixture_css ) );
		$this->assertSame( $expected, Util::validate_minify_path( $this->fixture_css ) );

		// Deterministic: repeated validation returns the identical string.
		$this->assertSame( $expected, Util::validate_minify_path( $this->fixture_css ) );
	}

	/**
	 * The Minify constructors fail closed on traversal input.
	 */
	public function test_minify_constructors_reject_traversal(): void {
		Functions\when( 'has_filter' )->justReturn( false );

		$css = new CSS( '/tmp/wordpress/wp-content/../wp-config.php', '/tmp/wordpress/wp-content/cache/wppo/min/css' );
		$this->assertSame( '', $this->read_prop( $css, 'file_path' ) );
		$this->assertSame( '', $css->minify() );

		$js = new JS( 'php://filter/convert.base64-encode/resource=/tmp/wordpress/wp-content/x.js', '/tmp/wordpress/wp-content/cache/wppo/min/js' );
		$this->assertSame( '', $this->read_prop( $js, 'file_path' ) );
		$this->assertSame( '', $js->minify() );
	}

	/**
	 * The Minify constructors keep legitimate in-root files (byte-identical accept).
	 */
	public function test_minify_constructors_accept_legitimate(): void {
		Functions\when( 'has_filter' )->justReturn( false );

		$css = new CSS( $this->fixture_css, '/tmp/wordpress/wp-content/cache/wppo/min/css' );
		$this->assertSame( realpath( $this->fixture_css ), $this->read_prop( $css, 'file_path' ) );

		$js = new JS( $this->fixture_js, '/tmp/wordpress/wp-content/cache/wppo/min/js' );
		$this->assertSame( realpath( $this->fixture_js ), $this->read_prop( $js, 'file_path' ) );
	}

	/**
	 * Wrapper and encoded-traversal payloads are rejected by get_local_path().
	 */
	public function test_get_local_path_rejects_wrapper_matrix(): void {
		$this->assertSame( '', Util::get_local_path( 'php://filter/convert.base64-encode/resource=/etc/passwd' ) );
		$this->assertSame( '', Util::get_local_path( 'file:///etc/passwd' ) );
		$this->assertSame( '', Util::get_local_path( "http://example.com/wp-content/a\0b.css" ) );
		$this->assertSame( '', Util::get_local_path( 'http://example.com/%2e%2e/%2e%2e/etc/passwd' ) );
		$this->assertSame( '', Util::get_local_path( 'http://example.com/..%2f..%2fetc/passwd' ) );
	}

	/**
	 * Legitimate and http(s) absolute URLs are still mapped by get_local_path().
	 */
	public function test_get_local_path_preserves_benign(): void {
		$this->assertSame(
			'/tmp/wordpress/wp-content/themes/my-theme/style.css',
			Util::get_local_path( 'http://example.com/wp-content/themes/my-theme/style.css' )
		);
		$this->assertSame(
			'/tmp/wordpress/wp-content/themes/my-theme/style.css',
			Util::get_local_path( 'https://example.com/wp-content/themes/my-theme/style.css' )
		);
	}
}
