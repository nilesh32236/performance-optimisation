/**
 * Registry gate tests (black-box over node evals/check-registry.mjs).
 *
 * Covers acceptance item 2: every candidate has positive free evidence
 * (numeric price 0) or is excluded by the gate.
 */
'use strict';

const { execFileSync } = require( 'node:child_process' );
const fs = require( 'node:fs' );
const os = require( 'node:os' );
const path = require( 'node:path' );

const CHECK = path.resolve( __dirname, 'check-registry.mjs' );
const REGISTRY = path.resolve( __dirname, 'registry.json' );
const CHAMPION = 'opencode/muse-spark-1.3-contributor-free';

function run( args = [] ) {
	try {
		const stdout = execFileSync( process.execPath, [ CHECK, ...args ], {
			encoding: 'utf8',
			stdio: [ 'ignore', 'pipe', 'pipe' ],
		} );
		return { code: 0, stdout };
	} catch ( error ) {
		return {
			code: error.status ?? 1,
			stdout: error.stdout ?? '',
			stderr: error.stderr ?? '',
		};
	}
}

function writeTempRegistry( mutate ) {
	const dir = fs.mkdtempSync( path.join( os.tmpdir(), 'wppo-registry-' ) );
	const base = JSON.parse( fs.readFileSync( REGISTRY, 'utf8' ) );
	const file = path.join( dir, 'registry.json' );
	fs.writeFileSync( file, JSON.stringify( mutate( base ) ) );
	return file;
}

describe( 'evals/check-registry', () => {
	it( 'passes for the known-good registry with the champion pinned', () => {
		const result = run( [ '--registry', REGISTRY ] );
		expect( result.code ).toBe( 0 );
		expect( result.stdout ).toContain( CHAMPION );
	} );

	it( 'rejects unknown (null) price: unknown price is not free', () => {
		const file = writeTempRegistry( ( base ) => {
			base.candidates[ 0 ].freeEvidence.price = null;
			return base;
		} );
		const result = run( [ '--registry', file ] );
		expect( result.code ).not.toBe( 0 );
		expect( result.stderr ).toMatch( /price must be numeric 0/ );
	} );

	it( 'rejects string price evidence', () => {
		const file = writeTempRegistry( ( base ) => {
			base.candidates[ 0 ].freeEvidence.price = 'free';
			return base;
		} );
		expect( run( [ '--registry', file ] ).code ).not.toBe( 0 );
	} );

	it( 'rejects paid candidates', () => {
		const file = writeTempRegistry( ( base ) => {
			base.candidates.push( {
				id: 'opencode/some-paid-model',
				provider: 'opencode',
				status: 'challenger',
				freeEvidence: {
					source: 'catalog',
					price: 3,
					checkedAt: '2026-09-25T00:00:00Z',
				},
				health: { status: 'unknown' },
				capabilities: [ 'code-review' ],
			} );
			return base;
		} );
		expect( run( [ '--registry', file ] ).code ).not.toBe( 0 );
	} );

	it( 'rejects a changed champion literal', () => {
		const file = writeTempRegistry( ( base ) => {
			base.champion = 'opencode/some-other-model';
			return base;
		} );
		const result = run( [ '--registry', file ] );
		expect( result.code ).not.toBe( 0 );
		expect( result.stderr ).toMatch( /champion must remain/ );
	} );

	it( 'rejects stored credentials and raw provider payloads', () => {
		const withSecret = writeTempRegistry( ( base ) => {
			base.candidates[ 0 ].apiKey = 'sk-test';
			return base;
		} );
		expect( run( [ '--registry', withSecret ] ).code ).not.toBe( 0 );

		const withPayload = writeTempRegistry( ( base ) => {
			base.candidates[ 0 ].rawPayload = { everything: true };
			return base;
		} );
		const result = run( [ '--registry', withPayload ] );
		expect( result.code ).not.toBe( 0 );
		expect( result.stderr ).toMatch( /raw provider payload/ );
	} );
} );
