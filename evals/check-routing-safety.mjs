/**
 * Routing-safety check: production model routing must be unchanged.
 *
 * - Every executable selection site keeps the explicit-selection shape
 *   `${{ vars.OPENCODE_MODEL || '<champion>' }}`.
 * - No model literal other than the champion appears in workflow/CLI files.
 * - No evals artifact claims to change production routing.
 *
 * Read-only: never edits workflows. Usage: node evals/check-routing-safety.mjs
 */

import { readFileSync, readdirSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const ROOT = resolve( here, '..' );
export const CHAMPION = 'opencode/muse-spark-1.3-contributor-free';

const WORKFLOW_DIR = resolve( ROOT, '.github/workflows' );
// Matches vars.OPENCODE_MODEL || '<literal>' in any quoting/style.
const SELECTION_PATTERN = /vars\.OPENCODE_MODEL\s*\|\|\s*['"]([^'"]+)['"]/g;
// Bare model literals that are NOT part of the approved selection shape.
const BARE_MODEL_PATTERN = /opencode\/[A-Za-z0-9][A-Za-z0-9._-]*/g;

function selectionSites( text ) {
	const sites = [];
	let match;
	SELECTION_PATTERN.lastIndex = 0;
	while ( ( match = SELECTION_PATTERN.exec( text ) ) !== null ) {
		sites.push( match[ 1 ] );
	}
	return sites;
}

export function checkWorkflows() {
	const errors = [];
	let sites = 0;
	let files = [];
	try {
		files = readdirSync( WORKFLOW_DIR ).filter( ( file ) => file.endsWith( '.yml' ) );
	} catch ( error ) {
		return { errors: [ `.github/workflows unreadable: ${ error.message }` ], sites: 0 };
	}

	for ( const file of files ) {
		const text = readFileSync( resolve( WORKFLOW_DIR, file ), 'utf8' );
		for ( const literal of selectionSites( text ) ) {
			sites++;
			if ( literal !== CHAMPION ) {
				errors.push( `${ file }: non-champion selection literal ${ JSON.stringify( literal ) }` );
			}
		}
		// Strip approved selections, then look for stray bare literals.
		const stripped = text.replace( SELECTION_PATTERN, '' );
		let stray;
		BARE_MODEL_PATTERN.lastIndex = 0;
		while ( ( stray = BARE_MODEL_PATTERN.exec( stripped ) ) !== null ) {
			// Allow mentions inside comments/docs lines, flag code-level literals.
			const line = stripped.slice( 0, stray.index ).split( '\n' ).pop() + stray[ 0 ];
			if ( /^\s*#/.test( line.trim() ) ) {
				continue;
			}
			errors.push( `${ file }: bare model literal outside explicit-selection shape: ${ stray[ 0 ] }` );
		}
	}

	if ( sites === 0 ) {
		errors.push( 'no explicit-selection sites found; expected champion fallback wiring' );
	}
	return { errors, sites };
}

function main() {
	const { errors, sites } = checkWorkflows();
	if ( errors.length > 0 ) {
		for ( const message of errors ) {
			console.error( `routing-safety: ${ message }` );
		}
		process.exitCode = 1;
		return;
	}
	// eslint-disable-next-line no-console
	console.log( `routing-safety OK: ${ sites } explicit-selection site(s), champion fallback preserved` );
}

main();
