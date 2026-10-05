/**
 * Log-mirror parity test (audit #1354 review).
 *
 * esi.js, lazyload.js and main.js each carry a dependency-free mirror of
 * the SPA's getErrorLogMessage() (they cannot import the SPA bundle).
 * This test pins the shared contract — message-only, 500-char cap, never
 * full objects — so the mirrors cannot drift apart silently.
 */
import { getErrorLogMessage } from '../lib/apiRequest';

describe( 'log mirror contract', () => {
	it( 'truncates long messages to 500 chars', () => {
		expect( getErrorLogMessage( 'x'.repeat( 600 ) ) ).toHaveLength( 500 );
	} );

	it( 'returns Unknown error for nullish input', () => {
		expect( getErrorLogMessage( null ) ).toBe( 'Unknown error' );
		expect( getErrorLogMessage( undefined ) ).toBe( 'Unknown error' );
	} );

	it( 'extracts Error.message without extra payload', () => {
		expect( getErrorLogMessage( new Error( 'boom' ) ) ).toBe( 'boom' );
	} );

	it( 'all four implementations share the same truncation cap', () => {
		const fs = require( 'fs' );
		const path = require( 'path' );
		const read = ( rel ) =>
			fs.readFileSync( path.join( __dirname, '..', rel ), 'utf8' );
		for ( const rel of [
			'esi.js',
			'lazyload.js',
			'main.js',
			'lib/apiRequest.js',
		] ) {
			const src = read( rel );
			// Whitespace-tolerant: `slice( 0, 500 )` and `slice(0, 500)` are the
			// same contract, and a pure reformat must not fail this. The exact
			// spacing also forced the `500` literal to stay copy-pasted in every
			// mirror, which is what blocks collapsing them onto one shared
			// MAX_LOG_MESSAGE_LENGTH constant. Behaviour for the SPA copy is
			// asserted above, not by string-matching the source.
			expect( src ).toMatch( /slice\(\s*0,\s*500\s*\)/ );
		}
	} );

	it( 'no SPA component re-implements the helper inline', () => {
		// The fifth divergent copy appeared in ErrorBoundary.js: it logged
		// `redactLogSecrets( String( error ) )` with no nullish guard, no
		// guarded coercion and no 500-char cap — and a throw from
		// componentDidCatch escalates the very crash it exists to contain.
		// It now delegates to getErrorLogMessage(). This pins the delegation.
		const fs = require( 'fs' );
		const path = require( 'path' );
		const src = fs.readFileSync(
			path.join(
				__dirname,
				'..',
				'components',
				'common',
				'ErrorBoundary.js'
			),
			'utf8'
		);
		expect( src ).toContain( 'getErrorLogMessage( error )' );
		expect( src ).not.toMatch( /String\(\s*error\s*\)/ );
	} );
} );
