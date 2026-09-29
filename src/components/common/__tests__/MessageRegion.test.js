/**
 * A notice must be visible wherever the card that produced it sits.
 *
 * The audit found no toast, snackbar or portal anywhere in the app: twenty
 * components own their notice state and the banner renders inline inside the
 * owning card. On a four-thousand-pixel page that is often off-screen.
 *
 * @package
 */

// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';
import { render, screen, act } from '@testing-library/react';

import MessageRegion from '../MessageRegion';

import { publish } from '../../../lib/noticeBus';

describe( 'the app-level message region', () => {
	it( 'renders nothing until something is published', () => {
		const { container } = render( <MessageRegion /> );
		expect( container.querySelector( '.wppo-message-region' ) ).toBeNull();
	} );

	it( 'shows a published notice', () => {
		render( <MessageRegion /> );
		act( () => {
			publish( {
				type: 'success',
				message: 'Monitoring settings saved.',
			} );
		} );
		expect(
			screen.getByText( 'Monitoring settings saved.' )
		).toBeInTheDocument();
	} );

	it( 'carries the tone as a class, so the left rule can mean something', () => {
		const { container } = render( <MessageRegion /> );
		act( () => {
			publish( { type: 'error', message: 'Could not reach Redis.' } );
		} );
		expect(
			container.querySelector( '.wppo-message-region__item--error' )
		).not.toBeNull();
	} );

	// Two identical messages in a row must both show. Keying on the object would
	// make the second silently replace the first.
	it( 'does not collapse two identical messages into one', () => {
		render( <MessageRegion /> );
		act( () => {
			publish( { type: 'info', message: 'Saved.' } );
			publish( { type: 'info', message: 'Saved.' } );
		} );
		expect( screen.getAllByText( 'Saved.' ) ).toHaveLength( 2 );
	} );

	// The inline banner already carries role="alert", so it is already
	// announced. Announcing again here would double every message.
	it( 'is hidden from assistive technology, so nothing is announced twice', () => {
		const { container } = render( <MessageRegion /> );
		act( () => {
			publish( { type: 'success', message: 'Saved.' } );
		} );
		expect(
			container.querySelector( '.wppo-message-region' )
		).toHaveAttribute( 'aria-hidden', 'true' );
	} );
} );
