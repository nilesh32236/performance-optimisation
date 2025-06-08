// src/components/Dashboard/Dashboard.js

import React from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faTachometerAlt, faBroom, faImages as faImagesSolid, faTrashAlt } from '@fortawesome/free-solid-svg-icons'; // Using a different faImages for clarity

const Dashboard = ({
	settings, // General settings if needed
	imageInfo,
	cacheSize,
	minifiedAssets,
	translations,
	apiUrl,
	nonce,
	setIsLoading, // To control loading state from App.js
	setImageInfo, // To update imageInfo after optimization
	setCacheSize, // To update cacheSize after clearing
	// toast, // If using react-toastify
}) => {

	const handleClearAllCache = async () => {
		// eslint-disable-next-line no-alert
		if (!window.confirm(translations.confirmClearAll || 'Are you sure you want to clear ALL cache? This includes HTML pages and minified assets.')) {
			return;
		}
		setIsLoading(true);
		try {
			const response = await fetch(`${apiUrl}clear-cache`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body: JSON.stringify({ action: 'all' }),
			});
			const result = await response.json();
			if (result.success) {
				setCacheSize('0 B'); // Optimistic update
				// toast?.success(translations.cacheCleared || 'All cache cleared successfully!');
				console.log(translations.cacheCleared || 'All cache cleared successfully!');
			} else {
				// toast?.error(result.data?.message || translations.cacheClearError || 'Error clearing cache.');
				console.error(result.data?.message || translations.cacheClearError || 'Error clearing cache.');
			}
		} catch (error) {
			// toast?.error(translations.cacheClearError || 'Error clearing cache.');
			console.error(translations.cacheClearError || 'Error clearing cache:', error);
		} finally {
			setIsLoading(false);
		}
	};

	const handleOptimizeImages = async () => {
		const totalPending = (imageInfo?.pending?.webp?.length || 0) + (imageInfo?.pending?.avif?.length || 0);
		if (totalPending === 0) {
			// toast?.info(translations.noPendingImages || 'No images are currently pending optimization.');
			console.log(translations.noPendingImages || 'No images are currently pending optimization.');
			return;
		}
		setIsLoading(true);
		try {
			const response = await fetch(`${apiUrl}optimise-images`, {
				method: 'POST',
				headers: {
					'X-WP-Nonce': nonce,
					// No body needed if it processes all pending by default
				},
			});
			const result = await response.json();
			if (result.success && result.data.imageInfo) {
				setImageInfo(result.data.imageInfo); // Update state in App.js
				// toast?.success(translations.imagesOptimized || 'Image optimization batch process initiated.');
				console.log(translations.imagesOptimized || 'Image optimization batch process initiated.');
			} else {
				// toast?.error(result.data?.message || translations.errorOptimiseImg || 'Error initiating image optimization.');
				console.error(result.data?.message || translations.errorOptimiseImg || 'Error initiating image optimization.');
			}
		} catch (error) {
			// toast?.error(translations.errorOptimiseImg || 'Error initiating image optimization.');
			console.error(translations.errorOptimiseImg || 'Error initiating image optimization:', error);
		} finally {
			setIsLoading(false);
		}
	};

	const handleDeleteOptimizedImages = async () => {
		// eslint-disable-next-line no-alert
		if (!window.confirm(translations.confirmDeleteOptimized || 'Are you sure you want to delete all converted WebP/AVIF images? Original images will not be affected.')) {
			return;
		}
		setIsLoading(true);
		try {
			const response = await fetch(`${apiUrl}delete-optimised-images`, {
				method: 'POST',
				headers: {
					'X-WP-Nonce': nonce,
				},
			});
			const result = await response.json();
			if (result.success) {
				if (result.data.imageInfo) {
					setImageInfo(result.data.imageInfo); // Reset image info state
				}
				// toast?.success(translations.imagesDeleted || 'Converted images deleted successfully.');
				console.log(translations.imagesDeleted || 'Converted images deleted successfully.');
			} else {
				// toast?.error(result.data?.message || translations.errorRemovingImg || 'Error deleting converted images.');
				console.error(result.data?.message || translations.errorRemovingImg || 'Error deleting converted images.');
			}
		} catch (error) {
			// toast?.error(translations.errorRemovingImg || 'Error deleting converted images.');
			console.error(translations.errorRemovingImg || 'Error deleting converted images:', error);
		} finally {
			setIsLoading(false);
		}
	};

	const totalPendingImages = (imageInfo?.pending?.webp?.length || 0) + (imageInfo?.pending?.avif?.length || 0);
	const totalCompletedImages = (imageInfo?.completed?.webp?.length || 0) + (imageInfo?.completed?.avif?.length || 0);
	const totalFailedImages = (imageInfo?.failed?.webp?.length || 0) + (imageInfo?.failed?.avif?.length || 0);
	const totalSkippedImages = (imageInfo?.skipped?.webp?.length || 0) + (imageInfo?.skipped?.avif?.length || 0);


	return (
		<div className="wppo-dashboard-container">
			<h2 className="wppo-section-title">
				<FontAwesomeIcon icon={faTachometerAlt} style={{ marginRight: '10px' }} />
				{translations.dashboard || 'Dashboard'}
			</h2>
			<p className="wppo-section-description">
				{translations.dashboardDesc || 'Overview of your site\'s performance optimization status and quick actions.'}
			</p>

			<div className="wppo-dashboard-overview">
				<div className="wppo-dashboard-card">
					<h3>
						<FontAwesomeIcon icon={faBroom} style={{ marginRight: '8px' }} />
						{translations.cacheStatus || 'Cache Status'}
					</h3>
					<p>
						{translations.currentCacheSize || 'Current Cache Size:'}{' '}
						<strong>{cacheSize}</strong>
					</p>
					<div className="wppo-action-buttons">
						<button className="wppo-button" onClick={handleClearAllCache}>
							<FontAwesomeIcon icon={faBroom} style={{ marginRight: '5px' }}/>
							{translations.clearCacheNow || 'Clear All Cache'}
						</button>
					</div>
				</div>

				<div className="wppo-dashboard-card">
					<h3>{translations.minifiedFiles || 'Minified Assets'}</h3>
					<p>
						{translations.jsFilesMinified || 'JavaScript Files Minified:'}{' '}
						<strong>{minifiedAssets?.js || 0}</strong>
					</p>
					<p>
						{translations.cssFilesMinified || 'CSS Files Minified:'}{' '}
						<strong>{minifiedAssets?.css || 0}</strong>
					</p>
					{/* Placeholder for a button if you want to clear only minified assets cache */}
					{/* <div className="wppo-action-buttons">
						<button className="wppo-button wppo-button--secondary">Clear Minified Assets</button>
					</div> */}
				</div>

				<div className="wppo-dashboard-card wppo-dashboard-card--image-overview">
					<h3>
						<FontAwesomeIcon icon={faImagesSolid} style={{ marginRight: '8px' }} />
						{translations.imageConversionStatus || 'Image Conversion'}
					</h3>
					<div className="wppo-status-group">
						<div className="wppo-status-item">
							<h4>{translations.pending || 'Pending'}</h4>
							<p>WebP: {imageInfo?.pending?.webp?.length || 0}</p>
							<p>AVIF: {imageInfo?.pending?.avif?.length || 0}</p>
						</div>
						<div className="wppo-status-item">
							<h4>{translations.completed || 'Completed'}</h4>
							<p>WebP: {imageInfo?.completed?.webp?.length || 0}</p>
							<p>AVIF: {imageInfo?.completed?.avif?.length || 0}</p>
						</div>
						<div className="wppo-status-item">
							<h4>{translations.failed || 'Failed'}</h4>
							<p>WebP: {imageInfo?.failed?.webp?.length || 0}</p>
							<p>AVIF: {imageInfo?.failed?.avif?.length || 0}</p>
						</div>
						<div className="wppo-status-item">
							<h4>{translations.skipped || 'Skipped'}</h4>
							<p>WebP: {imageInfo?.skipped?.webp?.length || 0}</p>
							<p>AVIF: {imageInfo?.skipped?.avif?.length || 0}</p>
						</div>
					</div>
					<div className="wppo-action-buttons">
						<button
							className="wppo-button"
							onClick={handleOptimizeImages}
							disabled={totalPendingImages === 0}
						>
							<FontAwesomeIcon icon={faImagesSolid} style={{ marginRight: '5px' }}/>
							{translations.optimiseImagesNow || 'Optimize Pending Images'} ({totalPendingImages})
						</button>
						<button
							className="wppo-button wppo-button--danger" // Add a danger style for this
							onClick={handleDeleteOptimizedImages}
							disabled={totalCompletedImages === 0 && totalFailedImages === 0 && totalSkippedImages === 0 && totalPendingImages === 0}
						>
							<FontAwesomeIcon icon={faTrashAlt} style={{ marginRight: '5px' }}/>
							{translations.deleteOptimizedImages || 'Delete Converted Images'}
						</button>
					</div>
				</div>
			</div>
		</div>
	);
};

export default Dashboard;