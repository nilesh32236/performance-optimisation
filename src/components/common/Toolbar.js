/**
 * Toolbar — the 48px sticky bar across the top of the admin.
 *
 * First item in the approved reference's target look (designs/variant-c):
 * a sticky 48px toolbar carrying the brand, a search field, a ⌘K hint, and the
 * page actions. It is the one piece of that furniture this admin did not have.
 *
 * Two behaviours, deliberately different:
 *   - the input filters as you type and shows a list you can click;
 *   - ⌘K (or Ctrl+K) opens the same list as a modal over a scrim.
 *
 * The list is the area and screen index from `informationArchitecture`, so it
 * cannot drift from the sidebar: the same SECTIONS the navigation renders.
 * Search here is navigation, not a settings search — indexing all 246 fields
 * by their visible labels would need a copy source per field, and an index
 * that silently misses a setting is worse than an honest narrower one.
 *
 * @package
 */

import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { SECTIONS } from '../../lib/informationArchitecture';

/**
 * Flatten the section tree into a single searchable list.
 *
 * @return {Array} Entries of `{ sectionId, viewId, area, label, purpose }`.
 */
const buildIndex = () =>
	SECTIONS.flatMap( ( section ) =>
		( section.items || [] ).map( ( item ) => ( {
			sectionId: section.id,
			viewId: item.id,
			area: section.label,
			label: item.label,
			purpose: section.purpose || '',
		} ) )
	);

/**
 * The toolbar.
 *
 * @param {Object}   props            Component props.
 * @param {Function} props.onNavigate Receives `{ section, view }` on pick.
 * @param {*}        [props.actions]  Page actions shown at the right.
 * @return {Object} The toolbar.
 */
