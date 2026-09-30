<?php
/**
 * CHARACTERISATION TESTS for includes/Core/class-main.php.
 *
 * These are NOT aspirational. They record what the class does today - the
 * public API surface, the property surface, the hook names/priorities it
 * registers itself, and its short-circuit paths - so that a later
 * modularisation of this 10,306-line file can be proven not to change
 * behaviour. A test here that looks wrong is doing its job: it pins the
 * current shape, it does not endorse it.
 *
 * Recorded from master 84df9c75.
 *
 * NOTE for anyone running this by hand: class-main.php:17-19 does
 * `if ( ! defined( 'ABSPATH' ) ) { exit; }`. Any loader that includes the file
 * without ABSPATH defined gets a SILENT exit(0) - no error, no class, no
 * output. That is why ad-hoc reflection over this class looks like "the class
 * does not exist" when it is really the include-time guard.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Class MainCharacterisationTest.
 *
 * @package PerformanceOptimise\Tests
 */
class MainCharacterisationTest extends \PHPUnit\Framework\TestCase {

	use WPPO_Test_Bootstrap;

	/**
	 * In-memory option store.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Recorded add_action()/add_filter() registrations.
	 *
	 * @var array
	 */
	private $hooks = array();

	/**
	 * Recorded option writes, in order.
	 *
	 * @var array
	 */
	private $writes = array();

