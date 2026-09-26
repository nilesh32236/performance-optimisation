/**
 * App-level unsaved-changes tests.
 *
 * These close a gap two independent reviews found: **not one test in the repo
 * rendered `<App/>` in a dirty state.** Deleting the unsaved-changes guard
 * outright, or reverting it so it only guards area changes, passed all 1,107
 * tests. The guard is the whole point of this change, so it is now tested at
 * the level it actually runs at.
 *
 * The dirty flag is *derived*: `useUnsavedChanges( settings, baseline )` compares
 * a screen's current settings with the values it loaded. So the form is made
 * dirty by interacting with a real control, not by poking an event bus.
 *
 * RTL renders into a detached container, so queries go through `baseElement`.
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

/** Text only the Images screen renders. */
const MEDIA_SCREEN = /Optimize media delivery/;

/**
 * Mount the app on the Images screen, which reliably renders controls here.
 *
 * @return {Promise<HTMLElement>} The rendered container.
 */
const renderMedia = async () => {
	window.history.replaceState( {}, '', `${ PAGE }&section=media` );
	const { container } = render( <App /> );
	await screen.findByRole( 'heading', { level: 1 } );
	await screen.findByText( MEDIA_SCREEN );
	return container;
};

/**
 * A sub-tab in the section sub-navigation.
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
 * An area button in the sidebar.
 *
 * @param {HTMLElement} container Rendered container.
 * @param {RegExp}      name      Label to match.
 * @return {HTMLElement} The button.
 */
const areaButton = ( container, name ) =>
	Array.from( container.querySelectorAll( '.wppo-sidebar nav button' ) ).find(
		( el ) => name.test( el.textContent )
	);

/**
 * The open confirm dialog, if any.
 *
 * @return {HTMLElement|null} The dialog.
 */
const openDialog = () => screen.queryByRole( 'dialog' );

/**
 * Make the settings form genuinely dirty by toggling a real control.
 *
 * @return {Promise<void>} Resolves once the control has been toggled.
 */
const dirtyTheForm = async () => {
	const field = await waitFor( () => {
		const el = document.querySelector( 'input[type="checkbox"]' );
		expect( el ).toBeTruthy();
		return el;
	} );
	await act( async () => {
		fireEvent.click( field );
	} );
	// Prove the form really is dirty: a guard is only expected if it is.
	await waitFor( () =>
		expect( field.checked ).toBe( ! field.defaultChecked )
	);
};

/**
 * Click a dialog button by name.
 *
 * @param {RegExp} name Button label.
 * @return {Promise<void>} Resolves once clicked.
 */
const clickDialog = async ( name ) => {
	const button = await waitFor( () => {
		const el = screen.getByRole( 'button', { name } );
		return el;
	} );
	await act( async () => {
		fireEvent.click( button );
	} );
};

describe( 'the unsaved-changes guard on a SUB-screen change', () => {
	// Speed is the only area here with both editable controls and a sibling
	// sub-tab, so it is what pins the sub-screen half of the guard. Without
	// this, narrowing the guard to area changes only — the exact N1 revert —
	// passes the entire suite.
	beforeEach( async () => {
		window.history.replaceState(
			{},
			'',
			`${ PAGE }&section=speed&view=preload`
		);
	} );

	it( 'blocks a sub-screen change while the form is dirty', async () => {
		const { container } = render( <App /> );
		await screen.findByRole( 'heading', { level: 1 } );
		await waitFor( () =>
			expect(
				document.querySelector( '.wppo-subnav [role="tab"]' )
			).toBeTruthy()
		);
		await waitFor( () =>
			expect(
				document.querySelector( 'input[type="checkbox"]' )
			).toBeTruthy()
		);
		await dirtyTheForm();

		fireEvent.click( subTab( container, /Assets/ ) );

		await waitFor( () => expect( openDialog() ).toBeTruthy() );
		// Still on Preload, and the URL still says so.
		expect( window.location.search ).toContain( 'view=preload' );
	} );

	it( 'leaves the sub-tab click harmless when the form is clean', async () => {
		const { container } = render( <App /> );
		await screen.findByRole( 'heading', { level: 1 } );
		await waitFor( () =>
			expect(
				document.querySelector( '.wppo-subnav [role="tab"]' )
			).toBeTruthy()
		);

		fireEvent.click( subTab( container, /Assets/ ) );

		await waitFor( () => {
			expect(
				container.querySelector(
					'.wppo-subnav [role="tab"][aria-selected="true"]'
				).textContent
			).toMatch( /Assets/ );
		} );
		expect( openDialog() ).toBeNull();
	} );
} );

