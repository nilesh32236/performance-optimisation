import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';

jest.mock( '../../lib/apiRequest', () => {
	const actual = jest.requireActual( '../../lib/apiRequest' );
	return {
		...actual,
		apiCall: jest.fn(),
		fetchRecentActivities: jest.fn(),
	};
} );

jest.mock( '@fortawesome/react-fontawesome', () => ( {
	FontAwesomeIcon: ( { icon } ) => (
		<span data-icon={ icon?.iconName || 'icon' } />
	),
} ) );

jest.mock( '@fortawesome/free-solid-svg-icons', () => ( {
	faFileExport: { iconName: 'file-export' },
	faFileImport: { iconName: 'file-import' },
	faCheckCircle: { iconName: 'check-circle' },
	faExclamationCircle: { iconName: 'exclamation-circle' },
	faHistory: { iconName: 'history' },
	faTachometerAlt: { iconName: 'tachometer-alt' },
} ) );

import PluginSetting, {
	redactSecrets,
	validateImportData,
	isValidImportValue,
	isPollutionKey,
	getAllowedImportKeys,
	getMaxImportTopKeys,
} from '../PluginSetting';
import { apiCall, fetchRecentActivities } from '../../lib/apiRequest';

describe( 'PluginSetting', () => {
	const baseOptions = {
		performance_audit: {},
		file_optimisation: {},
	};

	beforeEach( () => {
		global.wppoSettings = {
			performance_audit: { pagespeedApiKeyConfigured: false },
			settings: { performance_audit: {} },
		};
		jest.clearAllMocks();
	} );

	it( 'renders the tools header and cards', () => {
		render( <PluginSetting options={ baseOptions } /> );

		expect( screen.getByText( 'Tools' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'Optimisation Activity Log' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Google PageSpeed API Key' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Export Configuration' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Import Configuration' )
		).toBeInTheDocument();
	} );

	it( 'loads the activity log when requested', async () => {
		fetchRecentActivities.mockResolvedValueOnce( {
			activities: [
				{ id: 1, activity: 'Cache cleared' },
				{ id: 2, activity: 'Settings saved' },
			],
			current_page: 1,
			total_pages: 1,
		} );

		render( <PluginSetting options={ baseOptions } /> );

		fireEvent.click(
			screen.getByRole( 'button', { name: /Load Activity Log/i } )
		);

		await waitFor( () =>
			expect( screen.getByText( 'Cache cleared' ) ).toBeInTheDocument()
		);
		expect( screen.getByText( 'Settings saved' ) ).toBeInTheDocument();
	} );

	it( 'shows an error when loading the activity log fails', async () => {
		fetchRecentActivities.mockRejectedValueOnce( new Error( 'boom' ) );
		const errorSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );

		render( <PluginSetting options={ baseOptions } /> );

		fireEvent.click(
			screen.getByRole( 'button', { name: /Load Activity Log/i } )
		);

		await waitFor( () =>
			expect(
				screen.getByText( 'Failed to load activity log.' )
			).toBeInTheDocument()
		);

		errorSpy.mockRestore();
	} );

	it( 'saves a new API key on success', async () => {
		// First call is the mount-time settings_snapshot probe.
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { has_snapshot: false },
		} );
		apiCall.mockResolvedValueOnce( { success: true } );

		render( <PluginSetting options={ baseOptions } /> );

		const input = screen.getByLabelText( 'New API Key' );
		fireEvent.change( input, { target: { value: 'AIza-super-secret' } } );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Save Settings/i } )
		);

		await waitFor( () =>
			expect( screen.getByText( 'API key saved.' ) ).toBeInTheDocument()
		);

		expect( apiCall ).toHaveBeenCalledWith( 'update_settings', {
			tab: 'performance_audit',
			settings: { pagespeed_api_key: 'AIza-super-secret' },
		} );
	} );

	it( 'shows an error notice when the API key save fails', async () => {
		// First call is the mount-time settings_snapshot probe.
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { has_snapshot: false },
		} );
		apiCall.mockResolvedValueOnce( {
			success: false,
			message: 'Rejected',
		} );

		render( <PluginSetting options={ baseOptions } /> );

		fireEvent.change( screen.getByLabelText( 'New API Key' ), {
			target: { value: 'bad-key' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Save Settings/i } )
		);

		await waitFor( () =>
			expect( screen.getByText( 'Rejected' ) ).toBeInTheDocument()
		);
	} );

	it( 'saves the auto-rescan frequency and passes it to update_settings', async () => {
		global.wppoSettings = {
			performance_audit: {
				pagespeedApiKeyConfigured: true,
				autoRescan: '',
			},
			settings: { performance_audit: {} },
		};
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { has_snapshot: false },
		} );
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Auto-rescan frequency saved.',
		} );

		render( <PluginSetting options={ baseOptions } /> );

		const select = screen.getByLabelText( 'Auto PageSpeed Re-scan' );
		expect( select ).toBeEnabled();
		fireEvent.change( select, { target: { value: 'weekly' } } );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Save Auto-rescan/i } )
		);

		await waitFor( () =>
			expect(
				screen.getByText( 'Auto-rescan frequency saved.' )
			).toBeInTheDocument()
		);

		expect( apiCall ).toHaveBeenCalledWith( 'update_settings', {
			tab: 'performance_audit',
			settings: { auto_rescan: 'weekly' },
		} );
	} );

	it( 'saves server timing and high-value URLs', async () => {
		global.wppoSettings = {
			performance_audit: { pagespeedApiKeyConfigured: false },
			settings: {
				performance_audit: {
					server_timing_enabled: false,
					high_value_urls: [],
				},
			},
		};
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { has_snapshot: false },
		} );
		apiCall.mockResolvedValueOnce( { success: true, data: {} } );

		render( <PluginSetting options={ baseOptions } /> );

		fireEvent.click(
			screen.getByLabelText( 'Enable Server-Timing Header' )
		);
		fireEvent.change( screen.getByLabelText( 'High-value URLs' ), {
			target: {
				value: 'http://example.com/about/\nhttp://example.com/blog/',
			},
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Save Monitoring/i } )
		);

		await waitFor( () =>
			expect( apiCall ).toHaveBeenCalledWith( 'update_settings', {
				tab: 'performance_audit',
				settings: expect.objectContaining( {
					server_timing_enabled: true,
					high_value_urls: [
						'http://example.com/about/',
						'http://example.com/blog/',
					],
				} ),
			} )
		);
	} );

	it( 'drops invalid high-value URLs and warns', async () => {
		global.wppoSettings = {
			performance_audit: { pagespeedApiKeyConfigured: false },
			homeUrl: 'http://example.com',
			settings: {
				performance_audit: {
					server_timing_enabled: false,
					high_value_urls: [],
				},
			},
		};
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { has_snapshot: false },
		} );
		apiCall.mockResolvedValueOnce( { success: true, data: {} } );

		render( <PluginSetting options={ baseOptions } /> );

		fireEvent.change( screen.getByLabelText( 'High-value URLs' ), {
			target: {
				value: 'http://example.com/about/\njavascript:alert(1)\nhttps://evil.example.org/x/',
			},
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Save Monitoring/i } )
		);

		await waitFor( () =>
			expect( apiCall ).toHaveBeenCalledWith( 'update_settings', {
				tab: 'performance_audit',
				settings: expect.objectContaining( {
					high_value_urls: [ 'http://example.com/about/' ],
				} ),
			} )
		);
		expect(
			screen.getByText( /Skipped 2 invalid URL\(s\)/ )
		).toBeInTheDocument();
	} );

	it( 'blocks saving when every high-value URL is invalid', async () => {
		global.wppoSettings = {
			performance_audit: { pagespeedApiKeyConfigured: false },
			homeUrl: 'http://example.com',
			settings: {
				performance_audit: {
					server_timing_enabled: false,
					high_value_urls: [],
				},
			},
		};

		render( <PluginSetting options={ baseOptions } /> );

		fireEvent.change( screen.getByLabelText( 'High-value URLs' ), {
			target: { value: 'javascript:alert(1)' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Save Monitoring/i } )
		);

		await waitFor( () =>
			expect(
				screen.getByText( /No valid URLs to save/ )
			).toBeInTheDocument()
		);
		// The mount-time settings_snapshot probe may have fired, but no
		// settings write must have been attempted.
		expect( apiCall ).not.toHaveBeenCalledWith(
			'update_settings',
			expect.anything()
		);
		expect( apiCall ).not.toHaveBeenCalledWith(
			'import_settings',
			expect.anything()
		);
	} );

	it( 'saves the real-user monitoring toggle', async () => {
		global.wppoSettings = {
			performance_audit: { pagespeedApiKeyConfigured: false },
			settings: {
				performance_audit: { rum_enabled: false },
			},
		};
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { has_snapshot: false },
		} );
		apiCall.mockResolvedValueOnce( { success: true, data: {} } );

		render( <PluginSetting options={ baseOptions } /> );

		fireEvent.click(
			screen.getByLabelText( 'Collect Real-user Web Vitals' )
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: /Save Monitoring/i } )
		);

		await waitFor( () =>
			expect( apiCall ).toHaveBeenCalledWith( 'update_settings', {
				tab: 'performance_audit',
				settings: expect.objectContaining( { rum_enabled: true } ),
			} )
		);
	} );

	it( 'exports settings with the API key redacted', async () => {
		const options = {
			performance_audit: { pagespeed_api_key: 'SECRET-KEY' },
		};
		const createObjectURL = jest.fn( () => 'blob:fake-url' );
		const revokeObjectURL = jest.fn();
		global.URL.createObjectURL = createObjectURL;
		global.URL.revokeObjectURL = revokeObjectURL;

		const anchorClick = jest
			.spyOn( HTMLAnchorElement.prototype, 'click' ) // eslint-disable-line no-undef -- jsdom browser global.
			.mockImplementation( () => {} );

		render( <PluginSetting options={ options } /> );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Download JSON/i } )
		);

		const blob = createObjectURL.mock.calls[ 0 ][ 0 ];
		expect( blob ).toBeInstanceOf( Blob );
		expect( anchorClick ).toHaveBeenCalled();

		// Object URL revocation is deferred so the download can start first.
		await waitFor( () =>
			expect( revokeObjectURL ).toHaveBeenCalledWith( 'blob:fake-url' )
		);

		const text = await new Promise( ( resolve, reject ) => {
			const reader = new FileReader(); // eslint-disable-line no-undef -- jsdom browser global.
			reader.onload = () => resolve( reader.result );
			reader.onerror = reject;
			reader.readAsText( blob );
		} );
		const parsed = JSON.parse( text );
		expect( parsed.performance_audit.pagespeed_api_key ).toBe( 'REDACTED' );

		anchorClick.mockRestore();
	} );

	it( 'rejects importing an invalid JSON file', async () => {
		apiCall.mockResolvedValueOnce( { success: true } );

		render( <PluginSetting options={ baseOptions } /> );

		const file = new File(
			[ JSON.stringify( { unexpected: {} } ) ],
			'settings.json',
			{ type: 'application/json' }
		);
		fireEvent.change(
			screen.getByLabelText( 'Select configuration file' ),
			{
				target: { files: [ file ] },
			}
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: /Import Settings/i } )
		);

		await waitFor( () =>
			expect(
				screen.getByText( /Importing this file will overwrite/i )
			).toBeInTheDocument()
		);

		fireEvent.click( screen.getByRole( 'button', { name: /^Confirm$/i } ) );

		await waitFor( () =>
			expect(
				screen.getByText( /Invalid settings file/ )
			).toBeInTheDocument()
		);
		expect( apiCall ).not.toHaveBeenCalledWith(
			'import_settings',
			expect.anything()
		);
	} );

	it( 'imports a valid settings file after confirmation', async () => {
		apiCall.mockResolvedValueOnce( { success: true } );

		render( <PluginSetting options={ baseOptions } /> );

		const file = new File(
			[ JSON.stringify( { file_optimisation: { minifyJS: true } } ) ],
			'settings.json',
			{ type: 'application/json' }
		);
		fireEvent.change(
			screen.getByLabelText( 'Select configuration file' ),
			{
				target: { files: [ file ] },
			}
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: /Import Settings/i } )
		);

		await waitFor( () =>
			expect(
				screen.getByText( /Importing this file will overwrite/i )
			).toBeInTheDocument()
		);

		fireEvent.click( screen.getByRole( 'button', { name: /^Confirm$/i } ) );

		await waitFor( () =>
			expect( apiCall ).toHaveBeenCalledWith( 'import_settings', {
				action: 'import_settings',
				settings: { file_optimisation: { minifyJS: true } },
			} )
		);
	} );

	it( 'redacts generic *_key names on export', () => {
		const redacted = redactSecrets( {
			object_cache: {
				auth_key: 'supersecret',
				consumer_key: 'ck_123',
				private_key: 'pk_456',
				google_key: 'gkey',
				host: 'localhost',
			},
		} );
		expect( redacted.object_cache.auth_key ).toBe( 'REDACTED' );
		expect( redacted.object_cache.consumer_key ).toBe( 'REDACTED' );
		expect( redacted.object_cache.private_key ).toBe( 'REDACTED' );
		expect( redacted.object_cache.google_key ).toBe( 'REDACTED' );
		expect( redacted.object_cache.host ).toBe( 'localhost' );
	} );

	it( 'does not redact ordinary words ending in key', () => {
		const redacted = redactSecrets( { monkey: 'banana' } );
		expect( redacted.monkey ).toBe( 'banana' );
	} );

	it( 'redacts separator-less apikey variants on export', () => {
		const redacted = redactSecrets( {
			performance_audit: {
				apikey: 'secret-1',
				apiKey: 'secret-2',
				pagespeed_api_key: 'AIza-secret',
			},
		} );
		expect( redacted.performance_audit.apikey ).toBe( 'REDACTED' );
		expect( redacted.performance_audit.apiKey ).toBe( 'REDACTED' );
	} );

	it( 'caps top-level import keys at the allowlist length', () => {
		expect( getMaxImportTopKeys() ).toBeGreaterThan( 0 );
		expect( getMaxImportTopKeys() ).toBe( getAllowedImportKeys().length );
		expect(
			validateImportData( { file_optimisation: { minifyJS: true } } )
		).toBe( true );
	} );

	it( 'resolves the allowlist lazily from the live wppoSettings', () => {
		global.wppoSettings = {
			...global.wppoSettings,
			allowedSettingsKeys: [ 'file_optimisation', 'custom_tab' ],
		};
		expect( getAllowedImportKeys() ).toEqual( [
			'file_optimisation',
			'custom_tab',
		] );
		expect( validateImportData( { custom_tab: { enabled: true } } ) ).toBe(
			true
		);
		expect(
			validateImportData( { database_cleanup: { enabled: true } } )
		).toBe( false );
	} );

	it( 'rejects over-cap and unknown-key import payloads', () => {
		const overCap = {};
		for ( let i = 0; i < getMaxImportTopKeys() + 1; i++ ) {
			overCap[ `unknown_key_${ i }` ] = {};
		}
		expect( validateImportData( overCap ) ).toBe( false );
		expect( validateImportData( { unknown_top_level_key: {} } ) ).toBe(
			false
		);
	} );

	it( 'rejects prototype-pollution keys at any depth', () => {
		expect( isPollutionKey( '__proto__' ) ).toBe( true );
		expect( isPollutionKey( 'constructor' ) ).toBe( true );
		expect( isPollutionKey( 'prototype' ) ).toBe( true );
		expect( isPollutionKey( 'minifyJS' ) ).toBe( false );
		// Top-level proto keys (crafted via JSON.parse so __proto__ stays
		// an own property) are rejected even though the allowlist would
		// already exclude them.
		expect(
			validateImportData(
				JSON.parse( '{"__proto__":{"polluted":true}}' )
			)
		).toBe( false );
		// Nested proto keys inside an otherwise-valid payload are rejected.
		expect(
			validateImportData(
				JSON.parse(
					'{"file_optimisation":{"__proto__":{"polluted":true}}}'
				)
			)
		).toBe( false );
		expect(
			validateImportData(
				JSON.parse(
					'{"file_optimisation":{"nested":{"constructor":{"prototype":{"polluted":true}}}}}'
				)
			)
		).toBe( false );
		expect(
			isValidImportValue(
				JSON.parse( '{"__proto__":{"polluted":true}}' ),
				1
			)
		).toBe( false );
		expect(
			isValidImportValue( JSON.parse( '{"ok":1,"prototype":"x"}' ), 1 )
		).toBe( false );
		// Benign payloads still pass.
		expect(
			validateImportData( { file_optimisation: { minifyJS: true } } )
		).toBe( true );
	} );

	it( 'drops pollution keys during redaction without polluting prototypes', () => {
		const input = JSON.parse(
			'{"file_optimisation":{"minifyJS":true,"__proto__":{"polluted":"yes"}},"__proto__":{"polluted":"top"}}'
		);
		const redacted = redactSecrets( input );
		expect( Object.keys( redacted ) ).not.toContain( '__proto__' );
		expect( Object.keys( redacted.file_optimisation ) ).not.toContain(
			'__proto__'
		);
		expect( redacted.file_optimisation.minifyJS ).toBe( true );
		expect( {}.polluted ).toBeUndefined();
		expect( Object.prototype.polluted ).toBeUndefined();
	} );

	it( 'enables the Undo button when a snapshot exists', async () => {
		// Mount-time probe reports a snapshot; no restore attempted yet.
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { has_snapshot: true, taken_at: 1234567890 },
		} );

		render( <PluginSetting options={ baseOptions } /> );

		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: /Undo Last Change/i } )
			).toBeEnabled()
		);
		expect( apiCall ).toHaveBeenCalledWith(
			'settings_snapshot',
			{},
			'GET'
		);
	} );

	it( 'restores settings on Undo success', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { has_snapshot: true, taken_at: 1234567890 },
		} );
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {},
			message: 'Settings restored successfully.',
		} );

		render( <PluginSetting options={ baseOptions } /> );

		const undoButton = await screen.findByRole( 'button', {
			name: /Undo Last Change/i,
		} );
		// The button enables only after the snapshot check resolves; await
		// the enabled state (React 19 flushes the effect later than 18).
		await waitFor( () => expect( undoButton ).toBeEnabled() );
		fireEvent.click( undoButton );

		await waitFor( () =>
			expect(
				screen.getByText( 'Settings restored successfully.' )
			).toBeInTheDocument()
		);
		expect( apiCall ).toHaveBeenCalledWith( 'restore_settings', {} );
	} );

	it( 'shows an error notice when no snapshot exists to restore', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { has_snapshot: true, taken_at: 1234567890 },
		} );
		apiCall.mockResolvedValueOnce( {
			success: false,
			message: 'No settings snapshot available to restore.',
		} );
		const errorSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );

		render( <PluginSetting options={ baseOptions } /> );

		const undoButton = await screen.findByRole( 'button', {
			name: /Undo Last Change/i,
		} );
		// Await the enabled state: clicking a still-disabled button is a
		// no-op, which starves the restore call (flaky on React 19).
		await waitFor( () => expect( undoButton ).toBeEnabled() );
		fireEvent.click( undoButton );

		await waitFor( () =>
			expect(
				screen.getByText( 'No settings snapshot available to restore.' )
			).toBeInTheDocument()
		);

		errorSpy.mockRestore();
	} );

	it( 'shows an error notice when the restore request fails', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { has_snapshot: true, taken_at: 1234567890 },
		} );
		apiCall.mockRejectedValueOnce( new Error( 'network down' ) );
		const errorSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );

		render( <PluginSetting options={ baseOptions } /> );

		const undoButton = await screen.findByRole( 'button', {
			name: /Undo Last Change/i,
		} );
		// Await the enabled state: clicking a still-disabled button is a
		// no-op, which starves the restore call (flaky on React 19).
		await waitFor( () => expect( undoButton ).toBeEnabled() );
		fireEvent.click( undoButton );

		await waitFor( () =>
			expect(
				screen.getByText( 'Error restoring settings.' )
			).toBeInTheDocument()
		);

		errorSpy.mockRestore();
	} );

	it( 'disables the Undo button when no snapshot exists', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { has_snapshot: false, taken_at: null },
		} );

		render( <PluginSetting options={ baseOptions } /> );

		await waitFor( () =>
			expect(
				screen.getByText( /No snapshot available yet/ )
			).toBeInTheDocument()
		);
		expect(
			screen.getByRole( 'button', { name: /Undo Last Change/i } )
		).toBeDisabled();
	} );
} );
