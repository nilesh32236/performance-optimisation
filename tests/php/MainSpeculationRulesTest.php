<?php
/**
 * Tests for Main::filter_speculation_rules_configuration() reconciling the
 * plugin's speculation-rules handling with the WP 7.1 moderate default.
 *
 * WordPress 7.1 escalates the default eagerness from `conservative` to
 * `moderate` when caching is detected (#64066). The plugin pins an explicit
 * eagerness whenever it owns the speculation-rules decision so that
 * escalation cannot override the plugin UI, while still honoring the
 * `WP_SPECULATIVE_LOADING_DEFAULT_*` constant/env escape hatch (#65624).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;
use Brain\Monkey\Functions;

/**
 * Tests the wp_speculation_rules_configuration filter callback.
 *
 * @package PerformanceOptimise\Tests
 */
class MainSpeculationRulesTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Build a Main instance (skipping the full constructor) with the given
	 * preload_settings so filter_speculation_rules_configuration() can be
	 * exercised in isolation.
	 *
	 * @param array $preload_settings preload_settings option value.
	 * @param bool  $cache_enabled    Whether the plugin's static cache is active.
	 * @return Main
	 */
	private function make_main( array $preload_settings, bool $cache_enabled = true ): Main {
		$reflection = new \ReflectionClass( Main::class );
		$main       = $reflection->newInstanceWithoutConstructor();

		$options = $reflection->getProperty( 'options' );
		$options->setValue(
			$main,
			array(
				'preload_settings' => $preload_settings,
				'cache_settings'   => array( 'enableCache' => $cache_enabled ),
			)
		);

		return $main;
	}

	/**
	 * Clear any WP_SPECULATIVE_LOADING_DEFAULT_* environment variables set by a test.
	 *
	 * PHP constants cannot be unset once defined, so if the test bootstrap or a
	 * host defines WP_SPECULATIVE_LOADING_DEFAULT_MODE/EAGERNESS those tests
	 * that assert "no override" would see one. This suite assumes no such
	 * constants are defined by the test environment (they are never defined in
	 * this file), which keeps the assertions deterministic.
	 */
	private function clear_override_env(): void {
		putenv( 'WP_SPECULATIVE_LOADING_DEFAULT_EAGERNESS' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		putenv( 'WP_SPECULATIVE_LOADING_DEFAULT_MODE' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
	}

	/**
	 * Clear overrides before each test so environment leaks cannot affect results.
	 *
	 * Also forces a deterministic non-commerce context: AiAdaptiveTest
	 * eval-declares Woo helpers process-wide (Brain Monkey cannot undeclare
	 * functions), which would otherwise cap eager to moderate site-wide for
	 * every later test file in the same process (issue #1183 guardrail).
	 */
	protected function setUp(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		\PerformanceOptimise\Inc\Util::reset_runtime_caches();
		\PerformanceOptimise\Inc\RUM::clear_field_lcp_cache();
		\PerformanceOptimise\Inc\AI_Adaptive::reset_model_memo();
		\PerformanceOptimise\Inc\Main::reset_speculation_url_memo();
		// Explicit visitor context: is_speculation_suppressed_for_visitor()
		// fails closed on throwable, so an unstubbed (or stale cross-file)
		// is_user_logged_in would suppress every configuration under test.
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wppo_ai_adaptive_commerce_context' === $hook ) {
					return false;
				}
				return $value;
			}
		);
		$this->clear_override_env();
	}

	/**
	 * Clear overrides after each test so environment leaks cannot affect other tests.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$this->clear_override_env();
		\Brain\Monkey\tearDown();
		if ( class_exists( 'PerformanceOptimise\Inc\Main' ) ) {
			\PerformanceOptimise\Inc\Main::reset_instance();
		}
		parent::tearDown();
	}

	/**
	 * Test that when the toggle is off and no host override exists, the plugin
	 * pins eagerness back to conservative so the WP 7.1 cached-site escalation
	 * cannot change behavior behind the user's back. Mode stays 'auto' so core
	 * keeps resolving its own (prefetch) default.
	 */
	public function test_toggle_off_pins_conservative_when_auto(): void {
		$main = $this->make_main(
			array(
				'enableSpeculationRules' => false,
			)
		);

		$result = $main->filter_speculation_rules_configuration(
			array(
				'mode'      => 'auto',
				'eagerness' => 'auto',
			),
			array( 'enableSpeculationRules' => false ),
			false
		);

		$this->assertSame(
			array(
				'mode'      => 'auto',
				'eagerness' => 'conservative',
			),
			$result
		);
	}

	/**
	 * Test that the conservative pin only applies while the plugin's static
	 * cache is active: on a site where the plugin's cache is off, core's
	 * cached-site escalation heuristic would not be triggered by this plugin,
	 * so the plugin leaves the 'auto' default to core.
	 */
	public function test_toggle_off_leaves_auto_when_cache_inactive(): void {
		$main = $this->make_main( array(), false );

		$result = $main->filter_speculation_rules_configuration(
			array(
				'mode'      => 'auto',
				'eagerness' => 'auto',
			),
			array( 'enableSpeculationRules' => false ),
			false
		);

		$this->assertSame( 'auto', $result['eagerness'] );
	}

	/**
	 * Test that an invalid eagerness override (one core would reject) is ignored
	 * so the conservative pin still applies, instead of silently permitting the
	 * cached-site escalation.
	 */
	public function test_toggle_off_ignores_invalid_eagerness_override(): void {
		putenv( 'WP_SPECULATIVE_LOADING_DEFAULT_EAGERNESS=immediate' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		$main = $this->make_main( array() );

		$result = $main->filter_speculation_rules_configuration(
			array(
				'mode'      => 'auto',
				'eagerness' => 'auto',
			),
			array( 'enableSpeculationRules' => false ),
			false
		);

		$this->assertSame( 'conservative', $result['eagerness'] );
	}

	/**
	 * Test that a mode-only override (no eagerness override) still lets the
	 * conservative eagerness pin apply: the mode pin does not govern eagerness.
	 */
	public function test_toggle_off_pins_conservative_with_mode_override(): void {
		putenv( 'WP_SPECULATIVE_LOADING_DEFAULT_MODE=prerender' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		$main = $this->make_main( array() );

		$result = $main->filter_speculation_rules_configuration(
			array(
				'mode'      => 'auto',
				'eagerness' => 'auto',
			),
			array( 'enableSpeculationRules' => false ),
			false
		);

		$this->assertSame( 'conservative', $result['eagerness'] );
	}

	/**
	 * Test that an invalid mode override is ignored, mirroring the eagerness
	 * validation.
	 */
	public function test_invalid_mode_override_ignored(): void {
		$main = $this->make_main( array() );

		$reflection = new \ReflectionClass( Main::class );
		$method     = $reflection->getMethod( 'get_speculation_default_override' );

		putenv( 'WP_SPECULATIVE_LOADING_DEFAULT_MODE=banana' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv

		$this->assertNull(
			$method->invoke( $main, 'WP_SPECULATIVE_LOADING_DEFAULT_MODE' )
		);
	}

	/**
	 * Test that a constant override wins over an environment variable, matching
	 * core's precedence. Uses a dedicated test constant so the real WordPress
	 * constants are never touched (PHP constants cannot be unset).
	 */
	public function test_constant_takes_precedence_over_environment(): void {
		$name = 'WPPO_TEST_SPECULATIVE_LOADING_DEFAULT_EAGERNESS';
		if ( ! defined( $name ) ) {
			define( $name, 'moderate' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		}

		putenv( $name . '=eager' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv

		$main = $this->make_main( array() );

		$reflection = new \ReflectionClass( Main::class );
		$method     = $reflection->getMethod( 'get_speculation_default_override' );

		$this->assertSame( 'moderate', $method->invoke( $main, $name ) );

		putenv( $name ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
	}

	/**
	 * Test that a non-auto eagerness supplied by another filter (or core) is
	 * left untouched when the toggle is off.
	 */
	public function test_toggle_off_leaves_explicit_eagerness_untouched(): void {
		$main = $this->make_main( array() );

		$result = $main->filter_speculation_rules_configuration(
			array(
				'mode'      => 'auto',
				'eagerness' => 'moderate',
			),
			array( 'enableSpeculationRules' => false ),
			false
		);

		$this->assertSame( 'moderate', $result['eagerness'] );
	}

	/**
	 * Test that an explicit WP_SPECULATIVE_LOADING_DEFAULT_EAGERNESS host
	 * override is honored: the plugin leaves 'auto' so core resolves to the
	 * pinned default instead of overriding it.
	 */
	public function test_toggle_off_respects_host_eagerness_override(): void {
		putenv( 'WP_SPECULATIVE_LOADING_DEFAULT_EAGERNESS=moderate' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		$main = $this->make_main( array() );

		$result = $main->filter_speculation_rules_configuration(
			array(
				'mode'      => 'auto',
				'eagerness' => 'auto',
			),
			array( 'enableSpeculationRules' => false ),
			false
		);

		$this->assertSame( 'auto', $result['eagerness'] );
	}

	/**
	 * Test that when the toggle is on, the user's chosen mode and eagerness are
	 * applied regardless of core defaults or host overrides.
	 *
	 * Runs in the deterministic non-commerce context forced by setUp(), so
	 * eager passes through untouched (issue #1183 guardrail only caps
	 * commerce/auth contexts).
	 */
	public function test_toggle_on_applies_user_configuration(): void {
		putenv( 'WP_SPECULATIVE_LOADING_DEFAULT_EAGERNESS=moderate' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		$main = $this->make_main( array() );

		$result = $main->filter_speculation_rules_configuration(
			array(
				'mode'      => 'auto',
				'eagerness' => 'auto',
			),
			array(
				'enableSpeculationRules' => true,
				'speculationMode'        => 'prefetch',
				'speculationEagerness'   => 'eager',
			),
			true
		);

		$this->assertSame(
			array(
				'mode'      => 'prefetch',
				'eagerness' => 'eager',
			),
			$result
		);
	}

	/**
	 * Test that the global configuration caps eager to moderate in
	 * commerce/auth contexts (issue #1183), matching the singular/archive/
	 * list rule paths.
	 *
	 * @return void
	 */
	public function test_toggle_on_caps_eager_in_commerce_context(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wppo_ai_adaptive_commerce_context' === $hook ) {
					return true;
				}
				return $value;
			}
		);
		$main = $this->make_main( array() );

		$result = $main->filter_speculation_rules_configuration(
			array(
				'mode'      => 'auto',
				'eagerness' => 'auto',
			),
			array(
				'enableSpeculationRules' => true,
				'speculationMode'        => 'prefetch',
				'speculationEagerness'   => 'eager',
			),
			true
		);

		$this->assertSame( 'prefetch', $result['mode'] );
		$this->assertSame( 'moderate', $result['eagerness'] );
	}

	/**
	 * Test that a null config (speculative loading disabled for the request,
	 * e.g. logged-in users) is returned untouched so the plugin never
	 * re-enables it.
	 */
	public function test_null_configuration_untouched(): void {
		$main = $this->make_main( array() );

		$this->assertNull(
			$main->filter_speculation_rules_configuration( null, array(), true )
		);
	}
}
