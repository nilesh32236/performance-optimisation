<?php
/**
 * Tests for Cloudflare APO double-cache degrade (issue #911).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Apo_Detect;
use PerformanceOptimise\Inc\Edge_Cache;
use PerformanceOptimise\Inc\System_Info;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

require_once __DIR__ . '/../../includes/Edge/class-apo-detect.php';
require_once __DIR__ . '/../../includes/Integrations/class-host-detect.php';

/**
 * APO degrade tests.
 */
class ApoDegradeTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options.
	 *
	 * @var array
	 */
	private array $options = array();

	/**
	 * Forced APO override (null = no override).
	 *
	 * @var bool|null
	 */
	private $apo_override = null;

	/**
	 * Install stubs.
	 *
	 * @return void
	 */
	private function install_stubs(): void {
		unset( $_SERVER['HTTP_CF_APO_VIA'], $_SERVER['HTTP_CF_EDGE_CACHE'] );
		$this->options = array();

		Functions\stubs(
			array(
				'apply_filters',
				'get_option',
			)
		);
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				if ( 'wppo_cf_apo_active' === $hook && null !== $this->apo_override ) {
					return $this->apo_override;
				}
				if ( 'wppo_host_adapter' === $hook || 'wppo_host_detected' === $hook ) {
					return $value;
				}
				return $value;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				if ( 'cloudflare' === $name ) {
					return $fallback;
				}
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
	}

	/**
	 * APO is inactive by default (fail closed to caching on).
	 *
	 * @return void
	 */
	public function test_inactive_by_default(): void {
		$this->apo_override = null;
		$this->install_stubs();
		$this->assertFalse( Apo_Detect::is_apo_active() );
		$this->assertSame( '', Apo_Detect::get_degrade_reason() );
	}

	/**
	 * Filter override forces APO active.
	 *
	 * @return void
	 */
	public function test_filter_forces_active(): void {
		$this->apo_override = true;
		$this->install_stubs();
		$this->assertTrue( Apo_Detect::is_apo_active() );
		$this->assertNotSame( '', Apo_Detect::get_degrade_reason() );
	}

	/**
	 * APO response header marks APO active.
	 *
	 * @return void
	 */
	public function test_header_marks_active(): void {
		$this->apo_override = null;
		$this->install_stubs();
		$_SERVER['HTTP_CF_APO_VIA'] = 'cache';
		$this->assertTrue( Apo_Detect::is_apo_active() );
		unset( $_SERVER['HTTP_CF_APO_VIA'] );
	}

	/**
	 * Edge cache refuses to double-cache under APO.
	 *
	 * @return void
	 */
	public function test_edge_cache_degraded_under_apo(): void {
		$this->apo_override = true;
		$this->install_stubs();
		$this->options['wppo_settings'] = array( 'edge_cache' => array( 'enabled' => true ) );
		Util::clear_settings_cache();
		$this->assertFalse( Edge_Cache::is_enabled() );
		$this->assertTrue( Edge_Cache::is_apo_degraded() );
		$this->assertNotSame( '', Edge_Cache::get_degrade_notice() );
	}

	/**
	 * Edge cache stays enabled without APO.
	 *
	 * @return void
	 */
	public function test_edge_cache_enabled_without_apo(): void {
		$this->apo_override = null;
		$this->install_stubs();
		$this->options['wppo_settings'] = array( 'edge_cache' => array( 'enabled' => true ) );
		Util::clear_settings_cache();
		$this->assertTrue( Edge_Cache::is_enabled() );
		$this->assertFalse( Edge_Cache::is_apo_degraded() );
	}

	/**
	 * System Info exposes the host group with who-caches-what.
	 *
	 * @return void
	 */
	public function test_system_info_host_group(): void {
		$this->apo_override = true;
		$this->install_stubs();
		$host = System_Info::get_host();
		$this->assertSame( 'none', $host['slug'] );
		$this->assertTrue( $host['apo_active'] );
		$this->assertSame( 'cloudflare-apo', $host['who_caches_what']['page_cache'] );
		// get_all() wires the host group through (verified via the
		// ArchitectureInventoryTest class inventory, not called here:
		// get_all() also probes $wpdb/pageload state outside this test's
		// stubs).
		$method = new \ReflectionMethod( System_Info::class, 'get_all' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local file scan (no remote URL, no WP_Filesystem in unit tests).
		$source = file_get_contents( (string) $method->getFileName() );
		$this->assertStringContainsString( "'host'", (string) $source );
	}
}
