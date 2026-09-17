import getWppoString from '../getWppoString';

describe( 'getWppoString (lib/getWppoString.js)', () => {
	const original = global.wppoSettings;

	afterEach( () => {
		global.wppoSettings = original;
	} );

	it( 'prefers the MO-backed wppoSettings.translations map', () => {
		global.wppoSettings = { translations: { dismiss: 'Schliessen' } };
		expect( getWppoString( 'dismiss', 'Dismiss' ) ).toBe( 'Schliessen' );
	} );

	it( 'falls back to __() when the map entry is missing', () => {
		global.wppoSettings = { translations: {} };
		expect( getWppoString( 'dismiss', 'Dismiss' ) ).toBe( 'Dismiss' );
	} );

	it( 'falls back to __() when wppoSettings is undefined', () => {
		global.wppoSettings = undefined;
		expect( getWppoString( 'saving', 'Saving…' ) ).toBe( 'Saving…' );
	} );

	it( 'ignores non-string map entries', () => {
		global.wppoSettings = { translations: { dismiss: 42 } };
		expect( getWppoString( 'dismiss', 'Dismiss' ) ).toBe( 'Dismiss' );
	} );
} );
