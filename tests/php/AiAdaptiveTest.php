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
	 * Helper to build RUM data with a given avg LCP and invoke heuristic via learn().
	 *
	 * @param float $avg_lcp Average LCP to encode.
	 * @return string Eagerness.
	 */
	private function eagerness_for_avg_lcp( float $avg_lcp ): string {
		$this->install_stubs();
		$today = gmdate( 'Y-m-d' );
		// Use n=2 sum=avg*2 so avg is exact.
		$this->options[ AI_Adaptive::OPTION ] = array(); // Ensure learn recomputes.
		$this->options['wppo_web_vitals_rum'] = array(
			$today => array(
				'/a/' => array(
					'lcp' => array(
						'n'   => 2,
						'sum' => $avg_lcp * 2,
						'min' => (int) $avg_lcp,
						'max' => (int) $avg_lcp,
					),
				),
			),
		);
		$this->options['wppo_web_vitals_trends'] = array();
		$this->options['wppo_settings']          = array( 'ai_adaptive' => array( 'enabled' => true ) );
		Util::clear_settings_cache();
		// Clear previous learn lock.
		$this->transients = array();
		$model = AI_Adaptive::learn();
		return $model['eagerness'] ?? 'conservative';
	}

	/**
	 * Test eagerness is eager when avg LCP > 3500.
	 */
	public function test_eagerness_is_eager_above_3500(): void {
		$eagerness = $this->eagerness_for_avg_lcp( 3600 );
		$this->assertSame( 'eager', $eagerness );
		// Boundary: exactly 3500 should NOT be eager (requires >3500).
		$this->transients = array();
		$eagerness2 = $this->eagerness_for_avg_lcp( 3500 );
		$this->assertSame( 'moderate', $eagerness2, 'Exactly 3500 should be moderate, not eager' );
	}

	/**
	 * Test eagerness is moderate when avg LCP > 2500 and <=3500.
	 */
	public function test_eagerness_is_moderate_above_2500(): void {
		$eagerness = $this->eagerness_for_avg_lcp( 3000 );
		$this->assertSame( 'moderate', $eagerness );
		$eagerness2 = $this->eagerness_for_avg_lcp( 2600 );
		$this->assertSame( 'moderate', $eagerness2 );
		// Boundary: exactly 2500 should be conservative.
		$this->transients = array();
		$eagerness3 = $this->eagerness_for_avg_lcp( 2500 );
		$this->assertSame( 'conservative', $eagerness3, 'Exactly 2500 should be conservative' );
	}

	/**
	 * Test eagerness is conservative when avg LCP <= 2500 or no data.
	 */
	public function test_eagerness_is_conservative_otherwise(): void {
		$eagerness = $this->eagerness_for_avg_lcp( 2000 );
		$this->assertSame( 'conservative', $eagerness );
		$eagerness2 = $this->eagerness_for_avg_lcp( 1000 );
		$this->assertSame( 'conservative', $eagerness2 );

		// No RUM data at all -> conservative.
		$this->install_stubs();
		$this->options['wppo_web_vitals_rum']    = array();
		$this->options['wppo_web_vitals_trends'] = array();
		$this->options['wppo_settings']          = array( 'ai_adaptive' => array( 'enabled' => true ) );
		Util::clear_settings_cache();
		$this->transients = array();
		$model = AI_Adaptive::learn();
		$this->assertSame( 'conservative', $model['eagerness'] );
	}

	/**
	 * Test that filter can override eagerness and invalid filter value falls back to conservative.
	 */
	public function test_eagerness_filter_sanitizes_invalid_value(): void {
		$this->install_stubs();
		$today = gmdate( 'Y-m-d' );
		$this->options['wppo_web_vitals_rum'] = array(
			$today => array(
				'/a/' => array(
					'lcp' => array(
						'n'   => 2,
						'sum' => 7200,
						'min' => 3600,
						'max' => 3600,
					),
				),
			),
		);
		$this->options['wppo_web_vitals_trends'] = array();
		$this->options['wppo_settings']          = array( 'ai_adaptive' => array( 'enabled' => true ) );
		Util::clear_settings_cache();
		$this->transients = array();
		// Override filter to return invalid value.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wppo_ai_adaptive_eagerness' === $hook ) {
					return 'invalid_eagerness';
				}
				return $value;
			}
		);
		$model = AI_Adaptive::learn();
		$this->assertSame( 'conservative', $model['eagerness'], 'Invalid filtered eagerness must fall back to conservative' );
	}
}
