<?php
/**
 * Tests for Main::filter_speculation_list_rules() + get_speculation_list_urls().
 *
 * Emits a `{"source":"list","urls":[...]}` rule for high-value same-site URLs
 * (home + performance_audit.high_value_urls) via the `wp_speculation_rules`
 * filter (WP 6.8+), complementing the mode/eagerness-only configuration.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests the high-value speculation list rule.
 *
 * @package PerformanceOptimise\Tests
 */
class MainSpeculationListRulesTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options store.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Register WP stubs backed by the in-memory options store.
	 *
	 * @param array $settings wppo_settings value.
	 * @return void
	 */
	private function install_stubs( array $settings = array() ): void {
		$this->options = array(
			'wppo_settings'       => $settings,
			'permalink_structure' => '/%postname%/',
		);

		Functions\when( 'untrailingslashit' )->alias(
			static function ( $url ) {
				return rtrim( (string) $url, '/' );
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
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
	 * Default settings: speculation on + two high-value URLs.
	 *
	 * @return array
	 */
	private function default_settings(): array {
		return array(
			'preload_settings'  => array(
				'enableSpeculationRules' => true,
				'speculationMode'        => 'prefetch',
				'speculationEagerness'   => 'moderate',
			),
			'performance_audit' => array(
				'high_value_urls' => array(
					'http://example.com/pricing/',
					'http://example.com/about/',
				),
			),
		);
	}

	/**
	 * Test the list rule contains home + top same-site URLs with validated eagerness.
	 *
	 * @return void
	 */
	public function test_list_contains_home_and_high_value_urls(): void {
		$this->install_stubs( $this->default_settings() );
		$main = $this->make_main( $this->default_settings()['preload_settings'] );

		$rules = $main->filter_speculation_list_rules( array() );

		$this->assertCount( 1, $rules );
		$this->assertSame( 'list', $rules[0]['source'] );
		$this->assertSame(
			array(
				'http://example.com/',
				'http://example.com/pricing/',
				'http://example.com/about/',
			),
			$rules[0]['urls']
		);
		$this->assertSame( 'moderate', $rules[0]['eagerness'] );
	}

	/**
	 * Test cart/checkout/account, admin, login, REST, and cross-site URLs are excluded.
	 *
	 * @return void
	 */
	public function test_list_excludes_commerce_admin_and_cross_site(): void {
		$settings = $this->default_settings();
		$settings['performance_audit']['high_value_urls'] = array(
			'http://example.com/cart/',
			'http://example.com/checkout/',
			'http://example.com/my-account/',
			'http://example.com/wp-admin/edit.php',
			'http://example.com/wp-login.php',
			'http://example.com/wp-json/wp/v2/posts',
			'https://evil.test/steal/',
			'http://example.com/good/',
		);
		$this->install_stubs( $settings );
		$main = $this->make_main( $settings['preload_settings'] );

		$urls = $main->get_speculation_list_urls();

		$this->assertSame(
			array(
				'http://example.com/',
				'http://example.com/good/',
			),
			$urls
		);
	}

	/**
	 * Test null/non-array rules input is returned untouched.
	 *
	 * @return void
	 */
	public function test_null_and_non_array_rules_untouched(): void {
		$this->install_stubs( $this->default_settings() );
		$main = $this->make_main( $this->default_settings()['preload_settings'] );

		$this->assertNull( $main->filter_speculation_list_rules( null ) );
		$this->assertSame( 'unchanged', $main->filter_speculation_list_rules( 'unchanged' ) );
	}

	/**
	 * Test the rule is not emitted when speculation is disabled.
	 *
	 * @return void
	 */
	public function test_disabled_speculation_leaves_rules_unchanged(): void {
		$this->install_stubs( $this->default_settings() );
		$main = $this->make_main( array( 'enableSpeculationRules' => false ) );

		$this->assertSame( array(), $main->filter_speculation_list_rules( array() ) );
	}

	/**
	 * Test plain permalinks (and empty high-value list beyond home) emit nothing.
	 *
	 * Core document rules require pretty permalinks, so the list is skipped.
	 *
	 * @return void
	 */
	public function test_plain_permalinks_emit_nothing(): void {
		$this->install_stubs( $this->default_settings() );
		$this->options['permalink_structure'] = '';
		Util::clear_settings_cache();
		$main = $this->make_main( $this->default_settings()['preload_settings'] );

		$this->assertSame( array(), $main->get_speculation_list_urls() );
		$this->assertSame( array(), $main->filter_speculation_list_rules( array() ) );
	}

	/**
	 * Test duplicates are removed and the list is capped at 10 URLs.
	 *
	 * @return void
	 */
	public function test_list_dedupes_and_caps_at_ten(): void {
		$settings = $this->default_settings();
		$extra    = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$extra[] = 'http://example.com/page-' . $i . '/';
		}
		// Home duplicated on purpose; must appear exactly once.
		$extra[] = 'http://example.com/';
		$extra[] = 'http://example.com/page-1/';
		$settings['performance_audit']['high_value_urls'] = $extra;
		$this->install_stubs( $settings );
		$main = $this->make_main( $settings['preload_settings'] );

		$urls = $main->get_speculation_list_urls();

		$this->assertCount( 10, $urls );
		$this->assertSame( 'http://example.com/', $urls[0] );
		$this->assertSame( $urls, array_unique( $urls ) );
	}

	/**
	 * Test invalid eagerness falls back to conservative in the list rule.
	 *
	 * @return void
	 */
	public function test_invalid_eagerness_falls_back_to_conservative(): void {
		$settings = $this->default_settings();
		$settings['preload_settings']['speculationEagerness'] = 'aggressive';
		$this->install_stubs( $settings );
		$main = $this->make_main( $settings['preload_settings'] );

		$rules = $main->filter_speculation_list_rules( array() );

		$this->assertCount( 1, $rules );
		$this->assertSame( 'conservative', $rules[0]['eagerness'] );
	}
}
