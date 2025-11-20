import React, { useState, useEffect } from 'react';
import { Button, Card, Switch, Spinner, Notice } from '../components/UI';
import { secureApiFetch } from '../utils/security';

interface CacheStats {
	page_cache: { size: string; files: number; hit_rate: number; enabled: boolean };
	object_cache: { enabled: boolean; hit_rate: number; backend: string };
	browser_cache: { enabled: boolean; max_age: number };
	total_requests: number;
	cache_savings: string;
}

export const CachingTab: React.FC = () => {
	const [stats, setStats] = useState<CacheStats | null>(null);
	const [loading, setLoading] = useState(false);
	const [notification, setNotification] = useState<{type: string, message: string} | null>(null);
	const [settings, setSettings] = useState({
		page_cache_enabled: true,
		object_cache_enabled: true,
		browser_cache_enabled: true,
		cache_preload_enabled: false,
		cache_compression: true,
		cache_mobile_separate: false
	});

	const fetchCacheStats = async () => {
		try {
			const data = await secureApiFetch('/wp-json/performance-optimisation/v1/cache/stats');
			setStats(data);
		} catch (error) {
			console.error('Failed to fetch cache stats:', error);
			setNotification({
				type: 'error',
				message: 'Failed to load cache statistics'
			});
		}
	};

	const clearCache = async (type: string) => {
		setLoading(true);
		try {
			await secureApiFetch('/wp-json/performance-optimisation/v1/cache/clear', {
				method: 'POST',
				data: { type }
			});
			
			setNotification({
				type: 'success',
				message: `${type === 'all' ? 'All cache' : `${type} cache`} cleared successfully!`
			});
			
			await fetchCacheStats();
		} catch (error) {
			setNotification({
				type: 'error',
				message: 'Failed to clear cache. Please try again.'
			});
		}
		setLoading(false);
		
		setTimeout(() => setNotification(null), 3000);
	};

	const saveSettings = async () => {
		setLoading(true);
		try {
			await secureApiFetch('/wp-json/performance-optimisation/v1/cache/settings', {
				method: 'POST',
				data: settings
			});
			
			setNotification({
				type: 'success',
				message: 'Cache settings saved successfully!'
			});
		} catch (error) {
			setNotification({
				type: 'error',
				message: 'Failed to save settings. Please try again.'
			});
		}
		setLoading(false);
		
		setTimeout(() => setNotification(null), 3000);
	};

	useEffect(() => {
		fetchCacheStats();
		
		// Set up periodic refresh
		const interval = setInterval(fetchCacheStats, 30000); // Refresh every 30 seconds
		
		return () => clearInterval(interval);
	}, []);

	const getCacheHealthColor = (hitRate: number) => {
		if (hitRate >= 90) return 'success';
		if (hitRate >= 70) return 'warning';
		return 'error';
	};

	const validateCacheSettings = (newSettings: typeof settings) => {
		const errors: string[] = [];
		
		if (newSettings.cache_preload_enabled && !newSettings.page_cache_enabled) {
			errors.push('Cache preloading requires page caching to be enabled');
		}
		
		if (newSettings.cache_mobile_separate && !newSettings.page_cache_enabled) {
			errors.push('Separate mobile cache requires page caching to be enabled');
		}
		
		return errors;
	};

	const handleSettingChange = (key: keyof typeof settings, value: boolean) => {
		const newSettings = { ...settings, [key]: value };
		const errors = validateCacheSettings(newSettings);
		
		if (errors.length > 0) {
			setNotification({
				type: 'warning',
				message: errors.join('. ')
			});
			return;
		}
		
		setSettings(newSettings);
	};

	return (
		<div className="wppo-caching-tab">
			{notification && (
				<Notice type={notification.type as any}>
					{notification.message}
				</Notice>
			)}

			{/* Cache Overview Stats */}
			<div className="wppo-stats-grid">
				{stats ? (
					<>
						<div className={`wppo-stat wppo-stat--${getCacheHealthColor(stats.page_cache.hit_rate)}`}>
							<div className="wppo-stat-icon">⚡</div>
							<span className="wppo-stat-value">{stats.page_cache.hit_rate}%</span>
							<div className="wppo-stat-label">Page Cache Hit Rate</div>
							<div className="wppo-stat-meta">{stats.page_cache.files} files cached</div>
						</div>
						
						<div className={`wppo-stat wppo-stat--${getCacheHealthColor(stats.object_cache.hit_rate)}`}>
							<div className="wppo-stat-icon">🗄️</div>
							<span className="wppo-stat-value">{stats.object_cache.hit_rate}%</span>
							<div className="wppo-stat-label">Object Cache Hit Rate</div>
							<div className="wppo-stat-meta">{stats.object_cache.backend} backend</div>
						</div>
						
						<div className="wppo-stat wppo-stat--success">
							<div className="wppo-stat-icon">💾</div>
							<span className="wppo-stat-value">{stats.page_cache.size}</span>
							<div className="wppo-stat-label">Cache Size</div>
							<div className="wppo-stat-meta">Saved: {stats.cache_savings}</div>
						</div>
						
						<div className="wppo-stat">
							<div className="wppo-stat-icon">📊</div>
							<span className="wppo-stat-value">{stats.total_requests.toLocaleString()}</span>
							<div className="wppo-stat-label">Total Requests</div>
							<div className="wppo-stat-meta">Last 24 hours</div>
						</div>
					</>
				) : (
					<div className="wppo-loading-stats">
						<Spinner size="large" />
						<p>Loading cache statistics...</p>
					</div>
				)}
			</div>

			<div className="wppo-grid wppo-grid--2-col">
				{/* Cache Management */}
				<Card title="🚀 Cache Management" className="wppo-card--highlight">
					<div className="wppo-cache-controls">
						<div className="wppo-control-group">
							<h4>Quick Actions</h4>
							<div className="wppo-button-group">
								<Button 
									variant="primary" 
									onClick={() => clearCache('all')}
									disabled={loading}
								>
									{loading ? <Spinner size="small" /> : '🗑️'} Clear All Cache
								</Button>
								<Button 
									variant="secondary" 
									onClick={() => clearCache('page')}
									disabled={loading}
								>
									📄 Clear Page Cache
								</Button>
								<Button 
									variant="secondary" 
									onClick={() => clearCache('object')}
									disabled={loading}
								>
									🗄️ Clear Object Cache
								</Button>
							</div>
						</div>
						
						<div className="wppo-control-group">
							<h4>Advanced Actions</h4>
							<div className="wppo-button-group">
								<Button variant="secondary" disabled={loading}>
									🔄 Preload Cache
								</Button>
								<Button variant="secondary" disabled={loading}>
									📊 Generate Report
								</Button>
							</div>
						</div>
					</div>
				</Card>

				{/* Cache Settings */}
				<Card title="⚙️ Cache Configuration">
					<div className="wppo-settings-section">
						<div className="wppo-settings-section-body">
							<div className="wppo-setting">
								<Switch 
									checked={settings.page_cache_enabled}
									onChange={(checked) => handleSettingChange('page_cache_enabled', checked)}
									label="Page Caching"
									id="page-cache-setting"
								/>
								<div className="wppo-setting-content">
									<h4>Page Caching</h4>
									<p>Cache full HTML pages for faster loading times</p>
								</div>
							</div>
							
							<div className="wppo-setting">
								<Switch 
									checked={settings.object_cache_enabled}
									onChange={(checked) => handleSettingChange('object_cache_enabled', checked)}
									label="Object Caching"
									id="object-cache-setting"
								/>
								<div className="wppo-setting-content">
									<h4>Object Caching</h4>
									<p>Cache database queries and PHP objects</p>
								</div>
							</div>
							
							<div className="wppo-setting">
								<Switch 
									checked={settings.browser_cache_enabled}
									onChange={(checked) => handleSettingChange('browser_cache_enabled', checked)}
									label="Browser Caching"
									id="browser-cache-setting"
								/>
								<div className="wppo-setting-content">
									<h4>Browser Caching</h4>
									<p>Set cache headers for static resources</p>
								</div>
							</div>
							
							<div className="wppo-setting">
								<Switch 
									checked={settings.cache_preload_enabled}
									onChange={(checked) => handleSettingChange('cache_preload_enabled', checked)}
									label="Cache Preloading"
									id="cache-preload-setting"
									disabled={!settings.page_cache_enabled}
								/>
								<div className="wppo-setting-content">
									<h4>Cache Preloading</h4>
									<p>Automatically generate cache for important pages</p>
									{!settings.page_cache_enabled && (
										<p className="wppo-setting-note">Requires page caching to be enabled</p>
									)}
								</div>
							</div>
							
							<div className="wppo-setting">
								<Switch 
									checked={settings.cache_compression}
									onChange={(checked) => setSettings({...settings, cache_compression: checked})}
								/>
								<div className="wppo-setting-content">
									<h4>GZIP Compression</h4>
									<p>Compress cached files to save bandwidth</p>
								</div>
							</div>
							
							<div className="wppo-setting">
								<Switch 
									checked={settings.cache_mobile_separate}
									onChange={(checked) => setSettings({...settings, cache_mobile_separate: checked})}
								/>
								<div className="wppo-setting-content">
									<h4>Separate Mobile Cache</h4>
									<p>Create separate cache files for mobile devices</p>
								</div>
							</div>
						</div>
					</div>
					
					<div className="wppo-save-actions">
						<Button 
							variant="primary" 
							onClick={saveSettings}
							disabled={loading}
						>
							{loading ? <Spinner size="small" /> : '💾'} Save Settings
						</Button>
						<Button variant="secondary">
							🔄 Reset to Defaults
						</Button>
					</div>
				</Card>
			</div>

			{/* Cache Performance Chart */}
			<Card title="📈 Cache Performance Trends" className="wppo-col-span-full">
				<div className="wppo-performance-chart">
					<div className="wppo-chart-placeholder">
						<div className="wppo-chart-bars">
							{[85, 92, 88, 94, 91, 96, 93].map((value, index) => (
								<div key={index} className="wppo-chart-bar">
									<div 
										className="wppo-chart-bar-fill" 
										style={{height: `${value}%`}}
									></div>
									<span className="wppo-chart-label">Day {index + 1}</span>
								</div>
							))}
						</div>
						<div className="wppo-chart-legend">
							<div className="wppo-legend-item">
								<span className="wppo-legend-color wppo-legend-color--primary"></span>
								Cache Hit Rate (%)
							</div>
						</div>
					</div>
				</div>
			</Card>
		</div>
	);
};
