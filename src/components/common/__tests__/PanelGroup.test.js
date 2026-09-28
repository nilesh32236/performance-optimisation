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

	// The group that hid "Optimize All" behind a disclosure was the mistake
	// this commit already made once: four Dashboard tests failed because the
	// action was unreachable. The default stays open.
	//
	// The rule is deliberately **narrower** than "no primary button is ever
	// folded": three per-panel `Save` buttons (LLMs.txt, AI, Edge Cache) do
	// live inside the folded group, because a Save is a commit for that
	// panel's own fields rather than an arrival action.
	it( 'is open by default, so a screen-level action stays reachable', () => {
		renderGroup();
		expect(
			screen.getByRole( 'button', { name: /Optimize Images/i } )
		).toBeVisible();
	} );
	// --- The four the first version did not have. ---------------------------
	//
	// An independent review ran 19 mutations and 9 survived, and these four
	// are the ones that mattered: the landmark label survived both removal and
	// being repointed at a nonexistent id, the screen-reader action phrase could
	// be deleted wholesale, the chevron could be inverted, and a hard-coded id
	// (producing three duplicate ids on the page) was green. Every a11y claim
	// the component's own docblock makes was unverified.

	it( 'exposes no region landmark, rather than a mislabelled one', () => {
		const { container } = renderGroup( { defaultOpen: false } );
		// The first version pointed `aria-labelledby` at the **button**, so the
		// region was named "Advanced tuning Option bloat, edge caching,
		// adaptive AI, and llms.txt. Expand this group" — a fifteen-word name
		// that renamed itself on every toggle. A `<section>` with no
		// `aria-label`/`aria-labelledby` is not exposed as a landmark at all,
		// which is strictly better than a wrong one. The base had no landmarks
		// here either, so this is also not a regression.
		expect( container.querySelector( 'section' ) ).not.toBeNull();
		expect( screen.queryByRole( 'region' ) ).not.toBeInTheDocument();
	} );

	it( 'describes the button with the summary instead of naming it', () => {
		renderGroup();
		const toggle = screen.getByRole( 'button', {
			name: /Advanced tuning/,
		} );
		// The button's name is the title alone: `aria-expanded` already
		// announces the state, so the "Collapse this group" tail was making a
		// fifteen-word accessible name.
		expect( toggle ).toHaveAccessibleName( 'Advanced tuning' );
		expect( toggle ).toHaveAccessibleDescription(
			'Option bloat, edge caching, and llms.txt.'
		);
	} );

	it( 'renders the summary, so it is not silently dropped', () => {
		renderGroup();
		expect(
			screen.getByText( 'Option bloat, edge caching, and llms.txt.' )
		).toBeInTheDocument();
	} );

	// A chevron pointing the wrong way is a *direction* bug, not a style one.
	it( 'points the chevron the same way as the expanded state', () => {
		const { unmount } = renderGroup( { defaultOpen: false } );
		expect(
			screen.getByRole( 'button', { name: /Advanced tuning/ } )
		).toHaveTextContent( '▸' );
		unmount();

		renderGroup();
		expect(
			screen.getByRole( 'button', { name: /Advanced tuning/ } )
		).toHaveTextContent( '▾' );
	} );

	it( 'gives each group a distinct id, so aria-controls is unambiguous', () => {
		// Two siblings in one tree, because `useId` is deliberately stable
		// across a rerender at the same position — a rerender cannot prove
		// distinctness, only two real instances can.
		render(
			<>
				<PanelGroup title="How your site is doing" summary="Lab scans.">
					<button type="button">Run Scan</button>
				</PanelGroup>
				<PanelGroup title="Advanced tuning" summary="Option bloat.">
					<button type="button">Optimize All</button>
				</PanelGroup>
			</>
		);
		const first = screen
			.getByRole( 'button', { name: 'How your site is doing' } )
			.getAttribute( 'aria-controls' );
		const second = screen
			.getByRole( 'button', { name: 'Advanced tuning' } )
			.getAttribute( 'aria-controls' );

		// A hard-coded id would make these equal, and would put three
		// duplicate ids on one page.
		expect( second ).not.toBe( first );
		expect( document.getElementById( first ) ).not.toBeNull();
		expect( document.getElementById( second ) ).not.toBeNull();
	} );

	// D-2: the prop used to be thrown away, which also made `&--spaced`
	// unreachable dead CSS.
	it( 'forwards a className to the group element', () => {
		const { container } = renderGroup( {
			className: 'wppo-panel-group--spaced',
		} );
		expect(
			container.querySelector( '.wppo-panel-group--spaced' )
		).not.toBeNull();
	} );
} );
