<?php
/**
 * Tests for the shared WP 6.9+ core-API helpers on Util (issues #882, #883).
 *
 * Covers Util::cache_salt() (salted-cache salt values must be the option
 * VALUE, not the key) and the WP_HTML_Processor factory helpers
 * (Util::should_use_html_processor() / Util::create_html_processor()) shared
 * by the image-optimisation, CDN and used-CSS buffer rewrites.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests for the shared core-API helpers.
 *
 * @package PerformanceOptimise\Tests
 */
class UtilCoreApiHelpersTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Set up Brain Monkey and common stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		// Replicate the trait's per-test cache resets for isolation (issue
		// #882 review), then re-probe the HTML processor availability with the
		// stubs loaded.
		Util::reset_cached_home_urls();
		Util::clear_settings_cache();
		Util::reset_html_processor_memo();
		// Minimal functional WP_HTML_* stand-ins (same pattern as
		// ImageOptimisationTest) so the processor helpers can be exercised.
		require_once __DIR__ . '/stubs/wp-html-api.php';
	}

	/**
	 * Tear down Brain Monkey.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		Util::reset_html_processor_memo();
		\Brain\Monkey\tearDown();
		if ( class_exists( \PerformanceOptimise\Inc\Main::class ) ) {
			\PerformanceOptimise\Inc\Main::reset_instance();
		}
		parent::tearDown();
	}

	/**
	 * Test that cache_salt returns the option value as a string.
	 */
	public function test_cache_salt_returns_option_value(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $option, $fallback = false ) {
				return 'wppo_salt_present' === $option ? 42 : $fallback;
			}
		);

		$this->assertSame( '42', Util::cache_salt( 'wppo_salt_present' ) );
		// Missing option falls back to the '0' sentinel (never the key name).
		$this->assertSame( '0', Util::cache_salt( 'wppo_salt_missing' ) );
	}

	/**
	 * Test that cache_salt is array-safe (never triggers an Array-to-string
	 * conversion when a test/consumer stub returns an array) and maps
	 * non-stringable values to the '0' sentinel.
	 */
	public function test_cache_salt_is_array_safe(): void {
		Functions\when( 'get_option' )->justReturn( array( 'not' => 'a salt' ) );
		$this->assertSame( '0', Util::cache_salt( 'wppo_salt_array' ) );

		// An unset option (false) maps to the sentinel, never ''.
		Functions\when( 'get_option' )->justReturn( false );
		$this->assertSame( '0', Util::cache_salt( 'wppo_salt_bool' ) );
	}

	/**
	 * Test that the HTML processor availability check is true with the test
	 * stubs (public serialize_token) and that the memo sticks until reset.
	 */
	public function test_should_use_html_processor_true_with_public_serializer(): void {
		$this->assertTrue( Util::should_use_html_processor() );

		// The memoized value is stored on the class static and survives until
		// reset (the reflection probe is not repeated on every call).
		$prop = new \ReflectionProperty( Util::class, 'html_processor_available' );
		$prop->setAccessible( true );
		$this->assertTrue( $prop->getValue() );

		Util::reset_html_processor_memo();
		$this->assertNull( $prop->getValue() );
	}

	/**
	 * Test the negative path: when the probe has decided the processor is
	 * unavailable, create_html_processor() returns null so callers fall back
	 * to their byte-identical Tag Processor / regex paths.
	 */
	public function test_create_html_processor_returns_null_when_unavailable(): void {
		$prop = new \ReflectionProperty( Util::class, 'html_processor_available' );
		$prop->setAccessible( true );
		$prop->setValue( null, false );

		$this->assertFalse( Util::should_use_html_processor() );
		$this->assertNull( Util::create_html_processor( '<p>x</p>' ) );
	}

	/**
	 * Test that the full-parser factory is available (the helper prefers it).
	 *
	 * Note: the test stubs implement create_full_parser() and
	 * create_fragment() identically, so this suite is a smoke test; the
	 * full-vs-fragment fidelity was additionally validated manually against
	 * real WP 7.1 core (a fragment parser drops <!DOCTYPE>/<html>/<head> on
	 * full documents — see the create_html_processor() docblock).
	 */
	public function test_full_parser_factory_is_available(): void {
		$this->assertTrue( method_exists( 'WP_HTML_Processor', 'create_full_parser' ) );
	}

	/**
	 * Test that create_html_processor returns a working processor and that a
	 * full document keeps its wrapper markup through a serialize_token walk.
	 *
	 * A fragment parser would drop <!DOCTYPE>/<html>/<head>/<body> when
	 * re-serializing a full document; the shared helper must therefore prefer
	 * the full parser.
	 */
	public function test_create_html_processor_preserves_full_document(): void {
		$html      = "<!DOCTYPE html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><title>T</title></head>"
			. '<body class="home"><p>hi</p></body></html>';
		$processor = Util::create_html_processor( $html );

		$this->assertInstanceOf( \WP_HTML_Processor::class, $processor );

		$out = '';
		while ( $processor->next_token() ) {
			$out .= $processor->serialize_token();
		}

		$this->assertStringContainsString( '<!DOCTYPE html>', $out );
		$this->assertStringContainsString( '<html lang="en">', $out );
		$this->assertStringContainsString( '<meta charset="utf-8">', $out );
		$this->assertStringContainsString( '<body class="home">', $out );
		$this->assertStringContainsString( '<p>hi</p>', $out );
		$this->assertNull( $processor->get_last_error() );
	}

	/**
	 * Test that attribute rewriting through the shared factory + serialize_token
	 * keeps the rewritten value in the serialized output.
	 */
	public function test_create_html_processor_supports_attribute_rewrites(): void {
		$html      = '<img src="http://example.com/wp-content/a.png" alt="x">';
		$processor = Util::create_html_processor( $html );

		$this->assertInstanceOf( \WP_HTML_Processor::class, $processor );

		$out = '';
		while ( $processor->next_token() ) {
			if ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() && 'IMG' === $processor->get_tag() ) {
				$processor->set_attribute( 'src', 'https://cdn.example.com/wp-content/a.png' );
			}
			$out .= $processor->serialize_token();
		}

		$this->assertStringContainsString( 'https://cdn.example.com/wp-content/a.png', $out );
		$this->assertStringNotContainsString( 'http://example.com/wp-content/a.png', $out );
	}
}
