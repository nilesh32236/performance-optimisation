import { render, screen, fireEvent } from '@testing-library/react';
// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';
import NoticeBanner, { NOTICE_TYPES, NOTICE_ICONS } from '../NoticeBanner';

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
		expect( banner ).toHaveAttribute( 'aria-live', 'assertive' );
		expect(
			banner.querySelector( 'svg[data-icon="triangle-exclamation"]' )
		).toBeInTheDocument();
	} );

	it( 'renders non-error notices with status semantics and polite live', () => {
		const { container } = render(
			<NoticeBanner message="Heads up." type="warning" />
		);
		const banner = container.querySelector( '.wppo-notice' );
		expect( banner ).toHaveClass( 'wppo-notice--warning' );
		expect( banner ).toHaveAttribute( 'role', 'status' );
		expect( banner ).toHaveAttribute( 'aria-live', 'polite' );
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

	it( 'exposes a per-type icon map with an info icon distinct from warning', () => {
		expect( [ ...NOTICE_TYPES ].sort() ).toEqual( [
			'error',
			'info',
			'success',
			'warning',
		] );
		expect( NOTICE_ICONS.info ).toBeDefined();
		expect( NOTICE_ICONS.info ).not.toBe( NOTICE_ICONS.warning );
	} );

	it( 'falls back to info styling for unknown types', () => {
		const { container } = render(
			<NoticeBanner message="Hmm." type="bogus" />
		);
		const banner = container.querySelector( '.wppo-notice' );
		expect( banner ).toHaveClass( 'wppo-notice--info' );
		expect( banner ).not.toHaveClass( 'wppo-notice--bogus' );
	} );
} );
