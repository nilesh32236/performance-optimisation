<?php
/**
 * Tests for AI Adaptive (N1).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\AI_Adaptive;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests is_enabled gate, heuristic learn and suggestion generation.
 */
class AiAdaptiveTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options store.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * In-memory transients store.
	 *
	 * @var array
	 */
	private $transients = array();

	/**
	 * Install Brain Monkey stubs.
	 *
	 * @return void
	 */
	private function install_stubs(): void {
		Functions\stubs(
			array(
				'get_option',
				'update_option',
				'get_transient',
				'set_transient',
				'delete_transient',
				'esc_url_raw',
				'sanitize_text_field',
				'apply_filters',
				'is_multisite',
				'get_current_blog_id',
				'home_url',
			)
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return array_key_exists( $key, $this->transients ) ? $this->transients[ $key ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $exp = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$this->transients[ $key ] = $value;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				// Return filtered value (wppo_ai_adaptive_enabled passes bool, eagerness passes string).
				return $value;
			}
		);
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $url ) {
				return rtrim( (string) $url, '/' );
			}
		);
		// Restore component parsing (bootstrap alias): install_stubs() must not
		// leave wp_parse_url as returnArg, or Woo URL derivation gets full URLs.
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->alias(
			function ( $path = '' ) {
				return 'http://example.com' . $path;
			}
		);
	}

	/**
	 * Test is_enabled returns false when disabled.
	 *
	 * @return void
	 */
	public function test_is_enabled_false_by_default(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array( 'ai_adaptive' => array( 'enabled' => false ) );
		Util::clear_settings_cache();
		$this->assertFalse( AI_Adaptive::is_enabled() );
	}

	/**
	 * Test is_enabled returns true when enabled.
	 *
	 * @return void
	 */
	public function test_is_enabled_true_when_setting_set(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array( 'ai_adaptive' => array( 'enabled' => true ) );
		Util::clear_settings_cache();
		// apply_filters returns true by stub.
		$this->assertTrue( AI_Adaptive::is_enabled() );
	}

	/**
	 * Test heuristic learn produces model with prefetch URLs.
	 *
	 * @return void
	 */
	public function test_learn_heuristic_produces_model(): void {
		$this->install_stubs();
		$today                                   = gmdate( 'Y-m-d' );
		$this->options['wppo_web_vitals_rum']    = array(
			$today => array(
				'/slow/' => array(
					'lcp'  => array(
						'n'   => 5,
						'sum' => 20000,
						'min' => 3000,
						'max' => 5000,
					),
					'ttfb' => array(
						'n'   => 5,
						'sum' => 4000,
						'min' => 700,
						'max' => 900,
					),
				),
				'/fast/' => array(
					'lcp' => array(
						'n'   => 1,
						'sum' => 1000,
						'min' => 1000,
						'max' => 1000,
					),
				),
			),
		);
		$this->options['wppo_web_vitals_trends'] = array();
		$this->options['wppo_settings']          = array( 'ai_adaptive' => array( 'enabled' => true ) );
		Util::clear_settings_cache();

		$model = AI_Adaptive::learn();
		$this->assertIsArray( $model );
		$this->assertSame( 'heuristic', $model['source'] );
		$this->assertNotEmpty( $model['prefetch_urls'] );
		$this->assertContains( 'http://example.com/slow/', $model['prefetch_urls'] );
	}

	/**
	 * Test get_prefetch_urls returns top two URLs.
	 *
	 * @return void
	 */
	public function test_get_prefetch_urls_returns_top_two(): void {
		$this->install_stubs();
		$this->options[ AI_Adaptive::OPTION ] = array(
			'prefetch_urls' => array( 'http://example.com/a/', 'http://example.com/b/', 'http://example.com/c/' ),
			'eagerness'     => 'conservative',
		);
		$urls                                 = AI_Adaptive::get_prefetch_urls();
		$this->assertCount( 2, $urls );
		$this->assertSame( 'http://example.com/a/', $urls[0] );
	}

	/**
	 * Test speculation rules respect disabled flag.
	 *
	 * @return void
	 */
	public function test_filter_speculation_rules_respects_disabled(): void {
		$this->install_stubs();
		$this->options['wppo_settings']       = array( 'ai_adaptive' => array( 'enabled' => false ) );
		$this->options[ AI_Adaptive::OPTION ] = array(
			'prefetch_urls' => array( 'http://example.com/a/' ),
			'eagerness'     => 'conservative',
		);
		Util::clear_settings_cache();
		$rules = AI_Adaptive::filter_speculation_rules( array() );
		$this->assertSame( array(), $rules );
	}

	/**
	 * Seed RUM aggregates with average LCP above 3500 (forces `eager` eagerness).
	 *
	 * @return void
	 */
	private function seed_eager_rum(): void {
		$today                                   = gmdate( 'Y-m-d' );
		$this->options['wppo_web_vitals_rum']    = array(
			$today => array(
				'/slow/' => array(
					'lcp'  => array(
						'n'   => 5,
						'sum' => 20000,
						'min' => 3000,
						'max' => 5000,
					),
					'ttfb' => array(
						'n'   => 5,
						'sum' => 4000,
						'min' => 700,
						'max' => 900,
					),
				),
			),
		);
		$this->options['wppo_web_vitals_trends'] = array();
		$this->options['wppo_settings']          = array( 'ai_adaptive' => array( 'enabled' => true ) );
		Util::clear_settings_cache();
	}

	/**
	 * Find a suggestion by metric name.
	 *
	 * @param array[] $suggestions Suggestion list.
	 * @param string  $metric      Metric to find.
	 * @return array|null
	 */
	private function find_suggestion( array $suggestions, string $metric ): ?array {
		foreach ( $suggestions as $suggestion ) {
			if ( ( $suggestion['metric'] ?? '' ) === $metric ) {
				return $suggestion;
			}
		}
		return null;
	}

	/**
	 * Test learn preserves eager eagerness without a commerce/auth context.
	 *
	 * @return void
	 */
	public function test_learn_preserves_eager_without_commerce_context(): void {
		$this->install_stubs();
		$this->seed_eager_rum();
		unset( $_COOKIE['woocommerce_items_in_cart'], $_COOKIE['woocommerce_cart_hash'] );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$model = AI_Adaptive::learn();
		$this->assertSame( 'eager', $model['eagerness'] );
	}

	/**
	 * Test learn caps eager eagerness to moderate for logged-in frontend visitors.
	 *
	 * @return void
	 */
	public function test_learn_caps_eager_to_moderate_in_commerce_context(): void {
		$this->install_stubs();
		$this->seed_eager_rum();
		unset( $_COOKIE['woocommerce_items_in_cart'], $_COOKIE['woocommerce_cart_hash'] );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$model = AI_Adaptive::learn();
		$this->assertSame( 'moderate', $model['eagerness'] );
	}

	/**
	 * Test learn in wp-admin (always logged in) is not capped by the login probe.
	 *
	 * Learn/get_suggestions execute via manage_options REST endpoints in
	 * wp-admin; the logged-in admin there is not a frontend visitor, so a
	 * plain non-commerce site can still learn `eager`.
	 *
	 * @return void
	 */
	public function test_learn_in_admin_context_ignores_login_probe(): void {
		$this->install_stubs();
		$this->seed_eager_rum();
		unset( $_COOKIE['woocommerce_items_in_cart'], $_COOKIE['woocommerce_cart_hash'] );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$this->assertFalse( AI_Adaptive::is_commerce_or_auth_context() );
		$model = AI_Adaptive::learn();
		$this->assertSame( 'eager', $model['eagerness'] );
	}

	/**
	 * Test learn caps eager eagerness when an active cart cookie is present.
	 *
	 * @return void
	 */
	public function test_learn_caps_eager_to_moderate_via_cart_cookie(): void {
		$this->install_stubs();
		$this->seed_eager_rum();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$_COOKIE['woocommerce_items_in_cart'] = '1';

		$model = AI_Adaptive::learn();
		unset( $_COOKIE['woocommerce_items_in_cart'] );
		$this->assertSame( 'moderate', $model['eagerness'] );
	}

	/**
	 * Fake AI client returning a canned prompt() result.
	 *
	 * @param mixed $result Canned prompt() result.
	 * @return object
	 */
	private function fake_ai_client( $result ): object {
		return new class( $result ) {
			/**
			 * Canned result.
			 *
			 * @var mixed
			 */
			private $result;

			/**
			 * Constructor.
			 *
			 * @param mixed $result Canned prompt() result.
			 */
			public function __construct( $result ) {
				$this->result = $result;
			}

			/**
			 * Return the canned result.
			 *
			 * @param string $prompt Prompt text (ignored).
			 * @return mixed
			 */
			public function prompt( $prompt ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
				return $this->result;
			}
		};
	}

	/**
	 * Test the AI-client path caps eager in commerce context and sanitizes output.
	 *
	 * @return void
	 */
	public function test_learn_via_ai_client_caps_eager_in_commerce_context(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array( 'ai_adaptive' => array( 'enabled' => true, 'use_wp_ai_client' => true ) );
		Util::clear_settings_cache();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_ai_client' )->justReturn(
			$this->fake_ai_client(
				array(
					'eagerness'     => 'eager',
					'prefetch_urls' => array( 'http://example.com/x/', 'http://example.com/y/', 'http://example.com/z/', array( 'not-a-string' ) ),
					'exclude_js'    => array( 'handle-a', 'handle-b', 'handle-c', 'handle-d' ),
					'rogue_key'     => 'dropped',
				)
			)
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$model = AI_Adaptive::learn();
		$this->assertSame( 'ai_client', $model['source'] );
		$this->assertSame( 'moderate', $model['eagerness'] );
		$this->assertSame( 1, $model['version'] );
		// Untrusted LLM output is sanitized at persist: URLs sliced to top-2
		// with non-strings dropped, handles sliced to 3.
		$this->assertSame( array( 'http://example.com/x/', 'http://example.com/y/' ), $model['prefetch_urls'] );
		$this->assertSame( array( 'handle-a', 'handle-b', 'handle-c' ), $model['exclude_js'] );
		// Unknown LLM keys are not persisted.
		$this->assertArrayNotHasKey( 'rogue_key', $model );
		$this->assertSame( 'moderate', $this->options[ AI_Adaptive::OPTION ]['eagerness'] );
	}

	/**
	 * Test the AI-client path coerces garbage eagerness to conservative.
	 *
	 * @return void
	 */
	public function test_learn_via_ai_client_validates_garbage_eagerness(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array( 'ai_adaptive' => array( 'enabled' => true, 'use_wp_ai_client' => true ) );
		Util::clear_settings_cache();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_ai_client' )->justReturn(
			$this->fake_ai_client( array( 'eagerness' => 'aggressive' ) )
		);
		unset( $_COOKIE['woocommerce_items_in_cart'], $_COOKIE['woocommerce_cart_hash'] );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$model = AI_Adaptive::learn();
		$this->assertSame( 'ai_client', $model['source'] );
		$this->assertSame( 'conservative', $model['eagerness'] );
		$this->assertSame( 1, $model['version'] );
	}

	/**
	 * Test the AI-client path defaults a missing eagerness key to conservative.
	 *
	 * @return void
	 */
	public function test_learn_via_ai_client_defaults_missing_eagerness(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array( 'ai_adaptive' => array( 'enabled' => true, 'use_wp_ai_client' => true ) );
		Util::clear_settings_cache();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_ai_client' )->justReturn(
			$this->fake_ai_client( array( 'prefetch_urls' => array( 'http://example.com/x/' ) ) )
		);
		unset( $_COOKIE['woocommerce_items_in_cart'], $_COOKIE['woocommerce_cart_hash'] );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$model = AI_Adaptive::learn();
		$this->assertSame( 'ai_client', $model['source'] );
		$this->assertArrayHasKey( 'eagerness', $model );
		$this->assertSame( 'conservative', $model['eagerness'] );
		$this->assertSame( 1, $model['version'] );
	}

	/**
	 * Test stale invalid eagerness is allowlisted before rule injection.
	 *
	 * @return void
	 */
	public function test_filter_speculation_rules_validates_stale_eagerness(): void {
		$this->install_stubs();
		$this->options['wppo_settings']       = array( 'ai_adaptive' => array( 'enabled' => true ) );
		$this->options[ AI_Adaptive::OPTION ] = array(
			'prefetch_urls' => array( 'http://example.com/a/' ),
			'eagerness'     => 'aggressive',
		);
		Util::clear_settings_cache();
		unset( $_COOKIE['woocommerce_items_in_cart'], $_COOKIE['woocommerce_cart_hash'] );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$rules = AI_Adaptive::filter_speculation_rules( array() );
		$this->assertCount( 1, $rules );
		$this->assertSame( 'conservative', $rules[0]['eagerness'] );
	}

	/**
	 * Test commerce URLs are excluded from AI prefetch injection in commerce context.
	 *
	 * Explicit list-source rules bypass href exclude-path filtering, so the
	 * model-level filter is the only guard for a nominated /checkout/.
	 *
	 * @return void
	 */
	public function test_filter_speculation_rules_drops_commerce_prefetch_urls(): void {
		$this->install_stubs();
		$this->options['wppo_settings']       = array( 'ai_adaptive' => array( 'enabled' => true ) );
		$this->options[ AI_Adaptive::OPTION ] = array(
			'prefetch_urls' => array( 'http://example.com/checkout/', 'http://example.com/a/' ),
			'eagerness'     => 'moderate',
		);
		Util::clear_settings_cache();
		unset( $_COOKIE['woocommerce_items_in_cart'], $_COOKIE['woocommerce_cart_hash'] );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$rules = AI_Adaptive::filter_speculation_rules( array() );
		$this->assertCount( 1, $rules );
		$this->assertSame( array( 'http://example.com/a/' ), $rules[0]['urls'] );
	}

	/**
	 * Test prefetch URLs are preserved without a commerce context.
	 *
	 * @return void
	 */
	public function test_filter_speculation_rules_preserves_prefetch_without_commerce_context(): void {
		$this->install_stubs();
		$this->options['wppo_settings']       = array( 'ai_adaptive' => array( 'enabled' => true ) );
		$this->options[ AI_Adaptive::OPTION ] = array(
			'prefetch_urls' => array( 'http://example.com/checkout/', 'http://example.com/a/' ),
			'eagerness'     => 'moderate',
		);
		Util::clear_settings_cache();
		unset( $_COOKIE['woocommerce_items_in_cart'], $_COOKIE['woocommerce_cart_hash'] );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$rules = AI_Adaptive::filter_speculation_rules( array() );
		$this->assertCount( 1, $rules );
		$this->assertSame( array( 'http://example.com/checkout/', 'http://example.com/a/' ), $rules[0]['urls'] );
	}

	/**
	 * Test the eagerness filter cannot loosen past moderate in commerce context.
	 *
	 * @return void
	 */
	public function test_eagerness_filter_cannot_loosen_past_moderate_in_commerce_context(): void {
		$this->install_stubs();
		$this->options['wppo_web_vitals_rum']    = array();
		$this->options['wppo_web_vitals_trends'] = array();
		$this->options['wppo_settings']          = array( 'ai_adaptive' => array( 'enabled' => true ) );
		Util::clear_settings_cache();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				if ( 'wppo_ai_adaptive_eagerness' === $hook ) {
					return 'eager';
				}
				return $value;
			}
		);

		$model = AI_Adaptive::learn();
		$this->assertSame( 'moderate', $model['eagerness'] );
	}

	/**
	 * Test the commerce-context filter can force the context deterministically.
	 *
	 * @return void
	 */
	public function test_commerce_context_filter_can_force_context(): void {
		$this->install_stubs();
		unset( $_COOKIE['woocommerce_items_in_cart'], $_COOKIE['woocommerce_cart_hash'] );
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				if ( 'wppo_ai_adaptive_commerce_context' === $hook ) {
					return true;
				}
				return $value;
			}
		);

		$this->assertTrue( AI_Adaptive::is_commerce_or_auth_context() );
		$this->assertSame( 'moderate', AI_Adaptive::maybe_cap_eagerness( 'eager' ) );
		$this->assertSame( 'conservative', AI_Adaptive::maybe_cap_eagerness( 'conservative' ) );
	}

	/**
	 * Test commerce exclude paths fall back when WooCommerce helpers are absent.
	 *
	 * @return void
	 */
	public function test_get_commerce_exclude_paths_falls_back_without_woo(): void {
		$this->install_stubs();

		$this->assertSame(
			array( '/cart/*', '/checkout/*', '/my-account/*' ),
			AI_Adaptive::get_commerce_exclude_paths()
		);
	}

	/**
	 * Test speculation rules cap a stale eager model in commerce context.
	 *
	 * @return void
	 */
	public function test_filter_speculation_rules_caps_stale_eager_in_commerce_context(): void {
		$this->install_stubs();
		$this->options['wppo_settings']       = array( 'ai_adaptive' => array( 'enabled' => true ) );
		$this->options[ AI_Adaptive::OPTION ] = array(
			'prefetch_urls' => array( 'http://example.com/a/' ),
			'eagerness'     => 'eager',
		);
		Util::clear_settings_cache();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$rules = AI_Adaptive::filter_speculation_rules( array() );
		$this->assertCount( 1, $rules );
		$this->assertSame( 'moderate', $rules[0]['eagerness'] );
	}

	/**
	 * Test speculation rules preserve eager without a commerce context.
	 *
	 * @return void
	 */
	public function test_filter_speculation_rules_preserves_eager_without_commerce_context(): void {
		$this->install_stubs();
		$this->options['wppo_settings']       = array( 'ai_adaptive' => array( 'enabled' => true ) );
		$this->options[ AI_Adaptive::OPTION ] = array(
			'prefetch_urls' => array( 'http://example.com/a/' ),
			'eagerness'     => 'eager',
		);
		Util::clear_settings_cache();
		unset( $_COOKIE['woocommerce_items_in_cart'], $_COOKIE['woocommerce_cart_hash'] );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$rules = AI_Adaptive::filter_speculation_rules( array() );
		$this->assertCount( 1, $rules );
		$this->assertSame( 'eager', $rules[0]['eagerness'] );
	}

	/**
	 * Test suggestions cap eagerness and append commerce excludes in commerce context.
	 *
	 * @return void
	 */
	public function test_get_suggestions_caps_eagerness_and_appends_commerce_excludes(): void {
		$this->install_stubs();
		$this->options[ AI_Adaptive::OPTION ] = array(
			'prefetch_urls' => array( 'http://example.com/a/' ),
			'eagerness'     => 'eager',
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$suggestions = AI_Adaptive::get_suggestions();

		$eagerness_suggestion = $this->find_suggestion( $suggestions, 'ai_speculation_eagerness' );
		$this->assertNotNull( $eagerness_suggestion );
		$this->assertSame( 'moderate', $eagerness_suggestion['value'] );

		$excludes_suggestion = $this->find_suggestion( $suggestions, 'ai_speculation_excludes' );
		$this->assertNotNull( $excludes_suggestion );
		$this->assertStringContainsString( '/cart/', $excludes_suggestion['value'] );
		$this->assertStringContainsString( '/checkout/', $excludes_suggestion['value'] );
		$this->assertStringContainsString( '/cart/', $excludes_suggestion['ai_payload']['settings']['speculationExcludeUrls'] );
	}

	/**
	 * Test suggestions omit commerce excludes already present in settings.
	 *
	 * The suggestion must resolve once the user applies it instead of
	 * reappearing on every load.
	 *
	 * @return void
	 */
	public function test_get_suggestions_omits_excludes_when_already_configured(): void {
		$this->install_stubs();
		$this->options[ AI_Adaptive::OPTION ] = array(
			'prefetch_urls' => array( 'http://example.com/a/' ),
			'eagerness'     => 'eager',
		);
		$this->options['wppo_settings']       = array(
			'preload_settings' => array(
				'speculationExcludeUrls' => "/cart/*\n/checkout/*\n/my-account/*",
			),
		);
		Util::clear_settings_cache();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$suggestions = AI_Adaptive::get_suggestions();

		// Eagerness suggestion is still emitted (capped), excludes resolve.
		$eagerness_suggestion = $this->find_suggestion( $suggestions, 'ai_speculation_eagerness' );
		$this->assertNotNull( $eagerness_suggestion );
		$this->assertSame( 'moderate', $eagerness_suggestion['value'] );
		$this->assertNull( $this->find_suggestion( $suggestions, 'ai_speculation_excludes' ) );
	}

	/**
	 * Test non-string (malformed) eagerness coerces to conservative without warnings.
	 *
	 * @return void
	 */
	public function test_non_string_eagerness_coerces_to_conservative(): void {
		$this->install_stubs();
		$this->options[ AI_Adaptive::OPTION ] = array(
			'prefetch_urls' => array( 'http://example.com/a/' ),
			'eagerness'     => array( 'eager' ),
		);
		$this->options['wppo_settings']       = array( 'ai_adaptive' => array( 'enabled' => true ) );
		Util::clear_settings_cache();
		unset( $_COOKIE['woocommerce_items_in_cart'], $_COOKIE['woocommerce_cart_hash'] );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$suggestions = AI_Adaptive::get_suggestions();
		$this->assertNull( $this->find_suggestion( $suggestions, 'ai_speculation_eagerness' ) );

		$rules = AI_Adaptive::filter_speculation_rules( array() );
		$this->assertCount( 1, $rules );
		$this->assertSame( 'conservative', $rules[0]['eagerness'] );
	}

	/**
	 * Test suggestions omit commerce excludes without a commerce context.
	 *
	 * @return void
	 */
	public function test_get_suggestions_omits_commerce_excludes_without_commerce_context(): void {
		$this->install_stubs();
		$this->options[ AI_Adaptive::OPTION ] = array(
			'prefetch_urls' => array( 'http://example.com/a/' ),
			'eagerness'     => 'eager',
		);
		unset( $_COOKIE['woocommerce_items_in_cart'], $_COOKIE['woocommerce_cart_hash'] );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$suggestions = AI_Adaptive::get_suggestions();

		$eagerness_suggestion = $this->find_suggestion( $suggestions, 'ai_speculation_eagerness' );
		$this->assertNotNull( $eagerness_suggestion );
		$this->assertSame( 'eager', $eagerness_suggestion['value'] );
		$this->assertNull( $this->find_suggestion( $suggestions, 'ai_speculation_excludes' ) );
	}

	/**
	 * Test excludes suggestion only lists missing paths but preserves existing in payload.
	 *
	 * @return void
	 */
	public function test_get_suggestions_excludes_preserve_existing_on_apply(): void {
		$this->install_stubs();
		$this->options[ AI_Adaptive::OPTION ] = array(
			'prefetch_urls' => array( 'http://example.com/a/' ),
			'eagerness'     => 'eager',
		);
		$this->options['wppo_settings']       = array(
			'preload_settings' => array(
				// Formatting variant ('/cart' without trailing slash) must
				// still count as configured via normalized comparison.
				'speculationExcludeUrls' => "  /cart  \n/members/*",
			),
		);
		Util::clear_settings_cache();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$suggestions = AI_Adaptive::get_suggestions();

		$excludes_suggestion = $this->find_suggestion( $suggestions, 'ai_speculation_excludes' );
		$this->assertNotNull( $excludes_suggestion );
		$this->assertSame( '/checkout/*, /my-account/*', $excludes_suggestion['value'] );
		// Payload preserves the user's existing excludes and appends missing.
		$payload_excludes = $excludes_suggestion['ai_payload']['settings']['speculationExcludeUrls'];
		$this->assertStringContainsString( '/members/*', $payload_excludes );
		$this->assertStringContainsString( '/checkout/*', $payload_excludes );
		$this->assertStringContainsString( '/my-account/*', $payload_excludes );
	}

	/**
	 * Test full-URL existing excludes match path patterns (no re-suggest).
	 *
	 * @return void
	 */
	public function test_get_suggestions_omits_excludes_matching_full_urls(): void {
		$this->install_stubs();
		$this->options[ AI_Adaptive::OPTION ] = array(
			'prefetch_urls' => array( 'http://example.com/a/' ),
			'eagerness'     => 'eager',
		);
		$this->options['wppo_settings']       = array(
			'preload_settings' => array(
				'speculationExcludeUrls' => "https://example.com/cart/*\nhttps://example.com/checkout/*\nhttps://example.com/my-account/*",
			),
		);
		Util::clear_settings_cache();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$suggestions = AI_Adaptive::get_suggestions();

		$this->assertNull( $this->find_suggestion( $suggestions, 'ai_speculation_excludes' ) );
	}

	/**
	 * Test shop-page probes (cart/checkout/account) signal a commerce context.
	 *
	 * @return void
	 */
	public function test_is_commerce_context_detects_shop_pages(): void {
		$this->install_stubs();
		unset( $_COOKIE['woocommerce_items_in_cart'], $_COOKIE['woocommerce_cart_hash'] );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		Functions\when( 'is_cart' )->justReturn( true );
		$this->assertTrue( AI_Adaptive::is_commerce_or_auth_context() );

		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( true );
		$this->assertTrue( AI_Adaptive::is_commerce_or_auth_context() );

		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( true );
		$this->assertTrue( AI_Adaptive::is_commerce_or_auth_context() );
	}

	/**
	 * Test commerce exclude paths derive from WooCommerce URLs when available.
	 *
	 * Runs last: covering the Woo-presence branch requires eval-declaring the
	 * Woo helpers, and Brain Monkey cannot undeclare functions, so these
	 * definitions persist process-wide (bare function_exists probe reads
	 * true afterwards). Contained by file order — no commerce-off assertion
	 * follows — and the full suite is green with them; this matches the
	 * existing SystemInfoTest.collects_urls precedent, which leaks the same
	 * helpers. A function_exists override cannot substitute: presence-branch
	 * coverage needs callable helpers, not just a true existence report.
	 *
	 * @return void
	 */
	public function test_get_commerce_exclude_paths_derives_woo_urls(): void {
		$this->install_stubs();
		// Mimic core trailingslashit (bootstrap stubs it as identity) so the
		// slash-adding branch is verified with a slash-less Woo URL.
		Functions\when( 'trailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/' ) . '/';
			}
		);
		Functions\when( 'wc_get_checkout_url' )->justReturn( 'http://example.com/checkout' );
		Functions\when( 'wc_get_cart_url' )->justReturn( 'http://example.com/cart/' );
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'http://example.com/my-account/' );

		$this->assertSame(
			array( '/cart/*', '/checkout/*', '/my-account/*' ),
			AI_Adaptive::get_commerce_exclude_paths()
		);
	}

	/**
	 * Test an active WooCommerce install signals a commerce context site-wide.
	 *
	 * Runs last (see test_get_commerce_exclude_paths_derives_woo_urls).
	 *
	 * @return void
	 */
	public function test_woo_active_signals_commerce_context(): void {
		$this->install_stubs();
		unset( $_COOKIE['woocommerce_items_in_cart'], $_COOKIE['woocommerce_cart_hash'] );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wc_get_checkout_url' )->justReturn( 'http://example.com/checkout/' );

		$this->assertTrue( AI_Adaptive::is_commerce_or_auth_context() );
	}

	/**
	 * Test AI URLs already covered by an existing list rule are deduped.
	 *
	 * Main's high-value list runs at priority 10, AI at 20, so the AI rule
	 * must not double-prefetch the same URL.
	 *
	 * @return void
	 */
	public function test_filter_speculation_rules_dedupes_against_existing_list(): void {
		$this->install_stubs();
		$this->options['wppo_settings']       = array( 'ai_adaptive' => array( 'enabled' => true ) );
		$this->options[ AI_Adaptive::OPTION ] = array(
			'prefetch_urls' => array( 'http://example.com/a/', 'http://example.com/b/' ),
			'eagerness'     => 'conservative',
		);
		Util::clear_settings_cache();
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $url ) {
				return rtrim( (string) $url, '/' );
			}
		);
		unset( $_COOKIE['woocommerce_items_in_cart'], $_COOKIE['woocommerce_cart_hash'] );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$existing = array(
			array(
				'source'    => 'list',
				'urls'      => array( 'http://example.com/a/' ),
				'eagerness' => 'moderate',
			),
		);

		$rules = AI_Adaptive::filter_speculation_rules( $existing );
		$this->assertCount( 2, $rules );
		$this->assertSame( array( 'http://example.com/b/' ), $rules[1]['urls'] );
	}

	/**
	 * Test cross-site, admin, and login AI URLs are skipped individually.
	 *
	 * @return void
	 */
	public function test_filter_speculation_rules_skips_cross_site_and_admin_urls(): void {
		$this->install_stubs();
		$this->options['wppo_settings']       = array( 'ai_adaptive' => array( 'enabled' => true ) );
		$this->options[ AI_Adaptive::OPTION ] = array(
			// Note: the model keeps top-2 prefetch URLs, so the valid URL
			// must be within the first two entries to reach the filter.
			'prefetch_urls' => array(
				'https://evil.test/steal/',
				'http://example.com/good/',
			),
			'eagerness'     => 'conservative',
		);
		Util::clear_settings_cache();
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $url ) {
				return rtrim( (string) $url, '/' );
			}
		);
		unset( $_COOKIE['woocommerce_items_in_cart'], $_COOKIE['woocommerce_cart_hash'] );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$rules = AI_Adaptive::filter_speculation_rules( array() );
		$this->assertCount( 1, $rules );
		$this->assertSame( array( 'http://example.com/good/' ), $rules[0]['urls'] );

		// Admin/login URLs are skipped the same way.
		$this->options[ AI_Adaptive::OPTION ]['prefetch_urls'] = array(
			'http://example.com/wp-admin/edit.php',
			'http://example.com/good/',
		);
		$rules = AI_Adaptive::filter_speculation_rules( array() );
		$this->assertCount( 1, $rules );
		$this->assertSame( array( 'http://example.com/good/' ), $rules[0]['urls'] );
	}

	/**
	 * Test learn stays on the local heuristic when the opt-in is off.
	 *
	 * Even with a wp_ai_client function present, the default path must be
	 * local-only with no remote calls.
	 *
	 * @return void
	 */
	public function test_learn_stays_heuristic_without_opt_in(): void {
		$this->install_stubs();
		$this->seed_eager_rum();
		$this->options['wppo_settings'] = array( 'ai_adaptive' => array( 'enabled' => true ) );
		Util::clear_settings_cache();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_ai_client' )->justReturn(
			$this->fake_ai_client( array( 'eagerness' => 'moderate' ) )
		);
		unset( $_COOKIE['woocommerce_items_in_cart'], $_COOKIE['woocommerce_cart_hash'] );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$this->assertFalse( AI_Adaptive::is_wp_ai_client_enabled() );
		$model = AI_Adaptive::learn();
		$this->assertSame( 'heuristic', $model['source'] );
	}

	/**
	 * Test learn uses the AI client path only with the explicit opt-in.
	 *
	 * @return void
	 */
	public function test_learn_uses_ai_client_with_opt_in(): void {
		$this->install_stubs();
		$this->seed_eager_rum();
		$this->options['wppo_settings'] = array( 'ai_adaptive' => array( 'enabled' => true, 'use_wp_ai_client' => true ) );
		Util::clear_settings_cache();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_ai_client' )->justReturn(
			$this->fake_ai_client( array( 'eagerness' => 'moderate' ) )
		);
		unset( $_COOKIE['woocommerce_items_in_cart'], $_COOKIE['woocommerce_cart_hash'] );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$this->assertTrue( AI_Adaptive::is_wp_ai_client_enabled() );
		$model = AI_Adaptive::learn();
		$this->assertSame( 'ai_client', $model['source'] );
	}

	/**
	 * Test every suggestion validates as a suggestion object.
	 *
	 * @return void
	 */
	public function test_get_suggestions_all_items_validate_as_suggestions(): void {
		$this->install_stubs();
		$this->seed_eager_rum();
		$this->options[ AI_Adaptive::OPTION ] = array(
			'prefetch_urls' => array( 'http://example.com/a/' ),
			'exclude_js'    => array( 'handle-a' ),
			'exclude_css'   => array( 'style-a' ),
			'eagerness'     => 'moderate',
		);
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$suggestions = AI_Adaptive::get_suggestions();
		$this->assertNotEmpty( $suggestions );
		$allowed = \PerformanceOptimise\Inc\Suggestion_Engine::VALID_FIX_ACTIONS;
		foreach ( $suggestions as $s ) {
			foreach ( array( 'metric', 'value', 'unit', 'status', 'description', 'fix_action' ) as $key ) {
				$this->assertArrayHasKey( $key, $s, "Missing key {$key}" );
			}
			$this->assertContains( $s['status'], array( 'good', 'needs_improvement', 'poor' ) );
			$this->assertContains( $s['fix_action'], $allowed );
		}
	}

	/**
	 * Test learn makes no HTTP requests without the opt-in.
	 *
	 * @return void
	 */
	public function test_learn_makes_no_http_requests_without_opt_in(): void {
		$this->install_stubs();
		$this->seed_eager_rum();
		$this->options['wppo_settings'] = array( 'ai_adaptive' => array( 'enabled' => true ) );
		Util::clear_settings_cache();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_ai_client' )->justReturn(
			$this->fake_ai_client( array( 'eagerness' => 'moderate' ) )
		);
		foreach ( array( 'wp_remote_get', 'wp_remote_post', 'wp_safe_remote_get', 'wp_safe_remote_post' ) as $http_fn ) {
			Functions\when( $http_fn )->alias(
				static function () {
					throw new \Exception( 'HTTP must not be called without opt-in' );
				}
			);
		}
		unset( $_COOKIE['woocommerce_items_in_cart'], $_COOKIE['woocommerce_cart_hash'] );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$model = AI_Adaptive::learn();
		$this->assertSame( 'heuristic', $model['source'] );
	}
}
