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
}
