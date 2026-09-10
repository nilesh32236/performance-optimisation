/**
 * Tooltip component.
 *
 * A simple, lightweight tooltip that displays on hover.
 * Uses CSS for positioning and visibility.
 *
 * @param {Object}                    props
 * @param {string}                    props.content  The tooltip text.
 * @param {import('react').ReactNode} props.children The element that triggers the tooltip.
 *
 * @since 1.5.0
 */
import { useState, useId } from '@wordpress/element';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faInfoCircle } from '@fortawesome/free-solid-svg-icons';

const Tooltip = ( { content, children } ) => {
	const [ visible, setVisible ] = useState( false );
	const id = useId();

	if ( ! content ) {
		return children;
	}

	const hasChildren = Boolean( children );

	const handleKeyDown = ( e ) => {
		if ( e.key === 'Escape' || e.key === 'Esc' ) {
			setVisible( false );
		}
		// Keyboard toggle is scoped to the icon-only trigger, which owns
		// the button role below. In wrapped mode the children provide
		// their own semantics, so bubbled Enter/Space must not toggle.
		if ( ! hasChildren && ( e.key === 'Enter' || e.key === ' ' ) ) {
			e.preventDefault();
			setVisible( ( v ) => ! v );
		}
	};

	const handleBlur = ( e ) => {
		// Avoid flicker when focus moves from wrapper to inner interactive control.
		if ( e.relatedTarget && e.currentTarget.contains( e.relatedTarget ) ) {
			return;
		}
		setVisible( false );
	};

	const handleClick = ( e ) => {
		// Scope click-toggle to tooltip trigger itself; ignore bubbled clicks from inner interactive controls.
		if (
			hasChildren &&
			e.target.closest &&
			e.target.closest( 'button, input, [role="switch"], a' )
		) {
			return;
		}
		setVisible( ( v ) => ! v );
	};

	return (
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions -- wrapped mode deliberately carries no role (children own their semantics); the icon-only branch sets role="button" via spread below.
		<span
			className={ `wppo-tooltip-container${
				hasChildren
					? ' wppo-tooltip-container--wrap'
					: ' wppo-tooltip-container--icon'
			}${ visible ? ' wppo-tooltip-container--visible' : '' }` }
			{ ...( hasChildren
				? { 'aria-describedby': id }
				: {
						role: 'button',
						tabIndex: '0',
						'aria-expanded': visible,
						'aria-describedby': id,
				  } ) }
			onFocus={ () => setVisible( true ) }
			onBlur={ handleBlur }
			onMouseEnter={ () => setVisible( true ) }
			onMouseLeave={ () => setVisible( false ) }
			onKeyDown={ handleKeyDown }
			onClick={ handleClick }
		>
			{ children || (
				<FontAwesomeIcon
					icon={ faInfoCircle }
					className="wppo-tooltip-icon"
					aria-hidden="true"
				/>
			) }
			<span className="wppo-tooltip-content" role="tooltip" id={ id }>
				{ content }
			</span>
		</span>
	);
};

export default Tooltip;
