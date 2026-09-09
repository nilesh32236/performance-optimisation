import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';

jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
} ) );

jest.mock( '../common/FeatureCard', () => ( { children, title } ) => (
	<div data-testid="feature-card">
		<h3>{ title }</h3>
		{ children }
	</div>
) );

import AutoloadedOptions from '../AutoloadedOptions';
import { apiCall } from '../../lib/apiRequest';

describe( 'AutoloadedOptions', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'renders the largest autoloaded options', async () => {
		apiCall.mockResolvedValue( {
			success: true,
			data: {
				options: [
					{ option_name: 'big_option', size: 5000 },
					{ option_name: 'small_option', size: 10 },
				],
			},
		} );

		render( <AutoloadedOptions /> );

		await waitFor( () =>
			expect( screen.getByText( 'big_option' ) ).toBeInTheDocument()
		);
		expect( screen.getByText( '4.9 KB' ) ).toBeInTheDocument();
		expect( screen.getByText( '10 B' ) ).toBeInTheDocument();
		expect( apiCall ).toHaveBeenCalledWith(
			'autoloaded_options?limit=20',
			{},
			'GET'
		);
	} );

	it( 'renders an empty state when no options exist', async () => {
		apiCall.mockResolvedValue( { success: true, data: { options: [] } } );

		render( <AutoloadedOptions /> );

		await waitFor( () =>
			expect(
				screen.getByText( 'No autoloaded options found.' )
			).toBeInTheDocument()
		);
	} );

	it( 'renders a distinct failure message when the request fails', async () => {
		apiCall.mockRejectedValue( new Error( 'boom' ) );
		const errorSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );

		render( <AutoloadedOptions /> );

		await waitFor( () =>
			expect(
				screen.getByText( 'Failed to load autoloaded options.' )
			).toBeInTheDocument()
		);
		expect(
			screen.queryByText( 'No autoloaded options found.' )
		).not.toBeInTheDocument();

		errorSpy.mockRestore();
	} );

	it( 'renders the dry-run report with savings before touching anything', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				options: [ { option_name: 'big_option', size: 5000 } ],
			},
		} );
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				threshold: 1024,
				supported: true,
				total_autoload_bytes: 2097152,
				count: 1,
				bytes_saved: 1048576,
				options: [ { option_name: 'big_plugin_blob', size: 1048576 } ],
				remediated: {},
			},
		} );

		render( <AutoloadedOptions /> );

		await waitFor( () =>
			expect( screen.getByText( 'big_option' ) ).toBeInTheDocument()
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check savings' } )
		);

		await waitFor( () =>
			expect(
				screen.getByText( /Dry run: 1 options would save/ )
			).toBeInTheDocument()
		);
		expect( screen.getByText( 'big_plugin_blob' ) ).toBeInTheDocument();
		expect( apiCall ).toHaveBeenCalledWith( 'autoload_remediate', {
			mode: 'dry_run',
		} );
	} );

	it( 'applies the fix and offers per-option revert', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				options: [ { option_name: 'big_option', size: 5000 } ],
			},
		} );
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				threshold: 1024,
				supported: true,
				total_autoload_bytes: 2097152,
				count: 1,
				bytes_saved: 1048576,
				options: [ { option_name: 'big_plugin_blob', size: 1048576 } ],
				remediated: {},
			},
		} );
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				threshold: 1024,
				supported: true,
				applied: [
					{
						option_name: 'big_plugin_blob',
						size: 1048576,
						prior: 'yes',
					},
				],
				failed: [],
				bytes_saved: 1048576,
				total_autoload_bytes: 1048576,
				remediated: { big_plugin_blob: 'yes' },
			},
		} );
		// applyFix() reloads the option list afterwards.
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { options: [] },
		} );
		// revertOption() call + the option-list reload afterwards.
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { reverted: 'big_plugin_blob' },
		} );
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { options: [] },
		} );

		render( <AutoloadedOptions /> );

		await waitFor( () =>
			expect( screen.getByText( 'big_option' ) ).toBeInTheDocument()
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check savings' } )
		);

		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: 'Apply fix' } )
			).toBeInTheDocument()
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Apply fix' } ) );

		await waitFor( () =>
			expect(
				screen.getByText( 'Remediation applied to 1 options.' )
			).toBeInTheDocument()
		);
		expect( apiCall ).toHaveBeenCalledWith( 'autoload_remediate', {
			mode: 'apply',
		} );

		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: 'Revert' } )
			).toBeInTheDocument()
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Revert' } ) );

		await waitFor( () =>
			expect(
				screen.getByText( 'Reverted autoload for big_plugin_blob.' )
			).toBeInTheDocument()
		);
		expect( apiCall ).toHaveBeenCalledWith( 'autoload_remediate', {
			mode: 'revert',
			option: 'big_plugin_blob',
		} );
	} );
} );
