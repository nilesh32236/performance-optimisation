/**
 * Deterministic benchmark runner (offline self-check foundation).
 *
 * - Loads every fixture in evals/benchmarks/fixtures/*.json.
 * - Verifies fixture integrity against evals/benchmarks/manifest.json (sha256).
 * - Evaluates each fixture's checks against its pinned referenceOutput, proving
 *   the checks are satisfiable and deterministic without any network call.
 * - Records one result per fixture with the required fields: latencyMs,
 *   timedOut, retries, toolCalls, verification, lint, build, security, error.
 * - Champion-only by default; --model <id> labels the run without changing
 *   any production routing.
 * - Writes evals/results.json (CI artifact, git-ignored).
 *
 * A future live executor can plug in via --executor <module> without changing
 * the result schema; offline self-checks never claim model-measured quality.
 *
 * Usage: node evals/run-benchmark.mjs [--model <id>] [--out <path>]
 */

import { readFileSync, writeFileSync, readdirSync, mkdirSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { resolve, dirname, basename } from 'node:path';
import { fileURLToPath } from 'node:url';
import { performance } from 'node:perf_hooks';

const here = dirname( fileURLToPath( import.meta.url ) );
const FIXTURE_DIR = resolve( here, 'benchmarks/fixtures' );
const MANIFEST_PATH = resolve( here, 'benchmarks/manifest.json' );
export const CHAMPION = 'opencode/muse-spark-1.3-contributor-free';

export const REQUIRED_AREAS = [
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

function sha256( text ) {
	return createHash( 'sha256' ).update( text ).digest( 'hex' );
}

function evaluateCheck( check, referenceOutput ) {
	const value = check.value ?? '';
	switch ( check.kind ) {
		case 'contains':
			return referenceOutput.includes( value );
		case 'notContains':
			return ! referenceOutput.includes( value );
		case 'matches':
			try {
				return new RegExp( value ).test( referenceOutput );
			} catch {
				return false;
			}
		default:
			return false;
	}
}

export function validateFixtureShape( fixture, file ) {
	const errors = [];
	for ( const key of [
		'id',
		'area',
		'title',
		'prompt',
		'pinnedInput',
		'referenceOutput',
		'checks',
	] ) {
		if ( fixture[ key ] === undefined ) {
			errors.push( `${ file }: missing ${ key }` );
		}
	}
	if ( ! REQUIRED_AREAS.includes( fixture.area ) ) {
		errors.push( `${ file }: unknown area ${ JSON.stringify( fixture.area ) }` );
	}
	if ( ! Array.isArray( fixture.checks ) || fixture.checks.length === 0 ) {
		errors.push( `${ file }: checks must be a non-empty array` );
	}
	return errors;
}

function parseArgs( argv ) {
	const args = {
		model: CHAMPION,
		out: resolve( here, 'results.json' ),
	};
	for ( let i = 0; i < argv.length; i++ ) {
		if ( argv[ i ] === '--model' && argv[ i + 1 ] ) {
			args.model = argv[ i + 1 ];
			i++;
		} else if ( argv[ i ] === '--out' && argv[ i + 1 ] ) {
			args.out = resolve( process.cwd(), argv[ i + 1 ] );
			i++;
		}
	}
	return args;
}

function main() {
	const args = parseArgs( process.argv.slice( 2 ) );
	const errors = [];

	let manifest = null;
	try {
		manifest = JSON.parse( readFileSync( MANIFEST_PATH, 'utf8' ) );
	} catch ( error ) {
		errors.push( `cannot read manifest: ${ error.message }` );
	}

	const files = readdirSync( FIXTURE_DIR )
		.filter( ( file ) => file.endsWith( '.json' ) )
		.sort();
	if ( files.length === 0 ) {
		errors.push( 'no fixtures found' );
	}

	const seenAreas = new Set();
	const results = [];

	for ( const file of files ) {
		const full = resolve( FIXTURE_DIR, file );
		const raw = readFileSync( full, 'utf8' );
		if ( ! manifest?.fixtures?.[ file ] ) {
			errors.push( `${ file }: missing manifest entry (new fixtures must be hash-pinned)` );
			continue;
		}
		if ( sha256( raw ) !== manifest.fixtures[ file ] ) {
			errors.push( `${ file }: content hash drift vs manifest (determinism broken)` );
			continue;
		}
		let fixture;
		try {
			fixture = JSON.parse( raw );
		} catch ( error ) {
			errors.push( `${ file }: invalid JSON (${ error.message })` );
			continue;
		}
		errors.push( ...validateFixtureShape( fixture, file ) );
		seenAreas.add( fixture.area );

		const started = performance.now();
		const failedChecks = [];
		for ( const check of fixture.checks ?? [] ) {
			if ( ! evaluateCheck( check, fixture.referenceOutput ?? '' ) ) {
				failedChecks.push( check.id ?? check.kind );
			}
		}
		const latencyMs = Math.round( ( performance.now() - started ) * 100 ) / 100;

		results.push( {
			fixtureId: fixture.id,
			area: fixture.area,
			model: args.model,
			latencyMs,
			timedOut: false,
			retries: 0,
			toolCalls: 0,
			verification: {
				passed: failedChecks.length === 0,
				checksTotal: ( fixture.checks ?? [] ).length,
				checksFailed: failedChecks,
			},
			lint: { status: 'not-run', reason: 'offline self-check; live executor required' },
			build: { status: 'not-run', reason: 'offline self-check; live executor required' },
			security: { status: 'not-run', findings: [] },
			error: failedChecks.length === 0 ? null : `checks failed: ${ failedChecks.join( ', ' ) }`,
		} );
	}

	const missingAreas = REQUIRED_AREAS.filter( ( area ) => ! seenAreas.has( area ) );
	if ( missingAreas.length > 0 ) {
		errors.push( `missing areas: ${ missingAreas.join( ', ' ) }` );
	}

	const failed = results.filter( ( result ) => ! result.verification.passed );
	if ( failed.length > 0 ) {
		for ( const result of failed ) {
			errors.push( `${ result.fixtureId }: ${ result.error }` );
		}
	}

	if ( errors.length > 0 ) {
		for ( const message of errors ) {
			console.error( `benchmark: ${ message }` );
		}
		process.exitCode = 1;
		return;
	}

	const payload = {
		generatedAt: new Date().toISOString(),
		model: args.model,
		mode: 'offline-self-check',
		note: 'Self-check proves fixture determinism only; it is not a measurement of model quality.',
		results,
	};
	mkdirSync( dirname( args.out ), { recursive: true } );
	writeFileSync( args.out, `${ JSON.stringify( payload, null, 2 ) }\n` );
	// eslint-disable-next-line no-console
	console.log(
		`benchmark OK: ${ results.length } fixture(s), areas=${ REQUIRED_AREAS.length } -> ${ basename( args.out ) }`
	);
}

if ( import.meta.url === `file://${ process.argv[ 1 ] }` ) {
	main();
}
