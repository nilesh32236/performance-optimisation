/**
 * External dependencies
 */
import React from 'react';
import { createRoot } from 'react-dom/client';
/**
 * Internal dependencies
 */
import SetupWizard from './components/Wizard/SetupWizard';
import './styles/main.scss';

// Initialize the wizard when DOM is ready
document.addEventListener( 'DOMContentLoaded', function() {
	const container = document.getElementById( 'performance-optimisation-wizard-app' );
	if ( container ) {
		// Hide the loading indicator
		const loadingIndicator = container.querySelector( '.wppo-wizard-loading-initial' );
		if ( loadingIndicator ) {
			loadingIndicator.style.display = 'none';
		}

		// Check if required data is available
		if ( ! window.wppoWizardData ) {
			container.innerHTML = `
                <div class="wppo-wizard-error" role="alert">
                    <span class="dashicons dashicons-warning"></span>
                    Setup wizard could not load properly. Please refresh the page and try again.
                </div>
            `;
			return;
		}

		try {
			const root = createRoot( container );
			const { apiUrl, nonce, translations } = window.wppoWizardData;

			root.render( <SetupWizard apiUrl={ apiUrl } nonce={ nonce } translations={ translations } /> );
		} catch ( error ) {
			console.error( 'Wizard initialization error:', error );
			container.innerHTML = `
                <div class="wppo-wizard-error" role="alert">
                    <span class="dashicons dashicons-warning"></span>
                    Setup wizard failed to initialize. Please refresh the page and try again.
                </div>
            `;
		}
	}
} );

// Add TypeScript declarations for global variables
declare global {
	interface Window {
		wppoWizardData: {
			apiUrl: string;
			nonce: string;
			translations: Record<string, string>;
		};
	}
}
