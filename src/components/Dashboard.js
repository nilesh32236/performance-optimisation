import React, { useState, useEffect, useRef, useCallback } from 'react';
import { apiCall } from '../lib/apiRequest';

const translations = qtpoSettings.translations;

const Dashboard = ({ activities }) => {
	// Manage the state
	const [state, setState] = useState({
		totalCacheSize: qtpoSettings.cache_size,
		total_js: qtpoSettings.total_js_css.js,
		total_css: qtpoSettings.total_js_css.css,
		imageInfo: qtpoSettings.image_info || [],
		loading: {
			clear_cache: false,
			optimize_images: false,
			remove_images: false
		}
	});

	// Memoizing the image information to reduce unnecessary re-renders
	const { imageInfo } = state;
	const { completed = {}, pending = {}, failed = {} } = imageInfo;

	// Handle loading state changes
	const handleLoading = useCallback((key, isLoading) => {
		setState((prevState) => ({
			...prevState,
			loading: { ...prevState.loading, [key]: isLoading }
		}));
	}, []);

	// Update cache values in state
	const updateCache = useCallback(() => {
		setState((prevState) => ({
			...prevState,
			totalCacheSize: 0,
			total_js: 0,
			total_css: 0
		}));
	}, []);

	// Clear Cache Handler
	const onClickHandle = useCallback((e) => {
		e.preventDefault();
		handleLoading('clear_cache', true);
		apiCall('clear_cache', 'POST', { action: 'clear_cache' })
			.then((data) => {
				console.log(translations.clearCacheSuccess, data);
				updateCache();
			})
			.catch((error) => console.error(translations.errorClearCache, error))
			.finally(() => handleLoading('clear_cache', false));
	}, [handleLoading, updateCache]);

	// Convert Pending Images
	const convertPendingImages = useCallback(() => {
		handleLoading('optimize_images', true);

		const { webp = [], avif = [] } = pending || {};

		if (webp.length === 0 && avif.length === 0) {
			alert(translations.noPendingImage);
			handleLoading('optimize_images', false);
			return;
		}

		apiCall('optimise_image', 'POST', { webp, avif })
			.then(() => {
				console.log('Images optimized successfully');
				updateCache();
			})
			.catch((error) => console.error('Error optimizing images: ', error))
			.finally(() => handleLoading('optimize_images', false));
	}, [handleLoading, pending, updateCache]);

	// Remove Optimized Images
	const removeOptimizedImages = useCallback(() => {
		handleLoading('remove_images', true);

		const { webp = [], avif = [] } = completed || {};

		if (webp.length === 0 && avif.length === 0) {
			alert('No optimized images to remove!');
			handleLoading('remove_images', false);
			return;
		}

		apiCall('delete_optimised_image', 'POST', {})
			.then((data) => {
				if (data.success) {
					alert('Optimized images removed successfully!');
					console.log('Removed images:', data.deleted);
				} else {
					alert('Some images could not be removed.');
					console.error('Failed to remove:', data.failed);
				}
			})
			.catch((error) => {
				console.error('Error removing optimized images:', error);
				alert('An error occurred while removing optimized images.');
			})
			.finally(() => handleLoading('remove_images', false));
	}, [handleLoading, completed]);

	// When qtpoSettings changes, update the state (for example, for the cache size)
	useEffect(() => {
		setState((prevState) => ({
			...prevState,
			totalCacheSize: qtpoSettings.cache_size,
			total_js: qtpoSettings.total_js_css.js,
			total_css: qtpoSettings.total_js_css.css,
			imageInfo: qtpoSettings.image_info || prevState.imageInfo
		}));
	}, [qtpoSettings]);

	return (
		<div className="settings-form">
			<h2>Dashboard</h2>
			<div className="dashboard-overview">
				{/* Cache Section */}
				<div className="dashboard-card">
					<h3>Cache Status</h3>
					<p>Current Cache Size: {state.totalCacheSize}</p>
					<button
						className="clear-cache-btn"
						onClick={onClickHandle}
						disabled={state.loading.clear_cache}
					>
						{state.loading.clear_cache ? 'Clearing...' : 'Clear Cache Now'}
					</button>
				</div>

				{/* JavaScript & CSS Optimization Section */}
				<div className="dashboard-card">
					<h3>JavaScript & CSS Optimization</h3>
					<p>JavaScript Files Minified: {state.total_js}</p>
					<p>CSS Files Minified: {state.total_css}</p>
				</div>

				{/* Image Optimization Section */}
				<div className="dashboard-card image-overview">
					<h3>Image Optimization</h3>
					<div className="status-group">
						<div className="status-item">
							<h4>WebP</h4>
							<p>Completed: {completed?.webp?.length || 0}</p>
							<p>Pending: {pending?.webp?.length || 0}</p>
							<p>Failed: {failed?.webp?.length || 0}</p>
						</div>
						<div className="status-item">
							<h4>AVIF</h4>
							<p>Completed: {completed?.avif?.length || 0}</p>
							<p>Pending: {pending?.avif?.length || 0}</p>
							<p>Failed: {Object.keys(failed?.avif || {}).length}</p>
						</div>
					</div>
					<div className="action-buttons">
						<button
							className="optimize-images-btn"
							onClick={convertPendingImages}
							disabled={state.loading.optimize_images}
						>
							{state.loading.optimize_images ? 'Optimizing...' : 'Optimize Now'}
						</button>
						<button
							className="remove-optimized-btn"
							onClick={removeOptimizedImages}
							disabled={state.loading.remove_images}
						>
							{state.loading.remove_images ? 'Removing...' : 'Remove Optimized'}
						</button>
					</div>
				</div>
			</div>

			{/* Recent Activities */}
			<div className="recent-activities">
				<h3>Recent Activities</h3>
				<ul>
					{activities?.length > 0 ? (
						activities.map((activity, index) => (
							<li key={index}>
								<div dangerouslySetInnerHTML={{ __html: activity.activity }} />
							</li>
						))
					) : (
						<li>Loading recent activities...</li>
					)}
				</ul>
			</div>

			{/* Plugin Information */}
			<div className="plugin-info">
				<h3>Plugin Information</h3>
				<p><strong>Version:</strong> 1.0.0</p>
				<p><strong>Last Updated:</strong> September 21, 2024</p>
				<p>Get the best performance for your website by optimizing images, caching, and assets like JavaScript and CSS files. Stay updated with new features and improvements in each release!</p>
			</div>
		</div>
	);
};

export default Dashboard;
