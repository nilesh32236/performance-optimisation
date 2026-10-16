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

	it( 'redacts quoted Cookie values without leaving the secret', () => {
		// RFC 6265 allows a quoted-string cookie value; the value atom must
		// consume the quotes instead of truncating at the opening quote.
		expect(
			redactLogSecrets( 'Set-Cookie: session="s3cr3t"; Path=/' )
		).toBe( 'Set-Cookie: [redacted]' );
		expect( redactLogSecrets( "Cookie: token='abc def'; a=b" ) ).toBe(
			'Cookie: [redacted]'
		);
	} );

	it( 'redacts compound query keys', () => {
		for ( const key of [
			'client_secret',
			'access_token',
			'refresh_token',
			'id_token',
			'csrf_token',
			'xsrf_token',
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
		expect( redactLogSecrets( 'refresh_token=tok123' ) ).toBe(
			'refresh_token=[redacted]'
		);
		expect( redactLogSecrets( 'private_key: -----BEGIN' ) ).toBe(
			'private_key=[redacted]'
		);
		expect( redactLogSecrets( 'session=abc123' ) ).toBe(
			'session=[redacted]'
		);
	} );

	it( 'redacts a quoted credential value that contains spaces', () => {
		// The generic value atom must accept a quoted run, not just \S+: with
		// \S+ the match fails on `token="abc def"` and the whole value was
		// logged verbatim.
		expect( redactLogSecrets( 'token="abc def"' ) ).toBe(
			'token=[redacted]'
		);
		expect( redactLogSecrets( "password='a b c'" ) ).toBe(
			'password=[redacted]'
		);
		expect( redactLogSecrets( 'api_key: "k 1 2 3" done' ) ).toBe(
			'api_key=[redacted] done'
		);
	} );

	it( 'leaves an already-masked value alone', () => {
		expect( redactLogSecrets( 'token=[redacted]' ) ).toBe(
			'token=[redacted]'
		);
	} );

	it( 'redacts a quoted value containing an escaped quote', () => {
		// The quoted atom must consume \\" as part of the value, not treat the
		// escaped quote as the terminator and leave the tail in the log.
		expect( redactLogSecrets( 'token="a\\"b SECRETTAIL"' ) ).toBe(
			'token=[redacted]'
		);
		expect( redactLogSecrets( "token='a\\'b SECRETTAIL'" ) ).toBe(
			'token=[redacted]'
		);
	} );

	it( 'fails closed past the walk depth instead of returning the subtree', () => {
		let deep = { client_secret: 'TOP-SECRET' };
		for ( let i = 0; i < 40; i++ ) {
			deep = { nested: deep };
		}

		expect( JSON.stringify( stripSensitiveKeys( deep ) ) ).not.toContain(
			'TOP-SECRET'
		);
		expect( JSON.stringify( redactSecrets( deep ) ) ).not.toContain(
			'TOP-SECRET'
		);
	} );

	it( 'redacts camelCase and compound key names', () => {
		expect( redactLogSecrets( 'accessToken=abc123' ) ).toBe(
			'accessToken=[redacted]'
		);
		expect( redactLogSecrets( 'clientSecret: s3cr3t' ) ).toBe(
			'clientSecret=[redacted]'
		);
		expect( redactLogSecrets( 'privateKey=abc' ) ).toBe(
			'privateKey=[redacted]'
		);
		expect( redactLogSecrets( 'sessionId=abc' ) ).toBe(
			'sessionId=[redacted]'
		);
	} );

	it( 'redacts a quoted JSON key', () => {
		expect(
			redactLogSecrets( '{"token": "abc123", "keep": 1}' )
		).not.toContain( 'abc123' );
	} );

	it( 'redacts an unquoted value that contains spaces', () => {
		expect( redactLogSecrets( 'password=my secret phrase' ) ).toBe(
			'password=[redacted]'
		);
	} );

	it( 'keeps the query context after a redacted value', () => {
		expect( redactLogSecrets( '?token=abc&page=2' ) ).toBe(
			'?token=[redacted]&page=2'
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

	it( 'redacts a credential that straddles the published length cap', () => {
		// The scan window is wider than the 500-char output cap, so a quoted
		// Cookie value whose closing quote sits past the cap is still matched
		// whole. Pre-slicing the input at 500 would cut the quote, fail the
		// quoted-string alternative, and log the value prefix verbatim.
		const prefix = 'a'.repeat( 470 );
		const out = redactLogSecrets(
			`${ prefix }Set-Cookie: session="SECRETVALUE1234"; Path=/`
		);

		expect( out ).not.toContain( 'SECRETVALUE1234' );
		expect( out ).not.toContain( 'SECRET' );
		expect( out ).toContain( 'Set-Cookie: [redacted]' );
	} );

	it( 'bounds the returned message at the published cap', () => {
		const out = redactLogSecrets( 'x'.repeat( 5000 ) );

		expect( out ).toHaveLength( 500 );
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