export default function Toolbar( { onNavigate, actions } ) {
	const [ query, setQuery ] = useState( '' );
	const [ open, setOpen ] = useState( false );
	const [ active, setActive ] = useState( 0 );
	const inputRef = useRef( null );
	const index = useMemo( buildIndex, [] );

	const matches = useMemo( () => {
		const q = query.trim().toLowerCase();
		if ( ! q ) {
			return index.slice( 0, 8 );
		}
		return index
			.filter(
				( e ) =>
					e.label.toLowerCase().includes( q ) ||
					e.area.toLowerCase().includes( q )
			)
			.slice( 0, 8 );
	}, [ query, index ] );

	const pick = useCallback(
		( entry ) => {
			onNavigate?.( { section: entry.sectionId, view: entry.viewId } );
			setOpen( false );
			setQuery( '' );
			inputRef.current?.blur();
		},
		[ onNavigate ]
	);

	// ⌘K / Ctrl+K anywhere in the admin opens the palette. Bound on the
	// document rather than the input, because the point of a shortcut is that
	// you do not have to find the field first.
	useEffect( () => {
		const onKey = ( e ) => {
			if ( ( e.metaKey || e.ctrlKey ) && e.key.toLowerCase() === 'k' ) {
				e.preventDefault();
				setOpen( ( v ) => ! v );
				// Open AND focus. Opening without focusing leaves a keyboard user
				// on whatever they were on, looking at a list they cannot type
				// into or arrow through - the shortcut appeared to do nothing.
				// Deferred so the field exists; ⌘K is bound on the document, so
				// the ref may not be mounted on the very first keystroke.
				setTimeout( () => inputRef.current?.focus(), 0 );
			}
			if ( e.key === 'Escape' ) {
				// Both, not just `open`: showList is `open || query.trim()`, so
				// clearing only `open` left the list on screen whenever the user
				// had typed something. Escape has to close what it looks like it
				// closes.
				setOpen( false );
				setQuery( '' );
				setTimeout( () => inputRef.current?.focus(), 0 );
			}
		};
		document.addEventListener( 'keydown', onKey );
		return () => document.removeEventListener( 'keydown', onKey );
	}, [] );

	const showList = open || query.trim().length > 0;

	const onKeyDown = useCallback(
		( e ) => {
			if ( ! showList ) {
				return;
			}
			if ( e.key === 'ArrowDown' ) {
				e.preventDefault();
				setActive( ( i ) => ( i + 1 ) % Math.max( matches.length, 1 ) );
			} else if ( e.key === 'ArrowUp' ) {
				e.preventDefault();
				setActive(
					( i ) =>
						( i - 1 + Math.max( matches.length, 1 ) ) %
						Math.max( matches.length, 1 )
				);
			} else if ( e.key === 'Enter' && matches[ active ] ) {
				e.preventDefault();
				pick( matches[ active ] );
			} else if ( e.key === 'Home' ) {
				e.preventDefault();
				setActive( 0 );
			} else if ( e.key === 'End' ) {
				e.preventDefault();
				setActive( Math.max( matches.length - 1, 0 ) );
			}
		},
		[ showList, matches, active, pick ]
	);

	return (
		<div className="wppo-toolbar">
			<div className="wppo-toolbar__brand">
				<span className="wppo-toolbar__logo" aria-hidden="true">
					{ '⚡' }
				</span>
				<span className="wppo-toolbar__name">
					{ __( 'Performance', 'performance-optimisation' ) }
				</span>
			</div>

			<div className="wppo-toolbar__search">
				<label
					className="screen-reader-text"
					htmlFor="wppo-toolbar-search"
				>
					{ __(
						'Search settings and screens',
						'performance-optimisation'
					) }
				</label>
				<input
					id="wppo-toolbar-search"
					ref={ inputRef }
					type="search"
					className="wppo-toolbar__input"
					value={ query }
					placeholder={ __(
						'Search settings, run command…',
						'performance-optimisation'
					) }
					onChange={ ( e ) => setQuery( e.target.value ) }
					onFocus={ () => setOpen( true ) }
					aria-expanded={ showList }
					aria-controls="wppo-toolbar-results"
					aria-activedescendant={
						showList && matches[ active ]
							? `wppo-toolbar-result-${ active }`
							: undefined
					}
					aria-autocomplete="list"
					role="combobox"
					// Focus stays in the input the whole time - that is what makes
					// this a combobox rather than a dialog. The list is announced
					// through aria-activedescendant, so arrow keys move the
					// selection without ever moving focus off the field.
					onKeyDown={ onKeyDown }
				/>
			</div>

			<span className="wppo-toolbar__kbd" aria-hidden="true">
				{ '⌘K' }
			</span>

			{ actions ? (
				<div className="wppo-toolbar__actions">{ actions }</div>
			) : null }

			{ showList ? (
				<>
					{ /* A <button> is in the tab sequence. A full-viewport button therefore put a
 focus stop between the search field and EVERY element after it, and the
 keyboard walkthrough measured 16-58 focused elements fully obscured per
 screen as a result - the single largest WCAG failure in the redesign.
 A div with aria-hidden takes it out of the tab order while keeping the
 click-to-dismiss affordance for pointer users. Escape already closes the
 palette from the input, so keyboard users lose nothing.
					*/ }
					<div
						className="wppo-toolbar__scrim"
						aria-hidden="true"
						onClick={ () => {
							setOpen( false );
							setQuery( '' );
						} }
					/>
					<div
						className="wppo-toolbar__results"
						id="wppo-toolbar-results"
						role="listbox"
						aria-label={ __(
							'Search results',
							'performance-optimisation'
						) }
					>
						{ matches.length === 0 ? (
							<p className="wppo-toolbar__empty">
								{ __(
									'No screen matches that.',
									'performance-optimisation'
								) }
							</p>
						) : (
							matches.map( ( entry ) => (
								<button
									key={ `${ entry.sectionId }:${ entry.viewId }` }
									type="button"
									// A role="option" inside a role="listbox" is NOT a tab stop.
									// Leaving it focusable made every result an extra tab stop and
									// put the selection out of step with aria-activedescendant,
									// which is the mechanism this combobox actually uses.
									tabIndex={ -1 }
									id={ `wppo-toolbar-result-${ matches.indexOf(
										entry
									) }` }
									role="option"
									aria-selected={
										matches.indexOf( entry ) === active
									}
									className={ `wppo-toolbar__result${
										matches.indexOf( entry ) === active
											? ' wppo-toolbar__result--active'
											: ''
									}` }
									onClick={ () => pick( entry ) }
									onMouseEnter={ () =>
										setActive( matches.indexOf( entry ) )
									}
								>
									<span className="wppo-toolbar__result-area">
										{ entry.area }
									</span>
									<span className="wppo-toolbar__result-label">
										{ entry.label }
									</span>
								</button>
							) )
						) }
					</div>
				</>
			) : null }
		</div>
	);
}
