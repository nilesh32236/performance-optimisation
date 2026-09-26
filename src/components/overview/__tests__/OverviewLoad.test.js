/**
 * Tests for the Overview's loading and failure behaviour.
 *
 * The defect these pin: `object_cache` is throttled to five calls a minute and
 * answers 429 with a *resolved* response. Treating "resolved" as success left
 * the row reading "Unavailable" with no way to retry, for the rest of the
 * throttle window — reproduced live.
 */

import { act, render, screen, waitFor } from '@testing-library/react';

import Overview from '../Overview';

jest.mock( '../../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
	fetchSystemInfo: jest.fn(),
	fetchWebVitalsTrends: jest.fn(),
} ) );

const {
	apiCall,
	fetchSystemInfo,
	fetchWebVitalsTrends,
} = require( '../../../lib/apiRequest' );

describe( 'Overview loading and failure behaviour', () => {
	beforeEach( () => {
		apiCall.mockReset();
		fetchSystemInfo.mockReset();
		fetchWebVitalsTrends.mockReset();
		fetchSystemInfo.mockResolvedValue( {
			success: true,
			data: { php: { version: '8.3' }, wordpress: { version: '7.1.2' } },
		} );
		apiCall.mockResolvedValue( {
			success: true,
			data: { enabled: true, redis_reachable: true },
		} );
		fetchWebVitalsTrends.mockResolvedValue( {
			success: true,
			data: { trends: {} },
		} );
	} );

	it( 'issues exactly one request per source, never two', async () => {
		// The rewrite initially fetched every source twice to derive both the
		// page state and the per-source state. Against a 5-per-minute endpoint
		// that is self-inflicted throttling.
		render( <Overview onNavigate={ jest.fn() } /> );
		await screen.findByRole( 'heading', { name: /Site status/i } );
		await waitFor( () =>
			expect( fetchSystemInfo ).toHaveBeenCalledTimes( 1 )
		);
		expect( apiCall ).toHaveBeenCalledTimes( 1 );
		expect( fetchWebVitalsTrends ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'shows the page while loading and never a verdict before it settles', async () => {
		let release;
		fetchSystemInfo.mockReturnValue(
			new Promise( ( resolve ) => {
				release = resolve;
			} )
		);
		render( <Overview onNavigate={ jest.fn() } /> );
		// While a source is in flight the card must not be claiming "Working".
		expect( screen.getByText( 'Checking your site…' ) ).toBeInTheDocument();
		act( () =>
			release( {
				success: true,
				data: {
					php: { version: '8.3' },
					wordpress: { version: '7.1.2' },
				},
			} )
		);
		await waitFor( () =>
			expect( screen.queryByText( 'Checking your site…' ) ).toBeNull()
		);
	} );

	it( 'offers a retry when one source produced nothing', async () => {
		// The throttle case: 429 arrives resolved, with success:false.
		apiCall.mockResolvedValue( {
			success: false,
			message: 'Too many requests.',
		} );
		render( <Overview onNavigate={ jest.fn() } /> );
		await waitFor( () =>
			expect(
				screen.getByText( /Some information could not be loaded/ )
			).toBeInTheDocument()
		);
		expect(
			screen.getByRole( 'button', { name: 'Try again' } )
		).toBeInTheDocument();
	} );

	it( 'does not offer a retry when everything loaded', async () => {
		render( <Overview onNavigate={ jest.fn() } /> );
		await screen.findByRole( 'heading', { name: /Site status/i } );
		await new Promise( ( r ) => setTimeout( r, 300 ) );
		expect(
			screen.queryByText( /Some information could not be loaded/ )
		).toBeNull();
	} );

	it( 'treats absent vitals as normal, not as a failure', async () => {
		// A site with no stored measurements is a legitimate state and must not
		// trigger a retry prompt or a failure state.
		fetchWebVitalsTrends.mockResolvedValue( {
			success: true,
			data: { trends: {} },
		} );
		render( <Overview onNavigate={ jest.fn() } /> );
		await screen.findByRole( 'heading', { name: /Site status/i } );
		await new Promise( ( r ) => setTimeout( r, 300 ) );
		expect(
			screen.queryByText( /Some information could not be loaded/ )
		).toBeNull();
		expect(
			screen.queryByText( /This information could not be loaded/ )
		).toBeNull();
	} );

	it( 're-requests when the retry control is used', async () => {
		apiCall.mockResolvedValue( {
			success: false,
			message: 'Too many requests.',
		} );
		render( <Overview onNavigate={ jest.fn() } /> );
		// Wait for the affordance itself, not just the request: clicking before
		// the banner exists would pass for the wrong reason.
		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: 'Try again' } )
			).toBeInTheDocument()
		);
		expect( apiCall ).toHaveBeenCalledTimes( 1 );
		await act( async () => {
			screen.getByRole( 'button', { name: 'Try again' } ).click();
		} );
		await waitFor( () => expect( apiCall ).toHaveBeenCalledTimes( 2 ) );
	} );
} );

describe( 'fetchObjectCache envelope handling', () => {
	// A `WP_Error`-shaped body has a truthy `data`, which used to reach the model
	// and render "Object cache is off" — a false claim manufactured out of an
	// error. Only reachable by testing the function directly.
	beforeEach( () => {
		apiCall.mockReset();
	} );

	it( 'returns null for an error envelope, however it is shaped', async () => {
		const { fetchObjectCache } = require( '../Overview' );
		const envelopes = [
			{
				success: false,
				message: 'Too many requests.',
				data: { status: 429 },
			},
			// A `WP_Error` body has no `success` key at all, which is why the
			// check must be an allow-list. A negative check lets this through.
			{ code: 'too_many', message: 'x', data: { status: 500 } },
			{
				success: false,
				data: { enabled: false, redis_reachable: false },
			},
		];
		// Sequential, awaited. `forEach( async … )` does not await, so these
		// assertions used to escape the test and surface as an unhandled
		// rejection after it had already passed.
		for ( const envelope of envelopes ) {
			apiCall.mockResolvedValue( envelope );

			expect( await fetchObjectCache() ).toBeNull();
		}
	} );

	it( 'returns the payload on success', async () => {
		const { fetchObjectCache } = require( '../Overview' );
		apiCall.mockResolvedValue( {
			success: true,
			data: { enabled: true, redis_reachable: true },
		} );
		expect( await fetchObjectCache() ).toEqual( {
			enabled: true,
			redis_reachable: true,
		} );
	} );

	it( 'returns null rather than throwing when there is no response at all', async () => {
		const { fetchObjectCache } = require( '../Overview' );
		apiCall.mockResolvedValue( null );
		expect( await fetchObjectCache() ).toBeNull();
	} );

	it( 'asks for the status action, which the endpoint requires', async () => {
		const { fetchObjectCache } = require( '../Overview' );
		apiCall.mockResolvedValue( { success: true, data: {} } );
		await fetchObjectCache();
		// Without `action: 'status'` the endpoint answers 400.
		expect( apiCall ).toHaveBeenCalledWith(
			'object_cache',
			expect.objectContaining( { action: 'status' } ),
			'POST',
			undefined
		);
	} );
} );
