import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

class ErrorBoundary extends Component {
	constructor( props ) {
		super( props );
		this.state = { hasError: false, error: null };
	}

	static getDerivedStateFromError( error ) {
		return { hasError: true, error };
	}

	componentDidCatch( error, errorInfo ) {
		// Minimal logging by default: the full errorInfo component stack can
		// contain props/state fragments (settings, request payloads) that
		// should not sit in a shared console. Verbose output is gated behind
		// window.wppoSettings.debug.
		const debug =
			typeof window !== 'undefined' &&
			typeof window.wppoSettings !== 'undefined' &&
			window.wppoSettings?.debug;
		if ( debug ) {
			console.error( 'ErrorBoundary caught:', error, errorInfo );
		} else if ( error instanceof Error ) {
			console.error( 'ErrorBoundary caught:', error.message );
		} else {
			const primitive = String( error );
			console.error( 'ErrorBoundary caught:', primitive || 'an error.' );
		}
	}

	render() {
		if ( this.state.hasError ) {
			return (
				<div className="wppo-error-boundary">
					<h3>
						{ __(
							'Something went wrong',
							'performance-optimisation'
						) }
					</h3>
					<p>
						{ __(
							'An unexpected error occurred. Please reload the page.',
							'performance-optimisation'
						) }
					</p>
					<button
						className="wppo-button wppo-button--primary"
						onClick={ () => window.location.reload() }
					>
						{ __( 'Reload', 'performance-optimisation' ) }
					</button>
				</div>
			);
		}

		return this.props.children;
	}
}

export default ErrorBoundary;
