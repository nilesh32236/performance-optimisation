import React, { useState, useEffect } from 'react';
import HelpTooltip from './HelpTooltip';
import HelpPanel from './HelpPanel';
import OnboardingTour from './OnboardingTour';

interface ContextualHelpProps {
	page: string;
	showOnboarding?: boolean;
	onOnboardingComplete?: () => void;
}

const ContextualHelp: React.FC<ContextualHelpProps> = ({
	page,
	showOnboarding = false,
	onOnboardingComplete
}) => {
	const [showTour, setShowTour] = useState(showOnboarding);

	// Help content for different pages
	const getHelpContent = (pageName: string) => {
		const helpContent: Record<string, any> = {
			dashboard: {
				sections: [
					{
						id: 'performance-metrics',
						title: 'Understanding Performance Metrics',
						content: `
							<p>The performance dashboard shows key metrics that indicate how well your site is performing:</p>
							<ul>
								<li><strong>Performance Score (0-100):</strong> Overall performance rating based on multiple factors</li>
								<li><strong>Page Load Time:</strong> Average time for pages to fully load</li>
								<li><strong>Cache Hit Ratio:</strong> Percentage of requests served from cache</li>
								<li><strong>Total Page Views:</strong> Number of pages served in the selected period</li>
							</ul>
						`,
						links: [
							{ text: 'Performance Best Practices', url: '#', external: false },
							{ text: 'Google PageSpeed Insights', url: 'https://pagespeed.web.dev/', external: true }
						]
					},
					{
						id: 'optimization-status',
						title: 'Optimization Features',
						content: `
							<p>Track which optimization features are active on your site:</p>
							<ul>
								<li><strong>Green checkmarks:</strong> Features that are active and working</li>
								<li><strong>Yellow warnings:</strong> Features that need attention</li>
								<li><strong>Red X marks:</strong> Disabled or problematic features</li>
							</ul>
							<p>Click on any feature to configure its settings.</p>
						`
					}
				],
				tour: [
					{
						id: 'welcome',
						target: '.wppo-analytics-dashboard__header',
						title: 'Welcome to Performance Optimisation!',
						content: 'This is your performance dashboard where you can monitor your site\'s speed and optimization status.',
						position: 'bottom'
					},
					{
						id: 'metrics',
						target: '.wppo-metrics-overview',
						title: 'Performance Metrics',
						content: 'These cards show your site\'s key performance indicators. Higher scores and lower load times are better.',
						position: 'bottom'
					},
					{
						id: 'charts',
						target: '.wppo-analytics-dashboard__charts-grid',
						title: 'Performance Trends',
						content: 'These charts show how your performance changes over time. Look for improvements after enabling optimizations.',
						position: 'top'
					}
				]
			},
			settings: {
				sections: [
					{
						id: 'caching',
						title: 'Caching Settings',
						content: `
							<p>Caching stores copies of your pages and files to serve them faster:</p>
							<ul>
								<li><strong>Page Caching:</strong> Stores complete HTML pages (recommended for all sites)</li>
								<li><strong>Object Caching:</strong> Caches database queries (requires Redis/Memcached)</li>
								<li><strong>Browser Caching:</strong> Tells browsers to cache static files</li>
							</ul>
							<p><strong>Tip:</strong> Start with page caching enabled and default settings.</p>
						`
					},
					{
						id: 'file-optimization',
						title: 'File Optimization',
						content: `
							<p>Optimize CSS, JavaScript, and HTML files for faster loading:</p>
							<ul>
								<li><strong>Minification:</strong> Removes unnecessary characters (safe for most sites)</li>
								<li><strong>Combination:</strong> Merges multiple files (may cause conflicts)</li>
								<li><strong>Deferring:</strong> Delays JavaScript loading (test thoroughly)</li>
							</ul>
							<p><strong>Warning:</strong> Test your site after enabling these features.</p>
						`
					}
				]
			},
			wizard: {
				sections: [
					{
						id: 'setup-process',
						title: 'Setup Wizard Process',
						content: `
							<p>The setup wizard helps configure optimal settings for your site:</p>
							<ol>
								<li><strong>Site Analysis:</strong> Detects your hosting environment and current performance</li>
								<li><strong>Optimization Level:</strong> Choose between Safe, Recommended, or Advanced settings</li>
								<li><strong>Feature Selection:</strong> Enable additional features like image optimization</li>
								<li><strong>Completion:</strong> Apply settings and start monitoring</li>
							</ol>
						`
					}
				],
				tour: [
					{
						id: 'wizard-start',
						target: '.wppo-wizard-container',
						title: 'Setup Wizard',
						content: 'This wizard will help you configure the best settings for your site. It only takes a few minutes!',
						position: 'bottom'
					}
				]
			}
		};

		return helpContent[pageName] || { sections: [], tour: [] };
	};

	const helpContent = getHelpContent(page);

	const handleTourComplete = () => {
		setShowTour(false);
		if (onOnboardingComplete) {
			onOnboardingComplete();
		}
		
		// Save tour completion status
		localStorage.setItem(`wppo_tour_completed_${page}`, 'true');
	};

	const handleTourSkip = () => {
		setShowTour(false);
		localStorage.setItem(`wppo_tour_skipped_${page}`, 'true');
	};

	// Check if tour was already completed
	useEffect(() => {
		const tourCompleted = localStorage.getItem(`wppo_tour_completed_${page}`);
		const tourSkipped = localStorage.getItem(`wppo_tour_skipped_${page}`);
		
		if (tourCompleted || tourSkipped) {
			setShowTour(false);
		}
	}, [page]);

	return (
		<div className="wppo-contextual-help">
			{/* Help Panel */}
			{helpContent.sections.length > 0 && (
				<HelpPanel
					sections={helpContent.sections}
					title={`${page.charAt(0).toUpperCase() + page.slice(1)} Help`}
					collapsible={true}
					defaultExpanded={false}
				/>
			)}

			{/* Onboarding Tour */}
			{helpContent.tour && helpContent.tour.length > 0 && (
				<OnboardingTour
					steps={helpContent.tour}
					isActive={showTour}
					onComplete={handleTourComplete}
					onSkip={handleTourSkip}
				/>
			)}

			{/* Restart Tour Button */}
			{helpContent.tour && helpContent.tour.length > 0 && !showTour && (
				<div className="wppo-help-actions">
					<button
						className="wppo-help-restart-tour"
						onClick={() => setShowTour(true)}
						title="Restart onboarding tour"
					>
						<span className="dashicons dashicons-controls-play"></span>
						Restart Tour
					</button>
				</div>
			)}
		</div>
	);
};

// Helper component for inline help tooltips
export const InlineHelp: React.FC<{
	content: string;
	title?: string;
	position?: 'top' | 'bottom' | 'left' | 'right';
}> = ({ content, title, position = 'top' }) => {
	return (
		<HelpTooltip
			content={content}
			title={title}
			position={position}
			size="medium"
		/>
	);
};

// Helper component for field help
export const FieldHelp: React.FC<{
	content: string;
	title?: string;
}> = ({ content, title }) => {
	return (
		<div className="wppo-field-help">
			<HelpTooltip
				content={content}
				title={title}
				position="right"
				size="small"
			>
				<span className="wppo-field-help-icon">
					<span className="dashicons dashicons-editor-help"></span>
				</span>
			</HelpTooltip>
		</div>
	);
};

export default ContextualHelp;