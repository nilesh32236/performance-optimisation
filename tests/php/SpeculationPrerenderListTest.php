<?php
/**
 * Tests for the opt-in high-value prerender list (issue #1237 follow-up).
 *
 * Covers Main::wppo_register_speculation_rules() guards (toggle off,
 * logged-in visitor, commerce context, unqualified prerender gate),
 * post-filter re-validation + limit re-slicing, rule-shape enforcement,
 * normalization-aware dedupe, the object-path idempotency guard, and the
 * pinned boolean sanitizer for speculationPrerenderList.
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
 * Tests the guarded high-value prerender list rule.
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */
class SpeculationPrerenderListTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options store.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Per-test apply_filters overrides keyed by hook name.
	 *
	 * @var array<string, mixed>
	 */
	private $filter_overrides = array();

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
	 * Register WP stubs backed by the in-memory options store.
	 *
	 * Note: this setUp() shadows the trait method (as in
	 * MainSpeculationDedupTest), so Brain Monkey setup, common stubs,
	 * and memo resets are registered explicitly here.
	 *
	 * @return void
	 */
	protected function setUp(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		\PerformanceOptimise\Inc\Util::reset_runtime_caches();
		RUM::clear_field_lcp_cache();
		AI_Adaptive::reset_model_memo();
		Main::reset_speculation_url_memo();
		$this->had_wp_version      = array_key_exists( 'wp_version', $GLOBALS );
		$this->previous_wp_version = $GLOBALS['wp_version'] ?? null;
		$this->filter_overrides = array();
		$overrides              = &$this->filter_overrides;
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) use ( &$overrides ) {
				$hook = (string) $hook;
				if ( array_key_exists( $hook, $overrides ) ) {
					$override = $overrides[ $hook ];
					return is_callable( $override ) ? call_user_func( $override, $value ) : $override;
				}
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
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		if ( ! function_exists( 'wp_get_speculation_rules' ) ) {
			eval( 'function wp_get_speculation_rules() { return array(); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}
		if ( ! function_exists( 'wp_get_speculation_rules_configuration' ) ) {
			eval( 'function wp_get_speculation_rules_configuration() { return array(); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}
		$GLOBALS['wp_version'] = '6.8';
	}

	/**
	 * Restore $GLOBALS['wp_version'].
	 *
	 * @return void
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		if ( $this->had_wp_version ) {
			$GLOBALS['wp_version'] = $this->previous_wp_version;
		} else {
			unset( $GLOBALS['wp_version'] );
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
		$options->setAccessible( true );
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
	 * Install the wppo_settings + permalink fixtures.
	 *
	 * Disables RUM gating so is_prerender_allowed() passes on the static
	 * cache alone; tests needing the gate override the fixture.
	 *
	 * @param array $preload_settings preload_settings for wppo_settings.
	 * @return void
	 */
	private function install_settings( array $preload_settings ): void {
		$this->options = array(
			'wppo_settings'       => array(
				'preload_settings' => array_merge(
					array( 'speculationRumGating' => false ),
					$preload_settings
				),
			),
			'wppo_web_vitals_rum' => array(),
			'permalink_structure' => '/%postname%/',
		);
		Util::clear_settings_cache();
	}

	/**
	 * Prerender-enabled preload settings.
	 *
	 * @return array
	 */
	private function prerender_on(): array {
		return array(
			'enableSpeculationRules' => true,
			'speculationPrerenderList' => true,
			'speculationTopUrlsLimit' => 2,
		);
	}

	/**
	 * Toggle off returns every input unchanged (array, object, scalar).
	 *
	 * @return void
	 */
	public function test_toggle_off_returns_input_unchanged(): void {
		$this->install_settings( array( 'enableSpeculationRules' => true ) );
		$main = $this->make_main( array( 'enableSpeculationRules' => true ) );

		$this->assertSame( array(), $main->wppo_register_speculation_rules( array() ) );
		$rules = array( array( 'source' => 'list', 'urls' => array( 'http://example.com/a/' ), 'eagerness' => 'conservative' ) );
		$this->assertSame( $rules, $main->wppo_register_speculation_rules( $rules ) );
		$this->assertSame( 'nope', $main->wppo_register_speculation_rules( 'nope' ) );
		$this->assertNull( $main->wppo_register_speculation_rules( null ) );
	}

	/**
	 * Logged-in visitors never receive the prerender rule.
	 *
	 * @return void
	 */
	public function test_logged_in_visitor_gets_no_prerender_rule(): void {
		$this->install_settings( $this->prerender_on() );
		$main = $this->make_main( $this->prerender_on() );

		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$rules = array( array( 'source' => 'list', 'urls' => array( 'http://example.com/a/' ), 'eagerness' => 'conservative' ) );
		$this->assertSame( $rules, $main->wppo_register_speculation_rules( $rules, array( 'http://example.com/b/' ) ) );
	}

	/**
	 * Commerce contexts never receive the prerender rule (fail-closed).
	 *
	 * @return void
	 */
	public function test_commerce_context_gets_no_prerender_rule(): void {
		$this->install_settings( $this->prerender_on() );
		$main = $this->make_main( $this->prerender_on() );

		$this->filter_overrides['wppo_ai_adaptive_commerce_context'] = true;
		$this->assertSame( array(), $main->wppo_register_speculation_rules( array(), array( 'http://example.com/b/' ) ) );
	}

	/**
	 * Unqualified prerender gate (RUM gating on, no qualifying data) yields nothing.
	 *
	 * @return void
	 */
	public function test_unqualified_prerender_gate_yields_nothing(): void {
		$this->options = array(
			'wppo_settings'       => array(
				'preload_settings' => array( 'speculationRumGating' => true ),
			),
			'wppo_web_vitals_rum' => array(),
			'permalink_structure' => '/%postname%/',
		);
		Util::clear_settings_cache();
		$main = $this->make_main( $this->prerender_on() );

		$this->assertSame( array(), $main->wppo_register_speculation_rules( array(), array( 'http://example.com/b/' ) ) );
	}

	/**
	 * Legacy path emits a moderate list rule with safe URLs only.
	 *
	 * @return void
	 */
	public function test_legacy_path_emits_guarded_rule(): void {
		$this->install_settings( $this->prerender_on() );
		$main = $this->make_main( $this->prerender_on() );

		$result = $main->wppo_register_speculation_rules(
			array(),
			array(
				'http://example.com/good-post/',
				'http://example.com/cart/',
				'https://evil.example.net/good-post/',
				'http://example.com/good-post/?x=1',
			)
		);

		$this->assertCount( 1, $result );
		$this->assertSame( 'list', $result[0]['source'] );
		$this->assertSame( 'moderate', $result[0]['eagerness'] );
		$this->assertSame( array( 'http://example.com/good-post/' ), $result[0]['urls'] );
	}

	/**
	 * Filter-injected unsafe URLs are re-validated away on both URL filters.
	 *
	 * @return void
	 */
	public function test_filter_injected_unsafe_urls_are_dropped(): void {
		$this->install_settings( $this->prerender_on() );
		$main = $this->make_main( $this->prerender_on() );

		$this->filter_overrides['wppo_speculation_prerender_list_urls'] = array(
			'http://example.com/good-post/',
			'http://example.com/cart/',
			'https://evil.example.net/other/',
			'http://example.com/with-query/?x=1',
		);
		$result = $main->wppo_register_speculation_rules( array(), array( 'http://example.com/seed/' ) );

		$this->assertCount( 1, $result );
		$this->assertSame( array( 'http://example.com/good-post/' ), $result[0]['urls'] );
	}

	/**
	 * Post-filter URL output is re-sliced to the top-URL limit.
	 *
	 * @return void
	 */
	public function test_post_filter_urls_resliced_to_limit(): void {
		$this->install_settings( $this->prerender_on() );
		$main = $this->make_main( $this->prerender_on() );

		$this->filter_overrides['wppo_speculation_prerender_list_urls'] = array(
			'http://example.com/a/',
			'http://example.com/b/',
			'http://example.com/c/',
			'http://example.com/d/',
			'http://example.com/e/',
			'http://example.com/f/',
		);
		$result = $main->wppo_register_speculation_rules( array(), array( 'http://example.com/seed/' ) );

		$this->assertCount( 1, $result );
		$this->assertSame( array( 'http://example.com/a/', 'http://example.com/b/' ), $result[0]['urls'] );
	}

	/**
	 * A rule filter morphing source away from list drops the rule.
	 *
	 * @return void
	 */
	public function test_rule_filter_wrong_source_drops_rule(): void {
		$this->install_settings( $this->prerender_on() );
		$main = $this->make_main( $this->prerender_on() );

		$this->filter_overrides['wppo_speculation_prerender_list_rule'] = static function ( $rule ) {
			$rule['source'] = 'document';
			return $rule;
		};
		$this->assertSame( array(), $main->wppo_register_speculation_rules( array(), array( 'http://example.com/seed/' ) ) );
	}

	/**
	 * A rule filter injecting unsafe URLs into the rule is re-validated.
	 *
	 * @return void
	 */
	public function test_rule_filter_unsafe_urls_are_revalidated(): void {
		$this->install_settings( $this->prerender_on() );
		$main = $this->make_main( $this->prerender_on() );

		$this->filter_overrides['wppo_speculation_prerender_list_rule'] = static function ( $rule ) {
			$rule['urls'] = array( 'http://example.com/cart/', 'https://evil.example.net/x/' );
			return $rule;
		};
		$this->assertSame( array(), $main->wppo_register_speculation_rules( array(), array( 'http://example.com/seed/' ) ) );
	}

	/**
	 * Trailing-slash variants dedupe against pre-existing list rules.
	 *
	 * @return void
	 */
	public function test_trailing_slash_variant_dedupes(): void {
		$this->install_settings( $this->prerender_on() );
		$main = $this->make_main( $this->prerender_on() );

		$existing = array(
			array(
				'source'    => 'list',
				'urls'      => array( 'http://example.com/post/' ),
				'eagerness' => 'conservative',
			),
		);
		$result   = $main->wppo_register_speculation_rules( $existing, array( 'http://example.com/post' ) );

		$this->assertSame( $existing, $result );
	}

	/**
	 * Same-host different-port and userinfo URLs are rejected.
	 *
	 * @return void
	 */
	public function test_port_and_userinfo_urls_rejected(): void {
		$this->install_settings( $this->prerender_on() );
		$main = $this->make_main( $this->prerender_on() );

		$reflection = new \ReflectionClass( Main::class );
		$method     = $reflection->getMethod( 'is_speculation_list_url_valid' );
		$method->setAccessible( true );

		$this->assertTrue( (bool) $method->invoke( $main, 'http://example.com/post/' ) );
		$this->assertFalse( (bool) $method->invoke( $main, 'http://example.com:8080/post/' ) );
		$this->assertFalse( (bool) $method->invoke( $main, 'http://user:pass@example.com/post/' ) );
	}

	/**
	 * Object path adds the rule exactly once across repeated calls.
	 *
	 * @return void
	 */
	public function test_object_path_adds_rule_once(): void {
		$this->install_settings( $this->prerender_on() );
		$main = $this->make_main( $this->prerender_on() );

		$fake = new class() {
			/**
			 * Added rules.
			 *
			 * @var array
			 */
			public $added = array();

			/**
			 * Record an added rule.
			 *
			 * @param string $type  Rule type.
			 * @param string $id    Rule id.
			 * @param array  $args  Rule arguments.
			 * @return void
			 */
			public function add_rule( $type, $id, $args ) {
				$this->added[] = array( $type, $id, $args );
			}
		};

		$main->wppo_register_speculation_rules( $fake, array( 'http://example.com/seed/' ) );
		$main->wppo_register_speculation_rules( $fake, array( 'http://example.com/seed/' ) );

		$this->assertCount( 1, $fake->added );
		$this->assertSame( 'prerender', $fake->added[0][0] );
		$this->assertSame( 'wppo-high-value-prerender', $fake->added[0][1] );
		$this->assertSame( 'moderate', $fake->added[0][2]['eagerness'] );
	}

	/**
	 * String 'false' for speculationPrerenderList sanitizes to bool false.
	 *
	 * @return void
	 */
	public function test_prerender_toggle_string_false_sanitizes_to_false(): void {
		$sanitized = Util::sanitize_settings_recursively(
			array(
				'preload_settings' => array(
					'speculationPrerenderList' => 'false',
				),
			)
		);

		$this->assertFalse( $sanitized['preload_settings']['speculationPrerenderList'] );
	}
}
