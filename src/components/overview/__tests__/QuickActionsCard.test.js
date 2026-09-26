/**
 * Tests for the Overview quick actions.
 *
 * The rule under test is the one the campaign is built on: the control the
 * user activated shows its own busy state, and nothing else spins.
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import QuickActionsCard from '../QuickActionsCard';

jest.mock( '../../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
	getErrorLogMessage: ( error ) => error?.message ?? 'Something went wrong.',
} ) );

jest.mock( '../../../lib/useNotice', () => ( {
	__esModule: true,
	default: () => ( { notify: mockNotify } ),
} ) );

const mockNotify = jest.fn();
const { apiCall } = require( '../../../lib/apiRequest' );

const LABELS = {
	clear: 'Clear the page cache',
	images: 'Review images',
	database: 'Review the database',
	speed: 'Tune speed settings',
};

describe( 'QuickActionsCard', () => {
	beforeEach( () => {
		mockNotify.mockClear();
		apiCall.mockReset();
	} );

	it( 'renders every action with an understandable label', () => {
		render( <QuickActionsCard onNavigate={ jest.fn() } /> );
		Object.values( LABELS ).forEach( ( label ) => {
			expect( screen.getByText( label ) ).toBeInTheDocument();
		} );
	} );

	it( 'navigates when a link action is used', () => {
		const onNavigate = jest.fn();
		render( <QuickActionsCard onNavigate={ onNavigate } /> );
		fireEvent.click( screen.getByText( LABELS.images ) );
		expect( onNavigate ).toHaveBeenCalledWith( 'media' );
	} );

	it( 'shows the busy state on the button that was clicked, and only there', async () => {
		// The behaviour #849 was filed for, and it is easy to reintroduce with
		// a single shared `isLoading` flag.
		let resolveCall;
		apiCall.mockImplementation(
			() =>
				new Promise( ( resolve ) => {
					resolveCall = resolve;
				} )
		);
		render( <QuickActionsCard onNavigate={ jest.fn() } /> );

		fireEvent.click( screen.getByText( LABELS.clear ) );

		// LoadingSubmitButton renders the loading text twice on purpose: once
		// visibly, once in a live region for screen readers. So assert on
		// presence, not on a single match.
		await waitFor( () => {
			expect( screen.getAllByText( 'Clearing…' ).length ).toBeGreaterThan(
				0
			);
		} );
		// The navigation links must not have entered a busy state. Checking only
		// that their text is still present is not enough: a shared loading flag
		// can also disable them, which leaves the text visible and the controls
		// dead. That is exactly the #849 bug, so both properties are asserted.
		[ LABELS.images, LABELS.database, LABELS.speed ].forEach( ( l ) => {
			const control = screen.getByText( l ).closest( 'button' );
			expect( control ).toBeTruthy();
			expect( control ).not.toBeDisabled();
			// Absent or "false" are both fine; "true" is the bug. Asserting
			// aria-busy === 'false' would fail on correct code that simply never
			// sets the attribute at all.
			expect( control.getAttribute( 'aria-busy' ) ).not.toBe( 'true' );
		} );
		expect( screen.queryByText( LABELS.clear ) ).not.toBeInTheDocument();

		resolveCall( { success: true } );
		await waitFor( () => {
			expect( screen.getByText( LABELS.clear ) ).toBeInTheDocument();
		} );
	} );

	it( 'returns the button to normal after a failure', async () => {
		apiCall.mockRejectedValue( new Error( 'boom' ) );
		render( <QuickActionsCard onNavigate={ jest.fn() } /> );

		fireEvent.click( screen.getByText( LABELS.clear ) );

		await waitFor( () => {
			expect( mockNotify ).toHaveBeenCalledWith(
				expect.objectContaining( { type: 'error' } )
			);
		} );
		// Not left stuck spinning.
		expect( screen.getByText( LABELS.clear ) ).toBeInTheDocument();
	} );

	it( 'treats a success:false response as a failure', async () => {
		apiCall.mockResolvedValue( { success: false, message: 'Nope.' } );
		render( <QuickActionsCard onNavigate={ jest.fn } /> );
		fireEvent.click( screen.getByText( LABELS.clear ) );
		await waitFor( () => {
			expect( mockNotify ).toHaveBeenCalledWith(
				expect.objectContaining( { type: 'error' } )
			);
		} );
	} );

	it( 'reports success with a message that says what happened', async () => {
		apiCall.mockResolvedValue( { success: true } );
		render( <QuickActionsCard onNavigate={ jest.fn() } /> );
		fireEvent.click( screen.getByText( LABELS.clear ) );
		await waitFor( () => {
			expect( mockNotify ).toHaveBeenCalledWith(
				expect.objectContaining( { type: 'success' } )
			);
		} );
	} );

	it( 'still renders and stays usable with no navigation callback', () => {
		// A missing optional prop must not take the card down, and the control
		// must remain present and enabled rather than vanishing.
		render( <QuickActionsCard /> );
		const control = screen.getByText( LABELS.speed ).closest( 'button' );
		expect( control ).toBeTruthy();
		expect( control ).not.toBeDisabled();
		expect( () => fireEvent.click( control ) ).not.toThrow();
		expect( screen.getByText( LABELS.speed ) ).toBeInTheDocument();
	} );
} );
