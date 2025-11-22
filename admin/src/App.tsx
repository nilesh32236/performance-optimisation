/**
 * Main App Component - Modern Tab Design
 */

import React, { useState, useEffect } from 'react';
import { Spinner } from '@wordpress/components';

import { Layout } from './components/Layout';
import { DashboardView } from './components/Dashboard/DashboardView';
import { CachingTab } from './components/CachingTab';
import { OptimizationTab } from './components/OptimizationTab';
import { ImagesTab } from './components/ImagesTab';
import { AdvancedTab } from './components/AdvancedTab';
import type { PluginConfig } from './types';

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
			<div className="flex flex-col items-center justify-center h-screen bg-gray-50">
				<Spinner />
				<p className="mt-4 text-sm text-gray-600 font-medium">Loading Performance Optimisation...</p>
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="flex items-center justify-center h-screen bg-gray-50 p-6">
				<div className="max-w-md w-full bg-white rounded-lg border border-red-200 p-6">
					<h3 className="text-lg font-semibold text-red-800 mb-2">Configuration Error</h3>
					<p className="text-red-700 mb-4">{error}</p>
					<button 
						onClick={ () => window.location.reload() }
						className="w-full bg-red-600 text-white px-4 py-2 rounded-lg hover:bg-red-700 transition-colors"
					>
						Reload Page
					</button>
				</div>
			</div>
		);
	}

	const tabs = [
		{ id: 'dashboard', label: 'Dashboard', icon: 'dashboard' },
		{ id: 'caching', label: 'Caching', icon: 'database' },
		{ id: 'optimization', label: 'Optimization', icon: 'performance' },
		{ id: 'images', label: 'Images', icon: 'format-image' },
		{ id: 'advanced', label: 'Advanced', icon: 'admin-tools' },
	];

	const renderContent = () => {
		switch (activeTab) {
			case 'dashboard':
				return <DashboardView />;
			case 'caching':
				return <CachingTab />;
			case 'optimization':
				return <OptimizationTab />;
			case 'images':
				return <ImagesTab />;
			case 'advanced':
				return <AdvancedTab />;
			default:
				return <DashboardView />;
		}
	};

	return (
		<Layout
			activeTab={activeTab}
			onSelectTab={setActiveTab}
			tabs={tabs}
			headerActions={
				<a 
					href="https://wordpress.org/plugins/performance-optimisation/"
					target="_blank"
					rel="noopener noreferrer"
					className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
				>
					<span>Documentation</span>
					<svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
					</svg>
				</a>
			}
		>
			{renderContent()}
		</Layout>
	);
};
