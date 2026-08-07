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
	 * Invoke a private method on a Main instance.
	 *
	 * @param Main   $main Main instance.
	 * @param string $name Method name.
	 * @param mixed  ...$args Method arguments.
	 * @return mixed Method return value.
	 */
	private function invoke_private_method( Main $main, string $name, ...$args ) {
		$reflection = new \ReflectionMethod( Main::class, $name );
		$reflection->setAccessible( true );
		return $reflection->invoke( $main, ...$args );
	}

	/**
	 * Test that get_delay_strategy_for_handle() uses word-boundary matching so a
	 * short pattern like `slide` no longer matches unrelated handles such as
	 * `slider-custom`, while exact and dash-delimited prefix matches still work.
	 */
	public function test_delay_strategy_ignores_partial_word_pattern_matches(): void {
		$this->stub_main_construction(
			array(
				'delayJS'         => true,
				'delayJSIdleList' => "slide\nhttps://cdn.example.com/analytics.js",
			)
		);

		$main = new Main();

		$default = $this->invoke_private_method( $main, 'get_delay_strategy_for_handle', 'totally-unrelated-handle' );

		// Pattern 'slide' must not partial-match 'slider-custom' / 'slider'.
		$this->assertSame( $default, $this->invoke_private_method( $main, 'get_delay_strategy_for_handle', 'slider-custom' ) );
		$this->assertSame( $default, $this->invoke_private_method( $main, 'get_delay_strategy_for_handle', 'slider' ) );

		// Exact and dash-delimited matches still resolve.
		$this->assertSame( 'idle', $this->invoke_private_method( $main, 'get_delay_strategy_for_handle', 'slide' ) );
		$this->assertSame( 'idle', $this->invoke_private_method( $main, 'get_delay_strategy_for_handle', 'slide-custom' ) );
		$this->assertSame( 'idle', $this->invoke_private_method( $main, 'get_delay_strategy_for_handle', 'https://cdn.example.com/analytics.js' ) );
	}

	/**
	 * Test that word-boundary matching still preserves dash-delimited prefix
	 * matches (jquery → jquery-core) and whole-handle matches (slider → slider-custom).
	 */
	public function test_delay_strategy_preserves_dash_prefix_matches(): void {
		$this->stub_main_construction(
			array(
				'delayJS'         => true,
				'delayJSIdleList' => "jquery\nslider",
			)
		);

		$main = new Main();

		$this->assertSame( 'idle', $this->invoke_private_method( $main, 'get_delay_strategy_for_handle', 'jquery-core' ) );
		$this->assertSame( 'idle', $this->invoke_private_method( $main, 'get_delay_strategy_for_handle', 'slider-custom' ) );
	}

	/**
	 * Test that viewport strategy matching also uses word boundaries and does not
	 * match partial words inside unrelated handles.
	 */
	public function test_delay_viewport_strategy_uses_word_boundary_matching(): void {
		$this->stub_main_construction(
			array(
				'delayJS'             => true,
				'delayJSViewportList' => 'slide',
			)
		);

		$main = new Main();

		$default = $this->invoke_private_method( $main, 'get_delay_strategy_for_handle', 'totally-unrelated-handle' );

		$this->assertSame( $default, $this->invoke_private_method( $main, 'get_delay_strategy_for_handle', 'slider-custom' ) );
		$this->assertSame( 'viewport', $this->invoke_private_method( $main, 'get_delay_strategy_for_handle', 'slide' ) );
	}

	/**
	 * Test that get_delay_priority_for_handle() uses word-boundary matching so a
	 * short pattern like `slide` no longer assigns its priority to unrelated
	 * handles, while exact handles (including metacharacter patterns) still resolve.
	 *
	 * Note: full URL patterns (e.g. `https://cdn.example.com/analytics.js:low`)
	 * cannot be used as priority keys because the priority option parser splits on
	 * the first colon (`https:`), a pre-existing limitation outside this issue.
	 */
	public function test_delay_priority_uses_word_boundary_matching(): void {
		$this->stub_main_construction(
			array(
				'delayJS'         => true,
				'delayJSPriority' => "slide:high\nanalytics.js:low",
			)
		);

		$main = new Main();

		$this->assertSame( 'normal', $this->invoke_private_method( $main, 'get_delay_priority_for_handle', 'slider-custom' ) );
		$this->assertSame( 'high', $this->invoke_private_method( $main, 'get_delay_priority_for_handle', 'slide' ) );
		$this->assertSame( 'low', $this->invoke_private_method( $main, 'get_delay_priority_for_handle', 'analytics.js' ) );
		$this->assertSame( 'normal', $this->invoke_private_method( $main, 'get_delay_priority_for_handle', 'analytics.min.js' ) );
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
