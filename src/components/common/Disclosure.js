import { useState, useId } from '@wordpress/element';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faChevronDown } from '@fortawesome/free-solid-svg-icons';

/**
 * Disclosure — Progressive disclosure wrapper for advanced settings.
 * Uses a button with aria-expanded/aria-controls for keyboard and screen reader support.
 * Does NOT use a div as trigger.
 *
 * @param {Object}                    props               Component props.
 * @param {string}                    props.title         Visible trigger label.
 * @param {string}                    [props.description] Optional helper text under title.
 * @param {boolean}                   [props.defaultOpen] Whether open by default.
 * @param {string}                    [props.badge]       Optional badge text (e.g. Advanced).
 * @param {import('react').ReactNode} props.children      Disclosed content.
 *
 * @since NEXT
 */
const Disclosure = ( {
	title,
	description,
	defaultOpen = false,
	badge,
	children,
} ) => {
	const [ isOpen, setIsOpen ] = useState( defaultOpen );
	const contentId = useId();

	return (
		<div className="wppo-disclosure">
			<button
				type="button"
				className="wppo-disclosure__trigger"
				aria-expanded={ isOpen }
				aria-controls={ contentId }
				onClick={ () => setIsOpen( ( prev ) => ! prev ) }
			>
				<span className="wppo-disclosure__trigger-text">
					<span className="wppo-disclosure__title">{ title }</span>
					{ badge && (
						<span className="wppo-status-badge wppo-status-badge--info wppo-disclosure__badge">
							{ badge }
						</span>
					) }
				</span>
				{ description && (
					<span className="wppo-disclosure__desc">
						{ description }
					</span>
				) }
				<FontAwesomeIcon
					icon={ faChevronDown }
					className={ `wppo-disclosure__icon${
						isOpen ? ' wppo-disclosure__icon--open' : ''
					}` }
					aria-hidden="true"
				/>
			</button>
			<div
				id={ contentId }
				className="wppo-disclosure__content"
				hidden={ ! isOpen }
			>
				{ isOpen && children }
			</div>
		</div>
	);
};

export default Disclosure;
