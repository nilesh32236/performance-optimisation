import { parseGuardedInt } from '../numeric';

describe( 'parseGuardedInt', () => {
	it( 'parses numbers and numeric strings with truncation', () => {
		expect( parseGuardedInt( 3, { min: 0, max: 5, fallback: 5 } ) ).toBe(
			3
		);
		expect(
			parseGuardedInt( '3.7', { min: 0, max: 5, fallback: 5 } )
		).toBe( 3 );
		expect( parseGuardedInt( '+3', { min: 0, max: 5, fallback: 5 } ) ).toBe(
			3
		);
	} );

	it( 'clamps to min/max', () => {
		expect( parseGuardedInt( 99, { min: 0, max: 5, fallback: 5 } ) ).toBe(
			5
		);
		expect( parseGuardedInt( -2, { min: 0, max: 5, fallback: 5 } ) ).toBe(
			0
		);
	} );

	it( 'fails open to fallback for non-numeric input', () => {
		const opts = { min: 0, max: 5, fallback: 5 };
		expect( parseGuardedInt( undefined, opts ) ).toBe( 5 );
		expect( parseGuardedInt( '', opts ) ).toBe( 5 );
		expect( parseGuardedInt( '   ', opts ) ).toBe( 5 );
		expect( parseGuardedInt( 'abc', opts ) ).toBe( 5 );
		expect( parseGuardedInt( [ '3' ], opts ) ).toBe( 5 );
		expect( parseGuardedInt( true, opts ) ).toBe( 5 );
		expect( parseGuardedInt( NaN, opts ) ).toBe( 5 );
		expect( parseGuardedInt( '1e2', opts ) ).toBe( 5 );
	} );

	it( 'rejects hex/binary/octal literals like PHP is_numeric()', () => {
		const opts = { min: 0, max: 5, fallback: 5 };
		expect( parseGuardedInt( '0x3', opts ) ).toBe( 5 );
		expect( parseGuardedInt( '0b101', opts ) ).toBe( 5 );
		expect( parseGuardedInt( '0o17', opts ) ).toBe( 5 );
	} );

	it( 'supports unbounded ranges for edge caps', () => {
		expect( parseGuardedInt( '2560', { fallback: 2560 } ) ).toBe( 2560 );
		expect( parseGuardedInt( -5, { min: 0, fallback: 2560 } ) ).toBe( 0 );
	} );

	it( 'fails open to fallback for explicit null options', () => {
		expect( parseGuardedInt( 'abc', null ) ).toBe( 0 );
		expect( parseGuardedInt( '3', null ) ).toBe( 3 );
	} );
} );
