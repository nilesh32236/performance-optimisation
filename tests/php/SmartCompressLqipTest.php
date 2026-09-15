<?php
/**
 * Tests for size-compare smart compression + local LQIP placeholders (#1158).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Image_Optimisation;
use PerformanceOptimise\Inc\Img_Converter;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Size-compare discard and native-lazy LQIP placeholder tests.
 *
 * @package PerformanceOptimise\Tests
 */
class SmartCompressLqipTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Base options for converter/optimisation instances.
	 *
	 * @var array
	 */
	private array $options;

	/**
	 * Temporary uploads directory for fixtures.
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

		Util::clear_settings_cache();
		Image_Optimisation::clear_runtime_caches();
		$this->reset_converter_state();

		$this->options = array(
			'image_optimisation' => array(
				'convertImg'              => true,
				'conversionFormat'        => 'webp',
				'avifFirst'               => true,
				'smartQuality'            => true,
				'skipSmallThresholdBytes' => 0,
				'discardOversizedSibling' => true,
				'lazyLoadImages'          => true,
				'lazyLoadNative'          => true,
				'placeholderType'         => 'lqip',
				'lcpHeroPreload'          => false,
				'prioritizeLCPImages'     => false,
				'autoPreloadLCP'          => false,
				'fieldLcpOverride'        => false,
			),
		);

		$this->uploads_dir = rtrim( WP_CONTENT_DIR, '/' ) . '/uploads/2026/08';
		if ( ! is_dir( $this->uploads_dir ) ) {
			mkdir( $this->uploads_dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
		}

		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		// Disengage the salted-cache gates (issue #882) and pin the WP 7.1+
		// quality API: other test files in the same process may have
		// eval-declared these functions, so the plugin's function_exists()
		// guards would otherwise take the call path with no expectations.
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'wp_get_image_encode_quality' )->justReturn( 82 );
		Functions\when( 'wp_image_quality' )->justReturn( null );
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
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'path_is_absolute' )->justReturn( true );
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
		Functions\when( 'add_action' )->returnArg();
		Functions\when( 'wp_upload_dir' )->alias(
			function () {
				return array(
					'basedir' => wp_normalize_path( WP_CONTENT_DIR . '/uploads' ),
					'path'    => $this->uploads_dir,
				);
			}
		);
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
		$this->reset_converter_state();
		Image_Optimisation::clear_runtime_caches();
		Util::clear_settings_cache();
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Reset Img_Converter static caches between tests.
	 */
	private function reset_converter_state(): void {
		$reflected = new ReflectionClass( Img_Converter::class );

		foreach ( array( 'deferred_img_info', 'img_info_shutdown_registered', 'img_info_persisted' ) as $prop ) {
			if ( ! $reflected->hasProperty( $prop ) ) {
				continue;
			}
			$property = $reflected->getProperty( $prop );
			$property->setAccessible( true );
			$property->setValue( null, 'deferred_img_info' === $prop ? null : false );
		}
	}

	/**
	 * Write a fixture file of an exact byte size.
	 *
	 * @param string $name File name.
	 * @param int    $bytes Byte size.
	 * @return string Absolute path.
	 */
	private function make_sized_file( string $name, int $bytes ): string {
		$path = $this->uploads_dir . '/' . $name;
		file_put_contents( $path, str_repeat( 'x', $bytes ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		return $path;
	}

	/**
	 * Canonical defaults must carry the additive discard key.
	 */
	public function test_defaults_include_discard_oversized_sibling(): void {
		$defaults = Util::get_default_settings();

		$this->assertArrayHasKey( 'discardOversizedSibling', $defaults['image_optimisation'] );
		$this->assertTrue( $defaults['image_optimisation']['discardOversizedSibling'] );
	}

	/**
	 * An oversized sibling (larger than source) must be discarded.
	 */
	public function test_oversized_sibling_is_discarded(): void {
		$converter = new Img_Converter( $this->options );
		$source    = $this->make_sized_file( 'wppo-source.jpg', 100 );
		$sibling   = $this->make_sized_file( 'wppo-source.webp', 200 );

		$this->assertTrue( $converter->should_discard_oversized_sibling( $source, $sibling ) );
		$this->assertTrue( $converter->discard_oversized_sibling( $source, $sibling ) );
		$this->assertFileDoesNotExist( $sibling, 'Oversized sibling must be deleted' );
		$this->assertFileExists( $source, 'Source must be kept' );

		wp_delete_file( $source );
	}

	/**
	 * An equal-size sibling regresses bytes and must be discarded.
	 */
	public function test_equal_size_sibling_is_discarded(): void {
		$converter = new Img_Converter( $this->options );
		$source    = $this->make_sized_file( 'wppo-equal.jpg', 100 );
		$sibling   = $this->make_sized_file( 'wppo-equal.webp', 100 );

		$this->assertTrue( $converter->should_discard_oversized_sibling( $source, $sibling ) );

		wp_delete_file( $source );
		wp_delete_file( $sibling );
	}

	/**
	 * A smaller sibling must be kept.
	 */
	public function test_smaller_sibling_is_kept(): void {
		$converter = new Img_Converter( $this->options );
		$source    = $this->make_sized_file( 'wppo-keep.jpg', 200 );
		$sibling   = $this->make_sized_file( 'wppo-keep.webp', 100 );

		$this->assertFalse( $converter->should_discard_oversized_sibling( $source, $sibling ) );
		$this->assertFalse( $converter->discard_oversized_sibling( $source, $sibling ) );
		$this->assertFileExists( $sibling, 'Smaller sibling must be kept' );

		wp_delete_file( $source );
		wp_delete_file( $sibling );
	}

	/**
	 * Missing files fail open (never discard).
	 */
	public function test_missing_files_fail_open(): void {
		$converter = new Img_Converter( $this->options );

		$this->assertFalse( $converter->should_discard_oversized_sibling( $this->uploads_dir . '/missing.jpg', $this->uploads_dir . '/missing.webp' ) );
		$this->assertFalse( $converter->should_discard_oversized_sibling( '', '' ) );
	}

	/**
	 * Disabling the setting keeps siblings (fail-open opt-out).
	 */
	public function test_disabled_setting_keeps_sibling(): void {
		$options = $this->options;
		$options['image_optimisation']['discardOversizedSibling'] = false;
		$converter = new Img_Converter( $options );
		$source    = $this->make_sized_file( 'wppo-off.jpg', 100 );
		$sibling   = $this->make_sized_file( 'wppo-off.webp', 200 );

		$this->assertFalse( $converter->should_discard_oversized_sibling( $source, $sibling ) );
		$this->assertFalse( $converter->discard_oversized_sibling( $source, $sibling ) );
		$this->assertFileExists( $sibling );

		wp_delete_file( $source );
		wp_delete_file( $sibling );
	}

	/**
	 * The record helper discards oversized output and marks it skipped.
	 */
	public function test_record_encoded_sibling_discards_and_marks_skipped(): void {
		$converter = new Img_Converter( $this->options );
		$source    = $this->make_sized_file( 'wppo-record.jpg', 100 );
		$sibling   = $this->make_sized_file( 'wppo-record.webp', 200 );

		$method = new ReflectionMethod( Img_Converter::class, 'record_encoded_sibling' );
		$method->setAccessible( true );

		$success = true;
		$args    = array( $source, $sibling, 'webp', &$success );
		$method->invokeArgs( $converter, $args );

		$this->assertFalse( $success );
		$this->assertFileDoesNotExist( $sibling );

		$rel  = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $source ) );
		$info = Img_Converter::get_img_info();
		$this->assertContains( $rel, $info['skipped']['webp'] ?? array() );
		$this->assertNotContains( $rel, $info['completed']['webp'] ?? array() );

		wp_delete_file( $source );
	}

	/**
	 * The record helper keeps smaller output and marks it completed.
	 */
	public function test_record_encoded_sibling_completes_smaller_output(): void {
		$converter = new Img_Converter( $this->options );
		$source    = $this->make_sized_file( 'wppo-record-ok.jpg', 200 );
		$sibling   = $this->make_sized_file( 'wppo-record-ok.webp', 100 );

		$method = new ReflectionMethod( Img_Converter::class, 'record_encoded_sibling' );
		$method->setAccessible( true );

		$success = true;
		$args    = array( $source, $sibling, 'webp', &$success );
		$method->invokeArgs( $converter, $args );

		$this->assertTrue( $success );
		$this->assertFileExists( $sibling );

		$rel  = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $source ) );
		$info = Img_Converter::get_img_info();
		$this->assertContains( $rel, $info['completed']['webp'] ?? array() );

		wp_delete_file( $source );
		wp_delete_file( $sibling );
	}

	/**
	 * Smart quality stays within 1-100 and never rates AVIF above WebP.
	 */
	public function test_smart_quality_stays_sane(): void {
		$converter = new Img_Converter( $this->options );

		$avif = $converter->get_smart_quality( 'image/avif' );
		$webp = $converter->get_smart_quality( 'image/webp' );

		$this->assertGreaterThanOrEqual( 1, $avif );
		$this->assertLessThanOrEqual( 100, $avif );
		$this->assertLessThanOrEqual( $webp, $avif, 'AVIF quality must never exceed WebP quality' );
	}

	/**
	 * Seed placeholder data for a uploads-relative fixture URL.
	 *
	 * @param string $url Image URL.
	 * @return string The ABSPATH-relative path key used for lookup.
	 */
	private function seed_placeholder_data( string $url ): string {
		$local = Util::get_local_path( $url );
		$this->assertNotSame( '', $local );
		$rel = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $local ) );

		Img_Converter::set_img_info(
			array(
				'dominant_color' => array( $rel => '#aabbcc' ),
				'lqip'           => array( $rel => 'data:image/jpeg;base64,/9j/placeholder' ),
			)
		);

		return $rel;
	}

	/**
	 * Invoke the private native placeholder helper via reflection.
	 *
	 * @param Image_Optimisation $instance Instance under test.
	 * @param string             $url Image src URL.
	 * @param array              $exclude Exclusion list.
	 * @return array Attributes.
	 */
	private function native_attrs( Image_Optimisation $instance, string $url, array $exclude = array() ): array {
		$method = new ReflectionMethod( Image_Optimisation::class, 'get_native_lazy_placeholder_attrs' );
		$method->setAccessible( true );

		return $method->invoke( $instance, $url, $exclude, '', '' );
	}

	/**
	 * Non-hero lazy images get the local LQIP attribute (no src swap, no HTTP).
	 */
	public function test_native_lazy_emits_lqip_attr_for_non_hero(): void {
		$url = 'http://example.com/wp-content/uploads/2026/08/hero-below.jpg';
		$this->seed_placeholder_data( $url );

		$instance = new Image_Optimisation( $this->options );
		$attrs    = $this->native_attrs( $instance, $url );

		$this->assertSame( array( 'data-wppo-lqip' => '1' ), $attrs );
	}

	/**
	 * Dominant-color placeholders emit the wash attribute for non-hero images.
	 */
	public function test_native_lazy_emits_dominant_color_attr(): void {
		$options = $this->options;
		$options['image_optimisation']['placeholderType'] = 'dominant_color';
		$url = 'http://example.com/wp-content/uploads/2026/08/wash.jpg';
		$this->seed_placeholder_data( $url );

		$instance = new Image_Optimisation( $options );
		$attrs    = $this->native_attrs( $instance, $url );

		$this->assertSame( array( 'data-wppo-dominant-color' => '#aabbcc' ), $attrs );
	}

	/**
	 * The LCP hero is explicitly excluded from blur.
	 */
	public function test_native_lazy_excludes_lcp_hero_from_blur(): void {
		$url = 'http://example.com/wp-content/uploads/2026/08/lcp-hero.jpg';
		$this->seed_placeholder_data( $url );

		$instance = new Image_Optimisation( $this->options );
		$attrs    = $this->native_attrs( $instance, $url, array( $url ) );

		$this->assertSame( array(), $attrs, 'LCP hero must not receive blur attributes' );
	}

	/**
	 * The none placeholder type emits no attributes.
	 */
	public function test_native_lazy_none_type_emits_nothing(): void {
		$options = $this->options;
		$options['image_optimisation']['placeholderType'] = 'none';
		$url = 'http://example.com/wp-content/uploads/2026/08/plain.jpg';
		$this->seed_placeholder_data( $url );

		$instance = new Image_Optimisation( $options );
		$attrs    = $this->native_attrs( $instance, $url );

		$this->assertSame( array(), $attrs );
	}

	/**
	 * The hero matcher accepts exclusion entries and rejects other URLs.
	 */
	public function test_is_lcp_hero_url_matching(): void {
		$instance = new Image_Optimisation( $this->options );
		$method   = new ReflectionMethod( Image_Optimisation::class, 'is_lcp_hero_url' );
		$method->setAccessible( true );

		$hero = 'http://example.com/wp-content/uploads/2026/08/lcp-hero.jpg';

		$this->assertTrue( $method->invoke( $instance, $hero, array( $hero ), '', '' ) );
		$this->assertFalse( $method->invoke( $instance, $hero, array(), '', '' ) );
		$this->assertFalse( $method->invoke( $instance, '', array( $hero ), '', '' ) );
		$this->assertFalse(
			$method->invoke( $instance, 'http://example.com/wp-content/uploads/2026/08/other.jpg', array( $hero ), '', '' )
		);
	}
}
