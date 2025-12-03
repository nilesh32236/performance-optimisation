/**
 * Analytics Dashboard - Refactored
 */

/**
 * External dependencies
 */
import React, { useState, useEffect } from 'react';
import { Panel, PanelBody, Spinner } from '@wordpress/components';

/**
 * Internal dependencies
 */


const AnalyticsDashboard: React.FC = () => {
	const [isLoading, setIsLoading] = useState(true);
	const [error, setError] = useState<string | null>(null);

	useEffect(() => {
		const loadAnalytics = async () => {
			try {
				setIsLoading(true);
				setError(null);
				// Load analytics data
				await new Promise(resolve => setTimeout(resolve, 1000));
			} catch (err) {
				setError(err instanceof Error ? err.message : 'Failed to load analytics');
			} finally {
				setIsLoading(false);
			}
		};
		loadAnalytics();
	}, []);

	return (
		<Panel header="Performance Over Time">
			<PanelBody>
				{error && (
					<div className="notice notice-error">
						<p>{error}</p>
					</div>
				)}
				{isLoading ? (
					<div style={{ textAlign: 'center', padding: '20px' }}>
						<Spinner />
						<p>Loading analytics...</p>
					</div>
				) : (
					<p>Performance chart will be displayed here.</p>
				)}
			</PanelBody>
		</Panel>
	);
};

export default AnalyticsDashboard;