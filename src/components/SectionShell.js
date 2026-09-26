/**
 * SectionShell — the frame for one top-level area.
 *
 * The admin used to be seven undifferentiated top-level tabs. That is being
 * reorganised into five task-oriented areas, each of which owns one or more of
 * the existing screens. Nothing is removed: the original screens become the
 * sub-navigation inside an area, so a capability that used to be one click away
 * is at most one extra click, and always reachable.
 *
 * This component owns only the sub-navigation chrome. It deliberately does not
 * fetch anything, so moving between areas cannot trigger a data request.
 *
 * @package
 */

import { useCallback, useEffect, useRef } from '@wordpress/element';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';

/**
 * One top-level area, and the screens inside it.
 *
 * @param {Object}   props          Component props.
 * @param {string}   props.id       Area id, matching the URL `section` value.
 * @param {string}   props.title    Area title.
 * @param {string}   props.purpose  One line explaining what the area is for.
 * @param {Array}    props.items    Sub-navigation entries.
 * @param {string}   props.activeId Currently active sub-item id.
 * @param {Function} props.onSelect Called with the next sub-item id.
 * @param {*}        props.children The active screen.
 * @return {Object} The section frame.
 */
export default function SectionShell( {
	id,
	title,
	purpose,
	items,
	activeId,
	onSelect,
	children,
} ) {
	const tabRefs = useRef( {} );

	// Roving focus: arrow keys move between tabs, as the WAI-ARIA tabs pattern
	// requires, rather than making the user Tab through every sub-tab.
	const onKeyDown = useCallback(
		( event ) => {
			const index = items.findIndex( ( item ) => item.id === activeId );
			if ( index < 0 ) {
				return;
			}
			let next = null;
			if ( event.key === 'ArrowRight' ) {
				next = items[ ( index + 1 ) % items.length ];
			} else if ( event.key === 'ArrowLeft' ) {
				next = items[ ( index - 1 + items.length ) % items.length ];
			} else if ( event.key === 'Home' ) {
				next = items[ 0 ];
			} else if ( event.key === 'End' ) {
				next = items[ items.length - 1 ];
			}
			if ( ! next ) {
				return;
			}
			event.preventDefault();
			onSelect( next.id );
			// Move focus with the selection, so the keyboard user stays oriented.
			window.requestAnimationFrame( () => {
				tabRefs.current[ next.id ]?.focus();
			} );
		},
		[ items, activeId, onSelect ]
	);

	// A deep link can name a sub-item this area does not own; fall back rather
	// than render an empty panel.
	useEffect( () => {
		if (
			items.length &&
			! items.some( ( item ) => item.id === activeId )
		) {
			onSelect( items[ 0 ].id );
		}
	}, [ items, activeId, onSelect ] );

	const single = items.length < 2;

	return (
		<section
			className="wppo-section"
			id={ `wppo-section-${ id }` }
			aria-labelledby={ `wppo-section-${ id }-title` }
		>
			<header className="wppo-section__header">
				<h1
					className="wppo-section__title"
					id={ `wppo-section-${ id }-title` }
				>
					{ title }
				</h1>
				{ purpose ? (
					<p className="wppo-section__purpose">{ purpose }</p>
				) : null }
			</header>

			{ ! single && (
				<div
					className="wppo-subnav"
					role="tablist"
					aria-label={ `${ title } sections` }
				>
					{ items.map( ( item ) => {
						const selected = item.id === activeId;
						return (
							<button
								key={ item.id }
								ref={ ( el ) => {
									tabRefs.current[ item.id ] = el;
								} }
								type="button"
								role="tab"
								id={ `wppo-subtab-${ item.id }` }
								className={
									selected
										? 'wppo-subnav__tab wppo-is-active'
										: 'wppo-subnav__tab'
								}
								aria-selected={ selected }
								aria-controls={ `wppo-subpanel-${ id }` }
								tabIndex={ selected ? 0 : -1 }
								onClick={ () => onSelect( item.id ) }
								onKeyDown={ onKeyDown }
							>
								{ item.icon ? (
									<span
										className="wppo-subnav__icon"
										aria-hidden="true"
									>
										<FontAwesomeIcon icon={ item.icon } />
									</span>
								) : null }
								<span className="wppo-subnav__label">
									{ item.label }
								</span>
							</button>
						);
					} ) }
				</div>
			) }

			<div
				className="wppo-section__panel"
				id={ `wppo-subpanel-${ id }` }
				role={ single ? undefined : 'tabpanel' }
				aria-labelledby={
					single ? undefined : `wppo-subtab-${ activeId }`
				}
				// Only the selected panel is exposed to assistive tech; a hidden
				// screen reader tab stop inside an unselected panel is a defect.
				tabIndex={ 0 }
			>
				{ children }
			</div>
		</section>
	);
}
