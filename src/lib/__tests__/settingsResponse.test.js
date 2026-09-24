/**
 * Tests for the P3-019 shared settings-response commit contract.
 */
import {
	commitSettingsResponse,
	resolveSettingsPayload,
	isFullSettingsMap,
	FULL_SETTINGS_MAP_ACTIONS,
	NESTED_SETTINGS_ACTIONS,
} from '../settingsResponse';

describe( 'settingsResponse', () => {
	beforeEach( () => {
		global.wppoSettings = { settings: { cache_settings: {} } };
	} );

	describe( 'isFullSettingsMap', () => {
		it( 'accepts a non-empty plain object', () => {
			expect(
				isFullSettingsMap( { file_optimisation: { delayJS: true } } )
			).toBe( true );
		} );

		it( 'rejects null, arrays, primitives and empty objects', () => {
			expect( isFullSettingsMap( null ) ).toBe( false );
			expect( isFullSettingsMap( undefined ) ).toBe( false );
			expect( isFullSettingsMap( 'nope' ) ).toBe( false );
			expect( isFullSettingsMap( 42 ) ).toBe( false );
			expect( isFullSettingsMap( [] ) ).toBe( false );
			expect( isFullSettingsMap( [ { a: 1 } ] ) ).toBe( false );
			// An empty map must never wipe sibling tabs from the cache.
			expect( isFullSettingsMap( {} ) ).toBe( false );
		} );
	} );

	describe( 'resolveSettingsPayload', () => {
		it.each( FULL_SETTINGS_MAP_ACTIONS )(
			'resolves the full map for %s',
			( action ) => {
				const map = { cache_settings: { enabled: true } };
				expect( resolveSettingsPayload( action, map ) ).toBe( map );
			}
		);

		it( 'resolves nested settings for apply_preset', () => {
			const map = { cache_settings: { enabled: true } };
			expect(
				resolveSettingsPayload( 'apply_preset', {
					preset: 'balanced',
					settings: map,
					diff: [],
				} )
			).toBe( map );
		} );

		it( 'returns null for unknown actions and unusable payloads', () => {
			const map = { cache_settings: {} };
			expect( resolveSettingsPayload( 'suggestions', map ) ).toBe( null );
			expect( resolveSettingsPayload( 'update_settings', null ) ).toBe(
				null
			);
			expect( resolveSettingsPayload( 'update_settings', {} ) ).toBe(
				null
			);
			expect( resolveSettingsPayload( 'update_settings', [] ) ).toBe(
				null
			);
			expect(
				resolveSettingsPayload( 'apply_preset', {
					preset: 'safe',
					diff: [],
				} )
			).toBe( null );
			expect(
				resolveSettingsPayload( 'apply_preset', {
					preset: 'safe',
					settings: {},
					diff: [],
				} )
			).toBe( null );
		} );
	} );

	describe( 'commitSettingsResponse', () => {
		it( 'replaces the global cache with the server map (frozen)', () => {
			const map = {
				cache_settings: { enabled: true },
				file_optimisation: { delayJS: true },
			};
			expect( commitSettingsResponse( 'import_settings', map ) ).toBe(
				true
			);
			expect( global.wppoSettings.settings ).toEqual( map );
			expect( Object.isFrozen( global.wppoSettings.settings ) ).toBe(
				true
			);
			// The commit freezes a copy, never the caller's payload.
			expect( global.wppoSettings.settings ).not.toBe( map );
			expect( Object.isFrozen( map ) ).toBe( false );
		} );

		it( 'commits sandbox_promote, safe_mode and restore full maps', () => {
			expect(
				commitSettingsResponse( 'sandbox_promote', {
					file_optimisation: { delayJS: true },
				} )
			).toBe( true );
			expect( global.wppoSettings.settings ).toEqual( {
				file_optimisation: { delayJS: true },
			} );
			expect(
				commitSettingsResponse( 'safe_mode', {
					file_optimisation: { safeMode: true },
				} )
			).toBe( true );
			expect(
				commitSettingsResponse( 'restore_settings', {
					cache_settings: {},
				} )
			).toBe( true );
		} );

		it( 'commits the nested apply_preset settings map', () => {
			const map = { file_optimisation: { delayJS: true } };
			expect(
				commitSettingsResponse( 'apply_preset', {
					preset: 'balanced',
					settings: map,
					diff: [],
				} )
			).toBe( true );
			expect( global.wppoSettings.settings ).toEqual( map );
			expect( global.wppoSettings.settings ).not.toBe( map );
		} );

		it( 'hydrates sibling tabs so cross-tab reads see the commit', () => {
			commitSettingsResponse( 'update_settings', {
				cache_settings: { enabled: true },
				ai_adaptive: { enabled: false },
			} );
			// Deferred readers (mounting after the commit) see the snapshot.
			expect( global.wppoSettings.settings.ai_adaptive ).toEqual( {
				enabled: false,
			} );
			expect( global.wppoSettings.settings.cache_settings ).toEqual( {
				enabled: true,
			} );
		} );

		it( 'is a fail-safe no-op for unknown actions and bad payloads', () => {
			const before = global.wppoSettings.settings;
			expect(
				commitSettingsResponse( 'suggestions', {
					cache_settings: {},
				} )
			).toBe( false );
			expect( commitSettingsResponse( 'update_settings', null ) ).toBe(
				false
			);
			expect( commitSettingsResponse( 'update_settings', {} ) ).toBe(
				false
			);
			expect(
				commitSettingsResponse( 'apply_preset', {
					preset: 'safe',
					diff: [],
				} )
			).toBe( false );
			expect( global.wppoSettings.settings ).toBe( before );
		} );

		it( 'returns false when the global is absent', () => {
			delete global.wppoSettings;
			expect(
				commitSettingsResponse( 'update_settings', {
					cache_settings: {},
				} )
			).toBe( false );
		} );

		it( 'exposes the covered action lists', () => {
			expect( FULL_SETTINGS_MAP_ACTIONS ).toEqual(
				expect.arrayContaining( [
					'update_settings',
					'import_settings',
					'sandbox_promote',
				] )
			);
			expect( NESTED_SETTINGS_ACTIONS ).toEqual( [ 'apply_preset' ] );
		} );
	} );
} );
