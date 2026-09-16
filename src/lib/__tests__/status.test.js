import { STATUS_LEVELS, scoreToStatus, boolToStatus } from '../status';

describe( 'status helpers (lib/status.js)', () => {
	it( 'exposes a frozen level list', () => {
		expect( [ ...STATUS_LEVELS ] ).toEqual( [
			'good',
			'warning',
			'poor',
			'unknown',
		] );
		expect( Object.isFrozen( STATUS_LEVELS ) ).toBe( true );
	} );

	describe( 'scoreToStatus', () => {
		it( 'maps Lighthouse thresholds', () => {
			expect( scoreToStatus( 95 ) ).toBe( 'good' );
			expect( scoreToStatus( 90 ) ).toBe( 'good' );
			expect( scoreToStatus( 75 ) ).toBe( 'warning' );
			expect( scoreToStatus( 50 ) ).toBe( 'warning' );
			expect( scoreToStatus( 10 ) ).toBe( 'poor' );
		} );

		it( 'coerces numeric strings and falls back to unknown', () => {
			expect( scoreToStatus( '80' ) ).toBe( 'warning' );
			expect( scoreToStatus( NaN ) ).toBe( 'unknown' );
			expect( scoreToStatus( undefined ) ).toBe( 'unknown' );
		} );
	} );

	describe( 'boolToStatus', () => {
		it( 'maps booleans and falls back to unknown', () => {
			expect( boolToStatus( true ) ).toBe( 'good' );
			expect( boolToStatus( false ) ).toBe( 'poor' );
			expect( boolToStatus( 1 ) ).toBe( 'unknown' );
			expect( boolToStatus( null ) ).toBe( 'unknown' );
		} );
	} );
} );
