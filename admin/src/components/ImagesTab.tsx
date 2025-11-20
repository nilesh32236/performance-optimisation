import React, { useState, useEffect } from 'react';
import { Button, Card, Switch, Slider, Progress, Notice } from '../components/UI';
import { secureApiFetch, validateNumber } from '../utils/security';

interface ImageStats {
	total_images: number;
	optimized_images: number;
	size_saved: string;
	webp_images: number;
	avif_images: number;
}

export const ImagesTab: React.FC = () => {
	const [settings, setSettings] = useState({
		auto_optimize: true,
		webp_conversion: true,
		avif_conversion: false,
		lazy_loading: true,
		quality: 85,
		max_width: 1920,
		max_height: 1080
	});
	const [stats, setStats] = useState<ImageStats>({
		total_images: 0,
		optimized_images: 0,
		size_saved: '0 KB',
		webp_images: 0,
		avif_images: 0
	});
	const [optimizing, setOptimizing] = useState(false);
	const [progress, setProgress] = useState(0);
	const [notification, setNotification] = useState<{type: string, message: string} | null>(null);

	const fetchStats = async () => {
		try {
			const data = await secureApiFetch('/wp-json/performance-optimisation/v1/images/stats');
			setStats(data);
		} catch (error) {
			console.error('Failed to fetch image stats:', error);
			setNotification({
				type: 'error',
				message: 'Failed to load image statistics'
			});
		}
	};

	const validateImageSettings = (newSettings: typeof settings) => {
		const errors: string[] = [];
		
		try {
			validateNumber(newSettings.quality, 50, 100);
			validateNumber(newSettings.max_width, 400, 4000);
			validateNumber(newSettings.max_height, 300, 3000);
		} catch (error) {
			errors.push(error instanceof Error ? error.message : 'Invalid settings');
		}
		
		if (newSettings.avif_conversion && !newSettings.webp_conversion) {
			errors.push('AVIF conversion requires WebP conversion to be enabled as fallback');
		}
		
		return errors;
	};

	const handleSettingChange = (key: keyof typeof settings, value: any) => {
		const newSettings = { ...settings, [key]: value };
		const errors = validateImageSettings(newSettings);
		
		if (errors.length > 0) {
			setNotification({
				type: 'warning',
				message: errors.join('. ')
			});
			return;
		}
		
		setSettings(newSettings);
	};

	const bulkOptimize = async () => {
		if (stats.total_images === stats.optimized_images) {
			setNotification({
				type: 'info',
				message: 'All images are already optimized'
			});
			return;
		}

		setOptimizing(true);
		setProgress(0);
		
		try {
			const response = await secureApiFetch('/wp-json/performance-optimisation/v1/images/bulk-optimize', {
				method: 'POST',
				data: settings
			});
			
			// Simulate progress updates with real API polling
			const pollProgress = async () => {
				try {
					const progressData = await secureApiFetch('/wp-json/performance-optimisation/v1/images/progress');
					setProgress(progressData.percentage || 0);
					
					if (progressData.percentage < 100) {
						setTimeout(pollProgress, 1000);
					} else {
						await fetchStats();
						setNotification({
							type: 'success',
							message: `Successfully optimized ${progressData.processed || 0} images`
						});
					}
				} catch (error) {
					console.error('Progress polling failed:', error);
				}
			};
			
			pollProgress();
		} catch (error) {
			console.error('Bulk optimization failed:', error);
			setNotification({
				type: 'error',
				message: 'Image optimization failed. Please try again.'
			});
		} finally {
			setOptimizing(false);
		}
	};

	useEffect(() => {
		fetchStats();
	}, []);

	const optimizationPercentage = stats.total_images > 0 
		? Math.round((stats.optimized_images / stats.total_images) * 100) 
		: 0;

	return (
		<div className="wppo-images-tab">
			<div className="wppo-grid">
				{/* Image Statistics */}
				<Card title="Image Statistics" className="wppo-col-span-2">
					<div className="wppo-stats-grid">
						<div className="wppo-stat">
							<h4>Total Images</h4>
							<p className="wppo-stat-value">{stats.total_images}</p>
						</div>
						<div className="wppo-stat">
							<h4>Optimized</h4>
							<p className="wppo-stat-value">{stats.optimized_images}</p>
							<p className="wppo-stat-meta">{optimizationPercentage}% complete</p>
						</div>
						<div className="wppo-stat">
							<h4>Size Saved</h4>
							<p className="wppo-stat-value">{stats.size_saved}</p>
						</div>
						<div className="wppo-stat">
							<h4>Modern Formats</h4>
							<p className="wppo-stat-value">{stats.webp_images + stats.avif_images}</p>
							<p className="wppo-stat-meta">WebP: {stats.webp_images}, AVIF: {stats.avif_images}</p>
						</div>
					</div>
					
					<div className="wppo-optimization-progress">
						<Progress value={optimizationPercentage} />
						<p>Optimization Progress: {optimizationPercentage}%</p>
					</div>
				</Card>

				{/* Bulk Optimization */}
				<Card title="Bulk Optimization">
					{optimizing ? (
						<div className="wppo-optimization-status">
							<Progress value={progress} />
							<p>Optimizing images... {Math.round(progress)}%</p>
						</div>
					) : (
						<div className="wppo-bulk-controls">
							<Button 
								variant="primary" 
								onClick={bulkOptimize}
								disabled={stats.total_images === stats.optimized_images}
							>
								Optimize All Images
							</Button>
							<p>{stats.total_images - stats.optimized_images} images remaining</p>
						</div>
					)}
				</Card>

				{/* Format Settings */}
				<Card title="Image Formats">
					<div className="wppo-settings-list">
						<div className="wppo-setting">
							<Switch 
								checked={settings.webp_conversion}
								onChange={(checked) => setSettings({...settings, webp_conversion: checked})}
							/>
							<div>
								<h4>WebP Conversion</h4>
								<p>Convert images to WebP format (widely supported)</p>
							</div>
						</div>
						<div className="wppo-setting">
							<Switch 
								checked={settings.avif_conversion}
								onChange={(checked) => setSettings({...settings, avif_conversion: checked})}
							/>
							<div>
								<h4>AVIF Conversion</h4>
								<p>Convert to AVIF format (better compression, newer browsers)</p>
							</div>
						</div>
						<div className="wppo-setting">
							<Switch 
								checked={settings.lazy_loading}
								onChange={(checked) => setSettings({...settings, lazy_loading: checked})}
							/>
							<div>
								<h4>Lazy Loading</h4>
								<p>Load images only when they enter viewport</p>
							</div>
						</div>
					</div>
				</Card>

				{/* Quality & Size Settings */}
				<Card title="Quality & Size Settings">
					<div className="wppo-settings-list">
						<div className="wppo-setting-full">
							<h4>Image Quality: {settings.quality}%</h4>
							<Slider 
								value={settings.quality}
								onChange={(value) => setSettings({...settings, quality: value})}
								min={50}
								max={100}
								step={5}
							/>
							<p>{settings.quality < 70 ? 'High compression' : settings.quality < 85 ? 'Balanced' : 'High quality'}</p>
						</div>
						<div className="wppo-setting-full">
							<h4>Max Width: {settings.max_width}px</h4>
							<Slider 
								value={settings.max_width}
								onChange={(value) => setSettings({...settings, max_width: value})}
								min={800}
								max={3840}
								step={80}
							/>
						</div>
						<div className="wppo-setting-full">
							<h4>Max Height: {settings.max_height}px</h4>
							<Slider 
								value={settings.max_height}
								onChange={(value) => setSettings({...settings, max_height: value})}
								min={600}
								max={2160}
								step={60}
							/>
						</div>
					</div>
				</Card>
			</div>
		</div>
	);
};
