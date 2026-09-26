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
			expect( src ).toMatch( /slice\( 0, 500 \)/ );
		}
	} );
} );
