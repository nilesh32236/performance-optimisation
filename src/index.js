/**
 * External dependencies
 */
import React from 'react';
import { createRoot } from 'react-dom/client';
/**
 * Internal dependencies
 */
import App from './App';
import './css/style.scss';

const adminAppContainer = document.getElementById('performance-optimisation-admin-app');

if (adminAppContainer) {
	const root = createRoot(adminAppContainer);
	root.render(<App adminData={window.wppoAdminData || {}} />);
} else if (window.wp?.hooks) {
	window.wp.hooks.addAction(
		'DOMContentLoaded', // Or a more specific hook
		'performance-optimisation/initAdminApp',
		() => {
			const dynamicContainer = document.getElementById('performance-optimisation-admin-app');
			if (dynamicContainer) {
				const dynamicRoot = createRoot(dynamicContainer);
				dynamicRoot.render(<App />);
			} else {
				// Admin container not found.
			}
		}
	);
}
