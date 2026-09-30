<?php
/**
 * CHARACTERISATION TESTS, part 2: the boot-time hook contract and the
 * upgrade short-circuits in includes/Core/class-main.php.
 *
 * Records what the code does today. Part 1 (MainCharacterisationTest) pins the
 * API surface; this pins the 103 unique hooks the plugin registers when
 * `new Main()` runs, and the four observable short-circuits of
 * maybe_run_version_upgrade() including its idempotency gate.
 *
 * IMPORTANT: those 103 hooks are registered by Hook_Registry, not by
 * class-main.php itself. class-main.php contains only 8 add_action/add_filter
 * calls of its own; the constructor delegates to
 * includes/Core/class-hook-registry.php:109. Pinning them here is deliberate -
 * it is the integration surface a refactor of this file must not change,
 * whether or not it stays in this file.
 *
 * Every test here runs in its own process. Two reasons, both measured:
 * constructing Main registers real global hooks, and mocking
 * current_user_can in a shared process poisons the Patchwork definition for
 * later files (it broke seven ObjectCache tests in an earlier revision).
 * The repo already uses #[RunInSeparateProcess] for the same reason - see
 * tests/php/SchedulerBoundaryTest.php:88.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Class MainCharacterisationHooksTest.
 *
 * @package PerformanceOptimise\Tests
 */
class MainCharacterisationHooksTest extends \PHPUnit\Framework\TestCase {

	use WPPO_Test_Bootstrap;

	/**
	 * Recorded add_action()/add_filter() calls as "type name @priority/args".
	 *
	 * @var string[]
	 */
	private $hooks = array();

	/**
	 * In-memory option store.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Recorded option writes as "function:option_name".
	 *
	 * @var string[]
	 */
	private $writes = array();

	/**
	 * Whether the mocked current_user_can() should grant manage_options.
	 *
	 * @var bool
	 */
	private $can_manage = false;

