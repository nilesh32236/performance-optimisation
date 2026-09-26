import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faSpinner } from '@fortawesome/free-solid-svg-icons';

/**
 * A reusable submit button with loading state support.
 *
 * @param {Object}               props              Component props.
 * @param {boolean}              props.isLoading    Whether the button is in a loading state.
 * @param {string}               props.label        The label to show when not loading.
 * @param {string}               props.loadingLabel The label to show when loading.
 * @param {string}               [props.doneLabel]  Optional completion announcement for the live region.
 * @param {string}               props.className    Additional CSS classes.
 * @param {string}               props.type         Button type (default: 'submit').
 * @param {boolean}              props.disabled     Whether the button is disabled (default: isLoading).
 * @param {Object}               props.rest         Any other button props.
 * @param {import('react').Node} props.children     The child elements.
 */
const LoadingSubmitButton = ( {
	isLoading,
	label,
	loadingLabel,
	doneLabel,
	className = 'wppo-button wppo-button--primary',
	type = 'submit',
	disabled,
	children,
	...rest
} ) => {
	const isDisabled = Boolean( disabled ) || Boolean( isLoading );
	const buttonText = isLoading
		? loadingLabel || label || children
		: label || children;

	// The live region announces **completion only**, and only when the caller
	// asked for it.
	//
	// It previously also announced the loading text, and remembered the last
	// loading text in a ref to use as a fallback. Both were defects, measured on
	// the live page:
	//
	// 1. While loading, the region contained the same text the button already
	//    showed, so a screen reader heard it twice.
	// 2. The ref was never cleared, so after a single save the region *permanently*
	//    held the loading text — a `role="status"` reading "Saving…" on an idle
	//    form, for the rest of the page's life. No production caller passes
	//    `doneLabel`, so this was the state every one of the 16 usages reached.
	//
	// The button's own `aria-busy` and disabled state, plus the text change to
	// `loadingLabel`, already convey the in-flight state to assistive tech.
	const showLiveRegion = ! isLoading && Boolean( doneLabel );
	const liveText = doneLabel || '';

	return (
		<>
			<button
				{ ...rest }
				type={ type }
				className={ className }
				disabled={ isDisabled }
				aria-busy={ isLoading || undefined }
			>
				{ isLoading && (
					<FontAwesomeIcon
						icon={ faSpinner }
						spin
						aria-hidden="true"
						className="wppo-mr-8"
					/>
				) }
				<span>{ buttonText }</span>
			</button>
			{ /* Present only when there is a completion to announce, so it can
			never be left holding stale text, and never renders empty. */ }
			{ showLiveRegion && (
				<span
					role="status"
					aria-live="polite"
					className="wppo-screen-reader-text"
				>
					{ liveText }
				</span>
			) }
		</>
	);
};

export default LoadingSubmitButton;
