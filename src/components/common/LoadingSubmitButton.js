import { useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
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
	// Remember the last loading text so it is never announced as empty.
	const lastLoadingRef = useRef( '' );
	const buttonText = isLoading
		? loadingLabel || label || children
		: label || children;
	const loadingText =
		loadingLabel ||
		label ||
		( typeof children === 'string' ? children : '' ) ||
		__( 'Loading…', 'performance-optimisation' );
	if ( isLoading && loadingText ) {
		lastLoadingRef.current = loadingText;
	}
	const doneText = doneLabel || lastLoadingRef.current || '';
	const liveText = isLoading ? loadingText : doneText;
	const showLiveRegion = Boolean( liveText );

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
			{ /* Audit #1354 review: persistent region (toggled text, not
			mount) so SRs never miss the announcement. Audit #1483: never
			render an empty role=status — completion announces doneLabel
			(or the last loading label) instead of clearing to ''. */ }
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
