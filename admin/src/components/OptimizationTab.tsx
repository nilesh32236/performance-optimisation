import React, { useState, useEffect } from 'react';
import { Button, Card, Switch, Slider, Select, Notice, Progress } from '../components/UI';
import { secureApiFetch, validateNumber } from '../utils/security';

interface OptimizationSettings {
	minify_css: boolean;
	minify_js: boolean;
	minify_html: boolean;
	combine_css: boolean;
	combine_js: boolean;
	defer_js: boolean;
	critical_css: boolean;
	compression_level: number;
	remove_unused_css: boolean;
	optimize_fonts: boolean;
}

interface OptimizationStats {
	css_files: number;
	js_files: number;
	html_pages: number;
	size_saved: string;
	load_time_improvement: string;
	optimization_score: number;
}

export const OptimizationTab: React.FC = () => {
	const [settings, setSettings] = useState<OptimizationSettings>({
		minify_css: true,
		minify_js: true,
		minify_html: false,
		combine_css: true,
		combine_js: false,
		defer_js: true,
		critical_css: false,
		compression_level: 6,
		remove_unused_css: false,
		optimize_fonts: true
	});
	
	const [stats, setStats] = useState<OptimizationStats>({
		css_files: 0,
		js_files: 0,
		html_pages: 0,
		size_saved: '0 KB',
		load_time_improvement: '0%',
		optimization_score: 0
	});
	
	const [saving, setSaving] = useState(false);
	const [optimizing, setOptimizing] = useState(false);
	const [notification, setNotification] = useState<{type: string, message: string} | null>(null);

	const fetchStats = async () => {
		try {
			const data = await secureApiFetch('/wp-json/performance-optimisation/v1/optimization/stats');
			setStats(data);
		} catch (error) {
			console.error('Failed to fetch stats:', error);
			setNotification({
				type: 'error',
				message: 'Failed to load optimization statistics'
			});
		}
	};

	const validateOptimizationSettings = (newSettings: OptimizationSettings) => {
		const warnings: string[] = [];
		
		try {
			validateNumber(newSettings.compression_level, 1, 9);
		} catch (error) {
			warnings.push('Invalid compression level');
		}
		
		if (newSettings.combine_js && newSettings.defer_js) {
			warnings.push('Combining and deferring JavaScript may cause conflicts. Test thoroughly.');
		}
		
		if (newSettings.critical_css && !newSettings.minify_css) {
			warnings.push('Critical CSS works best with CSS minification enabled');
		}
		
		if (newSettings.remove_unused_css) {
			warnings.push('Unused CSS removal is experimental and may break styling. Test on staging first.');
		}
		
		return warnings;
	};

	const handleSettingChange = (key: keyof OptimizationSettings, value: any) => {
		const newSettings = { ...settings, [key]: value };
		const warnings = validateOptimizationSettings(newSettings);
		
		if (warnings.length > 0) {
			setNotification({
				type: 'warning',
				message: warnings.join(' ')
			});
		}
		
		setSettings(newSettings);
	};

	const saveSettings = async () => {
		setSaving(true);
		try {
			await secureApiFetch('/wp-json/performance-optimisation/v1/optimization/settings', {
				method: 'POST',
				data: settings
			});
			
			setNotification({
				type: 'success',
				message: 'Optimization settings saved successfully!'
			});
		} catch (error) {
			setNotification({
				type: 'error',
				message: 'Failed to save settings. Please try again.'
			});
		}
		setSaving(false);
		setTimeout(() => setNotification(null), 3000);
	};

	const optimizeNow = async () => {
		setOptimizing(true);
		try {
			await secureApiFetch('/wp-json/performance-optimisation/v1/optimization/run', {
				method: 'POST',
				data: { settings }
			});
			
			await fetchStats();
			
			setNotification({
				type: 'success',
				message: 'Files optimized successfully!'
			});
		} catch (error) {
			setNotification({
				type: 'error',
				message: 'Optimization failed. Please try again.'
			});
		}
		setOptimizing(false);
		setTimeout(() => setNotification(null), 3000);
	};

	useEffect(() => {
		fetchStats();
	}, []);

	const getCompressionLabel = (level: number) => {
		if (level < 4) return 'Fast';
		if (level < 7) return 'Balanced';
		return 'Maximum';
	};

	const getScoreColor = (score: number) => {
		if (score >= 90) return 'success';
		if (score >= 70) return 'warning';
		return 'error';
	};

	return (
		<div className="wppo-optimization-tab">
			{notification && (
				<Notice type={notification.type as any}>
					{notification.message}
				</Notice>
			)}

			{/* Optimization Overview */}
			<div className="wppo-stats-grid">
				<div className={`wppo-stat wppo-stat--${getScoreColor(stats.optimization_score)}`}>
					<div className="wppo-stat-icon">🎯</div>
					<span className="wppo-stat-value">{stats.optimization_score}</span>
					<div className="wppo-stat-label">Optimization Score</div>
					<Progress value={stats.optimization_score} color={getScoreColor(stats.optimization_score)} />
				</div>
				
				<div className="wppo-stat wppo-stat--success">
					<div className="wppo-stat-icon">📦</div>
					<span className="wppo-stat-value">{stats.size_saved}</span>
					<div className="wppo-stat-label">Size Reduced</div>
					<div className="wppo-stat-meta">{stats.load_time_improvement} faster loading</div>
				</div>
				
				<div className="wppo-stat">
					<div className="wppo-stat-icon">🎨</div>
					<span className="wppo-stat-value">{stats.css_files}</span>
					<div className="wppo-stat-label">CSS Files Optimized</div>
				</div>
				
				<div className="wppo-stat">
					<div className="wppo-stat-icon">⚡</div>
					<span className="wppo-stat-value">{stats.js_files}</span>
					<div className="wppo-stat-label">JS Files Optimized</div>
				</div>
			</div>

			<div className="wppo-grid wppo-grid--3-col">
				{/* CSS Optimization */}
				<Card title="🎨 CSS Optimization" className="wppo-card--success">
					<div className="wppo-settings-section">
						<div className="wppo-settings-section-body">
							<div className="wppo-setting">
								<Switch 
									checked={settings.minify_css}
									onChange={(checked) => setSettings({...settings, minify_css: checked})}
								/>
								<div className="wppo-setting-content">
									<h4>Minify CSS</h4>
									<p>Remove whitespace and comments</p>
								</div>
							</div>
							
							<div className="wppo-setting">
								<Switch 
									checked={settings.combine_css}
									onChange={(checked) => setSettings({...settings, combine_css: checked})}
								/>
								<div className="wppo-setting-content">
									<h4>Combine CSS Files</h4>
									<p>Merge multiple CSS files into one</p>
								</div>
							</div>
							
							<div className="wppo-setting">
								<Switch 
									checked={settings.critical_css}
									onChange={(checked) => setSettings({...settings, critical_css: checked})}
								/>
								<div className="wppo-setting-content">
									<h4>Critical CSS</h4>
									<p>Inline above-the-fold CSS</p>
								</div>
							</div>
							
							<div className="wppo-setting">
								<Switch 
									checked={settings.remove_unused_css}
									onChange={(checked) => setSettings({...settings, remove_unused_css: checked})}
								/>
								<div className="wppo-setting-content">
									<h4>Remove Unused CSS</h4>
									<p>Eliminate unused CSS rules</p>
								</div>
							</div>
						</div>
					</div>
				</Card>

				{/* JavaScript Optimization */}
				<Card title="⚡ JavaScript Optimization" className="wppo-card--warning">
					<div className="wppo-settings-section">
						<div className="wppo-settings-section-body">
							<div className="wppo-setting">
								<Switch 
									checked={settings.minify_js}
									onChange={(checked) => setSettings({...settings, minify_js: checked})}
								/>
								<div className="wppo-setting-content">
									<h4>Minify JavaScript</h4>
									<p>Remove whitespace and optimize code</p>
								</div>
							</div>
							
							<div className="wppo-setting">
								<Switch 
									checked={settings.combine_js}
									onChange={(checked) => setSettings({...settings, combine_js: checked})}
								/>
								<div className="wppo-setting-content">
									<h4>Combine JS Files</h4>
									<p>Merge JavaScript files (use carefully)</p>
								</div>
							</div>
							
							<div className="wppo-setting">
								<Switch 
									checked={settings.defer_js}
									onChange={(checked) => setSettings({...settings, defer_js: checked})}
								/>
								<div className="wppo-setting-content">
									<h4>Defer JavaScript</h4>
									<p>Load JS after page content</p>
								</div>
							</div>
						</div>
					</div>
				</Card>

				{/* HTML & Advanced */}
				<Card title="🔧 Advanced Optimization">
					<div className="wppo-settings-section">
						<div className="wppo-settings-section-body">
							<div className="wppo-setting">
								<Switch 
									checked={settings.minify_html}
									onChange={(checked) => setSettings({...settings, minify_html: checked})}
								/>
								<div className="wppo-setting-content">
									<h4>Minify HTML</h4>
									<p>Remove whitespace from HTML output</p>
								</div>
							</div>
							
							<div className="wppo-setting">
								<Switch 
									checked={settings.optimize_fonts}
									onChange={(checked) => setSettings({...settings, optimize_fonts: checked})}
								/>
								<div className="wppo-setting-content">
									<h4>Optimize Fonts</h4>
									<p>Preload and optimize web fonts</p>
								</div>
							</div>
							
							<div className="wppo-form-group">
								<label>GZIP Compression Level: {settings.compression_level} ({getCompressionLabel(settings.compression_level)})</label>
								<Slider 
									value={settings.compression_level}
									onChange={(value) => setSettings({...settings, compression_level: value})}
									min={1}
									max={9}
									step={1}
								/>
							</div>
						</div>
					</div>
				</Card>
			</div>

			{/* Optimization Actions */}
			<Card title="🚀 Optimization Actions" className="wppo-card--highlight">
				<div className="wppo-optimization-actions">
					<div className="wppo-action-group">
						<h4>Quick Actions</h4>
						<div className="wppo-button-group">
							<Button 
								variant="primary" 
								onClick={optimizeNow}
								disabled={optimizing}
							>
								{optimizing ? <Spinner size="small" /> : '🚀'} Optimize All Files
							</Button>
							<Button variant="secondary">
								🧹 Clear Optimized Files
							</Button>
							<Button variant="secondary">
								📊 Generate Report
							</Button>
						</div>
					</div>
					
					{optimizing && (
						<div className="wppo-optimization-progress">
							<h4>Optimizing Files...</h4>
							<Progress value={75} />
							<p>Processing CSS and JavaScript files...</p>
						</div>
					)}
				</div>
			</Card>

			{/* Save Settings */}
			<div className="wppo-save-bar">
				<Button 
					variant="primary" 
					onClick={saveSettings}
					disabled={saving}
				>
					{saving ? <Spinner size="small" /> : '💾'} Save Settings
				</Button>
				<Button variant="secondary">
					🔄 Reset to Defaults
				</Button>
				<Button variant="secondary">
					📋 Export Settings
				</Button>
			</div>
		</div>
	);
};
