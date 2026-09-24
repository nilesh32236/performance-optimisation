<?php
/**
 * P3-018 issue #1602 source and compatibility parity tests.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Ccss_Generator;
use PerformanceOptimise\Inc\Ccss_Store;
use PerformanceOptimise\Inc\Critical_CSS;
use PerformanceOptimise\Inc\Loader_Map;

/**
 * Critical CSS generation/status extraction parity tests.
 */
class CcssGeneratorParity1602Test extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Read a repository-local source file for contract assertions.
	 *
	 * @param string $relative_path Path relative to this test directory.
	 * @return string Source text.
	 */
	private function read_source( string $relative_path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local source scan.
		$source = file_get_contents( __DIR__ . '/../../' . ltrim( $relative_path, '/' ) );
		$this->assertIsString( $source );
		return $source;
	}

	/**
	 * Compatibility facades retain their existing signatures and visibility.
	 *
	 * @return void
	 */
	public function test_critical_css_compatibility_facades_remain_available(): void {
		$expected = array(
			'get_ccss_max_retries'       => 'public',
			'get_status_cache_for_store' => 'public',
		);

		foreach ( $expected as $method => $visibility ) {
			$reflection = new ReflectionMethod( Critical_CSS::class, $method );
			$this->assertSame( $visibility, $reflection->isPublic() ? 'public' : ( $reflection->isPrivate() ? 'private' : 'protected' ), $method );
		}
	}

	/**
	 * Critical_CSS keeps thin same-signature source facades to the new owner.
	 *
	 * @return void
	 */
	public function test_critical_css_methods_delegate_to_ccss_generator(): void {
		$source = $this->read_source( 'includes/CSS/class-critical-css.php' );

		$delegates = array(
			'Ccss_Generator::get_ccss_max_retries',
			'Ccss_Generator::get_status_cache',
		);
		foreach ( $delegates as $call ) {
			$this->assertStringContainsString( $call, $source, $call );
		}
		foreach ( array( 'function get_ccss_generation_attempts', 'function schedule_ccss_job', 'function set_status_cache' ) as $moved_private ) {
			$this->assertStringNotContainsString( $moved_private, $source, $moved_private );
		}
	}

	/**
	 * The loader exposes the owner without adding eager or PSR-4 loading.
	 *
	 * @return void
	 */
	public function test_loader_map_registers_only_the_lazy_css_owner(): void {
		$expected = realpath( __DIR__ . '/../../includes/CSS/class-ccss-generator.php' );
		$this->assertIsString( $expected );
		$this->assertSame( $expected, realpath( (string) Loader_Map::path_for( Ccss_Generator::class ) ) );
		$this->assertNotContains( 'CSS/class-ccss-generator.php', Loader_Map::eager_files() );
		$this->assertSame( 'CSS/class-ccss-generator.php', Loader_Map::fallback_map()['Ccss_Generator'] );
	}

	/**
	 * Status keys, salt, and transient fallback remain byte-compatible.
	 *
	 * @return void
	 */
	public function test_status_cache_key_contract_is_preserved(): void {
		$source = $this->read_source( 'includes/CSS/class-ccss-generator.php' );
		$this->assertStringContainsString( "private const SALT_KEY = 'wppo_ccss_salt';", $source );
		$this->assertStringContainsString( '$key = \'wppo_ccss_status_\' . $hash', $source );
		$this->assertStringContainsString( 'Util::transient_key( $key )', $source );
		$this->assertStringContainsString( 'Util::cache_salt( self::SALT_KEY )', $source );
		$this->assertStringContainsString( 'set_transient( Util::transient_key( $key ), $status, $ttl )', $source );
	}

	/**
	 * Scheduler payload, group, retry hook, and status vocabulary are pinned.
	 *
	 * @return void
	 */
	public function test_generation_scheduler_contract_is_preserved(): void {
		$source = $this->read_source( 'includes/CSS/class-ccss-generator.php' );
		foreach ( array( 'wppo_generate_ccss', 'wppo-ccss', 'performance_optimisation' ) as $contract ) {
			$this->assertStringContainsString( $contract, $source, $contract );
		}
		foreach ( array( 'queued', 'failed' ) as $status ) {
			$this->assertStringContainsString( "'" . $status . "'", $source, $status );
		}
		foreach ( array( 'wppo_ccss_attempts_', 'wppo_ccss_timeout_', 'wppo_ccss_timeout_first_' ) as $key ) {
			$this->assertStringContainsString( $key, $source, $key );
		}
	}

	/**
	 * Ccss_Store projects the canonical owner without a reverse feature edge.
	 *
	 * @return void
	 */
	public function test_store_status_projection_reads_the_new_owner_directly(): void {
		$source = $this->read_source( 'includes/CSS/class-ccss-store.php' );
		$this->assertStringContainsString( 'Ccss_Generator::get_status_cache( $hash )', $source );
		$this->assertStringNotContainsString( 'Critical_CSS::get_status_cache_for_store( $hash )', $source );
		$this->assertTrue( method_exists( Ccss_Store::class, 'set_status_cache_reader' ) );
	}

	/**
	 * The extracted owner stays within the selected lifecycle boundary.
	 *
	 * @return void
	 */
	public function test_owner_does_not_absorb_frontend_purge_or_other_features(): void {
		$source = $this->read_source( 'includes/CSS/class-ccss-generator.php' );
		foreach ( array( 'Image_Optimisation', 'Lcp_Preload', 'LiteSpeed_', 'Object_Cache', 'clear_all(', 'inline_ccss(', 'generate_and_store(' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $source, $forbidden );
		}
	}
}
