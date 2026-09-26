<?php
/**
 * Characterization and reset tests for Bfcache request state.
 *
 * @package PerformanceOptimise\Tests
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Bfcache;

/**
 * Bfcache request-state lifecycle tests.
 *
 * @package PerformanceOptimise\Tests
 */
class BfcacheRequestStateTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Reset state before each characterization test.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		Bfcache::reset_runtime_state();
	}

	/**
	 * Tear down Brain Monkey and leave the process clean for the next test.
	 */
	protected function tearDown(): void {
		Bfcache::reset_runtime_state();
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Printing clears the staged script but keeps duplicate suppression for
	 * the remainder of the same request.
	 */
	public function test_print_is_one_shot_until_request_reset(): void {
		$printed = array();
		Functions\when( 'wp_print_inline_script_tag' )->alias(
			static function ( $javascript, $attributes = array() ) use ( &$printed ): void {
				$printed[] = array( $javascript, $attributes );
			}
		);

		$this->set_static_property( 'invalidation_script', 'window.token = "abc";' );
		$this->set_static_property( 'script_staged', true );

		Bfcache::print_invalidation_script();
		Bfcache::print_invalidation_script();

		$this->assertCount( 1, $printed );
		$this->assertSame( 'window.token = "abc";', $printed[0][0] );
		$this->assertSame( array( 'id' => 'wppo-bfcache-invalidation' ), $printed[0][1] );
		$this->assertTrue( $this->get_static_property( 'script_staged' ) );
	}

	/**
	 * The request-boundary reset clears both the token-bearing script and the
	 * per-request duplicate guard. The test helper remains an alias for BC.
	 */
	public function test_runtime_reset_clears_request_state(): void {
		$this->set_static_property( 'invalidation_script', 'window.token = "abc";' );
		$this->set_static_property( 'script_staged', true );

		Bfcache::reset_state_for_tests();

		$this->assertSame( '', $this->get_static_property( 'invalidation_script' ) );
		$this->assertFalse( $this->get_static_property( 'script_staged' ) );
	}

	/**
	 * The production lifecycle registers the reset on WordPress shutdown, so
	 * REST and cron requests are covered even though they do not print scripts.
	 */
	public function test_init_registers_shutdown_reset(): void {
		$source = file_get_contents( WPPO_PLUGIN_PATH . 'includes/Cache/class-bfcache.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static source seam.

		$this->assertIsString( $source );
		$this->assertStringContainsString(
			"add_action( 'shutdown', array( self::class, 'reset_runtime_state' ), PHP_INT_MAX )",
			(string) $source
		);
	}

	/**
	 * Set one private static request-state property without deprecated reflection APIs.
	 *
	 * @param string $name Property name.
	 * @param mixed  $value Value to set.
	 */
	private function set_static_property( string $name, $value ): void {
		$property = new \ReflectionProperty( Bfcache::class, $name );
		$property->setValue( null, $value );
	}

	/**
	 * Read one private static request-state property.
	 *
	 * @param string $name Property name.
	 * @return mixed Property value.
	 */
	private function get_static_property( string $name ) {
		$property = new \ReflectionProperty( Bfcache::class, $name );
		return $property->getValue();
	}
}
