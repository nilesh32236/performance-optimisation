import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { redactLogSecrets } from '../../lib/logSecrets';

class ErrorBoundary extends Component {
	constructor( props ) {
		super( props );
		this.state = { hasError: false, error: null };
	}

	static getDerivedStateFromError( error ) {
		return { hasError: true, error };
	}

	componentDidCatch( error ) {
		// Audit #1776: the previous `window.wppoSettings.debug` gate was dead
		// configuration surface — wp_localize_script never emitted a `debug`
		// key — and both of its branches logged the same thing, so the flag is
		// gone and the redacted message is always what gets logged.
		//
		// Minimal logging by design: the errorInfo component stack can contain
		// props/state fragments (settings, request payloads) that should not sit
		// in a shared console, so it is never logged. Audit #1493 review: error
		// messages can themselves embed request URLs or payload fragments
		// carrying nonces/tokens, so the message is redacted too.
		const message =
			error instanceof Error ? error.message : String( error );
		console.error(
			'ErrorBoundary caught:',
			redactLogSecrets( message ) || 'an error.'
		);
	}

	render() {
		if ( this.state.hasError ) {
			return (
				// Audit #1354: announce crashes to screen readers.
				<div className="wppo-error-boundary" role="alert">
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
						type="button"
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
