import React from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faSpinner } from '@fortawesome/free-solid-svg-icons';

/**
 * A reusable submit button with loading state support.
 *
 * @param {Object} props
 * @param {boolean} props.isLoading Whether the button is in a loading state.
 * @param {string} props.label The label to show when not loading.
 * @param {string} props.loadingLabel The label to show when loading.
 * @param {string} props.className Additional CSS classes.
 * @param {string} props.type Button type (default: 'submit').
 * @param {boolean} props.disabled Whether the button is disabled (default: isLoading).
 * @param {Object} props.rest Any other button props.
 */
const LoadingSubmitButton = ( {
	isLoading,
	label,
	loadingLabel,
	className = 'submit-button',
	type = 'submit',
	disabled,
	children,
	...rest
} ) => {
	const isDisabled = Boolean( disabled ) || Boolean( isLoading );

	return (
		<button
			type={ type }
			className={ className }
			disabled={ isDisabled }
			aria-busy={ isLoading }
			{ ...rest }
		>
			{ isLoading ? (
				<>
					<FontAwesomeIcon icon={ faSpinner } spin aria-hidden="true" />{ ' ' }
					<span role="status" aria-live="polite">
						{ loadingLabel || children }
					</span>
				</>
			) : (
				label || children
			) }
		</button>
	);
};

export default LoadingSubmitButton;
