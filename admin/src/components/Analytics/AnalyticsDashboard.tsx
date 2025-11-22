/**
 * Analytics Dashboard - Refactored
 */

/**
 * External dependencies
 */
import React from 'react';
import { Panel, PanelBody } from '@wordpress/components';

/**
 * Internal dependencies
 */


const AnalyticsDashboard: React.FC = () => {
	return (
		<Panel header="Performance Over Time">
			<PanelBody>
				<p>Performance chart will be displayed here.</p>
			</PanelBody>
		</Panel>
	);
};

export default AnalyticsDashboard;