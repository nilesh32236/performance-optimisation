<?php
/**
 * Tests for builder-template exclusions and bounded retries (issue #1274 review).
 *
 * Covers Critical_CSS::get_excluded_post_types() additive merge over the
 * built-in builder defaults, the empty/all-invalid fallback to defaults,
 * get_ccss_max_retries() clamping (0..5, 0 = fail fast), Used_CSS
 * delegation, and Used_CSS::is_excluded_post().
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Critical_CSS;
use PerformanceOptimise\Inc\Used_CSS;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Exclusion-parsing and retry-cap tests for issue #1274.
 *
 * @package PerformanceOptimise\Tests
 */
class CcssExclusionsRetry1274Test extends \PHPUnit\Framework\TestCase {

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
	 * Defaults survive when no exclusions are configured.
	 *
	 * @return void
	 */
	public function test_exclusions_default_to_builder_templates(): void {
		$this->use_file_optimisation_settings( array() );
		$this->assertSame(
			array( 'fl-builder-template', 'elementor_library' ),
			Critical_CSS::get_excluded_post_types()
		);
	}

	/**
	 * Custom slugs merge over (not replace) the builder defaults.
	 *
	 * @return void
	 */
	public function test_exclusions_merge_custom_with_defaults(): void {
		$this->use_file_optimisation_settings(
			array(
				'ccssExcludedPostTypes' => "my_library\nELEMENTOR_LIBRARY",
			)
		);
		$excluded = Critical_CSS::get_excluded_post_types();
		$this->assertContains( 'fl-builder-template', $excluded );
		$this->assertContains( 'elementor_library', $excluded );
		$this->assertContains( 'my_library', $excluded );
		$this->assertSame( $excluded, array_values( array_unique( $excluded ) ) );
	}

	/**
	 * An all-invalid setting keeps the defaults instead of disabling protection.
	 *
	 * @return void
	 */
	public function test_exclusions_all_invalid_falls_back_to_defaults(): void {
		$this->use_file_optimisation_settings(
			array(
				'ccssExcludedPostTypes' => '!!!',
			)
		);
		$this->assertSame(
			array( 'fl-builder-template', 'elementor_library' ),
			Critical_CSS::get_excluded_post_types()
		);
	}

	/**
	 * Retry cap clamps to 0..5 with fail-open default 5.
	 *
	 * @return void
	 */
	public function test_max_retries_clamp(): void {
		$this->use_file_optimisation_settings( array( 'ccssMaxRetries' => 99 ) );
		$this->assertSame( 5, Critical_CSS::get_ccss_max_retries() );

		$this->use_file_optimisation_settings( array( 'ccssMaxRetries' => -3 ) );
		$this->assertSame( 0, Critical_CSS::get_ccss_max_retries() );

		$this->use_file_optimisation_settings( array( 'ccssMaxRetries' => 0 ) );
		$this->assertSame( 0, Critical_CSS::get_ccss_max_retries() );

		$this->use_file_optimisation_settings( array( 'ccssMaxRetries' => 'not-a-number' ) );
		$this->assertSame( 5, Critical_CSS::get_ccss_max_retries() );
	}

	/**
	 * Used_CSS shares the Critical_CSS exclusion list.
	 *
	 * @return void
	 */
	public function test_used_css_delegates_exclusions(): void {
		$this->use_file_optimisation_settings(
			array(
				'ccssExcludedPostTypes' => 'my_library',
			)
		);
		$this->assertSame(
			Critical_CSS::get_excluded_post_types(),
			Used_CSS::get_excluded_post_types()
		);
	}

	/**
	 * Used_CSS::is_excluded_post() matches builder types and passes content through.
	 *
	 * @return void
	 */
	public function test_used_css_is_excluded_post(): void {
		$this->use_file_optimisation_settings( array() );
		Functions\when( 'get_post_type' )->alias(
			static function ( $post_id ) {
				return 7 === $post_id ? 'elementor_library' : 'post';
			}
		);
		$this->assertTrue( Used_CSS::is_excluded_post( 7 ) );
		$this->assertFalse( Used_CSS::is_excluded_post( 8 ) );
		$this->assertFalse( Used_CSS::is_excluded_post( 0 ) );
	}
}
