import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';

jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
	fetchRecentActivities: jest.fn(),
} ) );

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

import PluginSetting from '../PluginSetting';
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
			screen.getByRole( 'button', { name: /Export Settings/i } )
		);

		const blob = createObjectURL.mock.calls[ 0 ][ 0 ];
		expect( blob ).toBeInstanceOf( Blob );
		expect( anchorClick ).toHaveBeenCalled();

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
} );
