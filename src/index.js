import App from './App';
import { createRoot } from '@wordpress/element';

import './css/style.scss';

const rootElement = document.getElementById( 'performance-optimisation' );
if ( rootElement ) {
	const root = createRoot( rootElement );

	root.render( <App /> );
} else {
	console.error(
		'Performance Optimisation: mount node #performance-optimisation not found.'
	);
}
