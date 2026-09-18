<?php
/**
 * Tests for RUM-segmented speculation auto-tune (issue #1425).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\AI_Adaptive;
use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\RUM;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Pins the opt-in per-URL list-rule auto-tune contract: undersampled sites
 * emit zero list rules, commerce/auth URLs are never listed, eagerness never
 * exceeds moderate, qualified output stays bounded under ~1KB, manual
 * settings are never written, and the legacy path is unchanged when the
 * opt-in flag is off.
 */
class SpeculationAutotune1425Test extends \PHPUnit\Framework\TestCase {
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
	 * @param callable|null $apply_filters_handler Optional apply_filters handler.
	 * @return void
	 */
	private function install_stubs( ?callable $apply_filters_handler = null ): void {
		RUM::clear_field_lcp_cache();
		AI_Adaptive::reset_model_memo();
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
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		$apply_filters_handler = $apply_filters_handler ?? static function ( $hook, $value ) {
			return $value;
		};
		Functions\when( 'apply_filters' )->alias( $apply_filters_handler );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $url ) {
				return rtrim( (string) $url, '/' );
			}
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->alias(
			function ( $path = '' ) {
				return 'http://example.com' . $path;
			}
		);
	}

	/**
	 * Build a qualified RUM day fixture (good p75, n >= 20).
	 *
	 * @param array $extra_paths Optional path => sample-count pairs to add.
	 * @return array RUM aggregate keyed by date.
	 */
	private function qualified_rum( array $extra_paths = array() ): array {
		$today = gmdate( 'Y-m-d' );
		$day   = array(
			'/popular/' => array(
				'lcp'    => array(
					'n'   => 30,
					'sum' => 54000.0,
					'min' => 1800.0,
					'max' => 1800.0,
				),
				'lcpSeg' => array(
					'mobile|single' => array(
						'device'   => 'mobile',
						'template' => 'single',
						'n'        => 20,
						'sum'      => 36000.0,
						'min'      => 1800.0,
						'max'      => 1800.0,
						'samples'  => array_fill( 0, 20, 1800.0 ),
					),
				),
			),
		);
		foreach ( $extra_paths as $path => $n ) {
			$day[ $path ] = array(
				'lcp' => array(
					'n'   => $n,
					'sum' => (float) $n * 1000.0,
					'min' => 1000.0,
					'max' => 1000.0,
				),
			);
		}
		return array( $today => $day );
	}

	/**
	 * Baseline frontend stubs: visitor context with pretty permalinks.
	 *
	 * @return void
	 */
	private function frontend_context(): void {
		unset( $_COOKIE['woocommerce_items_in_cart'], $_COOKIE['woocommerce_cart_hash'] );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$this->options['permalink_structure'] = '/%postname%/';
	}

	/**
	 * Auto-tune defaults to off with min 20 and max 5.
	 *
	 * @return void
	 */
	public function test_autotune_defaults_are_opt_in_off(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array( 'ai_adaptive' => array( 'enabled' => true ) );
		Util::clear_settings_cache();

		$this->assertFalse( AI_Adaptive::is_speculation_autotune_enabled() );
		$this->assertSame( 20, AI_Adaptive::speculation_min_samples() );
		$this->assertSame( 5, AI_Adaptive::speculation_max_urls() );

		$defaults = Util::get_default_settings();
		$this->assertFalse( $defaults['ai_adaptive']['speculation_autotune_enabled'] );
		$this->assertSame( 20, $defaults['ai_adaptive']['speculation_min_samples'] );
		$this->assertSame( 5, $defaults['ai_adaptive']['speculation_max_urls'] );
	}

	/**
	 * Legacy behaviour is unchanged while the opt-in flag is off.
	 *
	 * @return void
	 */
	public function test_legacy_path_unchanged_when_autotune_off(): void {
		$this->install_stubs();
		$this->options['wppo_web_vitals_rum'] = array();
		$this->options['wppo_settings']       = array( 'ai_adaptive' => array( 'enabled' => true ) );
		$this->options[ AI_Adaptive::OPTION ] = array(
			'prefetch_urls' => array( 'http://example.com/a/' ),
			'eagerness'     => 'eager',
		);
		Util::clear_settings_cache();
		$this->frontend_context();

		$rules = AI_Adaptive::filter_speculation_rules( array() );
		$this->assertCount( 1, $rules );
		$this->assertSame( 'conservative', $rules[0]['eagerness'] );
	}

	/**
	 * Undersampled RUM emits zero list rules even with stale model URLs.
	 *
	 * @return void
	 */
	public function test_autotune_undersampled_emits_zero_list_rules(): void {
		$this->install_stubs();
		$today                                = gmdate( 'Y-m-d' );
		$this->options['wppo_web_vitals_rum'] = array(
			$today => array(
				'/thin/' => array(
					'lcp' => array(
						'n'   => 5,
						'sum' => 9000.0,
						'min' => 1800.0,
						'max' => 1800.0,
					),
				),
			),
		);
		$this->options['wppo_settings']       = array(
			'ai_adaptive' => array(
				'enabled'                      => true,
				'speculation_autotune_enabled' => true,
			),
		);
		$this->options[ AI_Adaptive::OPTION ] = array(
			'prefetch_urls' => array( 'http://example.com/stale/' ),
			'eagerness'     => 'moderate',
		);
		Util::clear_settings_cache();
		$this->frontend_context();

		$incoming = array(
			array(
				'source'    => 'document',
				'eagerness' => 'conservative',
			),
		);
		$this->assertSame( $incoming, AI_Adaptive::filter_speculation_rules( $incoming ) );
	}

	/**
	 * A custom min-sample threshold is honoured by the auto-tune gate.
	 *
	 * @return void
	 */
	public function test_autotune_custom_min_samples_gate(): void {
		$this->install_stubs();
		$this->options['wppo_web_vitals_rum'] = $this->qualified_rum();
		$this->options['wppo_settings']       = array(
			'ai_adaptive' => array(
				'enabled'                      => true,
				'speculation_autotune_enabled' => true,
				'speculation_min_samples'      => 100,
			),
		);
		$this->options[ AI_Adaptive::OPTION ] = array(
			'prefetch_urls' => array( 'http://example.com/a/' ),
			'eagerness'     => 'moderate',
		);
		Util::clear_settings_cache();
		$this->frontend_context();

		$this->assertSame( 100, AI_Adaptive::speculation_min_samples() );
		$this->assertSame( array(), AI_Adaptive::filter_speculation_rules( array() ) );
	}

	/**
	 * Commerce/auth URLs are never listed on the auto-tune path, even outside
	 * commerce contexts where the legacy path would list them.
	 *
	 * @return void
	 */
	public function test_autotune_never_lists_commerce_urls(): void {
		$this->install_stubs();
		$this->options['wppo_web_vitals_rum'] = $this->qualified_rum();
		$this->options['wppo_settings']       = array(
			'ai_adaptive' => array(
				'enabled'                      => true,
				'speculation_autotune_enabled' => true,
			),
		);
		$this->options[ AI_Adaptive::OPTION ] = array(
			'prefetch_urls' => array( 'http://example.com/checkout/sneaky/', 'http://example.com/a/' ),
			'eagerness'     => 'moderate',
		);
		Util::clear_settings_cache();
		$this->frontend_context();

		$rules = AI_Adaptive::filter_speculation_rules( array() );
		$this->assertCount( 1, $rules );
		$this->assertContains( 'http://example.com/a/', $rules[0]['urls'] );
		foreach ( $rules[0]['urls'] as $url ) {
			$this->assertStringNotContainsString( 'checkout', $url );
			$this->assertStringNotContainsString( 'cart', $url );
			$this->assertStringNotContainsString( 'account', $url );
		}
	}

	/**
	 * A rogue eager eagerness is capped at moderate on the auto-tune path,
	 * even without a commerce context.
	 *
	 * @return void
	 */
	public function test_autotune_caps_rogue_eager_at_moderate(): void {
		$this->install_stubs(
			static function ( $hook, $value ) {
				if ( 'wppo_ai_speculation_eagerness' === $hook ) {
					return 'eager';
				}
				return $value;
			}
		);
		$this->options['wppo_web_vitals_rum'] = $this->qualified_rum();
		$this->options['wppo_settings']       = array(
			'ai_adaptive' => array(
				'enabled'                      => true,
				'speculation_autotune_enabled' => true,
			),
		);
		$this->options[ AI_Adaptive::OPTION ] = array(
			'prefetch_urls' => array( 'http://example.com/a/' ),
			'eagerness'     => 'conservative',
		);
		Util::clear_settings_cache();
		$this->frontend_context();

		$rules = AI_Adaptive::filter_speculation_rules( array() );
		$this->assertCount( 1, $rules );
		$this->assertSame( 'moderate', $rules[0]['eagerness'] );
	}

	/**
	 * Qualified segments emit bounded per-URL list rules under a 1KB delta.
	 *
	 * @return void
	 */
	public function test_autotune_qualified_emits_bounded_list_under_1kb(): void {
		$this->install_stubs();
		$this->options['wppo_web_vitals_rum'] = $this->qualified_rum(
			array(
				'/post-one/'   => 90,
				'/post-two/'   => 80,
				'/post-three/' => 70,
				'/post-four/'  => 60,
				'/post-five/'  => 50,
				'/post-six/'   => 40,
			)
		);
		$this->options['wppo_settings']       = array(
			'ai_adaptive' => array(
				'enabled'                      => true,
				'speculation_autotune_enabled' => true,
			),
		);
		$this->options[ AI_Adaptive::OPTION ] = array(
			'prefetch_urls' => array( 'http://example.com/model-pick/' ),
			'eagerness'     => 'conservative',
		);
		Util::clear_settings_cache();
		$this->frontend_context();

		$rules = AI_Adaptive::filter_speculation_rules( array() );
		$this->assertCount( 1, $rules );
		$this->assertSame( 'list', $rules[0]['source'] );
		$this->assertLessThanOrEqual( 5, count( $rules[0]['urls'] ) );
		$this->assertContains( 'http://example.com/model-pick/', $rules[0]['urls'] );
		$this->assertLessThanOrEqual( 1024, strlen( (string) wp_json_encode( $rules[0]['urls'] ) ) );
	}

	/**
	 * A custom max-URL cap is honoured by the auto-tune path.
	 *
	 * @return void
	 */
	public function test_autotune_custom_max_urls_cap(): void {
		$this->install_stubs();
		$this->options['wppo_web_vitals_rum'] = $this->qualified_rum(
			array(
				'/post-one/'   => 90,
				'/post-two/'   => 80,
				'/post-three/' => 70,
				'/post-four/'  => 60,
			)
		);
		$this->options['wppo_settings']       = array(
			'ai_adaptive' => array(
				'enabled'                      => true,
				'speculation_autotune_enabled' => true,
				'speculation_max_urls'         => 3,
			),
		);
		$this->options[ AI_Adaptive::OPTION ] = array(
			'prefetch_urls' => array( 'http://example.com/model-pick/' ),
			'eagerness'     => 'conservative',
		);
		Util::clear_settings_cache();
		$this->frontend_context();

		$this->assertSame( 3, AI_Adaptive::speculation_max_urls() );
		$rules = AI_Adaptive::filter_speculation_rules( array() );
		$this->assertCount( 1, $rules );
		$this->assertLessThanOrEqual( 3, count( $rules[0]['urls'] ) );
	}

	/**
	 * The budget trimmer keeps rank order under the byte budget.
	 *
	 * @return void
	 */
	public function test_trim_speculation_urls_to_budget(): void {
		$urls    = array(
			'http://example.com/a/',
			'http://example.com/' . str_repeat( 'p', 900 ) . '/',
			'http://example.com/' . str_repeat( 'q', 900 ) . '/',
		);
		$trimmed = AI_Adaptive::trim_speculation_urls_to_budget( $urls, 1024 );
		$this->assertSame( 'http://example.com/a/', $trimmed[0] );
		$this->assertLessThanOrEqual( 1024, strlen( (string) wp_json_encode( $trimmed ) ) );
		$this->assertGreaterThanOrEqual( 1, count( $trimmed ) );
	}

	/**
	 * The auto-tune filter never writes manual settings.
	 *
	 * @return void
	 */
	public function test_autotune_never_overwrites_manual_settings(): void {
		$this->install_stubs();
		$this->options['wppo_web_vitals_rum'] = $this->qualified_rum();
		$this->options['wppo_settings']       = array(
			'ai_adaptive'      => array(
				'enabled'                      => true,
				'speculation_autotune_enabled' => true,
			),
			'preload_settings' => array(
				'enableSpeculationRules' => true,
				'speculationMode'        => 'prefetch',
				'speculationEagerness'   => 'conservative',
			),
		);
		$this->options[ AI_Adaptive::OPTION ] = array(
			'prefetch_urls' => array( 'http://example.com/a/' ),
			'eagerness'     => 'conservative',
		);
		Util::clear_settings_cache();
		$this->frontend_context();

		$before = $this->options;
		AI_Adaptive::filter_speculation_rules( array() );

		$this->assertSame( $before['wppo_settings'], $this->options['wppo_settings'] );
		$this->assertSame( 'conservative', $this->options['wppo_settings']['preload_settings']['speculationEagerness'] );
	}

	/**
	 * Undersampled learning emits zero prefetch URLs when opted in.
	 *
	 * @return void
	 */
	public function test_learn_undersampled_emits_zero_urls_when_opted_in(): void {
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
			),
		);
		$this->options['wppo_web_vitals_trends'] = array();
		$this->options['wppo_settings']          = array(
			'ai_adaptive' => array(
				'enabled'                      => true,
				'speculation_autotune_enabled' => true,
			),
		);
		Util::clear_settings_cache();

		$model = AI_Adaptive::learn();
		$this->assertIsArray( $model );
		$this->assertSame( array(), $model['prefetch_urls'] );
	}

	/**
	 * Migration backfills the additive keys without touching other keys.
	 *
	 * @return void
	 */
	public function test_migration_backfills_autotune_keys(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'ai_adaptive' => array( 'enabled' => true ),
		);

		$main = ( new \ReflectionClass( Main::class ) )->newInstanceWithoutConstructor();
		$prop = new \ReflectionProperty( Main::class, 'options' );
		$prop->setValue( $main, array( 'ai_adaptive' => array( 'enabled' => true ) ) );

		$main->maybe_migrate_ai_speculation_autotune();

		$stored = $this->options['wppo_settings'];
		$this->assertFalse( $stored['ai_adaptive']['speculation_autotune_enabled'] );
		$this->assertSame( 20, $stored['ai_adaptive']['speculation_min_samples'] );
		$this->assertSame( 5, $stored['ai_adaptive']['speculation_max_urls'] );
		$this->assertTrue( $stored['ai_adaptive']['enabled'], 'Existing keys must be preserved verbatim.' );

		// Idempotent: a second run keeps the backfilled values.
		$main->maybe_migrate_ai_speculation_autotune();
		$this->assertFalse( $this->options['wppo_settings']['ai_adaptive']['speculation_autotune_enabled'] );
	}

	/**
	 * Migration preserves explicitly stored tuning values.
	 *
	 * @return void
	 */
	public function test_migration_preserves_explicit_tuning_values(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = array(
			'ai_adaptive' => array(
				'enabled'                      => true,
				'speculation_autotune_enabled' => true,
				'speculation_min_samples'      => 50,
				'speculation_max_urls'         => 3,
			),
		);

		$main = ( new \ReflectionClass( Main::class ) )->newInstanceWithoutConstructor();
		$prop = new \ReflectionProperty( Main::class, 'options' );
		$prop->setValue(
			$main,
			array(
				'ai_adaptive' => array(
					'enabled'                      => true,
					'speculation_autotune_enabled' => true,
					'speculation_min_samples'      => 50,
					'speculation_max_urls'         => 3,
				),
			)
		);

		$main->maybe_migrate_ai_speculation_autotune();

		$stored = $this->options['wppo_settings'];
		$this->assertTrue( $stored['ai_adaptive']['speculation_autotune_enabled'] );
		$this->assertSame( 50, $stored['ai_adaptive']['speculation_min_samples'] );
		$this->assertSame( 3, $stored['ai_adaptive']['speculation_max_urls'] );
	}
}
