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
		console.error( 'ErrorBoundary caught:', error, errorInfo );
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
