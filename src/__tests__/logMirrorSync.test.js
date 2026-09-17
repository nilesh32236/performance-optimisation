/**
 * Log-mirror parity test (audit #1354 review + maintainability audit).
 *
 * esi.js, lazyload.js and main.js each carry a dependency-free mirror of
 * the shared getLogMessage() in lib/logMessage.js (they cannot import the
 * SPA bundle; apiRequest.js re-exports the canonical implementation).
 * This test pins the shared contract — message-only, redact-then-truncate,
 * 500-char cap, never full objects — so the mirrors cannot drift apart
 * silently.
 */
import { getErrorLogMessage } from '../lib/apiRequest';
import { getLogMessage, redactLogSecrets } from '../lib/logMessage';

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

	it( 'canonical module and apiRequest re-export agree', () => {
		expect( getLogMessage( new Error( 'boom' ) ) ).toBe(
			getErrorLogMessage( new Error( 'boom' ) )
		);
		expect( getLogMessage( 'x'.repeat( 600 ) ) ).toHaveLength( 500 );
	} );

	it( 'redacts credential-bearing messages before logging', () => {
		expect(
			redactLogSecrets( 'api_key: secret123' ).includes( 'secret123' )
		).toBe( false );
		expect(
			getErrorLogMessage( new Error( 'token abcdefgh1234' ) ).includes(
				'abcdefgh1234'
			)
		).toBe( false );
	} );

	it( 'all mirrors carry the redact step', () => {
		const fs = require( 'fs' );
		const path = require( 'path' );
		const read = ( rel ) =>
			fs.readFileSync( path.join( __dirname, '..', rel ), 'utf8' );
		for ( const rel of [
			'esi.js',
			'lazyload.js',
			'main.js',
			'lib/logMessage.js',
		] ) {
			const src = read( rel );
			expect( src ).toMatch( /redact\w*LogSecrets/ );
			expect( src ).toMatch( /\[redacted/ );
		}
	} );

	it( 'all implementations share the same truncation cap', () => {
		const fs = require( 'fs' );
		const path = require( 'path' );
		const read = ( rel ) =>
			fs.readFileSync( path.join( __dirname, '..', rel ), 'utf8' );
		for ( const rel of [
			'esi.js',
			'lazyload.js',
			'main.js',
			'lib/logMessage.js',
		] ) {
			const src = read( rel );
			expect( src ).toMatch( /slice\( 0, 500 \)/ );
		}
		// apiRequest.js re-exports the canonical implementation.
		expect( read( 'lib/apiRequest.js' ) ).toMatch(
			/logMessage|getSharedLogMessage/
		);
	} );
} );
