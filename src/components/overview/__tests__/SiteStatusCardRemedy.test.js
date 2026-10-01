/**
 * One shared remedy line for an unmeasured vital.
 *
 * The Overview's INP rows say a PageSpeed lab scan cannot measure them, which
 * is true, and an independent review pointed out what that leaves the user with:
 * no route to the source that *can*, on a site where real-user INP data is one
 * sub-screen away in the same area. The card had no links at all.
 *
 * So: one line, not one per row — two INP rows repeating the same advice is
 * noise — and only when a vital is genuinely unmeasured, so a slow metric that
 * *does* have data never produces advice about collecting it.
 *
 * @package
 */

// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import SiteStatusCard from '../SiteStatusCard';

const VITALS = () => [
	{ id: 'page-cache', labelKey: 'page-cache', status: 'healthy' },
	{ id: 'vital-lcp--desktop', labelKey: 'lcp', status: 'healthy' },
	{
		id: 'vital-inp--desktop',
		labelKey: 'inp',
		status: 'unknown',
		detailArgs: { device: 'desktop' },
	},
];

const remedy = () =>
	screen.queryByRole( 'button', { name: /Real-user Web Vitals/i } );

describe( 'SiteStatusCard remedy line', () => {
	it( 'offers a route to real-user metrics when a vital is unmeasured', () => {
		render( <SiteStatusCard rows={ VITALS() } overall="healthy" /> );
		expect( remedy() ).toBeInTheDocument();
	} );

	it( 'does not offer it when every vital has a reading', () => {
		// A metric that is merely *slow* must not produce advice about
		// collecting it — the value is present, and the row already says so.
		render(
			<SiteStatusCard
				rows={ VITALS().map( ( r ) =>
					r.status === 'unknown' ? { ...r, status: 'attention' } : r
				) }
				overall="attention"
			/>
		);
		expect( remedy() ).not.toBeInTheDocument();
	} );

	it( 'appears once however many vitals are unmeasured', () => {
		const rows = [
			...VITALS(),
			{
				id: 'vital-inp--mobile',
				labelKey: 'inp',
				status: 'unknown',
				detailArgs: { device: 'mobile' },
			},
		];
		render( <SiteStatusCard rows={ rows } overall="unknown" /> );
		expect(
			screen.getAllByRole( 'button', { name: /Real-user Web Vitals/i } )
		).toHaveLength( 1 );
	} );

	it( 'navigates to the panel that holds the real-user data', () => {
		const onNavigate = jest.fn();
		render(
			<SiteStatusCard
				rows={ VITALS() }
				overall="healthy"
				onNavigate={ onNavigate }
			/>
		);
		fireEvent.click( remedy() );
		// The **view** id, not an area and not an object. `resolveDestination`
		// does `String( target )`, so `{ area, view }` becomes
		// `"[object Object]"`, matches no section, and the click silently does
		// nothing — which is exactly what the first live run of this showed.
		expect( onNavigate ).toHaveBeenCalledWith( 'dashboard' );
	} );

	// A mutation removing the `typeof onNavigate === 'function'` guard survived
	// every other test, because they all either pass a handler or never click.
	// The card is rendered with no `onNavigate` in at least one real path, and
	// clicking the remedy there must not throw.
	// The predicate is about ONE metric, so an unmeasured LCP with no INP row
	// must not raise a sentence about taps and clicks — a state an independent
	// review produced from real model output, not a hand-built fixture.
	it( 'does not offer real-user advice for an unmeasured LCP alone', () => {
		render(
			<SiteStatusCard
				rows={ [
					{
						id: 'page-cache',
						labelKey: 'page-cache',
						status: 'healthy',
					},
					{
						id: 'vital-lcp--desktop',
						labelKey: 'lcp',
						status: 'unknown',
						detailArgs: { device: 'desktop' },
					},
				] }
				overall="unknown"
			/>
		);
		expect( remedy() ).not.toBeInTheDocument();
	} );

	it( 'offers it for an unmeasured INP in the flat, unlabelled id shape too', () => {
		// The back-compat path emits `vital-inp` with no device suffix.
		render(
			<SiteStatusCard
				rows={ [
					{
						id: 'vital-inp',
						labelKey: 'inp',
						status: 'unknown',
						detailArgs: { device: null },
					},
				] }
				overall="unknown"
			/>
		);
		expect( remedy() ).toBeInTheDocument();
	} );

	// 3. The explanatory sentence is the point of the change, and it was
	// unasserted. In a plugin whose review history is about copy honesty, an
	// unasserted user-facing sentence is a coverage gap.
	it( 'states the correction the rows cannot make themselves', () => {
		render( <SiteStatusCard rows={ VITALS() } overall="healthy" /> );
		expect(
			screen.getByText(
				/comes from real visitors, not a PageSpeed scan/i
			)
		).toBeInTheDocument();
	} );

	it( 'does not throw when clicked with no navigator supplied', () => {
		render( <SiteStatusCard rows={ VITALS() } overall="healthy" /> );
		expect( remedy() ).toBeInTheDocument();
		expect( () => fireEvent.click( remedy() ) ).not.toThrow();
	} );

	it( 'ignores a non-vital unknown row', () => {
		// An unknown *cache* state is not a metric that needs collecting, so
		// this must not raise the line.
		render(
			<SiteStatusCard
				rows={ [
					{
						id: 'object-cache',
						labelKey: 'object-cache',
						status: 'unknown',
					},
				] }
				overall="unknown"
			/>
		);
		expect( remedy() ).not.toBeInTheDocument();
	} );
} );
