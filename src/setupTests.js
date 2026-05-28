require( '@testing-library/jest-dom' );
jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
} ) );
global.wppoSettings = {};

Object.defineProperty( window, 'matchMedia', {
	writable: true,
	value: jest.fn().mockImplementation( ( query ) => ( {
		matches: false,
		media: query,
		onchange: null,
		addListener: jest.fn(), // Deprecated
		removeListener: jest.fn(), // Deprecated
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
			ToggleControl: ( { checked, onChange, label } ) => (
				<input
					type="checkbox"
					checked={ checked }
					onChange={ ( e ) => onChange( e.target.checked ) }
					aria-label={ label }
				/>
			),
		};
	},
	{ virtual: true }
);
