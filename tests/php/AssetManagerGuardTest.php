<?php
/**
 * Tests for the per-page Script & Style Manager dependency guard (issue #1406).
 *
 * Covers Asset_Manager::filter_safe_handles() dependent-subtree refusal,
 * commerce-handle immunity, the commerce-context bypass, the global
 * assetManagerEnabled gate, the end-to-end dequeue_selected_assets() flow,
 * Metabox save-time protected-handle stripping, and the settings
 * export/import round-trip of the manager rules.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Asset_Manager;
use PerformanceOptimise\Inc\Metabox;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests for the Asset Manager dependency guard.
 *
 * @package PerformanceOptimise\Tests
 */
class AssetManagerGuardTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Settings fixture served to Util::get_settings() via get_option().
	 *
	 * @var array
	 */
	private $settings_fixture = array();

	/**
	 * Set up Brain Monkey and common stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		\PerformanceOptimise\Inc\Util::reset_runtime_caches();
		\PerformanceOptimise\Inc\Asset_Manager::reset_request_state();
		$this->settings_fixture                       = array();
		$GLOBALS['wppo_asset_guard_dequeued_scripts'] = array();
		$GLOBALS['wppo_asset_guard_dequeued_styles']  = array();
		$this->define_dequeue_doubles();

		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'get_option' )->alias(
			function ( $key, $fallback = false ) {
				if ( 'wppo_settings' === $key ) {
					return $this->settings_fixture;
				}
				return $fallback;
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 123 );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		unset( $_SERVER['REQUEST_URI'] );
	}

	/**
	 * Define isolated dequeue doubles via Brain Monkey only.
	 *
	 * No eval'd real functions and no writes to other files' globals: each
	 * when()->alias() mock is owned by the current Brain Monkey session
	 * (torn down per test below), so this file stays green regardless of
	 * suite ordering relative to CoreTweaksTest, which manages its own
	 * wp_dequeue_script double and globals independently.
	 *
	 * @return void
	 */
	private function define_dequeue_doubles(): void {
		Functions\when( 'wp_dequeue_script' )->alias(
			static function ( $handle ) {
				$GLOBALS['wppo_asset_guard_dequeued_scripts'][] = $handle;
			}
		);
		Functions\when( 'wp_deregister_script' )->justReturn( null );
		Functions\when( 'wp_dequeue_style' )->alias(
			static function ( $handle ) {
				$GLOBALS['wppo_asset_guard_dequeued_styles'][] = $handle;
			}
		);
		Functions\when( 'wp_deregister_style' )->justReturn( null );
	}

	/**
	 * Handles passed to wp_dequeue_script() during dequeue tests.
	 *
	 * @return string[]
	 */
	private function dequeued_scripts(): array {
		return array_values( $GLOBALS['wppo_asset_guard_dequeued_scripts'] ?? array() );
	}

	/**
	 * Handles passed to wp_dequeue_style() during dequeue tests.
	 *
	 * @return string[]
	 */
	private function dequeued_styles(): array {
		return array_values( $GLOBALS['wppo_asset_guard_dequeued_styles'] ?? array() );
	}

	/**
	 * Tear down superglobal fixtures and the Brain Monkey session.
	 *
	 * Closing the session per test restores any Patchwork override of an
	 * already-declared wp_dequeue_script() (e.g. when CoreTweaksTest runs
	 * first) and drops this file's doubles, so no stub leaks across files.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		\PerformanceOptimise\Inc\Asset_Manager::reset_request_state();
		unset( $_SERVER['REQUEST_URI'] );
		unset( $GLOBALS['wp_scripts'], $GLOBALS['wp_styles'] );
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a WP_Scripts-like registry double.
	 *
	 * @param array<string,string[]> $map   Handle => deps.
	 * @param string[]               $queue Queued handles.
	 * @return stdClass Registry double.
	 */
	private function make_registry( array $map, array $queue ): stdClass {
		$registry             = new stdClass();
		$registry->registered = array();
		foreach ( $map as $handle => $deps ) {
			$item                            = new stdClass();
			$item->src                       = 'https://example.com/' . $handle . '.js';
			$item->deps                      = $deps;
			$registry->registered[ $handle ] = $item;
		}
		$registry->queue = $queue;
		return $registry;
	}

	/**
	 * A leaf handle with no dependents dequeues normally.
	 */
	public function test_filter_safe_handles_allows_leaf_dequeue(): void {
		$registered = array(
			'contact-form-7' => array( 'jquery' ),
			'jquery'         => array(),
		);
		$safe       = Asset_Manager::filter_safe_handles( array( 'contact-form-7' ), $registered, array( 'contact-form-7', 'jquery' ) );
		$this->assertSame( array( 'contact-form-7' ), $safe );
	}

	/**
	 * A handle with an active dependent is refused (kept enqueued).
	 */
	public function test_filter_safe_handles_refuses_handle_with_active_dependent(): void {
		$registered = array(
			'shared-lib' => array(),
			'form-app'   => array( 'shared-lib' ),
		);
		$safe       = Asset_Manager::filter_safe_handles( array( 'shared-lib' ), $registered, array( 'shared-lib', 'form-app' ) );
		$this->assertSame( array(), $safe );
	}

	/**
	 * Disabling the whole dependent subtree at once is allowed.
	 */
	public function test_filter_safe_handles_allows_whole_subtree_disable(): void {
		$registered = array(
			'shared-lib' => array(),
			'form-app'   => array( 'shared-lib' ),
		);
		$safe       = Asset_Manager::filter_safe_handles( array( 'shared-lib', 'form-app' ), $registered, array( 'shared-lib', 'form-app' ) );
		$this->assertSame( array( 'shared-lib', 'form-app' ), $safe );
	}

	/**
	 * Transitive dependents block the dequeue, not just direct ones.
	 */
	public function test_filter_safe_handles_refuses_transitive_dependent(): void {
		$registered = array(
			'base'   => array(),
			'middle' => array( 'base' ),
			'top'    => array( 'middle' ),
		);
		$safe       = Asset_Manager::filter_safe_handles( array( 'base' ), $registered, array( 'base', 'middle', 'top' ) );
		$this->assertSame( array(), $safe );
	}

	/**
	 * An inactive dependent (registered but not queued) does not block.
	 */
	public function test_filter_safe_handles_ignores_inactive_dependents(): void {
		$registered = array(
			'shared-lib' => array(),
			'form-app'   => array( 'shared-lib' ),
		);
		$safe       = Asset_Manager::filter_safe_handles( array( 'shared-lib' ), $registered, array( 'shared-lib' ) );
		$this->assertSame( array( 'shared-lib' ), $safe );
	}

	/**
	 * Protected core handles are immune even when requested.
	 */
	public function test_filter_safe_handles_protects_core_handles(): void {
		$safe = Asset_Manager::filter_safe_handles(
			array( 'jquery-core', 'admin-bar', 'plain-handle' ),
			array(
				'jquery-core'  => array(),
				'admin-bar'    => array(),
				'plain-handle' => array(),
			),
			array( 'jquery-core', 'admin-bar', 'plain-handle' )
		);
		$this->assertSame( array( 'plain-handle' ), $safe );
	}

	/**
	 * Commerce fragment handles are immune even on non-commerce pages.
	 */
	public function test_filter_safe_handles_protects_commerce_fragments(): void {
		$commerce = Asset_Manager::get_commerce_handles();
		$this->assertContains( 'wc-cart-fragments', $commerce );
		$safe = Asset_Manager::filter_safe_handles(
			array( 'wc-cart-fragments', 'plain-handle' ),
			array(
				'wc-cart-fragments' => array(),
				'plain-handle'      => array(),
			),
			array( 'wc-cart-fragments', 'plain-handle' )
		);
		$this->assertSame( array( 'plain-handle' ), $safe );
	}

	/**
	 * Extra allowlist handles from settings are never dequeued.
	 */
	public function test_filter_safe_handles_honours_extra_allowlist(): void {
		$safe = Asset_Manager::filter_safe_handles(
			array( 'keep-me', 'plain-handle' ),
			array(
				'keep-me'      => array(),
				'plain-handle' => array(),
			),
			array( 'keep-me', 'plain-handle' ),
			array( 'keep-me' )
		);
		$this->assertSame( array( 'plain-handle' ), $safe );
	}

	/**
	 * The global gate defaults OFF: absent settings keep assets enqueued.
	 */
	public function test_is_enabled_defaults_off(): void {
		$this->assertFalse( Asset_Manager::is_enabled( array() ) );
		$this->assertFalse( Asset_Manager::is_enabled( array( 'file_optimisation' => array() ) ) );
		$this->assertTrue( Asset_Manager::is_enabled( array( 'file_optimisation' => array( 'assetManagerEnabled' => true ) ) ) );
		// A stored string 'false' (legacy import shape) must not enable.
		$this->assertFalse( Asset_Manager::is_enabled( array( 'file_optimisation' => array( 'assetManagerEnabled' => 'false' ) ) ) );
	}

	/**
	 * Cart/checkout/account conditional tags trigger the commerce bypass.
	 */
	public function test_is_commerce_context_on_cart(): void {
		Functions\when( 'is_cart' )->justReturn( true );
		$this->assertTrue( Asset_Manager::is_commerce_context() );
	}

	/**
	 * Woo dynamic paths trigger the bypass even without conditional tags.
	 */
	public function test_is_commerce_context_on_woo_path(): void {
		$_SERVER['REQUEST_URI'] = '/checkout/?x=1';
		$this->assertTrue( Asset_Manager::is_commerce_context() );
	}

	/**
	 * Plain post URLs are not commerce contexts.
	 */
	public function test_is_commerce_context_off_for_plain_post(): void {
		$_SERVER['REQUEST_URI'] = '/hello-world/';
		$this->assertFalse( Asset_Manager::is_commerce_context() );
	}

	/**
	 * End-to-end: a form script disabled on a non-form page is dequeued.
	 */
	public function test_dequeue_selected_assets_dequeues_leaf_on_plain_page(): void {
		$this->settings_fixture = array(
			'file_optimisation' => array( 'assetManagerEnabled' => true ),
		);
		$_SERVER['REQUEST_URI'] = '/about/';
		Functions\when( 'get_post_meta' )->alias(
			static function ( $post_id, $key, $single ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				if ( '_wppo_disabled_scripts' === $key ) {
					return array( 'contact-form-7' );
				}
				return array();
			}
		);
		$GLOBALS['wp_scripts'] = $this->make_registry(
			array(
				'contact-form-7' => array(),
				'theme-main'     => array(),
			),
			array( 'contact-form-7', 'theme-main' )
		);
		$GLOBALS['wp_styles']  = $this->make_registry( array(), array() );

		$manager = ( new ReflectionClass( Asset_Manager::class ) )->newInstanceWithoutConstructor();
		$manager->dequeue_selected_assets();

		$this->assertSame( array( 'contact-form-7' ), $this->dequeued_scripts() );
		$this->assertSame( array(), $this->dequeued_styles() );
	}

	/**
	 * End-to-end: the dependency guard refuses a shared library at runtime.
	 */
	public function test_dequeue_selected_assets_refuses_shared_dependency(): void {
		$this->settings_fixture = array(
			'file_optimisation' => array( 'assetManagerEnabled' => true ),
		);
		$_SERVER['REQUEST_URI'] = '/about/';
		Functions\when( 'get_post_meta' )->alias(
			static function ( $post_id, $key, $single ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				if ( '_wppo_disabled_scripts' === $key ) {
					return array( 'shared-lib' );
				}
				return array();
			}
		);
		$GLOBALS['wp_scripts'] = $this->make_registry(
			array(
				'shared-lib' => array(),
				'form-app'   => array( 'shared-lib' ),
			),
			array( 'shared-lib', 'form-app' )
		);
		$GLOBALS['wp_styles']  = $this->make_registry( array(), array() );

		$manager = ( new ReflectionClass( Asset_Manager::class ) )->newInstanceWithoutConstructor();
		$manager->dequeue_selected_assets();

		$this->assertSame( array(), $this->dequeued_scripts() );
	}

	/**
	 * End-to-end: the global gate OFF keeps everything enqueued.
	 */
	public function test_dequeue_selected_assets_noops_when_manager_disabled(): void {
		$this->settings_fixture = array(
			'file_optimisation' => array( 'assetManagerEnabled' => false ),
		);
		$_SERVER['REQUEST_URI'] = '/about/';
		Functions\when( 'get_post_meta' )->alias(
			static function ( $post_id, $key, $single ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				if ( '_wppo_disabled_scripts' === $key ) {
					return array( 'contact-form-7' );
				}
				return array();
			}
		);
		$GLOBALS['wp_scripts'] = $this->make_registry( array( 'contact-form-7' => array() ), array( 'contact-form-7' ) );
		$GLOBALS['wp_styles']  = $this->make_registry( array(), array() );

		$manager = ( new ReflectionClass( Asset_Manager::class ) )->newInstanceWithoutConstructor();
		$manager->dequeue_selected_assets();

		$this->assertSame( array(), $this->dequeued_scripts() );
	}

	/**
	 * End-to-end: cart pages are never stripped even with the manager on.
	 */
	public function test_dequeue_selected_assets_noops_on_cart(): void {
		$this->settings_fixture = array(
			'file_optimisation' => array( 'assetManagerEnabled' => true ),
		);
		Functions\when( 'is_cart' )->justReturn( true );
		$_SERVER['REQUEST_URI'] = '/cart/';
		Functions\when( 'get_post_meta' )->alias(
			static function ( $post_id, $key, $single ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				if ( '_wppo_disabled_scripts' === $key ) {
					return array( 'contact-form-7' );
				}
				return array();
			}
		);
		$GLOBALS['wp_scripts'] = $this->make_registry( array( 'contact-form-7' => array() ), array( 'contact-form-7' ) );
		$GLOBALS['wp_styles']  = $this->make_registry( array(), array() );

		$manager = ( new ReflectionClass( Asset_Manager::class ) )->newInstanceWithoutConstructor();
		$manager->dequeue_selected_assets();

		$this->assertSame( array(), $this->dequeued_scripts() );
	}

	/**
	 * Per-type never-strip: a style named like a protected script handle is
	 * not over-blocked in the style pass.
	 */
	public function test_filter_safe_handles_script_allowlist_does_not_block_styles(): void {
		$safe = Asset_Manager::filter_safe_handles(
			array( 'jquery', 'plain-style' ),
			array(
				'jquery'      => array(),
				'plain-style' => array(),
			),
			array( 'jquery', 'plain-style' ),
			array(),
			''
		);
		// Legacy merged list (empty type falls back to BC behavior): jquery blocked.
		$this->assertSame( array( 'plain-style' ), $safe );

		$safe = Asset_Manager::filter_safe_handles(
			array( 'jquery', 'plain-style' ),
			array(
				'jquery'      => array(),
				'plain-style' => array(),
			),
			array( 'jquery', 'plain-style' ),
			array(),
			'style'
		);
		$this->assertSame( array( 'jquery', 'plain-style' ), $safe );
	}

	/**
	 * Per-type never-strip: style-only and commerce handles are scoped to
	 * their own pass (commerce guards scripts, not styles).
	 */
	public function test_filter_safe_handles_style_and_commerce_allowlist_are_type_scoped(): void {
		$safe = Asset_Manager::filter_safe_handles(
			array( 'dashicons', 'wc-cart-fragments', 'plain-script' ),
			array(
				'dashicons'         => array(),
				'wc-cart-fragments' => array(),
				'plain-script'      => array(),
			),
			array( 'dashicons', 'wc-cart-fragments', 'plain-script' ),
			array(),
			'script'
		);
		$this->assertSame( array( 'dashicons', 'plain-script' ), $safe );

		$safe = Asset_Manager::filter_safe_handles(
			array( 'dashicons', 'wc-cart-fragments', 'plain-style' ),
			array(
				'dashicons'         => array(),
				'wc-cart-fragments' => array(),
				'plain-style'       => array(),
			),
			array( 'dashicons', 'wc-cart-fragments', 'plain-style' ),
			array(),
			'style'
		);
		$this->assertSame( array( 'wc-cart-fragments', 'plain-style' ), $safe );
	}

	/**
	 * Handles are normalized before comparison: mixed-case disabled input
	 * still matches a lowercased extra-allowlist entry.
	 */
	public function test_filter_safe_handles_normalizes_mixed_case_handles(): void {
		$safe = Asset_Manager::filter_safe_handles(
			array( 'Keep-Me', 'Plain-Handle' ),
			array(
				'keep-me'      => array(),
				'plain-handle' => array(),
			),
			array( 'keep-me', 'plain-handle' ),
			array( 'keep-me' )
		);
		$this->assertSame( array( 'plain-handle' ), $safe );
	}

	/**
	 * Save-time: crafted protected handles never persist to post meta.
	 */
	public function test_process_disabled_assets_strips_protected_handles(): void {
		$metabox    = ( new ReflectionClass( Metabox::class ) )->newInstanceWithoutConstructor();
		$reflection = new ReflectionClass( $metabox );
		$method     = $reflection->getMethod( 'process_disabled_assets' );

		$result = $method->invokeArgs(
			$metabox,
			array(
				array( 'jquery-core', 'my-plugin', 'wc-cart-fragments' ),
				array( 'jquery-core', 'my-plugin', 'wc-cart-fragments' ),
				array_merge( Asset_Manager::get_protected_scripts(), Asset_Manager::get_commerce_handles() ),
			)
		);

		$this->assertSame( array( 'my-plugin' ), array_values( $result ) );
	}

	/**
	 * Defaults carry the manager keys OFF, and the sanitizer round-trips them.
	 */
	public function test_manager_rules_round_trip_through_sanitize(): void {
		$defaults = Util::get_default_settings();
		$this->assertArrayHasKey( 'assetManagerEnabled', $defaults['file_optimisation'] );
		$this->assertFalse( $defaults['file_optimisation']['assetManagerEnabled'] );
		$this->assertArrayHasKey( 'assetManagerAllowlistExtra', $defaults['file_optimisation'] );

		$exported = array(
			'file_optimisation' => array(
				'assetManagerEnabled'        => true,
				'assetManagerAllowlistExtra' => "keep-me\nanother-handle",
			),
		);
		$restored = Util::sanitize_settings_recursively( $exported );
		$this->assertTrue( $restored['file_optimisation']['assetManagerEnabled'] );
		$this->assertSame( "keep-me\nanother-handle", $restored['file_optimisation']['assetManagerAllowlistExtra'] );

		// Malformed import shapes normalize fail-safe OFF.
		$restored = Util::sanitize_settings_recursively(
			array(
				'file_optimisation' => array( 'assetManagerEnabled' => 'not-a-bool' ),
			)
		);
		$this->assertFalse( $restored['file_optimisation']['assetManagerEnabled'] );

		// Schema covers the new keys so CLI validation accepts them.
		$schema = Util::get_settings_schema();
		$this->assertArrayHasKey( 'assetManagerEnabled', $schema['file_optimisation'] );
		$this->assertArrayHasKey( 'assetManagerAllowlistExtra', $schema['file_optimisation'] );
	}

	/**
	 * Extra allowlist parsing splits lines and drops empties/duplicates.
	 */
	public function test_get_extra_allowlist_parses_lines(): void {
		$settings = array(
			'file_optimisation' => array(
				'assetManagerAllowlistExtra' => "keep-me\n\nkeep-me\r\nanother",
			),
		);
		$this->assertSame( array( 'keep-me', 'another' ), Asset_Manager::get_extra_allowlist( $settings ) );
		$this->assertSame( array(), Asset_Manager::get_extra_allowlist( array() ) );
	}
}
