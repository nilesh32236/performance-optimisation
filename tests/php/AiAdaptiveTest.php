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
				'wp_parse_url',
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
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$model = AI_Adaptive::learn();
		$this->assertSame( 'eager', $model['eagerness'] );
	}

	/**
	 * Test learn caps eager eagerness to moderate for logged-in users.
	 *
	 * @return void
	 */
	public function test_learn_caps_eager_to_moderate_in_commerce_context(): void {
		$this->install_stubs();
		$this->seed_eager_rum();
		unset( $_COOKIE['woocommerce_items_in_cart'], $_COOKIE['woocommerce_cart_hash'] );
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$model = AI_Adaptive::learn();
		$this->assertSame( 'moderate', $model['eagerness'] );
	}

	/**
	 * Test learn caps eager eagerness when an active cart cookie is present.
	 *
	 * @return void
	 */
	public function test_learn_caps_eager_to_moderate_via_cart_cookie(): void {
		$this->install_stubs();
		$this->seed_eager_rum();
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$_COOKIE['woocommerce_items_in_cart'] = '1';

		$model = AI_Adaptive::learn();
		unset( $_COOKIE['woocommerce_items_in_cart'] );
		$this->assertSame( 'moderate', $model['eagerness'] );
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
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$suggestions = AI_Adaptive::get_suggestions();

		$eagerness_suggestion = $this->find_suggestion( $suggestions, 'ai_speculation_eagerness' );
		$this->assertNotNull( $eagerness_suggestion );
		$this->assertSame( 'eager', $eagerness_suggestion['value'] );
		$this->assertNull( $this->find_suggestion( $suggestions, 'ai_speculation_excludes' ) );
	}
}
