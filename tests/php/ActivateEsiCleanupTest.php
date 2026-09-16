<?php
/**
 * Tests for the one-time orphaned ESI secret cleanup (issue #1291).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Activate;
use Brain\Monkey\Functions;

/**
 * Verifies Activate::maybe_delete_orphaned_esi_secret() deletes the leftover
 * option once (flag-guarded) and never fatals when the option API throws.
 *
 * @package PerformanceOptimise\Tests
 */
class ActivateEsiCleanupTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Set up Brain Monkey session with common stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
	}

	/**
	 * Tear down Brain Monkey session.
	 */
	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The upgrade migration must delete the orphaned ESI fallback secret once.
	 */
	public function test_deletes_orphaned_esi_secret(): void {
		$deleted = array();
		$updated = array();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Test mock signature must match get_option().
				if ( 'wppo_esi_secret_cleaned' === $name ) {
					return false;
				}
				return $fallback;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$deleted ) {
				$deleted[] = $name;
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value = null, $autoload = null ) use ( &$updated ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Test mock signature must match update_option().
				$updated[] = $name;
				return true;
			}
		);
		$this->assertTrue( Activate::maybe_delete_orphaned_esi_secret() );
		$this->assertContains( 'wppo_esi_fallback_secret', $deleted );
		$this->assertContains( 'wppo_esi_secret_cleaned', $updated );
	}

	/**
	 * A second run must be a no-op (flag guard): no DELETE is issued.
	 */
	public function test_skips_when_already_cleaned(): void {
		$deleted = array();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Test mock signature must match get_option().
				if ( 'wppo_esi_secret_cleaned' === $name ) {
					return 1;
				}
				return $fallback;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$deleted ) {
				$deleted[] = $name;
				return true;
			}
		);
		$this->assertTrue( Activate::maybe_delete_orphaned_esi_secret() );
		$this->assertSame( array(), $deleted );
	}

	/**
	 * A throwing delete_option must not bubble (fail-open upgrade).
	 */
	public function test_fail_open_when_delete_throws(): void {
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'delete_option' )->alias(
			static function () { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Test mock throws regardless of args.
				throw new \RuntimeException( 'db down' );
			}
		);
		Functions\when( 'update_option' )->justReturn( true );
		$this->assertTrue( Activate::maybe_delete_orphaned_esi_secret() );
	}
}
