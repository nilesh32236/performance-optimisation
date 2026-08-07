<?php
/**
 * Tests for Main defer/delay JS exclusion reconciliation.
 *
 * Verifies that when both Defer JS and Delay JS are active, the deferred script
 * handles are merged into the delay-JS exclusion list so delay processing never
 * rewrites (wppo-src / wppo/javascript) scripts that are deferred natively.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;
use Brain\Monkey\Functions;

/**
 * Tests the setup_hooks() defer/delay exclusion merge.
 *
 * @package PerformanceOptimise\Tests
 */
class MainDelayDeferTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Stub the WP environment needed to construct Main with the given
	 * file_optimisation overrides.
	 *
	 * @param array $file_overrides file_optimisation option overrides.
	 * @return void
	 */
	private function stub_main_construction( array $file_overrides ): void {
		Functions\stubs(
			array(
				'WP_Filesystem'       => false,
				'sanitize_text_field' => '',
				'wp_unslash'          => '',
				'is_user_logged_in'   => false,
				'absint'              => 3000,
			)
		);

		Functions\when( 'get_option' )->alias(
			static function ( $option, $default_value = false ) use ( $file_overrides ) {
				if ( 'wppo_settings' === $option && is_array( $default_value ) ) {
					$default_value['file_optimisation'] = array_merge( $default_value['file_optimisation'] ?? array(), $file_overrides );
				}
				return $default_value;
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'content_url' )->returnArg();
		Functions\when( 'trailingslashit' )->returnArg();
		Functions\when( 'wp_is_block_theme' )->justReturn( false );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );

		// Only the target function_exists probes are faked; everything else is
		// delegated to the real function_exists so the rest of the Main
		// constructor keeps running on its real branch.
		Functions\when( 'function_exists' )->alias(
			static function ( $function_name ) {
				if ( 'WP_Filesystem' === $function_name || 'wp_is_block_theme' === $function_name ) {
					return true;
				}
				return \function_exists( $function_name );
			}
		);
	}

	/**
	 * Read a private property off a Main instance.
	 *
	 * @param Main   $main Main instance.
	 * @param string $prop Property name.
	 * @return mixed Property value.
	 */
	private function read_private_prop( Main $main, string $prop ) {
		$reflection = new \ReflectionProperty( Main::class, $prop );
		$reflection->setAccessible( true );
		return $reflection->getValue( $main );
	}

	/**
	 * Test that deferred handles (default + user-configured) are merged into the
	 * delay-JS exclusion list when both defer and delay JS are active.
	 */
	public function test_deferred_handles_merged_into_delay_exclusions_when_both_enabled(): void {
		$this->stub_main_construction(
			array(
				'deferJS'        => true,
				'delayJS'        => true,
				'excludeDeferJS' => "my-deferred-script\nhttps://cdn.example.com/analytics.js",
				'excludeDelayJS' => 'my-delay-only-script',
			)
		);

		$main = new Main();

		$delay_excludes = $this->read_private_prop( $main, 'exclude_delay_js' );

		$this->assertContains( 'wppo-lazyload', $delay_excludes );
		$this->assertContains( 'my-deferred-script', $delay_excludes );
		$this->assertContains( 'https://cdn.example.com/analytics.js', $delay_excludes );
		$this->assertContains( 'my-delay-only-script', $delay_excludes );
	}

	/**
	 * Test that deferred handles are NOT merged into the delay-JS exclusion list
	 * when defer JS is disabled.
	 */
	public function test_deferred_handles_not_merged_when_defer_disabled(): void {
		$this->stub_main_construction(
			array(
				'deferJS'        => false,
				'delayJS'        => true,
				'excludeDeferJS' => 'my-deferred-script',
			)
		);

		$main = new Main();

		$delay_excludes = $this->read_private_prop( $main, 'exclude_delay_js' );

		$this->assertContains( 'wppo-lazyload', $delay_excludes );
		$this->assertNotContains( 'my-deferred-script', $delay_excludes );
	}

	/**
	 * Test that handles added through the wppo_exclude_defer_js filter are also
	 * merged into the delay-JS exclusion list when both options are active.
	 */
	public function test_wppo_exclude_defer_js_filter_handles_merged_into_delay_exclusions(): void {
		$this->stub_main_construction(
			array(
				'deferJS' => true,
				'delayJS' => true,
			)
		);

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wppo_exclude_defer_js' === $hook && is_array( $value ) ) {
					$value[] = 'filter-added-defer-handle';
				}
				return $value;
			}
		);

		$main = new Main();

		$delay_excludes = $this->read_private_prop( $main, 'exclude_delay_js' );

		$this->assertContains( 'filter-added-defer-handle', $delay_excludes );
	}

	/**
	 * Test that add_defer_attribute() leaves a deferred handle's tag untouched
	 * when both defer and delay JS are active.
	 */
	public function test_add_defer_attribute_skips_deferred_handle_when_both_enabled(): void {
		$this->stub_main_construction(
			array(
				'deferJS'        => true,
				'delayJS'        => true,
				'excludeDeferJS' => 'my-deferred-script',
			)
		);

		$main = new Main();

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for add_defer_attribute() tests.
		$tag    = '<script src="https://example.com/my-deferred-script.js" type="text/javascript"></script>';
		$result = $main->add_defer_attribute( $tag, 'my-deferred-script' );

		$this->assertSame( $tag, $result );
	}
}
