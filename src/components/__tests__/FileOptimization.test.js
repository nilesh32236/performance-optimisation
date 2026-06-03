// eslint-disable-next-line import/no-extraneous-dependencies, import/no-unresolved -- React is required for JSX rendering in tests
import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import FileOptimization from '../FileOptimization';
import { apiCall } from '../../lib/apiRequest';

jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
} ) );

describe( 'FileOptimization', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		global.wppoSettings = { translations: {} };
	} );

	it( 'renders without crashing', () => {
		render( <FileOptimization /> );
		expect(
			screen.getByRole( 'tab', { name: /Assets/i } )
		).toBeInTheDocument();
	} );

	it( 'submits settings correctly', async () => {
		apiCall.mockResolvedValueOnce( { success: true, message: 'Saved' } );

		render( <FileOptimization options={ { minifyJS: false } } /> );

		const scriptsTab = screen.getByRole( 'tab', { name: /Scripts/i } );
		fireEvent.click( scriptsTab );

		const toggle = screen.getByRole( 'checkbox', {
			name: /Minify JavaScript/i,
		} );
		fireEvent.click( toggle );

		const saveButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		fireEvent.click( saveButton );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith(
				'update_settings',
				expect.objectContaining( {
					tab: 'file_optimisation',
					settings: expect.objectContaining( {
						minifyJS: true,
					} ),
				} )
			);
		} );
	} );

	it( 'handles server rules logic for apache vs nginx', () => {
		// Nginx
		const { rerender } = render(
			<FileOptimization
				serverRules={ { server_type: 'nginx', nginx: 'some rules' } }
			/>
		);

		const networkTab = screen.getByRole( 'tab', { name: /Network/i } );
		fireEvent.click( networkTab );

		expect( screen.getByText( /Nginx Detected/i ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'checkbox', { name: /Enable Server Rules/i } )
		).toBeDisabled();

		// Apache
		rerender(
			<FileOptimization serverRules={ { server_type: 'apache' } } />
		);
		expect(
			screen.getByRole( 'checkbox', { name: /Enable Server Rules/i } )
		).not.toBeDisabled();
	} );

	it( 'disables the switch when server is not apache', () => {
		render(
			<FileOptimization
				serverRules={ { server_type: 'nginx', nginx: 'some rules' } }
			/>
		);

		const networkTab = screen.getByRole( 'tab', { name: /Network/i } );
		fireEvent.click( networkTab );

		const switchField = screen.getByRole( 'checkbox', {
			name: /Enable Server Rules/i,
		} );
		expect( switchField ).toBeDisabled();
	} );
} );
