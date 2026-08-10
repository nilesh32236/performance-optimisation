<?php
/**
 * Tests for System_Info class.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\System_Info;
use Brain\Monkey\Functions;

/**
 * Tests for System_Info class.
 *
 * @package PerformanceOptimise\Tests
 */
class SystemInfoTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Test that get_all returns every expected group.
	 */
	public function test_get_all_returns_all_groups(): void {
		$GLOBALS['wpdb'] = new WPPO_SystemInfo_DB_Mock();

		Functions\stubs(
			array(
				'get_option',
				'wp_using_ext_object_cache',
				'esc_html__',
				'size_format',
				'__',
				'get_bloginfo',
				'wp_get_environment_type',
				'is_ssl',
				'is_multisite',
			)
		);
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'size_format' )->returnArg( 1 );
		Functions\when( 'function_exists' )->justReturn( false );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'is_multisite' )->justReturn( false );

		$info = System_Info::get_all();

		$this->assertArrayHasKey( 'php', $info );
		$this->assertArrayHasKey( 'database', $info );
		$this->assertArrayHasKey( 'wordpress', $info );
		$this->assertArrayHasKey( 'wp_constants', $info );
		$this->assertArrayHasKey( 'server', $info );
		$this->assertArrayHasKey( 'cache', $info );
		$this->assertArrayHasKey( 'infrastructure', $info );
		$this->assertArrayHasKey( 'opcache', $info );
	}

	/**
	 * Test that get_php returns version and extension count.
	 */
	public function test_get_php_returns_environment_details(): void {
		$php = System_Info::get_php();

		$this->assertSame( PHP_VERSION, $php['version'] );
		$this->assertIsString( $php['sapi'] );
		$this->assertIsInt( $php['extensions_count'] );
		$this->assertGreaterThan( 0, $php['extensions_count'] );
	}

	/**
	 * Test that get_database handles a mocked $wpdb with a db_version method.
	 */
	public function test_get_database_returns_details(): void {
		$GLOBALS['wpdb']                    = new WPPO_SystemInfo_DB_Mock();
		$GLOBALS['wpdb']->db_version_result = '8.0.36';

		$db = System_Info::get_database();

		$this->assertSame( '8.0.36', $db['server_version'] );
		$this->assertNull( $db['extension'] );
	}

	/**
	 * Test that get_database returns null version when db_version is unavailable.
	 */
	public function test_get_database_handles_missing_db_version(): void {
		$GLOBALS['wpdb']                    = new WPPO_SystemInfo_DB_Mock();
		$GLOBALS['wpdb']->db_version_result = '';

		$db = System_Info::get_database();

		$this->assertNull( $db['server_version'] );
	}

	/**
	 * Test that get_wordpress returns expected values.
	 */
	public function test_get_wordpress_returns_installation_details(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'get_option' )->justReturn( '/%postname%/' );
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( '__' )->returnArg( 1 );

		$wp = System_Info::get_wordpress();

		$this->assertSame( '6.8', $wp['version'] );
		$this->assertSame( 'production', $wp['environment_type'] );
		$this->assertSame( '/%postname%/', $wp['permalink_structure'] );
		$this->assertSame( 'No', $wp['using_https'] );
		$this->assertSame( 'No', $wp['multisite'] );
	}

	/**
	 * Test that get_wp_constants reports undefined constants.
	 */
	public function test_get_wp_constants_reports_undefined(): void {
		$constants = System_Info::get_wp_constants();

		$this->assertContains( $constants['WP_DEBUG'], array( 'true', 'false', 'undefined' ) );
		$this->assertSame( 'undefined', $constants['WP_MEMORY_LIMIT'] );
	}

	/**
	 * Test that get_server returns server software and OS details.
	 */
	public function test_get_server_returns_details(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.18.0';

		$server = System_Info::get_server();

		$this->assertSame( 'nginx/1.18.0', $server['server_software'] );
		$this->assertSame( PHP_OS, explode( ' ', $server['os'] )[0] );
		$this->assertIsString( $server['architecture'] );
	}

	/**
	 * Test that get_cache returns memory usage strings.
	 */
	public function test_get_cache_returns_memory_usage(): void {
		Functions\stubs(
			array(
				'wp_using_ext_object_cache',
				'esc_html__',
				'size_format',
				'get_option',
				'is_multisite',
			)
		);
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'size_format' )->justReturn( '1 MB' );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'is_multisite' )->justReturn( false );

		$cache = System_Info::get_cache();

		$this->assertSame( 'Disabled', $cache['object_cache_status'] );
		$this->assertIsString( $cache['peak_memory_usage'] );
		$this->assertIsString( $cache['current_memory_usage'] );
	}

	/**
	 * Test that get_infrastructure reports Action Scheduler availability.
	 */
	public function test_get_infrastructure_returns_status(): void {
		Functions\stubs(
			array(
				'get_option',
				'function_exists',
				'__',
			)
		);
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'function_exists' )->justReturn( false );
		Functions\when( '__' )->returnArg( 1 );

		$infra = System_Info::get_infrastructure();

		$this->assertFalse( $infra['action_scheduler']['available'] );
		$this->assertFalse( $infra['pagespeed_api']['configured'] );
	}

	/**
	 * Test that get_opcache reports disabled when the extension is unavailable.
	 */
	public function test_get_opcache_disabled_when_unavailable(): void {
		Functions\stubs(
			array(
				'function_exists',
				'esc_html__',
			)
		);
		Functions\when( 'function_exists' )->justReturn( false );
		Functions\when( 'esc_html__' )->returnArg( 1 );

		$opcache = System_Info::get_opcache();

		$this->assertSame( 'Disabled', $opcache['status'] );
		$this->assertSame( 'not available', $opcache['detail'] );
	}

	/**
	 * Test that get_request_start_microtime returns null when unset.
	 */
	public function test_get_request_start_microtime_returns_null_when_unset(): void {
		unset( $_SERVER['REQUEST_TIME_FLOAT'] );
		$this->assertNull( System_Info::get_request_start_microtime() );
	}

	/**
	 * Test that get_request_start_microtime returns the timestamp when set.
	 */
	public function test_get_request_start_microtime_returns_value(): void {
		$_SERVER['REQUEST_TIME_FLOAT'] = '1700000000.5';
		$this->assertSame( 1700000000.5, System_Info::get_request_start_microtime() );
	}

	/**
	 * Test that get_woocommerce_presets returns null when WooCommerce is inactive.
	 */
	public function test_get_woocommerce_presets_null_when_inactive(): void {
		Functions\stubs( array( 'function_exists' ) );
		Functions\when( 'function_exists' )->justReturn( false );

		$this->assertNull( System_Info::get_woocommerce_presets() );
	}

	/**
	 * Test that get_woocommerce_presets collects checkout and cart URLs.
	 */
	public function test_get_woocommerce_presets_collects_urls(): void {
		// Report the WooCommerce helpers as available while leaving every other
		// function existence check (including Brain Monkey internals) intact.
		Functions\stubs(
			array(
				'wc_get_checkout_url',
				'wc_get_cart_url',
				'esc_url_raw',
			)
		);
		Functions\when( 'function_exists' )->alias(
			static function ( $name ) {
				return in_array( $name, array( 'wc_get_checkout_url', 'wc_get_cart_url' ), true );
			}
		);
		Functions\when( 'wc_get_checkout_url' )->justReturn( 'http://example.com/checkout/' );
		Functions\when( 'wc_get_cart_url' )->justReturn( 'http://example.com/cart/' );
		Functions\when( 'esc_url_raw' )->returnArg();

		$presets = System_Info::get_woocommerce_presets();

		$this->assertSame( array( 'http://example.com/checkout/', 'http://example.com/cart/' ), $presets );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile

/**
 * Minimal $wpdb stand-in for System_Info tests.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_SystemInfo_DB_Mock {
	/**
	 * Result returned by db_version().
	 *
	 * @var string
	 */
	public $db_version_result = '';

	/**
	 * Simulate db_version().
	 *
	 * @return string
	 */
	public function db_version() {
		return $this->db_version_result;
	}

	/**
	 * Simulate prepare() by returning the query unchanged.
	 *
	 * @param string $query SQL query.
	 * @param mixed  ...$args Prepared arguments (unused).
	 * @return string
	 */
	public function prepare( $query, ...$args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $query;
	}

	/**
	 * Return null (no MySQL variable row).
	 *
	 * @param string $query SQL query (unused).
	 * @return null
	 */
	public function get_row( $query = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return null;
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
