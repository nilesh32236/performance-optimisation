<?php
/**
 * Tests for issue #1215: single-block speculation-rules dedup against WP 6.8 core.
 *
 * Covers Main::add_speculation_rules() core deferral (6.8+ registers the core
 * filters and prints no block of its own; pre-6.8 is a fail-open no-op with no
 * legacy block per the Option A maintainer decision), the conservative
 * prefetch default with opt-in prerender guardrails, and the exclusion matrix
 * (cart, checkout, nonce URLs, logged-in visitors, plain permalinks).
 *
 * @package PerformanceOptimise\Tests
 * @since 2.2.0
 */

use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests the WP 6.8 core-parity speculation-rules dedup.
 *
 * @package PerformanceOptimise\Tests
 * @since 2.2.0
 */
class MainSpeculationDedupTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options store.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Recorded add_filter() hooks.
	 *
	 * @var string[]
	 */
	private $added_filters = array();

	/**
	 * Previous $GLOBALS['wp_version'] value for restoration.
	 *
	 * @var mixed
	 */
	private $previous_wp_version;

	/**
	 * Whether $GLOBALS['wp_version'] existed before the test.
	 *
	 * @var bool
	 */
	private $had_wp_version = false;

	/**
	 * Set up Brain Monkey, common stubs, and the 6.8 API polyfill.
	 *
	 * @return void
	 */
	protected function setUp(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		\PerformanceOptimise\Inc\Util::reset_runtime_caches();
		\PerformanceOptimise\Inc\RUM::clear_field_lcp_cache();
		\PerformanceOptimise\Inc\AI_Adaptive::reset_model_memo();
		Main::reset_speculation_url_memo();

		$this->had_wp_version      = array_key_exists( 'wp_version', $GLOBALS );
		$this->previous_wp_version = $GLOBALS['wp_version'] ?? null;

		// Declare the WP 6.8 speculation entry points once per process so the
		// function_exists() half of the add_speculation_rules() guard passes;
		// the pre-6.8 test below exercises the version_compare() half via
		// $GLOBALS['wp_version']. No other test file calls
		// add_speculation_rules(), so the process-wide declaration is safe.
		if ( ! function_exists( 'wp_get_speculation_rules' ) ) {
			eval( 'function wp_get_speculation_rules() { return array(); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}
		if ( ! function_exists( 'wp_get_speculation_rules_configuration' ) ) {
			eval( 'function wp_get_speculation_rules_configuration() { return array(); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}

		$this->added_filters = array();
		$recorded            = &$this->added_filters;
		Functions\when( 'add_filter' )->alias(
			static function ( $hook, $callback = null, $priority = 10, $args = 1 ) use ( &$recorded ) {
				unset( $callback, $priority, $args );
				$recorded[] = (string) $hook;
				return true;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wppo_ai_adaptive_commerce_context' === $hook ) {
					return false;
				}
				return $value;
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $url ) {
				return rtrim( (string) $url, '/' );
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
	}

	/**
	 * Restore $GLOBALS['wp_version'] and tear down Brain Monkey.
	 *
	 * @return void
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		if ( $this->had_wp_version ) {
			$GLOBALS['wp_version'] = $this->previous_wp_version;
		} else {
			unset( $GLOBALS['wp_version'] );
		}
		\Brain\Monkey\tearDown();
		if ( class_exists( 'PerformanceOptimise\Inc\Main' ) ) {
			\PerformanceOptimise\Inc\Main::reset_instance();
		}
		parent::tearDown();
	}

	/**
	 * Build a Main instance with the given preload_settings.
	 *
	 * @param array $preload_settings preload_settings option value.
	 * @return Main
	 */
	private function make_main( array $preload_settings ): Main {
		$reflection = new \ReflectionClass( Main::class );
		$main       = $reflection->newInstanceWithoutConstructor();

		$options = $reflection->getProperty( 'options' );
		$options->setValue(
			$main,
			array(
				'preload_settings' => $preload_settings,
				'cache_settings'   => array( 'enableCache' => true ),
			)
		);

		return $main;
	}

	/**
	 * Invoke the private is_speculation_list_url_valid().
	 *
	 * @param Main   $main Main instance.
	 * @param string $url  Candidate URL.
	 * @return bool
	 */
	private function is_url_valid( Main $main, string $url ): bool {
		$reflection = new \ReflectionClass( Main::class );
		$method     = $reflection->getMethod( 'is_speculation_list_url_valid' );
		return (bool) $method->invoke( $main, $url );
	}

	/**
	 * On WP 6.8+ the plugin defers to core: it registers the core speculation
	 * filters and prints no block of its own (single merged block).
	 *
	 * @return void
	 */
	public function test_registers_core_filters_on_wp_68_without_printing_block(): void {
		$GLOBALS['wp_version'] = '6.8';
		$this->options         = array(
			'wppo_settings'       => array(),
			'permalink_structure' => '/%postname%/',
		);
		$main                  = $this->make_main( array( 'enableSpeculationRules' => true ) );

		ob_start();
		$main->add_speculation_rules();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
		$this->assertContains( 'wp_speculation_rules_configuration', $this->added_filters );
		$this->assertContains( 'wp_speculation_rules_href_exclude_paths', $this->added_filters );
		$this->assertContains( 'wp_speculation_rules', $this->added_filters );
	}

	/**
	 * On pre-6.8 the plugin registers nothing (fail-open no-op, Option A: no
	 * legacy plugin-owned block is reintroduced).
	 *
	 * @return void
	 */
	public function test_pre_68_registers_no_filters(): void {
		$GLOBALS['wp_version'] = '6.7';
		$this->options         = array(
			'wppo_settings'       => array(),
			'permalink_structure' => '/%postname%/',
		);
		$main                  = $this->make_main( array( 'enableSpeculationRules' => true ) );

		ob_start();
		$main->add_speculation_rules();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
		$this->assertSame( array(), $this->added_filters );
	}

	/**
	 * Defaults stay prefetch conservative unless the user opts into prerender.
	 *
	 * @return void
	 */
	public function test_defaults_stay_prefetch_conservative(): void {
		$GLOBALS['wp_version'] = '6.8';
		$main                  = $this->make_main( array( 'enableSpeculationRules' => true ) );

		$result = $main->filter_speculation_rules_configuration(
			array(
				'mode'      => 'auto',
				'eagerness' => 'auto',
			),
			array( 'enableSpeculationRules' => true ),
			true
		);

		$this->assertSame( 'prefetch', $result['mode'] );
		$this->assertSame( 'conservative', $result['eagerness'] );
	}

	/**
	 * Opt-in prerender without qualifying field data degrades to conservative
	 * prefetch (eager only where safe, conservative everywhere else).
	 *
	 * @return void
	 */
	public function test_prerender_opt_in_degrades_without_qualified_rum(): void {
		$GLOBALS['wp_version'] = '6.8';
		$this->options         = array(
			'wppo_settings'       => array(),
			'wppo_web_vitals_rum' => array(),
			'permalink_structure' => '/%postname%/',
		);
		Util::clear_settings_cache();
		$main = $this->make_main(
			array(
				'enableSpeculationRules' => true,
				'speculationMode'        => 'prerender',
				'speculationEagerness'   => 'moderate',
			)
		);

		$result = $main->filter_speculation_rules_configuration(
			array(
				'mode'      => 'auto',
				'eagerness' => 'auto',
			),
			array(
				'enableSpeculationRules' => true,
				'speculationMode'        => 'prerender',
				'speculationEagerness'   => 'moderate',
			),
			true
		);

		$this->assertSame( 'prefetch', $result['mode'] );
		$this->assertSame( 'conservative', $result['eagerness'] );
	}

	/**
	 * Exclusion matrix: cart, checkout, and nonce-bearing URLs are rejected,
	 * logged-in visitors get a null configuration, and plain permalinks emit
	 * no list URLs.
	 *
	 * @return void
	 */
	public function test_exclusion_matrix(): void {
		$GLOBALS['wp_version'] = '6.8';
		$this->options         = array(
			'wppo_settings'       => array(
				'preload_settings'  => array( 'enableSpeculationRules' => true ),
				'performance_audit' => array(
					'high_value_urls' => array(
						'http://example.com/cart/',
						'http://example.com/checkout/',
					),
				),
			),
			'permalink_structure' => '/%postname%/',
		);
		Util::clear_settings_cache();
		$main = $this->make_main( array( 'enableSpeculationRules' => true ) );

		$this->assertFalse( $this->is_url_valid( $main, 'http://example.com/cart/' ) );
		$this->assertFalse( $this->is_url_valid( $main, 'http://example.com/checkout/' ) );
		$this->assertFalse( $this->is_url_valid( $main, 'http://example.com/post/?_wpnonce=abc123' ) );
		$this->assertFalse( $this->is_url_valid( $main, 'http://example.com/nonce-cleanup/' ) );

		$urls = $main->get_speculation_list_urls();
		$this->assertNotContains( 'http://example.com/cart/', $urls );
		$this->assertNotContains( 'http://example.com/checkout/', $urls );

		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$this->assertNull(
			$main->filter_speculation_rules_configuration(
				array(
					'mode'      => 'auto',
					'eagerness' => 'auto',
				),
				array( 'enableSpeculationRules' => true ),
				true
			)
		);

		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$this->options['permalink_structure'] = '';
		Util::clear_settings_cache();
		$this->assertSame( array(), $main->get_speculation_list_urls() );
	}
}