	/**
	 * Install the WP stubs and start recording.
	 *
	 * Deliberately not named setUp(): that would shadow
	 * WPPO_Test_Bootstrap::setUp() and Brain Monkey would never be set up.
	 *
	 * @return void
	 */
	private function begin(): void {
		unset( $GLOBALS['wp_version'] );
		Util::clear_settings_cache();
		Util::reset_runtime_caches();
		Main::reset_speculation_url_memo();

		$this->hooks      = array();
		$this->writes     = array();
		$this->can_manage = false;
		$this->options    = array( 'wppo_settings' => array() );

		Functions\stubs(
			array(
				'get_option',
				'update_option',
				'add_option',
				'delete_option',
				'get_transient',
				'set_transient',
				'delete_transient',
				'wp_hash',
				'sanitize_text_field',
				'esc_url_raw',
				'wp_unslash',
				'is_multisite',
				'wp_rand',
				'absint',
				'wp_parse_url',
				'do_action',
				'apply_filters',
				'remove_action',
				'remove_filter',
				'doing_filter',
				'has_action',
				'has_filter',
				'is_admin',
				'is_user_logged_in',
				'wp_doing_ajax',
				'wp_doing_cron',
				'wp_using_ext_object_cache',
				'wp_parse_args',
				'sanitize_key',
				'wp_list_pluck',
				'wp_json_encode',
				'current_time',
				'home_url',
				'site_url',
				'plugins_url',
				'plugin_dir_path',
				'__',
				'esc_html__',
				'trailingslashit',
				'untrailingslashit',
			)
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$this->writes[]         = 'update_option:' . $name;
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( $name, $value = '', $deprecated = '', $autoload = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$this->writes[] = 'add_option:' . $name;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				$this->writes[] = 'delete_option:' . $name;
				return true;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_hash' )->alias(
			static function ( $data ) {
				return 'h_' . $data;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'trailingslashit' )->returnArg();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_rand' )->justReturn( 1 );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		// current_user_can is deliberately NOT in the bulk stub() list: doing
		// that in an earlier revision of this file made seven unrelated
		// ObjectCache tests fail with "current_user_can is not defined nor
		// mocked" - a Patchwork cross-file definition collision, not a product
		// bug. Binding it per-test keeps the definition scoped to the tests
		// that actually need a capability decision.
		Functions\when( 'current_user_can' )->alias(
			function ( $capability ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
				return $this->can_manage;
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'doing_filter' )->justReturn( false );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'current_time' )->justReturn( 1700000000 );
		Functions\when( 'remove_action' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return $value;
			}
		);
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback = null, $priority = 10, $accepted_args = 1 ) {
				$this->hooks[] = 'action  ' . $hook . ' @' . $priority . '/' . $accepted_args;
				return true;
			}
		);
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback = null, $priority = 10, $accepted_args = 1 ) {
				$this->hooks[] = 'filter ' . $hook . ' @' . $priority . '/' . $accepted_args;
				return true;
			}
		);

		$GLOBALS['wp_version'] = '6.8';
	}

	/**
	 * The pinned boot-time hook set, recorded from master 84df9c75.
	 *
	 * @return string[]
	 */
	private function pinned_boot_hooks(): array {
		return array(
			'action  activated_plugin @10/1',
			'action  add_meta_boxes @10/1',
			'action  add_option_wppo_settings @10/2',
			'action  added_post_meta @10/3',
			'action  admin_bar_menu @100/1',
			'action  admin_enqueue_scripts @10/1',
			'action  admin_init @10/1',
			'action  admin_menu @10/1',
			'action  clear_auth_cookie @10/1',
			'action  deactivated_plugin @10/1',
			'action  delete_attachment @10/1',
			'action  delete_option_wppo_settings @10/1',
			'action  delete_post @10/1',
			'action  deleted_post_meta @10/3',
			'action  elementor/core/files/clear_cache @10/0',
			'action  elementor/css-file/post/parse_after @10/2',
			'action  elementor/editor/after_save @10/2',
			'action  init @1/1',
			'action  init @10/1',
			'action  litespeed_purge_finalize @10/1',
			'action  litespeed_purged_all @10/1',
			'action  litespeed_purged_post @10/1',
			'action  permalink_structure_changed @10/1',
			'action  plugins_loaded @0/0',
			'action  plugins_loaded @1/0',
			'action  rest_api_init @10/1',
			'action  save_post @10/1',
			'action  save_post @10/3',
			'action  send_headers @0/1',
			'action  send_headers @10/1',
			'action  set_logged_in_cookie @10/6',
			'action  shutdown @9223372036854775807/1',
			'action  switch_blog @10/1',
			'action  switch_blog @10/2',
			'action  switch_theme @10/1',
			'action  switch_theme @20/3',
			'action  template_redirect @1/1',
			'action  template_redirect @10/1',
			'action  update_option_home @10/3',
			'action  update_option_permalink_structure @10/1',
			'action  update_option_siteurl @10/3',
			'action  update_option_wppo_settings @10/2',
			'action  updated_post_meta @10/3',
			'action  upgrader_process_complete @10/0',
			'action  upgrader_process_complete @10/2',
			'action  upgrader_process_complete @20/2',
			'action  woocommerce_checkout_order_created @10/1',
			'action  woocommerce_coupon_options_save @10/1',
			'action  woocommerce_update_order @10/1',
			'action  woocommerce_update_product @10/1',
			'action  wp_abilities_api_categories_init @10/1',
			'action  wp_abilities_api_init @10/1',
			'action  wp_abilities_init @10/1',
			'action  wp_ajax_wppo_get_nonce @10/1',
			'action  wp_enqueue_scripts @10/1',
			'action  wp_enqueue_scripts @10000/1',
			'action  wp_enqueue_scripts @5/1',
			'action  wp_enqueue_scripts @9999/1',
			'action  wp_footer @90/1',
			'action  wp_footer @9999/1',
			'action  wp_head @0/1',
			'action  wp_head @1/1',
			'action  wp_logout @10/1',
			'action  wppo_after_cache_clear @10/1',
			'action  wppo_after_cache_clear @10/2',
			'action  wppo_ai_css_refresh_queued @10/3',
			'action  wppo_builder_drift_purge @10/0',
			'action  wppo_ccss_regeneration @10/1',
			'action  wppo_convert_image_background @10/1',
			'action  wppo_crawler_warm @10/1',
			'action  wppo_database_cleanup_cron @10/1',
			'action  wppo_generate_static_page @10/1',
			'action  wppo_generate_static_url @10/1',
			'action  wppo_google_fonts_download @10/1',
			'action  wppo_img_conversion @10/1',
			'action  wppo_litespeed_crawler_batch @10/1',
			'action  wppo_llms_txt_daily @10/1',
			'action  wppo_object_cache_probe @10/1',
			'action  wppo_page_cron_batch @10/1',
			'action  wppo_page_cron_hook @10/1',
			'action  wppo_pagespeed_scan @10/1',
			'action  wppo_perf_translations_file_written @10/1',
			'action  wppo_preload_url_batch @10/1',
			'action  wppo_rum_flush @10/1',
			'action  wppo_run_upgrades @10/1',
			'action  wppo_upgrade_purge @10/1',
			'action  wppo_used_css_cron @10/1',
			'action  wppo_used_css_generate @10/2',
			'action  wppo_web_vitals_rescan @10/1',
			'filter attach_session_information @10/1',
			'filter cron_schedules @10/1',
			'filter litespeed_buffer_finalize @10/1',
			'filter litespeed_vary @10/1',
			'filter load_textdomain_mofile @10/2',
			'filter load_translation_file @10/2',
			'filter nocache_headers @1000/1',
			'filter query_vars @10/1',
			'filter script_loader_src @20/2',
			'filter style_loader_src @20/2',
			'filter wp_calculate_image_srcset @20/1',
			'filter wp_get_attachment_image_attributes @10/3',
			'filter wp_get_attachment_url @20/2',
			'filter wp_resource_hints @10/2',
		);
	}

	/**
	 * `new Main()` registers exactly this hook set: name, priority and
	 * accepted-args, for every hook and filter in the plugin.
	 *
	 * Hook names and priorities ARE the public contract - core and every
	 * integration matches on these exact strings, so a refactor that changes
	 * one breaks all of it silently.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function test_boot_registers_the_pinned_hook_set(): void {
		$this->begin();
		new Main();

		$actual = array_values( array_unique( $this->hooks ) );
		sort( $actual );

		$this->assertSame(
			$this->pinned_boot_hooks(),
			$actual,
			'The set of hooks registered at boot changed: a name, a priority or an accepted-args count moved.'
		);
		$this->assertCount(
			103,
			$actual,
			'103 unique hooks are registered at boot today (141 registrations, some registered more than once).'
		);
	}

	/**
	 * Short-circuit 1 of 4: without manage_options the upgrade routine returns
	 * before touching anything. class-main.php:2197-2199. This one IS
	 * discriminating: it fails if the capability gate is removed.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function test_version_upgrade_is_a_no_op_without_manage_options(): void {
		$this->begin();
		$main         = new Main();
		$this->writes = array();

		$main->maybe_run_version_upgrade();

		$this->assertSame(
			array(),
			$this->writes,
			'A non-administrator must not be able to trigger the upgrade routine: no option may be written.'
		);
	}

	/**
	 * The version gate means a second run writes nothing. class-main.php:2201-2205.
	 *
	 * LIMITATION, measured: this asserts the observable (no option written,
	 * twice), not WHICH gate produced it. The drop-in gate at :2210 produces
	 * the same observable in a test environment, so changing '>=' to '>' in
	 * the version_compare does NOT fail this test. Distinguishing the two
	 * gates needs Advanced_Cache_Handler::create() to be forced true, and
	 * that is a static method on another class - see the PR body for the
	 * seam that would be needed. Recorded rather than papered over.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function test_version_upgrade_is_idempotent_once_the_version_is_current(): void {
		$this->begin();
		$main                          = new Main();
		$this->can_manage              = true;
		$this->options['wppo_version'] = WPPO_VERSION;
		$this->writes                  = array();

		$main->maybe_run_version_upgrade();
		$first = $this->writes;
		$main->maybe_run_version_upgrade();
		$second = $this->writes;

		$this->assertSame(
			array(),
			$first,
			'With wppo_version already at WPPO_VERSION the routine must write nothing at all.'
		);
		$this->assertSame(
			$first,
			$second,
			'Calling it a second time must be identical to the first: the version gate is the idempotency guarantee.'
		);
	}

	/**
	 * With a stale stored version the routine gets past the version gate and
	 * stops at the drop-in gate instead: in a test environment the drop-in
	 * cannot be written, so wppo_version is deliberately left alone for a
	 * later request to retry. class-main.php:2210-2212.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function test_version_upgrade_stops_at_the_dropin_gate_and_leaves_the_version_alone(): void {
		$this->begin();
		$main                          = new Main();
		$this->can_manage              = true;
		$this->options['wppo_version'] = '0.0.1';
		$this->writes                  = array();

		$main->maybe_run_version_upgrade();

		$this->assertNotContains(
			'update_option:wppo_version',
			$this->writes,
			'A failed drop-in write must leave wppo_version alone so a later request retries; the bump is the last step, not the first.'
		);
		$this->assertSame(
			'0.0.1',
			$this->options['wppo_version'],
			'The stored version is untouched when the routine aborts early.'
		);
	}
}
