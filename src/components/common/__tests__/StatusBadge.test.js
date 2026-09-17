/**
 * Tests for StatusBadge component.
 */

import { render, screen } from '@testing-library/react';
import StatusBadge from '../StatusBadge';
// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';

describe( 'StatusBadge', () => {
	it( 'renders a good badge with a translated label', () => {
		render( <StatusBadge status="good" /> );
		const badge = screen.getByText( 'Good' );
		expect( badge ).toHaveClass( 'wppo-status-badge' );
		expect( badge ).toHaveClass( 'wppo-status-badge--good' );
	} );

	it( 'renders a needs_improvement badge', () => {
		render( <StatusBadge status="needs_improvement" /> );
		const badge = screen.getByText( 'Needs Improvement' );
		expect( badge ).toHaveClass( 'wppo-status-badge--needs_improvement' );
	} );

	it( 'maps the lib/status.js warning level to Needs Improvement', () => {
		render( <StatusBadge status="warning" /> );
		const badge = screen.getByText( 'Needs Improvement' );
		expect( badge ).toHaveClass( 'wppo-status-badge--needs_improvement' );
	} );

	it( 'renders a poor badge', () => {
		render( <StatusBadge status="poor" /> );
		const badge = screen.getByText( 'Poor' );
		expect( badge ).toHaveClass( 'wppo-status-badge--poor' );
	} );

	it( 'falls back to a safe unknown badge for unexpected values', () => {
		render( <StatusBadge status="unknown_value" /> );
		const badge = screen.getByText( 'Unknown' );
		expect( badge ).toHaveClass( 'wppo-status-badge--unknown' );
	} );

	it( 'falls back to a safe unknown badge when status is missing', () => {
		render( <StatusBadge status={ undefined } /> );
		const badge = screen.getByText( 'Unknown' );
		expect( badge ).toHaveClass( 'wppo-status-badge--unknown' );
	} );
} );
