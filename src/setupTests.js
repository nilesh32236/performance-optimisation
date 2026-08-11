require( '@testing-library/jest-dom' );
jest.mock( '@wordpress/i18n', () => {
	const _n = ( singular, plural, count ) => {
		return count === 1 ? singular : plural;
	};
	const sprintf = ( format, ...args ) => {
		let i = 0;
		return format.replace( /%(?:(\d+)\$)?[sd]/g, ( match, index ) => {
			return index ? args[ Number( index ) - 1 ] : args[ i++ ];
		} );
	};
	return {
		__: ( str ) => str,
		_n,
		sprintf,
	};
} );
global.wppoSettings = {};

Object.defineProperty( window, 'matchMedia', {
	writable: true,
	value: jest.fn().mockImplementation( ( query ) => ( {
		matches: false,
		media: query,
		onchange: null,
		addEventListener: jest.fn(),
		removeEventListener: jest.fn(),
		dispatchEvent: jest.fn(),
	} ) ),
} );

/* eslint-disable @wordpress/no-unused-vars-before-return, import/no-extraneous-dependencies */
jest.mock(
	'@wordpress/components',
	() => {
		const React = require( 'react' );
		return {
			ToggleControl: ( { checked, onChange, label, disabled } ) => (
				<input
					type="checkbox"
					checked={ checked }
					onChange={ ( e ) => onChange( e.target.checked ) }
					aria-label={ label }
					disabled={ disabled }
				/>
			),
		};
	},
	{ virtual: true }
);
/* eslint-enable @wordpress/no-unused-vars-before-return, import/no-extraneous-dependencies */
