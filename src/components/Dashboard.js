import React from 'react';

const Dashboard = () => {
	return (
		<div className="settings-form">
			<h2>Performance Optimization Dashboard</h2>
			<div className="dashboard-overview">
				<div className="dashboard-card">
					<h3>Cache Status</h3>
					<p>Current Cache Size: 45 MB</p>
					<p>Last Cache Cleared: 2 days ago</p>
					<button className="clear-cache-btn">Clear Cache Now</button>
				</div>

				<div className="dashboard-card">
					<h3>Image Optimization</h3>
					<p>Images Optimized: 320</p>
					<p>Images Converted to WebP: 150</p>
					<button className="optimize-images-btn">Optimize Now</button>
				</div>

				<div className="dashboard-card">
					<h3>JavaScript & CSS Optimization</h3>
					<p>JavaScript Files Minified: 18</p>
					<p>CSS Files Minified: 12</p>
					<button className="optimize-assets-btn">Minify Assets</button>
				</div>
			</div>

			<div className="recent-activities">
				<h3>Recent Activities</h3>
				<ul>
					<li>Cache cleared on September 22, 2024</li>
					<li>150 images converted to WebP format</li>
					<li>JavaScript files minified on September 20, 2024</li>
					<li>Lazy loading enabled for images</li>
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
