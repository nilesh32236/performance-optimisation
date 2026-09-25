import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';

jest.mock( '../../lib/apiRequest', () => {
	const actual = jest.requireActual( '../../lib/apiRequest' );
	return {
		...actual,
		apiCall: jest.fn(),
	};
} );

import GuidedNextStep, { serverTypeNote } from '../GuidedNextStep';
import { apiCall } from '../../lib/apiRequest';

describe( 'GuidedNextStep', () => {
	beforeEach( () => {
		global.wppoSettings = {};
		jest.clearAllMocks();
	} );

	it( 'renders a single RUM-driven next action with a fix button', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				next_action: {
					metric: 'rum_lcp',
					value: 4500,
					unit: 'ms',
					status: 'poor',
					description: 'Real-user Largest Contentful Paint',
					fix_action: 'open_image_optimization_tab',
				},
				server_type: 'nginx',
			},
		} );

		const onNavigate = jest.fn();
		render( <GuidedNextStep onNavigate={ onNavigate } /> );

		await waitFor( () =>
			expect(
				screen.getByText( 'Real-user Largest Contentful Paint' )
			).toBeInTheDocument()
		);
		// Server-type note is always displayed.
		expect( screen.getByText( /Server: Nginx/ ) ).toBeInTheDocument();

		fireEvent.click(
			screen.getByRole( 'button', { name: /Review Image Optimization/i } )
		);
		expect( onNavigate ).toHaveBeenCalledWith( 'imageOptimization' );
	} );

	it( 'renders the all-clear copy when there is no next action', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { next_action: null, server_type: 'apache' },
		} );

		render( <GuidedNextStep /> );

		await waitFor( () =>
			expect(
				screen.getByText( /No urgent next step/ )
			).toBeInTheDocument()
		);
		expect( screen.getByText( /Server: Apache/ ) ).toBeInTheDocument();
	} );

	it( 'announces failure when the suggestions fetch fails', async () => {
		const consoleSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );
		try {
			apiCall.mockRejectedValueOnce( new Error( 'boom' ) );

			render( <GuidedNextStep /> );

			await waitFor( () =>
				expect(
					screen.getByText( 'Failed to load the guided next step.' )
				).toBeInTheDocument()
			);
		} finally {
			consoleSpy.mockRestore();
		}
	} );

	it( 'serverTypeNote covers every server type', () => {
		expect( serverTypeNote( 'apache' ) ).toMatch( /Apache/ );
		expect( serverTypeNote( 'nginx' ) ).toMatch( /Nginx/ );
		expect( serverTypeNote( 'litespeed' ) ).toMatch( /LiteSpeed/ );
		expect( serverTypeNote( 'other' ) ).toMatch( /unknown/ );
		expect( serverTypeNote( undefined ) ).toMatch( /unknown/ );
	} );
} );
