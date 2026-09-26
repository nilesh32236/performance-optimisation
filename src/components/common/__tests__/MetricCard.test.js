/**
 * Tests for MetricCard component.
 */

import { render, screen } from '@testing-library/react';
import MetricCard from '../MetricCard';
// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';

describe( 'MetricCard', () => {
	it( 'renders label and value', () => {
		render( <MetricCard label="LCP" value="2.1s" /> );
		expect( screen.getByText( 'LCP' ) ).toHaveClass(
			'wppo-metric-card__label'
		);
		expect( screen.getByText( '2.1s' ) ).toHaveClass(
			'wppo-metric-card__value'
		);
	} );

	it( 'renders a unit suffix when provided', () => {
		render( <MetricCard label="Requests" value="42" unit="req" /> );
		expect(
			screen.getByText( /req/ ).closest( '.wppo-metric-card__unit' )
		).toBeInTheDocument();
	} );

	it( 'does not render a unit when empty', () => {
		const { container } = render(
			<MetricCard label="Requests" value="42" />
		);
		expect(
			container.querySelector( '.wppo-metric-card__unit' )
		).toBeNull();
	} );

	it( 'renders a StatusBadge when a status is provided', () => {
		render( <MetricCard label="CLS" value="0.05" status="good" /> );
		expect( screen.getByText( 'Good' ) ).toHaveClass( 'wppo-status-badge' );
	} );

	it( 'does not render a badge without a status', () => {
		const { container } = render( <MetricCard label="CLS" value="0.05" /> );
		expect( container.querySelector( '.wppo-status-badge' ) ).toBeNull();
	} );
} );
