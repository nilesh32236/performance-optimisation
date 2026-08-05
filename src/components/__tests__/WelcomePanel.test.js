import { render, screen, act, fireEvent } from '@testing-library/react';
import WelcomePanel from '../WelcomePanel';
import { apiCall } from '../../lib/apiRequest';

jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
} ) );

describe( 'WelcomePanel', () => {
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
} );
