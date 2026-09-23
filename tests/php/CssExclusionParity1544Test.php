<?php
/**
 * Tests for CSS exclusion ownership consolidation (issue #1544, ARCH-009).
 *
 * Critical_CSS owns the exclusion semantics (parser, post-type list +
 * memo + filter, is_excluded_post, defaults); Used_CSS is a thin
 * delegator with a defaults-only fallback for the Critical_CSS-unavailable
 * (mixed-version) path. These tests pin the parity and the fallback so the
 * ~60-line duplicated bodies cannot drift back in.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Critical_CSS;
use PerformanceOptimise\Inc\Used_CSS;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Exclusion-parity tests for issue #1544.
 *
 * @package PerformanceOptimise\Tests
 */
class CssExclusionParity1544Test extends \PHPUnit\Framework\TestCase {

	use WPPO_Test_Bootstrap;

	/**
	 * Point Util at a canned settings array for one test.
	 *
	 * @param array $file_optimisation File-optimisation settings slice.
	 * @return void
	 */
	private function use_file_optimisation_settings( array $file_optimisation ): void {
		Util::set_settings_cache(
			array(
				'file_optimisation' => $file_optimisation,
			)
		);
		Critical_CSS::reset_excluded_post_types_memo();
	}

	/**
	 * Both classes agree on the built-in defaults.
	 *
	 * @return void
	 */
	public function test_parity_on_defaults(): void {
		$this->use_file_optimisation_settings( array() );
		$expected = array( 'fl-builder-template', 'elementor_library' );
		$this->assertSame( $expected, Critical_CSS::get_excluded_post_types() );
		$this->assertSame( $expected, Used_CSS::get_excluded_post_types() );
	}

	/**
	 * Both classes agree when a custom setting merges additively.
	 *
	 * @return void
	 */
	public function test_parity_with_custom_setting(): void {
		$this->use_file_optimisation_settings(
			array(
				'ccssExcludedPostTypes' => "my_library\nELEMENTOR_LIBRARY",
			)
		);
		$owner     = Critical_CSS::get_excluded_post_types();
		$delegator = Used_CSS::get_excluded_post_types();
		$this->assertSame( $owner, $delegator );
		$this->assertContains( 'fl-builder-template', $delegator );
		$this->assertContains( 'elementor_library', $delegator );
		$this->assertContains( 'my_library', $delegator );
	}

	/**
	 * Both classes agree with and without the exclusion filter.
	 *
	 * @return void
	 */
	public function test_parity_with_filter(): void {
		$this->use_file_optimisation_settings( array() );
		Functions\when( 'has_filter' )->alias(
			static function ( $hook ) {
				return 'wppo_ccss_excluded_post_types' === $hook;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wppo_ccss_excluded_post_types' === $hook && is_array( $value ) ) {
					$value[] = 'my_library';
					return $value;
				}
				return $value;
			}
		);
		$this->assertSame(
			Critical_CSS::get_excluded_post_types(),
			Used_CSS::get_excluded_post_types()
		);
		$this->assertContains( 'my_library', Used_CSS::get_excluded_post_types() );

