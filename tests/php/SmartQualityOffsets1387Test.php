<?php
/**
 * Tests for size/role-aware smart quality offsets + hero-first queue (#1387).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Img_Converter;
use Brain\Monkey\Functions;

/**
 * Covers offset boundaries, ±10 clamp, filter override paths, hero
 * delimiter matching, ordering stability, and fail-open behaviour.
 *
 * @package PerformanceOptimise\Tests
 */
class SmartQualityOffsets1387Test extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Base options for converter instances.
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

		$this->options = array(
			'image_optimisation' => array(
				'smartQuality' => true,
			),
		);

		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'wp_get_image_encode_quality' )->justReturn( 82 );
		Functions\when( 'wp_image_quality' )->justReturn( null );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return $value;
			}
		);
		Functions\when( 'get_attached_file' )->justReturn( '' );
	}

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Size offsets follow the documented boundary table.
	 */
	public function test_size_offset_boundaries(): void {
		$this->assertSame( 0, Img_Converter::get_smart_quality_size_offset( array() ) );
		$this->assertSame(
			0,
			Img_Converter::get_smart_quality_size_offset(
				array(
					'width'  => 0,
					'height' => 100,
				)
			)
		);
		$this->assertSame(
			-8,
			Img_Converter::get_smart_quality_size_offset(
				array(
					'width'  => 100,
					'height' => 100,
				)
			)
		);
		$this->assertSame(
			-8,
			Img_Converter::get_smart_quality_size_offset(
				array(
					'width'  => 150,
					'height' => 100,
				)
			)
		);
		$this->assertSame(
			-5,
			Img_Converter::get_smart_quality_size_offset(
				array(
					'width'  => 300,
					'height' => 200,
				)
			)
		);
		$this->assertSame(
			-3,
			Img_Converter::get_smart_quality_size_offset(
				array(
					'width'  => 768,
					'height' => 500,
				)
			)
		);
		$this->assertSame(
			0,
			Img_Converter::get_smart_quality_size_offset(
				array(
					'width'  => 1600,
					'height' => 900,
				)
			)
		);
		$this->assertSame(
			2,
			Img_Converter::get_smart_quality_size_offset(
				array(
					'width'  => 2000,
					'height' => 1200,
				)
			)
		);
	}

	/**
	 * Hero tokens match on delimiters; recovery/discover do not false-positive.
	 */
	public function test_hero_candidate_delimiter_matching(): void {
		$this->assertTrue( Img_Converter::is_hero_candidate_image( '/uploads/hero.jpg' ) );
		$this->assertTrue( Img_Converter::is_hero_candidate_image( '/uploads/home-hero-banner.jpg' ) );
		$this->assertTrue( Img_Converter::is_hero_candidate_image( '/uploads/cover_photo.jpg' ) );
		$this->assertTrue( Img_Converter::is_hero_candidate_image( '/uploads/featured.image.png' ) );
		$this->assertTrue( Img_Converter::is_hero_candidate_image( '/uploads/site-lcp.webp' ) );
		$this->assertFalse( Img_Converter::is_hero_candidate_image( '/uploads/recovery.jpg' ) );
		$this->assertFalse( Img_Converter::is_hero_candidate_image( '/uploads/discover.png' ) );
		$this->assertFalse( Img_Converter::is_hero_candidate_image( '/uploads/photo.jpg' ) );
		$this->assertFalse( Img_Converter::is_hero_candidate_image( '' ) );
	}

	/**
	 * Role offsets: hero +5, thumbnail-class -5, else 0.
	 */
	public function test_role_offset_values(): void {
		$this->assertSame( 5, Img_Converter::get_smart_quality_role_offset( '/uploads/hero.jpg' ) );
		$this->assertSame( 0, Img_Converter::get_smart_quality_role_offset( '/uploads/recovery.jpg' ) );
		$this->assertSame( -5, Img_Converter::get_smart_quality_role_offset( '/uploads/thumb-photo.jpg' ) );
		$this->assertSame( -5, Img_Converter::get_smart_quality_role_offset( '/uploads/photo-150x150.jpg' ) );
		$this->assertSame( 0, Img_Converter::get_smart_quality_role_offset( '/uploads/photo.jpg' ) );
		$this->assertSame( 0, Img_Converter::get_smart_quality_role_offset( '' ) );
	}

	/**
	 * The single choke point clamps inputs and the total delta to ±10.
	 */
	public function test_apply_offsets_clamps_total_delta(): void {
		$this->assertSame( 92, Img_Converter::apply_smart_quality_offsets( 82, 10, 10 ) );
		$this->assertSame( 72, Img_Converter::apply_smart_quality_offsets( 82, -10, -10 ) );
		$this->assertSame( 92, Img_Converter::apply_smart_quality_offsets( 82, 50, 50 ) );
		$this->assertSame( 100, Img_Converter::apply_smart_quality_offsets( 95, 10, 10 ) );
		$this->assertSame( 1, Img_Converter::apply_smart_quality_offsets( 5, -10, -10 ) );
	}

	/**
	 * End-to-end smart quality stays within ±10 of the flat base.
	 */
	public function test_get_smart_quality_bounded_delta(): void {
		$converter = new Img_Converter( $this->options );

		$small_thumb = $converter->get_smart_quality(
			'image/webp',
			array(
				'width'  => 100,
				'height' => 100,
			),
			'/uploads/thumb-photo.jpg'
		);
		$this->assertGreaterThanOrEqual( 72, $small_thumb );
		$this->assertLessThanOrEqual( 92, $small_thumb );

		$hero_large = $converter->get_smart_quality(
			'image/webp',
			array(
				'width'  => 2000,
				'height' => 1200,
			),
			'/uploads/hero.jpg'
		);
		$this->assertGreaterThanOrEqual( 72, $hero_large );
		$this->assertLessThanOrEqual( 92, $hero_large );

		// Derived `-WxH` suffix feeds the size offset when $size is empty.
		// 150x150 => size -8, sub-size role -5, total -13 clamped to -10.
		$suffixed = $converter->get_smart_quality( 'image/webp', array(), '/uploads/photo-150x150.jpg' );
		$this->assertSame( 72, $suffixed );
	}

	/**
	 * A valid wppo_smart_quality_value return wins outright and receives
	 * the effective size plus source path.
	 */
	public function test_filter_override_wins_with_effective_args(): void {
		$captured = null;
		Functions\when( 'has_filter' )->alias(
			static function ( $hook ) {
				return 'wppo_smart_quality_value' === $hook;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, $mime = null, $size = null, $source = null ) use ( &$captured ) {
				if ( 'wppo_smart_quality_value' === $hook ) {
					$captured = array( $value, $mime, $size, $source );
					return 70;
				}
				return $value;
			}
		);

		$converter = new Img_Converter( $this->options );
		$this->assertSame( 70, $converter->get_smart_quality( 'image/webp', array(), '/uploads/photo-300x200.jpg' ) );
		$this->assertSame( 82, $captured[0] );
		$this->assertSame( 'image/webp', $captured[1] );
		$this->assertSame(
			array(
				'width'  => 300,
				'height' => 200,
			),
			$captured[2]
		);
		$this->assertSame( '/uploads/photo-300x200.jpg', $captured[3] );
	}

	/**
	 * An invalid wppo_smart_quality_value return fails open to the flat base.
	 */
	public function test_invalid_filter_fails_open_to_flat_base(): void {
		Functions\when( 'has_filter' )->alias(
			static function ( $hook ) {
				return 'wppo_smart_quality_value' === $hook;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wppo_smart_quality_value' === $hook ) {
					return array( 'bogus' );
				}
				return $value;
			}
		);

		$converter = new Img_Converter( $this->options );
		$this->assertSame( 82, $converter->get_smart_quality( 'image/webp', array(), '/uploads/photo.jpg' ) );
	}

	/**
	 * Core jpeg_quality/wp_editor_set_quality filters suppress offsets verbatim.
	 */
	public function test_core_quality_filter_suppresses_offsets(): void {
		Functions\when( 'has_filter' )->alias(
			static function ( $hook ) {
				return 'jpeg_quality' === $hook;
			}
		);

		$converter = new Img_Converter( $this->options );
		// Hero + large would otherwise offset; suppressed path returns flat base.
		$this->assertSame(
			82,
			$converter->get_smart_quality(
				'image/webp',
				array(
					'width'  => 2000,
					'height' => 1200,
				),
				'/uploads/hero.jpg'
			)
		);
	}

	/**
	 * Hero candidates move to the front stably; others keep order.
	 */
	public function test_order_queue_hero_first_stable(): void {
		$files = array(
			11 => '/uploads/photo-a.jpg',
			12 => '/uploads/recovery.jpg',
			13 => '/uploads/hero.jpg',
			14 => '/uploads/photo-b.jpg',
			15 => '/uploads/site-banner.png',
		);
		Functions\when( 'get_attached_file' )->alias(
			static function ( $id ) use ( $files ) {
				return $files[ (int) $id ] ?? '';
			}
		);

		$this->assertSame( array( 13, 15, 11, 12, 14 ), Img_Converter::order_queue_hero_first( array( 11, 12, 13, 14, 15 ) ) );
		$this->assertSame( array( 11, 12 ), Img_Converter::order_queue_hero_first( array( 11, 12 ) ) );
	}

	/**
	 * Ordering fails open to scan order when get_attached_file() is unavailable.
	 */
	public function test_order_queue_fail_open_without_get_attached_file(): void {
		$handle = \Patchwork\redefine(
			'function_exists',
			static function ( $function_name ) {
				return 'get_attached_file' !== $function_name;
			}
		);
		try {
			$this->assertSame( array( 1, 2, 3 ), Img_Converter::order_queue_hero_first( array( 1, 2, 3 ) ) );
		} finally {
			\Patchwork\restore( $handle );
		}
	}
}
