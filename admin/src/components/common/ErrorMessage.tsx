/**
 * External dependencies
 */
import React from 'react';

interface ErrorMessageProps {
	message: string;
	onRetry?: () => void;
	retryLabel?: string;
	className?: string;
}

function ErrorMessage( {
	message,
	onRetry,
	retryLabel = 'Try Again',
	className = '',
}: ErrorMessageProps ) {
	return (
		<div className={ `wppo-error-message ${ className }` } role="alert">
			<div className="wppo-error-content">
				<span className="dashicons dashicons-warning" aria-hidden="true" />
				<span className="wppo-error-text">{ message }</span>
				{ onRetry && (
					<button
						type="button"
						className="wppo-button wppo-button--secondary wppo-button--small"
						onClick={ onRetry }
					>
						{ retryLabel }
					</button>
				) }
			</div>
		</div>
	);
}

export default ErrorMessage;
