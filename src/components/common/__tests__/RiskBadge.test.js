import { render, screen } from '@testing-library/react';
// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';
import RiskBadge from '../RiskBadge';

describe( 'RiskBadge', () => {
	it( 'renders the default Aggressive label with risk styling', () => {
		const { container } = render( <RiskBadge /> );
		const badge = screen.getByText( 'Aggressive' );
		expect( badge ).toHaveClass( 'wppo-status-badge' );
		expect( badge ).toHaveClass( 'wppo-status-badge--poor' );
		expect( badge ).toHaveClass( 'wppo-risk-badge' );
		expect(
			container.querySelector( '.wppo-risk-badge' )
		).toBeInTheDocument();
	} );

	it( 'supports a label override', () => {
		render( <RiskBadge label="High risk" /> );
		expect( screen.getByText( 'High risk' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Aggressive' ) ).not.toBeInTheDocument();
	} );
} );
