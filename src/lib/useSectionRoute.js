/**
 * useSectionRoute — keeps the admin's area *and* sub-screen in the URL.
 *
 * The admin used to keep its active tab in React state only, so the browser URL
 * never changed: refresh lost the section, Back and Forward did nothing, and a
 * bookmark could not be shared.
 *
 * ## Why this owns the sub-screen too
 *
 * The first version of this hook owned only the area and left the sub-screen in
 * separate caller state. That was a desynchronisation waiting to happen, and it
 * happened: `popstate` updated the area, the sidebar followed, but the rendered
 * panel was still driven by the caller's stale sub-screen — so Back moved the
 * URL and the sidebar while the panel stayed frozen, and this module's own
 * "the URL and the screen cannot drift" claim was false.
 *
 * Holding both values here, derived from one read of the URL, makes that class
 * of bug unrepresentable rather than merely untested.
 *
 * ## What this does not do
 *
 * It does not manage the unsaved-changes guard. That stays with the caller; this
 * hook reports what the URL says and moves the URL when asked.
 *
 * @package
 */

import { useCallback, useEffect, useState } from '@wordpress/element';

/**
 * The query key that carries the area.
 */
export const SECTION_PARAM = 'section';

/**
 * The query key that carries the sub-screen within the area.
 */
export const VIEW_PARAM = 'view';

/**
 * A query key an older version of this admin used. It is consumed on arrival
 * and never written back — leaving it in place is what made a legacy link
 * re-assert itself and lock navigation permanently.
 */
export const LEGACY_PARAM = 'tab';

/**
 * Read a query value without ever throwing.
 *
 * @param {string} search A `location.search` value.
 * @param {string} key    Parameter name.
 * @return {string|null} The value, or null.
 */
const readParam = ( search, key ) => {
	try {
		return new URLSearchParams( search || '' ).get( key );
	} catch {
		return null;
	}
};

/**
 * Read a valid area id from a URL search string.
 *
 * Returns the fallback for a missing, empty, unknown or hostile value rather
 * than rendering nothing. Whether the *URL* should then be corrected is a
 * separate question, handled by the mount effect.
 *
 * @param {string}   search   A `location.search` value.
 * @param {string}   fallback Area id to use when none is valid.
 * @param {string[]} known    Every area id that exists.
 * @return {string} A valid area id.
 */
export const readSection = ( search, fallback, known ) => {
	const raw = readParam( search, SECTION_PARAM );
	return raw && known.includes( raw ) ? raw : fallback;
};

/**
 * Read a valid sub-screen for a given area.
 *
 * Returns undefined when the view is absent, unknown, or owned by a different
 * area, so the caller falls back to the area's first screen. Validating
 * ownership is what stops `?section=manage&view=preload` from opening a Speed
 * screen under a Manage header.
 *
 * @param {string}   search A `location.search` value.
 * @param {string[]} items  Sub-item ids the area owns.
 * @return {string|undefined} A valid view id, or undefined.
 */
export const readView = ( search, items ) => {
	const raw = readParam( search, VIEW_PARAM );
	return raw && ( items || [] ).includes( raw ) ? raw : undefined;
};

/**
 * Build a search string for a target area and view, preserving every other
 * admin argument — `page`, the nonce, anything a plugin added.
 *
 * `view` is omitted when it equals the area's first screen, which keeps ordinary
 * URLs short and gives "no view" exactly one representation.
 *
 * @param {string}   search A `location.search` value.
 * @param {string}   area   Target area id.
 * @param {string}   view   Target view id, or undefined.
 * @param {string[]} items  Sub-item ids the area owns.
 * @return {string} The `?…` suffix for the new location.
 */
export const buildSearch = ( search, area, view, items ) => {
	const params = new URLSearchParams( search || '' );
	params.set( SECTION_PARAM, area );
	if ( view && items && items[ 0 ] !== view ) {
		params.set( VIEW_PARAM, view );
	} else {
		params.delete( VIEW_PARAM );
	}
	// The legacy key is consumed, never rewritten.
	params.delete( LEGACY_PARAM );
	return `?${ params.toString() }`;
};

/**
 * Resolve the full route from a URL.
 *
 * One function turns a query string into the pair the UI renders, so the first
 * render, a popstate and an explicit navigation cannot disagree.
 *
 * @param {Object}   config             Configuration.
 * @param {string}   config.search      A `location.search` value.
 * @param {string}   config.defaultArea Area id when the URL names none.
 * @param {string[]} config.areas       Every area id.
 * @param {Function} config.itemsFor    ( areaId ) => sub-item ids.
 * @return {{area: string, view: string|undefined}} The resolved route.
 */
export const resolveRoute = ( { search, defaultArea, areas, itemsFor } ) => {
	const area = readSection( search, defaultArea, areas );
	const items = itemsFor( area ) || [];
	const view = readView( search, items );

	// A link from the old seven-tab scheme carries `tab=<screen id>` and no
	// `section`. Resolve it to the screen's owning area so existing bookmarks
	// and task links still land where they used to, then let the mount effect
	// strip the key. Ignoring it would silently send those links to Overview.
	const rawArea = readParam( search, SECTION_PARAM );
	if ( ! rawArea && ! view ) {
		const legacy = readParam( search, LEGACY_PARAM );
		if ( legacy ) {
			const owner = areas.find( ( id ) =>
				( itemsFor( id ) || [] ).includes( legacy )
			);
			if ( owner ) {
				return { area: owner, view: legacy };
			}
		}
	}

	return { area, view: view ?? items[ 0 ] };
};

