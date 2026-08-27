import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';

jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
} ) );

jest.mock( '../common/FeatureCard', () => ( { children } ) => (
	<div>{ children }</div>
) );
jest.mock( '../common/SwitchField', () => ( { label, checked, onChange } ) => (
	// eslint-disable-next-line jsx-a11y/label-has-associated-control -- test mock
	<label>
		<input type="checkbox" checked={ checked } onChange={ onChange } />
		{ label }
	</label>
) );
jest.mock( '../common/NoticeBanner', () => ( { message } ) => (
	<div>{ message }</div>
) );
jest.mock(
	'../common/LoadingSubmitButton',
	() =>
		( { onClick, label, isLoading } ) => (
			<button onClick={ onClick } disabled={ isLoading }>
				{ label }
			</button>
		)
);

import LlmsPanel from '../LlmsPanel';
import { apiCall } from '../../lib/apiRequest';

describe( 'LlmsPanel', () => {
	beforeEach( () => {
		global.wppoSettings = {
			homeUrl: 'http://example.com',
			settings: { llms_txt: { enabled: false, source: 'both' } },
		};
		jest.clearAllMocks();
		apiCall.mockResolvedValue( { success: true } );
	} );

	it( 'renders switch unchecked by default', () => {
		render( <LlmsPanel /> );
		expect( screen.getByText( /Enable LLMs.txt/i ) ).toBeInTheDocument();
		const checkbox = screen.getByRole( 'checkbox' );
		expect( checkbox.checked ).toBe( false );
	} );

	it( 'toggles and saves settings via apiCall', async () => {
		render( <LlmsPanel /> );
		const checkbox = screen.getByRole( 'checkbox' );
		fireEvent.click( checkbox );
		expect( checkbox.checked ).toBe( true );

		fireEvent.click( screen.getByRole( 'button', { name: /Save LLMs/i } ) );

		await waitFor( () =>
			expect( apiCall ).toHaveBeenCalledWith( 'update_settings', {
				tab: 'llms_txt',
				settings: { enabled: true, source: 'both' },
			} )
		);
		expect(
			screen.getByText( /LLMs.txt settings saved/i )
		).toBeInTheDocument();
	} );

	it( 'changes source and persists on save', async () => {
		render( <LlmsPanel /> );
		const select = screen.getByLabelText( /Source/i );
		fireEvent.change( select, { target: { value: 'sitemap' } } );

		fireEvent.click( screen.getByRole( 'button', { name: /Save LLMs/i } ) );

		await waitFor( () =>
			expect( apiCall ).toHaveBeenCalledWith( 'update_settings', {
				tab: 'llms_txt',
				settings: expect.objectContaining( { source: 'sitemap' } ),
			} )
		);
	} );

	it( 'shows error on failure', async () => {
		apiCall.mockRejectedValue( new Error( 'fail' ) );
		render( <LlmsPanel /> );
		fireEvent.click( screen.getByRole( 'button', { name: /Save LLMs/i } ) );
		await waitFor( () =>
			expect( screen.getByText( /Failed to save/i ) ).toBeInTheDocument()
		);
	} );
} );
