import { render, screen, fireEvent } from '@testing-library/react';
// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';
import NoticeBanner from '../NoticeBanner';

describe( 'NoticeBanner', () => {
	it( 'renders nothing when message is empty', () => {
		const { container } = render(
			<NoticeBanner message="" type="success" />
		);
		expect( container.firstChild ).toBeNull();
	} );

	it( 'renders a success notice with the success modifier', () => {
		const { container } = render(
			<NoticeBanner message="Saved." type="success" />
		);
		const banner = container.querySelector( '.wppo-notice' );
		expect( banner ).toHaveClass( 'wppo-notice--success' );
		expect( screen.getByText( 'Saved.' ) ).toBeInTheDocument();
		expect(
			banner.querySelector( 'svg[data-icon="circle-check"]' )
		).toBeInTheDocument();
	} );

	it( 'renders an error notice with alert semantics', () => {
		const { container } = render(
			<NoticeBanner message="Failed." type="error" />
		);
		const banner = container.querySelector( '.wppo-notice' );
		expect( banner ).toHaveClass( 'wppo-notice--error' );
		expect( banner ).toHaveAttribute( 'role', 'alert' );
		expect( banner ).not.toHaveAttribute( 'aria-live' );
		expect(
			banner.querySelector( 'svg[data-icon="triangle-exclamation"]' )
		).toBeInTheDocument();
	} );

	it( 'renders non-error notices with alert semantics and no aria-live', () => {
		const { container } = render(
			<NoticeBanner message="Heads up." type="warning" />
		);
		const banner = container.querySelector( '.wppo-notice' );
		expect( banner ).toHaveClass( 'wppo-notice--warning' );
		expect( banner ).toHaveAttribute( 'role', 'alert' );
		expect( banner ).not.toHaveAttribute( 'aria-live' );
	} );

	it( 'does not render a dismiss button without onDismiss', () => {
		render( <NoticeBanner message="Info." type="info" /> );
		expect( screen.queryByRole( 'button' ) ).not.toBeInTheDocument();
	} );

	it( 'renders a dismiss button and calls onDismiss on click', () => {
		const onDismiss = jest.fn();
		render(
			<NoticeBanner message="Info." type="info" onDismiss={ onDismiss } />
		);
		const button = screen.getByRole( 'button', { name: /Dismiss/i } );
		expect(
			button.querySelector( 'svg[data-icon="xmark"]' )
		).toBeInTheDocument();
		fireEvent.click( button );
		expect( onDismiss ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'appends a custom className', () => {
		const { container } = render(
			<NoticeBanner message="Info." type="info" className="wppo-mb-20" />
		);
		expect( container.querySelector( '.wppo-notice' ) ).toHaveClass(
			'wppo-mb-20'
		);
	} );
} );
