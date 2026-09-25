/**
 * Fixture determinism + benchmark schema tests
 * (black-box over node evals/run-benchmark.mjs).
 *
 * Covers acceptance items 4-5: eleven task areas and full result fields.
 */
'use strict';

const { execFileSync } = require( 'node:child_process' );
const crypto = require( 'node:crypto' );
const fs = require( 'node:fs' );
const os = require( 'node:os' );
const path = require( 'node:path' );

const RUN = path.resolve( __dirname, 'run-benchmark.mjs' );
const FIXTURE_DIR = path.resolve( __dirname, 'benchmarks/fixtures' );
const MANIFEST = path.resolve( __dirname, 'benchmarks/manifest.json' );

const REQUIRED_AREAS = [
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

const REQUIRED_RESULT_FIELDS = [
	'fixtureId',
	'area',
	'model',
	'latencyMs',
	'timedOut',
	'retries',
	'toolCalls',
	'verification',
	'lint',
	'build',
	'security',
	'error',
];

function tmpFile( name ) {
	return path.join( fs.mkdtempSync( path.join( os.tmpdir(), 'wppo-bench-' ) ), name );
}

function runBenchmark( extraArgs = [] ) {
	const out = tmpFile( 'results.json' );
	try {
		execFileSync( process.execPath, [ RUN, '--out', out, ...extraArgs ], {
			encoding: 'utf8',
			stdio: [ 'ignore', 'pipe', 'pipe' ],
		} );
	} catch ( error ) {
		throw new Error( `run-benchmark failed: ${ error.stderr || error.message }` );
	}
	return JSON.parse( fs.readFileSync( out, 'utf8' ) );
}

describe( 'evals/benchmark fixtures', () => {
	it( 'covers all eleven required areas with pinned inputs and checks', () => {
		const files = fs.readdirSync( FIXTURE_DIR ).filter( ( file ) => file.endsWith( '.json' ) );
		expect( files.length ).toBeGreaterThanOrEqual( 11 );
		const areas = new Set();
		for ( const file of files ) {
			const fixture = JSON.parse( fs.readFileSync( path.join( FIXTURE_DIR, file ), 'utf8' ) );
			expect( typeof fixture.pinnedInput ).toBe( 'string' );
			expect( typeof fixture.referenceOutput ).toBe( 'string' );
			expect( Array.isArray( fixture.checks ) ).toBe( true );
			expect( fixture.checks.length ).toBeGreaterThan( 0 );
			areas.add( fixture.area );
		}
		for ( const area of REQUIRED_AREAS ) {
			expect( areas.has( area ) ).toBe( true );
		}
	} );

	it( 'matches the sha256 manifest (no silent drift)', () => {
		const manifest = JSON.parse( fs.readFileSync( MANIFEST, 'utf8' ) );
		for ( const [ file, expected ] of Object.entries( manifest.fixtures ) ) {
			const actual = crypto
				.createHash( 'sha256' )
				.update( fs.readFileSync( path.join( FIXTURE_DIR, file ) ) )
				.digest( 'hex' );
			expect( actual ).toBe( expected );
		}
	} );

	it( 'emits one passing result per fixture with all required fields', () => {
		const payload = runBenchmark();
		expect( payload.model ).toBe( 'opencode/muse-spark-1.3-contributor-free' );
		expect( payload.results.length ).toBeGreaterThanOrEqual( 11 );
		const areas = new Set();
		for ( const result of payload.results ) {
			for ( const field of REQUIRED_RESULT_FIELDS ) {
				expect( result ).toHaveProperty( field );
			}
			expect( typeof result.latencyMs ).toBe( 'number' );
			expect( result.verification.passed ).toBe( true );
			expect( result.error ).toBeNull();
			areas.add( result.area );
		}
		for ( const area of REQUIRED_AREAS ) {
			expect( areas.has( area ) ).toBe( true );
		}
	} );

	it( 'is deterministic across runs', () => {
		const first = runBenchmark();
		const second = runBenchmark();
		const summarize = ( payload ) =>
			payload.results.map( ( result ) => [
				result.fixtureId,
				result.verification.passed,
				result.verification.checksTotal,
			] );
		expect( summarize( second ) ).toEqual( summarize( first ) );
	} );
} );
