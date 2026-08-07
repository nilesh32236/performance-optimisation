import { render, screen, fireEvent } from '@testing-library/react';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import NoticeBanner from '../NoticeBanner';

jest.mock( '@fortawesome/react-fontawesome', () => ( {
	FontAwesomeIcon: ( { icon } ) => (
		<span data-icon={ icon?.iconName || 'icon' } />
	),
} ) );

jest.mock( '@fortawesome/free-solid-svg-icons', () => ( {
	faCheckCircle: { iconName: 'check-circle' },
	faExclamationCircle: { iconName: 'exclamation-circle' },
	faExclamationTriangle: { iconName: 'exclamation-triangle' },
	faInfoCircle: { iconName: 'info-circle' },
	faTimes: { iconName: 'times' },
} ) );

describe( 'NoticeBanner', () => {
	it( 'renders the message with a success variant', () => {
		render( <NoticeBanner type="success" message="Saved." /> );
		expect( screen.getByText( 'Saved.' ) ).toBeInTheDocument();
		const banner = screen.getByRole( 'alert' );
		expect( banner ).toHaveClass( 'wppo-notice' );
		expect( banner ).toHaveClass( 'wppo-notice--success' );
	} );

	it( 'renders each notice variant', () => {
		const { rerender } = render(
			<NoticeBanner type="success" message="ok" />
		);
		for ( const type of [ 'success', 'error', 'warning', 'info' ] ) {
			rerender( <NoticeBanner type={ type } message="msg" /> );
			expect( screen.getByRole( 'alert' ) ).toHaveClass(
				`wppo-notice--${ type }`
			);
		}
	} );

	it( 'maps error to assertive aria-live and others to polite', () => {
		const { rerender } = render(
			<NoticeBanner type="error" message="Nope." />
		);
		expect( screen.getByRole( 'alert' ) ).toHaveAttribute(
			'aria-live',
			'assertive'
		);
		rerender( <NoticeBanner type="success" message="Yep." /> );
		expect( screen.getByRole( 'alert' ) ).toHaveAttribute(
			'aria-live',
			'polite'
		);
	} );

	it( 'calls onDismiss when the dismiss button is clicked', () => {
		const onDismiss = jest.fn();
		render(
			<NoticeBanner
				type="info"
				message="Heads up."
				onDismiss={ onDismiss }
			/>
		);
		fireEvent.click( screen.getByRole( 'button', { name: /Dismiss/i } ) );
		expect( onDismiss ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does not render a dismiss button when onDismiss is omitted', () => {
		render( <NoticeBanner type="error" message="msg" /> );
		expect(
			screen.queryByRole( 'button', { name: /Dismiss/i } )
		).not.toBeInTheDocument();
	} );

	it( 'appends extra className when provided', () => {
		render(
			<NoticeBanner type="success" message="msg" className="wppo-mb-20" />
		);
		expect( screen.getByRole( 'alert' ) ).toHaveClass( 'wppo-mb-20' );
	} );
} );
