import App from './App';
import { createRoot } from '@wordpress/element';

import './css/style.scss';

const rootElement = document.getElementById( 'performance-optimisation' );
if ( rootElement ) {
	const root = createRoot( rootElement );

	root.render( <App /> );
} else {
	// Audit #1420: loud mount failure instead of a silent blank page.
	console.error(
		'Performance Optimisation: #performance-optimisation mount node not found.'
	);
}
