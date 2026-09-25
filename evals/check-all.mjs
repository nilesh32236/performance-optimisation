/**
 * Aggregator: runs every evals gate in dependency order, fail-closed.
 *
 * Order: registry gate -> deterministic benchmark -> scoring (champion-only,
 * retains champion without challenger evidence) -> routing-safety ->
 * privacy -> docs.
 *
 * Discovery is intentionally excluded: it is network-bound and advisory, and
 * a known-good registry must survive discovery failure (it runs separately
 * in the twice-daily workflow and exits 0 with stale-cache status).
 *
 * Usage: node evals/check-all.mjs
 */

import { execFileSync } from 'node:child_process';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );

const STEPS = [
	[ 'registry', [ `${ here }/check-registry.mjs` ] ],
	[ 'benchmark', [ `${ here }/run-benchmark.mjs` ] ],
	[ 'score', [ `${ here }/score.mjs`, '--output', `${ here }/verdict.json` ] ],
	[ 'routing-safety', [ `${ here }/check-routing-safety.mjs` ] ],
	[ 'privacy', [ `${ here }/check-privacy.mjs` ] ],
	[ 'docs', [ `${ here }/check-docs.mjs` ] ],
];

function main() {
	let failed = false;
	for ( const [ name, argv ] of STEPS ) {
		try {
			execFileSync( 'node', argv, { stdio: 'inherit' } );
		} catch {
			console.error( `checks: step failed: ${ name }` );
			failed = true;
			break;
		}
	}

	if ( failed ) {
		process.exitCode = 1;
	} else {
		// eslint-disable-next-line no-console
		console.log( 'checks OK: registry + benchmark + score + routing-safety + privacy + docs' );
	}
}

if ( import.meta.url === `file://${ process.argv[ 1 ] }` ) {
	main();
}