		// An empty filter return is ignored (no opt-out) on both pipelines.
		Critical_CSS::reset_excluded_post_types_memo();
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wppo_ccss_excluded_post_types' === $hook ) {
					return array();
				}
				return $value;
			}
		);
		$expected = array( 'fl-builder-template', 'elementor_library' );
		$this->assertSame( $expected, Critical_CSS::get_excluded_post_types() );
		$this->assertSame( $expected, Used_CSS::get_excluded_post_types() );
	}

	/**
	 * The Used_CSS fallback body stays defaults-only (no settings/parse/merge replicas).
	 *
	 * Critical_CSS is always loadable in this suite, so the fallback path
	 * itself is unreachable at runtime here; this structural pin keeps the
	 * ARCH-009 consolidation from drifting back into a duplicated fallback.
	 *
	 * @return void
	 */
	public function test_used_css_fallback_body_is_defaults_only(): void {
		$method = new ReflectionMethod( Used_CSS::class, 'get_excluded_post_types' );
		$file   = $method->getFileName();
		$lines  = file( $file );
		$this->assertNotFalse( $lines );
		$body = implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );
		// Skip the docblock: the shared-contract docs legitimately name the
		// setting key, so only the executable body must stay defaults-only.
		$fn_pos = strpos( $body, 'function get_excluded_post_types' );
		$this->assertNotFalse( $fn_pos );
		$body = substr( $body, $fn_pos );
		// Strip comments: explanatory comments may name the shared setting
		// key, but the executable fallback must not touch it.
		$code = '';
		foreach ( token_get_all( '<?php ' . $body ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$code .= is_array( $token ) ? $token[1] : $token;
		}
		$body = $code;
		$this->assertStringNotContainsString( 'get_settings', $body, 'Fallback must not re-read settings (Critical_CSS owns them).' );
		$this->assertStringNotContainsString( 'parse_excluded_slugs', $body, 'Fallback must not replicate parsing (Critical_CSS owns it).' );
		$this->assertStringNotContainsString( 'ccssExcludedPostTypes', $body, 'Fallback must not reference the setting key.' );
		$this->assertStringNotContainsString( 'array_merge', $body, 'Fallback must not replicate the additive merge.' );
		$this->assertStringContainsString( 'fl-builder-template', $body );
		$this->assertStringContainsString( 'elementor_library', $body );
	}

	/**
	 * Used_CSS::is_excluded_post() fails open on bad IDs and missing APIs.
	 *
	 * @return void
	 */
	public function test_is_excluded_post_fail_open(): void {
		$this->use_file_optimisation_settings( array() );
		$this->assertFalse( Used_CSS::is_excluded_post( 0 ) );
		$this->assertFalse( Used_CSS::is_excluded_post( -5 ) );
		// The missing-get_post_type-API path is unreachable in this shared
		// process (earlier suites eval-declare the stub permanently), so it
		// is pinned by code review: the `$post_id <= 0 ||
		// ! function_exists( 'get_post_type' )` guard above returns false.

		// Unknown/empty post types fail open (generate as before).
		Functions\when( 'get_post_type' )->alias(
			static function ( $post_id ) {
				return 7 === $post_id ? 'elementor_library' : '';
			}
		);
		$this->assertTrue( Used_CSS::is_excluded_post( 7 ) );
		$this->assertFalse( Used_CSS::is_excluded_post( 8 ) );
	}

	/**
	 * The exclusion memo is a plain request-lifetime static (no blog keying).
	 *
	 * Multisite safety comes from the per-request lifecycle plus per-site
	 * option reads: one HTTP request serves one blog, so the memo cannot
	 * leak across blogs in production. This test documents that behavior —
	 * a settings change without a memo reset still serves the memoized
	 * list — and must be revisited only if a real cross-blog leak is proven.
	 *
	 * @return void
	 */
	public function test_excluded_memo_is_request_lifetime_without_blog_key(): void {
		$this->use_file_optimisation_settings( array() );
		$defaults = Critical_CSS::get_excluded_post_types();
		$this->assertSame( array( 'fl-builder-template', 'elementor_library' ), $defaults );

		// Swap the underlying settings without resetting: the memo wins.
		Util::set_settings_cache(
			array(
				'file_optimisation' => array(
					'ccssExcludedPostTypes' => 'my_library',
				),
			)
		);
		$this->assertSame( $defaults, Critical_CSS::get_excluded_post_types() );
		$this->assertSame( $defaults, Used_CSS::get_excluded_post_types() );

		// After a reset both pipelines observe the new settings together.
		Critical_CSS::reset_excluded_post_types_memo();
		$updated = Critical_CSS::get_excluded_post_types();
		$this->assertContains( 'my_library', $updated );
		$this->assertSame( $updated, Used_CSS::get_excluded_post_types() );

		// The memo store itself is a plain nullable list, not blog-keyed.
		$prop = new ReflectionProperty( Critical_CSS::class, 'excluded_memo' );
		$type = (string) $prop->getType();
		$this->assertStringContainsString( 'array', $type );
	}
}
