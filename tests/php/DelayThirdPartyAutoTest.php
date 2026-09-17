<?php
/**
 * Tests for the opt-in auto third-party Delay-JS mode (issue #1314).
 *
 * Covers the additive `delayJSThirdPartyAuto` toggle: off-by-default
 * behavior (upgrade-safe), bool sanitization, curated auto-pattern
 * matching, allowlist-wins parity with the manual path, unconditional
 * builder/commerce exclusions, load-when-idle strategy parity on both the
 * `script_loader_tag` and buffered-HTML paths, and the admin migration.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Util;
use PerformanceOptimise\Inc\LiteSpeed_Integration;
use Brain\Monkey\Functions;

/**
 * Tests for auto third-party delay candidacy and idle parity.
 *
 * @package PerformanceOptimise\Tests
 */
class DelayThirdPartyAutoTest extends \PHPUnit\Framework\TestCase {
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
		Main::reset_delay_third_party_auto_cache();
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
		Main::reset_delay_third_party_auto_cache();
		LiteSpeed_Integration::reset_cache();
		unset( $GLOBALS['wp_version'], $GLOBALS['wp_scripts'] );
		$this->wppoTearDown();
	}

	/**
	 * Stub the WP environment needed to construct Main with the given
	 * file_optimisation overrides (mirrors DelayThirdPartyTest).
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
	 * Pin the delay-guard conditional tags for candidate/buffered tests.
	 *
	 * @return void
	 */
	private function stub_delay_guard_env(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'is_wc_endpoint_url' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'get_the_ID' )->justReturn( 0 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'has_block' )->justReturn( false );
		// compute_delay_excluded_context() calls wp_parse_url() inside the
		// fail-closed Store-API probe (#1315): without this stub Brain Monkey
		// throws, the probe returns true, and every buffered-path delay test
		// sees unchanged markup. Alias to PHP's parse_url (same signature).
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_unslash' )->returnArg();
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
		$prop->setValue( $main, $options );

		return $main;
	}

	/**
	 * The auto toggle defaults to off so upgrades keep current behavior.
	 */
	public function test_auto_defaults_off(): void {
		$defaults = Util::get_default_settings();

		$this->assertArrayHasKey( 'delayJSThirdPartyAuto', $defaults['file_optimisation'] );
		$this->assertFalse( $defaults['file_optimisation']['delayJSThirdPartyAuto'] );
	}

	/**
	 * The auto toggle sanitizes to bool, failing safe to off.
	 */
	public function test_auto_sanitize_normalizes_bool_fail_safe_off(): void {
		Functions\when( 'has_filter' )->justReturn( false );

		$clean = Util::sanitize_settings_recursively( array( 'delayJSThirdPartyAuto' => true ) );
		$this->assertTrue( $clean['delayJSThirdPartyAuto'] );

		$clean = Util::sanitize_settings_recursively( array( 'delayJSThirdPartyAuto' => '1' ) );
		$this->assertTrue( $clean['delayJSThirdPartyAuto'] );

		$clean = Util::sanitize_settings_recursively( array( 'delayJSThirdPartyAuto' => '0' ) );
		$this->assertFalse( $clean['delayJSThirdPartyAuto'] );

		$clean = Util::sanitize_settings_recursively( array( 'delayJSThirdPartyAuto' => 'banana' ) );
		$this->assertFalse( $clean['delayJSThirdPartyAuto'], 'Unrecognized values must fail safe to off' );
	}

	/**
	 * The curated auto list is non-empty and covers known vendors.
	 */
	public function test_auto_patterns_cover_known_vendors(): void {
		Functions\when( 'has_filter' )->justReturn( false );

		$patterns = Main::get_delay_js_third_party_auto_patterns();

		$this->assertNotEmpty( $patterns );
		$this->assertContains( 'googletagmanager.com', $patterns );
		$this->assertContains( 'connect.facebook.net', $patterns );
		$this->assertContains( 'clarity.ms', $patterns );
	}

	/**
	 * A non-array filter return fails open to the built-in preset.
	 */
	public function test_auto_patterns_filter_failure_fails_open(): void {
		Functions\when( 'has_filter' )->alias(
			static function ( $hook ) {
				return 'wppo_delay_js_third_party_auto_patterns' === $hook;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'wppo_delay_js_third_party_auto_patterns' === $hook ) {
					return 'not-an-array';
				}
				return $value;
			}
		);

		$patterns = Main::get_delay_js_third_party_auto_patterns();

		$this->assertContains( 'googletagmanager.com', $patterns );
	}

	/**
	 * Known-vendor srcs are auto candidates.
	 */
	public function test_auto_candidate_matches_known_vendor_src(): void {
		$this->stub_delay_guard_env();
		$main = $this->make_main( array( 'file_optimisation' => array() ) );

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for candidate tests.
		$tag = '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-X"></script>';
		$this->assertTrue( $main->is_delay_third_party_auto_candidate( $tag, 'site-metrics' ) );
	}

	/**
	 * Handle keywords also match (word-boundary, consistent with delay matching).
	 */
	public function test_auto_candidate_matches_handle_keyword(): void {
		$this->stub_delay_guard_env();
		$main = $this->make_main( array( 'file_optimisation' => array() ) );

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for candidate tests.
		$tag = '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-X"></script>';
		$this->assertTrue( Main::matches_third_party_auto_pattern( 'my-googletagmanager-loader', $tag ) );
		$this->assertFalse( Main::matches_third_party_auto_pattern( 'site-metrics', 'https://example.com/app.js' ) );
	}

	/**
	 * The user allowlist always wins over auto patterns (parity with manual path).
	 */
	public function test_auto_candidate_allowlist_wins(): void {
		$this->stub_delay_guard_env();
		$main = $this->make_main(
			array(
				'file_optimisation' => array(
					'delayJSThirdPartyAllowlist' => "googletagmanager.com\n",
				),
			)
		);

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for candidate tests.
		$tag = '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-X"></script>';
		$this->assertFalse( $main->is_delay_third_party_auto_candidate( $tag, 'site-metrics' ) );
	}

	/**
	 * First-party scripts stay eager in auto mode (no generic cross-origin rule).
	 */
	public function test_auto_candidate_first_party_stays_eager(): void {
		$this->stub_delay_guard_env();
		$main = $this->make_main( array( 'file_optimisation' => array() ) );

		// home_url() is stubbed to http://example.com by the bootstrap.
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for candidate tests.
		$tag = '<script src="https://cdn.example.com/assets/app.js"></script>';
		$this->assertFalse( $main->is_delay_third_party_auto_candidate( $tag, 'app-bundle' ) );

		// Unknown foreign hosts (no curated pattern) also stay eager in
		// auto mode — only known vendors qualify.
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for candidate tests.
		$foreign = '<script src="https://tracker.foreign-cdn.net/app.js"></script>';
		$this->assertFalse( $main->is_delay_third_party_auto_candidate( $foreign, 'foreign-lib' ) );
	}

	/**
	 * Inline scripts (no src) are never auto candidates.
	 */
	public function test_auto_candidate_inline_without_src_is_never_candidate(): void {
		$this->stub_delay_guard_env();
		$main = $this->make_main( array( 'file_optimisation' => array() ) );

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for candidate tests.
		$this->assertFalse( $main->is_delay_third_party_auto_candidate( '<script>var gtag=1;</script>', 'gtag-inline' ) );
	}

	/**
	 * Commerce contexts never auto-delay, unconditionally.
	 */
	public function test_auto_candidate_excluded_in_commerce_context(): void {
		$this->stub_delay_guard_env();
		Main::reset_delay_context_memo();
		Functions\when( 'is_checkout' )->justReturn( true );
		$main = $this->make_main( array( 'file_optimisation' => array() ) );

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for candidate tests.
		$tag = '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-X"></script>';
		$this->assertFalse( $main->is_delay_third_party_auto_candidate( $tag, 'site-metrics' ) );

		Main::reset_delay_context_memo();
	}

	/**
	 * Auto-delayed scripts stamp the idle strategy (load-when-idle parity).
	 */
	public function test_add_defer_attribute_auto_stamps_idle_strategy(): void {
		$this->stub_main_construction(
			array(
				'delayJS'               => true,
				'delayJSThirdPartyAuto' => true,
			)
		);

		$main = new Main();

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for add_defer_attribute() tests.
		$tag    = '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-X"></script>';
		$result = $main->add_defer_attribute( $tag, 'site-metrics' );

		$this->assertStringContainsString( 'wppo-src=', $result );
		$this->assertStringContainsString( 'data-wppo-delay-strategy="idle"', $result );
	}

	/**
	 * Upgrade parity: with auto absent, behavior is unchanged (interaction default, no idle stamp).
	 */
	public function test_upgrade_without_auto_keeps_interaction_default(): void {
		$this->stub_main_construction(
			array(
				'delayJS'           => true,
				'delayJSThirdParty' => true,
			)
		);

		$main = new Main();

		// Manual-mode foreign candidate still delays with the interaction default.
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for add_defer_attribute() tests.
		$tag    = '<script src="https://tracker.foreign-cdn.net/app.js"></script>';
		$result = $main->add_defer_attribute( $tag, 'foreign-lib' );

		$this->assertStringContainsString( 'wppo-src=', $result );
		$this->assertStringNotContainsString( 'data-wppo-delay-strategy="idle"', $result );

		// Auto-only vendor src stays eager when neither mode is on.
		$this->stub_main_construction( array( 'delayJS' => true ) );
		Main::reset_delay_context_memo();
		$fresh = new Main();

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for add_defer_attribute() tests.
		$vendor = '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-X"></script>';
		$this->assertStringContainsString( 'wppo-src=', $fresh->add_defer_attribute( $vendor, 'site-metrics' ), 'Plain delayJS mode still delays everything (pre-existing behavior)' );
	}

	/**
	 * The buffered path delays auto vendors with idle parity and keeps first-party eager.
	 */
	public function test_buffered_path_auto_gate_with_idle_parity(): void {
		$this->stub_delay_guard_env();
		$options = array(
			'file_optimisation' => array(
				'delayJS'               => true,
				'delayJSThirdPartyAuto' => true,
			),
		);

		// Fixture uses mouseflow (an auto pattern with no always-on base-preset
		// exclusion): googletagmanager/gtm fixtures stay eager via the
		// always-applied base preset, so they cannot prove auto delay here.
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for buffered-path tests.
		$vendor = new \PerformanceOptimise\Inc\Minify\HTML( '<html><head></head><body><script src="https://cdn.mouseflow.com/website.js"></script></body></html>', $options );
		$markup = $vendor->get_minified_html();
		$this->assertStringContainsString( 'wppo/javascript', $markup );
		$this->assertStringContainsString( 'data-wppo-delay-strategy="idle"', $markup );

		Main::reset_delay_context_memo();
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for buffered-path tests.
		$first_party = new \PerformanceOptimise\Inc\Minify\HTML( '<html><head></head><body><script src="https://cdn.example.com/assets/app.js"></script></body></html>', $options );
		$this->assertStringNotContainsString( 'wppo/javascript', $first_party->get_minified_html() );
	}

	/**
	 * The buffered path honors the allowlist in auto mode.
	 */
	public function test_buffered_path_auto_honors_allowlist(): void {
		$this->stub_delay_guard_env();
		$options = array(
			'file_optimisation' => array(
				'delayJS'                    => true,
				'delayJSThirdPartyAuto'      => true,
				'delayJSThirdPartyAllowlist' => "mouseflow.com\n",
			),
		);

		// Same mouseflow fixture as the parity test so the allowlist (not the
		// base preset) is what keeps the tag eager.
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for buffered-path tests.
		$html = new \PerformanceOptimise\Inc\Minify\HTML( '<html><head></head><body><script src="https://cdn.mouseflow.com/website.js"></script></body></html>', $options );
		$this->assertStringNotContainsString( 'wppo/javascript', $html->get_minified_html() );
	}

	/**
	 * The migration backfills the additive key as off without touching other keys.
	 */
	public function test_migration_backfills_auto_off(): void {
		$stored = array(
			'file_optimisation' => array(
				'delayJS' => true,
			),
		);
		Functions\when( 'get_option' )->alias(
			static function ( $option, $default_value = false ) use ( &$stored ) {
				if ( 'wppo_settings' === $option ) {
					return $stored;
				}
				return $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $option, $value ) use ( &$stored ) {
				if ( 'wppo_settings' === $option ) {
					$stored = $value;
					return true;
				}
				return false;
			}
		);

		$main = $this->make_main( array( 'file_optimisation' => array( 'delayJS' => true ) ) );
		$main->maybe_migrate_third_party_auto();

		$this->assertArrayHasKey( 'delayJSThirdPartyAuto', $stored['file_optimisation'] );
		$this->assertFalse( $stored['file_optimisation']['delayJSThirdPartyAuto'] );
		$this->assertTrue( $stored['file_optimisation']['delayJS'], 'Existing keys must survive migration' );
	}

	/**
	 * The migration is a no-op when the key already exists.
	 */
	public function test_migration_skips_when_key_exists(): void {
		$stored = array(
			'file_optimisation' => array(
				'delayJSThirdPartyAuto' => true,
			),
		);
		$writes = array();
		Functions\when( 'get_option' )->alias(
			static function ( $option, $default_value = false ) use ( &$stored ) {
				if ( 'wppo_settings' === $option ) {
					return $stored;
				}
				return $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $option, $value ) use ( &$writes ) {
				$writes[] = array( $option, $value );
				return true;
			}
		);

		$main = $this->make_main( array( 'file_optimisation' => array( 'delayJSThirdPartyAuto' => true ) ) );
		$main->maybe_migrate_third_party_auto();

		$this->assertSame( array(), $writes, 'Migration must not write when the key exists' );
	}
}
