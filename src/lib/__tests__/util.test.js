/**
 * Tests for lib/util helpers.
 */

import { handleChange } from '../util';

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
} );
