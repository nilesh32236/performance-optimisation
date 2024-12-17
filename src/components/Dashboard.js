import React, { useState, useEffect, useCallback } from 'react';
import { apiCall } from '../lib/apiRequest';


const Dashboard = ({ activities }) => {
	const translations = qtpoSettings.translations;

	// Initialize state
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
	const { imageInfo, loading, totalCacheSize, total_js, total_css } = state;
	const { completed = {}, pending = {}, failed = {} } = imageInfo;

	// General function to update state
	const updateState = useCallback((updates) => {
		setState((prevState) => ({ ...prevState, ...updates }));
	}, []);

	// Handle loading state changes
	const handleLoading = useCallback((key, isLoading) => {
		updateState({
			loading: { ...state.loading, [key]: isLoading },
		});
	}, []);

	// Update cache values in state
	const updateCache = useCallback(() => {
		updateState({
			totalCacheSize: 0,
			total_js: 0,
			total_css: 0,
		});
	}, [updateState]);

	// Clear Cache Handler
	const onClearCache = useCallback(
		(e) => {
			e.preventDefault();
			handleLoading('clear_cache', true);
			apiCall('clear_cache', { action: 'clear_cache' })
				.then((data) => {
					console.log(translations.clearCacheSuccess, data);
					updateCache();
				})
				.catch((error) => console.error(translations.errorClearCache, error))
				.finally(() => handleLoading('clear_cache', false));
		},
		[handleLoading, updateCache, translations]
	);

	// Optimize Pending Images
	const optimizeImages = useCallback(() => {
		handleLoading('optimize_images', true);

		const { webp = [], avif = [] } = pending;
		if (!webp.length && !avif.length) {
			alert(translations.noPendingImage);
			handleLoading('optimize_images', false);
			return;
		}

		apiCall('optimise_image', { webp, avif })
			.then((response) => {
				console.log(translations.imgOptimiseSuccess);
				// console.log( response );
				qtpoSettings.imageInfo = response;
				console.log( response );
				
				// updateState((prevState) => ({
				// 	...prevState,
				// 	imageInfo: response,
				// }));
				// updateCache();
			})
			.catch((error) => console.error(translations.errorOptimiseImg, error))
			.finally(() => handleLoading('optimize_images', false));
	}, [handleLoading, pending, completed, failed]);

	// Remove Optimized Images
	const removeImages = useCallback(() => {
		handleLoading('remove_images', true);

		const { webp = [], avif = [] } = completed;
		if (!webp.length && !avif.length) {
			alert(translations.noImgRemove);
			handleLoading('remove_images', false);
			return;
		}

		apiCall('delete_optimised_image', {})
			.then((data) => {
				if (data.success) {
					// alert(translations.removedOptimiseImg);
					console.log(translations.removedImg, data.deleted);
					qtpoSettings.image_info.completed = {webp: [], avif: []};
				} else {
					// alert(translations.someImgNotRemoved);
					console.error(translations.failedToRemove, data.failed);
				}
			})
			.catch((error) => {
				console.error(translations.errorRemovingImg, error);
				alert(translations.errorEccurredRemovingImg);
			})
			.finally(() => handleLoading('remove_images', false));
	}, [handleLoading, completed]);

	// Sync state with qtpoSettings changes
	useEffect(() => {
		updateState({
			totalCacheSize: qtpoSettings.cache_size,
			total_js: qtpoSettings.total_js_css.js,
			total_css: qtpoSettings.total_js_css.css,
			imageInfo: qtpoSettings.image_info || state.imageInfo,
		});
	}, [qtpoSettings, updateState, state.imageInfo]);

	return (
		<div className="settings-form">
			<h2>{translations.dashboard}</h2>
			<div className="dashboard-overview">
				{/* Cache Section */}
				<div className="dashboard-card">
					<h3>{translations.cacheStatus}</h3>
					<p>{translations.currentCacheSize} {totalCacheSize}</p>
					<button
						className="clear-cache-btn"
						onClick={onClearCache}
						disabled={loading.clear_cache}
					>
						{loading.clear_cache ? translations.clearing : translations.clearCacheNow}
					</button>
				</div>

				{/* JavaScript & CSS Optimization Section */}
				<div className="dashboard-card">
					<h3>{translations.JSCSSOptimisation}</h3>
					<p>{translations.JSFilesMinified} {total_js}</p>
					<p>{translations.CSSFilesMinified} {total_css}</p>
				</div>

				{/* Image Optimization Section */}
				<div className="dashboard-card image-overview">
					<h3>{translations.imageOptimization}</h3>
					<div className="status-group">
						{['webp', 'avif'].map((format) => (
							<div key={format} className="status-item">
								<h4>{format.toUpperCase()}</h4>
								<p>Completed: {completed[format]?.length || 0}</p>
								<p>Pending: {pending[format]?.length || 0}</p>
								<p>Failed: {failed[format]?.length || 0}</p>
							</div>
						))}
					</div>
					<div className="action-buttons">
						<button
							className="optimize-images-btn"
							onClick={optimizeImages}
							disabled={loading.optimize_images}
						>
							{loading.optimize_images ? 'Optimizing...' : 'Optimize Now'}
						</button>
						<button
							className="remove-optimized-btn"
							onClick={removeImages}
							disabled={loading.remove_images}
						>
							{loading.remove_images ? 'Removing...' : 'Remove Optimized'}
						</button>
					</div>
				</div>
			</div>

			{/* Recent Activities */}
			<div className="recent-activities">
				<h3>Recent Activities</h3>
				<ul>
					{activities?.length ? (
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
