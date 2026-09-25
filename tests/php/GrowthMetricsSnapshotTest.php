<?php
/**
 * Tests for the Phase G evidence-based growth monitoring snapshots.
 *
 * Validates the committed growth snapshot (public sources only) and the
 * accompanying METRICS.md contract without touching runtime behavior.
 *
 * @package PerformanceOptimise\Tests
 * @since   NEXT
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Test-only local JSON reads.

use PHPUnit\Framework\TestCase;

/**
 * Validate growth metrics snapshots and documentation thresholds.
 *
 * @since NEXT
 */
final class GrowthMetricsSnapshotTest extends TestCase {

	/**
	 * Required top-level snapshot keys (schema contract).
	 *
	 * @var string[]
	 */
	private const REQUIRED_KEYS = array(
		'schema',
		'generated_at',
		'slug',
		'repository',
		'privacy',
		'wordpress_org',
		'github',
		'repository_evidence',
		'coverage',
		'operational',
		'limits',
	);

	/**
	 * Features that must appear in the coverage matrix.
	 *
	 * @var string[]
	 */
	private const REQUIRED_FEATURES = array(
		'Page Cache',
		'CSS',
		'JS',
		'HTML',
		'Images',
		'WebP',
		'AVIF',
		'Lazy Load',
		'Core Web Vitals',
		'PageSpeed',
		'RUM',
		'Redis',
		'Database',
		'WooCommerce',
		'LiteSpeed',
		'CDN',
		'Critical CSS',
		'Used CSS',
	);

	/**
	 * Key fragments that must never appear (no visitor/site tracking).
	 *
	 * @var string[]
	 */
	private const FORBIDDEN_KEY_FRAGMENTS = array(
		'visitor',
		'cookie',
		'email',
		'user_id',
		'password',
		'bearer',
		'session_id',
		'auth_token',
		'api_key',
	);

	/**
	 * Threshold markers that METRICS.md must document.
	 *
	 * @var string[]
	 */
	private const REQUIRED_THRESHOLD_MARKERS = array(
		'T1',
		'T2',
		'T3',
		'T4',
		'T5',
		'T6',
		'causality',
		'unavailable',
	);

	/**
	 * Committed latest.json validates against the required contract.
	 *
	 * @return void
	 */
	public function test_latest_snapshot_validates(): void {
		$snapshot = $this->read_growth_json( 'latest.json' );

		foreach ( self::REQUIRED_KEYS as $key ) {
			$this->assertArrayHasKey( $key, $snapshot, "Snapshot is missing required key {$key}." );
		}

		$this->assertSame( 'performance-optimisation', $snapshot['slug'] );
		$this->assertNotEmpty( $snapshot['generated_at'] );
		$this->assertNotEmpty( $snapshot['privacy'] );
		$this->assertIsArray( $snapshot['wordpress_org'] );
		$this->assertIsArray( $snapshot['github'] );
		$this->assertIsArray( $snapshot['repository_evidence'] );
		$this->assertIsArray( $snapshot['coverage'] );
		$this->assertGreaterThanOrEqual( 17, count( $snapshot['coverage'] ) );
		$this->assertNotEmpty( $snapshot['limits'] );

		// Unavailable is an allowed value, never a missing key.
		$this->assertArrayHasKey( 'status', $snapshot['wordpress_org'] );
		$this->assertArrayHasKey( 'status', $snapshot['github'] );
	}

	/**
	 * Coverage lists every required feature with a valid status.
	 *
	 * @return void
	 */
	public function test_coverage_lists_all_features(): void {
		$snapshot = $this->read_growth_json( 'latest.json' );

		$by_feature = array();
		foreach ( $snapshot['coverage'] as $entry ) {
			$this->assertArrayHasKey( 'feature', $entry );
			$this->assertArrayHasKey( 'status', $entry );
			$this->assertContains( $entry['status'], array( 'covered', 'gap' ) );
			$by_feature[ $entry['feature'] ] = $entry;
		}

		foreach ( self::REQUIRED_FEATURES as $feature ) {
			$this->assertArrayHasKey( $feature, $by_feature, "Coverage is missing feature {$feature}." );
		}
	}

	/**
	 * Snapshots contain no visitor, owner, or authenticated site data.
	 *
	 * @return void
	 */
	public function test_snapshot_contains_no_tracking_fields(): void {
		$snapshot = $this->read_growth_json( 'latest.json' );
		$keys     = $this->collect_keys( $snapshot );

		foreach ( $keys as $key ) {
			foreach ( self::FORBIDDEN_KEY_FRAGMENTS as $fragment ) {
				$this->assertStringNotContainsString(
					$fragment,
					strtolower( (string) $key ),
					"Snapshot key '{$key}' suggests tracked data."
				);
			}
		}
	}

	/**
	 * METRICS.md documents baseline, thresholds, and interpretation limits.
	 *
	 * @return void
	 */
	public function test_metrics_doc_records_thresholds_and_limits(): void {
		$path = dirname( __DIR__, 2 ) . '/docs/growth/METRICS.md';
		$this->assertFileExists( $path );
		$contents = file_get_contents( $path );
		$this->assertNotFalse( $contents );

		foreach ( self::REQUIRED_THRESHOLD_MARKERS as $marker ) {
			$this->assertStringContainsString(
				$marker,
				(string) $contents,
				"METRICS.md is missing threshold marker '{$marker}'."
			);
		}
	}

	/**
	 * Collector script exists, is executable, and degrades to unavailable.
	 *
	 * @return void
	 */
	public function test_collector_script_is_present_and_graceful(): void {
		$path = dirname( __DIR__, 2 ) . '/scripts/collect-growth-metrics.sh';
		$this->assertFileExists( $path );
		$this->assertTrue( is_executable( $path ), 'Collector must be executable.' );

		$contents = file_get_contents( $path );
		$this->assertNotFalse( $contents );
		$this->assertStringContainsString( 'unavailable', (string) $contents );
		$this->assertStringContainsString( 'exit 0', (string) $contents );
	}

	/**
	 * Read one committed growth metrics JSON artifact.
	 *
	 * @since NEXT
	 * @param string $filename Artifact filename.
	 * @return array<string|int,mixed>
	 */
	private function read_growth_json( string $filename ): array {
		$path = dirname( __DIR__, 2 ) . '/docs/growth/metrics/' . $filename;
		$this->assertFileExists( $path );
		$raw = file_get_contents( $path );
		$this->assertNotFalse( $raw );
		$data = json_decode( (string) $raw, true );
		$this->assertIsArray( $data );

		return $data;
	}

	/**
	 * Collect all array keys recursively for tracking-field scans.
	 *
	 * @since NEXT
	 * @param mixed $data Decoded snapshot data.
	 * @return string[]
	 */
	private function collect_keys( $data ): array {
		$keys = array();
		if ( ! is_array( $data ) ) {
			return $keys;
		}
		foreach ( $data as $key => $value ) {
			$keys[] = (string) $key;
			$keys   = array_merge( $keys, $this->collect_keys( $value ) );
		}

		return $keys;
	}
}
