import {
	formatMs,
	formatPercent,
	formatBytesShared,
	savingsPercent,
} from '../format';

describe( 'format helpers (lib/format.js)', () => {
	describe( 'formatMs', () => {
		it( 'formats finite values with an ms suffix', () => {
			expect( formatMs( 123.6 ) ).toBe( '124 ms' );
			expect( formatMs( '50' ) ).toBe( '50 ms' );
		} );

		it( 'falls back for non-finite input', () => {
			expect( formatMs( NaN ) ).toBe( '—' );
			expect( formatMs( undefined ) ).toBe( '—' );
		} );

		it( 'renders missing telemetry as em-dash, not 0 ms', () => {
			expect( formatMs( null ) ).toBe( '—' );
			expect( formatMs( '' ) ).toBe( '—' );
			expect( formatMs( '   ' ) ).toBe( '—' );
			expect( formatMs( false ) ).toBe( '—' );
			expect( formatMs( [] ) ).toBe( '—' );
		} );
	} );

	describe( 'formatPercent', () => {
		it( 'treats 0-1 values as ratios', () => {
			expect( formatPercent( 0.5 ) ).toBe( '50%' );
		} );

		it( 'passes through 0-100 values', () => {
			expect( formatPercent( 75 ) ).toBe( '75%' );
		} );

		it( 'honours an explicit ratio option instead of range-sniffing', () => {
			expect( formatPercent( 0.5, { ratio: false } ) ).toBe( '0.5%' );
			expect( formatPercent( 0.5, { ratio: true } ) ).toBe( '50%' );
			expect( formatPercent( 75, { ratio: false } ) ).toBe( '75%' );
		} );

		it( 'falls back for non-finite input', () => {
			expect( formatPercent( NaN ) ).toBe( '—' );
		} );
	} );

	describe( 'formatBytesShared', () => {
		it( 'formats bytes and kilobytes', () => {
			expect( formatBytesShared( 512 ) ).toBe( '512 B' );
			expect( formatBytesShared( 2048 ) ).toBe( '2 KB' );
		} );

		it( 'scales beyond GB into TB and PB', () => {
			expect( formatBytesShared( 5 * 1024 ** 4 ) ).toBe( '5 TB' );
			expect( formatBytesShared( 2 * 1024 ** 5 ) ).toBe( '2 PB' );
		} );

		it( 'falls back for negative or non-finite input', () => {
			expect( formatBytesShared( -1 ) ).toBe( '—' );
			expect( formatBytesShared( NaN ) ).toBe( '—' );
		} );

		it( 'renders missing sizes as em-dash, not 0 B', () => {
			expect( formatBytesShared( null ) ).toBe( '—' );
			expect( formatBytesShared( '' ) ).toBe( '—' );
			expect( formatBytesShared( false ) ).toBe( '—' );
			expect( formatBytesShared( [] ) ).toBe( '—' );
		} );
	} );

	describe( 'savingsPercent', () => {
		it( 'computes the percent saved', () => {
			expect( savingsPercent( 1000, 750 ) ).toBe( 25 );
		} );

		it( 'returns 0 when nothing changed', () => {
			expect( savingsPercent( 1000, 1000 ) ).toBe( 0 );
		} );

		it( 'returns null when not computable or inverted', () => {
			expect( savingsPercent( 1000, 1200 ) ).toBeNull();
			expect( savingsPercent( 0, 0 ) ).toBeNull();
			expect( savingsPercent( NaN, 10 ) ).toBeNull();
		} );

		it( 'rejects booleans and arrays instead of coercing', () => {
			expect( savingsPercent( true, 1 ) ).toBeNull();
			expect( savingsPercent( 1000, [ 500 ] ) ).toBeNull();
			expect( savingsPercent( [ 1000 ], 500 ) ).toBeNull();
		} );
	} );
} );
