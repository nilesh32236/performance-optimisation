import {
	render,
	screen,
	waitFor,
	fireEvent,
	act,
} from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import FileOptimization, {
	normalizeRetries,
	normalizeDeliveryMode,
	stripPreviewParams,
	withCdnRowIds,
	stripCdnRowIds,
	SAFE_PRESET_BUNDLE,
	AGGRESSIVE_PRESET_BUNDLE,
	isAggressiveDelay,
	isSafePresetActive,
} from '../FileOptimization';

// Mock the API request (sandbox perf test goes through the validated
// runPerformanceScan wrapper; keep the real isValidScanUrl so validation
// behaviour is genuinely exercised).
jest.mock( '../../lib/apiRequest', () => {
	const actual = jest.requireActual( '../../lib/apiRequest' );
	return {
		...actual,
		apiCall: jest.fn(),
		runPerformanceScan: jest.fn(),
	};
} );

import { apiCall, runPerformanceScan } from '../../lib/apiRequest';

describe( 'FileOptimization Component', () => {
	beforeEach( () => {
		global.wppoSettings = {
			apiUrl: 'https://example.com/wp-json/performance-optimisation/v1/',
			nonce: 'test-nonce',
			settings: {},
			translations: {},
		};
		jest.clearAllMocks();
	} );

	it( 'renders the component and defaults to the assets tab', () => {
		render( <FileOptimization options={ {} } serverRules={ {} } /> );
		expect(
			screen.getByRole( 'tab', { name: /Assets/i } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'tab', { name: /Scripts/i } )
		).toBeInTheDocument();
		expect( screen.getByText( 'CSS Optimisation' ) ).toBeInTheDocument(); // Within assets tab
	} );

	it( 'updates form state when switch is toggled', () => {
		render(
			<FileOptimization
				options={ { minifyCSS: false } }
				serverRules={ {} }
			/>
		);
		const minifyCssSwitch = screen.getByLabelText( /Minify CSS/i );
		expect( minifyCssSwitch ).not.toBeChecked();

		fireEvent.click( minifyCssSwitch );
		expect( minifyCssSwitch ).toBeChecked();
	} );

	it( 'does not render the removed Remove Query Strings toggle', () => {
		render( <FileOptimization options={ {} } serverRules={ {} } /> );

		expect(
			screen.queryByLabelText(
				/Remove Query Strings From Static Resources/i
			)
		).not.toBeInTheDocument();
	} );

	it( 'submits settings successfully and displays success notification', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Settings updated successfully.',
		} );

		render( <FileOptimization options={ {} } serverRules={ {} } /> );

		const submitButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );

		await act( async () => {
			fireEvent.click( submitButton );
		} );

		expect( apiCall ).toHaveBeenCalledWith(
			'update_settings',
			expect.objectContaining( {
				tab: 'file_optimisation',
				settings: expect.any( Object ),
			} )
		);

		await waitFor( () => {
			expect(
				screen.getByText( 'Settings updated successfully.' )
			).toBeInTheDocument();
		} );
	} );

	it( 'submits settings but fails and displays error notification', async () => {
		apiCall.mockResolvedValueOnce( {
			success: false,
			message: 'Failed updating settings on server.',
		} );

		render( <FileOptimization options={ {} } serverRules={ {} } /> );

		const submitButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		await act( async () => {
			fireEvent.click( submitButton );
		} );

		await waitFor( () => {
			expect(
				screen.getByText( 'Failed updating settings on server.' )
			).toBeInTheDocument();
		} );
	} );

	it( 'handles sad path network error and logs to console', async () => {
		const mockError = new Error( 'Network Failure' );
		apiCall.mockRejectedValueOnce( mockError );

		const consoleSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );

		render( <FileOptimization options={ {} } serverRules={ {} } /> );

		const submitButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		await act( async () => {
			fireEvent.click( submitButton );
		} );

		await waitFor( () => {
			expect(
				screen.getByText( 'An unexpected error occurred.' )
			).toBeInTheDocument();
		} );

		expect( consoleSpy ).toHaveBeenCalledWith(
			'Failed to update settings.',
			mockError.message
		);

		consoleSpy.mockRestore();
	} );

	it( 'navigates sub-tabs using keyboard arrows', async () => {
		render( <FileOptimization options={ {} } serverRules={ {} } /> );

		const assetsTab = screen.getByRole( 'tab', { name: /Assets/i } );
		const scriptsTab = screen.getByRole( 'tab', { name: /Scripts/i } );

		assetsTab.focus();
		expect( assetsTab ).toHaveFocus();

		// Simulate right arrow
		fireEvent.keyDown( assetsTab, { key: 'ArrowRight' } );

		await waitFor( () => {
			expect( scriptsTab ).toHaveFocus();
		} );

		// Simulate left arrow on scripts tab
		fireEvent.keyDown( scriptsTab, { key: 'ArrowLeft' } );

		await waitFor( () => {
			expect( assetsTab ).toHaveFocus();
		} );

		// Simulate ignored key
		fireEvent.keyDown( assetsTab, { key: 'Enter' } );
		expect( assetsTab ).toHaveFocus();
	} );

	it( 'renders apache server rules correctly', () => {
		render(
			<FileOptimization
				options={ {} }
				serverRules={ { server_type: 'apache' } }
			/>
		);

		const networkTab = screen.getByRole( 'tab', { name: /Network/i } );
		fireEvent.click( networkTab );

		expect(
			screen.getByText( /Enable Server Rules/i )
		).toBeInTheDocument();
		const enableRulesSwitch =
			screen.getByLabelText( /Enable Server Rules/i );
		expect( enableRulesSwitch ).not.toBeDisabled();
	} );

	it( 'renders nginx server rules correctly', () => {
		render(
			<FileOptimization
				options={ {} }
				serverRules={ {
					server_type: 'nginx',
					nginx: 'nginx_rules_mock',
				} }
			/>
		);

		const networkTab = screen.getByRole( 'tab', { name: /Network/i } );
		fireEvent.click( networkTab );

		expect( screen.getByText( /Nginx Detected/i ) ).toBeInTheDocument();
		expect( screen.getByText( 'nginx_rules_mock' ) ).toBeInTheDocument();
	} );

	it( 'renders unrecognised server message for other servers', () => {
		render(
			<FileOptimization
				options={ {} }
				serverRules={ { server_type: 'other' } }
			/>
		);

		const networkTab = screen.getByRole( 'tab', { name: /Network/i } );
		fireEvent.click( networkTab );

		expect(
			screen.getByText( /Unrecognised server software/i )
		).toBeInTheDocument();
	} );

	it( 'renders Remove Unused CSS switch in assets tab', () => {
		render( <FileOptimization options={ {} } serverRules={ {} } /> );
		expect( screen.getByText( 'Remove Unused CSS' ) ).toBeInTheDocument();
	} );

	it( 'toggling Remove Unused CSS shows safelist textarea and regenerate button', () => {
		render( <FileOptimization options={ {} } serverRules={ {} } /> );
		const switchField = screen.getByLabelText( /Remove Unused CSS/i );
		fireEvent.click( switchField );
		expect( screen.getByText( 'Safelist Selectors' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Regenerate Used CSS' ) ).toBeInTheDocument();
	} );

	it( 'submit includes removeUnusedCSS and excludeUnusedCSS in settings payload', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Settings updated successfully.',
		} );

		render(
			<FileOptimization
				options={ {
					removeUnusedCSS: true,
					excludeUnusedCSS: '.my-class',
				} }
				serverRules={ {} }
			/>
		);

		const submitButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		await act( async () => {
			fireEvent.click( submitButton );
		} );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith(
				'update_settings',
				expect.objectContaining( {
					tab: 'file_optimisation',
					settings: expect.objectContaining( {
						removeUnusedCSS: true,
						excludeUnusedCSS: '.my-class',
					} ),
				} )
			);
		} );
	} );

	it( 'end-to-end: toggle Remove Unused CSS, type safelist, submit includes both', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Settings updated successfully.',
		} );

		render( <FileOptimization options={ {} } serverRules={ {} } /> );

		// Initially the safelist textarea and regenerate button should be hidden.
		expect(
			screen.queryByText( 'Safelist Selectors' )
		).not.toBeInTheDocument();

		// Toggle Remove Unused CSS on.
		const switchField = screen.getByLabelText( /Remove Unused CSS/i );
		fireEvent.click( switchField );

		// Now safelist and regenerate button should appear.
		expect( screen.getByText( 'Safelist Selectors' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Regenerate Used CSS' ) ).toBeInTheDocument();

		// Type into the safelist textarea.
		const textarea = screen.getByLabelText( 'Safelist Selectors' );
		fireEvent.change( textarea, {
			target: { value: '.my-safelist\n.another-rule' },
		} );

		// Submit the form.
		const submitButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		await act( async () => {
			fireEvent.click( submitButton );
		} );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith(
				'update_settings',
				expect.objectContaining( {
					tab: 'file_optimisation',
					settings: expect.objectContaining( {
						removeUnusedCSS: true,
						excludeUnusedCSS: '.my-safelist\n.another-rule',
					} ),
				} )
			);
		} );
	} );

	it( 'regenerate button calls used_css_regenerate API after saving settings', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
		} );
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Used CSS regeneration queued.',
		} );
		// Staleness-banner refresh after a successful regen (issue #1220).
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { is_stale: false },
		} );

		render( <FileOptimization options={ {} } serverRules={ {} } /> );

		const switchField = screen.getByLabelText( /Remove Unused CSS/i );
		fireEvent.click( switchField );

		const regenerateButton = screen.getByText( 'Regenerate Used CSS' );
		fireEvent.click( regenerateButton );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledTimes( 3 );
		} );

		expect( apiCall.mock.calls[ 0 ][ 0 ] ).toBe( 'update_settings' );
		expect( apiCall.mock.calls[ 0 ][ 1 ].tab ).toBe( 'file_optimisation' );
		expect( apiCall.mock.calls[ 0 ][ 1 ].settings.removeUnusedCSS ).toBe(
			true
		);

		expect( apiCall.mock.calls[ 1 ][ 0 ] ).toBe( 'used_css_regenerate' );

		expect( apiCall.mock.calls[ 2 ][ 0 ] ).toBe( 'used_css_status' );

		await waitFor( () => {
			expect(
				screen.getByText( 'Used CSS regeneration queued.' )
			).toBeInTheDocument();
		} );
	} );

	it( 'shows the logged-out smoke note when Remove Unused CSS is on', async () => {
		render( <FileOptimization options={ {} } serverRules={ {} } /> );

		const switchField = screen.getByLabelText( /Remove Unused CSS/i );
		fireEvent.click( switchField );

		await waitFor( () => {
			expect(
				screen.getByText( /logged-out \(incognito\) window/i )
			).toBeInTheDocument();
		} );
		expect(
			screen.getByText( 'Purge Page Cache + Used CSS' )
		).toBeInTheDocument();
	} );

	it( 'purge button calls purge_used_css_cache API and shows feedback', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Page cache and used CSS purged.',
		} );

		render( <FileOptimization options={ {} } serverRules={ {} } /> );

		const switchField = screen.getByLabelText( /Remove Unused CSS/i );
		fireEvent.click( switchField );

		const purgeButton = await screen.findByText(
			'Purge Page Cache + Used CSS'
		);
		fireEvent.click( purgeButton );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith( 'purge_used_css_cache' );
		} );

		await waitFor( () => {
			expect(
				screen.getByText( 'Page cache and used CSS purged.' )
			).toBeInTheDocument();
		} );
	} );

	it( 'does not call used_css_regenerate if saving settings fails', async () => {
		apiCall.mockResolvedValueOnce( {
			success: false,
			message: 'Settings save failed.',
		} );

		render( <FileOptimization options={ {} } serverRules={ {} } /> );

		const switchField = screen.getByLabelText( /Remove Unused CSS/i );
		fireEvent.click( switchField );

		const regenerateButton = screen.getByText( 'Regenerate Used CSS' );
		fireEvent.click( regenerateButton );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledTimes( 1 );
		} );

		expect( apiCall.mock.calls[ 0 ][ 0 ] ).toBe( 'update_settings' );

		await waitFor( () => {
			expect(
				screen.getByText( 'Settings save failed.' )
			).toBeInTheDocument();
		} );
	} );

	it( 'renders server rules correctly without double-encoding', () => {
		render(
			<FileOptimization
				options={ {} }
				serverRules={ {
					server_type: 'nginx',
					nginx: 'server { listen 80; }',
				} }
			/>
		);

		const networkTab = screen.getByRole( 'tab', { name: /Network/i } );
		fireEvent.click( networkTab );

		expect( screen.getByText( /Nginx Detected/i ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'server { listen 80; }' )
		).toBeInTheDocument();
	} );

	it( 'renders Critical CSS switch in the assets tab', () => {
		render(
			<FileOptimization
				options={ { criticalCSS: false } }
				serverRules={ {} }
			/>
		);

		expect( screen.getByLabelText( /Critical CSS/i ) ).toBeInTheDocument();
		expect( screen.getByLabelText( /Critical CSS/i ) ).not.toBeChecked();
	} );

	it( 'shows CriticalCssPanel when Critical CSS is enabled', () => {
		render(
			<FileOptimization
				options={ { criticalCSS: true } }
				serverRules={ {} }
				ccssStatus={ {} }
			/>
		);

		expect(
			screen.getByText( /Critical CSS Status/i )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: /Regenerate All/i } )
		).toBeInTheDocument();
	} );

	it( 'toggles Critical CSS switch to show/hide panel', () => {
		render(
			<FileOptimization
				options={ { criticalCSS: false } }
				serverRules={ {} }
				ccssStatus={ {} }
			/>
		);

		expect(
			screen.queryByText( /Critical CSS Status/i )
		).not.toBeInTheDocument();

		const criticalCssSwitch = screen.getByLabelText( /Critical CSS/i );
		fireEvent.click( criticalCssSwitch );

		expect(
			screen.getByText( /Critical CSS Status/i )
		).toBeInTheDocument();
	} );

	it( 'clears notification automatically after 3 seconds', async () => {
		jest.useFakeTimers();
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Settings updated successfully.',
		} );

		render( <FileOptimization options={ {} } serverRules={ {} } /> );

		const submitButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		await act( async () => {
			fireEvent.click( submitButton );
		} );

		await waitFor( () => {
			expect(
				screen.getByText( 'Settings updated successfully.' )
			).toBeInTheDocument();
		} );

		act( () => {
			jest.advanceTimersByTime( 3000 );
		} );
		await act( async () => {} );

		await waitFor( () => {
			expect(
				screen.queryByText( 'Settings updated successfully.' )
			).not.toBeInTheDocument();
		} );
		jest.useRealTimers();
	} );

	it( 'clears existing timeout when a new notification is triggered', async () => {
		jest.useFakeTimers();
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'First update.',
		} );

		render( <FileOptimization options={ {} } serverRules={ {} } /> );

		const submitButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		await act( async () => {
			fireEvent.click( submitButton );
		} );

		await waitFor( () => {
			expect( screen.getByText( 'First update.' ) ).toBeInTheDocument();
		} );

		// Setup second API call
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Second update.',
		} );

		// Advance time partially
		act( () => {
			jest.advanceTimersByTime( 1500 );
		} );
		await act( async () => {} );

		// Trigger second call
		await act( async () => {
			fireEvent.click( submitButton );
		} );

		await waitFor( () => {
			expect( screen.getByText( 'Second update.' ) ).toBeInTheDocument();
		} );

		// Advance remaining time for the first timer (1500ms). The notification should STILL be "Second update"
		// because the first timer was cleared.
		act( () => {
			jest.advanceTimersByTime( 1500 );
		} );
		await act( async () => {} );

		expect( screen.getByText( 'Second update.' ) ).toBeInTheDocument();

		// Now advance the remaining 1500ms to finish the second timer.
		act( () => {
			jest.advanceTimersByTime( 1500 );
		} );
		await act( async () => {} );

		await waitFor( () => {
			expect(
				screen.queryByText( 'Second update.' )
			).not.toBeInTheDocument();
		} );

		jest.useRealTimers();
	} );

	it( 'shows an error notice when handleRegenerateCss fails', async () => {
		const mockError = new Error( 'CCSS Generation Failed' );
		apiCall.mockRejectedValueOnce( mockError );

		const consoleSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );

		render(
			<FileOptimization
				options={ { criticalCSS: true } }
				serverRules={ {} }
				ccssStatus={ {} }
			/>
		);

		const regenerateButton = screen.getByRole( 'button', {
			name: /Regenerate All/i,
		} );
		fireEvent.click( regenerateButton );

		await waitFor( () => {
			expect( consoleSpy ).toHaveBeenCalledWith(
				'Failed to regenerate critical CSS.',
				mockError.message
			);
		} );

		expect(
			screen.getByText( 'An unexpected error occurred.' )
		).toBeInTheDocument();

		consoleSpy.mockRestore();
	} );

	it( 'shows queued message and refreshes CCSS on success', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Critical CSS regeneration: 2 jobs queued.',
		} );
		const onCcssRefresh = jest.fn();

		render(
			<FileOptimization
				options={ { criticalCSS: true } }
				serverRules={ {} }
				ccssStatus={ { test_hash: { status: 'none', label: 'Test' } } }
				onCcssRefresh={ onCcssRefresh }
			/>
		);

		const regenerateButton = screen.getByRole( 'button', {
			name: /Regenerate All/i,
		} );
		fireEvent.click( regenerateButton );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith( 'regenerate_ccss' );
		} );

		expect( apiCall ).toHaveBeenCalledTimes( 1 );
		await waitFor( () => {
			expect(
				screen.getByText( 'Critical CSS regeneration: 2 jobs queued.' )
			).toBeInTheDocument();
		} );
		expect( onCcssRefresh ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'calls apiCall when Regenerate All is clicked', async () => {
		apiCall.mockResolvedValueOnce( { success: true } );

		render(
			<FileOptimization
				options={ { criticalCSS: true } }
				serverRules={ {} }
				ccssStatus={ { test_hash: { status: 'none', label: 'Test' } } }
				onCcssRefresh={ jest.fn() }
			/>
		);

		const regenerateButton = screen.getByRole( 'button', {
			name: /Regenerate All/i,
		} );
		fireEvent.click( regenerateButton );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith( 'regenerate_ccss' );
		} );

		expect( apiCall ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'displays template label from ccssStatus object', () => {
		render(
			<FileOptimization
				options={ { criticalCSS: true } }
				serverRules={ {} }
				ccssStatus={ {
					abc123: { status: 'ready', label: 'Home' },
					def456: { status: 'none', label: 'Single Post' },
				} }
			/>
		);

		expect( screen.getByText( 'Home' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Single Post' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Generated' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Not Generated' ) ).toBeInTheDocument();
	} );

	it( 'renders Host Google Fonts Locally switch in the assets tab', () => {
		render(
			<FileOptimization
				options={ { hostGoogleFontsLocally: false } }
				serverRules={ {} }
			/>
		);

		expect(
			screen.getByLabelText( /Host Google Fonts Locally/i )
		).toBeInTheDocument();
		expect(
			screen.getByLabelText( /Host Google Fonts Locally/i )
		).not.toBeChecked();
	} );

	it( 'submits hostGoogleFontsLocally setting correctly', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Settings updated successfully.',
		} );

		render(
			<FileOptimization
				options={ { hostGoogleFontsLocally: false } }
				serverRules={ {} }
			/>
		);

		const switchField = screen.getByLabelText(
			/Host Google Fonts Locally/i
		);
		fireEvent.click( switchField );
		expect( switchField ).toBeChecked();

		const submitButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		await act( async () => {
			fireEvent.click( submitButton );
		} );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith(
				'update_settings',
				expect.objectContaining( {
					tab: 'file_optimisation',
					settings: expect.objectContaining( {
						hostGoogleFontsLocally: true,
					} ),
				} )
			);
		} );
	} );

	it( 'renders Font Subsetting switch and submits subsets correctly', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Settings updated successfully.',
		} );

		render(
			<FileOptimization
				options={ { hostGoogleFontsLocally: true } }
				serverRules={ {} }
			/>
		);

		const subsetSwitch = screen.getByLabelText( /Font Subsetting/i );
		expect( subsetSwitch ).toBeInTheDocument();
		expect( subsetSwitch ).not.toBeChecked();

		fireEvent.click( subsetSwitch );
		expect( subsetSwitch ).toBeChecked();

		const subsetsInput = screen.getByLabelText( /Font Subsets/i );
		expect( subsetsInput ).toBeInTheDocument();
		fireEvent.change( subsetsInput, {
			target: { value: 'latin,latin-ext' },
		} );

		const submitButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		await act( async () => {
			fireEvent.click( submitButton );
		} );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith(
				'update_settings',
				expect.objectContaining( {
					tab: 'file_optimisation',
					settings: expect.objectContaining( {
						fontSubset: true,
						fontSubsetSubsets: 'latin,latin-ext',
					} ),
				} )
			);
		} );
	} );

	it( 'renders delay JS strategy selector when delayJS is enabled', () => {
		render(
			<FileOptimization
				options={ { delayJS: true } }
				serverRules={ {} }
			/>
		);

		const scriptsTab = screen.getByRole( 'tab', { name: /Scripts/i } );
		fireEvent.click( scriptsTab );

		expect(
			screen.getByLabelText( 'Default Load Strategy' )
		).toBeInTheDocument();
		expect(
			screen.getByLabelText( 'Scripts to Load When Idle' )
		).toBeInTheDocument();
		expect(
			screen.getByLabelText( 'Scripts to Load in Viewport' )
		).toBeInTheDocument();
		expect(
			screen.getByLabelText( 'Script Priority' )
		).toBeInTheDocument();
	} );

	it( 'defaults strategy to interaction when not configured', () => {
		render(
			<FileOptimization
				options={ { delayJS: true } }
				serverRules={ {} }
			/>
		);

		const scriptsTab = screen.getByRole( 'tab', { name: /Scripts/i } );
		fireEvent.click( scriptsTab );

		expect( screen.getByLabelText( 'Default Load Strategy' ) ).toHaveValue(
			'interaction'
		);
	} );

	it( 'submits delay JS strategy settings correctly', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Settings updated successfully.',
		} );

		render(
			<FileOptimization
				options={ {
					delayJS: true,
					delayJSDefaultStrategy: 'idle',
					delayJSIdleList: 'jquery-core',
					delayJSViewportList: 'analytics',
					delayJSPriority: 'jquery-core:high',
				} }
				serverRules={ {} }
			/>
		);

		const submitButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		await act( async () => {
			fireEvent.click( submitButton );
		} );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith(
				'update_settings',
				expect.objectContaining( {
					tab: 'file_optimisation',
					settings: expect.objectContaining( {
						delayJSDefaultStrategy: 'idle',
						delayJSIdleList: 'jquery-core',
						delayJSViewportList: 'analytics',
						delayJSPriority: 'jquery-core:high',
						delayJSIdleTimeout: 3000,
					} ),
				} )
			);
		} );
	} );

	it( 'shows idle timeout input when idle strategy is selected', () => {
		render(
			<FileOptimization
				options={ {
					delayJS: true,
					delayJSDefaultStrategy: 'idle',
				} }
				serverRules={ {} }
			/>
		);

		const scriptsTab = screen.getByRole( 'tab', { name: /Scripts/i } );
		fireEvent.click( scriptsTab );

		expect(
			screen.getByLabelText( 'Idle Timeout (ms)' )
		).toBeInTheDocument();
	} );

	it( 'toggles INP-first preset, fills strategy + heartbeat, and persists via update_settings', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Settings updated successfully.',
		} );

		render(
			<FileOptimization
				options={ { delayJS: true } }
				serverRules={ {} }
			/>
		);

		const scriptsTab = screen.getByRole( 'tab', { name: /Scripts/i } );
		fireEvent.click( scriptsTab );

		const presetToggle = screen.getByLabelText( /INP-first preset/i );
		expect( presetToggle ).not.toBeChecked();

		fireEvent.click( presetToggle );
		expect( presetToggle ).toBeChecked();
		// One-click fill: idle strategy + 60s heartbeat from defaults.
		expect( screen.getByLabelText( 'Default Load Strategy' ) ).toHaveValue(
			'idle'
		);

		const submitButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		await act( async () => {
			fireEvent.click( submitButton );
		} );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith(
				'update_settings',
				expect.objectContaining( {
					tab: 'file_optimisation',
					settings: expect.objectContaining( {
						delayJSINPPreset: true,
						delayJSDefaultStrategy: 'idle',
						heartbeatControl: '60s',
					} ),
				} )
			);
		} );
	} );

	it( 'toggles auto-delay for known third parties and persists via update_settings', async () => {
		// Opening the Scripts tab hydrates sandbox state first, so the
		// first mock serves sandbox_preview and the second serves the save.
		apiCall
			.mockResolvedValueOnce( {
				success: true,
				data: { staged: {}, has_staged: false, preview_url: '' },
			} )
			.mockResolvedValueOnce( {
				success: true,
				message: 'Settings updated successfully.',
			} );

		render(
			<FileOptimization
				options={ { delayJS: true, delayJSThirdParty: true } }
				serverRules={ {} }
			/>
		);

		const scriptsTab = screen.getByRole( 'tab', { name: /Scripts/i } );
		fireEvent.click( scriptsTab );

		const autoToggle = screen.getByLabelText(
			/Auto-delay known third parties/i
		);
		expect( autoToggle ).not.toBeChecked();

		fireEvent.click( autoToggle );
		expect( autoToggle ).toBeChecked();

		const submitButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		await act( async () => {
			fireEvent.click( submitButton );
		} );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith(
				'update_settings',
				expect.objectContaining( {
					tab: 'file_optimisation',
					settings: expect.objectContaining( {
						delayJSThirdPartyAuto: true,
					} ),
				} )
			);
		} );

		await waitFor( () => {
			// The success banner renders in each visible tab section,
			// so assert on all matches instead of a single node.
			expect(
				screen.getAllByText( 'Settings updated successfully.' ).length
			).toBeGreaterThan( 0 );
		} );
	} );

	it( 'shows the auto-delay toggle standalone when third-party mode is off', () => {
		render(
			<FileOptimization
				options={ { delayJS: true, delayJSThirdParty: false } }
				serverRules={ {} }
			/>
		);

		const scriptsTab = screen.getByRole( 'tab', { name: /Scripts/i } );
		fireEvent.click( scriptsTab );

		// Auto mode is an independent OR with manual mode on the backend, so
		// the toggle renders standalone (auto-only is a supported state).
		expect(
			screen.getByLabelText( /Auto-delay known third parties/i )
		).toBeInTheDocument();
	} );

	it( 'toggles Remove HTML Comments switch', () => {
		render(
			<FileOptimization
				options={ { removeHTMLComments: true } }
				serverRules={ {} }
			/>
		);
		const switchEl = screen.getByLabelText( /Remove HTML Comments/i );
		expect( switchEl ).toBeChecked();
		fireEvent.click( switchEl );
		expect( switchEl ).not.toBeChecked();
	} );

	it( 'toggles Minify Inline CSS switch', () => {
		render(
			<FileOptimization
				options={ { minifyInlineCSS: false } }
				serverRules={ {} }
			/>
		);
		const switchEl = screen.getByLabelText( /Minify Inline CSS/i );
		expect( switchEl ).not.toBeChecked();
		fireEvent.click( switchEl );
		expect( switchEl ).toBeChecked();
	} );

	it( 'toggles Minify Inline JavaScript switch', () => {
		render(
			<FileOptimization
				options={ { minifyInlineJS: false } }
				serverRules={ {} }
			/>
		);
		const switchEl = screen.getByLabelText( /Minify Inline JavaScript/i );
		expect( switchEl ).not.toBeChecked();
		fireEvent.click( switchEl );
		expect( switchEl ).toBeChecked();
	} );

	it( 'renders the extended core-bloat toggles', () => {
		render( <FileOptimization options={ {} } serverRules={ {} } /> );

		const coreTab = screen.getByRole( 'tab', { name: /Core/i } );
		fireEvent.click( coreTab );

		expect(
			screen.getByLabelText( /Remove REST API Links/i )
		).toBeInTheDocument();
		expect(
			screen.getByLabelText( /Disable RSS Feeds/i )
		).toBeInTheDocument();
		expect(
			screen.getByLabelText( /Remove Generator Meta Tag/i )
		).toBeInTheDocument();
		expect(
			screen.getByLabelText( /Remove jQuery Migrate/i )
		).toBeInTheDocument();
	} );

	it( 'renders LiteSpeed server rules correctly', () => {
		render(
			<FileOptimization
				options={ {} }
				serverRules={ { server_type: 'litespeed' } }
			/>
		);

		const networkTab = screen.getByRole( 'tab', { name: /Network/i } );
		fireEvent.click( networkTab );

		expect( screen.getByText( /LiteSpeed Detected/i ) ).toBeInTheDocument();
		expect(
			screen.getByLabelText( /Enable Server Rules/i )
		).toBeInTheDocument();
		expect(
			screen.getByLabelText( /Enable Server Rules/i )
		).not.toBeDisabled();
	} );

	it( 'checks commerce safe preset by default and textarea change flows into the save payload', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Settings updated successfully.',
		} );

		render(
			<FileOptimization
				options={ { delayJS: true } }
				serverRules={ {} }
			/>
		);

		const scriptsTab = screen.getByRole( 'tab', { name: /Scripts/i } );
		fireEvent.click( scriptsTab );

		expect(
			screen.getByLabelText( /Commerce safe preset/i )
		).toBeChecked();

		const textarea = screen.getByLabelText(
			/Disable Delay JS on these URLs/i
		);
		fireEvent.change( textarea, {
			target: { value: 'https://example.com/checkout/' },
		} );

		const submitButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		await act( async () => {
			fireEvent.click( submitButton );
		} );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith(
				'update_settings',
				expect.objectContaining( {
					tab: 'file_optimisation',
					settings: expect.objectContaining( {
						delayJSExcludeUrls: 'https://example.com/checkout/',
					} ),
				} )
			);
		} );
	} );

	it( 'shows the aggressive-mode banner only when a safe preset is off', () => {
		render(
			<FileOptimization
				options={ {
					delayJS: true,
					delayJSCommercePreset: true,
					delayJSBuilderPreset: true,
				} }
				serverRules={ {} }
			/>
		);

		const scriptsTab = screen.getByRole( 'tab', { name: /Scripts/i } );
		fireEvent.click( scriptsTab );

		expect(
			screen.queryByText( /Aggressive mode/i )
		).not.toBeInTheDocument();

		fireEvent.click( screen.getByLabelText( /Commerce safe preset/i ) );

		expect( screen.getByText( /Aggressive mode/i ) ).toBeInTheDocument();
	} );

	it( 'Network tab renders LiteSpeed (Apache-compatible) when server_type=litespeed and shows Cache-Vary', () => {
		global.wppoSettings.litespeed = {
			detected: true,
			server_type: 'litespeed',
			effective_mode: 'wppo',
			lscache_active: false,
		};
		render(
			<FileOptimization
				options={ {} }
				serverRules={ {
					server_type: 'litespeed',
					nginx: 'Cache-Vary: ismobile,webp',
					apache: 'Header append Vary Accept',
				} }
			/>
		);
		const networkTab = screen.getByRole( 'tab', { name: /Network/i } );
		fireEvent.click( networkTab );
		expect( screen.getByText( /LiteSpeed Detected/i ) ).toBeInTheDocument();
		expect( screen.getAllByText( /Server Rules/i ).length ).toBeGreaterThan(
			0
		);
	} );

	it( 'renders sandbox preview controls with promote and discard', () => {
		render( <FileOptimization options={ {} } serverRules={ {} } /> );
		fireEvent.click( screen.getByRole( 'tab', { name: /Scripts/i } ) );
		expect(
			screen.getByText( /Sandbox preview — test Delay/i )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: /Stage preview/i } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: /^Promote$/i } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: /Discard/i } )
		).toBeInTheDocument();
	} );

	it( 'stages sandbox preview then promotes', async () => {
		apiCall
			.mockResolvedValueOnce( {
				success: true,
				data: {
					staged: {},
					has_staged: false,
					preview_url: '',
				},
			} )
			.mockResolvedValueOnce( {
				success: true,
				data: { staged: { delayJS: true } },
			} )
			.mockResolvedValueOnce( {
				success: true,
				data: {
					staged: {},
					preview_url: 'http://example.com/?wppo_preview=assets',
				},
			} )
			.mockResolvedValueOnce( {
				success: true,
				data: {
					file_optimisation: { delayJS: true },
					staged: {},
				},
			} );
		render( <FileOptimization options={ {} } serverRules={ {} } /> );
		fireEvent.click( screen.getByRole( 'tab', { name: /Scripts/i } ) );
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: /Stage preview/i } )
			);
		} );
		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith(
				'sandbox_save',
				expect.objectContaining( { settings: expect.any( Object ) } )
			);
		} );
		// Staged-exists note appears after staging.
		await waitFor( () => {
			expect(
				screen.getByText( /A staged preview exists/i )
			).toBeInTheDocument();
		} );
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: /^Promote$/i } )
			);
		} );
		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith( 'sandbox_promote', {} );
		} );
		// Post-promote form sync: the promoted Delay JS value reaches the
		// production form control and the staged-exists note clears.
		await waitFor( () => {
			expect(
				screen.getByLabelText( /Delay JavaScript Execution/i )
			).toBeChecked();
		} );
		await waitFor( () => {
			expect(
				screen.queryByText( /A staged preview exists/i )
			).not.toBeInTheDocument();
		} );
	} );

	it( 'discards sandbox preview and clears the preview link', async () => {
		apiCall
			.mockResolvedValueOnce( {
				success: true,
				data: {
					staged: { delayJS: true },
					has_staged: true,
					preview_url:
						'http://example.com/?wppo_preview=assets&_wppo_preview_nonce=abc',
				},
			} )
			.mockResolvedValueOnce( { success: true, data: { staged: {} } } );
		render( <FileOptimization options={ {} } serverRules={ {} } /> );
		fireEvent.click( screen.getByRole( 'tab', { name: /Scripts/i } ) );

		await waitFor( () => {
			expect(
				screen.getByText( /A staged preview exists/i )
			).toBeInTheDocument();
		} );
		expect(
			screen.getByRole( 'link', { name: /Open admin preview/i } )
		).toBeInTheDocument();

		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: /Discard/i } )
			);
		} );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith( 'sandbox_discard', {} );
		} );
		// The stale preview link (nonce + staged values are gone) must be
		// removed, along with the staged-exists note.
		await waitFor( () => {
			expect(
				screen.queryByRole( 'link', { name: /Open admin preview/i } )
			).not.toBeInTheDocument();
		} );
		expect(
			screen.queryByText( /A staged preview exists/i )
		).not.toBeInTheDocument();
		await waitFor( () => {
			expect(
				screen.getByText( /Preview discarded/i )
			).toBeInTheDocument();
		} );
	} );

	it( 'stages preview-relevant toggles delayJSExternalOnly and minifyInlineJS', async () => {
		apiCall
			.mockResolvedValueOnce( {
				success: true,
				data: { staged: {}, has_staged: false, preview_url: '' },
			} )
			.mockResolvedValueOnce( {
				success: true,
				data: { staged: { delayJS: true } },
			} )
			.mockResolvedValueOnce( {
				success: true,
				data: { staged: {}, preview_url: '' },
			} );
		render( <FileOptimization options={ {} } serverRules={ {} } /> );
		fireEvent.click( screen.getByRole( 'tab', { name: /Scripts/i } ) );
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: /Stage preview/i } )
			);
		} );
		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith(
				'sandbox_save',
				expect.objectContaining( {
					settings: expect.objectContaining( {
						delayJSExternalOnly: expect.any( Boolean ),
						minifyInlineJS: expect.any( Boolean ),
					} ),
				} )
			);
		} );
	} );

	it( 'shows an error banner when staging fails', async () => {
		apiCall
			.mockResolvedValueOnce( {
				success: true,
				data: { staged: {}, has_staged: false, preview_url: '' },
			} )
			.mockRejectedValueOnce( new Error( 'nope' ) );
		render( <FileOptimization options={ {} } serverRules={ {} } /> );
		fireEvent.click( screen.getByRole( 'tab', { name: /Scripts/i } ) );
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: /Stage preview/i } )
			);
		} );
		await waitFor( () => {
			expect(
				screen.getByText( /Could not stage the preview/i )
			).toBeInTheDocument();
		} );
	} );

	it( 'shows a readable error when promote fails with a non-string payload', async () => {
		apiCall
			.mockResolvedValueOnce( {
				success: true,
				data: { staged: {}, has_staged: false, preview_url: '' },
			} )
			.mockResolvedValueOnce( {
				success: false,
				data: { errors: [ 'db failed' ] },
				message: '',
			} );
		render( <FileOptimization options={ {} } serverRules={ {} } /> );
		fireEvent.click( screen.getByRole( 'tab', { name: /Scripts/i } ) );
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: /^Promote$/i } )
			);
		} );
		await waitFor( () => {
			expect(
				screen.getByText( /Could not promote/i )
			).toBeInTheDocument();
		} );
		// The object payload must never leak into the banner text.
		expect( document.body.textContent ).not.toMatch( /\[object Object\]/ );
	} );

	it( 'hydrates sandbox status when the Scripts tab opens', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				staged: { delayJS: true },
				has_staged: true,
				preview_url: 'http://example.com/?wppo_preview=assets',
			},
		} );
		render( <FileOptimization options={ {} } serverRules={ {} } /> );
		fireEvent.click( screen.getByRole( 'tab', { name: /Scripts/i } ) );

		await waitFor( () => {
			// Audit #1420: sandbox fetch carries an AbortSignal now.
			expect( apiCall ).toHaveBeenCalledWith(
				'sandbox_preview',
				{},
				'GET',
				expect.any( AbortSignal )
			);
		} );
		await waitFor( () => {
			expect(
				screen.getByText( /A staged preview exists/i )
			).toBeInTheDocument();
		} );
	} );

	it( 'runs the perf test against the production URL without preview params', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				staged: { delayJS: true },
				has_staged: true,
				preview_url:
					'http://example.com/?wppo_preview=assets&_wppo_preview_nonce=abc',
			},
		} );
		runPerformanceScan.mockResolvedValueOnce( {
			success: true,
			data: { score: 95 },
		} );
		render( <FileOptimization options={ {} } serverRules={ {} } /> );
		fireEvent.click( screen.getByRole( 'tab', { name: /Scripts/i } ) );

		const perfButton = await screen.findByRole( 'button', {
			name: /Run perf test \(production URL\)/i,
		} );
		await waitFor( () => {
			expect( perfButton ).not.toBeDisabled();
		} );
		await act( async () => {
			fireEvent.click( perfButton );
		} );

		await waitFor( () => {
			expect( runPerformanceScan ).toHaveBeenCalledWith(
				'http://example.com/'
			);
		} );
		await waitFor( () => {
			expect(
				screen.getByText( /cannot use the admin preview session/i )
			).toBeInTheDocument();
		} );
	} );

	it( 'blocks the perf test when the preview URL fails validation', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				staged: { delayJS: true },
				has_staged: true,
				preview_url: 'javascript:alert(1)',
			},
		} );
		render( <FileOptimization options={ {} } serverRules={ {} } /> );
		fireEvent.click( screen.getByRole( 'tab', { name: /Scripts/i } ) );

		const perfButton = await screen.findByRole( 'button', {
			name: /Run perf test \(production URL\)/i,
		} );
		await waitFor( () => {
			expect( perfButton ).not.toBeDisabled();
		} );
		await act( async () => {
			fireEvent.click( perfButton );
		} );

		// The tampered preview URL never reaches the scan endpoint.
		await waitFor( () => {
			expect(
				screen.getByText( /not a valid same-origin http/i )
			).toBeInTheDocument();
		} );
		expect( runPerformanceScan ).not.toHaveBeenCalled();
		expect( apiCall ).not.toHaveBeenCalledWith(
			'performance_scan',
			expect.anything()
		);
	} );

	it( 'does not render a clickable preview link for non-http(s) preview URLs', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				staged: { delayJS: true },
				has_staged: true,
				preview_url: 'javascript:alert(1)',
			},
		} );
		render( <FileOptimization options={ {} } serverRules={ {} } /> );
		fireEvent.click( screen.getByRole( 'tab', { name: /Scripts/i } ) );

		await screen.findByText( /A staged preview exists/i );
		// No anchor is rendered for the javascript: URL — only plain text,
		// so clicking can never execute it.
		expect(
			screen.queryByRole( 'link', { name: /Open admin preview/i } )
		).toBeNull();
		expect( screen.getByText( 'Open admin preview' ) ).toBeInTheDocument();
	} );

	it( 'renders a hardened preview link for valid http(s) preview URLs', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				staged: { delayJS: true },
				has_staged: true,
				preview_url:
					'http://example.com/?wppo_preview=assets&_wppo_preview_nonce=abc',
			},
		} );
		render( <FileOptimization options={ {} } serverRules={ {} } /> );
		fireEvent.click( screen.getByRole( 'tab', { name: /Scripts/i } ) );

		const previewLink = await screen.findByRole( 'link', {
			name: /Open admin preview/i,
		} );
		expect( previewLink ).toHaveAttribute(
			'href',
			'http://example.com/?wppo_preview=assets&_wppo_preview_nonce=abc'
		);
		expect( previewLink ).toHaveAttribute( 'rel', 'noopener noreferrer' );
	} );

	it( 'purge derived caches button calls purge_derived_caches and shows feedback', async () => {
		apiCall.mockResolvedValue( { success: true, data: {} } );
		apiCall.mockResolvedValueOnce( { success: true, data: {} } );
		render( <FileOptimization options={ {} } serverRules={ {} } /> );
		fireEvent.click( screen.getByRole( 'tab', { name: /Scripts/i } ) );

		const purgeButton = await screen.findByText( 'Purge Derived Caches' );
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Page cache, used CSS and critical CSS purged.',
			data: { reason: 'manual purge', time: 123 },
		} );
		fireEvent.click( purgeButton );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith( 'purge_derived_caches' );
		} );
		await waitFor( () => {
			expect(
				screen.getByText(
					'Page cache, used CSS and critical CSS purged.'
				)
			).toBeInTheDocument();
		} );
	} );

	describe( 'normalizeRetries', () => {
		it( 'clamps valid values to 0..5 with truncation', () => {
			expect( normalizeRetries( 3 ) ).toBe( 3 );
			expect( normalizeRetries( '3.7' ) ).toBe( 3 );
			expect( normalizeRetries( '+3' ) ).toBe( 3 );
			expect( normalizeRetries( '1e2' ) ).toBe( 5 );
			expect( normalizeRetries( 99 ) ).toBe( 5 );
			expect( normalizeRetries( -2 ) ).toBe( 0 );
			expect( normalizeRetries( 0 ) ).toBe( 0 );
		} );

		it( 'fails open to 5 on missing or malformed input', () => {
			expect( normalizeRetries( undefined ) ).toBe( 5 );
			expect( normalizeRetries( '' ) ).toBe( 5 );
			expect( normalizeRetries( 'abc' ) ).toBe( 5 );
			expect( normalizeRetries( [ '3' ] ) ).toBe( 5 );
			expect( normalizeRetries( NaN ) ).toBe( 5 );
		} );

		it( 'rejects hex/binary/octal like PHP is_numeric', () => {
			expect( normalizeRetries( '0x3' ) ).toBe( 5 );
			expect( normalizeRetries( '0b101' ) ).toBe( 5 );
			expect( normalizeRetries( '0o17' ) ).toBe( 5 );
		} );
	} );

	describe( 'normalizeDeliveryMode', () => {
		it( 'accepts allowlisted string modes case-insensitively', () => {
			expect( normalizeDeliveryMode( 'Delay' ) ).toBe( 'delay' );
			expect( normalizeDeliveryMode( ' async ' ) ).toBe( 'async' );
		} );

		it( 'fails open to file for non-strings and unknown modes', () => {
			expect( normalizeDeliveryMode( [ 'delay' ] ) ).toBe( 'file' );
			expect( normalizeDeliveryMode( 42 ) ).toBe( 'file' );
			expect( normalizeDeliveryMode( 'eager' ) ).toBe( 'file' );
		} );
	} );

	it( 'shows the SPA-visible last-purge reason and safe preview link', async () => {
		global.wppoSettings.upgradePurge = {
			last_purge: { reason: 'plugin akismet/akismet.php', time: 123 },
			safe_preview_url: 'https://example.com/?wppo_nocache=1',
		};
		apiCall.mockResolvedValue( { success: true, data: {} } );
		render( <FileOptimization options={ {} } serverRules={ {} } /> );
		fireEvent.click( screen.getByRole( 'tab', { name: /Scripts/i } ) );

		expect(
			await screen.findByText( /Last purge: plugin akismet/i )
		).toBeInTheDocument();
		const safeLink = screen.getByRole( 'link', {
			name: /bypasses minify/i,
		} );
		expect( safeLink ).toHaveAttribute(
			'href',
			'https://example.com/?wppo_nocache=1'
		);
	} );

	describe( 'stripPreviewParams', () => {
		it( 'strips preview args from absolute http(s) URLs', () => {
			expect(
				stripPreviewParams(
					'https://example.com/?wppo_preview=1&_wppo_preview_nonce=abc&p=1'
				)
			).toBe( 'https://example.com/?p=1' );
		} );

		it( 'rejects non-http(s) and relative URLs', () => {
			expect( stripPreviewParams( 'javascript:alert(1)' ) ).toBe( '' );
			expect( stripPreviewParams( '/relative/path' ) ).toBe( '' );
			expect( stripPreviewParams( '' ) ).toBe( '' );
		} );
	} );

	describe( 'withCdnRowIds / stripCdnRowIds', () => {
		it( 'assigns deterministic index ids and preserves existing ids', () => {
			const rows = withCdnRowIds( [
				{ cdn_url: 'a' },
				{ cdn_url: 'b', id: 'cdn-9' },
			] );
			expect( rows[ 0 ].id ).toBe( 'cdn-row-0' );
			expect( rows[ 1 ].id ).toBe( 'cdn-9' );
			expect( withCdnRowIds( 'corrupt' ) ).toEqual( [] );
		} );

		it( 'strips client ids without touching other keys', () => {
			const stripped = stripCdnRowIds( {
				cdnURL: 'x',
				cdnMapping: [ { id: 'cdn-row-0', cdn_url: 'a' } ],
			} );
			expect( stripped ).toEqual( {
				cdnURL: 'x',
				cdnMapping: [ { cdn_url: 'a' } ],
			} );
		} );
	} );

	describe( 'Safe / Aggressive presets (issue #1442)', () => {
		it( 'safe bundle enables pipelines with builder, jQuery and Woo excludes', () => {
			expect( SAFE_PRESET_BUNDLE ).toEqual(
				expect.objectContaining( {
					minifyJS: true,
					minifyCSS: true,
					deferJS: true,
					delayJS: true,
					delayJSBuilderPreset: true,
					delayJSCommercePreset: true,
					delayJSInteractionPreset: true,
					delayJSJqueryPreset: true,
					combineCSS: false,
				} )
			);
		} );

		it( 'aggressive bundle drops the safe presets', () => {
			expect( AGGRESSIVE_PRESET_BUNDLE.delayJS ).toBe( true );
			expect( AGGRESSIVE_PRESET_BUNDLE.delayJSBuilderPreset ).toBe(
				false
			);
			expect( AGGRESSIVE_PRESET_BUNDLE.delayJSCommercePreset ).toBe(
				false
			);
		} );

		it( 'isAggressiveDelay gates on delay plus a missing safe preset', () => {
			expect( isAggressiveDelay( { delayJS: false } ) ).toBe( false );
			expect(
				isAggressiveDelay( {
					delayJS: true,
					delayJSBuilderPreset: true,
					delayJSCommercePreset: true,
					delayJSInteractionPreset: true,
				} )
			).toBe( false );
			expect(
				isAggressiveDelay( {
					delayJS: true,
					delayJSBuilderPreset: false,
					delayJSCommercePreset: true,
					delayJSInteractionPreset: true,
				} )
			).toBe( true );
		} );

		it( 'isSafePresetActive requires pipelines plus all four safe presets', () => {
			expect(
				isSafePresetActive( {
					minifyJS: true,
					minifyCSS: true,
					deferJS: true,
					delayJS: true,
					delayJSBuilderPreset: true,
					delayJSCommercePreset: true,
					delayJSInteractionPreset: true,
					delayJSJqueryPreset: true,
				} )
			).toBe( true );
			expect(
				isSafePresetActive( {
					minifyJS: true,
					minifyCSS: true,
					deferJS: true,
					delayJS: true,
					delayJSBuilderPreset: true,
					delayJSCommercePreset: true,
					delayJSInteractionPreset: true,
					delayJSJqueryPreset: false,
				} )
			).toBe( false );
			expect( isSafePresetActive( {} ) ).toBe( false );
		} );

		it( 'renders the preset panel on the assets tab', () => {
			apiCall.mockResolvedValue( { success: true, data: {} } );
			render( <FileOptimization options={ {} } serverRules={ {} } /> );
			expect(
				screen.getByRole( 'button', { name: /Apply Safe Preset/i } )
			).toBeInTheDocument();
			expect(
				screen.getByRole( 'button', {
					name: /Enable Aggressive Mode/i,
				} )
			).toBeInTheDocument();
			expect(
				screen.getByRole( 'button', { name: /Revert to Previous/i } )
			).toBeInTheDocument();
		} );

		it( 'safe preset button saves the bundle and shows success', async () => {
			apiCall.mockImplementation( ( endpoint ) => {
				if ( 'update_settings' === endpoint ) {
					return Promise.resolve( {
						success: true,
						message: 'Settings updated successfully.',
						data: {},
					} );
				}
				return Promise.resolve( { success: true, data: {} } );
			} );
			render( <FileOptimization options={ {} } serverRules={ {} } /> );

			await act( async () => {
				fireEvent.click(
					screen.getByRole( 'button', {
						name: /Apply Safe Preset/i,
					} )
				);
			} );

			await waitFor( () => {
				expect( apiCall ).toHaveBeenCalledWith(
					'update_settings',
					expect.objectContaining( {
						tab: 'file_optimisation',
						settings: expect.objectContaining( {
							minifyJS: true,
							deferJS: true,
							delayJS: true,
							delayJSBuilderPreset: true,
							delayJSCommercePreset: true,
							delayJSJqueryPreset: true,
						} ),
					} )
				);
			} );
			await waitFor( () => {
				expect(
					screen.getByText( /Safe preset applied/i )
				).toBeInTheDocument();
			} );
		} );

		it( 'failed preset apply leaves settings untouched with an error', async () => {
			apiCall.mockImplementation( ( endpoint ) => {
				if ( 'update_settings' === endpoint ) {
					return Promise.reject( new Error( 'network down' ) );
				}
				return Promise.resolve( { success: true, data: {} } );
			} );
			const errorSpy = jest
				.spyOn( console, 'error' )
				.mockImplementation( () => {} );
			render(
				<FileOptimization
					options={ { minifyJS: false } }
					serverRules={ {} }
				/>
			);

			await act( async () => {
				fireEvent.click(
					screen.getByRole( 'button', {
						name: /Apply Safe Preset/i,
					} )
				);
			} );

			await waitFor( () => {
				expect(
					screen.getByText( /settings left unchanged/i )
				).toBeInTheDocument();
			} );
			// Untouched: the granular toggle still reflects the old value.
			fireEvent.click( screen.getByRole( 'tab', { name: /Scripts/i } ) );
			expect(
				screen.getByLabelText( /Minify JavaScript/i )
			).not.toBeChecked();
			errorSpy.mockRestore();
		} );

		it( 'revert restores the snapshot and re-syncs the form', async () => {
			apiCall.mockImplementation( ( endpoint ) => {
				if ( 'restore_settings' === endpoint ) {
					return Promise.resolve( {
						success: true,
						message: 'Settings restored successfully.',
						data: {
							file_optimisation: {
								minifyJS: false,
								delayJS: false,
							},
						},
					} );
				}
				return Promise.resolve( { success: true, data: {} } );
			} );
			render(
				<FileOptimization
					options={ { minifyJS: true } }
					serverRules={ {} }
				/>
			);

			const revertButton = screen.getByRole( 'button', {
				name: /Revert to Previous/i,
			} );
			expect( revertButton ).not.toBeDisabled();

			await act( async () => {
				fireEvent.click( revertButton );
			} );

			await waitFor( () => {
				expect( apiCall ).toHaveBeenCalledWith(
					'restore_settings',
					expect.anything()
				);
			} );
			await waitFor( () => {
				expect(
					screen.getByText( /Settings restored/i )
				).toBeInTheDocument();
			} );
		} );

		it( 'aggressive preset shows the explicit warning banner', async () => {
			apiCall.mockImplementation( () =>
				Promise.resolve( { success: true, data: {} } )
			);
			render(
				<FileOptimization
					options={ {
						delayJS: true,
						delayJSBuilderPreset: true,
						delayJSCommercePreset: true,
						delayJSInteractionPreset: true,
					} }
					serverRules={ {} }
				/>
			);

			expect(
				screen.queryByText( /Aggressive mode is on/i )
			).not.toBeInTheDocument();

			await act( async () => {
				fireEvent.click(
					screen.getByRole( 'button', {
						name: /Enable Aggressive Mode/i,
					} )
				);
			} );

			await waitFor( () => {
				expect(
					screen.getByText( /Aggressive mode is on/i )
				).toBeInTheDocument();
			} );
		} );
	} );
} );
