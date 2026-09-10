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
	use WPPO_Test_Bootstrap {
		setUp as protected wppoSetUp;
		tearDown as protected wppoTearDown;
	}

	/**
	 * Ensure wp_version global is clean between tests (6.9 gate in stub_script_modules).
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp_version'], $GLOBALS['wp_scripts'] );
		$this->wppoTearDown();
	}

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
			)
		);

		// absint maps to a real int cast (not a pinned constant) so configured
		// delayJSIdleTimeout values are actually verified instead of the 3000
		// fallback passing tautologically.
		Functions\when( 'absint' )->alias(
			static function ( $maybeint ) {
				return (int) $maybeint;
			}
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
		// Woo conditional tags: default to plain frontend so the delay
		// guardrail (is_delay_excluded_context()) is deterministic even when
		// another suite defined these functions process-wide via Patchwork.
		// Tests covering the guard override is_cart() after this helper.
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );

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
	 * Reset the request superglobals read by is_delay_excluded_context().
	 *
	 * Brain Monkey tearDown() does not restore $_SERVER/$_GET/$_COOKIE, so
	 * guardrail fixtures must be cleared before (leakage from other suites)
	 * and after (leakage into other suites) each guard test.
	 *
	 * @return void
	 */
	private function reset_delay_guard_superglobals(): void {
		unset( $_SERVER['REQUEST_URI'], $_SERVER['QUERY_STRING'] );
		foreach ( array( 'elementor-preview', 'et_fb', 'et_pb_preview', 'vc_action', 'vc_editable', 'bricks', 'wc-ajax', 'add-to-cart' ) as $key ) {
			unset( $_GET[ $key ] );
		}
		foreach ( array( 'woocommerce_items_in_cart', 'woocommerce_cart_hash' ) as $key ) {
			unset( $_COOKIE[ $key ] );
		}
	}

	/**
	 * Restore passthrough request sanitizers pinned to fixed empty strings by
	 * stub_main_construction().
	 *
	 * Guard tests that exercise REQUEST_URI paths, QUERY_STRING signals, or
	 * the bricks=run check need the real passthrough behavior.
	 *
	 * Note: the Woo-tag function_exists probes are intentionally NOT faked
	 * here. Brain Monkey consults function_exists() internally when declaring
	 * stubs, so forcing it true for not-yet-declared functions breaks
	 * Functions\when('is_cart') with "Call to undefined function". The real
	 * lookup is correct in all cases: Brain Monkey-declared tags return true,
	 * undeclared ones are skipped, and wc_get_page_id()/get_post_field() stay
	 * false so the default slugs apply.
	 *
	 * @return void
	 */
	private function stub_guard_request_env(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
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
	 * Test that add_defer_attribute leaves a deferred handle untouched when both
	 * defer and delay JS are enabled.
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

	/**
	 * Test that the INP-first preset flips the effective default strategy to
	 * idle, and that a configured idle timeout is honored (not the 3000
	 * fallback passing tautologically).
	 */
	public function test_inp_preset_sets_idle_default_strategy(): void {
		$this->stub_main_construction(
			array(
				'delayJS'            => true,
				'delayJSINPPreset'   => true,
				'delayJSIdleTimeout' => '4500',
			)
		);

		$main = new Main();

		$this->assertSame( 'idle', $this->invoke_private_method( $main, 'get_delay_strategy_for_handle', 'totally-unrelated-handle' ) );
		$this->assertSame( 4500, $this->read_private_prop( $main, 'delay_js_idle_timeout' ) );
	}

	/**
	 * Test that without the preset the default strategy stays interaction-only
	 * and the idle timeout falls back to 3000.
	 */
	public function test_inp_preset_off_falls_back_to_interaction(): void {
		$this->stub_main_construction(
			array(
				'delayJS' => true,
			)
		);

		$main = new Main();

		$this->assertSame( 'interaction', $this->invoke_private_method( $main, 'get_delay_strategy_for_handle', 'totally-unrelated-handle' ) );
		$this->assertSame( 3000, $this->read_private_prop( $main, 'delay_js_idle_timeout' ) );
	}

	/**
	 * Test that the Delay-JS preset exclusions are safe by default (Woo, Elementor, forms).
	 *
	 * @since NEXT
	 */
	public function test_delay_js_preset_exclusions_include_safe_entries(): void {
		$this->stub_main_construction(
			array(
				'delayJS' => true,
			)
		);

		$main = new Main();

		$preset = $this->invoke_private_method( $main, 'get_delay_js_preset_exclusions' );

		foreach ( array( 'woocommerce', 'wc-checkout', 'cart-fragments', 'elementor', 'elementor-frontend', 'contact-form-7', 'wpcf7', 'gravityforms', 'gform', 'wpforms', 'ninja-forms', 'fluentform', 'jquery', 'stripe' ) as $entry ) {
			$this->assertContains( $entry, $preset );
		}
	}

	/**
	 * Test that an explicit non-interaction default wins over the preset and
	 * manual idle/viewport lists still take per-handle precedence (Option A
	 * in-memory override semantics, issue #932).
	 */
	public function test_inp_preset_explicit_default_wins_with_list_precedence(): void {
		$this->stub_main_construction(
			array(
				'delayJS'                => true,
				'delayJSINPPreset'       => true,
				'delayJSDefaultStrategy' => 'viewport',
				'delayJSIdleList'        => 'my-idle-script',
				'delayJSViewportList'    => 'my-viewport-script',
			)
		);
		// The stored default strategy is sanitized, not pinned, so restore
		// passthrough sanitization for this test.
		Functions\when( 'sanitize_text_field' )->returnArg();

		$main = new Main();

		$this->assertSame( 'idle', $this->invoke_private_method( $main, 'get_delay_strategy_for_handle', 'my-idle-script' ) );
		$this->assertSame( 'viewport', $this->invoke_private_method( $main, 'get_delay_strategy_for_handle', 'my-viewport-script' ) );
		$this->assertSame( 'viewport', $this->invoke_private_method( $main, 'get_delay_strategy_for_handle', 'totally-unrelated-handle' ) );
	}

	/**
	 * Test that the curated preset exclusions keep Woo-critical handles
	 * un-delayed by default (existing exclude path, issue #932).
	 */
	public function test_inp_preset_exclusions_cover_woo_handles(): void {
		$this->stub_main_construction(
			array(
				'delayJS'          => true,
				'delayJSINPPreset' => true,
			)
		);

		$main           = new Main();
		$delay_excludes = $this->read_private_prop( $main, 'exclude_delay_js' );

		$this->assertContains( 'wc-cart-fragments', $delay_excludes );
		$this->assertContains( 'wc-checkout', $delay_excludes );
		$this->assertContains( 'woocommerce', $delay_excludes );
		$this->assertContains( 'wc-add-to-cart', $delay_excludes );
		$this->assertContains( 'wc-single-product', $delay_excludes );
	}

	/**
	 * Test that the Woo guardrail skips delay rewriting on cart pages and
	 * fails open (un-delayed tag, never fatal).
	 */
	public function test_delay_excluded_context_on_cart_returns_tag_unmodified(): void {
		$this->stub_main_construction(
			array(
				'delayJS' => true,
			)
		);
		$this->reset_delay_guard_superglobals();
		$this->stub_guard_request_env();
		Functions\when( 'is_cart' )->justReturn( true );

		$main = new Main();

		$this->assertTrue( $main->is_delay_excluded_context() );

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for add_defer_attribute() tests.
		$tag = '<script src="https://example.com/app.js" type="text/javascript"></script>';
		$this->assertSame( $tag, $main->add_defer_attribute( $tag, 'app' ) );

		$this->reset_delay_guard_superglobals();
	}

	/**
	 * Test that is_delay_js_safe_context() returns false (delay allowed)
	 * when no safe signals are present.
	 *
	 * @since NEXT
	 */
	public function test_delay_js_safe_context_returns_false_when_no_safe_signals(): void {
		$this->stub_main_construction(
			array(
				'delayJS' => true,
			)
		);
		$this->reset_delay_guard_superglobals();
		$this->stub_guard_request_env();
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'is_wc_endpoint_url' )->justReturn( false );
		Functions\when( 'get_the_ID' )->justReturn( 0 );

		$main = new Main();

		$this->assertFalse( $main->is_delay_excluded_context() );
		$this->assertFalse( $main->is_delay_js_safe_context() );

		$this->reset_delay_guard_superglobals();
	}

	/**
	 * Test that checkout and account pages are excluded delay contexts.
	 *
	 * @param string $tag_function Woo conditional tag returning true.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'woo_tag_provider' )]
	public function test_delay_excluded_context_on_woo_tags( string $tag_function ): void {
		$this->stub_main_construction(
			array(
				'delayJS' => true,
			)
		);
		$this->reset_delay_guard_superglobals();
		$this->stub_guard_request_env();
		Functions\when( $tag_function )->justReturn( true );

		$main = new Main();

		$this->assertTrue( $main->is_delay_excluded_context(), "Expected {$tag_function}() to mark an excluded delay context." );

		$this->reset_delay_guard_superglobals();
	}

	/**
	 * Test that add_defer_attribute skips Delay-JS rewriting on WooCommerce checkout pages.
	 *
	 * @since NEXT
	 */
	public function test_add_defer_attribute_skips_delay_on_checkout(): void {
		$this->stub_main_construction(
			array(
				'delayJS' => true,
			)
		);

		// Pin the other safe-context conditionals false: Brain Monkey stubs
		// are process-persistent, so a stale true here would pass the test
		// for the wrong reason.
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'is_wc_endpoint_url' )->justReturn( false );
		Functions\when( 'get_the_ID' )->justReturn( 0 );
		Functions\when( 'is_checkout' )->justReturn( true );

		$main = new Main();

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for add_defer_attribute() tests.
		$tag    = '<script src="https://example.com/app.js" type="text/javascript"></script>';
		$result = $main->add_defer_attribute( $tag, 'app' );

		$this->assertSame( $tag, $result );
	}

	/**
	 * Test that delayJSSafeMode=false disables the safe-context check entirely,
	 * so a checkout page does NOT skip Delay-JS.
	 *
	 * @since NEXT
	 */
	public function test_delay_js_safe_context_disabled_when_safe_mode_false(): void {
		$this->stub_main_construction(
			array(
				'delayJS'         => true,
				'delayJSSafeMode' => false,
			)
		);

		Functions\when( 'is_checkout' )->justReturn( true );

		$main = new Main();

		$this->assertFalse( $main->is_delay_js_safe_context() );
	}

	/**
	 * Test that a form shortcode in the current post content skips Delay-JS.
	 *
	 * @since NEXT
	 */
	public function test_delay_js_safe_context_skips_on_form_shortcode(): void {
		$this->stub_main_construction(
			array(
				'delayJS' => true,
			)
		);

		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'is_wc_endpoint_url' )->justReturn( false );
		Functions\when( 'get_the_ID' )->justReturn( 42 );
		Functions\when( 'has_shortcode' )->alias(
			static function ( $content, $shortcode ) {
				return 'contact-form-7' === $shortcode && false !== strpos( $content, '[contact-form-7]' );
			}
		);
		Functions\when( 'get_post_field' )->alias(
			static function ( $field ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				return 'post_content' === $field ? '[contact-form-7]' : '';
			}
		);

		$main = new Main();

		$this->assertTrue( $main->is_delay_js_safe_context() );
	}

	/**
	 * Provide the Woo conditional tags covered by the guardrail.
	 *
	 * @return array<string, array{string}>
	 */
	public static function woo_tag_provider(): array {
		return array(
			'cart'     => array( 'is_cart' ),
			'checkout' => array( 'is_checkout' ),
			'account'  => array( 'is_account_page' ),
		);
	}

	/**
	 * Test the slug path fallback, including subdirectory installs.
	 *
	 * @param string $request_uri Request URI fixture.
	 * @param bool   $expected Whether the context is excluded.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'woo_path_provider' )]
	public function test_delay_excluded_context_path_fallback( string $request_uri, bool $expected ): void {
		$this->stub_main_construction(
			array(
				'delayJS' => true,
			)
		);
		$this->reset_delay_guard_superglobals();
		$this->stub_guard_request_env();
		$_SERVER['REQUEST_URI'] = $request_uri;

		// Pin is_wc_endpoint_url false: earlier tests declare it process-wide
		// via Brain Monkey, and a stale declaration without an expectation
		// throws MissingFunctionExpectations, which the guardrail (correctly)
		// fails open on — flipping the `false` fixtures to true.
		Functions\when( 'is_wc_endpoint_url' )->justReturn( false );

		$main = new Main();

		$this->assertSame( $expected, $main->is_delay_excluded_context(), "Unexpected guardrail result for {$request_uri}." );

		$this->reset_delay_guard_superglobals();
	}

	/**
	 * Provide path fallback fixtures.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function woo_path_provider(): array {
		return array(
			'top-level cart'         => array( '/cart/', true ),
			'top-level checkout'     => array( '/checkout/', true ),
			'top-level my-account'   => array( '/my-account/', true ),
			'subdirectory checkout'  => array( '/shop/checkout/order-pay/123/', true ),
			'multisite subsite cart' => array( '/subsite/cart/', true ),
			'unrelated page'         => array( '/blog/hello-world/', false ),
			'lookalike slug'         => array( '/cartography/', false ),
		);
	}

	/**
	 * Test wc-ajax and add-to-cart request signals.
	 */
	public function test_delay_excluded_context_on_wc_ajax_and_add_to_cart(): void {
		$this->stub_main_construction(
			array(
				'delayJS' => true,
			)
		);
		$this->reset_delay_guard_superglobals();
		$this->stub_guard_request_env();

		$main = new Main();

		$_GET['wc-ajax'] = 'get_refreshed_fragments';
		$this->assertTrue( $main->is_delay_excluded_context() );
		unset( $_GET['wc-ajax'] );

		$_GET['add-to-cart'] = '123';
		$this->assertTrue( $main->is_delay_excluded_context() );
		unset( $_GET['add-to-cart'] );

		$_SERVER['QUERY_STRING'] = 'foo=bar&add-to-cart=123';
		$this->assertTrue( $main->is_delay_excluded_context() );

		$_SERVER['REQUEST_URI'] = '/wc-ajax/get_refreshed_fragments/';
		unset( $_SERVER['QUERY_STRING'] );
		$this->assertTrue( $main->is_delay_excluded_context() );

		$this->reset_delay_guard_superglobals();
	}

	/**
	 * Test that Woo cart cookies alone no longer blanket-disable delay.
	 *
	 * Visitor-scoped cookies would wipe out the INP win on every page for all
	 * shoppers; mini-cart fragments are protected by the per-handle Woo
	 * exclusions instead (issue #932 review).
	 */
	public function test_delay_not_excluded_by_cart_cookies_alone(): void {
		$this->stub_main_construction(
			array(
				'delayJS' => true,
			)
		);
		$this->reset_delay_guard_superglobals();
		$this->stub_guard_request_env();
		$_COOKIE['woocommerce_items_in_cart'] = '3';
		$_COOKIE['woocommerce_cart_hash']     = 'abc123';

		$main = new Main();

		$this->assertFalse( $main->is_delay_excluded_context() );

		$this->reset_delay_guard_superglobals();
	}

	/**
	 * Test builder preview/edit contexts are excluded delay contexts.
	 *
	 * @param string $param Query param fixture.
	 * @param string $value Query param value.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'builder_param_provider' )]
	public function test_delay_excluded_context_on_builder_params( string $param, string $value ): void {
		$this->stub_main_construction(
			array(
				'delayJS' => true,
			)
		);
		$this->reset_delay_guard_superglobals();
		$this->stub_guard_request_env();
		$_GET[ $param ] = $value;

		$main = new Main();

		$this->assertTrue( $main->is_delay_excluded_context(), "Expected ?{$param} to mark an excluded delay context." );

		$this->reset_delay_guard_superglobals();
	}

	/**
	 * Provide builder preview/edit query params.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function builder_param_provider(): array {
		return array(
			'elementor' => array( 'elementor-preview', '123' ),
			'divi'      => array( 'et_fb', '1' ),
			'wpbakery'  => array( 'vc_action', 'vc_inline' ),
			'bricks'    => array( 'bricks', 'run' ),
		);
	}

	/**
	 * Test that a normal frontend request is not an excluded delay context.
	 */
	public function test_delay_excluded_context_false_on_plain_frontend(): void {
		$this->stub_main_construction(
			array(
				'delayJS' => true,
			)
		);
		// Guard against superglobal leakage from other suites sharing the process.
		$this->reset_delay_guard_superglobals();

		$main = new Main();

		$this->assertFalse( $main->is_delay_excluded_context() );

		$this->reset_delay_guard_superglobals();
	}

	/**
	 * Test that the inline-script delay path honors the Woo guardrail, matching
	 * the external-script path in add_defer_attribute() (issue #932 review).
	 */
	public function test_inline_delay_skipped_in_excluded_context(): void {
		$this->reset_delay_guard_superglobals();
		$this->stub_guard_request_env();
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'untrailingslashit' )->returnArg();
		// Stub all three Woo tags: functions declared by earlier tests in the
		// same process throw MissingFunctionExpectations when called without
		// an expectation, which the guardrail (correctly) fails open on.
		Functions\when( 'is_cart' )->justReturn( true );

		$html    = '<html><head></head><body><script>var wppoGuard = 1;</script></body></html>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for inline delay tests.
		$options = array(
			'file_optimisation' => array(
				'delayJS' => true,
			),
		);

		$excluded = new \PerformanceOptimise\Inc\Minify\HTML( $html, $options );
		$this->assertStringNotContainsString( 'wppo/javascript', $excluded->get_minified_html() );

		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		$delayed = new \PerformanceOptimise\Inc\Minify\HTML( $html, $options );
		$this->assertStringContainsString( 'wppo/javascript', $delayed->get_minified_html() );

		$this->reset_delay_guard_superglobals();
	}

	/**
	 * Build a Main instance with given options without invoking the constructor.
	 *
	 * @param array $options wppo_settings options.
	 * @return Main
	 */
	private function make_main( array $options ): Main {
		$reflection = new \ReflectionClass( Main::class );
		$main       = $reflection->newInstanceWithoutConstructor();

		$prop = $reflection->getProperty( 'options' );
		$prop->setAccessible( true );
		$prop->setValue( $main, $options );

		return $main;
	}

	/**
	 * Build a fake WP_Script_Modules that records set_in_footer/set_fetchpriority.
	 *
	 * @return object
	 */
	private function make_fake_modules(): object {
		return new class() {
			/**
			 * Registered modules (public property, mirrors core).
			 *
			 * @var array
			 */
			public $registered = array(
				'interactive' => array(),
				'my-mod'      => array(),
			);

			/**
			 * Recorded calls.
			 *
			 * @var string[]
			 */
			public $calls = array();

			/**
			 * Record set_in_footer.
			 *
			 * @param string $id        Module id.
			 * @param bool   $in_footer Whether in footer.
			 * @return true
			 */
			public function set_in_footer( $id, $in_footer ) {
				$this->calls[] = 'footer:' . $id . ':' . ( $in_footer ? 'true' : 'false' );
				return true;
			}

			/**
			 * Record set_fetchpriority.
			 *
			 * @param string $id       Module id.
			 * @param string $priority Priority.
			 * @return true
			 */
			public function set_fetchpriority( $id, $priority ) {
				$this->calls[] = 'priority:' . $id . ':' . $priority;
				return true;
			}
		};
	}

	/**
	 * Stub wp_script_modules() + its function_exists probe.
	 *
	 * @param object $fake Fake modules instance.
	 */
	private function stub_script_modules( object $fake ): void {
		// WP 6.9+ gate for fetchpriority/in_footer (apply_module_loading_strategies).
		// @since NEXT.
		$GLOBALS['wp_version'] = '6.9';
		Functions\when( 'get_bloginfo' )->justReturn( '6.9' );
		Functions\when( 'wp_script_modules' )->justReturn( $fake );
		Functions\when( 'function_exists' )->alias(
			static function ( $function_name ) use ( $fake ) {
				if ( 'wp_script_modules' === $function_name ) {
					return true;
				}
				return \function_exists( $function_name );
			}
		);
		// Visitors (not logged in) are always eligible, so the logged-in gate
		// passes in the happy-path tests; a dedicated test covers the gate.
		Functions\when( 'is_user_logged_in' )->justReturn( false );
	}

	/**
	 * Test that script modules get footer + low fetchpriority when defer is on.
	 */
	public function test_apply_module_loading_strategies_defers_modules(): void {
		$main = $this->make_main(
			array(
				'file_optimisation' => array(
					'deferJS'        => true,
					'excludeDeferJS' => '',
				),
			)
		);
		$fake = $this->make_fake_modules();
		$this->stub_script_modules( $fake );

		$main->apply_module_loading_strategies();

		$this->assertContains( 'footer:interactive:true', $fake->calls );
		$this->assertContains( 'priority:interactive:low', $fake->calls );
		$this->assertContains( 'footer:my-mod:true', $fake->calls );
	}

	/**
	 * Test that the module pass is a no-op when defer JS is off.
	 */
	public function test_apply_module_loading_strategies_skips_when_defer_off(): void {
		$main = $this->make_main(
			array(
				'file_optimisation' => array(
					'deferJS'        => false,
					'excludeDeferJS' => '',
				),
			)
		);
		$fake = $this->make_fake_modules();
		$this->stub_script_modules( $fake );

		$main->apply_module_loading_strategies();

		$this->assertSame( array(), $fake->calls );
	}

	/**
	 * Test that excluded module handles are not deferred.
	 */
	public function test_apply_module_loading_strategies_respects_exclusions(): void {
		$main = $this->make_main(
			array(
				'file_optimisation' => array(
					'deferJS'        => true,
					'excludeDeferJS' => 'my-mod',
				),
			)
		);
		$fake = $this->make_fake_modules();
		$this->stub_script_modules( $fake );

		$main->apply_module_loading_strategies();

		$this->assertContains( 'footer:interactive:true', $fake->calls );
		$this->assertNotContains( 'footer:my-mod:true', $fake->calls );
	}

	/**
	 * Test that the module pass is skipped for logged-in users who are not
	 * eligible for cached/optimised output.
	 */
	public function test_apply_module_loading_strategies_skips_ineligible_logged_in(): void {
		$main = $this->make_main(
			array(
				'cache_settings'    => array(
					'enableLoggedInCache' => false,
				),
				'file_optimisation' => array(
					'deferJS'        => true,
					'excludeDeferJS' => '',
				),
			)
		);
		$fake = $this->make_fake_modules();
		$this->stub_script_modules( $fake );
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$main->apply_module_loading_strategies();

		$this->assertSame( array(), $fake->calls );
	}

	/**
	 * Build a fake WP_Scripts registry with one queued classic handle.
	 *
	 * Mirrors core state at wp_enqueue_scripts:1000 and records
	 * wp_script_add_data() writes so tests can assert the applied data keys.
	 *
	 * @return object Anonymous instance extending the WP_Scripts stand-in.
	 */
	private function make_fake_wp_scripts(): object {
		return new class() extends WP_Scripts {
			/**
			 * Queued handles (mirrors WP_Scripts::$queue).
			 *
			 * @var string[]
			 */
			public $queue = array( 'third-party-analytics' );

			/**
			 * Recorded data keys per handle (mirrors wp_script_add_data).
			 *
			 * @var array<string, array<string, mixed>>
			 */
			public $data = array();

			/**
			 * Record data keys so get_data() mirrors core.
			 *
			 * @param string $handle Script handle.
			 * @param string $key    Data key.
			 * @param mixed  $value  Data value.
			 * @return bool
			 */
			public function add_data( $handle, $key, $value ) {
				$this->data[ $handle ][ $key ] = $value;
				return true;
			}

			/**
			 * Read a recorded data key (mirrors WP_Dependencies::get_data()).
			 *
			 * @param string $handle Script handle.
			 * @param string $key    Data key.
			 * @return mixed
			 */
			public function get_data( $handle, $key ) {
				return $this->data[ $handle ][ $key ] ?? false;
			}
		};
	}

	/**
	 * Install the shared stubs add_defer_strategy() tests need: a recording
	 * wp_script_add_data(), a passthrough apply_filters() with the LiteSpeed
	 * disable-decision filter pinned false, and a reset LiteSpeed cache.
	 *
	 * Other suites in the same process may define LSCWP_V (a process-persistent
	 * constant), which makes LiteSpeed_Integration treat LSCache as active and
	 * disable the optimizer guard; the filter pin keeps the guard false here.
	 *
	 * @param array $recorded Recorded wp_script_add_data() calls (by reference).
	 * @return void
	 */
	private function stub_defer_strategy_env( array &$recorded ): void {
		$fake_scripts = $GLOBALS['wp_scripts'];
		Functions\when( 'wp_script_add_data' )->alias(
			static function ( $handle, $key, $value ) use ( &$recorded, $fake_scripts ) {
				$recorded[] = array( $handle, $key, $value );
				$fake_scripts->add_data( $handle, $key, $value );
				return true;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wppo_litespeed_should_disable_optimizer' === $hook ) {
					return false;
				}
				return $value;
			}
		);
		// The LiteSpeed guard checks has_filter('litespeed_can_optm') — keep it absent.
		// should_disable_wppo_optimizer() early-bails on is_lscache_active() (no
		// LSCWP_V constant / LiteSpeed classes in the unit environment), so the
		// LiteSpeed_Integration class itself needs no stubbing. When the class was
		// already loaded by another suite in the same process, its cached statics
		// must be reset so the guard re-evaluates on its real branch.
		Functions\when( 'has_filter' )->justReturn( false );
		if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'reset_cache' ) ) {
			\PerformanceOptimise\Inc\LiteSpeed_Integration::reset_cache();
		}
	}

	/**
	 * Test that add_defer_strategy() moves deferred classic scripts to the
	 * footer via the 'group' data key on WP 6.9+ (issue #879).
	 *
	 * Core's wp_enqueue_script() args handler maps in_footer to group=1 and
	 * WP_Scripts::set_group() reads get_data( $handle, 'group' ); the
	 * 'in_footer' data key itself is never read for classic scripts, so the
	 * earlier wp_script_add_data( $handle, 'in_footer', true ) call was a
	 * no-op. The fix must record 'group' (and never 'in_footer').
	 */
	public function test_add_defer_strategy_sets_group_for_footer_on_wp69(): void {
		$this->stub_main_construction(
			array(
				'deferJS'        => true,
				'delayJS'        => false,
				'excludeDeferJS' => '',
			)
		);
		$GLOBALS['wp_version'] = '6.9';
		Functions\when( 'get_bloginfo' )->justReturn( '6.9' );

		$main = $this->make_main(
			array(
				'file_optimisation' => array(
					'deferJS'        => true,
					'excludeDeferJS' => '',
				),
			)
		);

		// Set the private exclusion list to empty so every queued handle is deferred.
		$exclude_prop = new \ReflectionProperty( Main::class, 'exclude_defer_js' );
		$exclude_prop->setAccessible( true );
		$exclude_prop->setValue( $main, array() );

		$GLOBALS['wp_scripts'] = $this->make_fake_wp_scripts();

		$recorded = array();
		$this->stub_defer_strategy_env( $recorded );

		$main->add_defer_strategy();

		// The native in_footer migration must set 'group' (core reads the group
		// data key for footer placement) and must not write an unread
		// 'in_footer' data key.
		$group_calls = array_filter(
			$recorded,
			static function ( array $call ): bool {
				return 'third-party-analytics' === $call[0] && 'group' === $call[1];
			}
		);
		$this->assertNotEmpty( $group_calls, 'Deferred classic scripts must receive the group data key for footer placement on WP 6.9+.' );
		foreach ( $group_calls as $call ) {
			$this->assertSame( 1, $call[2] );
		}
		foreach ( $recorded as list( $handle, $key ) ) {
			$this->assertNotSame( 'in_footer', $key, "'in_footer' is not a read data key for classic scripts — use 'group'." );
			$this->assertSame( 'third-party-analytics', $handle );
		}
	}

	/**
	 * Test that the wppo_deferred_in_footer filter can keep a deferred handle
	 * in the head (opt-out escape hatch, issue #879 review).
	 */
	public function test_add_defer_strategy_respects_in_footer_filter_opt_out(): void {
		$this->stub_main_construction(
			array(
				'deferJS'        => true,
				'delayJS'        => false,
				'excludeDeferJS' => '',
			)
		);
		$GLOBALS['wp_version'] = '6.9';
		Functions\when( 'get_bloginfo' )->justReturn( '6.9' );

		$main = $this->make_main(
			array(
				'file_optimisation' => array(
					'deferJS'        => true,
					'excludeDeferJS' => '',
				),
			)
		);

		$exclude_prop = new \ReflectionProperty( Main::class, 'exclude_defer_js' );
		$exclude_prop->setAccessible( true );
		$exclude_prop->setValue( $main, array() );

		$GLOBALS['wp_scripts'] = $this->make_fake_wp_scripts();
		$fake_scripts          = $GLOBALS['wp_scripts'];

		$recorded = array();
		$this->stub_defer_strategy_env( $recorded );
		// Re-configure apply_filters so wppo_deferred_in_footer opts this handle out.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, ...$args ) {
				if ( 'wppo_litespeed_should_disable_optimizer' === $hook ) {
					return false;
				}
				if ( 'wppo_deferred_in_footer' === $hook && isset( $args[0] ) && 'third-party-analytics' === $args[0] ) {
					return false;
				}
				return $value;
			}
		);

		$main->add_defer_strategy();
		$this->assertArrayNotHasKey( 'group', $fake_scripts->data['third-party-analytics'] ?? array(), 'The wppo_deferred_in_footer opt-out must keep the handle out of the footer group.' );
		// The defer strategy itself still applies.
		$this->assertSame( 'defer', $fake_scripts->data['third-party-analytics']['strategy'] ?? null );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile
// Core is not loaded in the unit test environment; add_defer_strategy()
// requires $wp_scripts instanceof WP_Scripts, so a minimal stand-in is needed.
// Declared after the test class so the FileName sniff keeps treating this file
// as test code (the first class decides), mirroring WPPO_DB_Mock in
// DatabaseCleanupTest.php.

if ( ! class_exists( 'WP_Scripts' ) ) {
	/**
	 * Minimal WP_Scripts stand-in for unit tests.
	 *
	 * @package PerformanceOptimise\Tests
	 */
	class WP_Scripts {
		/**
		 * Queued handles.
		 *
		 * @var string[]
		 */
		public $queue = array();

		/**
		 * Registered scripts.
		 *
		 * @var array
		 */
		public $registered = array();
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
