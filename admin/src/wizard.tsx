/**
 * WordPress dependencies
 */
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import './styles/index.css';

const domNode = document.getElementById('performance-optimisation-wizard');

if (domNode) {
    const root = createRoot(domNode);
    root.render(
        <div className="min-h-screen flex items-center justify-center bg-gray-50 dark:bg-gray-900">
             <h1 className="text-2xl font-bold text-gray-800 dark:text-white">Setup Wizard Loading...</h1>
        </div>
    );
}
