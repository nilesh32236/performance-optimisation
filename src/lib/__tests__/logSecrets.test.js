/**
 * Secret-redaction coverage for audit #1831.
 *
 * Pins the credential shapes added to redactLogSecrets() (Basic/Digest
 * schemes, Authorization headers, Cookie/Set-Cookie headers, compound
 * query/body keys) and the SECRET_KEY_PATTERN additions in
 * stripSensitive.js (authorization/cookie/session/sessionid export keys).
 */

import { redactLogSecrets } from '../logSecrets';
import { redactSecrets, stripSensitiveKeys } from '../stripSensitive';

describe( 'redactLogSecrets audit #1831 shapes', () => {
	it( 'redacts Basic credentials without leaving the secret', () => {
		expect( redactLogSecrets( 'Authorization: Basic dXNlcjpwYXNz' ) ).toBe(
			'Authorization=[redacted]'
		);
		expect( redactLogSecrets( 'Basic dXNlcjpwYXNz' ) ).toBe(
			'Basic=[redacted]'
		);
	} );

	it( 'keeps non-secret Basic prose intact', () => {
		// The Basic value class excludes spaces and treats an all-lowercase
		// token as prose, so "Basic authentication failed" is not mangled.
		expect( redactLogSecrets( 'Basic authentication failed' ) ).toBe(
			'Basic authentication failed'
		);
	} );

	it( 'redacts Digest quoted params but keeps plain prose', () => {
		expect(
			redactLogSecrets( 'Digest username="Mufasa", response="abc123"' )
		).toBe( 'Digest=[redacted]' );
		expect(
			redactLogSecrets( 'Digest: username="Mufasa", response="abc123"' )
		).toBe( 'Digest=[redacted]' );
		expect( redactLogSecrets( 'Digest authentication failed' ) ).toBe(
			'Digest authentication failed'
		);
	} );

	it( 'redacts the whole Authorization header value', () => {
		// Short credentials (below the scheme rules' 8-char floor) and
		// credentials with unusual characters still never survive.
		expect( redactLogSecrets( 'Authorization: Basic YTpi' ) ).toBe(
			'Authorization=[redacted]'
		);
		expect( redactLogSecrets( 'Authorization: Bearer abc!def' ) ).toBe(
			'Authorization=[redacted]'
		);
	} );

	it( 'redacts Cookie and Set-Cookie headers including pairs', () => {
		expect(
			redactLogSecrets( 'Cookie: wordpress_logged_in_x=s3cr3t' )
		).toBe( 'Cookie: [redacted]' );
		expect( redactLogSecrets( 'Cookie: a=1; b=2; c=3' ) ).toBe(
			'Cookie: [redacted]'
		);
		expect( redactLogSecrets( 'Set-Cookie: session=abc; Path=/' ) ).toBe(
			'Set-Cookie: [redacted]'
		);
	} );

	it( 'redacts compound query keys', () => {
		for ( const key of [
			'client_secret',
			'access_token',
			'private_key',
			'authorization',
			'cookie',
			'session',
			'passwd',
		] ) {
			expect(
				redactLogSecrets( `GET /api?${ key }=SECRET failed` )
			).toBe( 'GET /api?' + key + '=[redacted] failed' );
		}
	} );

	it( 'redacts compound body keys with "_" names', () => {
		expect( redactLogSecrets( 'client_secret: shhh' ) ).toBe(
			'client_secret=[redacted]'
		);
		expect( redactLogSecrets( 'access_token=tok123' ) ).toBe(
			'access_token=[redacted]'
		);
		expect( redactLogSecrets( 'private_key: -----BEGIN' ) ).toBe(
			'private_key=[redacted]'
		);
		expect( redactLogSecrets( 'session=abc123' ) ).toBe(
			'session=[redacted]'
		);
	} );

	it( 'leaves non-secret words alone', () => {
		expect( redactLogSecrets( 'tokens: none here' ) ).toBe(
			'tokens: none here'
		);
		expect( redactLogSecrets( 'monkey: banana' ) ).toBe( 'monkey: banana' );
		expect( redactLogSecrets( 'ordinary message' ) ).toBe(
			'ordinary message'
		);
	} );
} );

describe( 'stripSensitive audit #1831 keys', () => {
	it( 'masks authorization/cookie/session keys on export', () => {
		expect(
			redactSecrets( {
				authorization: 'Bearer x',
				cookie: 'y',
				session: 'z',
				sessionid: 'w',
				keep: 'yes',
			} )
		).toEqual( {
			authorization: 'REDACTED',
			cookie: 'REDACTED',
			session: 'REDACTED',
			sessionid: 'REDACTED',
			keep: 'yes',
		} );
	} );

	it( 'deletes authorization/cookie/session keys in strip mode', () => {
		expect(
			stripSensitiveKeys( {
				authorization: 'Bearer x',
				session: 'z',
				keep: 'yes',
			} )
		).toEqual( { keep: 'yes' } );
	} );
} );
