<?php
/**
 * Regression tests: late-registered `wppo_exclude_delay_js` callbacks.
 *
 * The plugin applies that filter while it bootstraps, and plugins load
 * before the active theme. A theme registering an exclusion from
 * functions.php therefore had no effect on the handle-level rewrite: the
 * script was still swapped to `wppo-src` and never executed, which
 * silently broke whatever depended on it.
 *
 * These tests pin the contract that an exclusion registered after
 * `new Main()` — the real ordering for a theme — takes effect on the
 * first tag processed.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;
use Brain\Monkey\Functions;

/**
 * Late delay-JS exclusion coverage.
 *
 * @package PerformanceOptimise\Tests
 */
class DelayExclusionLateFilterTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap {
		setUp as protected wppoSetUp;
		tearDown as protected wppoTearDown;
	}

	/**
	 * Registered filter callbacks, keyed by hook, for the in-test registry.
	 *
	 * @var array<string, array<int, callable>>
	 */
	private array $registered = array();

	/**
	 * Run the shared bootstrap and clear the process-wide delay-context memo.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->wppoSetUp();
		$this->registered = array();
		Main::reset_delay_context_memo();
	}

	/**
	 * Tear the shared bootstrap down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->registered = array();
		$this->wppoTearDown();
	}

	/**
	 * Install a real, minimal filter registry over Brain Monkey's stubs.
	 *
	 * @return void
	 */
	private function install_filter_registry(): void {
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback ) {
				$this->registered[ $hook ][] = $callback;
				return true;
			}
		);

		Functions\when( 'has_filter' )->alias(
			function ( $hook ) {
				return ! empty( $this->registered[ $hook ] );
			}
		);

		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				foreach ( $this->registered[ $hook ] ?? array() as $callback ) {
					$value = $callback( $value );
				}
				return $value;
			}
		);
	}

	/**
	 * Build a Main instance with the standard WP function stubs applied.
	 *
	 * @param array $file_overrides file_optimisation overrides.
	 * @return Main
	 */
	private function make_main( array $file_overrides ): Main {
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

		Functions\when( 'function_exists' )->alias(
			static function ( $function_name ) {
				if ( 'WP_Filesystem' === $function_name || 'wp_is_block_theme' === $function_name ) {
					return true;
				}
				return \function_exists( $function_name );
			}
		);

		$this->install_filter_registry();

		return new Main();
	}

	/**
	 * A theme registering the filter after Main is built must still have its
	 * handle left un-delayed, while unrelated handles still delay.
	 */
	public function test_filter_registered_after_bootstrap_excludes_handle(): void {
		// Plugin bootstrap: plugins_loaded, before the theme exists.
		$main = $this->make_main( array( 'delayJS' => true ) );

		// Theme functions.php, running after the plugin has bootstrapped.
		add_filter(
			'wppo_exclude_delay_js',
			static function ( array $exclusions ): array {
				$exclusions[] = 'theme-nav';
				return $exclusions;
			}
		);

		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML.
		$tag = '<script src="https://example.com/app.js" type="text/javascript"></script>';
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript

		// The theme's own handle keeps its src and is never made inert.
		$this->assertSame( $tag, $main->add_defer_attribute( $tag, 'theme-nav' ) );
	}

	/**
	 * The exclusion is targeted: an unrelated handle is still delayed, so
	 * late resolution cannot silently disable delay JS wholesale.
	 */
	public function test_unrelated_handle_is_still_delayed(): void {
		$main = $this->make_main( array( 'delayJS' => true ) );

		add_filter(
			'wppo_exclude_delay_js',
			static function ( array $exclusions ): array {
				$exclusions[] = 'theme-nav';
				return $exclusions;
			}
		);

		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML.
		$tag = '<script src="https://example.com/app.js" type="text/javascript"></script>';
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript

		$this->assertStringContainsString( 'wppo-src', $main->add_defer_attribute( $tag, 'some-other-plugin' ) );
	}

	/**
	 * Without any filter the rewrite behaves exactly as before, so the
	 * late-resolution path cannot quietly become a no-op.
	 */
	public function test_handle_is_delayed_when_no_filter_is_registered(): void {
		$main = $this->make_main( array( 'delayJS' => true ) );

		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML.
		$tag = '<script src="https://example.com/app.js" type="text/javascript"></script>';
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript

		$this->assertStringContainsString( 'wppo-src', $main->add_defer_attribute( $tag, 'theme-nav' ) );
	}
}
