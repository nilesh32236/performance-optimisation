require( '@testing-library/jest-dom' );
jest.mock( '@wordpress/i18n', () => {
	const _n = ( singular, plural, count ) => {
		return count === 1 ? singular : plural;
	};
	const sprintf = ( format, ...args ) => {
		return format.replace( /%[sd]/g, () => args.shift() );
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

jest.mock(
	'@wordpress/components',
	() => {
		// eslint-disable-next-line @wordpress/no-unused-vars-before-return, import/no-extraneous-dependencies
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
