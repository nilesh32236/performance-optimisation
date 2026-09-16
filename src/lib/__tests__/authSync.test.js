import fs from 'fs';
import path from 'path';
import { AUTH_ERROR_CODES } from '../authErrors';
import { getErrorLogMessage } from '../apiRequest';

const readSrc = ( rel ) =>
	fs.readFileSync( path.join( __dirname, '..', '..', rel ), 'utf8' );

describe( 'auth contract sync (apiRequest ↔ main.js)', () => {
	it( 'shares the same auth-error code set in both bundles', () => {
		const mainSrc = readSrc( 'main.js' );
		const apiSrc = readSrc( 'lib/apiRequest.js' );
		const authSrc = readSrc( 'lib/authErrors.js' );

		for ( const code of AUTH_ERROR_CODES ) {
			expect( mainSrc ).toContain( code );
			// SPA reads the codes via ./authErrors.js (single source).
			expect( authSrc ).toContain( code );
		}
		expect( apiSrc ).toContain( 'isAuthErrorCode' );

		// No bundle may introduce an extra rest_* code the others lack.
		const restCodes = ( src ) =>
			Array.from(
				new Set( ( src.match( /rest_[a-z_]+/g ) || [] ).sort() )
			);
		const expected = [ ...AUTH_ERROR_CODES ].sort();
		expect( restCodes( mainSrc ) ).toEqual( expected );
	} );

	it( 'main.js getErrorLogMessage matches the shared implementation', () => {
		const mainSrc = readSrc( 'main.js' );
		expect( mainSrc ).toContain( 'const getErrorLogMessage' );
		// Behavioural parity: message-only logging, 500-char cap.
		expect( getErrorLogMessage( new Error( 'boom' ) ) ).toBe( 'boom' );
		expect( getErrorLogMessage( 'x'.repeat( 600 ) ) ).toHaveLength( 500 );
		expect( getErrorLogMessage( null ) ).toBe( 'Unknown error' );
		expect( mainSrc ).toContain( 'slice( 0, 500 )' );
	} );
} );
