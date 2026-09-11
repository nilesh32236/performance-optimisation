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

import AutoloadedOptions, { isValidOptionName } from '../AutoloadedOptions';
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
			'GET',
			expect.any( AbortSignal )
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
		// The audit list renders independently of the notice banner, so the
		// empty state stays visible alongside the failure notice.
		expect(
			screen.getByText( 'No autoloaded options found.' )
		).toBeInTheDocument();

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
		expect( apiCall ).toHaveBeenCalledWith(
			'autoload_remediate',
			{
				mode: 'dry_run',
			},
			'POST',
			expect.any( AbortSignal )
		);
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
		expect( apiCall ).toHaveBeenCalledWith(
			'autoload_remediate',
			{
				mode: 'apply',
			},
			'POST',
			expect.any( AbortSignal )
		);

		await waitFor( () =>
			expect(
				screen.getByRole( 'button', {
					name: 'Revert autoload for big_plugin_blob',
				} )
			).toBeInTheDocument()
		);

		fireEvent.click(
			screen.getByRole( 'button', {
				name: 'Revert autoload for big_plugin_blob',
			} )
		);

		await waitFor( () =>
			expect(
				screen.getByText( 'Reverted autoload for big_plugin_blob.' )
			).toBeInTheDocument()
		);
		expect( apiCall ).toHaveBeenCalledWith(
			'autoload_remediate',
			{
				mode: 'revert',
				option: 'big_plugin_blob',
			},
			'POST',
			expect.any( AbortSignal )
		);
	} );

	it( 'shows a failure notice when the dry run request fails', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				options: [ { option_name: 'big_option', size: 5000 } ],
			},
		} );
		apiCall.mockRejectedValueOnce( new Error( 'boom' ) );
		const errorSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );

		render( <AutoloadedOptions /> );

		await waitFor( () =>
			expect( screen.getByText( 'big_option' ) ).toBeInTheDocument()
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check savings' } )
		);

		await waitFor( () =>
			expect(
				screen.getByText( 'Failed to build the remediation report.' )
			).toBeInTheDocument()
		);
		// The audit list stays visible alongside the notice.
		expect( screen.getByText( 'big_option' ) ).toBeInTheDocument();

		errorSpy.mockRestore();
	} );

	it( 'shows a failure notice when the apply request fails', async () => {
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
		apiCall.mockRejectedValueOnce( new Error( 'boom' ) );
		const errorSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );

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
				screen.getByText( 'Failed to apply remediation.' )
			).toBeInTheDocument()
		);

		errorSpy.mockRestore();
	} );

	it( 'hides Apply fix when the dry-run report is empty', async () => {
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
				total_autoload_bytes: 100,
				count: 0,
				bytes_saved: 0,
				options: [],
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
				screen.getByText( /Dry run: 0 options would save/ )
			).toBeInTheDocument()
		);
		expect(
			screen.queryByRole( 'button', { name: 'Apply fix' } )
		).not.toBeInTheDocument();
	} );

	it( 'keeps the dry-run report intact after apply and formats MB savings', async () => {
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
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { options: [ { option_name: 'big_option', size: 5000 } ] },
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
		// Dry-run report is preserved (not overwritten by the apply payload).
		expect(
			screen.getByText( /Dry run: 1 options would save/ )
		).toBeInTheDocument();
		// Apply payload renders in its own applied-summary branch with MB units.
		expect(
			screen.getByText( /Applied fix: 1 options now save 1\.0 MB/ )
		).toBeInTheDocument();
		// Audit list stays visible alongside the success notice.
		expect( screen.getByText( 'big_option' ) ).toBeInTheDocument();
	} );

	it( 'validates option names before they reach the remediate endpoint', () => {
		expect( isValidOptionName( 'big_plugin_blob' ) ).toBe( true );
		expect( isValidOptionName( 'my.option-name_1' ) ).toBe( true );
		expect( isValidOptionName( '' ) ).toBe( false );
		expect( isValidOptionName( null ) ).toBe( false );
		expect( isValidOptionName( 123 ) ).toBe( false );
		expect( isValidOptionName( 'a'.repeat( 192 ) ) ).toBe( false );
		expect( isValidOptionName( 'a'.repeat( 191 ) ) ).toBe( true );
		expect( isValidOptionName( '../../etc/passwd' ) ).toBe( false );
		expect( isValidOptionName( 'evil; DROP TABLE x' ) ).toBe( false );
		expect( isValidOptionName( 'key with spaces' ) ).toBe( false );
	} );

	it( 'does not call the remediate endpoint when reverting an invalid option name', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				options: [ { option_name: 'big_option', size: 5000 } ],
			},
		} );
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				count: 1,
				bytes_saved: 100,
				options: [],
				remediated: { 'evil; DROP TABLE x': 'yes' },
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
				screen.getByRole( 'button', {
					name: 'Revert autoload for evil; DROP TABLE x',
				} )
			).toBeInTheDocument()
		);

		const callsBefore = apiCall.mock.calls.length;

		fireEvent.click(
			screen.getByRole( 'button', {
				name: 'Revert autoload for evil; DROP TABLE x',
			} )
		);

		await waitFor( () =>
			expect(
				screen.getByText( 'Failed to revert option.' )
			).toBeInTheDocument()
		);
		expect( apiCall.mock.calls.length ).toBe( callsBefore );
		expect( apiCall ).not.toHaveBeenCalledWith(
			'autoload_remediate',
			expect.objectContaining( { mode: 'revert' } )
		);
	} );
} );
