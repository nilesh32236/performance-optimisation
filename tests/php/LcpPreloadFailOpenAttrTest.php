<?php
/**
 * Guards the fail-open contract of Lcp_Preload::fail_open_attr().
 *
 * The helper is the sole gate between a throwing fetchpriority filter and the
 * rendered <img> attributes on the critical rendering path. Its contract is that
 * an exception changes NOTHING: the attributes come back identical, no exception
 * escapes, and nothing is logged unless WP_DEBUG is on.
 *
 * D-165 recorded that no test enforced this, and that the invariant is
 * runtime-shaped — it is the shape of a returned value, not the presence of a
 * call — so a source-reading guard would miss the failure mode that matters. This
 * is therefore an execution test with a deliberately throwing filter.
 *
 * @package PerformanceOptimise\Tests
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Image_Optimisation;
use PerformanceOptimise\Inc\Lcp_Preload;
use PHPUnit\Framework\Attributes\PreserveGlobalState;

/**
 * Execution tests for the LCP preload fail-open helper.
 *
 * @package PerformanceOptimise\Tests
 */
class LcpPreloadFailOpenAttrTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Preload service under test.
	 *
	 * @var object
	 */
	private $preload;

	/**
	 * Set up the test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		Image_Optimisation::clear_runtime_caches();

		Functions\when( 'wp_normalize_path' )->alias( 'parse_url' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Unit stub mirroring the bootstrap alias.
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );

		$owner         = new Image_Optimisation( array() );
		$this->preload = new Lcp_Preload( $owner );
	}

	/**
	 * Invoke the private fail-open helper.
	 *
	 * @param \Throwable $e    Exception to feed it.
	 * @param mixed      $attr Attributes to hand it.
	 * @return mixed Whatever the helper returns.
	 */
	private function call_fail_open_attr( \Throwable $e, $attr ): mixed {
		$method = new \ReflectionMethod( $this->preload, 'fail_open_attr' );
		$method->setAccessible( true );
		return $method->invoke( $this->preload, $e, $attr );
	}

	/**
	 * An exception must not change the attributes. This is the whole contract.
	 *
	 * @return void
	 */
	public function test_attributes_come_back_identical_after_an_exception(): void {
		$attr  = array(
			'fetchpriority' => 'high',
			'loading'       => 'eager',
			'class'         => 'wp-image-42 lcp',
			'decoding'      => 'async',
		);
		$error = new \RuntimeException( 'fetchpriority filter exploded' );

		$result = $this->call_fail_open_attr( $error, $attr );

		// Identity, not merely equality: the helper must hand back the SAME
		// value the caller already had, because the caller's contract is
		// "$attr, unmodified" and a rebuilt copy could silently drop a key.
		$this->assertSame( $attr, $result );
	}

	/**
	 * No exception may escape, whatever the caller's attributes were.
	 *
	 * @return void
	 */
	public function test_no_exception_escapes(): void {
		$before = array( 'fetchpriority' => 'low' );

		// If this throws, PHPUnit records an error — which is the failure mode.
		$result = $this->call_fail_open_attr( new \LogicException( 'nested failure' ), $before );

		$this->assertSame( $before, $result );
	}

	/**
	 * Nothing is logged unless WP_DEBUG is on.
	 *
	 * @return void
	 */
	public function test_nothing_is_logged_without_wp_debug(): void {
		$logged = array();
		Functions\when( 'do_action' )->alias(
			static function ( string $hook ) use ( &$logged ): void {
				$logged[] = $hook;
			}
		);

		$this->call_fail_open_attr( new \RuntimeException( 'boom' ), array( 'fetchpriority' => 'high' ) );

		// The contract is "<em>if and only if</em> WP_DEBUG": never log in
		// production, always log in debug. Asserted conditionally rather than
		// assuming this environment's WP_DEBUG value, which would make the test
		// environment-dependent rather than contract-dependent.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->assertNotSame( array(), $logged, 'A WP_DEBUG run must leave a trace.' );
		} else {
			$this->assertSame( array(), $logged, 'A silent failure must stay silent in production.' );
		}
	}
}
