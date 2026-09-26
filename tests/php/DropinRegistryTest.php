<?php
/**
 * P3-007 tests for the neutral drop-in invalidation registry.
 *
 * @package PerformanceOptimise\Tests
 * @since   NEXT
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Test-only local source reads and isolated PHP process probe.

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Dropin_Registry;
use PerformanceOptimise\Inc\System_Info;
use PerformanceOptimise\Inc\Util;

/**
 * Validate Dropin_Registry behavior and its mutation-call-site boundary.
 *
 * @since NEXT
 */
final class DropinRegistryTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Option values captured while System_Info invalidates its caches.
	 *
	 * @var array<string,mixed>
	 */
	private array $options = array();

	/**
	 * Transient keys deleted by the invalidation.
	 *
	 * @var string[]
	 */
	private array $deleted_transients = array();

	/**
	 * Set up isolated cache-owner stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		$this->options            = array();
		$this->deleted_transients = array();

		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( (string) $name, $this->options ) ? $this->options[ (string) $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ (string) $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				$this->deleted_transients[] = (string) $key;
				return true;
			}
		);
	}

	/**
	 * The registry preserves System_Info's memo, salt, and transient invalidation.
	 *
	 * @return void
	 */
	public function test_invalidate_preserves_system_info_cache_invalidation(): void {
		$reflection = new \ReflectionProperty( System_Info::class, 'litespeed_request_cache' );
		$reflection->setValue( null, array( 'dropin' => array( 'advanced_cache' => 'none' ) ) );
		$this->options['wppo_sysinfo_salt'] = 4;

		Dropin_Registry::invalidate();

		$this->assertNull( $reflection->getValue() );
		$this->assertSame( 5, $this->options['wppo_sysinfo_salt'] );
		$this->assertContains( Util::transient_key( 'wppo_sysinfo_dropin_check' ), $this->deleted_transients );
	}

	/**
	 * Invalidating without a loadable System_Info owner is a fail-open no-op.
	 *
	 * @return void
	 */
	public function test_invalidate_without_system_info_fails_open(): void {
		$root = dirname( __DIR__, 2 );

		$command = escapeshellarg( PHP_BINARY )
			. ' -r ' . escapeshellarg(
				"define('ABSPATH', '/tmp/wordpress/'); require \$argv[1]; \\PerformanceOptimise\\Inc\\Dropin_Registry::invalidate();"
			)
			. ' -- ' . escapeshellarg( $root . '/includes/Cache/class-dropin-registry.php' )
			. ' 2>&1';
		$output  = array();
		$status  = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Isolated test process proves the missing-owner guard without loading System_Info into this process.
		exec( $command, $output, $status );

		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertSame( array(), $output );
	}

	/**
	 * Drop-in mutators use the registry and contain no direct System_Info flush.
	 *
	 * @return void
	 */
	public function test_mutators_route_invalidations_through_registry_only(): void {
		$advanced_source = (string) file_get_contents( WPPO_PLUGIN_PATH . 'includes/Cache/class-advanced-cache-handler.php' );
		$object_source   = (string) file_get_contents( WPPO_PLUGIN_PATH . 'includes/Cache/class-object-cache.php' );

		$this->assertSame( 2, substr_count( $advanced_source, 'Dropin_Registry::invalidate()' ) );
		$this->assertSame( 4, substr_count( $object_source, 'Dropin_Registry::invalidate()' ) );
		foreach ( array( $advanced_source, $object_source ) as $source ) {
			$this->assertStringNotContainsString( 'System_Info::flush_dropin_cache()', $source );
			$this->assertStringNotContainsString( "array( 'PerformanceOptimise\\Inc\\System_Info', 'flush_dropin_cache' )", $source );
		}
	}
}
