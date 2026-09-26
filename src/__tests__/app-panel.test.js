/**
 * Panel-body assertions.
 *
 * These exist because every earlier App-level test asserted only the `<h1>`
 * area label. That left a real hole: reintroducing the original "panel frozen
 * at mount" bug — the exact defect this feature exists to fix — passed the whole
 * suite, because the area heading follows `activeSection` independently of the
 * panel.
 *
 * So these assert on the **panel body**: a string only the screen named in the
 * URL can produce, never the area title. The two Speed sub-screens are used
 * deliberately, because the area heading is identical for both and so cannot
 * distinguish them.
 *
 * The screens are `React.lazy`, so every wait is an async `findBy*`. Sub-tab
 * queries go through the DOM directly: `FileOptimization` renders its own
 * `role="tab"` row, so a document-wide role query is ambiguous once that screen
 * paints.
 */

import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
} from '@testing-library/react';

import App from '../App';

const PAGE = '/wp-admin/admin.php?page=performance-optimisation';

/** Text only the Preload screen renders. */
const PRELOAD_SCREEN = /Improve perceived performance by pre-connecting/;
/** Text only the Assets & Scripts screen renders. */
const ASSETS_SCREEN = /Remove HTML Comments/;

/**
 * The selected sub-tab label, read from the section sub-nav.
 *
 * @param {HTMLElement} container Rendered container.
 * @return {string} The selected label.
 */
const selectedSubTab = ( container ) => {
	const el = container.querySelector(
		'.wppo-subnav [role="tab"][aria-selected="true"]'
	);
	return el ? el.textContent.trim() : '';
};

/**
 * The sub-tab with a given name inside the section sub-nav.
 *
 * @param {HTMLElement} container Rendered container.
 * @param {RegExp}      name      Label to match.
 * @return {HTMLElement} The button.
 */
const subTab = ( container, name ) =>
	Array.from(
		container.querySelectorAll( '.wppo-subnav [role="tab"]' )
	).find( ( el ) => name.test( el.textContent ) );

/**
 * Mount the app at a query string and wait for the lazy panel to paint.
 *
 * @param {string} search Query string suffix.
 * @return {Promise<HTMLElement>} The rendered container.
 */
const renderAt = async ( search ) => {
	window.history.replaceState( {}, '', PAGE + search );
	const { container } = render( <App /> );
	await screen.findByRole( 'heading', { level: 1 } );
	await waitFor( () =>
		expect(
			container.querySelector( '.wppo-subnav [role="tab"]' )
		).toBeTruthy()
	);
	return container;
};

/**
 * Move history the way the browser does on Back/Forward.
 *
 * @param {string} search Query string suffix for the target entry.
 * @return {void}
 */
const popTo = ( search ) => {
	act( () => {
		window.history.replaceState( {}, '', PAGE + search );
		window.dispatchEvent( new window.PopStateEvent( 'popstate' ) );
	} );
};

describe( 'the rendered panel follows the URL, not just the area heading', () => {
	it( 'paints the Preload screen for ?section=speed&view=preload', async () => {
		const container = await renderAt( '&section=speed&view=preload' );
		expect( selectedSubTab( container ) ).toBe( 'Preload' );
		expect( await screen.findByText( PRELOAD_SCREEN ) ).toBeInTheDocument();
		expect( screen.queryByText( ASSETS_SCREEN ) ).toBeNull();
	} );

	it( 'paints the Assets & Scripts screen for the same area with no view', async () => {
		const container = await renderAt( '&section=speed' );
		expect( selectedSubTab( container ) ).toBe( 'Assets & Scripts' );
		expect( await screen.findByText( ASSETS_SCREEN ) ).toBeInTheDocument();
		expect( screen.queryByText( PRELOAD_SCREEN ) ).toBeNull();
	} );

	it( 'repaints the panel when history moves between sub-screens', async () => {
		await renderAt( '&section=speed&view=preload' );
		expect( await screen.findByText( PRELOAD_SCREEN ) ).toBeInTheDocument();

		popTo( '&section=speed' );

		expect( await screen.findByText( ASSETS_SCREEN ) ).toBeInTheDocument();
		expect( screen.queryByText( PRELOAD_SCREEN ) ).toBeNull();
	} );

	it( 'repaints the panel when history moves to another area', async () => {
		await renderAt( '&section=speed&view=preload' );
		expect( await screen.findByText( PRELOAD_SCREEN ) ).toBeInTheDocument();

		popTo( '&section=media' );

		await waitFor( () =>
			expect(
				screen.getByRole( 'heading', { level: 1 } )
			).toHaveTextContent( 'Media' )
		);
		// The incoming screen is lazy, so the outgoing one stays in the DOM
		// until its chunk resolves. Wait for it to go, rather than asserting
		// the instant the heading changes.
		await waitFor( () => {
			expect( screen.queryByText( PRELOAD_SCREEN ) ).toBeNull();
			expect( screen.queryByText( ASSETS_SCREEN ) ).toBeNull();
		} );
	} );

	it( 'clicking a sub-tab repaints the panel and records it in the URL', async () => {
		const container = await renderAt( '&section=speed' );
		expect( await screen.findByText( ASSETS_SCREEN ) ).toBeInTheDocument();

		fireEvent.click( subTab( container, /Preload/ ) );

		expect( await screen.findByText( PRELOAD_SCREEN ) ).toBeInTheDocument();
		expect( window.location.search ).toContain( 'view=preload' );
	} );

	it( 'a deep link carrying a consumed legacy key still paints the named screen', async () => {
		await renderAt( '&section=speed&view=preload&tab=preload' );
		expect( await screen.findByText( PRELOAD_SCREEN ) ).toBeInTheDocument();
		expect( window.location.search ).not.toContain( 'tab=' );
	} );
} );
