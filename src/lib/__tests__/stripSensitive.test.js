import {
	stripSensitiveKeys,
	redactSecrets,
	isSensitivePollutionKey,
} from '../stripSensitive';

describe( 'stripSensitive shared walker', () => {
	it( 'shares the pollution guard', () => {
		expect( isSensitivePollutionKey( '__proto__' ) ).toBe( true );
		expect( isSensitivePollutionKey( 'minifyJS' ) ).toBe( false );
	} );

	it( 'delete mode drops secrets and pollution keys', () => {
		expect(
			stripSensitiveKeys( {
				auth_key: 'secret',
				keep: 'yes',
				__proto__: { polluted: true },
			} )
		).toEqual( { keep: 'yes' } );
	} );

	it( 'mask mode masks non-empty strings and drops non-strings', () => {
		expect(
			redactSecrets( {
				auth_key: 'secret',
				keep: 'yes',
				monkey: 'banana',
				__proto__: { polluted: true },
			} )
		).toEqual( {
			auth_key: 'REDACTED',
			keep: 'yes',
			monkey: 'banana',
		} );
		expect( redactSecrets( { nested: { apiToken: 12345 } } ) ).toEqual( {
			nested: {},
		} );
	} );
} );
