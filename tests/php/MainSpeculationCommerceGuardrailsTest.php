<?php
/**
 * Tests for the speculation-rules commerce guardrails entry point (issue #1343).
 *
 * Covers Main::filter_speculation_exclude_commerce(): cart/checkout request
 * exclusion, add-to-cart/admin/preview/nocache exclusion, RUM-gated
 * prerender downgrade to conservative prefetch, and no-double-emit
 * passthrough when core disables speculation (non-array config).
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */

use PerformanceOptimise\Inc\AI_Adaptive;
use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\RUM;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests the speculation-rules commerce guardrail filter.
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */
class MainSpeculationCommerceGuardrailsTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options store.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Previous $_SERVER snapshot for restoration.
	 *
	 * @var array
	 */
	private $previous_server = array();

	/**
	 * Register WP stubs backed by the in-memory options store.
	 *
	 * Note: this setUp() shadows the trait method (as in
	 * SpeculationPrerenderListTest), so Brain Monkey setup, common stubs,
	 * and memo resets are registered explicitly here.
	 *
	 * @return void
	 */
	protected function setUp(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		Util::reset_runtime_caches();
		RUM::clear_field_lcp_cache();
		AI_Adaptive::reset_model_memo();
		Main::reset_speculation_url_memo();
		$this->previous_server = $_SERVER;
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
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'is_preview' )->justReturn( false );
		Functions\when( 'is_customize_preview' )->justReturn( false );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
	}

	/**
	 * Restore $_SERVER and tear down Brain Monkey.
	 *
	 * @return void
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$_SERVER = $this->previous_server; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test restoration only.
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
	 * Install the wppo_settings + RUM fixtures.
	 *
	 * @param array $preload_settings preload_settings for wppo_settings.
	 * @return void
	 */
	private function install_settings( array $preload_settings ): void {
		$this->options = array(
			'wppo_settings'       => array(
				'preload_settings' => $preload_settings,
			),
			'wppo_web_vitals_rum' => array(),
			'permalink_structure' => '/%postname%/',
		);
		Util::clear_settings_cache();
	}

	/**
	 * Request-URI fixture without query-string side effects.
	 *
	 * @param string $uri     Request URI value.
	 * @param string $query   Query string value.
	 * @return void
	 */
	private function set_request( string $uri, string $query = '' ): void {
		$_SERVER['REQUEST_URI']  = $uri; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test fixture only.
		$_SERVER['QUERY_STRING'] = $query; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test fixture only.
	}

	/**
	 * A non-array config (core disabled speculation) passes through untouched:
	 * the plugin never re-enables speculation and never double-emits.
	 *
	 * @return void
	 */
	public function test_non_array_config_passes_through_untouched(): void {
		$this->install_settings( array( 'enableSpeculationRules' => true ) );
		$this->set_request( '/' );
		$main = $this->make_main( array( 'enableSpeculationRules' => true ) );

		$this->assertNull( $main->filter_speculation_exclude_commerce( null, array( 'enableSpeculationRules' => true ), true ) );
		$this->assertSame( 'nope', $main->filter_speculation_exclude_commerce( 'nope', array( 'enableSpeculationRules' => true ), true ) );
	}

	/**
	 * Cart conditional context suppresses speculation via a null configuration.
	 *
	 * @return void
	 */
	public function test_cart_context_returns_null(): void {
		$this->install_settings( array( 'enableSpeculationRules' => true ) );
		$this->set_request( '/' );
		Functions\when( 'is_cart' )->justReturn( true );
		$main = $this->make_main( array( 'enableSpeculationRules' => true ) );

		$this->assertNull(
			$main->filter_speculation_exclude_commerce(
				array(
					'mode'      => 'auto',
					'eagerness' => 'auto',
				),
				array( 'enableSpeculationRules' => true ),
				true
			)
		);
	}

	/**
	 * Add-to-cart query endpoints are excluded from speculation.
	 *
	 * @return void
	 */
	public function test_add_to_cart_query_returns_null(): void {
		$this->install_settings( array( 'enableSpeculationRules' => true ) );
		$this->set_request( '/?add-to-cart=123', 'add-to-cart=123' );
		$main = $this->make_main( array( 'enableSpeculationRules' => true ) );

		$this->assertNull(
			$main->filter_speculation_exclude_commerce(
				array(
					'mode'      => 'prefetch',
					'eagerness' => 'conservative',
				),
				array( 'enableSpeculationRules' => true ),
				true
			)
		);
	}

	/**
	 * Checkout request-URI paths are excluded even without conditional tags.
	 *
	 * @return void
	 */
	public function test_checkout_request_uri_returns_null(): void {
		$this->install_settings( array( 'enableSpeculationRules' => true ) );
		$this->set_request( '/checkout/' );
		$main = $this->make_main( array( 'enableSpeculationRules' => true ) );

		$this->assertNull(
			$main->filter_speculation_exclude_commerce(
				array(
					'mode'      => 'prefetch',
					'eagerness' => 'conservative',
				),
				array( 'enableSpeculationRules' => true ),
				true
			)
		);
	}

	/**
	 * Admin requests never receive speculation rules.
	 *
	 * @return void
	 */
	public function test_admin_request_returns_null(): void {
		$this->install_settings( array( 'enableSpeculationRules' => true ) );
		$this->set_request( '/' );
		Functions\when( 'is_admin' )->justReturn( true );
		$main = $this->make_main( array( 'enableSpeculationRules' => true ) );

		$this->assertNull(
			$main->filter_speculation_exclude_commerce(
				array(
					'mode'      => 'auto',
					'eagerness' => 'auto',
				),
				array( 'enableSpeculationRules' => true ),
				true
			)
		);
	}

	/**
	 * Preview contexts never receive speculation rules.
	 *
	 * @return void
	 */
	public function test_preview_request_returns_null(): void {
		$this->install_settings( array( 'enableSpeculationRules' => true ) );
		$this->set_request( '/?preview=true', 'preview=true' );
		Functions\when( 'is_preview' )->justReturn( true );
		$main = $this->make_main( array( 'enableSpeculationRules' => true ) );

		$this->assertNull(
			$main->filter_speculation_exclude_commerce(
				array(
					'mode'      => 'auto',
					'eagerness' => 'auto',
				),
				array( 'enableSpeculationRules' => true ),
				true
			)
		);
	}

	/**
	 * RUM-negative contexts downgrade prerender to conservative prefetch.
	 *
	 * @return void
	 */
	public function test_rum_negative_downgrades_prerender_to_prefetch(): void {
		$preload = array(
			'enableSpeculationRules' => true,
			'speculationMode'        => 'prerender',
			'speculationEagerness'   => 'moderate',
			'speculationRumGating'   => true,
		);
		$this->install_settings( $preload );
		$this->set_request( '/' );
		$main = $this->make_main( $preload );

		$result = $main->filter_speculation_exclude_commerce(
			array(
				'mode'      => 'auto',
				'eagerness' => 'auto',
			),
			$preload,
			true
		);

		$this->assertSame( 'prefetch', $result['mode'] );
		$this->assertSame( 'conservative', $result['eagerness'] );
	}

	/**
	 * With RUM gating disabled the user's prerender choice is honored.
	 *
	 * @return void
	 */
	public function test_rum_gating_disabled_honors_prerender_choice(): void {
		$preload = array(
			'enableSpeculationRules' => true,
			'speculationMode'        => 'prerender',
			'speculationEagerness'   => 'moderate',
			'speculationRumGating'   => false,
		);
		$this->install_settings( $preload );
		$this->set_request( '/' );
		$main = $this->make_main( $preload );

		$result = $main->filter_speculation_exclude_commerce(
			array(
				'mode'      => 'auto',
				'eagerness' => 'auto',
			),
			$preload,
			true
		);

		$this->assertSame( 'prerender', $result['mode'] );
		$this->assertSame( 'moderate', $result['eagerness'] );
	}

	/**
	 * A safe frontend request with prefetch settings passes configuration through.
	 *
	 * @return void
	 */
	public function test_safe_prefetch_request_applies_user_configuration(): void {
		$preload = array(
			'enableSpeculationRules' => true,
			'speculationMode'        => 'prefetch',
			'speculationEagerness'   => 'moderate',
		);
		$this->install_settings( $preload );
		$this->set_request( '/hello-world/' );
		$main = $this->make_main( $preload );

		$result = $main->filter_speculation_exclude_commerce(
			array(
				'mode'      => 'auto',
				'eagerness' => 'auto',
			),
			$preload,
			true
		);

		$this->assertSame(
			array(
				'mode'      => 'prefetch',
				'eagerness' => 'moderate',
			),
			$result
		);
	}
}