	/**
	 * Reset state and install the WP stubs these paths need.
	 *
	 * Deliberately not named setUp(): a same-named method here would shadow
	 * WPPO_Test_Bootstrap::setUp() and Brain Monkey would never be set up.
	 *
	 * @return void
	 */
	private function reset_state(): void {
		unset( $GLOBALS['wp_version'] );
		Util::clear_settings_cache();
		Util::reset_runtime_caches();
		Main::reset_speculation_url_memo();

		// The 6.8 core half of the add_speculation_rules() guard is
		// function_exists()-based. Same polyfill the existing
		// MainSpeculationDedupTest uses, so there is one convention.
		if ( ! function_exists( 'wp_get_speculation_rules' ) ) {
			eval( 'function wp_get_speculation_rules() { return array(); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}
		if ( ! function_exists( 'wp_get_speculation_rules_configuration' ) ) {
			eval( 'function wp_get_speculation_rules_configuration() { return array(); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}

		$this->options = array(
			'wppo_settings' => array(
				'performance_audit' => array( 'rum_enabled' => true ),
			),
		);
		$this->hooks   = array();
		$this->writes  = array();

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
				'add_action',
				'add_filter',
				'do_action',
				'apply_filters',
				'__',
				'esc_html__',
				'trailingslashit',
				'untrailingslashit',
				'wp_json_encode',
				'current_time',
				'home_url',
				'site_url',
				'plugins_url',
				'plugin_dir_path',
				'is_admin',
				'is_user_logged_in',
				'wp_doing_ajax',
				'wp_doing_cron',
				'has_action',
				'has_filter',
				'remove_action',
				'remove_filter',
				'doing_filter',
				'wp_using_ext_object_cache',
				'wp_parse_args',
				'sanitize_key',
				'wp_list_pluck',
			)
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'remove_action' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'doing_filter' )->justReturn( false );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_list_pluck' )->alias( // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
			static function ( $items, $field ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.NotSnakeCaseFound
				$out = array();
				foreach ( (array) $items as $item ) {
					$out[] = is_array( $item ) && isset( $item[ $field ] ) ? $item[ $field ] : null;
				}
				return $out;
			}
		);
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = array() ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$this->writes[]         = array( 'update_option', $name );
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( $name, $value = '', $deprecated = '', $autoload = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,WordPress.NamingConventions.ValidVariableName.NotSnakeCaseFound
				$this->writes[] = array( 'add_option', $name );
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				$this->writes[] = array( 'delete_option', $name );
				unset( $this->options[ $name ] );
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
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_rand' )->justReturn( 1 );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'current_time' )->justReturn( 1700000000 );
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test' . $path;
			}
		);
		Functions\when( 'site_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test' . $path;
			}
		);
		Functions\when( 'plugins_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test/wp-content/plugins/po/' . ltrim( (string) $path, '/' );
			}
		);
		Functions\when( 'plugin_dir_path' )->justReturn( '/tmp/fake-plugin/' );
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback = null, $priority = 10, $accepted_args = 1 ) {
				$this->hooks[] = array( 'action', $hook, $priority, $accepted_args );
				return true;
			}
		);
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback = null, $priority = 10, $accepted_args = 1 ) {
				$this->hooks[] = array( 'filter', $hook, $priority, $accepted_args );
				return true;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return $value;
			}
		);

		$GLOBALS['wp_version'] = '6.8';
	}

	/**
	 * The pinned public API surface, as method name => param count, static flag and signature.
	 *
	 * A rename, an added or removed argument, or a default-value change breaks this.
	 *
	 * @return array<string,array{int,int,string}>
	 */
	private function pinned_public_methods(): array {
		return array(
			'__construct'                                  => array( 0, 0, '' ),
			'add_defer_attribute'                          => array( 2, 0, '$tag,$handle' ),
			'add_defer_attribute_legacy'                   => array( 2, 0, '$tag,$handle' ),
			'add_defer_strategy'                           => array( 0, 0, '' ),
			'add_fetchpriority_to_deferred'                => array( 2, 0, '$tag,$handle' ),
			'add_preload_prefetch_preconnect'              => array( 0, 0, '' ),
			'add_resource_hints'                           => array( 2, 0, '$urls,$relation_type' ),
			'add_setting_to_admin_bar'                     => array( 1, 0, '$wp_admin_bar' ),
			'add_speculation_rules'                        => array( 0, 0, '' ),
			'admin_enqueue_scripts'                        => array( 0, 0, '' ),
			'admin_page'                                   => array( 0, 0, '' ),
			'apply_module_loading_strategies'              => array( 0, 0, '' ),
			'apply_per_page_delay_config'                  => array( 0, 0, '' ),
			'apply_preset_bundle'                          => array( 2, 1, '$current,$bundle' ),
			'build_safe_mode_enable_payload'               => array( 1, 1, '$file_optimisation=array' ),
			'capture_template_start'                       => array( 0, 0, '' ),
			'clear_all_cache'                              => array( 0, 1, '' ),
			'clear_role_hash_cookie'                       => array( 0, 0, '' ),
			'create_cache'                                 => array( 3, 1, '$options,$image_optimisation=NULL,$google_fonts=NULL' ),
			'detect_fragile_handles'                       => array( 1, 1, '$handles' ),
			'emit_server_timing_header'                    => array( 1, 0, '$output=\'\'' ),
			'enqueue_scripts'                              => array( 0, 0, '' ),
			'ensure_hook_cache_for_registry'               => array( 1, 0, '$force=false' ),
			'extract_font_urls_from_css'                   => array( 1, 1, '$css' ),
			'filter_speculation_list_rules'                => array( 1, 0, '$rules' ),
			'filter_speculation_rules_configuration'       => array( 3, 0, '$config,$preload_settings,$enable_speculation' ),
			'get_aggressive_preset_bundle'                 => array( 0, 1, '' ),
			'get_auto_discovered_font_urls'                => array( 1, 0, '$manual_urls=array' ),
			'get_defer_js_preset_exclusions'               => array( 0, 1, '' ),
			'get_delay_js_analytics_exclusions'            => array( 0, 1, '' ),
			'get_delay_js_base_preset_exclusions'          => array( 0, 1, '' ),
			'get_delay_js_builder_exclusions'              => array( 0, 1, '' ),
			'get_delay_js_commerce_exclusions'             => array( 0, 1, '' ),
			'get_delay_js_compat_preset_exclusions'        => array( 1, 1, '$slug' ),
			'get_delay_js_compat_preset_map'               => array( 0, 1, '' ),
			'get_delay_js_consent_exclusions'              => array( 0, 1, '' ),
			'get_delay_js_gallery_exclusions'              => array( 0, 1, '' ),
			'get_delay_js_interaction_exclusions'          => array( 0, 1, '' ),
			'get_delay_js_jquery_exclusions'               => array( 0, 1, '' ),
			'get_delay_js_preset_level_exclusions'         => array( 1, 1, '$level' ),
			'get_delay_js_preset_level_settings'           => array( 1, 1, '$level' ),
			'get_delay_js_preset_levels'                   => array( 0, 1, '' ),
			'get_delay_js_slider_exclusions'               => array( 0, 1, '' ),
			'get_delay_js_third_party_allowlist'           => array( 0, 0, '' ),
			'get_delay_js_third_party_allowlist_for_slice' => array( 1, 1, '$file_opt' ),
			'get_delay_js_third_party_auto_categories'     => array( 0, 1, '' ),
			'get_delay_js_third_party_auto_label'          => array( 1, 1, '$src_or_handle' ),
			'get_delay_js_third_party_auto_patterns'       => array( 0, 1, '' ),
			'get_delay_js_third_party_denylist'            => array( 0, 1, '' ),
			'get_effective_file_optimisation'              => array( 1, 1, '$file_optimisation=array' ),
			'get_fragile_handle_map'                       => array( 0, 1, '' ),
			'get_instance'                                 => array( 0, 1, '' ),
			'get_manual_font_urls'                         => array( 1, 0, '$preload_settings' ),
			'get_options'                                  => array( 0, 0, '' ),
			'get_page_disabled_delay_presets'              => array( 1, 1, '$post_id=0' ),
			'get_safe_mode_stack_state'                    => array( 1, 1, '$file_optimisation=array' ),
			'get_safe_preset_bundle'                       => array( 0, 1, '' ),
			'get_speculation_exclude_paths'                => array( 1, 0, '$preload_settings=array' ),
			'get_speculation_list_urls'                    => array( 0, 0, '' ),
			'init_menu'                                    => array( 0, 0, '' ),
			'invalidate_aggressive_kill_switch_cache'      => array( 1, 1, '$post_id' ),
			'invalidate_delay_kill_switch_cache'           => array( 1, 1, '$post_id' ),
			'is_aggressive_bypass_active'                  => array( 0, 1, '' ),
			'is_defer_disabled_for_page'                   => array( 1, 1, '$post_id=0' ),
			'is_delay_disabled_for_page'                   => array( 1, 1, '$post_id=0' ),
			'is_delay_excluded_context'                    => array( 0, 1, '' ),
			'is_delay_js_safe_context'                     => array( 0, 0, '' ),
			'is_delay_third_party_auto_candidate'          => array( 2, 0, '$tag,$handle' ),
			'is_delay_third_party_candidate'               => array( 2, 0, '$tag,$handle' ),
			'is_elementor_built_page'                      => array( 2, 1, '$post_id=NULL,$file_opt=array' ),
			'is_elementor_safe_mode_active'                => array( 1, 1, '$file_optimisation=array' ),
			'is_hidden_block_asset_omission_enabled'       => array( 0, 0, '' ),
			'is_pl_server_timing_active'                   => array( 0, 0, '' ),
			'is_safe_mode_active'                          => array( 1, 1, '$file_optimisation=array' ),
			'is_safe_mode_enabled'                         => array( 0, 0, '' ),
			'is_safe_preset_active'                        => array( 1, 1, '$file_opt' ),
			'is_same_site_script_host'                     => array( 2, 1, '$a,$b' ),
			'is_sandbox_preview_active'                    => array( 0, 1, '' ),
			'is_url_excluded_by_list'                      => array( 1, 1, '$url_list' ),
			'is_used_css_excluded_for_url'                 => array( 0, 1, '' ),
			'looks_like_elementor_request'                 => array( 0, 1, '' ),
			'matches_third_party_auto_pattern'             => array( 2, 1, '$handle,$tag' ),
			'maybe_fix_wp_cache'                           => array( 0, 0, '' ),
			'maybe_migrate_ai_anomaly_v2'                  => array( 0, 0, '' ),
			'maybe_migrate_ai_speculation_autotune'        => array( 0, 0, '' ),
			'maybe_migrate_block_assets_setting'           => array( 0, 0, '' ),
			'maybe_migrate_builder_watcher'                => array( 0, 0, '' ),
			'maybe_migrate_ccss_max_size'                  => array( 0, 0, '' ),
			'maybe_migrate_ccss_safelist'                  => array( 0, 0, '' ),
			'maybe_migrate_comment_image_hardening'        => array( 0, 0, '' ),
			'maybe_migrate_css_queue_defaults'             => array( 0, 0, '' ),
			'maybe_migrate_elementor_safe_mode'            => array( 0, 0, '' ),
			'maybe_migrate_image_alt_edge_defaults'        => array( 0, 0, '' ),
			'maybe_migrate_object_cache_outage_flag'       => array( 0, 0, '' ),
			'maybe_migrate_preload_auto_defaults'          => array( 0, 0, '' ),
			'maybe_migrate_rum_sample_rate'                => array( 0, 0, '' ),
			'maybe_migrate_safe_mode'                      => array( 0, 0, '' ),
			'maybe_migrate_speculation_prerender_list'     => array( 0, 0, '' ),
			'maybe_migrate_speculation_top_urls'           => array( 0, 0, '' ),
			'maybe_migrate_third_party_auto'               => array( 0, 0, '' ),
			'maybe_run_upgrades'                           => array( 0, 0, '' ),
			'maybe_run_version_upgrade'                    => array( 0, 0, '' ),
			'maybe_schedule_upgrade_routine'               => array( 2, 0, '$upgrader,$hook_extra' ),
			'minify_css'                                   => array( 3, 0, '$tag,$handle,$href' ),
			'minify_css_exclusions'                        => array( 0, 0, '' ),
			'minify_is_core_block_asset_skipped'           => array( 1, 0, '$handle' ),
			'minify_js'                                    => array( 3, 0, '$tag,$handle,$src' ),
			'minify_js_exclusions'                         => array( 0, 0, '' ),
			'minify_queued_styles'                         => array( 0, 0, '' ),
			'minify_should_optimise_for_logged_in'         => array( 0, 0, '' ),
			'omit_hidden_block_assets'                     => array( 0, 0, '' ),
			'on_aggressive_kill_switch_meta_changed'       => array( 3, 0, '$meta_id,$post_id,$meta_key' ),
			'on_ai_css_refresh_queued'                     => array( 3, 0, '$url,$post_id,$anomaly' ),
			'on_delay_kill_switch_meta_changed'            => array( 3, 0, '$meta_id,$post_id,$meta_key' ),
			'on_extension_update'                          => array( 2, 1, '$upgrader=NULL,$hook_extra=NULL' ),
			'on_save_post_invalidate_cache'                => array( 3, 0, '$post_id,$post,$update' ),
			'on_save_post_queue_used_css'                  => array( 3, 0, '$post_id,$post,$update' ),
			'on_settings_add'                              => array( 2, 1, '$option,$value' ),
			'on_settings_update'                           => array( 2, 1, '$old_value,$value' ),
			'on_site_url_change'                           => array( 3, 1, '$old_value=NULL,$value=NULL,$option=\'\'' ),
			'on_theme_switch_used_css'                     => array( 3, 1, '$new_name=\'\',$new_theme=NULL,$old_theme=NULL' ),
			'on_woocommerce_coupon_saved'                  => array( 1, 0, '$coupon' ),
			'on_woocommerce_order_changed'                 => array( 1, 0, '$order' ),
			'on_woocommerce_product_updated'               => array( 1, 0, '$product_id' ),
			'prepare_defer_delay_state_for_registry'       => array( 1, 0, '$staged_for_registration' ),
			'prepare_minify_excludes_for_registry'         => array( 1, 0, '$kind' ),
			'process_background_image'                     => array( 1, 0, '$args' ),
			'process_used_css_capture'                     => array( 1, 0, '$buffer' ),
			'process_used_css_only'                        => array( 2, 0, '$filtered_output,$output' ),
			'refresh_options'                              => array( 1, 0, '$settings=NULL' ),
			'register_collaborators'                       => array( 0, 0, '' ),
			'remove_woocommerce_scripts'                   => array( 0, 0, '' ),
			'render_htaccess_failure_notice'               => array( 0, 1, '' ),
			'reset_delay_context_memo'                     => array( 0, 1, '' ),
			'reset_delay_third_party_auto_cache'           => array( 0, 1, '' ),
			'reset_elementor_memo'                         => array( 0, 1, '' ),
			'reset_font_preload_emitted'                   => array( 0, 1, '' ),
			'reset_image_lcp_memos'                        => array( 2, 1, '$new_blog_id=0,$prev_blog_id=0' ),
			'reset_instance'                               => array( 0, 1, '' ),
			'reset_speculation_url_memo'                   => array( 0, 1, '' ),
			'script_should_optimise_for_logged_in'         => array( 0, 0, '' ),
			'script_state_defer_disabled_for_page'         => array( 0, 0, '' ),
			'script_state_deferred_handles'                => array( 0, 0, '' ),
			'script_state_delay_disabled_for_page'         => array( 0, 0, '' ),
			'script_state_delay_js_default_strategy'       => array( 0, 0, '' ),
			'script_state_delay_js_idle_list'              => array( 0, 0, '' ),
			'script_state_delay_js_per_page_interaction'   => array( 0, 0, '' ),
			'script_state_delay_js_priority'               => array( 0, 0, '' ),
			'script_state_delay_js_viewport_list'          => array( 0, 0, '' ),
			'script_state_exclude_defer_js'                => array( 0, 0, '' ),
			'script_state_exclude_delay_js'                => array( 0, 0, '' ),
			'script_state_page_preset_opt_out_remove'      => array( 0, 0, '' ),
			'script_state_resolved_delay_exclusions'       => array( 0, 0, '' ),
			'server_timing_enabled'                        => array( 0, 0, '' ),
			'set_role_hash_cookie'                         => array( 0, 0, '' ),
			'should_load_litespeed_stack'                  => array( 0, 1, '' ),
			'should_skip_combine_for_elementor'            => array( 2, 1, '$file_optimisation=array,$post_id=NULL' ),
			'should_use_core_template_buffer'              => array( 0, 1, '' ),
			'start_lcp_priority_buffer'                    => array( 0, 0, '' ),
			'start_used_css_buffer'                        => array( 0, 0, '' ),
			'supports_native_defer_strategy'               => array( 0, 1, '' ),
			'supports_native_script_fetchpriority'         => array( 0, 1, '' ),
			'wppo_register_speculation_rules'              => array( 2, 0, '$rules,$candidate_urls=NULL' ),
		);
	}

	/**
	 * The pinned property surface: name => visibility plus static/readonly flags.
	 *
	 * @return array<string,string>
	 */
	private function pinned_properties(): array {
		return array(
			'auto_fonts_memo'                    => 'private static',
			'cache'                              => 'private',
			'defer_disabled_for_page'            => 'private',
			'defer_disabled_page_cache'          => 'private static',
			'deferred_handles'                   => 'private',
			'delay_disabled_for_page'            => 'private',
			'delay_disabled_page_cache'          => 'private static',
			'delay_js_default_strategy'          => 'private',
			'delay_js_idle_list'                 => 'private',
			'delay_js_idle_timeout'              => 'private',
			'delay_js_per_page_interaction'      => 'private',
			'delay_js_priority'                  => 'private',
			'delay_js_viewport_list'             => 'private',
			'elementor_built_memo'               => 'private static',
			'exclude_css'                        => 'private',
			'exclude_defer_js'                   => 'private',
			'exclude_delay_js'                   => 'private',
			'exclude_js'                         => 'private',
			'filesystem'                         => 'private',
			'font_preload_emitted'               => 'private static',
			'font_stamp_memo'                    => 'private static',
			'google_fonts'                       => 'private',
			'image_optimisation'                 => 'private',
			'instance'                           => 'private static',
			'minify_policy'                      => 'private',
			'options'                            => 'private',
			'options_blog_id'                    => 'private',
			'page_preset_opt_out_remove'         => 'private',
			'preload_buffer_coordinator'         => 'private',
			'resolved_delay_exclusions'          => 'private',
			'script_strategy'                    => 'private',
			'server_timing_template_start'       => 'private',
			'settings_migrations'                => 'private',
			'speculation_commerce_paths_memo'    => 'private static',
			'speculation_commerce_paths_sig'     => 'private static',
			'speculation_normalize_memo'         => 'private static',
			'speculation_prerender_object_added' => 'private',
			'speculation_rules_registered'       => 'private',
			'speculation_rum_top_memo'           => 'private static',
			'speculation_url_validity_memo'      => 'private static',
		);
	}

	/**
	 * The public method surface is exactly what it is today.
	 *
	 * @return void
	 */
	public function test_public_method_surface_is_pinned(): void {
		$this->reset_state();
		$reflection = new \ReflectionClass( Main::class );
		$actual     = array();

		foreach ( $reflection->getMethods() as $method ) {
			if ( Main::class !== $method->getDeclaringClass()->getName() || ! $method->isPublic() ) {
				continue;
			}
			$signature = array();
			foreach ( $method->getParameters() as $parameter ) {
				$default = '';
				if ( $parameter->isDefaultValueAvailable() ) {
					$value = $parameter->getDefaultValue();
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Pinning a default value needs its exact literal.
					$default = '=' . ( is_array( $value ) ? 'array' : var_export( $value, true ) );
				}
				$signature[] = '$' . $parameter->getName() . $default;
			}
			$actual[ $method->getName() ] = array(
				count( $method->getParameters() ),
				(int) $method->isStatic(),
				implode( ',', $signature ),
			);
		}
		ksort( $actual );

		$pinned = $this->pinned_public_methods();

		$this->assertSame(
			array_keys( $pinned ),
			array_keys( $actual ),
			'The set of public methods on Main changed. A refactor must preserve it, or every external caller breaks with it.'
		);
		$this->assertSame(
			$pinned,
			$actual,
			'A public method signature, parameter count, static-ness or default value changed.'
		);
		$this->assertCount( 163, $actual, 'Main exposes 163 public methods today.' );
	}

	/**
	 * The property surface is exactly what it is today.
	 *
	 * @return void
	 */
	public function test_property_surface_is_pinned(): void {
		$this->reset_state();
		$reflection = new \ReflectionClass( Main::class );
		$actual     = array();

		foreach ( $reflection->getProperties() as $property ) {
			if ( Main::class !== $property->getDeclaringClass()->getName() ) {
				continue;
			}
			$actual[ $property->getName() ] = ( $property->isPublic() ? 'public' : ( $property->isProtected() ? 'protected' : 'private' ) )
				. ( $property->isStatic() ? ' static' : '' )
				. ( $property->isReadOnly() ? ' readonly' : '' );
		}
		ksort( $actual );

		$this->assertSame(
			$this->pinned_properties(),
			$actual,
			'A Main property was added, removed, or had its visibility/static-ness changed.'
		);
		$this->assertCount( 40, $actual, 'Main declares 40 properties today.' );
	}

	/**
	 * The constructor takes no arguments and writes no options.
	 *
	 * @return void
	 */
	public function test_constructor_takes_no_arguments_and_writes_no_options(): void {
		$this->reset_state();
		$main = new Main();

		$this->assertInstanceOf( Main::class, $main );
		$this->assertSame(
			array(),
			$this->writes,
			'Constructing Main must not write any option; it wires hooks and records the instance only.'
		);
		$this->assertSame(
			0,
			( new \ReflectionMethod( Main::class, '__construct' ) )->getNumberOfParameters(),
			'The constructor signature is part of the public contract.'
		);
	}

	/**
	 * The add_speculation_rules() method registers exactly these four core
	 * hooks, at these priorities with these accepted-args, on WP 6.8+.
	 * Recorded from class-main.php:7141-7172.
	 *
	 * @return void
	 */
	public function test_add_speculation_rules_registers_exactly_four_core_hooks(): void {
		$this->reset_state();
		$this->options['wppo_settings']['preload_settings'] = array( 'enableSpeculationRules' => true );

		$main        = new Main();
		$this->hooks = array();
		$main->add_speculation_rules();

		$registered = array();
		foreach ( $this->hooks as $hook ) {
			$registered[] = $hook[0] . ' ' . $hook[1] . ' @' . $hook[2] . '/' . $hook[3];
		}

		$this->assertSame(
			array(
				'filter wp_speculation_rules_href_exclude_paths @10/1',
				'filter wp_speculation_rules_configuration @10/1',
				'filter wp_speculation_rules @10/1',
				'action wp_load_speculation_rules @10/1',
			),
			$registered,
			'The core speculation hook names, priorities and accepted-args are the contract; core merges on these exact strings.'
		);
	}

	/**
	 * Short-circuit: below WP 6.8, add_speculation_rules() registers nothing
	 * and never fatals. Recorded from class-main.php:7113-7127.
	 *
	 * @return void
	 */
	public function test_add_speculation_rules_is_a_no_op_below_wp_68(): void {
		$this->reset_state();
		$this->options['wppo_settings']['preload_settings'] = array( 'enableSpeculationRules' => true );
		$GLOBALS['wp_version']                              = '6.7';

		$main        = new Main();
		$this->hooks = array();
		$main->add_speculation_rules();

		$this->assertSame( array(), $this->hooks, 'Pre-6.8 core must register no speculation hooks.' );
	}

	/**
	 * The exclusion list is the current list, in the current order.
	 * Recorded from class-main.php:7243-7253.
	 *
	 * @return void
	 */
	public function test_speculation_exclude_path_list_is_pinned(): void {
		$this->reset_state();
		$main = new Main();

		$this->assertSame(
			array(
				'/wp-login*',
				'/wp-admin/*',
				'/wp-json/*',
				'/wc-ajax/*',
				'/logout/*',
				'/cart/*',
				'/checkout/*',
				'/my-account/*',
				'/account/*',
			),
			$main->get_speculation_exclude_paths( array() ),
			'The speculation href exclusion list is a public contract; /wc-ajax/* is in it and the order is load-bearing for the de-dupe it feeds.'
		);
	}

	/**
	 * Hostile input must never fatal and must never yield a non-string.
	 * The fail-open behaviour recorded at class-main.php:7240-7280.
	 *
	 * @return void
	 */
	public function test_exclude_paths_fail_open_for_hostile_input(): void {
		$this->reset_state();
		$main = new Main();

		$inputs = array(
			array(),
			array( 'garbage' => true ),
			array( 'preload_settings' => 'not-an-array' ),
			array( 'preload_settings' => array( 'cdnDomains' => 'not-an-array' ) ),
		);

		foreach ( $inputs as $input ) {
			$paths = $main->get_speculation_exclude_paths( $input );
			$this->assertIsArray( $paths, 'Must always return an array.' );
			foreach ( $paths as $path ) {
				$this->assertIsString( $path, 'Every exclusion must stay a string.' );
			}
		}
	}
}
