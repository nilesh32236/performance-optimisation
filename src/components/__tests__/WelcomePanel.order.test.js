import {
	render,
	screen,
	act,
	fireEvent,
	waitFor,
} from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import WelcomePanel from '../WelcomePanel';
import { apiCall } from '../../lib/apiRequest';

jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
	fetchWooCacheSelfTest: jest.fn(),
	getErrorLogMessage: ( error ) =>
		error instanceof Error ? error.message : String( error ),
	getWppoSettings: () => global.wppoSettings ?? {},
} ) );

/**
 * Phase C UX regressions: step 1 stays always visible with a Safe-preset
 * shortcut, advanced steps collapse into one native disclosure, and the
 * update_settings payloads stay byte-identical. Copy/hierarchy only.
 */
describe( 'WelcomePanel order (Phase C)', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		global.wppoSettings = {
			show_welcome: true,
			settings: {
				cache_settings: { enableCache: false },
				file_optimisation: { minifyJS: false, minifyCSS: false },
				image_optimisation: { lazyLoadImages: false },
			},
		};
	} );

	afterEach( () => {
		jest.restoreAllMocks();
	} );

	it( 'links the beginner path to the Safe preset anchor', () => {
		render( <WelcomePanel /> );
		const link = screen.getByRole( 'link', {
			name: 'Go to the Safe preset',
		} );
		expect( link ).toHaveAttribute( 'href', '#wppoSafeStart' );
	} );

	it( 'keeps step 1 visible and collapses advanced steps in one disclosure', () => {
		const { container } = render( <WelcomePanel /> );
		const disclosure = container.querySelector(
			'details.wppo-welcome-advanced'
		);
		expect( disclosure ).toBeInTheDocument();
		// Native disclosure needs a summary for keyboard/AT users.
		expect( disclosure.querySelector( 'summary' ) ).toHaveTextContent(
			/Advanced steps/
		);
		// Advanced steps live inside the disclosure…
		expect( disclosure ).toHaveTextContent(
			'Enable JS / CSS Minification'
		);
		expect( disclosure ).toHaveTextContent( 'Enable Lazy Loading' );
		// …while step 1 stays outside it.
		expect( disclosure ).not.toHaveTextContent( 'Enable Page Caching' );
		expect( screen.getByText( 'Enable Page Caching' ) ).toBeInTheDocument();
	} );

	it( 'sends the unchanged minify payload from inside the disclosure', async () => {
		apiCall.mockImplementation( ( endpoint ) => {
			if ( 'update_settings' === endpoint ) {
				return Promise.resolve( { success: true } );
			}
			return Promise.resolve( { success: true } );
		} );

		render( <WelcomePanel /> );
		const minifyButton = screen.getByRole( 'button', {
			name: 'Enable – Enable JS / CSS Minification',
		} );

		await act( async () => {
			fireEvent.click( minifyButton );
		} );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith( 'update_settings', {
				tab: 'file_optimisation',
				settings: { minifyJS: true, minifyCSS: true },
			} );
		} );
		expect( apiCall ).toHaveBeenCalledWith( 'dismiss_welcome' );
	} );
} );
