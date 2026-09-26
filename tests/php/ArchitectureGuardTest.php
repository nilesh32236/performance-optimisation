<?php
/**
 * Focused coverage for deterministic Phase 3 architecture guards.
 *
 * @package PerformanceOptimise\Tests
 * @since   NEXT
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Test-only local JSON reads.

require_once dirname( __DIR__, 2 ) . '/scripts/architecture/class-architecture-guards.php';

use PerformanceOptimise\Architecture\Architecture_Guards;
use PHPUnit\Framework\TestCase;

/**
 * Prove current generated evidence passes and fixture violations fail.
 *
 * @since NEXT
 */
final class ArchitectureGuardTest extends TestCase {

	/**
	 * The generated inventory and graph satisfy all three guards.
	 *
	 * @return void
	 */
	public function test_current_generated_evidence_passes_all_guards(): void {
		$inventory = $this->read_json( 'class-inventory.json' );
		$graph     = $this->read_json( 'DEPENDENCY-GRAPH.json' );
		$result    = ( new Architecture_Guards() )->evaluate( $inventory, $graph );

		$this->assertSame( 'pass', $result['status'], (string) json_encode( $result['violations'] ) );
		$this->assertSame( 20, $result['summary']['boundary_edges']['checked'] );
		$this->assertSame( 11, $result['summary']['schedule_owners']['checked'] );
		$this->assertSame( 33, $result['summary']['static_state_owners']['checked'] );
		$this->assertSame( 0, $result['summary']['boundary_edges']['violations'] );
		$this->assertSame( 0, $result['summary']['schedule_owners']['violations'] );
		$this->assertSame( 0, $result['summary']['static_state_owners']['violations'] );
	}

	/**
	 * A new boundary-direction finding fails the boundary guard.
	 *
	 * @return void
	 */
	public function test_boundary_fixture_violation_fails(): void {
		$graph                                     = $this->read_json( 'DEPENDENCY-GRAPH.json' );
		$fixture                                   = $this->read_fixture();
		$graph['metrics']['boundary_violations'][] = array(
			'from'   => $fixture['boundary_edge']['from'],
			'to'     => $fixture['boundary_edge']['to'],
			'reason' => $fixture['boundary_edge']['reason'],
		);

		$violations = ( new Architecture_Guards() )->boundary_violations( $graph );

		$this->assertCount( 1, $violations );
		$this->assertSame( 'forbidden_boundary_edge', $violations[0]['code'] );
		$this->assertSame( 'PerformanceOptimise\\Inc\\Rest -> PerformanceOptimise\\Inc\\Cache', $violations[0]['subject'] );
	}

	/**
	 * A new schedule caller fails the schedule-owner guard.
	 *
	 * @return void
	 */
	public function test_schedule_fixture_violation_fails(): void {
		$graph   = $this->read_json( 'DEPENDENCY-GRAPH.json' );
		$fixture = $this->read_fixture();
		$node    = $fixture['schedule']['node'];
		$graph['nodes'][ $node ]['references']['external_functions'][ $fixture['schedule']['function'] ] = 42;

		$violations = ( new Architecture_Guards() )->schedule_violations( $graph );

		$this->assertCount( 1, $violations );
		$this->assertSame( 'unowned_schedule', $violations[0]['code'] );
		$this->assertSame( $node, $violations[0]['subject'] );
	}

	/**
	 * A new static-state owner without a classification fails the static guard.
	 *
	 * @return void
	 */
	public function test_static_state_fixture_violation_fails(): void {
		$inventory                         = $this->read_json( 'class-inventory.json' );
		$graph                             = $this->read_json( 'DEPENDENCY-GRAPH.json' );
		$fixture                           = $this->read_fixture();
		$static                            = $fixture['static_state'];
		$inventory[]                       = array(
			'file'         => $static['file'],
			'fqcn'         => $static['fqcn'],
			'static_state' => true,
		);
		$graph['nodes'][ $static['fqcn'] ] = array(
			'id'           => $static['fqcn'],
			'fqcn'         => $static['fqcn'],
			'file'         => $static['file'],
			'static_state' => true,
		);

		$violations = ( new Architecture_Guards() )->static_state_violations( $inventory, $graph );

		$this->assertCount( 1, $violations );
		$this->assertSame( 'unexplained_static_state_owner', $violations[0]['code'] );
		$this->assertSame( $static['fqcn'], $violations[0]['subject'] );
	}

	/**
	 * Read a committed architecture artifact.
	 *
	 * @since NEXT
	 * @param string $filename Artifact filename.
	 * @return array<string|int,mixed>
	 */
	private function read_json( string $filename ): array {
		$path = dirname( __DIR__, 2 ) . '/docs/architecture/' . $filename;
		$this->assertFileExists( $path );
		$raw = file_get_contents( $path );
		$this->assertNotFalse( $raw );
		$data = json_decode( (string) $raw, true, 512, JSON_THROW_ON_ERROR );
		$this->assertIsArray( $data );

		return $data;
	}

	/**
	 * Read the explicit guard violation fixture.
	 *
	 * @since NEXT
	 * @return array<string,mixed>
	 */
	private function read_fixture(): array {
		$path = __DIR__ . '/fixtures/architecture-guard-violations.json';
		$this->assertFileExists( $path );
		$raw = file_get_contents( $path );
		$this->assertNotFalse( $raw );
		$data = json_decode( (string) $raw, true, 512, JSON_THROW_ON_ERROR );
		$this->assertIsArray( $data );

		return $data;
	}
}
