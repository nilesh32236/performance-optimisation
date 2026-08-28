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
	 * @var array
	 */
	private $options = array();

	/**
	 * @var array
	 */
	private $transients = array();

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

	public function test_is_enabled_false_by_default(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array( 'ai_adaptive' => array( 'enabled' => false ) );
		Util::clear_settings_cache();
		$this->assertFalse( AI_Adaptive::is_enabled() );
	}

	public function test_is_enabled_true_when_setting_set(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array( 'ai_adaptive' => array( 'enabled' => true ) );
		Util::clear_settings_cache();
		// apply_filters returns true by stub.
		$this->assertTrue( AI_Adaptive::is_enabled() );
	}

	public function test_learn_heuristic_produces_model(): void {
		$this->install_stubs();
		$today                          = gmdate( 'Y-m-d' );
		$this->options['wppo_web_vitals_rum'] = array(
			$today => array(
				'/slow/' => array(
					'lcp'  => array( 'n' => 5, 'sum' => 20000, 'min' => 3000, 'max' => 5000 ),
					'ttfb' => array( 'n' => 5, 'sum' => 4000, 'min' => 700, 'max' => 900 ),
				),
				'/fast/' => array(
					'lcp' => array( 'n' => 1, 'sum' => 1000, 'min' => 1000, 'max' => 1000 ),
				),
			),
		);
		$this->options['wppo_web_vitals_trends'] = array();
		$this->options['wppo_settings']           = array( 'ai_adaptive' => array( 'enabled' => true ) );
		Util::clear_settings_cache();

		$model = AI_Adaptive::learn();
		$this->assertIsArray( $model );
		$this->assertSame( 'heuristic', $model['source'] );
		$this->assertNotEmpty( $model['prefetch_urls'] );
		$this->assertContains( 'http://example.com/slow/', $model['prefetch_urls'] );
	}

	public function test_get_prefetch_urls_returns_top_two(): void {
		$this->install_stubs();
		$this->options[ AI_Adaptive::OPTION ] = array(
			'prefetch_urls' => array( 'http://example.com/a/', 'http://example.com/b/', 'http://example.com/c/' ),
			'eagerness'     => 'conservative',
		);
		$urls = AI_Adaptive::get_prefetch_urls();
		$this->assertCount( 2, $urls );
		$this->assertSame( 'http://example.com/a/', $urls[0] );
	}

	public function test_filter_speculation_rules_respects_disabled(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array( 'ai_adaptive' => array( 'enabled' => false ) );
		$this->options[ AI_Adaptive::OPTION ] = array(
			'prefetch_urls' => array( 'http://example.com/a/' ),
			'eagerness'     => 'conservative',
		);
		Util::clear_settings_cache();
		$rules = AI_Adaptive::filter_speculation_rules( array() );
		$this->assertSame( array(), $rules );
	}
}
