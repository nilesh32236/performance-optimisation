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
import { useState, useRef } from '@wordpress/element';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faInfoCircle } from '@fortawesome/free-solid-svg-icons';

const Tooltip = ( { content, children } ) => {
	const [ visible, setVisible ] = useState( false );
	const id = useRef(
		`wppo-tooltip-${ Math.random().toString( 36 ).slice( 2, 9 ) }`
	);

	if ( ! content ) {
		return children;
	}

	return (
		<span
			className={ `wppo-tooltip-container${
				visible ? ' wppo-tooltip-container--visible' : ''
			}` }
			tabIndex="0"
			aria-describedby={ id.current }
			onFocus={ () => setVisible( true ) }
			onBlur={ () => setVisible( false ) }
			onMouseEnter={ () => setVisible( true ) }
			onMouseLeave={ () => setVisible( false ) }
		>
			{ children || (
				<FontAwesomeIcon
					icon={ faInfoCircle }
					className="wppo-tooltip-icon"
					aria-hidden="true"
				/>
			) }
			<span
				className="wppo-tooltip-content"
				role="tooltip"
				id={ id.current }
			>
				{ content }
			</span>
		</span>
	);
};

export default Tooltip;
