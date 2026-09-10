<?php
/**
 * Tests for AVIF-first picture output with smart quality and skip-small threshold (#931).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Htaccess_Handler;
use PerformanceOptimise\Inc\Image_Optimisation;
use PerformanceOptimise\Inc\Img_Converter;
use PerformanceOptimise\Inc\LiteSpeed_Integration;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * AVIF-first converter, picture, and htaccess tests.
 *
 * @package PerformanceOptimise\Tests
 */
class ImageAvifPictureTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Base options for converter/optimisation instances.
	 *
	 * @var array
	 */
	private array $options;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();

		Util::clear_settings_cache();
		Image_Optimisation::clear_file_exists_cache();
		$this->reset_nextgen_cache();

		$this->options = array(
			'image_optimisation' => array(
				'convertImg'              => true,
				'conversionFormat'        => 'both',
				'avifFirst'               => true,
				'smartQuality'            => true,
				'skipSmallThresholdBytes' => 5120,
				'placeholderType'         => 'none',
			),
		);

		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'wp_normalize_path' )->alias(
			static function ( $path ) {
				return str_replace( '\\', '/', (string) $path );
			}
		);
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test stub mirrors wp_parse_url() via parse_url().
				return parse_url( (string) $url, $component );
			}
		);
		Functions\when( 'path_is_absolute' )->alias(
			static function ( $path ) {
				return is_string( $path ) && str_starts_with( $path, '/' );
			}
		);
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'content_url' )->justReturn( 'http://example.com/wp-content' );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		Functions\when( 'esc_attr' )->alias(
			static function ( $value ) {
				return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return $value;
			}
		);
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_delete_file' )->alias(
			static function ( $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup for fixture files.
				return unlink( (string) $file );
			}
		);

		unset( $_SERVER['HTTP_ACCEPT'] );
		unset( $_SERVER['SERVER_SOFTWARE'] );
	}

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		Util::clear_settings_cache();
		Image_Optimisation::clear_file_exists_cache();
		$this->reset_nextgen_cache();
		unset( $_SERVER['HTTP_ACCEPT'] );
		unset( $_SERVER['SERVER_SOFTWARE'] );
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Reset the LiteSpeed next-gen static cache between tests.
	 */
	private function reset_nextgen_cache(): void {
		$prop = new ReflectionProperty( LiteSpeed_Integration::class, 'cached_nextgen' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	/**
	 * Canonical defaults must carry the AVIF-first additive keys.
	 */
	public function test_defaults_include_avif_first_keys(): void {
		$defaults = Util::get_default_settings();

		$this->assertTrue( $defaults['image_optimisation']['avifFirst'] );
		$this->assertTrue( $defaults['image_optimisation']['smartQuality'] );
		$this->assertSame( 5120, $defaults['image_optimisation']['skipSmallThresholdBytes'] );
	}

	/**
	 * AVIF encoder probe must return a boolean and never fatal.
	 */
	public function test_is_avif_encoder_available_returns_bool(): void {
		$this->assertIsBool( Img_Converter::is_avif_encoder_available() );
	}

	/**
	 * Skip-small threshold defaults to 5120 and honours the boundary.
	 */
	public function test_skip_small_threshold_boundary(): void {
		$converter = new Img_Converter( $this->options );
		$this->assertSame( 5120, $converter->get_skip_small_threshold() );

		$dir = sys_get_temp_dir() . '/wppo-avif-test';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
		}

		$small = $dir . '/small.jpg';
		$exact = $dir . '/exact.jpg';
		$large = $dir . '/large.jpg';
		file_put_contents( $small, str_repeat( 'a', 5119 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $exact, str_repeat( 'a', 5120 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $large, str_repeat( 'a', 5121 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$this->assertTrue( $converter->should_skip_small_file( $small ) );
		$this->assertTrue( $converter->should_skip_small_file( $exact ), 'Files at the threshold are skipped' );
		$this->assertFalse( $converter->should_skip_small_file( $large ) );
		$this->assertFalse( $converter->should_skip_small_file( $dir . '/missing.jpg' ), 'Missing files fail open' );

		wp_delete_file( $small );
		wp_delete_file( $exact );
		wp_delete_file( $large );
	}

	/**
	 * Convert_image() must skip tiny files, keep the original, and record skipped status.
	 */
	public function test_convert_image_skips_small_files(): void {
		// Fixture lives inside the uploads allowlist: convert_image()
		// refuses sources outside ABSPATH/WP_CONTENT_DIR (#1035).
		$dir = rtrim( WP_CONTENT_DIR, '/' ) . '/uploads/wppo-avif-convert';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
		}

		$tiny  = $dir . '/tiny.png';
		$image = imagecreatetruecolor( 8, 8 );
		$color = imagecolorallocate( $image, 200, 100, 50 );
		imagefill( $image, 0, 0, $color );
		imagepng( $image, $tiny );
		imagedestroy( $image ); // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- Test fixture cleanup.
		$this->assertLessThanOrEqual( 5120, filesize( $tiny ), 'Fixture must be under the default threshold' );

		$options = $this->options;
		$options['image_optimisation']['conversionFormat'] = 'webp';
		$converter = new Img_Converter( $options );

		$this->assertFalse( $converter->convert_image( $tiny, 'webp' ) );
		$this->assertFileExists( $tiny, 'Original must stay restorable' );

		$info     = Img_Converter::get_img_info();
		$full_rel = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $tiny ) );
		$this->assertContains( $full_rel, $info['skipped']['webp'] ?? array() );

		wp_delete_file( $tiny );
		$converted = Img_Converter::get_img_path( $tiny, 'webp' );
		if ( '' !== $converted && file_exists( $converted ) ) {
			wp_delete_file( $converted );
		}
	}

	/**
	 * Smart quality maps AVIF below WebP at equal visual quality.
	 */
	public function test_smart_quality_avif_below_webp(): void {
		$converter = new Img_Converter( $this->options );

		$avif = $converter->get_smart_quality( 'image/avif' );
		$webp = $converter->get_smart_quality( 'image/webp' );

		$this->assertSame( 82, $webp );
		$this->assertSame( 62, $avif );
		$this->assertLessThan( $webp, $avif );
	}

	/**
	 * Picture sources must order AVIF before WebP when Accept allows AVIF.
	 */
	public function test_build_sources_avif_first_order(): void {
		$base = WP_CONTENT_DIR . '/wppo/uploads/2026/08';
		if ( ! is_dir( $base ) ) {
			mkdir( $base, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
		}
		file_put_contents( $base . '/photo.avif', 'avif' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $base . '/photo.webp', 'webp' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$_SERVER['HTTP_ACCEPT'] = 'text/html,image/avif,image/webp,*/*';

		$optimisation = new Image_Optimisation( $this->options );
		$sources      = $optimisation->build_avif_first_sources(
			'http://example.com/wp-content/uploads/2026/08/photo.jpg',
			'',
			'',
			false,
			false
		);

		$this->assertStringContainsString( 'image/avif', $sources );
		$this->assertStringContainsString( 'image/webp', $sources );
		$this->assertLessThan(
			strpos( $sources, 'image/webp' ),
			strpos( $sources, 'image/avif' ),
			'AVIF source must precede WebP source'
		);
		$this->assertStringContainsString( 'photo.avif', $sources );
		$this->assertStringContainsString( 'photo.webp', $sources );

		wp_delete_file( $base . '/photo.avif' );
		wp_delete_file( $base . '/photo.webp' );
	}

	/**
	 * AVIF source must be omitted when the client Accept header lacks AVIF.
	 */
	public function test_build_sources_omits_avif_without_accept(): void {
		$base = WP_CONTENT_DIR . '/wppo/uploads/2026/08';
		if ( ! is_dir( $base ) ) {
			mkdir( $base, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
		}
		file_put_contents( $base . '/plain.avif', 'avif' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $base . '/plain.webp', 'webp' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$_SERVER['HTTP_ACCEPT'] = 'text/html,image/webp,*/*';

		$optimisation = new Image_Optimisation( $this->options );
		$sources      = $optimisation->build_avif_first_sources(
			'http://example.com/wp-content/uploads/2026/08/plain.jpg',
			'',
			'',
			false,
			false
		);

		$this->assertStringNotContainsString( 'image/avif', $sources );
		$this->assertStringContainsString( 'image/webp', $sources );

		wp_delete_file( $base . '/plain.avif' );
		wp_delete_file( $base . '/plain.webp' );
	}

	/**
	 * Missing converted files must fail open to the original MIME source.
	 */
	public function test_build_sources_fail_open_without_converted_files(): void {
		$_SERVER['HTTP_ACCEPT'] = 'text/html,image/avif,image/webp,*/*';

		$optimisation = new Image_Optimisation( $this->options );
		$sources      = $optimisation->build_avif_first_sources(
			'http://example.com/wp-content/uploads/2026/08/ghost.jpg',
			'',
			'',
			false,
			false
		);

		$this->assertStringContainsString( 'image/jpeg', $sources );
		$this->assertStringNotContainsString( 'image/avif', $sources );
		$this->assertStringNotContainsString( 'image/webp', $sources );
	}

	/**
	 * Htaccess next-gen block must order AVIF rewrite before WebP.
	 */
	public function test_htaccess_avif_before_webp(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';

		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = array() ) {
				if ( 'wppo_settings' === $name ) {
					return array(
						'litespeed_integration' => array( 'enableNextGenRewrite' => true ),
						'image_optimisation'    => array( 'convertImg' => true ),
					);
				}
				return $fallback;
			}
		);

		$rules  = Htaccess_Handler::get_rules();
		$joined = implode( "\n", $rules );

		$avif_pos = strpos( $joined, '$1.avif' );
		$webp_pos = strpos( $joined, '$1.webp' );

		$this->assertNotFalse( $avif_pos, 'AVIF rewrite must be present' );
		$this->assertNotFalse( $webp_pos, 'WebP rewrite must be present' );
		$this->assertLessThan( $webp_pos, $avif_pos, 'AVIF rewrite must precede WebP rewrite' );
	}

	/**
	 * Picture/lazy rewriting must leave alt text byte-identical.
	 */
	public function test_process_img_tag_preserves_alt_text(): void {
		require_once __DIR__ . '/stubs/wp-html-api.php';

		$optimisation = new Image_Optimisation( $this->options );

		$alt     = 'A hero image &mdash; deja vu';
		$img_tag = '<img src="http://example.com/wp-content/uploads/2026/08/photo.jpg" alt="' . $alt . '" width="1200" height="800"/>';
		$result  = $optimisation->process_img_tag( $img_tag, 'http://example.com/wp-content/uploads/2026/08/photo.jpg', array() );

		$this->assertStringContainsString( 'alt="' . $alt . '"', $result );
		$this->assertSame( 1, substr_count( $result, 'alt="' ) );
	}
}
