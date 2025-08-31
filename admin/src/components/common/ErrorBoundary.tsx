/**
 * External dependencies
 */
import React, { Component, ErrorInfo, ReactNode } from 'react';
import { __ } from '@wordpress/i18n';

interface Props {
	children: ReactNode;
	fallback?: ReactNode;
}

interface State {
	hasError: boolean;
	error?: Error;
}

class ErrorBoundary extends Component<Props, State> {
	public state: State = {
		hasError: false,
	};

	public static getDerivedStateFromError( error: Error ): State {
		return { hasError: true, error };
	}

	public componentDidCatch( error: Error, errorInfo: ErrorInfo ) {
		console.error( 'Wizard Error Boundary caught an error:', error, errorInfo );
	}

	public render() {
		if ( this.state.hasError ) {
			if ( this.props.fallback ) {
				return this.props.fallback;
			}

			return (
				<div className="wppo-error-boundary" role="alert">
					<div className="wppo-error-content">
						<span className="dashicons dashicons-warning" aria-hidden="true" />
						<h3>{__('Something went wrong', 'performance-optimisation')}</h3>
						<p>
							{__('The setup wizard encountered an unexpected error. Please refresh the page and try again.', 'performance-optimisation')}
						</p>
						<button
							type="button"
							className="wppo-button wppo-button--secondary"
							onClick={ () => window.location.reload() }
						>
							{__('Refresh Page', 'performance-optimisation')}
						</button>
					</div>
				</div>
			);
		}

		return this.props.children;
	}
}

export default ErrorBoundary;
