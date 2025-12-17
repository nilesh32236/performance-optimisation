/**
 * WordPress dependencies
 */
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import './styles/index.css';
import { App } from './App';

const domNode = document.getElementById('performance-optimisation-admin-app');

if (domNode) {
    const root = createRoot(domNode);
    root.render(<App />);
}