describe( 'the unsaved-changes guard, exercised through App', () => {
	it( 'blocks an area change while the form is dirty', async () => {
		const container = await renderMedia();
		await dirtyTheForm();

		fireEvent.click( areaButton( container, /Data/ ) );

		await waitFor( () => expect( openDialog() ).toBeTruthy() );
		expect( window.location.search ).toContain( 'section=media' );
	} );

	it( 'keeps the section and the edit when the dialog is cancelled', async () => {
		const container = await renderMedia();
		await dirtyTheForm();

		fireEvent.click( areaButton( container, /Data/ ) );
		await waitFor( () => expect( openDialog() ).toBeTruthy() );
		await clickDialog( /Cancel/ );

		await waitFor( () => expect( openDialog() ).toBeNull() );
		expect( window.location.search ).toContain( 'section=media' );
	} );

	it( 'navigates and clears the dirty state when the change is discarded', async () => {
		const container = await renderMedia();
		await dirtyTheForm();

		fireEvent.click( areaButton( container, /Data/ ) );
		await waitFor( () => expect( openDialog() ).toBeTruthy() );
		await clickDialog( /Discard/ );

		await waitFor( () => {
			expect( window.location.search ).toContain( 'section=data-system' );
		} );
		expect( openDialog() ).toBeNull();

		// Discard must clear the dirty flag, or the *next* navigation is guarded
		// against edits that no longer exist. The second click is deliberately
		// separated from the dialog dismissal: firing it immediately lands on
		// the still-mounted overlay, whose onClick cancels, and the test would
		// pass for the wrong reason.
		await waitFor( () =>
			expect( document.querySelector( '[role="dialog"]' ) ).toBeNull()
		);
		fireEvent.click( areaButton( container, /Speed/ ) );

		await waitFor( () =>
			expect( window.location.search ).toContain( 'section=speed' )
		);
		// No second dialog: the dirty flag really was cleared.
		expect( openDialog() ).toBeNull();
	} );

	it( 'blocks a browser Back while the form is dirty', async () => {
		// A popstate never reaches `navigate`, so without the veto this is a
		// silent loss of the edit: no dialog, and no browser prompt either,
		// because same-document navigation does not fire `beforeunload`.
		await renderMedia();
		await dirtyTheForm();

		act( () => {
			window.history.replaceState( {}, '', `${ PAGE }&section=speed` );
			window.dispatchEvent( new window.PopStateEvent( 'popstate' ) );
		} );

		await waitFor( () => expect( openDialog() ).toBeTruthy() );
		// The address bar is put back to what is on screen, so the URL does not
		// describe a section the user is not looking at.
		expect( window.location.search ).toContain( 'section=media' );
	} );

	it( 'honours a discarded Back by moving where the user asked to go', async () => {
		await renderMedia();
		await dirtyTheForm();

		act( () => {
			window.history.replaceState( {}, '', `${ PAGE }&section=speed` );
			window.dispatchEvent( new window.PopStateEvent( 'popstate' ) );
		} );
		await waitFor( () => expect( openDialog() ).toBeTruthy() );
		await clickDialog( /Discard/ );

		await waitFor( () =>
			expect( window.location.search ).toContain( 'section=speed' )
		);
	} );

	it( 'navigates freely when the form is NOT dirty', async () => {
		// The guard must not become a wall.
		const container = await renderMedia();

		fireEvent.click( areaButton( container, /Data/ ) );
		await waitFor( () =>
			expect( window.location.search ).toContain( 'section=data-system' )
		);
		expect( openDialog() ).toBeNull();
	} );

	it( 'follows Back without a dialog when the form is clean', async () => {
		await renderMedia();

		act( () => {
			window.history.replaceState( {}, '', `${ PAGE }&section=speed` );
			window.dispatchEvent( new window.PopStateEvent( 'popstate' ) );
		} );

		await waitFor( () =>
			expect( window.location.search ).toContain( 'section=speed' )
		);
		expect( openDialog() ).toBeNull();
	} );
} );
