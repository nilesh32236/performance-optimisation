/**
 * Main App Component - Refactored
 */

/**
 * External dependencies
 */
import React, { useState, useEffect } from 'react';
import { PluginConfig } from '@types/index';
import { Button, Card, CardBody, CardHeader, Spinner, TabPanel } from '@wordpress/components';

/**
 * Internal dependencies
 */
import MetricsOverview from './components/Analytics/MetricsOverview';
import AnalyticsDashboard from './components/Analytics/AnalyticsDashboard';
import OptimizationStatus from './components/Analytics/OptimizationStatus';
import { CachingTab } from './components/CachingTab';
import { OptimizationTab } from './components/OptimizationTab';
import { ImagesTab } from './components/ImagesTab';
import { AdvancedTab } from './components/AdvancedTab';
import { DashboardAnalytics } from './components/DashboardAnalytics';

// Import simple stylesheet
import './styles/style.scss';

export const App: React.FC = () => {
	const [ config, setConfig ] = useState<PluginConfig | null>( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState<string | null>( null );
	const [ activeTab, setActiveTab ] = useState<string>( 'dashboard' );

	useEffect( () => {
		const loadConfig = async () => {
			try {
				if ( window.wppoAdmin ) {
					setConfig( window.wppoAdmin );
				} else {
					throw new Error( 'Configuration not available' );
				}
			} catch ( err ) {
				setError( err instanceof Error ? err.message : 'Failed to load configuration' );
			} finally {
				setLoading( false );
			}
		};

		loadConfig();
	}, [] );

	if ( loading ) {
		return (
			<div className="wppo-admin-loading">
				<Spinner />
				<p>Loading Performance Optimisation settings...</p>
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="wppo-admin-error">
				<Card>
					<CardHeader>Error</CardHeader>
					<CardBody>{error}</CardBody>
					<Button isPrimary onClick={ () => window.location.reload() }>Reload Page</Button>
				</Card>
			</div>
		);
	}

	const renderDashboardTab = (safeConfig: any) => (
		<div className="wppo-dashboard-tab">
			<MetricsOverview config={safeConfig} />
			<DashboardAnalytics />
			<AnalyticsDashboard />
			<OptimizationStatus config={safeConfig} />
		</div>
	);

	const onSelectTab = ( tabName: string ) => {
		setActiveTab( tabName );
	};

	const tabs = [
		{ name: 'dashboard', title: 'Dashboard' },
		{ name: 'caching', title: 'Caching' },
		{ name: 'optimization', title: 'Optimization' },
		{ name: 'images', title: 'Images' },
		{ name: 'advanced', title: 'Advanced' },
	];

	return (
		<div className="wppo-admin">
			<TabPanel
				className="wppo-admin__tabs"
				activeClass="is-active"
				onSelect={onSelectTab}
				tabs={tabs}
			>
				{(tab) => {
					const safeConfig = {
						...config,
						overview: {
							performance_score: 75,
							average_load_time: 2.5,
							cache_hit_ratio: config?.cacheStats?.hit_ratio || 0,
						},
					};

					return (
						<div className="wppo-admin__content">
							{tab.name === 'dashboard' && renderDashboardTab(safeConfig)}
							{tab.name === 'caching' && <CachingTab />}
							{tab.name === 'optimization' && <OptimizationTab />}
							{tab.name === 'images' && <ImagesTab />}
							{tab.name === 'advanced' && <AdvancedTab />}
						</div>
					);
				}}
			</TabPanel>
		</div>
	);
};
