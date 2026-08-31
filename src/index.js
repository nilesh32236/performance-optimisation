import App from './App';
import { createRoot } from '@wordpress/element';

import './css/style.scss';
import './css/tailwind.css';

const rootElement = document.getElementById( 'performance-optimisation' );
if ( rootElement ) {
	const root = createRoot( rootElement );

	root.render( <App /> );
}
