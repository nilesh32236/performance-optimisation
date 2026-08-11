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
	 * Test that the client-side MIME override replaces core's default list,
	 * intersecting away any format core does not support client-side.
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
	 * Test that a selection containing formats core cannot process client-side
	 * is intersected down to core's authoritative list.
	 */
	public function test_client_side_mime_override_strips_unsupported_formats(): void {
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );
		Functions\when( 'sanitize_text_field' )->returnArg();

		$options = $this->default_options;
		$options['image_optimisation']['clientSideMimeTypeOverride'] = true;
		$options['image_optimisation']['clientSideMimeTypes']        = array( 'image/heic', 'image/heif', 'image/webp' );

		$image_opt = new Image_Optimisation( $options );

		// Core does not support HEIC/HEIF client-side, so only WebP survives.
		$core = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' );
		$this->assertSame( array( 'image/webp' ), $image_opt->filter_client_side_supported_mime_types( $core ) );
	}

	/**
	 * Test that an enabled override with an empty selection returns an empty
	 * list, which disables browser-side processing entirely (as the UI copy
	 * states that unchecking every format disables client-side processing).
	 */
	public function test_client_side_mime_override_empty_disables_processing(): void {
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );
		Functions\when( 'sanitize_text_field' )->returnArg();

		$options = $this->default_options;
		$options['image_optimisation']['clientSideMimeTypeOverride'] = true;
		$options['image_optimisation']['clientSideMimeTypes']        = array();

		$image_opt = new Image_Optimisation( $options );

		$core = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' );
		$this->assertSame( array(), $image_opt->filter_client_side_supported_mime_types( $core ) );
	}

	/**
	 * Stub the WP functions used to resolve a URL to a local path via
	 * Util::get_local_path().
	 */
	private function stub_local_path_resolution(): void {
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Emulates wp_parse_url() in tests.
				$parts = parse_url( $url );
				if ( false === $parts ) {
					return false;
				}
				if ( -1 !== $component ) {
					return $parts[ $component ] ?? null;
				}
				return $parts;
			}
		);
		Functions\when( 'wp_normalize_path' )->alias(
			static function ( $path ) {
				$path = str_replace( '\\', '/', $path );
				$path = preg_replace( '|(?<=.)/+|', '/', $path );
				if ( str_starts_with( $path, '//' ) ) {
					$path = '/' . ltrim( $path, '/' );
				}
				return $path;
			}
		);
		Functions\when( 'home_url' )->alias(
			static function () {
				return 'http://example.com';
			}
		);
	}

	/**
	 * Test that generate_svg_base64 caps extreme width/height attributes.
	 */
	public function test_generate_svg_base64_caps_extreme_dimensions(): void {
		Functions\when( 'esc_attr' )->returnArg();

		$image_opt = new Image_Optimisation( $this->default_options );

		$reflection = new \ReflectionMethod( Image_Optimisation::class, 'generate_svg_base64' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke( $image_opt, '<img width="999999" height="500000" />' );

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding test fixture output.
		$svg = base64_decode( str_replace( 'data:image/svg+xml;base64,', '', $result ), true );
		// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		$this->assertIsString( $svg );
		$this->assertStringContainsString( 'width="4096"', $svg );
		$this->assertStringContainsString( 'height="4096"', $svg );
		$this->assertStringContainsString( 'viewBox="0 0 4096 4096"', $svg );
	}

	/**
	 * Test that generate_svg_base64 preserves legitimate dimensions.
	 */
	public function test_generate_svg_base64_preserves_normal_dimensions(): void {
		Functions\when( 'esc_attr' )->returnArg();

		$image_opt = new Image_Optimisation( $this->default_options );

		$reflection = new \ReflectionMethod( Image_Optimisation::class, 'generate_svg_base64' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke( $image_opt, '<img width="800" height="600" />' );

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding test fixture output.
		$svg = base64_decode( str_replace( 'data:image/svg+xml;base64,', '', $result ), true );
		// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		$this->assertIsString( $svg );
		$this->assertStringContainsString( 'width="800"', $svg );
		$this->assertStringContainsString( 'height="600"', $svg );
		$this->assertStringContainsString( 'viewBox="0 0 800 600"', $svg );
	}

	/**
	 * Test that the img_size_cache refreshes key recency on hit so frequently
	 * accessed images survive cache eviction (true LRU ordering).
	 */
	public function test_img_size_cache_refreshes_lru_order(): void {
		$this->stub_local_path_resolution();

		// post_process_img_dimensions() guards on file_exists()/is_file() with
		// the real filesystem (those functions are no longer Patchwork
		// redefinable), so create the resolved files under ABSPATH that
		// Util::get_local_path() maps each lru-N.jpg URL to.
		$upload_dir = '/tmp/wordpress/wp-content/uploads';
		if ( ! is_dir( $upload_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			mkdir( $upload_dir, 0777, true );
		}

		$temp_files = array();
		for ( $i = 0; $i <= 100; $i++ ) {
			$temp_files[ $i ] = $upload_dir . '/lru-' . $i . '.jpg';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $temp_files[ $i ], 'not-a-real-jpeg' );
		}

		$getimagesize_calls = 0;
		Functions\when( 'getimagesize' )->alias(
			static function () use ( &$getimagesize_calls ) {
				++$getimagesize_calls;
				return array( 100, 100, 1, 1, 'image/jpeg' );
			}
		);

		$image_opt = new Image_Optimisation( $this->default_options );

		// Insert 100 unique images, then refresh the first key, insert a 101st
		// image (triggering eviction), and re-access the first key.
		$buffer = '';
		for ( $i = 0; $i < 100; $i++ ) {
			$buffer .= '<img data-src="http://example.com/wp-content/uploads/lru-' . $i . '.jpg" />';
		}
		$buffer .= '<img data-src="http://example.com/wp-content/uploads/lru-0.jpg" />';
		$buffer .= '<img data-src="http://example.com/wp-content/uploads/lru-100.jpg" />';
		$buffer .= '<img data-src="http://example.com/wp-content/uploads/lru-0.jpg" />';

		$reflection = new \ReflectionMethod( Image_Optimisation::class, 'post_process_img_dimensions' );
		$reflection->setAccessible( true );

		try {
			$result = $reflection->invoke( $image_opt, $buffer );

			// 100 initial reads + 1 for the 101st image. The refreshed first key
			// must remain cached (a FIFO eviction would evict it and re-read it,
			// bumping the count to 102).
			$this->assertSame( 101, $getimagesize_calls );
			$this->assertStringContainsString( 'width="100"', $result );
		} finally {
			foreach ( $temp_files as $f ) {
				if ( file_exists( $f ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Cleanup of temp fixture files.
					unlink( $f );
				}
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Cleanup of temp fixture dir.
			@rmdir( $upload_dir );
		}
	}

	/**
	 * Test that a non-array client-side MIME setting (corrupted storage) keeps
	 * core's default list untouched (graceful degradation).
	 */
	public function test_client_side_mime_override_non_array_keeps_core_list(): void {
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );
		Functions\when( 'sanitize_text_field' )->returnArg();

		$options = $this->default_options;
		$options['image_optimisation']['clientSideMimeTypeOverride'] = true;
		$options['image_optimisation']['clientSideMimeTypes']        = 'image/jpeg';

		$image_opt = new Image_Optimisation( $options );

		$core = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' );
		$this->assertSame( $core, $image_opt->filter_client_side_supported_mime_types( $core ) );
	}

	/**
	 * Stub the WP functions normalize_image_url() depends on and invoke it.
	 *
	 * @param int $blog_id Blog ID used to isolate the per-blog static cache.
	 * @return array{0:string,1:int} [normalized_url, home_url call count].
	 */
	private function invoke_normalize_image_url( int $blog_id ): array {
		Functions\when( 'untrailingslashit' )->returnArg();
		Functions\when( 'get_current_blog_id' )->justReturn( $blog_id );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test alias for wp_parse_url.
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		$calls = 0;
		Functions\when( 'home_url' )->alias(
			static function () use ( &$calls ) {
				++$calls;
				return 'http://example.com';
			}
		);

		$image_opt  = new Image_Optimisation( $this->default_options );
		$reflection = new \ReflectionMethod( Image_Optimisation::class, 'normalize_image_url' );
		$reflection->setAccessible( true );

		$url = $reflection->invoke( $image_opt, '/wp-content/uploads/a.jpg' );
		$reflection->invoke( $image_opt, '/wp-content/uploads/b.jpg' );

		return array( $url, $calls );
	}

	/**
	 * Test that normalize_image_url resolves home_url() once per blog when no
	 * home_url filter is registered (cached across calls).
	 */
	public function test_normalize_image_url_caches_home_url_when_no_filter(): void {
		Functions\when( 'has_filter' )->justReturn( false );

		list( $url, $calls ) = $this->invoke_normalize_image_url( 99 );

		$this->assertSame( 'example.com/wp-content/uploads/a.jpg', $url );
		$this->assertSame( 1, $calls );
	}

	/**
	 * Test that normalize_image_url resolves home_url() on every call when a
	 * home_url filter is registered (caching disabled).
	 */
	public function test_normalize_image_url_bypasses_cache_when_filter_registered(): void {
		Functions\when( 'has_filter' )->justReturn( true );

		list( $url, $calls ) = $this->invoke_normalize_image_url( 100 );

		$this->assertSame( 'example.com/wp-content/uploads/a.jpg', $url );
		$this->assertSame( 2, $calls );
	}

	/**
	 * Test that an inline background-image is converted to a lazy data attribute.
	 */
	public function test_add_delay_load_backgrounds_rewrites_inline_background(): void {
		require_once __DIR__ . '/stubs/wp-html-api.php';
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );

		$options                       = $this->default_options;
		$options['image_optimisation'] = array_merge(
			$options['image_optimisation'],
			array( 'lazyLoadBackgroundImages' => true )
		);
		$image_opt                     = new Image_Optimisation( $options );

		$html = '<div class="hero" style="background-image:url(\'https://example.com/img.jpg\'); background-size:cover;">Hi</div>';
		$out  = $image_opt->add_delay_load_backgrounds( $html );

		$this->assertStringContainsString( 'wppo-lazy-bg', $out );
		$this->assertStringContainsString( 'data-wppo-bg=', $out );
		$this->assertStringNotContainsString( 'background-image', $out );
		$this->assertStringContainsString( 'background-size:cover', $out );
	}

	/**
	 * Test that the first N backgrounds are left untouched (hero heuristic).
	 */
	public function test_add_delay_load_backgrounds_respects_exclude_first(): void {
		require_once __DIR__ . '/stubs/wp-html-api.php';
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );

		$options                       = $this->default_options;
		$options['image_optimisation'] = array_merge(
			$options['image_optimisation'],
			array(
				'lazyLoadBackgroundImages' => true,
				'excludeFirstImages'       => 1,
			)
		);
		$image_opt                     = new Image_Optimisation( $options );

		$html = '<div style="background-image:url(\'https://example.com/hero.jpg\');"></div>'
			. '<div style="background-image:url(\'https://example.com/below.jpg\');"></div>';
		$out  = $image_opt->add_delay_load_backgrounds( $html );

		// The first (hero) background stays inline; the second is deferred.
		$this->assertStringContainsString( 'background-image:url(\'https://example.com/hero.jpg\')', $out );
		$this->assertStringContainsString( 'data-wppo-bg=', $out );
		$this->assertStringContainsString( 'https://example.com/below.jpg', $out );
	}

	/**
	 * Test that the background pass is a no-op when the setting is off.
	 */
	public function test_add_delay_load_backgrounds_skips_when_disabled(): void {
		require_once __DIR__ . '/stubs/wp-html-api.php';
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );

		$image_opt = new Image_Optimisation( $this->default_options );

		$html = '<div style="background-image:url(\'https://example.com/img.jpg\');"></div>';
		$out  = $image_opt->add_delay_load_backgrounds( $html );

		$this->assertSame( $html, $out );
	}

	/**
	 * Test that data: URI backgrounds are never deferred.
	 */
	public function test_add_delay_load_backgrounds_skips_data_uri(): void {
		require_once __DIR__ . '/stubs/wp-html-api.php';
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );

		$options                       = $this->default_options;
		$options['image_optimisation'] = array_merge(
			$options['image_optimisation'],
			array( 'lazyLoadBackgroundImages' => true )
		);
		$image_opt                     = new Image_Optimisation( $options );

		$html = '<div style="background-image:url(\'data:image/svg+xml;base64,PHN2Zz4=\');"></div>';
		$out  = $image_opt->add_delay_load_backgrounds( $html );

		$this->assertStringNotContainsString( 'wppo-lazy-bg', $out );
		$this->assertStringContainsString( 'background-image:url', $out );
	}
}
