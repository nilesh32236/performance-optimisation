/**
 * Documentation check: evals docs must exist and stay consistent.
 *
 * - evals/README.md exists and names the champion + npm script mapping.
 * - evals/MODEL-PATHS.md exists and names the champion.
 * - Every REQUIRED benchmark area has at least one fixture file.
 *
 * Usage: node evals/check-docs.mjs
 */

import { readFileSync, existsSync, readdirSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const CHAMPION = 'opencode/muse-spark-1.3-contributor-free';
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

function main() {
	const errors = [];

	const readmePath = resolve( here, 'README.md' );
	if ( ! existsSync( readmePath ) ) {
		errors.push( 'evals/README.md missing' );
	} else {
		const readme = readFileSync( readmePath, 'utf8' );
		if ( ! readme.includes( CHAMPION ) ) {
			errors.push( 'evals/README.md must name the champion model' );
		}
		if ( ! readme.includes( 'eval:checks' ) ) {
			errors.push( 'evals/README.md must document the eval:checks script' );
		}
		if ( ! readme.includes( 'npm run' ) ) {
			errors.push( 'evals/README.md must document the npm script mapping' );
		}
	}

	const pathsDoc = resolve( here, 'MODEL-PATHS.md' );
	if ( ! existsSync( pathsDoc ) ) {
		errors.push( 'evals/MODEL-PATHS.md missing' );
	} else if ( ! readFileSync( pathsDoc, 'utf8' ).includes( CHAMPION ) ) {
		errors.push( 'evals/MODEL-PATHS.md must name the champion model' );
	}

	const fixtureDir = resolve( here, 'benchmarks/fixtures' );
	let areas = new Set();
	try {
		for ( const file of readdirSync( fixtureDir ) ) {
			if ( ! file.endsWith( '.json' ) ) {
				continue;
			}
			const fixture = JSON.parse( readFileSync( resolve( fixtureDir, file ), 'utf8' ) );
			if ( fixture.area ) {
				areas.add( fixture.area );
			}
		}
	} catch ( error ) {
		errors.push( `fixtures unreadable: ${ error.message }` );
	}
	for ( const area of REQUIRED_AREAS ) {
		if ( ! areas.has( area ) ) {
			errors.push( `no fixture documents area: ${ area }` );
		}
	}

	if ( errors.length > 0 ) {
		for ( const message of errors ) {
			console.error( `docs: ${ message }` );
		}
		process.exitCode = 1;
		return;
	}
	// eslint-disable-next-line no-console
	console.log( `docs OK: README + MODEL-PATHS present, ${ REQUIRED_AREAS.length } benchmark areas covered` );
}

main();
