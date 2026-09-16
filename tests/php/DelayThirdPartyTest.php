<?php
/**
 * Tests for the one-click third-party Delay-JS mode (issue #1217 review).
 *
 * Covers the review follow-ups: same-site host handling (apex/www/subdomain
 * CDN stay eager), settings-derived allowlist payload passed to the filter on
 * the buffered-HTML path, allowlist-wins precedence, inline-no-src eagerness,
 * cross-origin auto-detection, and case-insensitive exec stamping that ignores
 * async/defer inside quoted attribute values.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\LiteSpeed_Integration;
use Brain\Monkey\Functions;

/**
 * Tests for third-party delay candidate detection on both PHP paths.
 *
 * @package PerformanceOptimise\Tests
 */
class DelayThirdPartyTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap {
		setUp as protected wppoSetUp;
		tearDown as protected wppoTearDown;
	}

	/**
	 * Run the shared bootstrap and reset process-wide memos.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->wppoSetUp();
		Main::reset_delay_context_memo();
		LiteSpeed_Integration::reset_cache();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'untrailingslashit' )->returnArg();
	}

	/**
	 * Clean up memos and globals between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Main::reset_delay_context_memo();
		LiteSpeed_Integration::reset_cache();
		unset( $GLOBALS['wp_version'], $GLOBALS['wp_scripts'] );
		unset( $GLOBALS['wppo_allowlist_filter_payload'] );
		$this->wppoTearDown();
	}

	/**
	 * Stub the WP environment needed to construct Main with the given
	 * file_optimisation overrides (mirrors MainDelayDeferTest).
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
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );

		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'wppo_litespeed_should_disable_optimizer' === $hook ) {
					return false;
				}
				return $value;
			}
		);
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'get_the_ID' )->justReturn( 0 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'has_block' )->justReturn( false );
		Functions\when( 'is_wc_endpoint_url' )->justReturn( false );

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
	 * Pin the delay-guard conditional tags for buffered-path tests.
	 *
	 * Brain Monkey eval-declares a function on first when() and that
	 * declaration persists process-wide; a leaked declaration without an
	 * expectation makes the guardrail fail closed (excluded). Pinning them
	 * false keeps every buffered-path test valid in isolation AND in a
	 * full-suite run (mirrors MainDelayDeferTest).
	 *
	 * @return void
	 */
	private function stub_delay_guard_env(): void {
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'is_wc_endpoint_url' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'get_the_ID' )->justReturn( 0 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'has_block' )->justReturn( false );
	}

	/**
	 * Build a Main instance without invoking the constructor.
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
	 * Denylist hits by src (and by handle keyword) are candidates.
	 */
	public function test_denylist_hit_is_candidate(): void {
		$main = $this->make_main( array( 'file_optimisation' => array() ) );

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for candidate tests.
		$tag = '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-X"></script>';
		$this->assertTrue( $main->is_delay_third_party_candidate( $tag, 'my-gtag-loader' ) );
	}

	/**
	 * The user allowlist (textarea) always wins over the denylist.
	 */
	public function test_allowlist_wins_over_denylist(): void {
		$main = $this->make_main(
			array(
				'file_optimisation' => array(
					'delayJSThirdPartyAllowlist' => "googletagmanager.com\n",
				),
			)
		);

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for candidate tests.
		$tag = '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-X"></script>';
		$this->assertFalse( $main->is_delay_third_party_candidate( $tag, 'gtm-loader' ) );
	}

	/**
	 * Inline scripts (no src) are never candidates in third-party mode.
	 */
	public function test_inline_script_without_src_is_never_candidate(): void {
		$main = $this->make_main( array( 'file_optimisation' => array() ) );

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for candidate tests.
		$this->assertFalse( $main->is_delay_third_party_candidate( '<script>var gtag=1;</script>', 'gtag-inline' ) );
	}

	/**
	 * A genuinely foreign host auto-qualifies via cross-origin detection.
	 */
	public function test_foreign_host_is_candidate(): void {
		$main = $this->make_main( array( 'file_optimisation' => array() ) );

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for candidate tests.
		$tag = '<script src="https://tracker.foreign-cdn.net/app.js"></script>';
		$this->assertTrue( $main->is_delay_third_party_candidate( $tag, 'foreign-lib' ) );
	}

	/**
	 * Same-site hosts (first-party subdomain CDN) stay eager.
	 */
	public function test_first_party_subdomain_cdn_stays_eager(): void {
		$main = $this->make_main( array( 'file_optimisation' => array() ) );

		// home_url() is stubbed to http://example.com by the bootstrap.
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for candidate tests.
		$tag = '<script src="https://cdn.example.com/assets/app.js"></script>';
		$this->assertFalse( $main->is_delay_third_party_candidate( $tag, 'app-bundle' ) );
	}

	/**
	 * WWW vs apex mismatch stays eager.
	 */
	public function test_www_vs_apex_stays_eager(): void {
		Functions\when( 'home_url' )->justReturn( 'https://www.example.com' );
		$main = $this->make_main( array( 'file_optimisation' => array() ) );

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for candidate tests.
		$tag = '<script src="https://example.com/assets/app.js"></script>';
		$this->assertFalse( $main->is_delay_third_party_candidate( $tag, 'app-bundle' ) );
	}

	/**
	 * Relative srcs (same host, no denylist hit) are not candidates.
	 */
	public function test_relative_src_is_not_candidate(): void {
		$main = $this->make_main( array( 'file_optimisation' => array() ) );

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for candidate tests.
		$tag = '<script src="/wp-content/uploads/app.js"></script>';
		$this->assertFalse( $main->is_delay_third_party_candidate( $tag, 'app-bundle' ) );
	}

	/**
	 * Same-site host comparison unit cases.
	 *
	 * @return void
	 */
	public function test_is_same_site_script_host_cases(): void {
		$this->assertTrue( Main::is_same_site_script_host( 'example.com', 'example.com' ) );
		$this->assertTrue( Main::is_same_site_script_host( 'www.example.com', 'example.com' ) );
		$this->assertTrue( Main::is_same_site_script_host( 'example.com', 'www.example.com' ) );
		$this->assertTrue( Main::is_same_site_script_host( 'cdn.example.com', 'example.com' ) );
		$this->assertTrue( Main::is_same_site_script_host( 'example.com', 'cdn.example.com' ) );
		$this->assertTrue( Main::is_same_site_script_host( 'example.com.', 'www.example.com' ) );
		$this->assertFalse( Main::is_same_site_script_host( 'evil-example.com', 'example.com' ) );
		$this->assertFalse( Main::is_same_site_script_host( 'example.com.evil.com', 'example.com' ) );
		$this->assertFalse( Main::is_same_site_script_host( 'tracker.other.net', 'example.com' ) );
		$this->assertFalse( Main::is_same_site_script_host( '', 'example.com' ) );
	}

	/**
	 * Exec stamping preserves async/defer on the tag-filter path.
	 */
	public function test_add_defer_attribute_stamps_exec_semantics(): void {
		$this->stub_main_construction(
			array(
				'delayJS'           => true,
				'delayJSThirdParty' => true,
			)
		);

		$main = new Main();

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for add_defer_attribute() tests.
		$async = '<script src="https://tracker.foreign-cdn.net/a.js" async></script>';
		$this->assertStringContainsString( 'data-wppo-delay-exec="async"', $main->add_defer_attribute( $async, 'foreign-a' ) );

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for add_defer_attribute() tests.
		$defer = '<script src="https://tracker.foreign-cdn.net/d.js" defer></script>';
		$this->assertStringContainsString( 'data-wppo-delay-exec="defer"', $main->add_defer_attribute( $defer, 'foreign-d' ) );
	}

	/**
	 * Uppercase/non-lowercase script open tags still get the exec stamp.
	 */
	public function test_add_defer_attribute_stamps_uppercase_script_tag(): void {
		$this->stub_main_construction(
			array(
				'delayJS'           => true,
				'delayJSThirdParty' => true,
			)
		);

		$main = new Main();

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for add_defer_attribute() tests.
		$tag    = '<SCRIPT SRC="https://tracker.foreign-cdn.net/a.js" ASYNC></SCRIPT>';
		$result = $main->add_defer_attribute( $tag, 'foreign-a' );

		$this->assertStringContainsString( 'data-wppo-delay-exec="async"', $result );
		$this->assertStringContainsString( 'wppo-src=', $result );
	}

	/**
	 * Async/defer inside quoted attribute values must not stamp exec semantics.
	 */
	public function test_add_defer_attribute_ignores_quoted_async_values(): void {
		$this->stub_main_construction(
			array(
				'delayJS'           => true,
				'delayJSThirdParty' => true,
			)
		);

		$main = new Main();

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for add_defer_attribute() tests.
		$tag    = '<script src="https://tracker.foreign-cdn.net/a.js" data-info="async loader"></script>';
		$result = $main->add_defer_attribute( $tag, 'foreign-a' );

		$this->assertStringContainsString( 'wppo-src=', $result );
		$this->assertStringNotContainsString( 'data-wppo-delay-exec', $result );
	}

	/**
	 * Third-party mode leaves first-party scripts eager on the tag-filter path.
	 */
	public function test_add_defer_attribute_leaves_first_party_eager_in_third_party_mode(): void {
		$this->stub_main_construction(
			array(
				'delayJS'           => true,
				'delayJSThirdParty' => true,
			)
		);

		$main = new Main();

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for add_defer_attribute() tests.
		$tag = '<script src="https://cdn.example.com/assets/app.js"></script>';
		$this->assertSame( $tag, $main->add_defer_attribute( $tag, 'app-bundle' ) );
	}

	/**
	 * The buffered path delays a foreign host and keeps first-party eager.
	 */
	public function test_buffered_path_third_party_gate(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		$this->stub_delay_guard_env();
		$options = array(
			'file_optimisation' => array(
				'delayJS'           => true,
				'delayJSThirdParty' => true,
			),
		);

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for buffered-path tests.
		$foreign = new \PerformanceOptimise\Inc\Minify\HTML( '<html><head></head><body><script src="https://tracker.foreign-cdn.net/app.js"></script></body></html>', $options );
		$this->assertStringContainsString( 'wppo/javascript', $foreign->get_minified_html() );

		Main::reset_delay_context_memo();
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for buffered-path tests.
		$first_party = new \PerformanceOptimise\Inc\Minify\HTML( '<html><head></head><body><script src="https://cdn.example.com/assets/app.js"></script></body></html>', $options );
		$this->assertStringNotContainsString( 'wppo/javascript', $first_party->get_minified_html() );
	}

	/**
	 * The buffered path passes the settings-derived allowlist into the filter
	 * so array_merge-style filters keep textarea entries, and a filter-added
	 * entry allowlists the host on this path too.
	 */
	public function test_buffered_path_allowlist_filter_receives_settings_list(): void {
		$GLOBALS['wppo_allowlist_filter_payload'] = null;
		$this->stub_delay_guard_env();
		Functions\when( 'has_filter' )->alias(
			static function ( $hook ) {
				return 'wppo_delay_js_third_party_allowlist' === $hook;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'wppo_delay_js_third_party_allowlist' === $hook ) {
					$GLOBALS['wppo_allowlist_filter_payload'] = $value;
					// Real-world filter shape: merge additions onto the list.
					return array_merge( (array) $value, array( 'extra-allow.example' ) );
				}
				if ( 'wppo_litespeed_should_disable_optimizer' === $hook ) {
					return false;
				}
				return $value;
			}
		);

		// The textarea entry does not match the script, so the filter (not
		// the settings short-circuit) is what observes the payload; the
		// script stays delayed because neither list matches it.
		$options = array(
			'file_optimisation' => array(
				'delayJS'                    => true,
				'delayJSThirdParty'          => true,
				'delayJSThirdPartyAllowlist' => "some-unrelated-entry.example\n",
			),
		);

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for buffered-path tests.
		$html   = new \PerformanceOptimise\Inc\Minify\HTML( '<html><head></head><body><script src="https://tracker.foreign-cdn.net/app.js"></script></body></html>', $options );
		$result = $html->get_minified_html();

		$this->assertIsArray( $GLOBALS['wppo_allowlist_filter_payload'] );
		$this->assertContains( 'some-unrelated-entry.example', $GLOBALS['wppo_allowlist_filter_payload'] );
		$this->assertStringContainsString( 'wppo/javascript', $result );

		// A filter-added entry matching the host allowlists it on this path.
		Main::reset_delay_context_memo();
		$GLOBALS['wppo_allowlist_filter_payload'] = null;
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'wppo_delay_js_third_party_allowlist' === $hook ) {
					$GLOBALS['wppo_allowlist_filter_payload'] = $value;
					return array_merge( (array) $value, array( 'tracker.foreign-cdn.net' ) );
				}
				if ( 'wppo_litespeed_should_disable_optimizer' === $hook ) {
					return false;
				}
				return $value;
			}
		);

		$options['file_optimisation']['delayJSThirdPartyAllowlist'] = '';
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for buffered-path tests.
		$allowed = new \PerformanceOptimise\Inc\Minify\HTML( '<html><head></head><body><script src="https://tracker.foreign-cdn.net/app.js"></script></body></html>', $options );
		$this->assertStringNotContainsString( 'wppo/javascript', $allowed->get_minified_html() );
	}
}
