/**
 * The remedy line must actually reach the router.
 *
 * An independent review reverted the single `onNavigate={ onNavigate }` in
 * `Overview.js` and **all 1,319 tests stayed green**. In production the button
 * would still render and the `typeof onNavigate === 'function'` guard would
 * swallow the call — a silent dead control, the exact symptom this PR had
 * already shipped once when the call passed `{ area, view }` instead of a bare
 * view id.
 *
 * Every other test here renders `SiteStatusCard` directly, so nothing exercised
 * the prop **through** the component that supplies it. This one does.
 *
 * @package
 */

// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

const mockApiCall = jest.fn();
const mockFetchWebVitalsTrends = jest.fn();
const mockFetchSystemInfo = jest.fn();

// `Overview` imports three things from this module, and a mock that supplies
// only one makes the component throw on the first render - which is a test
// about the mock, not about the navigation.
jest.mock( '../../../lib/apiRequest', () => ( {
	apiCall: ( ...args ) => mockApiCall( ...args ),
	fetchWebVitalsTrends: ( ...args ) => mockFetchWebVitalsTrends( ...args ),
	fetchSystemInfo: ( ...args ) => mockFetchSystemInfo( ...args ),
} ) );

import Overview from '../Overview';

const VITALS_TRENDS = {
	home_desktop: [
		{ lcp: 345.5, cls: 0.0018, tbt: 120 },
		{ lcp: 361, cls: 0.002, tbt: 130 },
	],
};

describe( 'Overview passes navigation to the site status card', () => {
	beforeEach( () => {
		mockApiCall.mockReset();
		mockFetchWebVitalsTrends.mockReset();
		mockFetchSystemInfo.mockReset();

		// Both helpers gate on `response.success === true` and read
		// `response.data` — the raw payload is treated as a failure, and with it
		// the whole card renders "This information could not be loaded."
		mockFetchWebVitalsTrends.mockResolvedValue( {
			success: true,
			data: { trends: VITALS_TRENDS },
		} );
		// The store has no `inp`, exactly as the live one does, so the INP row
		// is unknown and the remedy line renders.
		mockFetchSystemInfo.mockResolvedValue( {
			success: true,
			data: { wordpress: '7.1.2', php: '8.3' },
		} );
		mockApiCall.mockImplementation( ( action ) => {
			if ( action === 'object_cache' ) {
				return Promise.resolve( {
					success: true,
					data: { connected: true },
				} );
			}
			if ( action === 'recent_activities' ) {
				return Promise.resolve( { activities: [] } );
			}
			return Promise.resolve( {} );
		} );
	} );

	it( 'reaches the router with a bare view id', async () => {
		const onNavigate = jest.fn();
		render( <Overview onNavigate={ onNavigate } activities={ [] } /> );

		const button = await screen.findByRole( 'button', {
			name: /Real-user Web Vitals/i,
		} );
		fireEvent.click( button );

		// A **bare id**, not `{ area, view }`: `resolveDestination` does
		// `String( target )`, so an object becomes `"[object Object]"` and the
		// click silently does nothing.
		await waitFor( () =>
			expect( onNavigate ).toHaveBeenCalledWith( 'dashboard' )
		);
	} );

	it( 'never sends an object, whatever the card is given', async () => {
		const onNavigate = jest.fn();
		render( <Overview onNavigate={ onNavigate } activities={ [] } /> );
		const button = await screen.findByRole( 'button', {
			name: /Real-user Web Vitals/i,
		} );
		fireEvent.click( button );
		await waitFor( () => expect( onNavigate ).toHaveBeenCalled() );
		for ( const call of onNavigate.mock.calls ) {
			expect( typeof call[ 0 ] ).toBe( 'string' );
		}
	} );

	it( 'does not crash with no navigator at all', async () => {
		render( <Overview activities={ [] } /> );
		const button = await screen.findByRole( 'button', {
			name: /Real-user Web Vitals/i,
		} );
		expect( () => fireEvent.click( button ) ).not.toThrow();
	} );
} );
