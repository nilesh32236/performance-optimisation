/**
 * Route and navigation tests.
 *
 * These exist because the first version of this feature shipped with three
 * critical defects that unit tests of the hook alone could not see:
 *
 *   1. Back/Forward moved the URL and the sidebar but left the rendered panel
 *      frozen, because the rendered area was derived from separate state.
 *   2. A `?view=` or `?tab=` deep link locked navigation permanently.
 *   3. All twelve Dashboard task links silently did nothing.
 *
 * Every test below drives the rendered output or a real history entry, not just
 * the hook's return value.
 */

import {
	act,
	fireEvent,
	render,
	renderHook,
	screen,
	waitFor,
} from '@testing-library/react';

import App from '../../App';
import {
	buildSearch,
	LEGACY_PARAM,
	readSection,
	readView,
	resolveRoute,
	SECTION_PARAM,
	urlMisdescribes,
	useSectionRoute,
	VIEW_PARAM,
} from '../useSectionRoute';
import { SECTIONS, SECTION_IDS } from '../informationArchitecture';

const PAGE = '/wp-admin/admin.php?page=performance-optimisation';

/**
 * Ids of the screens an area owns, matching what the hook is given.
 *
 * @param {string} areaId Area id.
 * @return {string[]} Screen ids.
 */
const itemsFor = ( areaId ) =>
	SECTIONS.find( ( s ) => s.id === areaId )?.items.map( ( i ) => i.id ) ?? [];

/**
 * Render the hook under test with the real area list.
 *
 * @return {Object} renderHook result.
 */
const renderRoute = () =>
	renderHook( () =>
		useSectionRoute( {
			defaultArea: 'overview',
			areas: SECTION_IDS,
			itemsFor,
		} )
	);

beforeEach( () => {
	window.history.replaceState( {}, '', PAGE );
} );

describe( 'readSection / readView', () => {
	it( 'reads a valid area', () => {
		expect(
			readSection( `${ PAGE }&section=speed`, 'overview', SECTION_IDS )
		).toBe( 'speed' );
	} );

	it( 'falls back for unknown, empty and missing values', () => {
		expect(
			readSection( `${ PAGE }&section=nope`, 'overview', SECTION_IDS )
		).toBe( 'overview' );
		expect(
			readSection( `${ PAGE }&section=`, 'overview', SECTION_IDS )
		).toBe( 'overview' );
		expect( readSection( PAGE, 'overview', SECTION_IDS ) ).toBe(
			'overview'
		);
	} );

	it( 'never treats a different parameter as the area', () => {
		expect(
			readSection( `${ PAGE }&view=speed`, 'overview', SECTION_IDS )
		).toBe( 'overview' );
	} );

	it( 'rejects a view the area does not own', () => {
		expect(
			readView( `${ PAGE }&view=preload`, itemsFor( 'manage' ) )
		).toBeUndefined();
		expect(
			readView( `${ PAGE }&view=preload`, itemsFor( 'speed' ) )
		).toBe( 'preload' );
	} );
} );

describe( 'buildSearch', () => {
	it( 'keeps the WordPress page argument and the nonce', () => {
		const out = buildSearch(
			`${ PAGE }&_wpnonce=abc`,
			'speed',
			undefined,
			itemsFor( 'speed' )
		);
		expect( out ).toContain( 'page=performance-optimisation' );
		expect( out ).toContain( '_wpnonce=abc' );
		expect( out ).toContain( `${ SECTION_PARAM }=speed` );
	} );

	it( 'omits the view when it is the area default', () => {
		const out = buildSearch(
			PAGE,
			'speed',
			'fileOptimization',
			itemsFor( 'speed' )
		);
		expect( out ).not.toContain( `${ VIEW_PARAM }=` );
	} );

	it( 'writes a non-default view', () => {
		const out = buildSearch(
			PAGE,
			'speed',
			'preload',
			itemsFor( 'speed' )
		);
		expect( out ).toContain( `${ VIEW_PARAM }=preload` );
	} );

	it( 'strips the legacy tab key rather than writing it back', () => {
		const out = buildSearch(
			`${ PAGE }&${ LEGACY_PARAM }=preload`,
			'speed',
			'preload',
			itemsFor( 'speed' )
		);
		expect( out ).not.toContain( `${ LEGACY_PARAM }=preload` );
	} );
} );

