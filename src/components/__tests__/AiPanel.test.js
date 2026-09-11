import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import AiPanel from '../AiPanel';

// Mock the API request
jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
} ) );

import { apiCall } from '../../lib/apiRequest';

describe( 'AiPanel Component', () => {
	beforeEach( () => {
		global.wppoSettings = {
			settings: {
				ai_adaptive: {
					enabled: false,
					use_wp_ai_client: false,
				},
			},
		};
		jest.clearAllMocks();
		apiCall.mockImplementation( async ( endpoint ) => {
			if ( endpoint === 'ai_model' ) {
				return { success: true, data: null };
			}
			if ( endpoint === 'ai_suggestions' ) {
				return { success: true, data: { suggestions: [] } };
			}
			return { success: true, data: {} };
		} );
	} );

	it( 'renders both toggles', () => {
		render( <AiPanel /> );
		expect(
			screen.getByLabelText( /Enable AI Adaptive/i )
		).toBeInTheDocument();
		expect(
			screen.getByLabelText( /Use WordPress AI client/i )
		).toBeInTheDocument();
	} );

	it( 'saves enabled + use_wp_ai_client payload', async () => {
		render( <AiPanel /> );

		fireEvent.click( screen.getByLabelText( /Enable AI Adaptive/i ) );
		fireEvent.click( screen.getByLabelText( /Use WordPress AI client/i ) );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Save AI Settings/i } )
		);

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith(
				'update_settings',
				expect.objectContaining( {
					tab: 'ai_adaptive',
					settings: {
						enabled: true,
						use_wp_ai_client: true,
						dismissed_suggestions: [],
					},
				} )
			);
		} );
	} );

	it( 'dismisses a suggestion and persists the metric', async () => {
		apiCall.mockImplementation( async ( endpoint ) => {
			if ( endpoint === 'ai_model' ) {
				return { success: true, data: null };
			}
			if ( endpoint === 'ai_suggestions' ) {
				return {
					success: true,
					data: {
						suggestions: [
							{
								metric: 'ai_delay_js',
								value: 'moderate · mobile · single · INP p75 0.3s',
								unit: 'string',
								status: 'needs_improvement',
								description: 'AI: Delay JavaScript suggestion',
								fix_action: 'open_file_optimization_tab',
								ai_payload: {
									tab: 'file_optimisation',
									settings: { delayJS: true },
								},
							},
						],
					},
				};
			}
			return { success: true, data: {} };
		} );
		render( <AiPanel /> );

		const dismissButton = await screen.findByRole( 'button', {
			name: /Dismiss: AI: Delay JavaScript suggestion/i,
		} );
		fireEvent.click( dismissButton );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith(
				'update_settings',
				expect.objectContaining( {
					tab: 'ai_adaptive',
					settings: expect.objectContaining( {
						dismissed_suggestions: [ 'ai_delay_js' ],
					} ),
				} )
			);
		} );
	} );
} );
