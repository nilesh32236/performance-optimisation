<?php
/**
 * Tests for the narrowed speculation-rules exclusions (issue #1018 follow-up).
 *
 * Covers Main::get_speculation_exclude_paths() canonical list + filter,
 * cache-aware suppression in filter_speculation_rules_configuration(),
 * fill-gaps-only document-rule dedup, and path-scoped unsafe-URL rejection
 * in is_speculation_list_url_valid().
 *
 * @package PerformanceOptimise\Tests
 * @since 2.0.0
 */

use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests the narrowed speculation-rules exclusion behavior.
 *
 * @package PerformanceOptimise\Tests
 * @since 2.0.0
 */
class MainSpeculationExclusionsTest extends \PHPUnit\Framework\TestCase {
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
			'wppo_web_vitals_rum' => array(),
			'permalink_structure' => '/%postname%/',
		);

		Functions\when( 'untrailingslashit' )->alias(
			static function ( $url ) {
				return rtrim( (string) $url, '/' );
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_archive' )->justReturn( false );
		Functions\when( 'is_home' )->justReturn( false );
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
	 * Invoke the private is_speculation_list_url_valid().
	 *
	 * @param Main   $main Main instance.
	 * @param string $url  Candidate URL.
	 * @return bool
	 */
	private function is_url_valid( Main $main, string $url ): bool {
		$reflection = new \ReflectionClass( Main::class );
		$method     = $reflection->getMethod( 'is_speculation_list_url_valid' );
		$method->setAccessible( true );
		return (bool) $method->invoke( $main, $url );
	}

	/**
	 * Canonical exclusion list contains commerce defaults without
	 * redundant or over-broad substring wildcards.
	 *
	 * @return void
	 */
	public function test_exclude_paths_include_commerce_defaults(): void {
		$this->install_stubs();
		$main  = $this->make_main( array() );
		$paths = $main->get_speculation_exclude_paths( array() );

		$this->assertContains( '/cart/*', $paths );
		$this->assertContains( '/checkout/*', $paths );
		$this->assertContains( '/my-account/*', $paths );
		$this->assertContains( '/account/*', $paths );
		$this->assertContains( '/wp-login*', $paths );
		$this->assertContains( '/wp-admin/*', $paths );
		$this->assertContains( '/wp-json/*', $paths );

		// Redundant duplicates are gone (wildcard forms cover them).
		$this->assertNotContains( '/wp-login.php', $paths );
		$this->assertNotContains( '/wp-admin/admin-ajax.php*', $paths );

		// Over-broad substring wildcards are gone: nonce/logout/add-to-cart
		// are query-param actions already excluded by core's ?-URL handling.
		$this->assertNotContains( '/*nonce*', $paths );
		$this->assertNotContains( '/*logout*', $paths );
		$this->assertNotContains( '/*add-to-cart*', $paths );
	}

	/**
	 * The wppo_speculation_exclusions filter can extend the canonical list.
	 *
	 * @return void
	 */
	public function test_exclude_paths_honor_filter(): void {
		$this->install_stubs();
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wppo_speculation_exclusions' === $hook && is_array( $value ) ) {
					$value[] = '/members/*';
				}
				return $value;
			}
		);
		$main  = $this->make_main( array() );
		$paths = $main->get_speculation_exclude_paths( array() );

		$this->assertContains( '/members/*', $paths );
		$this->assertContains( '/cart/*', $paths );
	}

	/**
	 * Cart pages suppress speculation via a null configuration.
	 *
	 * @return void
	 */
	public function test_config_returns_null_on_cart(): void {
		$this->install_stubs();
		Functions\when( 'is_cart' )->justReturn( true );
		$main = $this->make_main( array( 'enableSpeculationRules' => true ) );

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
	}

	/**
	 * Preview pages suppress speculation via a null configuration.
	 *
	 * @return void
	 */
	public function test_config_returns_null_on_preview(): void {
		$this->install_stubs();
		Functions\when( 'is_preview' )->justReturn( true );
		$main = $this->make_main( array( 'enableSpeculationRules' => true ) );

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
	}

	/**
	 * A pre-existing document-source rule suppresses the archive document rule.
	 *
	 * @return void
	 */
	public function test_document_rule_skipped_when_document_rule_preexists(): void {
		$settings = array(
			'preload_settings'  => array(
				'enableSpeculationRules' => true,
				'speculationMode'        => 'prefetch',
				'speculationEagerness'   => 'moderate',
			),
			'performance_audit' => array( 'high_value_urls' => array() ),
		);
		$this->install_stubs( $settings );
		Functions\when( 'is_archive' )->justReturn( true );
		Functions\when( 'get_permalink' )->alias(
			static function ( $post ) {
				unset( $post );
				return 'http://example.com/first-post/';
			}
		);
		$GLOBALS['wp_query'] = (object) array( 'posts' => array( (object) array( 'ID' => 1 ) ) );

		try {
			$main         = $this->make_main( $settings['preload_settings'] );
			$pre_existing = array(
				array(
					'source'    => 'document',
					'where'     => array( 'href_matches' => '/*' ),
					'eagerness' => 'conservative',
				),
			);
			$rules        = $main->filter_speculation_list_rules( $pre_existing );
		} finally {
			unset( $GLOBALS['wp_query'] );
		}

		$document_rules = array_values(
			array_filter(
				$rules,
				static function ( $rule ) {
					return is_array( $rule ) && ( $rule['source'] ?? '' ) === 'document';
				}
			)
		);

		$this->assertCount( 1, $document_rules );
		$this->assertSame( '/*', $document_rules[0]['where']['href_matches'] );
	}

	/**
	 * Unsafe substrings in the path reject the URL; a host containing the
	 * same substring does not.
	 *
	 * @return void
	 */
	public function test_unsafe_substrings_rejected_on_path_not_host(): void {
		$this->install_stubs();
		$main = $this->make_main( array() );

		$this->assertTrue( $this->is_url_valid( $main, 'http://example.com/good/' ) );
		$this->assertFalse( $this->is_url_valid( $main, 'http://example.com/nonce-cleanup/' ) );
		$this->assertFalse( $this->is_url_valid( $main, 'http://example.com/my-logout-page/' ) );
		$this->assertFalse( $this->is_url_valid( $main, 'http://example.com/shop/?add-to-cart=123' ) );

		// A host containing an unsafe substring must not reject every URL:
		// re-point home to a host with "logout" in it and expect validity.
		Functions\when( 'home_url' )->justReturn( 'http://logout-news.example.com' );
		Util::reset_cached_home_urls();
		$this->assertTrue( $this->is_url_valid( $main, 'http://logout-news.example.com/hello/' ) );
	}
}
