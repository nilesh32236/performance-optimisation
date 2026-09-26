/**
 * useSectionRoute — keeps the active admin section in the URL.
 *
 * The SPA kept its active tab in React state only, so the browser URL never
 * changed: refresh lost the section, Back and Forward did nothing, and a
 * bookmark could not be shared. This hook makes the URL the single source of
 * truth for *which section is open*, without pulling in a router dependency —
 * the repository deliberately has none, and `pushState`/`popstate` is the
 * right size of solution for one query key.
 *
 * Behaviour that must not regress:
 *
 * - WordPress admin routing is untouched. We only ever add or replace the
 *   `section` key on the existing `admin.php?page=…` URL, so `page`, the nonce
 *   and any other admin query args survive.
 * - No full page reload: in-app changes use `pushState`, and the document is
 *   only written on first mount if the URL is missing the key.
 * - An unknown or hostile value falls back to the default rather than rendering
 *   nothing, and does not get written back to the URL.
 * - The popstate listener is the single source of truth for Back/Forward, so
 *   the URL and the visible section cannot drift.
 *
 * @package
 */

import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

/**
 * The query key that carries the section. Short because it appears in every
 * URL, and deliberately distinct from the WordPress `page` key.
 */
export const SECTION_PARAM = 'section';

/**
 * Build the canonical section definitions.
 *
 * Kept in one place so navigation, the URL contract and the tests cannot
 * disagree about what exists. Order is the sidebar order.
 *
 * @param {Function} translate Translation function.
 * @return {Array<Object>} Section descriptors.
 */
export const buildSections = ( translate ) => [
	{
		id: 'overview',
		label: translate( 'Overview', 'performance-optimisation' ),
	},
	{ id: 'speed', label: translate( 'Speed', 'performance-optimisation' ) },
	{ id: 'media', label: translate( 'Media', 'performance-optimisation' ) },
	{
		id: 'data-system',
		label: translate( 'Data & System', 'performance-optimisation' ),
	},
	{ id: 'manage', label: translate( 'Manage', 'performance-optimisation' ) },
];

/**
 * Read a valid section id from a URL search string.
 *
 * Returns the default for a missing, empty, unknown or non-string value rather
 * than throwing or rendering a blank panel.
 *
 * @param {string}   search   A `location.search` value.
 * @param {string}   fallback Section id to use when none is valid.
 * @param {string[]} known    Every section id that exists.
 * @return {string} A valid section id.
 */
export const readSection = ( search, fallback, known ) => {
	try {
		const params = new URLSearchParams( search || '' );
		const raw = params.get( SECTION_PARAM );
		return known.includes( raw ) ? raw : fallback;
	} catch {
		// A malformed query string must never take the admin down.
		return fallback;
	}
};

/**
 * The query key that carries the sub-screen within an area.
 */
export const VIEW_PARAM = 'view';

/**
 * Read a valid sub-screen for a given area.
 *
 * The view is only honoured when the area that owns it is the one named in the
 * URL, so `?section=manage&view=preload` cannot open a Speed screen while the
 * sidebar says Manage. Returns undefined when the view is absent, unknown, or
 * owned by a different area, so the caller can fall back to the first item.
 *
 * @param {string}   search  A `location.search` value.
 * @param {string}   section The active area id.
 * @param {string[]} items   Sub-item ids the area owns.
 * @return {string|undefined} A valid view id, or undefined.
 */
export const readView = ( search, section, items ) => {
	try {
		const raw = new URLSearchParams( search || '' ).get( VIEW_PARAM );
		if ( ! raw ) {
			return undefined;
		}
		return ( items || [] ).includes( raw ) ? raw : undefined;
	} catch {
		return undefined;
	}
};

/**
 * Produce the URL for a section, preserving every other query argument.
 *
 * Uses the current location so `page`, the nonce and anything else a plugin or
 * WordPress added survive the navigation.
 *
 * @param {string} section Target section id.
 * @param {string} search  Current `location.search`.
 * @return {string} The `?…` suffix for the new location.
 */
export const buildSearch = ( section, search ) => {
	const params = new URLSearchParams( search || '' );
	params.set( SECTION_PARAM, section );
	return `?${ params.toString() }`;
};

/**
 * Track the active section in the URL.
 *
 * @param {Object}   config                Configuration.
 * @param {string}   config.defaultSection Section shown when the URL has none.
 * @param {string[]} config.sections       Every valid section id.
 * @return {Object} `{ active, navigate, setActive }` route state.
 */
export const useSectionRoute = ( { defaultSection, sections } ) => {
	const known = sections;
	const [ active, setActiveState ] = useState( () =>
		typeof window === 'undefined'
			? defaultSection
			: readSection( window.location.search, defaultSection, known )
	);

	// Guard against a Back/Forward arriving between render and effect.
	const mounted = useRef( false );

	// First mount: make the URL describe what is actually on screen. A bare
	// `replaceState` keeps this out of the history stack, so the first Back
	// press still leaves the admin rather than re-entering this write.
	useEffect( () => {
		if ( typeof window === 'undefined' ) {
			return;
		}
		const current = readSection(
			window.location.search,
			defaultSection,
			known
		);
		if ( current !== active ) {
			setActiveState( current );
			return;
		}
		if ( ! window.location.search.includes( `${ SECTION_PARAM }=` ) ) {
			const next =
				window.location.pathname +
				buildSearch( active, window.location.search );
			window.history.replaceState(
				{ ...( window.history.state || {} ), wppoSection: active },
				'',
				next
			);
		}
	}, [] );

	// popstate is the authority for Back/Forward. Reading the URL (not a
	// captured value) is what keeps the URL and the screen from disagreeing.
	useEffect( () => {
		if ( typeof window === 'undefined' ) {
			return undefined;
		}
		const onPopState = () => {
			const next = readSection(
				window.location.search,
				defaultSection,
				known
			);
			mounted.current = true;
			setActiveState( next );
		};
		window.addEventListener( 'popstate', onPopState );
		return () => window.removeEventListener( 'popstate', onPopState );
	}, [ defaultSection, known ] );

	const setActive = useCallback(
		( next ) => {
			if ( ! known.includes( next ) ) {
				// Never let an unknown id into the URL or the screen.
				return;
			}
			if ( typeof window !== 'undefined' && next !== active ) {
				const url =
					window.location.pathname +
					buildSearch( next, window.location.search );
				window.history.pushState(
					{ ...( window.history.state || {} ), wppoSection: next },
					'',
					url
				);
			}
			setActiveState( next );
		},
		[ active, known ]
	);

	return { active, navigate: setActive, setActive: setActiveState };
};
