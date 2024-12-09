import React, { useState, useEffect, useRef } from 'react';

const Dashboard = ({ activities }) => {
	console.log(qtpoSettings);

	const [totalCacheSize, setTotalCacheSize] = useState(qtpoSettings.cache_size);
	const [total_js, setTotal_js] = useState(qtpoSettings.total_js_css.js);
	const [total_css, setTotal_css] = useState(qtpoSettings.total_js_css.css);
	const [imageInfo, setImageInfo] = useState(qtpoSettings.image_info || []);
	const [loading, setLoading] = useState({
		clear_cache: false,
		optimize_images: false,
		remove_images: false,
	});

	const handleLoading = (key, isLoading) => {
		setLoading((prevState) => ({ ...prevState, [key]: isLoading }));
	};

	const onClickHandle = (e) => {
		e.preventDefault();
		handleLoading('clear_cache', true);
		fetch(qtpoSettings.apiUrl + 'clear_cache', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': qtpoSettings.nonce
			},
			body: JSON.stringify({ action: 'clear_cache' })
		})
			.then(response => response.json())
			.then((data) => {
				console.log('Cache cleared successfully: ', data)
				setTotalCacheSize(0);
				setTotal_js(0);
				setTotal_css(0);
			})
			.catch(error => console.error('Error clearing cache: ', error))
			.finally(() => handleLoading('clear_cache', false));
	};

	const convertPendingImages = () => {
		handleLoading('optimize_images', true);

		const pendingWebP = imageInfo?.pending?.webp || [];
		const pendingAVIF = imageInfo?.pending?.avif || [];

		if (pendingWebP.length === 0 && pendingAVIF.length === 0) {
			alert('No pending images to convert!');
			handleLoading('optimize_images', false);
			return;
		}

		fetch(qtpoSettings.apiUrl + 'optimise_image', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': qtpoSettings.nonce
			},
			body: JSON.stringify({ webp: pendingWebP, avif: pendingAVIF })
		})
			.then(response => response.json())
			.then((data) => {
				console.log('Cache cleared successfully: ', data)
				setTotalCacheSize(0);
				setTotal_js(0);
				setTotal_css(0);
			})
			.catch(error => console.error('Error clearing cache: ', error))
			.finally(() => handleLoading('optimize_images', false));
	};

	const removeOptimizedImages = () => {
		handleLoading('remove_images', true);

		const completedWebP = imageInfo?.completed?.webp || [];
		const completedAVIF = imageInfo?.completed?.avif || [];

		if (completedWebP.length === 0 && completedAVIF.length === 0) {
			alert('No optimized images to remove!');
			handleLoading('remove_images', false);
			return;
		}

		// Call the API to remove optimized images
		fetch(qtpoSettings.apiUrl + 'delete_optimised_image', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': qtpoSettings.nonce
			}
		})
			.then(response => {
				if (!response.ok) {
					throw new Error('Failed to remove optimized images');
				}
				return response.json();
			})
			.then(data => {
				if (data.success) {
					alert('Optimized images removed successfully!');
					console.log('Removed images:', data.deleted);
					// Optionally reset the completed image lists
					imageInfo.completed.webp = [];
					imageInfo.completed.avif = [];
				} else {
					alert('Some images could not be removed. Check logs for details.');
					console.error('Failed to remove:', data.failed);
				}
			})
			.catch(error => {
				console.error('Error removing optimized images:', error);
				alert('An error occurred while removing optimized images.');
			})
			.finally(() => handleLoading('remove_images', false));
	};

	qtpoSettings.cache_size = totalCacheSize;
	qtpoSettings.total_js_css.js = total_js;
	qtpoSettings.total_js_css.css = total_css;

	return (
		<div className="settings-form">
			<h2>Dashboard</h2>
			<div className="dashboard-overview">
				<div className="dashboard-card">
					<h3>Cache Status</h3>
					<p>Current Cache Size: {totalCacheSize}</p>
					<button
						className="clear-cache-btn"
						onClick={onClickHandle}
						disabled={loading.clear_cache}
					>
						{loading.clear_cache ? 'Clearing...' : 'Clear Cache Now'}
					</button>
				</div>

				<div className="dashboard-card">
					<h3>JavaScript & CSS Optimization</h3>
					<p>JavaScript Files Minified: {total_js}</p>
					<p>CSS Files Minified: {total_css}</p>
					{/* <button className="optimize-assets-btn">Minify Assets</button> */}
				</div>

				<div className="dashboard-card image-overview">
					<h3>Image Optimization</h3>
					<div className="status-group">
						<div className="status-item">
							<h4>WebP</h4>
							<p>Completed: {imageInfo?.completed?.webp?.length || 0}</p>
							<p>Pending: {imageInfo?.pending?.webp?.length || 0}</p>
							<p>Failed: {imageInfo?.failed?.webp?.length || 0}</p>
						</div>
						<div className="status-item">
							<h4>AVIF</h4>
							<p>Completed: {imageInfo?.completed?.avif?.length || 0}</p>
							<p>Pending: {imageInfo?.pending?.avif?.length || 0}</p>
							<p>Failed: {Object.keys(imageInfo?.failed?.avif || {}).length}</p>
						</div>
					</div>
					<div className="action-buttons">
						<button
							className="optimize-images-btn"
							onClick={convertPendingImages}
							disabled={loading.optimize_images}
						>
							{loading.optimize_images ? 'Optimizing...' : 'Optimize Now'}
						</button>
						<button
							className="remove-optimized-btn"
							onClick={removeOptimizedImages}
							disabled={loading.remove_images}
						>
							{loading.remove_images ? 'Removing...' : 'Remove Optimized'}
						</button>
					</div>
				</div>
			</div>

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
