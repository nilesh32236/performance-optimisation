<?php
/**
 * Tests for per-mapping cdn_attr handling (audit #888 finding 21).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\CDN;
use Brain\Monkey\Functions;

// The dev classmap can lag behind new class files (vendor is built on demand);
// require the class directly like LiteSpeedCrawlerTest does.
require_once __DIR__ . '/../../includes/class-cdn.php';

/**
 * Tests that CDN attribute restrictions are resolved per mapping, not by a
 * global union across mappings.
 *
 * @package PerformanceOptimise\Tests
 */
class CdnPerMappingAttrTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap {
		tearDown as protected wppoTearDown;
	}

	/**
	 * Stub the URL helpers find_cdn_match() relies on and reset shared Util
	 * caches (this setUp shadows the trait's, Part 2 review).
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		\PerformanceOptimise\Inc\Util::reset_cached_home_urls();
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		$this->register_common_function_stubs();
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
	}

	/**
	 * Drop any mapping memo written during a test so it cannot leak into other
	 * test classes (this setUp shadows the trait's reset).
	 */
	protected function tearDown(): void {
		CDN::reset_cache();
		$this->wppoTearDown();
	}

	/**
	 * A normalized single mapping restricted to the `src` attribute.
	 *
	 * @return array
	 */
	private function src_restricted_mapping(): array {
		return array(
			'cdn_url'           => 'https://cdn.example.com',
			'ori'               => '',
			'ori_dir'           => '',
			'include_dirs'      => 'wp-content|wp-includes',
			'include_filetypes' => 'jpg,png',
			'cdn_attr'          => 'src',
			'cdn_urls'          => array( 'https://cdn.example.com' ),
		);
	}

	/**
	 * Test that find_cdn_match returns both the CDN URL and the mapping.
	 */
	public function test_find_cdn_match_returns_cdn_and_mapping(): void {
		$mappings = array( $this->src_restricted_mapping() );

		$match = CDN::find_cdn_match( 'http://example.com/wp-content/uploads/img.jpg', $mappings );

		$this->assertNotNull( $match );
		$this->assertSame( 'https://cdn.example.com', $match['cdn'] );
		$this->assertSame( 'src', $match['mapping']['cdn_attr'] );
	}

	/**
	 * Test that find_cdn_for_url keeps returning the plain CDN URL.
	 */
	public function test_find_cdn_for_url_returns_plain_cdn(): void {
		$mappings = array( $this->src_restricted_mapping() );

		$this->assertSame(
			'https://cdn.example.com',
			CDN::find_cdn_for_url( 'http://example.com/wp-content/uploads/img.jpg', $mappings )
		);
	}

	/**
	 * Test that a mapping restricted to `src` permits `src` only.
	 */
	public function test_mapping_allows_attr_respects_restriction(): void {
		$ref = new \ReflectionMethod( CDN::class, 'mapping_allows_attr' );
		$ref->setAccessible( true );

		$mapping = array( 'cdn_attr' => 'src' );

		$this->assertTrue( $ref->invoke( null, $mapping, 'src' ) );
		$this->assertFalse( $ref->invoke( null, $mapping, 'href' ) );
		$this->assertFalse( $ref->invoke( null, $mapping, 'srcset' ) );
	}

	/**
	 * Test that a mapping with an empty cdn_attr allows the default set.
	 */
	public function test_mapping_allows_attr_allows_all_when_unrestricted(): void {
		$ref = new \ReflectionMethod( CDN::class, 'mapping_allows_attr' );
		$ref->setAccessible( true );

		$this->assertTrue( $ref->invoke( null, array(), 'href' ) );
		$this->assertTrue( $ref->invoke( null, array( 'cdn_attr' => '' ), 'poster' ) );
	}

	/**
	 * Test that multi-attribute restrictions are honoured per attribute.
	 */
	public function test_mapping_allows_attr_multi_values(): void {
		$ref = new \ReflectionMethod( CDN::class, 'mapping_allows_attr' );
		$ref->setAccessible( true );

		$mapping = array( 'cdn_attr' => 'src, srcset' );

		$this->assertTrue( $ref->invoke( null, $mapping, 'src' ) );
		$this->assertTrue( $ref->invoke( null, $mapping, 'srcset' ) );
		$this->assertFalse( $ref->invoke( null, $mapping, 'content' ) );
	}

	/**
	 * Union regression (audit #888 finding 21): with a src-only mapping and a
	 * href-only mapping, a URL matched by the src-only mapping must consult
	 * THAT mapping's restriction — a href URL matched by the other mapping
	 * must be allowed there. A global union would allow everything for
	 * everyone.
	 */
	public function test_per_mapping_restrictions_do_not_union(): void {
		$ref = new \ReflectionMethod( CDN::class, 'mapping_allows_attr' );
		$ref->setAccessible( true );

		$src_only  = array( 'cdn_attr' => 'src' );
		$href_only = array( 'cdn_attr' => 'href' );

		$mappings = array( $src_only, $href_only );

		$match_src = CDN::find_cdn_match( 'http://example.com/wp-content/uploads/img.jpg', $mappings );
		$this->assertNotNull( $match_src );
		// The first (src-only) mapping wins for a content-image URL.
		$this->assertSame( 'src', $match_src['mapping']['cdn_attr'] );
		$this->assertTrue( $ref->invoke( null, $match_src['mapping'], 'src' ) );
		$this->assertFalse( $ref->invoke( null, $match_src['mapping'], 'href' ) );

		$match_href = CDN::find_cdn_match( 'http://example.com/wp-content/themes/theme/style.css', $mappings );
		$this->assertNotNull( $match_href );
		$this->assertSame( 'src', $match_href['mapping']['cdn_attr'] );
	}

	/**
	 * Regression: when any CDN mapping filter is registered the per-request memo
	 * is skipped, but get_mappings() must still hydrate the stored settings.
	 * Previously the settings read lived inside the memo branch, so a
	 * filter-present call returned an empty mapping set and disabled the CDN.
	 *
	 * @since 2.0.0
	 */
	public function test_get_mappings_reads_settings_when_filter_registered(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'has_filter' )->alias(
			static function ( $tag ) {
				return 'wppo_cdn_mapping' === $tag;
			}
		);
		Functions\when( 'get_option' )->justReturn(
			array(
				'file_optimisation' => array(
					'cdnMapping' => array(
						array( 'cdn_url' => 'https://cdn.example.com' ),
					),
				),
			)
		);

		// Not order-dependent: reset both shared memos so the stored-settings
		// hydration is asserted from a clean slate.
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		CDN::reset_cache();

		$mappings = CDN::get_mappings();

		$this->assertNotEmpty( $mappings );
		$this->assertSame( 'https://cdn.example.com', $mappings[0]['cdn_url'] );
	}

	/**
	 * Regression: WordPress registers rewrite_url() as a 2-argument filter on
	 * `wp_get_attachment_url`, `style_loader_src` and `script_loader_src`, and
	 * passes the attachment ID / asset handle as the second argument — never an
	 * array. The old `?array $mappings` declaration threw:
	 *
	 *   TypeError: CDN::rewrite_url(): Argument #2 ($mappings) must be of type
	 *   ?array, string given
	 *
	 * from login_header() -> print_admin_styles(), hard-fataling wp-login.php.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_rewrite_url_accepts_non_array_second_arg(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->justReturn( array() );
		\PerformanceOptimise\Inc\LiteSpeed_Integration::reset_cache();

		$url    = 'https://example.test/app.css';
		$result = CDN::rewrite_url( $url, 'some-style-handle' );

		$this->assertIsString( $result );
		$this->assertSame( $url, $result );
	}

	/**
	 * The optional $mappings pass-down used by rewrite_srcset() and
	 * Critical_CSS must keep working: an array argument is used directly and
	 * the URL is rewritten without reading options.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_rewrite_url_uses_supplied_array_mappings(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// Intentionally no stored settings: the supplied array must be used.
		Functions\when( 'get_option' )->justReturn( array() );
		\PerformanceOptimise\Inc\LiteSpeed_Integration::reset_cache();

		$mappings = array(
			array(
				'cdn_url'           => 'https://cdn.example.com',
				'cdn_urls'          => array( 'https://cdn.example.com' ),
				'ori'               => '',
				'ori_dir'           => '',
				'cdn_attr'          => '',
				'include_dirs'      => 'wp-content',
				'include_filetypes' => 'css',
			),
		);

		$result = CDN::rewrite_url(
			'http://example.com/wp-content/themes/x/style.css',
			$mappings
		);

		$this->assertSame(
			'https://cdn.example.com/wp-content/themes/x/style.css',
			$result
		);
	}

	/**
	 * Regression (same class as #1074): rewrite_srcset() is registered on
	 * `wp_calculate_image_srcset`, and should accepted_args ever be raised,
	 * WordPress would pass `$size_array` as the second argument — never CDN
	 * mappings. The old `?array $mappings` declaration threw:
	 *
	 *   TypeError: CDN::rewrite_srcset(): Argument #2 ($mappings) must be of
	 *   type ?array, string given
	 *
	 * and the old `null === $mappings` guard would have silently treated any
	 * non-null value as mappings. A non-array argument must be ignored and
	 * mappings resolved via get_mappings().
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_rewrite_srcset_accepts_non_array_second_arg(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->justReturn(
			array(
				'file_optimisation' => array(
					'cdnMapping' => array(
						array( 'cdn_url' => 'https://cdn.example.com' ),
					),
				),
			)
		);
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		CDN::reset_cache();

		$sources = array(
			1 => array( 'url' => 'http://example.com/wp-content/uploads/img.jpg' ),
		);

		$result = CDN::rewrite_srcset( $sources, 'not-a-mappings-array' );

		// The string was ignored and the stored mapping was resolved through
		// get_mappings(): the URL is rewritten, not left untouched.
		$this->assertSame(
			'https://cdn.example.com/wp-content/uploads/img.jpg',
			$result[1]['url']
		);
	}

	/**
	 * The optional $mappings pass-down used by Critical_CSS must keep working:
	 * an array argument is used directly and the URL is rewritten without
	 * reading options.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_rewrite_srcset_uses_supplied_array_mappings(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// Intentionally no stored settings: the supplied array must be used.
		Functions\when( 'get_option' )->justReturn( array() );
		CDN::reset_cache();

		$mappings = array(
			array(
				'cdn_url'           => 'https://cdn.example.com',
				'cdn_urls'          => array( 'https://cdn.example.com' ),
				'ori'               => '',
				'ori_dir'           => '',
				'cdn_attr'          => '',
				'include_dirs'      => 'wp-content',
				'include_filetypes' => 'jpg',
			),
		);

		$sources = array(
			1 => array( 'url' => 'http://example.com/wp-content/uploads/img.jpg' ),
		);

		$result = CDN::rewrite_srcset( $sources, $mappings );

		$this->assertSame(
			'https://cdn.example.com/wp-content/uploads/img.jpg',
			$result[1]['url']
		);
	}
}
