<?php
/**
 * Tests for the RUM-driven guided next action (issue #1368).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Suggestion_Engine;

/**
 * Covers Suggestion_Engine::from_rum() (site-wide averaging, thresholds,
 * malformed-input tolerance) and ::pick_rum_next_action() (single worst
 * offender, poor-beats-needs-improvement, LCP > INP > CLS tie-break).
 *
 * @package PerformanceOptimise\Tests
 */
class RumNextActionTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Build a RUM aggregate fixture for one metric.
	 *
	 * @param string $metric Metric name.
	 * @param float  $avg    Desired average value.
	 * @return array Aggregate fixture.
	 */
	private function aggregate_for( string $metric, float $avg ): array {
		return array(
			'2026-09-16' => array(
				'/' => array(
					$metric => array(
						'n'   => 10,
						'sum' => $avg * 10,
					),
				),
			),
		);
	}

	/**
	 * Empty/malformed aggregates must yield no suggestions (fail-open).
	 */
	public function test_from_rum_empty_and_malformed_input(): void {
		$this->assertSame( array(), Suggestion_Engine::from_rum( array() ) );
		$this->assertNull( Suggestion_Engine::pick_rum_next_action( array() ) );
		$this->assertSame( array(), Suggestion_Engine::from_rum( array( 'not-a-day' => 'junk' ) ) );
		$this->assertNull( Suggestion_Engine::pick_rum_next_action( array( '2026-09-16' => array( '/' => array( 'lcp' => 'junk' ) ) ) ) );
	}

	/**
	 * All-green RUM metrics must yield no next action.
	 */
	public function test_all_green_rum_yields_no_next_action(): void {
		$aggregate = array(
			'2026-09-16' => array(
				'/' => array(
					'lcp' => array(
						'n'   => 10,
						'sum' => 20000.0,
					),
					'inp' => array(
						'n'   => 10,
						'sum' => 1500.0,
					),
					'cls' => array(
						'n'   => 10,
						'sum' => 0.5,
					),
				),
			),
		);

		$this->assertSame( array(), Suggestion_Engine::from_rum( $aggregate ) );
		$this->assertNull( Suggestion_Engine::pick_rum_next_action( $aggregate ) );
	}

	/**
	 * A poor LCP must surface as the next action with the image-tab fix action.
	 */
	public function test_poor_lcp_becomes_next_action(): void {
		$next = Suggestion_Engine::pick_rum_next_action( $this->aggregate_for( 'lcp', 4500.0 ) );

		$this->assertIsArray( $next );
		$this->assertSame( 'rum_lcp', $next['metric'] );
		$this->assertSame( 'poor', $next['status'] );
		$this->assertSame( 'open_image_optimization_tab', $next['fix_action'] );
		$this->assertSame( array( 'metric', 'value', 'unit', 'status', 'description', 'fix_action' ), array_keys( $next ), 'Next action must carry all 6 suggestion fields' );
	}

	/**
	 * Poor beats needs-improvement regardless of metric priority.
	 */
	public function test_poor_beats_needs_improvement(): void {
		$aggregate = array(
			'2026-09-16' => array(
				'/' => array(
					'lcp' => array(
						'n'   => 10,
						'sum' => 30000.0,
					),
					'inp' => array(
						'n'   => 10,
						'sum' => 8000.0,
					),
				),
			),
		);

		$next = Suggestion_Engine::pick_rum_next_action( $aggregate );

		$this->assertIsArray( $next );
		$this->assertSame( 'rum_inp', $next['metric'], 'Poor INP must win over needs-improvement LCP' );
		$this->assertSame( 'poor', $next['status'] );
	}

	/**
	 * Ties on status must break LCP > INP > CLS.
	 */
	public function test_tie_break_prefers_lcp_then_inp(): void {
		$aggregate = array(
			'2026-09-16' => array(
				'/' => array(
					'cls' => array(
						'n'   => 10,
						'sum' => 5.0,
					),
					'inp' => array(
						'n'   => 10,
						'sum' => 8000.0,
					),
					'lcp' => array(
						'n'   => 10,
						'sum' => 50000.0,
					),
				),
			),
		);

		$suggestions = Suggestion_Engine::from_rum( $aggregate );
		$this->assertCount( 3, $suggestions, 'All three poor vitals must be listed' );

		$next = Suggestion_Engine::pick_rum_next_action( $aggregate );
		$this->assertSame( 'rum_lcp', $next['metric'], 'LCP must win ties' );
	}

	/**
	 * Samples must average site-wide across days and paths.
	 */
	public function test_averages_across_days_and_paths(): void {
		$aggregate = array(
			'2026-09-15' => array(
				'/'      => array(
					'lcp' => array(
						'n'   => 10,
						'sum' => 20000.0,
					),
				),
				'/about' => array(
					'lcp' => array(
						'n'   => 10,
						'sum' => 60000.0,
					),
				),
			),
		);

		// Site-wide average is 4000ms: at the poor boundary is still
		// needs_improvement (only > poor flips to poor).
		$next = Suggestion_Engine::pick_rum_next_action( $aggregate );
		$this->assertIsArray( $next );
		$this->assertSame( 'rum_lcp', $next['metric'] );
		$this->assertSame( 'needs_improvement', $next['status'] );
		$this->assertEqualsWithDelta( 4000.0, $next['value'], 0.001 );
	}
}
