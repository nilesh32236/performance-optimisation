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
 * option and never fatals when the option API is unavailable.
 *
 * @package PerformanceOptimise\Tests
 */
class ActivateEsiCleanupTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * The upgrade migration must delete the orphaned ESI fallback secret.
	 */
	public function test_deletes_orphaned_esi_secret(): void {
		$deleted = array();
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$deleted ) {
				$deleted[] = $name;
				return true;
			}
		);
		Activate::maybe_delete_orphaned_esi_secret();
		$this->assertContains( 'wppo_esi_fallback_secret', $deleted );
	}
}
