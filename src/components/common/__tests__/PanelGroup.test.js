/**
 * `PanelGroup` is what stops the All-diagnostics screen being a 10-screen
 * scroll. These tests pin the three things that make it work — and one that it
 * is easy to get wrong.
 *
 * @package
 */

// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';

import PanelGroup from '../PanelGroup';

const renderGroup = ( props = {} ) =>
	render(
		<PanelGroup
			title="Advanced tuning"
			summary="Option bloat, edge caching, and llms.txt."
			{ ...props }
		>
			<button type="button">Optimize Images</button>
		</PanelGroup>
	);

describe( 'PanelGroup', () => {
	it( 'shows its content by default', () => {
		renderGroup();
		expect( screen.getByText( 'Advanced tuning' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: /Optimize Images/i } )
		).toBeVisible();
	} );

	it( 'folds when asked to, and unfolds again', () => {
		renderGroup( { defaultOpen: false } );
		const toggle = screen.getByRole( 'button', {
			name: /Advanced tuning/,
		} );

		expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
		// `getByRole` excludes anything inside a `hidden` subtree, so it would
		// throw rather than return the node to assert against. `getByText` does
		// not filter on visibility.
		expect( screen.getByText( 'Optimize Images' ) ).not.toBeVisible();

		fireEvent.click( toggle );
		expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );
		expect(
			screen.getByRole( 'button', { name: /Optimize Images/i } )
		).toBeVisible();
	} );

	it( 'points at the panel it controls', () => {
		renderGroup( { defaultOpen: false } );
		const toggle = screen.getByRole( 'button', {
			name: /Advanced tuning/,
		} );
		const controlled = toggle.getAttribute( 'aria-controls' );
		expect( controlled ).toBeTruthy();
		expect( document.getElementById( controlled ) ).not.toBeNull();
	} );

	// A real button with a real state, not a heading with a click handler.
	it( 'is a button, so Enter and Space both work', () => {
		renderGroup( { defaultOpen: false } );
		const toggle = screen.getByRole( 'button', {
			name: /Advanced tuning/,
		} );
		expect( toggle.tagName ).toBe( 'BUTTON' );
		expect( toggle ).toHaveAttribute( 'type', 'button' );
	} );

	// The group that hides "Optimize Images" behind a disclosure is the mistake
	// this commit already made once: four Dashboard tests failed because the
	// primary action became unreachable. A primary action does not belong behind
	// a fold, so the default stays open.
	it( 'is open by default, so no primary action is hidden', () => {
		renderGroup();
		expect(
			screen.getByRole( 'button', { name: /Optimize Images/i } )
		).toBeVisible();
	} );
} );
