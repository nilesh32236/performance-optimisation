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
		// Salted-cache gate default (issue #882).
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );

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
				'wp_get_image_editor_output_format',
				'wp_delete_file',
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
		// wp_get_image_editor_output_format() (WP 6.7+) is stubbed in every test
		// for the same reason. Returning an empty mapping keeps every existing
		// assertion deterministic while simulating the WP 6.7+ runtime; tests
		// that need a specific mapping override it with when()/expect().
		Functions\when( 'wp_get_image_editor_output_format' )->justReturn( array() );
		Functions\when( 'wp_parse_url' )->alias(
			static function ( string $url, $component = -1 ) {
				if ( PHP_URL_HOST === $component ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Used to emulate wp_parse_url() in tests.
					return parse_url( $url, PHP_URL_HOST );
				}
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
		Util::destroy_gd_image( $image );

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
	 * Test that is_client_side_media_processing() reports false when the
	 * "Force Server-Side Conversion" toggle is enabled even though core
	 * reports client-side media processing is available.
	 */
	public function test_client_side_media_processing_forced_off_by_setting(): void {
		Functions\when( 'wp_is_client_side_media_processing_enabled' )->justReturn( true );

		$converter = $this->make_converter( array( 'forceServerSideConversion' => true ) );

		$method = new ReflectionMethod( Img_Converter::class, 'is_client_side_media_processing' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( $converter ) );
	}

	/**
	 * Test that is_client_side_media_processing() reports true when core
	 * reports client-side media processing is available and the opt-out toggle
	 * is not enabled (control case).
	 */
	public function test_client_side_media_processing_true_by_default(): void {
		Functions\when( 'wp_is_client_side_media_processing_enabled' )->justReturn( true );

		$converter = $this->make_converter();

		$method = new ReflectionMethod( Img_Converter::class, 'is_client_side_media_processing' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $converter ) );
	}

	/**
	 * Test that convert_image() resolves the encode quality via
	 * wp_get_image_encode_quality() (WP 7.1+) with the expected
	 * MIME/size/default arguments and uses its return value for the encode.
	 *
	 * The 'avif' format is used because in this harness wp_image_quality() is
	 * always stubbed, so core_handles_next_gen() is true and 'webp'/'both'
	 * short-circuit to 'skipped' before quality resolution is reached.
	 *
	 * Smart quality mapping and the skip-small threshold are disabled here so
	 * the raw resolve_encode_quality() path is asserted; the smart/skip-small
	 * behaviour is covered by ImageAvifPictureTest.
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

		$converter = $this->make_converter(
			array(
				'conversionFormat'        => 'avif',
				'smartQuality'            => false,
				'skipSmallThresholdBytes' => 0,
			)
		);
		$result    = $converter->convert_image( $file, 'avif' );

		$this->assertTrue( $result );
		$this->assertSame(
			array(
				'image/avif',
				array(),
				82,
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
				return 'wp_image_quality' !== $function_name;
			}
		);

		$captured = null;
		Functions\when( 'wp_get_image_encode_quality' )->alias(
			static function ( $mime, $size, $default_quality ) use ( &$captured ) {
				$captured = array( $mime, $size, $default_quality );
				return 75;
			}
		);

		// Skip-small is disabled: the 64x48 fixture is under the default threshold.
		$converter = $this->make_converter(
			array(
				'conversionFormat'        => 'webp',
				'skipSmallThresholdBytes' => 0,
			)
		);
		$result    = $converter->convert_image( $file, 'webp' );

		$this->assertTrue( $result );
		$this->assertSame(
			array(
				'image/webp',
				array(),
				82,
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

		$converter = $this->make_converter();

		$this->assertSame( 75, $method->invoke( $converter, 'image/webp', 82 ) );
		$this->assertSame(
			60,
			$method->invoke(
				$converter,
				'image/avif',
				82,
				array(
					'width'  => 800,
					'height' => 600,
				)
			)
		);
		$this->assertSame(
			array(
				array( 'image/webp', array(), 82 ),
				array(
					'image/avif',
					array(
						'width'  => 800,
						'height' => 600,
					),
					82,
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
				return 'wp_get_image_encode_quality' !== $function_name;
			}
		);
		Functions\when( 'wp_image_quality' )->justReturn( 82 );

		$method = new ReflectionMethod( Img_Converter::class, 'resolve_encode_quality' );
		$method->setAccessible( true );

		$converter = $this->make_converter();

		$this->assertSame( 82, $method->invoke( $converter, 'image/webp', 40 ) );
		$this->assertSame( 82, $method->invoke( $converter, 'image/avif', 40 ) );
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
		$captured = null;
		Functions\when( 'wp_get_image_encode_quality' )->alias(
			static function ( $mime, $size, $default_quality ) use ( &$captured ) {
				$captured = array( $mime, $size, $default_quality );
				return 65;
			}
		);

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
		$this->assertSame(
			array(
				'image/webp',
				array(
					'width'  => 300,
					'height' => 200,
				),
				82,
			),
			$captured
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
	 * Test that resolve_encode_quality() guards a zero result from the flat
	 * wp_image_quality() helper and returns the supplied fallback instead of
	 * encoding at quality 0.
	 *
	 * This is the headline regression guard for the PR: a core helper that
	 * returns 0 (not null) must not be cast to a quality of 0.
	 */
	public function test_resolve_encode_quality_guards_zero_flat_quality(): void {
		Functions\when( 'wp_get_image_encode_quality' )->justReturn( null );
		Functions\when( 'wp_image_quality' )->justReturn( 0 );

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

	/**
	 * Test that resolve_output_format() returns the requested format unchanged
	 * when wp_get_image_editor_output_format() (WP 6.7+) is unavailable.
	 */
	public function test_resolve_output_format_falls_back_when_core_helper_absent(): void {
		Functions\when( 'function_exists' )->alias(
			static function ( $function_name ) {
				return 'wp_get_image_editor_output_format' !== $function_name;
			}
		);

		$method = new ReflectionMethod( Img_Converter::class, 'resolve_output_format' );
		$method->setAccessible( true );
		$converter = $this->make_converter();

		$this->assertSame( 'webp', $method->invoke( $converter, '/srv/wp-content/uploads/2026/08/sample.jpg', 'webp' ) );
		$this->assertSame( 'avif', $method->invoke( $converter, '/srv/wp-content/uploads/2026/08/sample.jpg', 'avif' ) );
		$this->assertSame( 'both', $method->invoke( $converter, '/srv/wp-content/uploads/2026/08/sample.jpg', 'both' ) );
	}

	/**
	 * Test that resolve_output_format() maps core-chosen target MIME types to
	 * plugin formats: image/webp -> webp, image/avif -> avif, and any legacy
	 * target -> 'none' (core owns the output). Also verifies that a missing
	 * mapping keeps the requested format unchanged.
	 */
	public function test_resolve_output_format_maps_core_targets(): void {
		$mappings = array(
			'image/jpeg' => 'image/webp',
			'image/png'  => 'image/avif',
			'image/heic' => 'image/jpeg',
		);
		Functions\when( 'wp_get_image_editor_output_format' )->alias(
			static function ( $filename, $mime_type ) use ( $mappings ) {
				return isset( $mappings[ $mime_type ] ) ? array( $mime_type => $mappings[ $mime_type ] ) : array();
			}
		);

		$method = new ReflectionMethod( Img_Converter::class, 'resolve_output_format' );
		$method->setAccessible( true );
		$converter = $this->make_converter();

		$this->assertSame( 'webp', $method->invoke( $converter, '/srv/wp-content/uploads/2026/08/photo.jpg', 'avif' ) );
		$this->assertSame( 'avif', $method->invoke( $converter, '/srv/wp-content/uploads/2026/08/photo.png', 'webp' ) );
		$this->assertSame( 'none', $method->invoke( $converter, '/srv/wp-content/uploads/2026/08/photo.heic', 'webp' ) );
		$this->assertSame( 'webp', $method->invoke( $converter, '/srv/wp-content/uploads/2026/08/photo.gif', 'webp' ) );
	}

	/**
	 * Test that resolve_output_format() defers to core's built-in defaults
	 * even with no `image_editor_output_format` filter registered: core
	 * ships an HEIC -> JPEG default mapping, so an HEIC source resolves to
	 * 'none' (core owns the output) while unmapped sources keep the
	 * requested format unchanged.
	 */
	public function test_resolve_output_format_falls_back_without_core_filter(): void {
		Functions\when( 'wp_get_image_editor_output_format' )->alias(
			static function ( $filename, $mime_type ) {
				return 'image/heic' === $mime_type ? array( 'image/heic' => 'image/jpeg' ) : array();
			}
		);

		$method = new ReflectionMethod( Img_Converter::class, 'resolve_output_format' );
		$method->setAccessible( true );
		$converter = $this->make_converter();

		$this->assertSame( 'none', $method->invoke( $converter, '/srv/wp-content/uploads/2026/08/photo.heic', 'webp' ) );
		$this->assertSame( 'none', $method->invoke( $converter, '/srv/wp-content/uploads/2026/08/photo.heic', 'avif' ) );
		$this->assertSame( 'webp', $method->invoke( $converter, '/srv/wp-content/uploads/2026/08/photo.jpg', 'webp' ) );
		$this->assertSame( 'avif', $method->invoke( $converter, '/srv/wp-content/uploads/2026/08/photo.png', 'avif' ) );
	}

	/**
	 * Test that convert_image() honors core's image_editor_output_format
	 * mapping: when core maps the source MIME to WebP while the plugin was
	 * requested to convert to AVIF, the method produces a WebP file, marks the
	 * requested AVIF format skipped, and leaves no stale AVIF pending entry.
	 *
	 * Core `function_exists()` is patched so `core_handles_next_gen()` reports
	 * no native next-gen support, which stops the resolved WebP path from
	 * short-circuiting to 'skipped'.
	 */
	public function test_convert_image_honors_core_format_mapping_to_webp(): void {
		if ( ! function_exists( 'imagewebp' ) ) {
			$this->markTestSkipped( 'GD WebP support is required.' );
		}

		$file = $this->create_sample_png( 'mapped-webp.png' );

		// Util::prepare_cache_dir() writes into the plugin's wppo directory and
		// relies on WP_Filesystem; provide a real-filesystem stand-in.
		$this->prepare_wppo_output_dir();

		Functions\when( 'function_exists' )->alias(
			static function ( $function_name ) {
				return 'wp_image_quality' !== $function_name;
			}
		);

		// Core maps the PNG source MIME to WebP (e.g. via image_editor_output_format).
		Functions\when( 'wp_get_image_editor_output_format' )->alias(
			static function ( $filename, $mime_type ) {
				return 'image/png' === $mime_type ? array( 'image/png' => 'image/webp' ) : array();
			}
		);

		$converter = $this->make_converter(
			array(
				'conversionFormat'        => 'avif',
				'skipSmallThresholdBytes' => 0,
			)
		);
		$result    = $converter->convert_image( $file, 'avif' );

		$this->assertTrue( $result );

		$webp_path = Img_Converter::get_img_path( $file, 'webp' );
		$this->assertFileExists( $webp_path );

		$info     = Img_Converter::get_img_info();
		$full_rel = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $file ) );
		$this->assertContains( $full_rel, $info['completed']['webp'] ?? array() );
		$this->assertContains( $full_rel, $info['skipped']['avif'] ?? array() );
		$this->assertNotContains( $full_rel, $info['pending']['avif'] ?? array() );
	}

	/**
	 * Test that convert_image() returns false, marks the requested format
	 * skipped, and writes no converted file when core maps the source MIME to a
	 * non-next-gen format (core owns that output).
	 */
	public function test_convert_image_skips_when_core_maps_to_legacy_format(): void {
		$file = $this->create_sample_png( 'mapped-legacy.png' );
		$this->prepare_wppo_output_dir();

		Functions\when( 'wp_get_image_editor_output_format' )->alias(
			static function ( $filename, $mime_type ) {
				return 'image/png' === $mime_type ? array( 'image/png' => 'image/jpeg' ) : array();
			}
		);

		$converter = $this->make_converter( array( 'conversionFormat' => 'webp' ) );
		$result    = $converter->convert_image( $file, 'webp' );

		$this->assertFalse( $result );

		$webp_path = Img_Converter::get_img_path( $file, 'webp' );
		$this->assertFileDoesNotExist( $webp_path );

		$info     = Img_Converter::get_img_info();
		$full_rel = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $file ) );
		$this->assertContains( $full_rel, $info['skipped']['webp'] ?? array() );
		$this->assertNotContains( $full_rel, $info['pending']['webp'] ?? array() );
	}

	/**
	 * Test that get_img_path() returns off-site URLs unchanged instead of
	 * mapping them onto ABSPATH-based filesystem paths (Finding 9 hardening).
	 *
	 * Because convert_image() requires file_exists() on the source, returning
	 * the URL unchanged makes external sources fail gracefully rather than
	 * fabricating local paths.
	 */
	public function test_get_img_path_returns_external_urls_unchanged(): void {
		// The host guard resolves the site's content/home URL hosts.
		Functions\when( 'content_url' )->justReturn( 'http://example.com/wp-content' );

		$external = 'https://external-cdn.example.com/img/photo.jpg';

		$this->assertSame( $external, Img_Converter::get_img_path( $external, 'webp' ) );
		$this->assertSame( $external, Img_Converter::get_img_path( $external, 'avif' ) );
	}

	/**
	 * Test that get_img_path() still resolves same-host content URLs to the
	 * plugin's wppo output directory — genuinely local sources keep working.
	 */
	public function test_get_img_path_resolves_same_host_content_urls(): void {
		// The host guard resolves the site's content/home URL hosts.
		Functions\when( 'content_url' )->justReturn( 'http://example.com/wp-content' );

		$url = 'http://example.com/wp-content/uploads/2026/08/photo.jpg';

		$this->assertSame(
			wp_normalize_path( WP_CONTENT_DIR . '/wppo/uploads/2026/08/photo.webp' ),
			Img_Converter::get_img_path( $url, 'webp' )
		);
	}

	/**
	 * Test that non-convertible formats (SVG, GIF, BMP, uppercase) are
	 * rejected at the queue gate and never enter the conversion queue.
	 */
	public function test_add_img_into_queue_rejects_non_convertible_formats(): void {
		$this->assertFalse( Img_Converter::add_img_into_queue( $this->uploads_dir . '/vector.svg' ) );
		$this->assertFalse( Img_Converter::add_img_into_queue( $this->uploads_dir . '/VECTOR.SVG' ) );
		$this->assertFalse( Img_Converter::add_img_into_queue( $this->uploads_dir . '/anim.gif' ) );
		$this->assertFalse( Img_Converter::add_img_into_queue( $this->uploads_dir . '/scan.bmp' ) );
		$this->assertFalse( Img_Converter::add_img_into_queue( $this->uploads_dir . '/clip.tiff' ) );
	}

	/**
	 * Install a scriptable $wpdb double and return the original instance.
	 *
	 * The bootstrap's $wpdb mock is a plain stdClass without query methods,
	 * and production calls `$wpdb->get_col()` method-style, which property
	 * closures cannot intercept — hence an anonymous class with __call().
	 *
	 * @param array $responses Method-name => return-value (or callable) map.
	 * @return object Original $wpdb instance for restoration in finally.
	 */
	private function stub_wpdb( array $responses ): object {
		global $wpdb;

		$original = $wpdb;
		$double   = new class() {
			/**
			 * Posts table name used inside interpolated SQL.
			 *
			 * @var string
			 */
			public string $posts = 'wp_posts';

			/**
			 * Method-name => return-value (or callable) map.
			 *
			 * @var array
			 */
			private array $responses = array();

			/**
			 * Configure the response map.
			 *
			 * @param array $responses Method-name => value-or-callable map.
			 * @return void
			 */
			public function set_responses( array $responses ): void {
				$this->responses = $responses;
			}

			/**
			 * Serve mapped responses for any queried method.
			 *
			 * @param string $name      Method name.
			 * @param array  $arguments Call arguments.
			 * @return mixed
			 */
			public function __call( string $name, array $arguments ) {
				$value = $this->responses[ $name ] ?? null;

				return is_callable( $value ) && ! is_string( $value )
					? call_user_func_array( $value, $arguments )
					: $value;
			}
		};
		$double->set_responses( $responses );
		$wpdb = $double;

		return $original;
	}

	/**
	 * Test that the cron discovery scan queues library images whose next-gen
	 * versions are missing on disk.
	 */
	public function test_queue_unconverted_library_images_queues_missing_conversions(): void {
		global $wpdb;

		Functions\when( 'content_url' )->justReturn( 'http://example.com/wp-content' );

		$source = $this->uploads_dir . '/disc.jpg';
		touch( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Test fixture.
		$this->assertFileExists( $source );

		Functions\when( 'get_attached_file' )->justReturn( $source );

		$original = $this->stub_wpdb(
			array(
				'prepare' => static function ( $sql ) {
					return $sql;
				},
				'get_col' => array( '11' ),
			)
		);

		try {
			$this->assertSame(
				2,
				Img_Converter::queue_unconverted_library_images( array( 'webp', 'avif' ), 10 )
			);
		} finally {
			$wpdb = $original;
		}
	}

	/**
	 * Test that the discovery scan skips images whose converted versions
	 * already exist under the wppo output directory.
	 */
	public function test_queue_unconverted_library_images_skips_already_converted(): void {
		global $wpdb;

		Functions\when( 'content_url' )->justReturn( 'http://example.com/wp-content' );

		$source = $this->uploads_dir . '/disc2.jpg';
		touch( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Test fixture.

		$dest_dir = wp_normalize_path( WP_CONTENT_DIR . '/wppo/uploads/2026/08' );
		if ( ! is_dir( $dest_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixtures use native filesystem.
			mkdir( $dest_dir, 0755, true );
		}
		touch( $dest_dir . '/disc2.webp' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Test fixture.
		touch( $dest_dir . '/disc2.avif' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Test fixture.

		Functions\when( 'get_attached_file' )->justReturn( $source );

		$original = $this->stub_wpdb(
			array(
				'prepare' => static function ( $sql ) {
					return $sql;
				},
				'get_col' => array( '21' ),
			)
		);

		try {
			$this->assertSame(
				0,
				Img_Converter::queue_unconverted_library_images( array( 'webp', 'avif' ), 10 )
			);
		} finally {
			wp_delete_file( $dest_dir . '/disc2.webp' ); // Test fixture cleanup.
			wp_delete_file( $dest_dir . '/disc2.avif' ); // Test fixture cleanup.
			$wpdb = $original;
		}
	}

	/**
	 * Test that UltraHDR / gain-map sources are skipped untouched so the
	 * embedded gain map survives (mirrors core's own handling).
	 */
	public function test_convert_image_skips_gain_map_sources(): void {
		$file = $this->uploads_dir . '/ultrahdr.jpg';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents(
			$file,
			"\xFF\xD8\xFF\xE0" . str_repeat( "\x00", 16 )
				. 'xmpmeta hdrgm:GainMapMin="0.0" hdrgm:GainMapMax="1.0"'
				. str_repeat( "\x00", 16 ) . "\xFF\xD9"
		);

		$converter = $this->make_converter( array( 'conversionFormat' => 'webp' ) );
		$result    = $converter->convert_image( $file, 'webp' );

		$this->assertFalse( $result );

		$full_rel = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $file ) );
		$info     = Img_Converter::get_img_info();
		$this->assertContains( $full_rel, $info['skipped']['webp'] ?? array() );
	}

	/**
	 * Test that the `wppo_convert_gain_map_images` filter opts gain-map
	 * sources back into conversion: with the filter returning true the
	 * gain-map skip is bypassed and the (unparseable fixture) conversion
	 * fails instead of being recorded as skipped.
	 */
	public function test_convert_image_gain_map_opt_in_flips_skip(): void {
		$file = $this->uploads_dir . '/ultrahdr-optin.jpg';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents(
			$file,
			"\xFF\xD8\xFF\xE0" . str_repeat( "\x00", 16 )
				. 'xmpmeta hdrgm:GainMapMin="0.0" hdrgm:GainMapMax="1.0"'
				. str_repeat( "\x00", 16 ) . "\xFF\xD9"
		);

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook_name, $value ) {
				if ( 'wppo_convert_gain_map_images' === $hook_name ) {
					return true;
				}
				return $value;
			}
		);

		$converter = $this->make_converter(
			array(
				'conversionFormat'        => 'avif',
				'skipSmallThresholdBytes' => 0,
			)
		);
		$result    = $converter->convert_image( $file, 'avif' );

		$this->assertFalse( $result );

		$full_rel = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $file ) );
		$info     = Img_Converter::get_img_info();
		$this->assertNotContains( $full_rel, $info['skipped']['avif'] ?? array() );
		$this->assertContains( $full_rel, $info['failed']['avif'] ?? array() );
	}

	/**
	 * Longest-edge cap defaults to 2560, is filter-overridable, and clamps negatives.
	 *
	 * @since 2.0.0
	 */
	public function test_longest_edge_cap_default_filter_and_clamp(): void {
		$converter = $this->make_converter();
		$this->assertSame( 2560, $converter->get_longest_edge_cap() );

		$disabled = $this->make_converter( array( 'maxLongestEdgePx' => 0 ) );
		$this->assertSame( 0, $disabled->get_longest_edge_cap() );

		$negative = $this->make_converter( array( 'maxLongestEdgePx' => -5 ) );
		$this->assertSame( 0, $negative->get_longest_edge_cap() );

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook_name, $value ) {
				if ( 'wppo_max_longest_edge_px' === $hook_name ) {
					return 1920;
				}
				return $value;
			}
		);
		$this->assertSame( 1920, $converter->get_longest_edge_cap() );

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook_name, $value ) {
				if ( 'wppo_max_longest_edge_px' === $hook_name ) {
					return -10;
				}
				return $value;
			}
		);
		$this->assertSame( 0, $converter->get_longest_edge_cap() );

		// A non-scalar filter return falls back to the 2560 default instead of
		// coercing an array to 0/1.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook_name, $value ) {
				if ( 'wppo_max_longest_edge_px' === $hook_name ) {
					return array( 1920 );
				}
				return $value;
			}
		);
		$this->assertSame( 2560, $converter->get_longest_edge_cap() );
	}

	/**
	 * A negative option cap is clamped to 0 by get_longest_edge_cap() itself.
	 *
	 * The constructor stores options verbatim (no sanitizer), so this exercises
	 * the explicit `cap < 0 => 0` branch rather than a pre-clamped value.
	 *
	 * @since 2.0.0
	 */
	public function test_longest_edge_cap_clamps_negative_option(): void {
		$converter = $this->make_converter( array( 'maxLongestEdgePx' => -5 ) );

		// Prove the raw option is still negative (the branch is reachable).
		$options_prop = new \ReflectionProperty( Img_Converter::class, 'options' );
		$options_prop->setAccessible( true );
		$raw = $options_prop->getValue( $converter )['image_optimisation']['maxLongestEdgePx'];
		$this->assertSame( -5, $raw );

		$this->assertSame( 0, $converter->get_longest_edge_cap() );

		// Extreme negative magnitude also clamps (no int overflow surprises).
		$this->assertSame(
			0,
			$this->make_converter( array( 'maxLongestEdgePx' => PHP_INT_MIN ) )->get_longest_edge_cap()
		);
	}

	/**
	 * The get_max_source_pixels() budget honors the wppo_max_source_pixels
	 * filter and returns a positive memory-derived value by default.
	 *
	 * @since 2.0.0
	 */
	public function test_get_max_source_pixels_filter_and_default(): void {
		$converter = $this->make_converter();

		// Default: a positive int (memory-derived or the 5000x5000 fallback).
		$default = $converter->get_max_source_pixels();
		$this->assertIsInt( $default );
		$this->assertGreaterThan( 0, $default );

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook_name, $value ) {
				if ( 'wppo_max_source_pixels' === $hook_name ) {
					return 1234567;
				}
				return $value;
			}
		);
		$this->assertSame( 1234567, $converter->get_max_source_pixels() );
	}

	/**
	 * Downscale shrinks oversized resources, keeps small ones, and fails open.
	 *
	 * @since 2.0.0
	 */
	public function test_maybe_downscale_gd_image(): void {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD support is required.' );
		}

		$converter = $this->make_converter( array( 'maxLongestEdgePx' => 32 ) );

		$image  = imagecreatetruecolor( 64, 48 );
		$result = $converter->maybe_downscale_gd_image( $image, 64, 48 );
		$this->assertNotSame( $image, $result );
		$this->assertSame( 32, imagesx( $result ) );
		$this->assertSame( 24, imagesy( $result ) );
		Util::destroy_gd_image( $image );
		Util::destroy_gd_image( $result );

		// Already within the cap: original resource retained.
		$small = imagecreatetruecolor( 16, 12 );
		$this->assertSame( $small, $converter->maybe_downscale_gd_image( $small, 16, 12 ) );
		Util::destroy_gd_image( $small );

		// Invalid dimensions fail open to the original resource.
		$odd = imagecreatetruecolor( 8, 8 );
		$this->assertSame( $odd, $converter->maybe_downscale_gd_image( $odd, 0, 0 ) );
		Util::destroy_gd_image( $odd );

		// Cap disabled (0): original resource retained.
		$off     = $this->make_converter( array( 'maxLongestEdgePx' => 0 ) );
		$big_img = imagecreatetruecolor( 64, 48 );
		$this->assertSame( $big_img, $off->maybe_downscale_gd_image( $big_img, 64, 48 ) );
		Util::destroy_gd_image( $big_img );
	}

	/**
	 * End-to-end: oversized source converts to a capped WebP; original kept.
	 *
	 * @since 2.0.0
	 */
	public function test_convert_image_downscales_oversized_output_to_cap(): void {
		if ( ! function_exists( 'imagewebp' ) ) {
			$this->markTestSkipped( 'GD WebP support is required.' );
		}

		$path  = $this->uploads_dir . '/oversized-cap.png';
		$image = imagecreatetruecolor( 1200, 900 );
		$color = imagecolorallocate( $image, 200, 100, 50 );
		imagefill( $image, 0, 0, $color );
		imagepng( $image, $path );
		Util::destroy_gd_image( $image );

		$this->prepare_wppo_output_dir();

		// Narrow override: force only the `wp_image_quality` core-handles probe
		// false, and restore the real builtin right after convert_image() so
		// function_exists() is not intercepted for the rest of the test. Brain
		// Monkey's Functions\when() discards Patchwork's handle, so the raw
		// handle is captured here and expired in the finally block.
		$function_exists_handle = \Patchwork\redefine(
			'function_exists',
			static function ( $function_name ) {
				return 'wp_image_quality' !== $function_name;
			}
		);

		$converter = $this->make_converter(
			array(
				'conversionFormat'        => 'webp',
				'skipSmallThresholdBytes' => 0,
				'maxLongestEdgePx'        => 600,
			)
		);
		try {
			$result = $converter->convert_image( $path, 'webp' );
			\Patchwork\restore( $function_exists_handle );

			$this->assertTrue( $result );

			// Original upload file is never modified.
			$orig = getimagesize( $path );
			$this->assertSame( 1200, $orig[0] );
			$this->assertSame( 900, $orig[1] );

			$webp_path = Img_Converter::get_img_path( $path, 'webp' );
			$this->assertFileExists( $webp_path );
			$out = getimagesize( $webp_path );
			$this->assertSame( 600, $out[0] );
			$this->assertSame( 450, $out[1] );
		} finally {
			\Patchwork\restore( $function_exists_handle );
			if ( file_exists( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
				unlink( $path );
			}
			$webp_path = Img_Converter::get_img_path( $path, 'webp' );
			if ( file_exists( $webp_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
				unlink( $webp_path );
			}
		}
	}

	/**
	 * Allowlist containment: uploads/wppo paths pass, while traversal,
	 * encoded traversal, NUL bytes, off-site URLs, relative paths, and
	 * outside-ABSPATH locations are refused (#1035).
	 *
	 * @since 2.0.0
	 */
	public function test_is_path_in_allowlist_rejects_traversal(): void {
		$this->assertTrue( Img_Converter::is_path_in_allowlist( $this->uploads_dir . '/allowlist.png' ) );
		$this->assertTrue( Img_Converter::is_path_in_allowlist( rtrim( WP_CONTENT_DIR, '/' ) . '/wppo/uploads/2026/08/allowlist.webp' ) );
		// Dotted filenames without a `..` segment stay valid.
		$this->assertTrue( Img_Converter::is_path_in_allowlist( $this->uploads_dir . '/my..photo.jpg' ) );

		$this->assertFalse( Img_Converter::is_path_in_allowlist( '' ) );
		$this->assertFalse( Img_Converter::is_path_in_allowlist( $this->uploads_dir . '/../secret.png' ) );
		$this->assertFalse( Img_Converter::is_path_in_allowlist( $this->uploads_dir . '/%2e%2e/secret.png' ) );
		$this->assertFalse( Img_Converter::is_path_in_allowlist( $this->uploads_dir . "/evil\0.png" ) );
		$this->assertFalse( Img_Converter::is_path_in_allowlist( '/etc/passwd' ) );
		$this->assertFalse( Img_Converter::is_path_in_allowlist( 'https://evil.example.com/img.png' ) );
		$this->assertFalse( Img_Converter::is_path_in_allowlist( 'relative/path/img.png' ) );
	}

	/**
	 * Strict delete allowlist: only uploads/wppo targets are unlinkable;
	 * same-prefix siblings, other ABSPATH locations, traversal, and
	 * off-site passthrough values are refused with the file intact (#1035).
	 *
	 * @since 2.0.0
	 */
	public function test_is_safe_delete_path_strict_allowlist(): void {
		$this->assertTrue( Img_Converter::is_safe_delete_path( rtrim( WP_CONTENT_DIR, '/' ) . '/wppo/uploads/2026/08/a.webp' ) );
		$this->assertTrue( Img_Converter::is_safe_delete_path( $this->uploads_dir . '/a.jpg' ) );

		// Same-prefix sibling of uploads must not pass the boundary check.
		$this->assertFalse( Img_Converter::is_safe_delete_path( rtrim( WP_CONTENT_DIR, '/' ) . '/uploads-evil/a.webp' ) );
		// Elsewhere in ABSPATH is readable but never unlinkable.
		$this->assertFalse( Img_Converter::is_safe_delete_path( rtrim( wp_normalize_path( ABSPATH ), '/' ) . '/wp-admin/admin.php' ) );
		$this->assertFalse( Img_Converter::is_safe_delete_path( $this->uploads_dir . '/../wp-config.php' ) );
		// Off-site passthrough from get_img_path() is never deletable.
		$this->assertFalse( Img_Converter::is_safe_delete_path( 'https://cdn.example.com/a.webp' ) );
		$this->assertFalse( Img_Converter::is_safe_delete_path( '' ) );
	}

	/**
	 * Channels-aware pixel budget: 40MP exceeds the fallback budget while
	 * a normal 12MP image fits, and corrupt (non-positive) dimensions are
	 * NOT an oversize skip so the caller records `failed` (#1035).
	 *
	 * @since 2.0.0
	 */
	public function test_exceeds_pixel_budget_channels_aware(): void {
		// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Test forces the unlimited-memory fallback path deterministically.
		$previous = ini_set( 'memory_limit', '-1' );
		try {
			$converter = $this->make_converter();
			$this->assertTrue( $converter->exceeds_pixel_budget( 8000, 5000, 4 ), '40MP exceeds the fallback budget' );
			$this->assertFalse( $converter->exceeds_pixel_budget( 4000, 3000, 3 ), '12MP fits the fallback budget' );
			$this->assertFalse( $converter->exceeds_pixel_budget( 0, 100 ), 'Corrupt zero dims are not an oversize skip' );
			$this->assertFalse( $converter->exceeds_pixel_budget( -5, 100 ), 'Negative dims are not an oversize skip' );
		} finally {
			if ( false !== $previous ) {
				// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Test restores the original memory limit.
				ini_set( 'memory_limit', (string) $previous );
			}
		}
	}

	/**
	 * Over-budget sources skip conversion fail-open: the original is served
	 * unoptimised and the queue entry is marked `skipped`, never fatal (#1035).
	 *
	 * @since 2.0.0
	 */
	public function test_convert_image_pixel_budget_skip_marks_skipped(): void {
		// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Test forces the unlimited-memory fallback path deterministically.
		$previous = ini_set( 'memory_limit', '-1' );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook_name, $value ) {
				if ( 'wppo_max_source_pixels' === $hook_name ) {
					return 1000;
				}
				return $value;
			}
		);
		$file = $this->create_sample_png( 'pixel-budget.png' );
		// Force the core-handles probe false so conversion reaches the
		// pixel-budget guard instead of the WP 6.7+ core skip (same
		// Patchwork pattern as the downscale test above).
		$function_exists_handle = \Patchwork\redefine(
			'function_exists',
			static function ( $function_name ) {
				return 'wp_image_quality' !== $function_name;
			}
		);
		try {
			$converter = $this->make_converter(
				array(
					'conversionFormat'        => 'webp',
					'skipSmallThresholdBytes' => 0,
				)
			);
			$this->assertFalse( $converter->convert_image( $file, 'webp' ) );
			\Patchwork\restore( $function_exists_handle );

			$this->assertFileExists( $file, 'Original must stay restorable' );

			$full_rel = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $file ) );
			$info     = Img_Converter::get_img_info();
			$this->assertContains( $full_rel, $info['skipped']['webp'] ?? array() );
		} finally {
			\Patchwork\restore( $function_exists_handle );
			if ( false !== $previous ) {
				// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Test restores the original memory limit.
				ini_set( 'memory_limit', (string) $previous );
			}
			if ( file_exists( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
				unlink( $file );
			}
		}
	}

	/**
	 * Traversal sources are refused before any decode: conversion returns
	 * false and records `failed` without touching the filesystem (#1035).
	 *
	 * @since 2.0.0
	 */
	public function test_convert_image_rejects_traversal_source(): void {
		$converter = $this->make_converter( array( 'conversionFormat' => 'webp' ) );

		$this->assertFalse( $converter->convert_image( $this->uploads_dir . '/../wp-config.php', 'webp' ) );
		$this->assertFalse( $converter->convert_image( 'https://evil.example.com/img.jpg', 'webp' ) );
	}
}
