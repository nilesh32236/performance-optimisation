<?php
/**
 * TEMPORARY diagnostic test — delete before finishing.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use Brain\Monkey\Functions;

/**
 * TEMPORARY diagnostic test.
 *
 * @package PerformanceOptimise\Tests
 */
class ZzDiagTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	private function make_cache(): Cache {
		return ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();
	}

	private function invoke_private( $instance, $name, array $args = array() ) {
		$method = new \ReflectionMethod( $instance, $name );
		$method->setAccessible( true );
		return $method->invokeArgs( $instance, $args );
	}

	private function make_temp_css( $bytes ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$file = tempnam( sys_get_temp_dir(), 'wppo-bud-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $file, str_repeat( 'a', $bytes ) );
		return $file;
	}

	public function test_probe(): void {
		Functions\when( 'function_exists' )->justReturn( true );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'update_option' )->justReturn( true );
		$GLOBALS['wpdb']->insert = static function () {
			return 1;
		};
		$GLOBALS['wp_version'] = '6.9';
		fwrite( STDERR, "HAS_INSERT=" . var_export( isset( $GLOBALS['wpdb']->insert ), true ) . " WPDB_CLASS=" . get_class( $GLOBALS['wpdb'] ) . "\n" );

		$file_a = $this->make_temp_css( 8192 );
		$file_b = $this->make_temp_css( 8192 );
		$file_c = $this->make_temp_css( 28672 );

		global $wp_styles;
		$wp_styles             = \Mockery::mock();
		$wp_styles->registered = array(
			'a' => (object) array( 'src' => 'http://example.com/a.css', 'extra' => array() ),
			'b' => (object) array( 'extra' => array() ),
			'c' => (object) array( 'src' => 'http://example.com/c.css', 'extra' => array() ),
		);
		$wp_styles->queue      = array( 'a', 'b', 'c' );
		$paths                 = array( 'a' => $file_a, 'b' => $file_b, 'c' => $file_c );
		$wp_styles->shouldReceive( 'get_data' )->with( \Mockery::any(), 'path' )->andReturnUsing(
			static function ( $handle ) use ( $paths ) {
				return $paths[ $handle ] ?? null;
			}
		);

		$cache = $this->make_cache();
		try {
			fwrite( STDERR, "PRE1=" . var_export( $this->invoke_private( $cache, 'core_inline_budget_will_inline', array( 'c', 40000, false ) ), true ) . "\n" );
			fwrite( STDERR, "PRE2=" . var_export( $this->invoke_private( $cache, 'core_inline_budget_will_inline', array( 'c', 40000, true ) ), true ) . "\n" );
			fwrite( STDERR, "HAS_INSERT2=" . var_export( isset( $GLOBALS['wpdb']->insert ), true ) . "\n" );
			$res = $this->invoke_private( $cache, 'core_will_inline', array( 'c' ) );
			fwrite( STDERR, "\nRESULT=" . var_export( $res, true ) . "\n" );
		} catch ( \Throwable $e ) {
			fwrite( STDERR, "\nTHROWN: " . get_class( $e ) . ': ' . $e->getMessage() . "\n" );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $file_a );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $file_b );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $file_c );
		$this->addToAssertionCount( 1 );
	}
}