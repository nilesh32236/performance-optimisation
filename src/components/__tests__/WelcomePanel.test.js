import {
	render,
	screen,
	act,
	fireEvent,
	waitFor,
} from '@testing-library/react';
import WelcomePanel from '../WelcomePanel';
import { apiCall } from '../../lib/apiRequest';

jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
} ) );

describe( 'WelcomePanel', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	afterEach( () => {
		jest.restoreAllMocks();
	} );

	beforeEach( () => {
		global.wppoSettings = {
			show_welcome: true,
			settings: {
				cache_settings: { enableCache: false },
				file_optimisation: { minifyJS: false, minifyCSS: false },
				image_optimisation: { lazyLoadImages: false },
			},
		};
	} );

	it( 'does not render when show_welcome is false', () => {
		global.wppoSettings.show_welcome = false;
		const { container } = render( <WelcomePanel /> );
		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'renders the steps correctly when show_welcome is true', () => {
		render( <WelcomePanel /> );
		expect(
			screen.getByText( 'Welcome to Performance Optimisation' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Enable Enable Page Caching' } )
		).toBeInTheDocument();
	} );

	it( 'shows loading state and updates aria-label during API call', async () => {
		let resolveApiCall;
		apiCall.mockImplementation(
			() =>
				new Promise( ( resolve ) => {
					resolveApiCall = resolve;
				} )
		);

		render( <WelcomePanel /> );
		const cacheButton = screen.getByRole( 'button', {
			name: 'Enable Enable Page Caching',
		} );

		expect( cacheButton ).not.toHaveAttribute( 'aria-busy', 'true' );
		expect( cacheButton ).not.toBeDisabled();

		await act( async () => {
			fireEvent.click( cacheButton );
		} );

		// Button should now be busy and disabled
		expect( cacheButton ).toHaveAttribute( 'aria-busy', 'true' );
		expect( cacheButton ).toBeDisabled();
		// Screen reader label should update
		expect( cacheButton ).toHaveAttribute(
			'aria-label',
			'Enabling Enable Page Caching…'
		);
		// Visual text should update
		expect( cacheButton ).toHaveTextContent( 'Enabling…' );

		// Resolve the mock promise
		await act( async () => {
			resolveApiCall( { success: true } );
		} );
	} );

	it( 'handles API failure when attempting to enable a feature', async () => {
		apiCall.mockImplementation( ( endpoint ) => {
			if ( endpoint === 'update_settings' ) {
				return Promise.resolve( {
					success: false,
					message: 'Custom API error',
				} );
			}
			return Promise.resolve( { success: true } );
		} );

		render( <WelcomePanel /> );
		const cacheButton = screen.getByRole( 'button', {
			name: 'Enable Enable Page Caching',
		} );

		await act( async () => {
			fireEvent.click( cacheButton );
		} );
		await waitFor( () => {
			expect(
				screen.getByText( 'Custom API error' )
			).toBeInTheDocument();
		} );
		expect( cacheButton ).not.toHaveAttribute( 'aria-busy', 'true' );
	} );

	it( 'handles API exception when attempting to enable a feature', async () => {
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		apiCall.mockRejectedValue( new Error( 'Network Error' ) );

		render( <WelcomePanel /> );
		const cacheButton = screen.getByRole( 'button', {
			name: 'Enable Enable Page Caching',
		} );

		await act( async () => {
			fireEvent.click( cacheButton );
		} );
		await waitFor( () => {
			expect(
				screen.getByText( 'Failed to enable the feature.' )
			).toBeInTheDocument();
		} );
	} );

	it( 'dismisses the panel successfully when Got it button is clicked', async () => {
		apiCall.mockResolvedValue( { success: true } );

		render( <WelcomePanel /> );
		const dismissButton = screen.getByRole( 'button', { name: 'Got it' } );

		await act( async () => {
			fireEvent.click( dismissButton );
		} );
		await waitFor( () => {
			expect(
				screen.queryByText( 'Welcome to Performance Optimisation' )
			).not.toBeInTheDocument();
		} );
	} );

	it( 'handles API failure when attempting to dismiss the panel', async () => {
		apiCall.mockResolvedValue( {
			success: false,
			message: 'Dismiss failed',
		} );

		render( <WelcomePanel /> );
		const dismissButton = screen.getByRole( 'button', { name: 'Got it' } );

		await act( async () => {
			fireEvent.click( dismissButton );
		} );
		await waitFor( () => {
			expect( screen.getByText( 'Dismiss failed' ) ).toBeInTheDocument();
		} );
		expect(
			screen.getByText( 'Welcome to Performance Optimisation' )
		).toBeInTheDocument();
	} );

	it( 'handles API exception when attempting to dismiss the panel', async () => {
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		apiCall.mockRejectedValue( new Error( 'Network Error' ) );

		render( <WelcomePanel /> );
		const dismissButton = screen.getByRole( 'button', { name: 'Got it' } );

		await act( async () => {
			fireEvent.click( dismissButton );
		} );
		await waitFor( () => {
			expect(
				screen.getByText( 'Failed to dismiss the welcome panel.' )
			).toBeInTheDocument();
		} );
		expect(
			screen.getByText( 'Welcome to Performance Optimisation' )
		).toBeInTheDocument();
	} );
} );
