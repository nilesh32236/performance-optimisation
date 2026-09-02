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
	};

	const handleBlur = ( e ) => {
		// Avoid flicker when focus moves from wrapper to inner interactive control.
		if ( e.relatedTarget && e.currentTarget.contains( e.relatedTarget ) ) {
			return;
		}
		setVisible( false );
	};

	return (
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions
		<span
			className={ `wppo-tooltip-container${
				hasChildren
					? ' wppo-tooltip-container--wrap'
					: ' wppo-tooltip-container--icon'
			}${ visible ? ' wppo-tooltip-container--visible' : '' }` }
			{ ...( hasChildren
				? {}
				: { tabIndex: '0', 'aria-describedby': id } ) }
			onFocus={ () => setVisible( true ) }
			onBlur={ handleBlur }
			onMouseEnter={ () => setVisible( true ) }
			onMouseLeave={ () => setVisible( false ) }
			onKeyDown={ handleKeyDown }
			onClick={ () => setVisible( ( v ) => ! v ) }
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