describe( 'resolveRoute / urlMisdescribes', () => {
	it( 'resolves area and view together', () => {
		expect(
			resolveRoute( {
				search: `${ PAGE }&section=speed&view=preload`,
				defaultArea: 'overview',
				areas: SECTION_IDS,
				itemsFor,
			} )
		).toEqual( { area: 'speed', view: 'preload' } );
	} );

	it( 'falls back to the first screen of the named area', () => {
		expect(
			resolveRoute( {
				search: `${ PAGE }&section=media`,
				defaultArea: 'overview',
				areas: SECTION_IDS,
				itemsFor,
			} )
		).toEqual( { area: 'media', view: 'imageOptimization' } );
	} );

	it( 'flags a missing, unknown or stale URL for correction', () => {
		expect(
			urlMisdescribes(
				PAGE,
				'overview',
				'dashboard',
				itemsFor( 'overview' )
			)
		).toBe( true );
		expect(
			urlMisdescribes(
				`${ PAGE }&section=nope`,
				'overview',
				'dashboard',
				itemsFor( 'overview' )
			)
		).toBe( true );
		expect(
			urlMisdescribes(
				`${ PAGE }&section=media`,
				'media',
				'imageOptimization',
				itemsFor( 'media' )
			)
		).toBe( false );
	} );

	it( 'flags a view the area does not own', () => {
		expect(
			urlMisdescribes(
				`${ PAGE }&section=manage&view=preload`,
				'manage',
				'tools',
				itemsFor( 'manage' )
			)
		).toBe( true );
	} );
} );

describe( 'useSectionRoute', () => {
	it( 'writes the resolved area into a bare URL', () => {
		const { result } = renderRoute();
		expect( result.current.area ).toBe( 'overview' );
		expect( window.location.search ).toContain(
			`${ SECTION_PARAM }=overview`
		);
	} );

	it( 'corrects an invalid section rather than leaving the URL to lie', () => {
		window.history.replaceState(
			{},
			'',
			`${ PAGE }&section=does-not-exist`
		);
		renderRoute();
		expect( window.location.search ).toContain(
			`${ SECTION_PARAM }=overview`
		);
		expect( window.location.search ).not.toContain( 'does-not-exist' );
	} );

	it( 'strips a legacy tab key and keeps its destination', () => {
		window.history.replaceState(
			{},
			'',
			`${ PAGE }&${ LEGACY_PARAM }=preload`
		);
		const { result } = renderRoute();
		expect( result.current.area ).toBe( 'speed' );
		expect( result.current.view ).toBe( 'preload' );
		expect( window.location.search ).not.toContain(
			`${ LEGACY_PARAM }=preload`
		);
	} );

	it( 'navigates by area id', () => {
		const { result } = renderRoute();
		act( () => {
			result.current.navigate( 'media' );
		} );
		expect( result.current.area ).toBe( 'media' );
		expect( result.current.view ).toBe( 'imageOptimization' );
	} );

	it( 'navigates by screen id, so the Dashboard task links keep working', () => {
		// This is the exact call the Dashboard makes.
		const { result } = renderRoute();
		let destination;
		act( () => {
			destination = result.current.navigate( 'fileOptimization' );
		} );
		expect( destination ).toEqual( {
			area: 'speed',
			view: 'fileOptimization',
		} );
		expect( result.current.area ).toBe( 'speed' );
	} );

	it( 'returns null for a target it cannot resolve, and does not move the URL', () => {
		const { result } = renderRoute();
		const before = window.location.href;
		let outcome = 'unset';
		act( () => {
			outcome = result.current.navigate( 'not-a-real-screen' );
		} );
		expect( outcome ).toBeNull();
		expect( window.location.href ).toBe( before );
	} );

	it( 'follows popstate for BOTH the area and the view', () => {
		const { result } = renderRoute();
		act( () => {
			result.current.navigate( 'speed' );
		} );
		act( () => {
			result.current.navigate( 'preload' );
		} );
		expect( result.current.view ).toBe( 'preload' );

		window.history.replaceState(
			{},
			'',
			`${ PAGE }&${ SECTION_PARAM }=speed`
		);
		act( () => {
			window.dispatchEvent( new window.PopStateEvent( 'popstate' ) );
		} );

		// Both must move, or the panel trails the address bar.
		expect( result.current.area ).toBe( 'speed' );
		expect( result.current.view ).toBe( 'fileOptimization' );
	} );

	it( 'does not let a stale view survive a popstate into another area', () => {
		const { result } = renderRoute();
		act( () => {
			result.current.navigate( 'speed' );
		} );
		act( () => {
			result.current.navigate( 'preload' );
		} );
		window.history.replaceState(
			{},
			'',
			`${ PAGE }&${ SECTION_PARAM }=manage`
		);
		act( () => {
			window.dispatchEvent( new window.PopStateEvent( 'popstate' ) );
		} );
		expect( result.current.area ).toBe( 'manage' );
		expect( result.current.view ).toBe( 'tools' );
	} );

	it( 'is not re-run into a lockout by a legacy key left in the URL', () => {
		// The shipped bug: the legacy effect re-ran on every area change and
		// yanked the user back, so the sidebar stopped responding entirely.
		window.history.replaceState(
			{},
			'',
			`${ PAGE }&${ LEGACY_PARAM }=preload`
		);
		const { result } = renderRoute();
		act( () => {
			result.current.navigate( 'media' );
		} );
		expect( result.current.area ).toBe( 'media' );
		act( () => {
			result.current.navigate( 'manage' );
		} );
		expect( result.current.area ).toBe( 'manage' );
	} );
} );

