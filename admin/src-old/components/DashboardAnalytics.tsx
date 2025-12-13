import React, { useState, useEffect } from 'react';
import { Button, Card, Select, Spinner, Notice } from '../components/UI';
import { secureApiFetch } from '../utils/security';
import { handleApiError, logError } from '../utils/errorHandler';
import { Dashicon } from '@wordpress/components';

interface PerformanceMetrics {
	page_load_time: number;
	cache_hit_rate: number;
	optimization_score: number;
	images_optimized: number;
	size_saved: string;
	recommendations: Array<{
		id: string;
		title: string;
		impact: 'high' | 'medium' | 'low';
		description: string;
	}>;
}

export const DashboardAnalytics: React.FC = () => {
	const [metrics, setMetrics] = useState<PerformanceMetrics | null>(null);
	const [timeRange, setTimeRange] = useState('7d');
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState<string | null>(null);

	const fetchMetrics = async () => {
		setLoading(true);
		setError(null);
		
		try {
			const data = await secureApiFetch(`analytics/dashboard?range=${encodeURIComponent(timeRange)}`);
			
			if (!data || typeof data !== 'object') {
				throw new Error('Invalid metrics data received');
			}
			
			const validatedData = {
				page_load_time: data.page_load_time || 0,
				cache_hit_rate: data.cache_hit_rate || 0,
				optimization_score: data.optimization_score || 0,
				images_optimized: data.images_optimized || 0,
				size_saved: data.size_saved || '0 KB',
				recommendations: data.recommendations || []
			};
			
			setMetrics(validatedData);
		} catch (err) {
			logError(err, { component: 'DashboardAnalytics', action: 'fetchMetrics', timeRange });
			const errorMessage = handleApiError(err);
			setError(errorMessage);
		} finally {
			setLoading(false);
		}
	};

	useEffect(() => {
		fetchMetrics();
	}, [timeRange]);

	const handleRefresh = () => {
		fetchMetrics();
	};

	if (loading) {
		return (
			<Card className="min-h-[400px] flex items-center justify-center">
				<div className="flex flex-col items-center gap-4">
					<Spinner size="large" />
					<p className="text-gray-500 font-medium">Loading analytics data...</p>
				</div>
			</Card>
		);
	}

	if (error) {
		return (
			<Card className="min-h-[200px] border-red-200 bg-red-50">
				<div className="flex flex-col items-center justify-center p-8 text-center">
					<div className="p-3 bg-red-100 rounded-full text-red-600 mb-4">
						<Dashicon icon="warning" size={32} />
					</div>
					<h3 className="text-lg font-semibold text-red-900 mb-2">Error Loading Analytics</h3>
					<p className="text-red-700 mb-6 max-w-md">{error}</p>
					<Button 
						variant="secondary"
						onClick={handleRefresh}
						className="bg-white border-red-200 text-red-700 hover:bg-red-50"
					>
						Try Again
					</Button>
				</div>
			</Card>
		);
	}

	return (
		<Card className="overflow-hidden">
			{/* Header */}
			<div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
				<div>
					<h3 className="text-lg font-semibold text-gray-900">Performance Analytics</h3>
					<p className="text-sm text-gray-500">Real-time monitoring and insights</p>
				</div>
				<div className="flex items-center gap-3">
					<Select 
						value={timeRange}
						onChange={(value) => setTimeRange(value)}
						options={[
							{ value: '24h', label: 'Last 24 Hours' },
							{ value: '7d', label: 'Last 7 Days' },
							{ value: '30d', label: 'Last 30 Days' }
						]}
						className="w-40"
					/>
					<Button 
						variant="secondary"
						onClick={handleRefresh}
						className="px-3"
					>
						<Dashicon icon="update" />
					</Button>
				</div>
			</div>

			{/* Metrics Grid */}
			<div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
				<div className="p-4 rounded-xl bg-blue-50 border border-blue-100 flex flex-col items-center text-center transition-transform hover:scale-[1.02]">
					<div className="p-2 bg-blue-100 text-blue-600 rounded-lg mb-3">
						<Dashicon icon="clock" size={24} />
					</div>
					<div className="text-2xl font-bold text-gray-900 mb-1">
						{metrics?.page_load_time?.toFixed(2) || '0.00'}s
					</div>
					<div className="text-xs font-semibold text-blue-700 uppercase tracking-wider">
						Page Load Time
					</div>
				</div>

				<div className="p-4 rounded-xl bg-green-50 border border-green-100 flex flex-col items-center text-center transition-transform hover:scale-[1.02]">
					<div className="p-2 bg-green-100 text-green-600 rounded-lg mb-3">
						<Dashicon icon="cloud-saved" size={24} />
					</div>
					<div className="text-2xl font-bold text-gray-900 mb-1">
						{metrics?.cache_hit_rate?.toFixed(1) || '0.0'}%
					</div>
					<div className="text-xs font-semibold text-green-700 uppercase tracking-wider">
						Cache Hit Rate
					</div>
				</div>

				<div className="p-4 rounded-xl bg-purple-50 border border-purple-100 flex flex-col items-center text-center transition-transform hover:scale-[1.02]">
					<div className="p-2 bg-purple-100 text-purple-600 rounded-lg mb-3">
						<Dashicon icon="performance" size={24} />
					</div>
					<div className="text-2xl font-bold text-gray-900 mb-1">
						{metrics?.optimization_score || 0}/100
					</div>
					<div className="text-xs font-semibold text-purple-700 uppercase tracking-wider">
						Optimization Score
					</div>
				</div>

				<div className="p-4 rounded-xl bg-orange-50 border border-orange-100 flex flex-col items-center text-center transition-transform hover:scale-[1.02]">
					<div className="p-2 bg-orange-100 text-orange-600 rounded-lg mb-3">
						<Dashicon icon="format-image" size={24} />
					</div>
					<div className="text-2xl font-bold text-gray-900 mb-1">
						{metrics?.images_optimized || 0}
					</div>
					<div className="text-xs font-semibold text-orange-700 uppercase tracking-wider">
						Images Optimized
					</div>
				</div>
			</div>

			{/* Size Saved Banner */}
			<div className="bg-gray-50 rounded-xl p-6 mb-8 border border-gray-100 flex items-center justify-between">
				<div className="flex items-center gap-4">
					<div className="p-3 bg-white rounded-full shadow-sm text-green-600">
						<Dashicon icon="chart-bar" size={24} />
					</div>
					<div>
						<h4 className="font-semibold text-gray-900">Total Bandwidth Saved</h4>
						<p className="text-sm text-gray-500">Across all optimizations since installation</p>
					</div>
				</div>
				<div className="text-2xl font-bold text-green-600">
					{metrics?.size_saved || '0 KB'}
				</div>
			</div>

			{/* Recommendations */}
			{metrics?.recommendations && metrics.recommendations.length > 0 && (
				<div>
					<h4 className="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2">
						<Dashicon icon="lightbulb" /> Recommendations
					</h4>
					<div className="space-y-3">
						{metrics.recommendations.map((rec) => (
							<div 
								key={rec.id}
								className={`flex items-start gap-4 p-4 rounded-lg border-l-4 transition-colors hover:bg-gray-50 ${
									rec.impact === 'high' ? 'border-l-red-500 bg-red-50/50' :
									rec.impact === 'medium' ? 'border-l-yellow-500 bg-yellow-50/50' :
									'border-l-blue-500 bg-blue-50/50'
								}`}
							>
								<div className={`flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold text-white ${
									rec.impact === 'high' ? 'bg-red-500' :
									rec.impact === 'medium' ? 'bg-yellow-500' :
									'bg-blue-500'
								}`}>
									{rec.impact === 'high' ? '!' : rec.impact === 'medium' ? '⚠' : 'i'}
								</div>
								<div className="flex-1">
									<div className="flex items-center justify-between mb-1">
										<h5 className="text-sm font-semibold text-gray-900">
											{rec.title}
										</h5>
										<span className={`text-xs px-2 py-0.5 rounded-full font-medium uppercase ${
											rec.impact === 'high' ? 'bg-red-100 text-red-700' :
											rec.impact === 'medium' ? 'bg-yellow-100 text-yellow-700' :
											'bg-blue-100 text-blue-700'
										}`}>
											{rec.impact} Impact
										</span>
									</div>
									<p className="text-sm text-gray-600 leading-relaxed">
										{rec.description}
									</p>
								</div>
							</div>
						))}
					</div>
				</div>
			)}
		</Card>
	);
};