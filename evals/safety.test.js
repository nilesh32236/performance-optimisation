/**
 * Discovery + safety tests (black-box).
 *
 * Covers acceptance items 1 and 6: the known-good registry survives
 * discovery failure, and discovery stays bounded without filing issues.
 */
'use strict';

const { execFileSync } = require( 'node:child_process' );
const fs = require( 'node:fs' );
const os = require( 'node:os' );
const path = require( 'node:path' );

const DISCOVER = path.resolve( __dirname, 'discover.mjs' );
const ROUTING = path.resolve( __dirname, 'check-routing-safety.mjs' );
const PRIVACY = path.resolve( __dirname, 'check-privacy.mjs' );
const DOCS = path.resolve( __dirname, 'check-docs.mjs' );
const REGISTRY = path.resolve( __dirname, 'registry.json' );

function runNode( script, args = [], env = {} ) {
	try {
		const stdout = execFileSync( process.execPath, [ script, ...args ], {
			encoding: 'utf8',
			stdio: [ 'ignore', 'pipe', 'pipe' ],
			env: { ...process.env, ...env },
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

describe( 'evals/discover', () => {
	it( 'survives an unreachable catalog: exit 0, registry untouched', () => {
		const before = fs.readFileSync( REGISTRY, 'utf8' );
		const out = path.join( fs.mkdtempSync( path.join( os.tmpdir(), 'wppo-disc-' ) ), 'discovery.json' );
		const result = runNode(
			DISCOVER,
			[ '--out', out ],
			{ MODELS_DEV_URL: 'http://127.0.0.1:1/models.json' }
		);
		expect( result.code ).toBe( 0 );
		expect( fs.readFileSync( REGISTRY, 'utf8' ) ).toBe( before );
		const discovery = JSON.parse( fs.readFileSync( out, 'utf8' ) );
		expect( [ 'failed', 'stale-cache' ] ).toContain( discovery.status );
		expect( discovery.bounds.maxConcurrency ).toBeLessThanOrEqual( 4 );
		expect( discovery.bounds.maxRetries ).toBeLessThanOrEqual( 2 );
		expect( discovery.bounds.timeoutMs ).toBeLessThanOrEqual( 15000 );
	} );

	it( 'offline mode reuses cache without touching the registry', () => {
		const before = fs.readFileSync( REGISTRY, 'utf8' );
		const out = path.join( fs.mkdtempSync( path.join( os.tmpdir(), 'wppo-disc-' ) ), 'discovery.json' );
		const result = runNode( DISCOVER, [ '--out', out, '--offline' ] );
		expect( result.code ).toBe( 0 );
		expect( fs.readFileSync( REGISTRY, 'utf8' ) ).toBe( before );
		expect( JSON.parse( fs.readFileSync( out, 'utf8' ) ).status ).toMatch(
			/failed|stale-cache/
		);
	} );
} );

describe( 'evals/safety gates', () => {
	it( 'routing-safety passes with explicit selection preserved', () => {
		const result = runNode( ROUTING );
		expect( result.code ).toBe( 0 );
		expect( result.stdout ).toMatch( /explicit-selection/ );
	} );

	it( 'privacy passes with artifacts git-ignored', () => {
		expect( runNode( PRIVACY ).code ).toBe( 0 );
	} );

	it( 'docs check passes', () => {
		expect( runNode( DOCS ).code ).toBe( 0 );
	} );
} );