/**
 * Whether the URL misdescribes what is on screen.
 *
 * True when the `section` key is absent or not a known id, or when a `view` is
 * present that the resolved area does not own. The mount effect uses this to
 * correct the address bar, so a bogus value cannot persist and quietly lie.
 *
 * @param {string}   search A `location.search` value.
 * @param {string}   actual The area actually rendered.
 * @param {string}   view   The view actually rendered.
 * @param {string[]} items  Sub-item ids the actual area owns.
 * @return {boolean} True when the URL should be rewritten.
 */
export const urlMisdescribes = ( search, actual, view, items ) => {
	const rawArea = readParam( search, SECTION_PARAM );
	if ( rawArea !== actual ) {
		return true;
	}
	const rawView = readParam( search, VIEW_PARAM );
	if ( ! rawView ) {
		return false;
	}
	// A view is only correct when the area owns it AND it is not the default.
	return ! ( items || [] ).includes( rawView ) || items[ 0 ] === view;
};

/**
 * Track the active area and sub-screen in the URL.
 *
 * @param {Object}   config             Configuration.
 * @param {string}   config.defaultArea Area id used when the URL names none.
 * @param {string[]} config.areas       Every area id that exists.
 * @param {Function} config.itemsFor    Returns the screen ids an area owns.
 * @return {Object} `{ area, view, navigate }` route state.
 */
export const useSectionRoute = ( { defaultArea, areas, itemsFor } ) => {
	const itemsForArea = useCallback(
		( area ) => itemsFor( area ) || [],
		[ itemsFor ]
	);

	const read = useCallback( () => {
		if ( typeof window === 'undefined' ) {
			return {
				area: defaultArea,
				view: itemsForArea( defaultArea )[ 0 ],
			};
		}
		return resolveRoute( {
			search: window.location.search,
			defaultArea,
			areas,
			itemsFor: itemsForArea,
		} );
	}, [ defaultArea, areas, itemsForArea ] );

	// The URL is the only source of truth; this is always a read of it.
	const [ route, setRoute ] = useState( read );

	const write = useCallback(
		( next, replace ) => {
			if ( typeof window === 'undefined' ) {
				return;
			}
			const url =
				window.location.pathname +
				buildSearch(
					window.location.search,
					next.area,
					next.view,
					itemsForArea( next.area )
				);
			const state = {
				...( window.history.state || {} ),
				wppoRoute: next,
			};
			if ( replace ) {
				window.history.replaceState( state, '', url );
			} else {
				window.history.pushState( state, '', url );
			}
		},
		[ itemsForArea ]
	);

	// Mount: correct a missing, unknown, stale or legacy URL so the address bar
	// describes what is on screen. `replaceState` keeps this out of history, so
	// the first Back press still leaves the admin rather than undoing a write
	// the user never made.
	useEffect( () => {
		const initial = read();
		setRoute( initial );
		if (
			typeof window !== 'undefined' &&
			urlMisdescribes(
				window.location.search,
				initial.area,
				initial.view,
				itemsForArea( initial.area )
			)
		) {
			write( initial, true );
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps -- one-shot mount sync.

	// popstate is the authority for Back/Forward, and it re-reads the whole
	// route rather than mutating one field. That is what makes the rendered
	// panel follow the address bar instead of trailing behind it.
	useEffect( () => {
		if ( typeof window === 'undefined' ) {
			return undefined;
		}
		const onPopState = () => {
			const next = read();
			setRoute( next );
			// Re-sync the address bar if the entry we landed on does not describe
			// what is now rendered. Without this, returning to a history entry
			// that lacks `section` leaves the URL silent about the screen, and the
			// "URL and screen agree" invariant holds only on the happy path.
			if (
				typeof window !== 'undefined' &&
				urlMisdescribes(
					window.location.search,
					next.area,
					next.view,
					itemsForArea( next.area )
				)
			) {
				write( next, true );
			}
		};
		window.addEventListener( 'popstate', onPopState );
		return () => window.removeEventListener( 'popstate', onPopState );
	}, [ read, itemsForArea, write ] );

	/**
	 * Navigate to an area, or to a specific screen within it.
	 *
	 * Accepts an area id, a screen id, or a legacy `tab` value. The Dashboard's
	 * existing task links pass screen ids such as `fileOptimization`, and
	 * existing bookmarks carry `tab=`. Both resolve here rather than silently
	 * doing nothing, which is what broke those links before.
	 *
	 * @param {string} target Area id, screen id, or a legacy value.
	 * @return {Object|null} The resolved destination, or null when unknown.
	 */
	const navigate = useCallback(
		( target ) => {
			if ( typeof window === 'undefined' ) {
				return null;
			}
			const raw = String( target || '' );
			let area = null;
			let view = null;

			if ( areas.includes( raw ) ) {
				area = raw;
			} else {
				const params = new URLSearchParams( window.location.search );
				const legacy = params.get( LEGACY_PARAM );
				for ( const candidate of [ raw, legacy ] ) {
					if ( ! candidate ) {
						continue;
					}
					const hit = areas.find( ( id ) =>
						itemsForArea( id ).includes( candidate )
					);
					if ( hit ) {
						area = hit;
						view = candidate;
						break;
					}
				}
			}

			if ( ! area ) {
				// Unknown target: do not move the URL, and do not pretend it
				// worked. A silent no-op here is what made 12 task links dead.
				return null;
			}
			const next = { area, view: view ?? itemsForArea( area )[ 0 ] };
			write( next, false );
			setRoute( next );
			return next;
		},
		[ areas, itemsForArea, write ]
	);

	return { area: route.area, view: route.view, navigate };
};
