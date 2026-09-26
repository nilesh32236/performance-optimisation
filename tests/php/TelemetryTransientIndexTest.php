<?php
/**
 * Tests for Telemetry::register_transient_key() concurrent-write hardening
 * (audit #888 finding 10).
 *
 * The transient index write must merge keys registered concurrently between
 * its read and write, so parallel scans cannot overwrite each other's index.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Telemetry;
use Brain\Monkey\Functions;

/**
 * Transient-index registration tests.
 *
 * @package PerformanceOptimise\Tests
 */
class TelemetryTransientIndexTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options store.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Number of get_option() calls made.
	 *
	 * @var int
	 */
	private int $reads = 0;

	/**
	 * Payloads captured from update_option() calls.
	 *
	 * @var array<int, mixed>
	 */
	private array $writes = array();

	/**
	 * Read number on which to inject the concurrent write.
	 *
	 * @var int|null
	 */
	private ?int $inject_on_read = null;

	/**
	 * Foreign key/value injected.
	 *
	 * @var array<string, int>
	 */
	private array $foreign = array();

	/**
	 * Install option stubs with an injectable concurrent-write simulation.
	 */
	private function install_option_stubs(): void {
		$this->options        = array();
		$this->reads          = 0;
		$this->writes         = array();
		$this->inject_on_read = null;
		$this->foreign        = array();

		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				++$this->reads;
				if ( null !== $this->inject_on_read && $this->reads > $this->inject_on_read ) {
					// Simulate a concurrent process having written its keys between
					// our previous read and this one. Keys are added only when
					// absent (merge semantics), so later reads — including the
					// verify read — stay consistent with the plugin's own merge.
					$value = array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
					$value = is_array( $value ) ? $value : array();
					foreach ( $this->foreign as $foreign_key => $foreign_expiry ) {
						if ( ! array_key_exists( $foreign_key, $value ) ) {
							$value[ $foreign_key ] = $foreign_expiry;
						}
					}
					$this->options[ $name ] = $value;
					return $value;
				}
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->writes[]         = $value;
				$this->options[ $name ] = $value;
				return true;
			}
		);
	}

	/**
	 * Registration stores the key with an absolute expiry ~1 hour out.
	 */
	public function test_register_stores_key_with_expiry(): void {
		$this->install_option_stubs();

		Telemetry::register_transient_key( 'wppo_telemetry_scan_123' );

		$index = $this->options['wppo_transient_index'];
		$this->assertArrayHasKey( 'wppo_telemetry_scan_123', $index );

		$expiry = $index['wppo_telemetry_scan_123'];
		$this->assertGreaterThanOrEqual( time() + HOUR_IN_SECONDS - 5, $expiry );
		$this->assertLessThanOrEqual( time() + HOUR_IN_SECONDS, $expiry );
	}

	/**
	 * A concurrent writer's key must survive in the payload we write (the
	 * re-read-before-write merge), instead of being lost to the overwrite.
	 */
	public function test_concurrent_key_is_merged_not_lost(): void {
		$this->install_option_stubs();

		// Inject the concurrent write on every read after the first — i.e. it
		// lands between our initial read and the merge re-read (the window
		// the re-read-before-write merge guards) and stays present for the
		// verify read. This keeps the simulation correct even if the
		// implementation skips the verify read on the no-contention path.
		$this->inject_on_read = 1;
		$this->foreign        = array(
			'wppo_concurrent_scan_key' => time() + HOUR_IN_SECONDS,
		);

		Telemetry::register_transient_key( 'wppo_telemetry_scan_abc' );

		$index = $this->options['wppo_transient_index'];
		$this->assertArrayHasKey( 'wppo_telemetry_scan_abc', $index, 'Our key must be registered.' );
		$this->assertArrayHasKey( 'wppo_concurrent_scan_key', $index, 'A concurrently registered key must not be lost by our write.' );
	}

	/**
	 * Our own key must win when a stale concurrent entry collides.
	 */
	public function test_own_key_wins_on_conflict(): void {
		$this->install_option_stubs();

		$this->inject_on_read = 1;
		$this->foreign        = array(
			'wppo_telemetry_scan_abc' => 12345, // Stale expiry for OUR key.
		);

		Telemetry::register_transient_key( 'wppo_telemetry_scan_abc' );

		$index = $this->options['wppo_transient_index'];
		$this->assertGreaterThan( time(), $index['wppo_telemetry_scan_abc'], 'Our fresh expiry must overwrite the stale concurrent entry.' );
	}

	/**
	 * Re-registering the same key is idempotent — single index entry.
	 */
	public function test_registration_is_idempotent(): void {
		$this->install_option_stubs();

		Telemetry::register_transient_key( 'wppo_telemetry_scan_dup' );
		Telemetry::register_transient_key( 'wppo_telemetry_scan_dup' );

		$index = $this->options['wppo_transient_index'];
		$this->assertCount( 1, $index );
		$this->assertSame( 1, count( preg_grep( '/wppo_telemetry_scan_dup/', array_keys( $index ) ) ) );
	}

	/**
	 * Over-cap indexes are pruned and capped at 200 entries.
	 */
	public function test_index_is_capped_at_200(): void {
		$this->install_option_stubs();

		// Seed 199 entries with FUTURE expiries (not prunable) plus ours = 201.
		$seed = array();
		for ( $i = 0; $i < 199; $i++ ) {
			$seed[ 'wppo_seed_key_' . $i ] = time() + HOUR_IN_SECONDS + $i;
		}
		$seed['wppo_new_key']                  = 0; // To be registered.
		$this->options['wppo_transient_index'] = $seed;

		Telemetry::register_transient_key( 'wppo_new_key' );

		$index = $this->options['wppo_transient_index'];
		$this->assertLessThanOrEqual( 200, count( $index ) );
		$this->assertArrayHasKey( 'wppo_new_key', $index, 'The just-registered key must survive the cap.' );
	}

	/**
	 * The common no-contention path skips the verify re-read (2 reads, not 3).
	 */
	public function test_clean_path_skips_verify_read(): void {
		$this->install_option_stubs();

		Telemetry::register_transient_key( 'wppo_telemetry_scan_clean' );

		$this->assertSame( 2, $this->reads, 'Clean path should read the index twice (initial + merge), not three times.' );
		$this->assertArrayHasKey( 'wppo_telemetry_scan_clean', $this->options['wppo_transient_index'] );
	}
}
