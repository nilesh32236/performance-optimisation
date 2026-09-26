/**
 * Tests for the Overview status card.
 *
 * The card's job is to show exactly what the status model decided — no
 * editorialising, and no inventing a score.
 */

import { render, screen } from '@testing-library/react';

import SiteStatusCard from '../SiteStatusCard';
import {
	ALL_STATUSES,
	buildStatusModel,
	STATUS,
} from '../../../lib/overviewStatus';

const model = ( payload ) => buildStatusModel( payload );

describe( 'SiteStatusCard', () => {
	it( 'shows the model rows verbatim, including their detail text', () => {
		const { rows, overall } = model( {
			cacheSettings: { enableCache: true, cache_enabled: true },
		} );
		render( <SiteStatusCard rows={ rows } overall={ overall } /> );
		expect( screen.getByText( rows[ 0 ].detail ) ).toBeInTheDocument();
	} );

	it( 'never renders a numeric score', () => {
		const { rows, overall } = model( {
			cacheSettings: { enableCache: true, cache_enabled: true },
		} );
		const { container } = render(
			<SiteStatusCard rows={ rows } overall={ overall } />
		);
		expect( container.textContent ).not.toMatch( /\d+\s*\/\s*100/ );
		expect( container.textContent ).not.toMatch( /score/i );
	} );

	it( 'labels an unavailable row as Unavailable, never as working', () => {
		const { rows, overall } = model( {
			cacheSettings: { enableCache: true, cache_enabled: true },
			objectCache: undefined,
		} );
		render( <SiteStatusCard rows={ rows } overall={ overall } /> );
		expect( screen.getAllByText( 'Unavailable' ).length ).toBeGreaterThan(
			0
		);
		expect(
			screen.queryByText( /is enabled and the server is reachable/ )
		).toBeNull();
	} );

	it( 'describes a feature that is simply off as Not set up', () => {
		const { rows, overall } = model( {
			cacheSettings: { enableCache: false },
		} );
		render( <SiteStatusCard rows={ rows } overall={ overall } /> );
		expect( screen.getAllByText( 'Not set up' ).length ).toBeGreaterThan(
			0
		);
		expect(
			screen.getByText( /Page cache is turned off/ )
		).toBeInTheDocument();
	} );

	it( 'surfaces a row that needs attention', () => {
		const { rows, overall } = model( {
			cacheSettings: { enableCache: true, cache_enabled: false },
		} );
		expect( overall ).toBe( STATUS.ATTENTION );
		render( <SiteStatusCard rows={ rows } overall={ overall } /> );
		expect(
			screen.getAllByText( 'Needs attention' ).length
		).toBeGreaterThan( 0 );
	} );

	it( 'offers a retry when the data could not be loaded', () => {
		const onRetry = jest.fn();
		render(
			<SiteStatusCard
				rows={ [] }
				overall={ STATUS.UNAVAILABLE }
				failed
				onRetry={ onRetry }
			/>
		);
		expect(
			screen.getByRole( 'button', { name: 'Try again' } )
		).toBeInTheDocument();
	} );

	it( 'announces the loading state politely', () => {
		render(
			<SiteStatusCard rows={ [] } overall={ STATUS.UNKNOWN } loading />
		);
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Checking your site'
		);
	} );

	it( 'announces the overall verdict exactly once', () => {
		// A screen reader must not hear the verdict once per badge.
		const { rows, overall } = model( {
			cacheSettings: { enableCache: true, cache_enabled: true },
		} );
		render( <SiteStatusCard rows={ rows } overall={ overall } /> );
		const live = screen
			.getAllByRole( 'status' )
			.filter( ( el ) => /Overall status/.test( el.textContent ) );
		expect( live ).toHaveLength( 1 );
	} );

	it( 'falls back to a known badge for an unrecognised status', () => {
		// A backend shape change must not produce a blank badge.
		render(
			<SiteStatusCard
				rows={ [
					{ id: 'x', label: 'X', status: 'nonsense', detail: 'd' },
				] }
				overall="also-nonsense"
			/>
		);
		expect( screen.getAllByText( 'Unknown' ).length ).toBeGreaterThan( 0 );
		ALL_STATUSES.forEach( ( s ) => expect( typeof s ).toBe( 'string' ) );
	} );
} );
