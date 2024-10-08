import React, { useState, useEffect, useRef } from 'react';

const Dashboard = ({activities}) => {
	const totalCacheSize = qtpoSettings.cache_size;
	const total_js       = qtpoSettings.total_js_css.js;
	const total_css      = qtpoSettings.total_js_css.css;

	const onClickHandle = (e) => {
		e.preventDefault();
		fetch(qtpoSettings.apiUrl + 'clear_cache', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': qtpoSettings.nonce
			},
			body: JSON.stringify({ action: 'clear_cache' })
		})
			.then(response => response.json())
			.then(data => console.log('Cache cleared successfully: ', data))
			.catch(error => console.error('Error clearing cache: ', error));
	};

	return (
		<div className="settings-form">
			<h2>Performance Optimization Dashboard</h2>
			<div className="dashboard-overview">
				<div className="dashboard-card">
					<h3>Cache Status</h3>
					<p>Current Cache Size: {totalCacheSize}</p>
					<p>Last Cache Cleared: 2 days ago</p>
					<button className="clear-cache-btn" onClick={onClickHandle}>Clear Cache Now</button>
				</div>

				<div className="dashboard-card">
					<h3>Image Optimization</h3>
					<p>Images Optimized: 320</p>
					<p>Images Converted to WebP: 150</p>
					<button className="optimize-images-btn">Optimize Now</button>
				</div>

				<div className="dashboard-card">
					<h3>JavaScript & CSS Optimization</h3>
					<p>JavaScript Files Minified: {total_js}</p>
					<p>CSS Files Minified: {total_css}</p>
					<button className="optimize-assets-btn">Minify Assets</button>
				</div>
			</div>

			<div className="recent-activities">
				<h3>Recent Activities</h3>
				<ul>
					{activities?.length > 0 ? (
						activities.map((activity, index) => (
							<li key={index}>{activity.activity}</li>
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
