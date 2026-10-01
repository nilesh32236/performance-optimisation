/**
 * Tests for the Tools & Settings inspector copy.
 *
 * This screen's controls are raw form elements rather than SettingField or
 * SwitchField, so nothing enforced that they had copy — the inspector simply
 * showed its empty state. These tests are what stops that coming back: the key
 * list is asserted, not merely a shape check, because a shape check passes
 * just as happily on an empty object as on a full one.
 *
 * @package
 */

import PLUGIN_SETTINGS_SUBJECTS, { subjectFor } from '../subjects';

const KEYS = Object.keys( PLUGIN_SETTINGS_SUBJECTS );

describe( 'Tools & Settings inspector subjects', () => {
	it( 'covers the settings on this screen', () => {
		expect( KEYS ).toEqual(
			expect.arrayContaining( [ 'autoRescan', 'highValueUrls' ] )
		);
	} );

	it( 'is not empty, because an empty object satisfies every other test here', () => {
		expect( KEYS.length ).toBeGreaterThan( 0 );
	} );

	it( 'gives every subject an id derived from its key', () => {
		KEYS.forEach( ( key ) => {
			expect( PLUGIN_SETTINGS_SUBJECTS[ key ].id ).toBe(
				`setting:${ key }`
			);
		} );
	} );

	it( 'uses unique ids', () => {
		const ids = KEYS.map( ( key ) => PLUGIN_SETTINGS_SUBJECTS[ key ].id );
		expect( new Set( ids ).size ).toBe( ids.length );
	} );

	it( 'marks every subject as a setting', () => {
		KEYS.forEach( ( key ) => {
			expect( PLUGIN_SETTINGS_SUBJECTS[ key ].kind ).toBe( 'setting' );
		} );
	} );

	it( 'gives every subject a title and prose in both required blocks', () => {
		KEYS.forEach( ( key ) => {
			const s = PLUGIN_SETTINGS_SUBJECTS[ key ];
			expect( typeof s.title ).toBe( 'string' );
			expect( s.title.length ).toBeGreaterThan( 0 );
			expect( typeof s.does ).toBe( 'string' );
			expect( s.does.length ).toBeGreaterThan( 0 );
			expect( typeof s.cost ).toBe( 'string' );
			expect( s.cost.length ).toBeGreaterThan( 0 );
		} );
	} );

	it( 'leaves no untranslated template placeholder in any string field', () => {
		KEYS.forEach( ( key ) => {
			const s = PLUGIN_SETTINGS_SUBJECTS[ key ];
			// `detail` is optional, so the set is built first and asserted after —
			// an expect inside a conditional trips jest/no-conditional-expect.
			const fields = [ s.title, s.does, s.cost, s.detail ].filter(
				( f ) => typeof f === 'string'
			);
			fields.forEach( ( field ) => {
				expect( field ).not.toMatch( /[{}]/ );
			} );
		} );
	} );

	it( 'returns undefined for a key it does not know, rather than throwing', () => {
		expect( subjectFor( 'thisIsNotASetting' ) ).toBeUndefined();
	} );
} );
