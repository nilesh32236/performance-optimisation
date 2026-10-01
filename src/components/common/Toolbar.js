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
			}
			if ( e.key === 'Escape' ) {
				setOpen( false );
			}
		};
		document.addEventListener( 'keydown', onKey );
		return () => document.removeEventListener( 'keydown', onKey );
	}, [] );

	const showList = open || query.trim().length > 0;

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
					role="combobox"
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
					<button
						type="button"
						className="wppo-toolbar__scrim"
						aria-label={ __(
							'Close search',
							'performance-optimisation'
						) }
						onClick={ () => {
							setOpen( false );
							setQuery( '' );
						} }
					/>
					<div
						className="wppo-toolbar__results"
						id="wppo-toolbar-results"
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
									className="wppo-toolbar__result"
									onClick={ () => pick( entry ) }
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
