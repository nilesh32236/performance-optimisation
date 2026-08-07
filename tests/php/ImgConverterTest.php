<?php
/**
 * Tests for Img_Converter client-side media processing integration.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Img_Converter;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests for Img_Converter client-side media processing integration.
 *
 * @package PerformanceOptimise\Tests
 */
class ImgConverterTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Options used to build the Img_Converter instance.
	 *
	 * @var array
	 */
	private array $default_options;

	/**
	 * Temporary uploads directory for test images.
	 *
	 * @var string
	 */
	private string $uploads_dir;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();

		$this->default_options = array(
			'image_optimisation' => array(
				'placeholderType'      => 'dominant_color',
				'conversionFormat'     => 'webp',
				'excludeWebPImages'    => '',
				'excludeConvertImages' => '',
			),
		);

		$this->uploads_dir = rtrim( WP_CONTENT_DIR, '/' ) . '/uploads/2026/08';
		if ( ! is_dir( $this->uploads_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixtures use native filesystem.
			mkdir( $this->uploads_dir, 0755, true );
		}

		Functions\stubs(
			array(
				'get_current_blog_id',
				'get_attached_file',
				'wp_get_attachment_url',
				'wp_upload_dir',
				'wp_normalize_path',
				'apply_filters',
				'get_option',
				'update_option',
				'add_action',
				'wp_is_client_side_media_processing_enabled',
				'sanitize_text_field',
				'wp_unslash',
				'home_url',
				'path_is_absolute',
				'wp_parse_url',
				'wp_image_quality',
				'wp_get_image_encode_quality',
			)
		);
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'add_action' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'path_is_absolute' )->justReturn( true );
		// wp_image_quality() is mocked in every test because Patchwork-defined
		// functions cannot be undefined between tests; returning null keeps
		// core_handles_next_gen()/core_handles_both_next_gen() in a known state.
		Functions\when( 'wp_image_quality' )->justReturn( null );
		// wp_get_image_encode_quality() (WP 7.1+) is also stubbed in every test
		// for the same reason. The plugin default (82) keeps every existing
		// assertion deterministic while simulating the WP 7.1+ runtime; tests
		// that need a specific value override it with when()/expect().
		Functions\when( 'wp_get_image_encode_quality' )->justReturn( 82 );
		Functions\when( 'wp_parse_url' )->alias(
			static function ( string $url, $component = -1 ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Used to emulate wp_parse_url() in tests.
				$path = parse_url( $url, PHP_URL_PATH );
				$path = is_string( $path ) ? $path : '/';
				if ( PHP_URL_PATH === $component ) {
					return $path;
				}
				return array( 'path' => $path );
			}
		);
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => wp_normalize_path( WP_CONTENT_DIR . '/uploads' ),
				'path'    => $this->uploads_dir,
			)
		);
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'http://example.com/wp-content/uploads/2026/08/sample.jpg' );

		$this->reset_static_state();
	}

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		$this->reset_static_state();
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Reset Img_Converter static caches between tests.
	 */
	private function reset_static_state(): void {
		$reflected = new ReflectionClass( Img_Converter::class );

		foreach ( array( 'deferred_img_info', 'client_side_processing_state', 'img_info_shutdown_registered' ) as $prop ) {
			$property = $reflected->getProperty( $prop );
			$property->setAccessible( true );
			$property->setValue( null );
		}
	}

	/**
	 * Create a small real PNG on disk and return its absolute path.
	 *
	 * @param string $name File name to create.
	 * @return string Absolute path to the created PNG.
	 */
	private function create_sample_png( string $name = 'sample.png' ): string {
		$path = $this->uploads_dir . '/' . $name;

		$image = imagecreatetruecolor( 64, 48 );
		$color = imagecolorallocate( $image, 120, 180, 240 );
		imagefill( $image, 0, 0, $color );
		imagepng( $image, $path );
		// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- imagedestroy() is still the correct way to free GD resources in PHP 8.x
		imagedestroy( $image );

		return $path;
	}

	/**
	 * Build an Img_Converter instance with the given overrides.
	 *
	 * @param array $overrides Options to merge over defaults.
	 * @return Img_Converter
	 */
	private function make_converter( array $overrides = array() ): Img_Converter {
		$options = $this->default_options;
		foreach ( $overrides as $key => $value ) {
			$options['image_optimisation'][ $key ] = $value;
		}

		return new Img_Converter( $options );
	}

	/**
	 * Set up the plugin's wppo output directory and a real-filesystem
	 * stand-in so Util::prepare_cache_dir() can write converted files.
	 *
	 * @return void
	 */
	private function prepare_wppo_output_dir(): void {
		$out_dir = rtrim( WP_CONTENT_DIR, '/' ) . '/wppo/uploads/2026/08';
		if ( ! is_dir( $out_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixtures use native filesystem.
			mkdir( $out_dir, 0755, true );
		}
		$GLOBALS['wp_filesystem'] = new class() {
			/**
			 * Whether the path is a directory.
			 *
			 * @param string $path Path to check.
			 * @return bool
			 */
			public function is_dir( $path ) {
				return is_dir( $path );
			}

			/**
			 * Create a directory.
			 *
			 * @param string $path  Path to create.
			 * @param int    $chmod Permissions.
			 * @return bool
			 */
			public function mkdir( $path, $chmod = 0755 ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test filesystem stand-in.
				return is_dir( $path ) || mkdir( $path, $chmod, true );
			}
		};
		Functions\when( 'WP_Filesystem' )->justReturn( true );
	}

	/**
	 * Test that placeholder data is stored for the full-size original AND each
	 * registered sub-size when client-side media processing is enabled.
	 */
	public function test_client_side_upload_stores_placeholders_for_all_sizes(): void {
		$file      = $this->create_sample_png();
		$size_path = $this->uploads_dir . '/sample-300x200.png';
		copy( $file, $size_path );

		Functions\when( 'get_attached_file' )->justReturn( $file );
		Functions\when( 'wp_is_client_side_media_processing_enabled' )->justReturn( true );

		$metadata = array(
			'file'  => '2026/08/sample.png',
			'sizes' => array(
				'medium' => array( 'file' => 'sample-300x200.png' ),
			),
		);

		$converter = $this->make_converter();
		$result    = $converter->convert_image_to_next_gen_format( $metadata, 42 );

		$this->assertSame( $metadata, $result );

		$info     = Img_Converter::get_placeholder_info();
		$full_rel = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $file ) );
		$size_rel = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $size_path ) );

		$this->assertArrayHasKey( $full_rel, $info['dominant_color'] );
		$this->assertArrayHasKey( $size_rel, $info['dominant_color'] );
		$this->assertMatchesRegularExpression( '/^#[a-f0-9]{6}$/i', $info['dominant_color'][ $full_rel ] );
		$this->assertSame( $info['dominant_color'][ $full_rel ], $info['dominant_color'][ $size_rel ] );
		$this->assertSame( $info['lqip'][ $full_rel ], $info['lqip'][ $size_rel ] );
	}

	/**
	 * Test that no placeholder data is stored when the configured placeholder
	 * type does not consume dominant-color/LQIP data (default 'svg').
	 */
	public function test_client_side_upload_skips_decode_for_svg_placeholder(): void {
		$file = $this->create_sample_png( 'sample2.png' );

		Functions\when( 'get_attached_file' )->justReturn( $file );
		Functions\when( 'wp_is_client_side_media_processing_enabled' )->justReturn( true );

		$metadata = array(
			'file'  => '2026/08/sample2.png',
			'sizes' => array(),
		);

		$converter = $this->make_converter( array( 'placeholderType' => 'svg' ) );
		$converter->convert_image_to_next_gen_format( $metadata, 43 );

		$info = Img_Converter::get_placeholder_info();
		$this->assertEmpty( $info['dominant_color'] );
		$this->assertEmpty( $info['lqip'] );
	}

	/**
	 * Test that excluded images do not get placeholder extraction on the
	 * client-side path.
	 */
	public function test_client_side_upload_respects_exclude_list(): void {
		$file = $this->create_sample_png( 'sample3.png' );

		Functions\when( 'get_attached_file' )->justReturn( $file );
		Functions\when( 'wp_is_client_side_media_processing_enabled' )->justReturn( true );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'http://example.com/wp-content/uploads/2026/08/sample3.png' );

		$metadata = array(
			'file'  => '2026/08/sample3.png',
			'sizes' => array(),
		);

		$converter = $this->make_converter(
			array(
				'excludeWebPImages' => 'uploads/2026/08/sample3.png',
			)
		);
		$converter->convert_image_to_next_gen_format( $metadata, 44 );

		$info = Img_Converter::get_placeholder_info();
		$this->assertEmpty( $info['dominant_color'] );
		$this->assertEmpty( $info['lqip'] );
	}

	/**
	 * Test that a missing converted file is NOT re-queued when client-side
	 * media processing is enabled and core handles both next-gen formats.
	 */
	public function test_client_side_processing_suppresses_re_queueing(): void {
		Functions\when( 'wp_is_client_side_media_processing_enabled' )->justReturn( true );
		Functions\when( 'wp_image_quality' )->justReturn( 82 );

		$_SERVER['HTTP_ACCEPT'] = 'image/avif,image/webp';

		$converter = $this->make_converter( array( 'conversionFormat' => 'avif' ) );
		$result    = $converter->maybe_serve_next_gen_image( array( 'http://example.com/wp-content/uploads/2026/08/serve.jpg', 640, 480 ) );

		$this->assertSame( 'http://example.com/wp-content/uploads/2026/08/serve.jpg', $result[0] );
		$info = Img_Converter::get_img_info();
		$this->assertEmpty( $info['pending']['webp'] ?? array() );
		$this->assertEmpty( $info['pending']['avif'] ?? array() );
	}

	/**
	 * Test that a missing converted file IS re-queued when client-side media
	 * processing is disabled (legacy server-side path).
	 *
	 * Uses the AVIF format because, with wp_image_quality() defined (as it is
	 * in this test harness), the constructor maps 'webp'/'both' to 'none' but
	 * leaves 'avif' untouched — matching the WP 6.7+ core-native setup where
	 * the plugin still owns AVIF conversion.
	 */
	public function test_legacy_processing_queues_missing_conversions(): void {
		Functions\when( 'wp_is_client_side_media_processing_enabled' )->justReturn( false );

		$_SERVER['HTTP_ACCEPT'] = 'image/avif';

		$converter = $this->make_converter( array( 'conversionFormat' => 'avif' ) );
		$result    = $converter->maybe_serve_next_gen_image( array( 'http://example.com/wp-content/uploads/2026/08/serve2.jpg', 640, 480 ) );

		$this->assertSame( 'http://example.com/wp-content/uploads/2026/08/serve2.jpg', $result[0] );
		$info = Img_Converter::get_img_info();
		$this->assertNotEmpty( $info['pending']['avif'] ?? array() );
	}

	/**
	 * Test that convert_image() resolves the encode quality via
	 * wp_get_image_encode_quality() (WP 7.1+) with the expected
	 * MIME/size/default arguments and uses its return value for the encode.
	 *
	 * The 'avif' format is used because in this harness wp_image_quality() is
	 * always stubbed, so core_handles_next_gen() is true and 'webp'/'both'
	 * short-circuit to 'skipped' before quality resolution is reached.
	 */
	public function test_convert_image_uses_wp_get_image_encode_quality(): void {
		if ( ! function_exists( 'imageavif' ) ) {
			$this->markTestSkipped( 'GD AVIF support is required.' );
		}

		$file = $this->create_sample_png( 'quality.png' );

		// Util::prepare_cache_dir() writes into the plugin's wppo directory and
		// relies on WP_Filesystem; provide a real-filesystem stand-in.
		$this->prepare_wppo_output_dir();

		$captured = null;
		Functions\when( 'wp_get_image_encode_quality' )->alias(
			static function ( $mime, $size, $default_quality ) use ( &$captured ) {
				$captured = array( $mime, $size, $default_quality );
				return 60;
			}
		);

		$converter = $this->make_converter( array( 'conversionFormat' => 'avif' ) );
		$result    = $converter->convert_image( $file, 'avif' );

		$this->assertTrue( $result );
		$this->assertSame(
			array(
				'image/avif',
				array(
					'width'  => 64,
					'height' => 48,
				),
				null,
			),
			$captured
		);

		$info     = Img_Converter::get_img_info();
		$full_rel = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $file ) );
		$this->assertContains( $full_rel, $info['completed']['avif'] ?? array() );
	}

	/**
	 * Test that convert_image() resolves the encode quality via
	 * wp_get_image_encode_quality() (WP 7.1+) on the WebP path, which only
	 * requires GD WebP support.
	 *
	 * The AVIF end-to-end test above skips when GD lacks libavif, so this
	 * test keeps end-to-end coverage in CI environments without AVIF.
	 * function_exists() is patched so core_handles_next_gen() reports no
	 * native next-gen support, which stops the WebP path from short-circuiting
	 * to 'skipped'.
	 */
	public function test_convert_image_webp_uses_wp_get_image_encode_quality(): void {
		if ( ! function_exists( 'imagewebp' ) ) {
			$this->markTestSkipped( 'GD WebP support is required.' );
		}

		$file = $this->create_sample_png( 'quality-webp.png' );

		// Util::prepare_cache_dir() writes into the plugin's wppo directory and
		// relies on WP_Filesystem; provide a real-filesystem stand-in.
		$this->prepare_wppo_output_dir();

		// Make core_handles_next_gen() report false so the WebP path does not
		// short-circuit to 'skipped'. The WP 7.1+ helper itself is left intact.
		Functions\when( 'function_exists' )->alias(
			static function ( $function_name ) {
				if ( 'wp_image_quality' === $function_name ) {
					return false;
				}
				return \function_exists( $function_name );
			}
		);

		$captured = null;
		Functions\when( 'wp_get_image_encode_quality' )->alias(
			static function ( $mime, $size, $default_quality ) use ( &$captured ) {
				$captured = array( $mime, $size, $default_quality );
				return 75;
			}
		);

		$converter = $this->make_converter( array( 'conversionFormat' => 'webp' ) );
		$result    = $converter->convert_image( $file, 'webp' );

		$this->assertTrue( $result );
		$this->assertSame(
			array(
				'image/webp',
				array(
					'width'  => 64,
					'height' => 48,
				),
				null,
			),
			$captured
		);

		$info     = Img_Converter::get_img_info();
		$full_rel = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $file ) );
		$this->assertContains( $full_rel, $info['completed']['webp'] ?? array() );
	}

	/**
	 * Test that resolve_encode_quality() resolves a distinct quality per output
	 * MIME type when wp_get_image_encode_quality() (WP 7.1+) is available,
	 * passing null as the core default so WordPress computes its own
	 * per-format baseline (86 for WebP, 82 for other formats).
	 *
	 * This mirrors the 'both' conversion path, which resolves avif and webp
	 * quality independently via two helper calls.
	 */
	public function test_resolve_encode_quality_resolves_per_format_quality(): void {
		$calls = array();
		Functions\when( 'wp_get_image_encode_quality' )->alias(
			static function ( $mime, $size, $default_quality ) use ( &$calls ) {
				$calls[] = array( $mime, $size, $default_quality );
				return 'image/avif' === $mime ? 60 : 75;
			}
		);

		$method = new ReflectionMethod( Img_Converter::class, 'resolve_encode_quality' );
		$method->setAccessible( true );

		$this->assertSame( 75, $method->invoke( null, 'image/webp' ) );
		$this->assertSame(
			60,
			$method->invoke(
				null,
				'image/avif',
				array(
					'width'  => 800,
					'height' => 600,
				)
			)
		);
		$this->assertSame(
			array(
				array( 'image/webp', array(), null ),
				array(
					'image/avif',
					array(
						'width'  => 800,
						'height' => 600,
					),
					null,
				),
			),
			$calls
		);
	}

	/**
	 * Test that resolve_encode_quality() falls back to wp_image_quality()
	 * (WP 6.7-7.0) when the WP 7.1+ helper is unavailable.
	 */
	public function test_resolve_encode_quality_falls_back_to_wp_image_quality(): void {
		Functions\when( 'function_exists' )->alias(
			static function ( $function_name ) {
				if ( 'wp_get_image_encode_quality' === $function_name ) {
					return false;
				}
				return \function_exists( $function_name );
			}
		);
		Functions\when( 'wp_image_quality' )->justReturn( 82 );

		$method = new ReflectionMethod( Img_Converter::class, 'resolve_encode_quality' );
		$method->setAccessible( true );

		$this->assertSame( 82, $method->invoke( null, 'image/webp' ) );
		$this->assertSame( 82, $method->invoke( null, 'image/avif' ) );
	}

	/**
	 * Test that placeholder data is also extracted on the WP 6.7+ core-native
	 * next-gen path (get_format() returns 'none') where no client-side media
	 * processing flag exists yet.
	 */
	public function test_core_native_next_gen_still_extracts_placeholders(): void {
		$file = $this->create_sample_png( 'sample4.png' );

		Functions\when( 'get_attached_file' )->justReturn( $file );
		Functions\when( 'wp_is_client_side_media_processing_enabled' )->justReturn( false );

		$metadata = array(
			'file'  => '2026/08/sample4.png',
			'sizes' => array(),
		);

		// wp_image_quality() exists (WP 6.7+) and returns null for next-gen
		// mimes, so the constructor maps the default 'webp' format to 'none'.
		$converter = $this->make_converter();
		$this->assertSame( 'none', $converter->get_format() );

		$converter->convert_image_to_next_gen_format( $metadata, 45 );

		$info     = Img_Converter::get_placeholder_info();
		$full_rel = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $file ) );
		$this->assertArrayHasKey( $full_rel, $info['dominant_color'] );
		$this->assertNotEmpty( $info['lqip'][ $full_rel ] ?? '' );
	}

	/**
	 * Test that get_image_mime_type() maps heic/heif extensions correctly.
	 */
	public function test_image_mime_type_maps_heic_and_heif(): void {
		$this->assertSame( 'image/heic', Util::get_image_mime_type( 'http://example.com/uploads/photo.heic' ) );
		$this->assertSame( 'image/heif', Util::get_image_mime_type( 'http://example.com/uploads/photo.heif' ) );
	}

	/**
	 * Test that resolve_encode_quality() falls back to the flat
	 * wp_image_quality() API on WP 6.7-7.0 when the 7.1 helper is absent.
	 *
	 * Runs before test_resolve_encode_quality_uses_size_aware_core_helper()
	 * so the WP 7.1 helper is not defined in-process yet (Brain Monkey keeps
	 * mocked function definitions alive for the duration of a test class).
	 */
	public function test_resolve_encode_quality_falls_back_to_flat_core_quality(): void {
		Functions\when( 'wp_get_image_encode_quality' )->justReturn( null );
		Functions\when( 'wp_image_quality' )->justReturn( 75 );

		$converter  = $this->make_converter();
		$reflection = new ReflectionMethod( Img_Converter::class, 'resolve_encode_quality' );
		$reflection->setAccessible( true );

		$this->assertSame( 75, $reflection->invoke( $converter, 'image/avif', 82 ) );
	}

	/**
	 * Test that resolve_encode_quality() returns the supplied default when no
	 * core quality API provides a value (older cores, or null quality).
	 */
	public function test_resolve_encode_quality_falls_back_to_default(): void {
		Functions\when( 'wp_get_image_encode_quality' )->justReturn( null );
		Functions\when( 'wp_image_quality' )->justReturn( null );

		$converter  = $this->make_converter();
		$reflection = new ReflectionMethod( Img_Converter::class, 'resolve_encode_quality' );
		$reflection->setAccessible( true );

		$this->assertSame( 82, $reflection->invoke( $converter, 'image/avif', 82 ) );
		$this->assertSame( 40, $reflection->invoke( $converter, 'image/jpeg', 40 ) );
	}

	/**
	 * Test that resolve_encode_quality() prefers the WP 7.1+ size-aware
	 * helper and forwards the source dimensions.
	 */
	public function test_resolve_encode_quality_uses_size_aware_core_helper(): void {
		Functions\expect( 'wp_get_image_encode_quality' )
			->once()
			->with(
				'image/webp',
				array(
					'width'  => 300,
					'height' => 200,
				),
				82
			)
			->andReturn( 65 );

		$converter  = $this->make_converter();
		$reflection = new ReflectionMethod( Img_Converter::class, 'resolve_encode_quality' );
		$reflection->setAccessible( true );

		$this->assertSame(
			65,
			$reflection->invoke(
				$converter,
				'image/webp',
				82,
				array(
					'width'  => 300,
					'height' => 200,
				)
			)
		);
	}

	/**
	 * Test that resolve_encode_quality() guards a null result from the WP 7.1+
	 * size-aware helper and returns the supplied fallback instead of casting
	 * null to 0 (which would encode at quality 0).
	 *
	 * Runs after test_resolve_encode_quality_uses_size_aware_core_helper() so
	 * the WP 7.1 helper is already defined in-process (Brain Monkey keeps
	 * mocked function definitions alive for the duration of a test class).
	 */
	public function test_resolve_encode_quality_guards_null_size_aware_result(): void {
		Functions\when( 'wp_get_image_encode_quality' )->justReturn( null );

		$converter  = $this->make_converter();
		$reflection = new ReflectionMethod( Img_Converter::class, 'resolve_encode_quality' );
		$reflection->setAccessible( true );

		$this->assertSame( 82, $reflection->invoke( $converter, 'image/avif', 82 ) );
		$this->assertSame( 40, $reflection->invoke( $converter, 'image/jpeg', 40 ) );
	}

	/**
	 * Test that get_source_image_dimensions() parses the -WxH sub-size suffix
	 * and returns an empty array for full-size originals.
	 */
	public function test_get_source_image_dimensions_parses_size_suffix(): void {
		$converter  = $this->make_converter();
		$reflection = new ReflectionMethod( Img_Converter::class, 'get_source_image_dimensions' );
		$reflection->setAccessible( true );

		$this->assertSame(
			array(
				'width'  => 300,
				'height' => 200,
			),
			$reflection->invoke( $converter, '/srv/wp-content/uploads/2026/08/sample-300x200.jpg' )
		);
		$this->assertSame(
			array(),
			$reflection->invoke( $converter, '/srv/wp-content/uploads/2026/08/sample.jpg' )
		);
	}
}
