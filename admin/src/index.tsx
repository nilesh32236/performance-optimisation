/**
 * Admin Interface Entry Point
 *
 * @package
 * @since 1.1.0
 */

/**
 * External dependencies
 */
import React from 'react';
import { createRoot } from 'react-dom/client';
/**
 * Internal dependencies
 */
import { App } from './App';
import './styles/main.scss';

// Initialize the admin interface when DOM is ready
document.addEventListener( 'DOMContentLoaded', () => {
	const container = document.getElementById( 'wppo-admin-root' );

	if ( container ) {
		const root = createRoot( container );
		root.render( <App /> );
	}
} );
