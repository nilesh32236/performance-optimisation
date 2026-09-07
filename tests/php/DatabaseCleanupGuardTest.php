<?php
/**
 * Tests for Database_Cleanup::invoke_cleanup_method() callable guard
 * (audit #888 finding 11).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Database_Cleanup;
use Brain\Monkey\Functions;

// phpcs:disable WordPress.Files.FileName -- Pulls in the shared WP_Error test stand-in from TelemetryTest.php.

// The plugin constructs core's WP_Error on error paths; the unit environment
// has no WP core, so reuse the guarded stand-in co-located in TelemetryTest.php
// (require_once keeps it a single declaration whichever file loads first).
if ( ! class_exists( 'WP_Error' ) ) {
	require_once __DIR__ . '/TelemetryTest.php';
}

// WPPO_DB_Mock lives in DatabaseCleanupTest.php; guarded require keeps
// standalone runs of this file working (PHPUnit includes every *Test.php in
// a full-suite run, so the guard only matters for filtered runs).
if ( ! class_exists( 'WPPO_DB_Mock' ) ) {
	require_once __DIR__ . '/DatabaseCleanupTest.php';
}

/**
 * Cleanup-method dispatch guard tests.
 *
 * @package PerformanceOptimise\Tests
 */
class DatabaseCleanupGuardTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Test that invalid method names return a WP_Error instead of fatals.
	 */
	public function test_invalid_method_returns_wp_error(): void {
		$res = Database_Cleanup::invoke_cleanup_method( 'does_not_exist' );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'wppo_invalid_cleanup_method', $res->get_error_code() );
	}

	/**
	 * Test that empty/scalar method names are rejected.
	 */
	public function test_empty_method_returns_wp_error(): void {
		$res = Database_Cleanup::invoke_cleanup_method( '' );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'wppo_invalid_cleanup_method', $res->get_error_code() );
	}

	/**
	 * Provide non-string method values a malformed caller could send.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function invalid_method_provider(): array {
		return array(
			'null'           => array( null ),
			'int'            => array( 123 ),
			'bool'           => array( true ),
			'array'          => array( array( 'clean_auto_drafts' ) ),
			'empty string'   => array( '' ),
			'unknown method' => array( 'does_not_exist' ),
		);
	}

	/**
	 * Test that non-string/unknown method names are rejected.
	 *
	 * @param mixed $method Invalid method value.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'invalid_method_provider' )]
	public function test_invalid_method_values_return_wp_error( $method ): void {
		$res = Database_Cleanup::invoke_cleanup_method( $method );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'wppo_invalid_cleanup_method', $res->get_error_code() );
	}

	/**
	 * Whitelisting must be stricter than is_callable(): real, callable static
	 * methods that are not cleanup methods must be rejected too.
	 */
	public function test_non_whitelisted_callable_is_rejected(): void {
		$res = Database_Cleanup::invoke_cleanup_method( 'get_revision_defaults', array() );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'wppo_invalid_cleanup_method', $res->get_error_code() );
	}

	/**
	 * A whitelisted method dispatches and converts a `false` DB result into
	 * the db_cleanup_failed WP_Error.
	 *
	 * Note: wp_cache_get_salted() is declared by the test bootstrap's
	 * object-cache template and cannot be redefined (Patchwork
	 * DefinedTooEarly); invalidate_counts_cache() therefore takes the
	 * salted-cache path and bumps the salt option.
	 */
	public function test_whitelisted_method_dispatches_and_converts_false(): void {
		// wpdb stub whose DELETE fails (query() → false) so delete_in_batches()
		// returns false and the dispatcher converts it into db_cleanup_failed.
		$GLOBALS['wpdb'] = new class() extends WPPO_DB_Mock {
			/**
			 * Return one row so the delete path is entered.
			 *
			 * @param string $query SQL query (unused).
			 * @return array<int, int>
			 */
			public function get_col( $query = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
				return array( 1 );
			}

			/**
			 * Simulate a failing DELETE.
			 *
			 * @param string $query SQL query (unused).
			 * @return false
			 */
			public function query( $query = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
				return false;
			}
		};

		$this->updated_options = array();
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$this->updated_options[] = $name;
				return true;
			}
		);
		Functions\when( 'is_multisite' )->justReturn( false );

		$res = Database_Cleanup::invoke_cleanup_method( 'clean_auto_drafts' );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'db_cleanup_failed', $res->get_error_code() );
	}

	/**
	 * A successful whitelisted method result must pass through unchanged and
	 * invalidate the counts cache (salted-cache path).
	 */
	public function test_whitelisted_method_result_passes_through(): void {
		$GLOBALS['wpdb']             = new WPPO_DB_Mock();
		$GLOBALS['wpdb']->last_error = '';

		$this->updated_options = array();
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$this->updated_options[] = $name;
				return true;
			}
		);
		Functions\when( 'is_multisite' )->justReturn( false );

		// WPPO_DB_Mock returns no rows → 0 deletions → not false → passes through.
		$res = Database_Cleanup::invoke_cleanup_method( 'clean_auto_drafts' );

		$this->assertSame( 0, $res );
		$this->assertContains( 'wppo_db_cleanup_salt', $this->updated_options, 'Counts cache must be invalidated on successful cleanup.' );
	}

	/**
	 * Option names recorded by the update_option stub.
	 *
	 * @var string[]
	 */
	private array $updated_options = array();
}
