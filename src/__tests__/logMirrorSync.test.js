/**
 * Log-mirror parity test (audit #1354 review).
 *
 * esi.js, lazyload.js and main.js each carry a dependency-free mirror of
 * the SPA's getErrorLogMessage() (they cannot import the SPA bundle).
 * This test pins the shared contract — message-only, 500-char cap, never
 * full objects — so the mirrors cannot drift apart silently.
 */
import { getErrorLogMessage } from '../lib/apiRequest';
import {
	getErrorLogMessage as getErrorLogMessageDirect,
	MAX_LOG_MESSAGE_LENGTH,
} from '../lib/logSecrets';

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

	it( 'apiRequest re-exports the canonical helper for back-compat', () => {
		expect( getErrorLogMessage ).toBe( getErrorLogMessageDirect );
	} );

	it( 'all four implementations share the same truncation cap', () => {
		const fs = require( 'fs' );
		const path = require( 'path' );
		const read = ( rel ) =>
			fs.readFileSync( path.join( __dirname, '..', rel ), 'utf8' );
		// The dependency-free mirrors cannot import the SPA bundle, so they
		// keep the copy-pasted `slice( 0, 500 )` literal (whitespace-tolerant
		// match: a pure reformat must not fail this).
		for ( const rel of [ 'esi.js', 'lazyload.js', 'main.js' ] ) {
			expect( read( rel ) ).toMatch( /slice\(\s*0,\s*500\s*\)/ );
		}
		// The canonical SPA implementation lives in lib/logSecrets.js and
		// expresses the cap through the shared MAX_LOG_MESSAGE_LENGTH
		// constant instead of a pasted literal. Pin the constant's value and
		// that the implementation actually uses it, so the behaviour above
		// (`truncates long messages to 500 chars`) cannot drift from the
		// mirrors' literal.
		expect( MAX_LOG_MESSAGE_LENGTH ).toBe( 500 );
		const spaSrc = read( 'lib/logSecrets.js' );
		expect( spaSrc ).toMatch( /MAX_LOG_MESSAGE_LENGTH/ );
		expect( getErrorLogMessageDirect( 'x'.repeat( 600 ) ) ).toHaveLength(
			MAX_LOG_MESSAGE_LENGTH
		);
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