describe( 'App navigation, asserted on rendered output', () => {
	// These are the tests that would have caught all three shipped defects.

	/**
	 * Mount the app at a given query string.
	 *
	 * @param {string} search Query string suffix.
	 * @return {Promise<Object>} user-event and render results.
	 */
	const renderApp = async ( search = '' ) => {
		window.history.replaceState( {}, '', PAGE + search );
		const result = render( <App /> );
		await screen.findByRole( 'heading', { level: 1 } );
		return result;
	};

	it( 'renders the area named in the URL, not a fixed one', async () => {
		await renderApp( '&section=media' );
		await waitFor( () =>
			expect(
				screen.getByRole( 'heading', { level: 1 } )
			).toHaveTextContent( 'Media' )
		);
	} );

	it( 'renders a different panel for each area in the URL', async () => {
		for ( const [ area, heading ] of [
			[ 'speed', 'Speed' ],
			[ 'data-system', 'Data & System' ],
			[ 'manage', 'Manage' ],
		] ) {
			window.history.replaceState(
				{},
				'',
				`${ PAGE }&section=${ area }`
			);
			const { unmount } = render( <App /> );
			await waitFor( () =>
				expect(
					screen.getByRole( 'heading', { level: 1 } )
				).toHaveTextContent( heading )
			);
			unmount();
		}
	} );

	it( 'shows the sub-screen named in the URL', async () => {
		await renderApp( '&section=speed&view=preload' );
		await waitFor( () =>
			expect(
				screen.getByRole( 'tab', { selected: true } )
			).toHaveTextContent( 'Preload' )
		);
	} );

	it( 'is not locked out by a view deep link: the sidebar still navigates', async () => {
		// The shipped bug: clicking any area did nothing on such a URL.
		await renderApp( '&section=speed&view=preload' );
		fireEvent.click( screen.getByRole( 'button', { name: /Media/ } ) );
		await waitFor( () => {
			expect( window.location.search ).toContain(
				`${ SECTION_PARAM }=media`
			);
			expect(
				screen.getByRole( 'heading', { level: 1 } )
			).toHaveTextContent( 'Media' );
		} );
	} );

	it( 'is not locked out by a legacy tab link', async () => {
		await renderApp( `&${ LEGACY_PARAM }=preload` );
		fireEvent.click( screen.getByRole( 'button', { name: /Manage/ } ) );
		await waitFor( () => {
			expect( window.location.search ).toContain(
				`${ SECTION_PARAM }=manage`
			);
			expect(
				screen.getByRole( 'heading', { level: 1 } )
			).toHaveTextContent( 'Manage' );
		} );
	} );

	it( 'keeps the URL and the rendered panel in step through history', async () => {
		await renderApp( '' );
		fireEvent.click( screen.getByRole( 'button', { name: /Speed/ } ) );
		await waitFor( () =>
			expect(
				screen.getByRole( 'heading', { level: 1 } )
			).toHaveTextContent( 'Speed' )
		);

		// Go back the way the browser does.
		act( () => {
			window.history.replaceState( {}, '', PAGE );
			window.dispatchEvent( new window.PopStateEvent( 'popstate' ) );
		} );

		await waitFor( () => {
			expect( window.location.search ).toContain(
				`${ SECTION_PARAM }=overview`
			);
			expect(
				screen.getByRole( 'heading', { level: 1 } )
			).toHaveTextContent( 'Overview' );
		} );
	} );

	it( 'falls back to Overview for an invalid area, in the URL as well as the screen', async () => {
		await renderApp( '&section=totally-bogus' );
		await waitFor( () => {
			expect(
				screen.getByRole( 'heading', { level: 1 } )
			).toHaveTextContent( 'Overview' );
			expect( window.location.search ).not.toContain( 'totally-bogus' );
		} );
	} );
} );
