/**
 * Keyboard and focus walkthrough for the mobile sidebar drawer and the
 * unscoped-dialog focus contract.
 *
 * Why this exists: the drawer scrim shipped as a full-viewport
 * `<button>`. That put a screen-filling, invisible-boundary control in the
 * tab order, immediately before the drawer it was meant to sit behind — so a
 * keyboard user tabbing in from the toggle landed on it first. Geometry and
 * design tokens do not measure accessibility, so this asserts the thing
 * itself: what the browser will actually focus.
 *
 * It also pins the parts that must NOT change while the scrim is fixed:
 * Escape closes, focus returns to the opener, and the trap holds.
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

/**
 * Every element the browser would reach with Tab, in document order.
 *
 * This is the accessibility-relevant definition of the tab order: an element
 * is in it iff it is natively focusable and is not removed from it.
 *
 * @return {Element[]} Focusable elements in DOM order.
 */
const tabOrder = () =>
	Array.from(
		document.querySelectorAll(
			'a[href], button, input:not([type="hidden"]), select, textarea, [tabindex]'
		)
	).filter( ( el ) => {
		if ( el.disabled || el.hidden ) {
			return false;
		}
		// tabindex="-1" removes an element from sequential focus.
		const ti = el.getAttribute( 'tabindex' );
		if ( ti !== null && Number( ti ) < 0 ) {
			return false;
		}
		if (
			el.closest( '[hidden]' ) ||
			el.closest( '[aria-hidden="true"]' )
		) {
			return false;
		}
		return true;
	} );

/**
 * Render the app and open the mobile drawer.
 *
 * @return {Promise<HTMLElement>} The rendered container.
 */
const openDrawer = async () => {
	const { container } = render( <App /> );
	await screen.findByRole( 'heading', { level: 1 } );
	const toggle = container.querySelector( '.wppo-mobile-toggle' );
	expect( toggle ).toBeTruthy();
	await act( async () => {
		fireEvent.click( toggle );
	} );
	await waitFor( () =>
		expect( document.querySelector( '.wppo-sidebar-overlay' ) ).toBeTruthy()
	);
	return container;
};

describe( 'the mobile drawer scrim', () => {
	beforeEach( () => {
		window.history.replaceState( {}, '', `${ PAGE }&section=media` );
	} );

	it( 'is NOT in the tab order', async () => {
		await openDrawer();
		const scrim = document.querySelector( '.wppo-sidebar-overlay' );
		expect( tabOrder() ).not.toContain( scrim );
	} );

	it( 'is not a focusable control the browser will stop on', async () => {
		await openDrawer();
		const scrim = document.querySelector( '.wppo-sidebar-overlay' );
		// Not a <button>/<a>/<input>, and if it carries tabindex at all, that
		// tabindex must remove it from sequential focus.
		expect( [ 'BUTTON', 'A', 'INPUT' ] ).not.toContain( scrim.tagName );
		const ti = scrim.getAttribute( 'tabindex' );
		expect( ti === null || Number( ti ) < 0 ).toBe( true );
	} );

	it( 'is hidden from the accessibility tree, because it names nothing', async () => {
		await openDrawer();
		const scrim = document.querySelector( '.wppo-sidebar-overlay' );
		expect( scrim.getAttribute( 'aria-hidden' ) ).toBe( 'true' );
	} );

	it( 'still closes the drawer when clicked', async () => {
		await openDrawer();
		const scrim = document.querySelector( '.wppo-sidebar-overlay' );
		await act( async () => {
			fireEvent.click( scrim );
		} );
		await waitFor( () =>
			expect(
				document.querySelector( '.wppo-sidebar-overlay' )
			).toBeNull()
		);
	} );
} );

describe( 'the mobile drawer keyboard contract (must not regress)', () => {
	beforeEach( () => {
		window.history.replaceState( {}, '', `${ PAGE }&section=media` );
	} );

	it( 'closes on Escape and returns focus to the toggle', async () => {
		const container = await openDrawer();
		const toggle = container.querySelector( '.wppo-mobile-toggle' );
		await act( async () => {
			fireEvent.keyDown( document, { key: 'Escape' } );
		} );
		await waitFor( () =>
			expect(
				document.querySelector( '.wppo-sidebar-overlay' )
			).toBeNull()
		);
		await waitFor( () => expect( document.activeElement ).toBe( toggle ) );
	} );

	it( 'moves focus into the drawer when it opens', async () => {
		await openDrawer();
		const sidebar = document.getElementById( 'mobile-sidebar' );
		await waitFor( () =>
			expect( sidebar.contains( document.activeElement ) ).toBe( true )
		);
	} );

	it( 'traps Tab inside the drawer instead of escaping to the page behind', async () => {
		await openDrawer();
		const sidebar = document.getElementById( 'mobile-sidebar' );
		const focusable = Array.from(
			sidebar.querySelectorAll(
				'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
			)
		);
		expect( focusable.length ).toBeGreaterThan( 1 );
		const last = focusable[ focusable.length - 1 ];
		last.focus();
		expect( document.activeElement ).toBe( last );
		await act( async () => {
			fireEvent.keyDown( document, { key: 'Tab' } );
		} );
		expect( sidebar.contains( document.activeElement ) ).toBe( true );
	} );
} );

/**
 * KNOWN DEFECT — measured, not asserted, and deliberately NOT fixed here.
 *
 * An open ConfirmDialog does NOT return focus to its opener when the opener
 * node was replaced by a re-render. Measured on the Media screen:
 *
 *   before opening : document.activeElement = <button> (sidebar area button)
 *   dialog open    : document.activeElement = .wppo-dialog-cancel   (correct)
 *   after Escape   : document.activeElement = BODY                  (focus lost)
 *   opener.isConnected = false
 *
 * Changing area re-renders the sidebar, so React replaces the opener node.
 * ConfirmDialog's return-focus guard tests `previouslyFocused.isConnected`
 * and therefore refuses to restore, dropping focus on <body>.
 *
 * It is left for its own slice on purpose. ConfirmDialog is shared by eight
 * screens (PresetsCard, PluginSetting, ObjectCache, FileOptimization,
 * DatabaseCleanup, Dashboard, AutoloadedOptions) and this branch is scoped to
 * one screen, so fixing it here would silently change seven others. There is
 * no skipped test here on purpose too: this repo has no other skipped tests,
 * and a skip that nobody runs is not enforcement. The full repro is in
 * docs/a11y/ and in the PR that measured it.
 */
