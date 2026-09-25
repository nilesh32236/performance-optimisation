/**
 * Scoring tests (black-box over node evals/score.mjs).
 *
 * Covers acceptance item 3: the champion stays until executable evidence
 * justifies promotion (sample gates + hysteresis band).
 */
'use strict';

const { execFileSync } = require( 'node:child_process' );
const fs = require( 'node:fs' );
const os = require( 'node:os' );
const path = require( 'node:path' );

const SCORE = path.resolve( __dirname, 'score.mjs' );
const REGISTRY = path.resolve( __dirname, 'registry.json' );

const AREAS = [
	'typescript',
	'javascript',
	'php',
	'wordpress',
	'react',
	'github-actions',
	'refactoring',
	'bugfix',
	'security',
	'tests',
	'documentation',
];

function verdictFor( championResults, challengerResults ) {
	const dir = fs.mkdtempSync( path.join( os.tmpdir(), 'wppo-score-' ) );
	const championFile = path.join( dir, 'champion.json' );
	fs.writeFileSync( championFile, JSON.stringify( { results: championResults } ) );
	const args = [ SCORE, '--results', championFile, '--registry', REGISTRY ];
	if ( challengerResults ) {
		const challengerFile = path.join( dir, 'challenger.json' );
		fs.writeFileSync( challengerFile, JSON.stringify( { results: challengerResults } ) );
		args.push( '--challenger', 'opencode/challenger-free' );
		args.push( '--challenger-results', challengerFile );
	}
	const stdout = execFileSync( process.execPath, args, {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} );
	return JSON.parse( stdout );
}

function passingResults( areas = AREAS ) {
	return areas.map( ( area, index ) => ( {
		fixtureId: `${ area }-fixture`,
		area,
		model: 'model-under-test',
		latencyMs: 1 + index,
		timedOut: false,
		retries: 0,
		toolCalls: 0,
		verification: { passed: true, checksTotal: 2, checksFailed: [] },
		lint: { status: 'not-run' },
		build: { status: 'not-run' },
		security: { status: 'not-run', findings: [] },
		error: null,
	} ) );
}

function failingResults() {
	return passingResults().map( ( result ) => ( {
		...result,
		verification: { passed: false, checksTotal: 2, checksFailed: [ 'c1' ] },
		error: 'checks failed: c1',
	} ) );
}

describe( 'evals/score', () => {
	it( 'retains the champion on champion-only evidence', () => {
		const verdict = verdictFor( passingResults(), null );
		expect( verdict.verdict ).toBe( 'retain-champion' );
		expect( verdict.champion.gatesPassed ).toBe( true );
	} );

	it( 'reports insufficient evidence when areas are missing', () => {
		const verdict = verdictFor( passingResults( AREAS.slice( 0, 4 ) ), passingResults( AREAS.slice( 0, 4 ) ) );
		expect( verdict.verdict ).toBe( 'insufficient-evidence' );
	} );

	it( 'holds the hysteresis band against a marginally better challenger', () => {
		// Challenger identical to champion: margin 0 stays inside the band.
		const verdict = verdictFor( passingResults(), passingResults() );
		expect( verdict.verdict ).toBe( 'retain-champion' );
		expect( verdict.margin ).toBeCloseTo( 0, 5 );
	} );

	it( 'promotes only on executable evidence above the hysteresis margin', () => {
		// Champion fails everywhere (smoothed floor), challenger passes
		// everywhere: margin must clear the 0.05 band.
		const verdict = verdictFor( failingResults(), passingResults() );
		expect( verdict.verdict ).toBe( 'promote-challenger' );
		expect( verdict.margin ).toBeGreaterThan( 0.05 );
	} );

	it( 'reports insufficient evidence when no results file exists', () => {
		const dir = fs.mkdtempSync( path.join( os.tmpdir(), 'wppo-score-' ) );
		const missing = path.join( dir, 'absent.json' );
		const stdout = execFileSync(
			process.execPath,
			[ SCORE, '--results', missing, '--registry', REGISTRY ],
			{ encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] }
		);
		expect( JSON.parse( stdout ).verdict ).toBe( 'insufficient-evidence' );
	} );
} );
