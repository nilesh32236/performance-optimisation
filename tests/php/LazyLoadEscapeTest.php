<?php
/**
 * Tests for lazy-load rewriter attribute escaping and REST route gating (issue #967).
 *
 * Covers the stored-XSS hardening of re-emitted attributes in the
 * lazy-load rewriter (iframe regex fallback, video placeholder payload)
 * plus the guard that keeps admin-gated routes admin-gated with no
 * IP-only public notifier path.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Image_Optimisation;
use PerformanceOptimise\Inc\Rest;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Tests for lazy-load attribute escaping and REST gating.
 *
 * @package PerformanceOptimise\Tests
 */
class LazyLoadEscapeTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();

		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'trailingslashit' )->returnArg();
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	/**
	 * Tear down Brain Monkey.
	 */
	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build an Image_Optimisation instance with JS-lazy iframe rewriting.
	 *
	 * @return Image_Optimisation
	 */
	private function make_image_optimisation(): Image_Optimisation {
		return new Image_Optimisation(
			array(
				'image_optimisation' => array(
					'lazyLoadImages'  => true,
					'lazyLoadVideos'  => true,
					'placeholderType' => 'none',
					'lazyLoadNative'  => false,
				),
			)
		);
	}

	/**
	 * The iframe regex fallback escapes the re-emitted data-src value so a
	 * hostile stored src can never break out of markup.
	 *
	 * Separate process: guarantees WP_HTML_Tag_Processor is unavailable so
	 * the regex fallback path executes. esc_attr is aliased to a real
	 * escaper to simulate core behaviour.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_process_iframe_tag_regex_fallback_escapes_data_src(): void {
		$this->assertFalse( class_exists( 'WP_HTML_Tag_Processor' ) );

		Functions\when( 'esc_attr' )->alias(
			static function ( $value ) {
				return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
			}
		);

		$image_opt = $this->make_image_optimisation();

		$tag = '<iframe src="https://example.com/embed?a=1&b=2" width="560"></iframe>';
		$out = $image_opt->process_iframe_tag( $tag, 'https://example.com/embed?a=1&b=2', array() );

		$this->assertStringContainsString( 'data-src="https://example.com/embed?a=1&amp;b=2"', $out );
		$this->assertStringNotContainsString( ' src="https://example.com/embed', $out );
		$this->assertStringContainsString( 'wppo-lazyload', $out );
	}

	/**
	 * The iframe regex fallback escapes the re-emitted class value.
	 *
	 * Separate process: guarantees the regex fallback path executes.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_process_iframe_tag_regex_fallback_escapes_class_value(): void {
		$this->assertFalse( class_exists( 'WP_HTML_Tag_Processor' ) );

		Functions\when( 'esc_attr' )->alias(
			static function ( $value ) {
				return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
			}
		);

		$image_opt = $this->make_image_optimisation();

		$tag = '<iframe src="https://example.com/e" class="a<b"></iframe>';
		$out = $image_opt->process_iframe_tag( $tag, 'https://example.com/e', array() );

		$this->assertStringContainsString( 'class="a&lt;b wppo-lazyload"', $out );
		$this->assertStringNotContainsString( 'class="a<b"', $out );
	}

	/**
	 * The video placeholder payload never carries event-handler attributes:
	 * a hostile onload/onerror on the source iframe must not reach the
	 * data-wppo-iframe-attrs JSON the client restores.
	 *
	 * Separate process: isolates the htmlspecialchars esc_attr alias so no
	 * other test observes the reconfigured stub.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_generate_video_placeholder_excludes_event_handler_attrs(): void {
		require_once __DIR__ . '/stubs/wp-html-api.php';

		Functions\when( 'esc_attr' )->alias(
			static function ( $value ) {
				return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
			}
		);
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg( 1 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$image_opt = $this->make_image_optimisation();

		$iframe_tag = '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" onload="alert(1)" onerror="alert(2)" width="560" height="315" title="t"></iframe>';

		$method = new ReflectionMethod( Image_Optimisation::class, 'generate_video_placeholder' );
		$method->setAccessible( true );
		$out = $method->invoke( $image_opt, $iframe_tag, 'https://www.youtube.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ' );

		$this->assertStringContainsString( 'data-wppo-iframe-attrs=', $out );

		// The thumbnail reserves a 16:9 box to avoid CLS before the lazy
		// image/embed resolves (audit #1077 finding 7).
		$this->assertStringContainsString( 'width="1280" height="720"', $out );

		$this->assertSame( 1, preg_match( '/data-wppo-iframe-attrs="([^"]*)"/', $out, $matches ) );
		$stored = json_decode( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ), true );

		$this->assertIsArray( $stored );
		$this->assertArrayNotHasKey( 'onload', $stored );
		$this->assertArrayNotHasKey( 'onerror', $stored );
		$this->assertArrayNotHasKey( 'src', $stored );
		// width/height/style are owned by the placeholder and never stored.
		$this->assertArrayNotHasKey( 'width', $stored );
		$this->assertArrayNotHasKey( 'height', $stored );
		$this->assertArrayNotHasKey( 'style', $stored );
		$this->assertSame( 't', (string) ( $stored['title'] ?? '' ) );
	}

	/**
	 * Admin-gated routes stay admin-gated: rum_collect is the only public
	 * route (token + IP rate-limited beacon by design) and no IP-only
	 * public notifier path exists in the route table.
	 */
	public function test_rest_routes_keep_admin_gating_without_ip_notifier(): void {
		$rest   = new Rest();
		$method = new ReflectionMethod( Rest::class, 'get_routes' );
		$method->setAccessible( true );
		$routes = $method->invoke( $rest );

		$this->assertIsArray( $routes );
		$this->assertArrayHasKey( 'rum_collect', $routes );

		$public = array();
		foreach ( $routes as $slug => $route ) {
			$permission = $route['permission_callback'] ?? null;
			if ( '__return_true' === $permission ) {
				$public[] = $slug;
				continue;
			}

			$this->assertSame(
				array( $rest, 'permission_callback' ),
				$permission,
				"Route {$slug} must stay behind the manage_options permission callback."
			);
		}

		$this->assertSame( array( 'rum_collect' ), $public, 'rum_collect must be the only public route.' );
	}
}
