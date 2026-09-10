/**
 * Tests for lib/util helpers.
 */

import { handleChange, formatBytes } from '../util';

describe( 'handleChange', () => {
	it( 'updates a text field value by name', () => {
		const setSettings = jest.fn();
		const handler = handleChange( setSettings );

		handler( {
			target: {
				name: 'title',
				type: 'text',
				value: 'New',
				checked: false,
			},
		} );

		expect( setSettings ).toHaveBeenCalledWith( expect.any( Function ) );
		const updater = setSettings.mock.calls[ 0 ][ 0 ];
		expect( updater( { title: 'Old' } ) ).toEqual( { title: 'New' } );
	} );

	it( 'stores the checked value for checkboxes', () => {
		const setSettings = jest.fn();
		const handler = handleChange( setSettings );

		handler( {
			target: {
				name: 'enabled',
				type: 'checkbox',
				value: 'on',
				checked: true,
			},
		} );

		const updater = setSettings.mock.calls[ 0 ][ 0 ];
		expect( updater( { enabled: false } ) ).toEqual( { enabled: true } );
	} );

	it( 'preserves unrelated settings keys', () => {
		const setSettings = jest.fn();
		const handler = handleChange( setSettings );

		handler( {
			target: {
				name: 'enabled',
				type: 'checkbox',
				value: 'on',
				checked: true,
			},
		} );

		const updater = setSettings.mock.calls[ 0 ][ 0 ];
		expect( updater( { enabled: false, other: 'keep' } ) ).toEqual( {
			enabled: true,
			other: 'keep',
		} );
	} );

	describe( 'delayJSIdleTimeout clamping', () => {
		const dispatch = ( value, type = 'number' ) => {
			const setSettings = jest.fn();
			handleChange( setSettings )( {
				target: {
					name: 'delayJSIdleTimeout',
					type,
					value,
					checked: false,
				},
			} );
			return setSettings.mock.calls[ 0 ][ 0 ]( {} ).delayJSIdleTimeout;
		};

		it( 'falls back to 3000 for empty values', () => {
			expect( dispatch( '' ) ).toBe( 3000 );
		} );

		it( 'falls back to 3000 for non-numeric values', () => {
			expect( dispatch( 'abc', 'text' ) ).toBe( 3000 );
		} );

		it( 'falls back to 3000 for zero and negative values', () => {
			expect( dispatch( '0' ) ).toBe( 3000 );
			expect( dispatch( '-100' ) ).toBe( 3000 );
		} );

		it( 'clamps small values up to 500', () => {
			expect( dispatch( '100' ) ).toBe( 500 );
		} );

		it( 'keeps exact boundary values 500 and 20000', () => {
			expect( dispatch( '500' ) ).toBe( 500 );
			expect( dispatch( '20000' ) ).toBe( 20000 );
		} );

		it( 'falls back to 3000 for non-finite values', () => {
			expect( dispatch( 'Infinity' ) ).toBe( 3000 );
		} );

		it( 'clamps large values down to 20000', () => {
			expect( dispatch( '99999' ) ).toBe( 20000 );
		} );

		it( 'keeps in-range values as numbers', () => {
			expect( dispatch( '5000' ) ).toBe( 5000 );
		} );
	} );
} );

describe( 'formatBytes', () => {
	it( 'formats zero, negative and non-numeric input as "0 B"', () => {
		expect( formatBytes( 0 ) ).toBe( '0 B' );
		expect( formatBytes( -10 ) ).toBe( '0 B' );
		expect( formatBytes( NaN ) ).toBe( '0 B' );
		expect( formatBytes( undefined ) ).toBe( '0 B' );
	} );

	it( 'formats byte counts below 1 KB', () => {
		expect( formatBytes( 10 ) ).toBe( '10 B' );
		expect( formatBytes( 0.5 ) ).toBe( '1 B' );
	} );

	it( 'formats KB and MB boundaries with one decimal', () => {
		expect( formatBytes( 1024 ) ).toBe( '1.0 KB' );
		expect( formatBytes( 5000 ) ).toBe( '4.9 KB' );
		expect( formatBytes( 1024 * 1024 ) ).toBe( '1.0 MB' );
		expect( formatBytes( 2 * 1024 * 1024 ) ).toBe( '2.0 MB' );
	} );

	it( 'formats GB values and coerces numeric strings', () => {
		expect( formatBytes( 1024 ** 3 ) ).toBe( '1.0 GB' );
		expect( formatBytes( '2048' ) ).toBe( '2.0 KB' );
	} );
} );
