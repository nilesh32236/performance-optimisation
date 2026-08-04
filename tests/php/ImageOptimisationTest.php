<?php
/**
 * Tests for Image_Optimisation class.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Image_Optimisation;
use Brain\Monkey\Functions;

/**
 * Tests for Image_Optimisation class.
 *
 * @package PerformanceOptimise\Tests
 */
class ImageOptimisationTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Default options for testing.
	 *
	 * @var array
	 */
	private array $default_options;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->default_options = array(
			'image_optimisation' => array(
				'lazyLoadImages'  => true,
				'lazyLoadVideos'  => true,
				'placeholderType' => 'svg',
				'autoPreloadLCP'  => false,
				'convertToWebp'   => true,
				'convertToAvif'   => false,
				'lazyLoadNative'  => false,
			),
		);
	}

	/**
	 * Test that constructor stores options.
	 */
	public function test_constructor_stores_options(): void {
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );

		$image_opt = new Image_Optimisation( $this->default_options );
		$this->assertNotNull( $image_opt );
	}

	/**
	 * Test lazy load attribute injection in HTML.
	 */
	public function test_lazy_load_attribute_injection(): void {
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );
		Functions\stubs(
			array(
				'esc_attr',
				'esc_url',
				'esc_html',
				'esc_textarea',
				'sanitize_text_field',
				'wp_unslash',
				'trailingslashit',
				'wp_parse_url',
				'content_url',
				'home_url',
				'is_admin',
				'is_feed',
				'is_preview',
				'is_embed',
				'wp_doing_ajax',
				'apply_filters',
				'wp_strip_all_tags',
				'wp_check_filetype',
				'wp_get_attachment_image_src',
				'get_post_meta',
			)
		);
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'trailingslashit' )->returnArg();
		Functions\when( 'wp_parse_url' )->returnArg( 1 );
		Functions\when( 'content_url' )->justReturn( 'http://example.com/wp-content' );
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_preview' )->justReturn( false );
		Functions\when( 'is_embed' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'wp_check_filetype' )->justReturn(
			array(
				'ext'  => 'jpg',
				'type' => 'image/jpeg',
			)
		);
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_ID' )->justReturn( 0 );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array() );

		$image_opt = new Image_Optimisation( $this->default_options );

		$html   = '<img src="test.jpg" alt="test"/>';
		$result = $image_opt->add_delay_load_img( $html );
		$this->assertStringContainsString( 'data-src', $result );
	}

	/**
	 * Test that expected methods exist on the class.
	 */
	public function test_methods_exist(): void {
		$this->assertTrue( method_exists( Image_Optimisation::class, 'lazy_load_videos' ) );
		$this->assertTrue( method_exists( Image_Optimisation::class, 'preload_images' ) );
		$this->assertTrue( method_exists( Image_Optimisation::class, 'add_delay_load_img' ) );
		$this->assertTrue( method_exists( Image_Optimisation::class, 'prioritize_lcp_in_buffer' ) );
		$this->assertTrue( method_exists( Image_Optimisation::class, 'process_img_tag' ) );
	}

	/**
	 * Test that prioritize_lcp_in_buffer leaves the buffer unchanged when the toggle is off.
	 */
	public function test_prioritize_lcp_in_buffer_noop_when_disabled(): void {
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );

		$image_opt = new Image_Optimisation( $this->default_options );

		$html   = '<img src="hero.jpg" loading="lazy" />';
		$result = $image_opt->prioritize_lcp_in_buffer( $html, $html );
		$this->assertSame( $html, $result );
	}

	/**
	 * Test that prioritize_lcp_in_buffer gracefully no-ops when the HTML API is unavailable.
	 */
	public function test_prioritize_lcp_in_buffer_graceful_without_html_api(): void {
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$options = $this->default_options;
		$options['image_optimisation']['prioritizeLCPImages'] = true;

		$image_opt = new Image_Optimisation( $options );

		$html   = '<img src="hero.jpg" loading="lazy" />';
		$result = $image_opt->prioritize_lcp_in_buffer( $html, $html );
		$this->assertSame( $html, $result );
	}

	/**
	 * Test that get_current_lcp_url resolves the LCP URL from singular post meta.
	 */
	public function test_get_current_lcp_url_from_post_meta(): void {
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 42 );
		Functions\when( 'is_front_page' )->justReturn( false );

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 42, '_wppo_lcp_image_url_mobile', true )
			->andReturn( 'https://example.com/wp-content/uploads/hero.jpg' );

		$image_opt = new Image_Optimisation( $this->default_options );

		$reflection = new \ReflectionMethod( Image_Optimisation::class, 'get_current_lcp_url' );
		$reflection->setAccessible( true );

		$this->assertSame( 'https://example.com/wp-content/uploads/hero.jpg', $reflection->invoke( $image_opt ) );
	}

	/**
	 * Test that get_current_lcp_url falls back to the front-page option.
	 */
	public function test_get_current_lcp_url_from_front_page_option(): void {
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'get_post_meta' )->justReturn( '' );

		Functions\expect( 'get_option' )
			->once()
			->with( 'wppo_front_page_lcp_mobile', '' )
			->andReturn( 'https://example.com/wp-content/uploads/front.jpg' );

		$image_opt = new Image_Optimisation( $this->default_options );

		$reflection = new \ReflectionMethod( Image_Optimisation::class, 'get_current_lcp_url' );
		$reflection->setAccessible( true );

		$this->assertSame( 'https://example.com/wp-content/uploads/front.jpg', $reflection->invoke( $image_opt ) );
	}

	/**
	 * Test that get_current_lcp_url returns an empty string when no LCP data is stored.
	 */
	public function test_get_current_lcp_url_returns_empty_when_absent(): void {
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'get_transient' )->justReturn( '' );
		Functions\when( 'untrailingslashit' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'add_query_arg' )->justReturn( 'http://example.com/sample-page/' );
		Functions\when( 'home_url' )->justReturn( 'http://example.com/sample-page/' );

		global $wp;
		$wp          = new \stdClass();
		$wp->request = 'sample-page';

		$image_opt = new Image_Optimisation( $this->default_options );

		$reflection = new \ReflectionMethod( Image_Optimisation::class, 'get_current_lcp_url' );
		$reflection->setAccessible( true );

		$this->assertSame( '', $reflection->invoke( $image_opt ) );
	}

	/**
	 * Stub the WP functions used to resolve the current LCP URL.
	 *
	 * @param string $lcp_url The LCP URL the transient lookup should return.
	 */
	private function stub_lcp_resolution( string $lcp_url ): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'get_transient' )->justReturn( $lcp_url );
		Functions\when( 'untrailingslashit' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'add_query_arg' )->returnArg( 2 );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
	}

	/**
	 * Build an Image_Optimisation instance with the LCP prioritization feature enabled.
	 *
	 * @param array $overrides Option overrides merged over the defaults.
	 * @return Image_Optimisation
	 */
	private function make_lcp_enabled_instance( array $overrides = array() ): Image_Optimisation {
		$options                       = $this->default_options;
		$options['image_optimisation'] = array_merge( $options['image_optimisation'], $overrides );
		$options['image_optimisation']['prioritizeLCPImages'] = true;
		return new Image_Optimisation( $options );
	}

	/**
	 * Test that loading="lazy" is removed only from the first N images.
	 */
	public function test_prioritize_lcp_in_buffer_unlazyloads_first_images(): void {
		require_once __DIR__ . '/stubs/wp-html-api.php';
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$this->stub_lcp_resolution( 'https://example.com/wp-content/uploads/hero.jpg' );

		$image_opt = $this->make_lcp_enabled_instance( array( 'excludeFirstImages' => 2 ) );

		$html   = '<img src="https://example.com/a.jpg" loading="lazy" /><img src="https://example.com/b.jpg" loading="lazy" /><img src="https://example.com/c.jpg" loading="lazy" />';
		$result = $image_opt->prioritize_lcp_in_buffer( $html, $html );

		$this->assertSame( 1, substr_count( $result, 'loading="lazy"' ) );
		$this->assertStringContainsString( '<img src="https://example.com/a.jpg" />', $result );
		$this->assertStringContainsString( '<img src="https://example.com/b.jpg" />', $result );
		$this->assertStringContainsString( '<img src="https://example.com/c.jpg" loading="lazy"', $result );
	}

	/**
	 * Test that fetchpriority="high" is stamped only on the image matching the LCP URL.
	 */
	public function test_prioritize_lcp_in_buffer_stamps_lcp_image_only(): void {
		require_once __DIR__ . '/stubs/wp-html-api.php';
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$this->stub_lcp_resolution( 'https://example.com/wp-content/uploads/hero.jpg' );

		$image_opt = $this->make_lcp_enabled_instance();

		$html   = '<img src="https://example.com/logo.jpg" /><img src="https://example.com/wp-content/uploads/hero.jpg" />';
		$result = $image_opt->prioritize_lcp_in_buffer( $html, $html );

		$this->assertSame( 1, substr_count( $result, 'fetchpriority="high"' ) );
		$this->assertStringContainsString( '<img src="https://example.com/logo.jpg" />', $result );
		$this->assertStringContainsString( '<img src="https://example.com/wp-content/uploads/hero.jpg" fetchpriority="high"', $result );
	}

	/**
	 * Test that an existing fetchpriority is preserved and the LCP image is un-lazy-loaded.
	 */
	public function test_prioritize_lcp_in_buffer_preserves_existing_fetchpriority(): void {
		require_once __DIR__ . '/stubs/wp-html-api.php';
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$this->stub_lcp_resolution( 'https://example.com/wp-content/uploads/hero.jpg' );

		$image_opt = $this->make_lcp_enabled_instance();

		$html   = '<img src="https://example.com/wp-content/uploads/hero.jpg" fetchpriority="low" loading="lazy" />';
		$result = $image_opt->prioritize_lcp_in_buffer( $html, $html );

		$this->assertStringContainsString( 'fetchpriority="low"', $result );
		$this->assertStringNotContainsString( 'fetchpriority="high"', $result );
		$this->assertStringNotContainsString( 'loading="lazy"', $result );
	}

	/**
	 * Test that a root-relative DOM src matches an absolute stored LCP URL.
	 */
	public function test_prioritize_lcp_in_buffer_matches_relative_url(): void {
		require_once __DIR__ . '/stubs/wp-html-api.php';
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$this->stub_lcp_resolution( 'https://example.com/wp-content/uploads/hero.jpg' );

		$image_opt = $this->make_lcp_enabled_instance();

		$html   = '<img src="/wp-content/uploads/hero.jpg" />';
		$result = $image_opt->prioritize_lcp_in_buffer( $html, $html );

		$this->assertStringContainsString( 'fetchpriority="high"', $result );
	}

	/**
	 * Test that an LCP URL present in a srcset candidate is matched.
	 */
	public function test_prioritize_lcp_in_buffer_matches_srcset_candidate(): void {
		require_once __DIR__ . '/stubs/wp-html-api.php';
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$this->stub_lcp_resolution( 'https://example.com/wp-content/uploads/hero.jpg' );

		$image_opt = $this->make_lcp_enabled_instance();

		$html   = '<img src="https://example.com/thumb.jpg" srcset="https://example.com/wp-content/uploads/hero.jpg 1024w, https://example.com/wp-content/uploads/hero-1024x1024.jpg 2048w" />';
		$result = $image_opt->prioritize_lcp_in_buffer( $html, $html );

		$this->assertStringContainsString( 'fetchpriority="high"', $result );
	}

	/**
	 * Test that the WP 6.9+ token-streaming path stamps fetchpriority="high" at most once
	 * even when the same LCP URL appears in multiple <img> tags.
	 */
	public function test_prioritize_lcp_in_buffer_wp69_stamps_lcp_once(): void {
		require_once __DIR__ . '/stubs/wp-html-api.php';
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$this->stub_lcp_resolution( 'https://example.com/wp-content/uploads/hero.jpg' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.9' );

		$image_opt = $this->make_lcp_enabled_instance();

		$html   = '<img src="https://example.com/logo.jpg" /><img src="https://example.com/wp-content/uploads/hero.jpg" /><img src="https://example.com/wp-content/uploads/hero.jpg" />';
		$result = $image_opt->prioritize_lcp_in_buffer( $html, $html );

		$this->assertSame( 1, substr_count( $result, 'fetchpriority="high"' ) );
	}

	/**
	 * Test that the client-side MIME override replaces core's default list.
	 */
	public function test_client_side_mime_override_replaces_default_list(): void {
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );
		Functions\when( 'sanitize_text_field' )->returnArg();

		$options = $this->default_options;
		$options['image_optimisation']['clientSideMimeTypeOverride'] = true;
		$options['image_optimisation']['clientSideMimeTypes']        = array( 'image/jpeg', 'image/webp', 'image/avif', 'image/jpeg' );

		$image_opt = new Image_Optimisation( $options );

		$core = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' );
		$this->assertSame( array( 'image/jpeg', 'image/webp', 'image/avif' ), $image_opt->filter_client_side_supported_mime_types( $core ) );
	}

	/**
	 * Test that an empty or non-array client-side MIME setting keeps core's
	 * default list untouched (graceful degradation).
	 */
	public function test_client_side_mime_override_empty_keeps_core_list(): void {
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );
		Functions\when( 'sanitize_text_field' )->returnArg();

		$options = $this->default_options;
		$options['image_optimisation']['clientSideMimeTypeOverride'] = true;
		$options['image_optimisation']['clientSideMimeTypes']        = array();

		$image_opt = new Image_Optimisation( $options );

		$core = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' );
		$this->assertSame( $core, $image_opt->filter_client_side_supported_mime_types( $core ) );
	}
}
