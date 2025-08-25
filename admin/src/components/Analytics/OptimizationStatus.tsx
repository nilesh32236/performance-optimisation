import React from 'react';
import { Card } from '../Card';

interface OptimizationStatusProps {
	status: {
		features: Record<string, boolean>;
		image_optimization: {
			total_optimized: number;
			total_pending: number;
			optimization_ratio: number;
		};
	};
}

const OptimizationStatus: React.FC<OptimizationStatusProps> = ({ status }) => {
	const featureLabels: Record<string, string> = {
		page_caching: 'Page Caching',
		css_minification: 'CSS Minification',
		js_minification: 'JS Minification',
		html_minification: 'HTML Minification',
		image_lazy_loading: 'Image Lazy Loading',
		image_conversion: 'Image Conversion',
	};

	const enabledFeatures = Object.entries(status.features).filter(([, enabled]) => enabled);
	const totalFeatures = Object.keys(status.features).length;
	const enabledPercentage = (enabledFeatures.length / totalFeatures) * 100;

	const getStatusColor = (percentage: number): string => {
		if (percentage >= 80) return 'excellent';
		if (percentage >= 60) return 'good';
		if (percentage >= 40) return 'fair';
		return 'poor';
	};

	return (
		<div className="wppo-optimization-status">
			<h3>Optimization Status</h3>
			<div className="wppo-optimization-status__grid">
				<Card title="Active Features" className="wppo-optimization-card">
					<div className="wppo-optimization-summary">
						<div className={`wppo-optimization-score wppo-optimization-score--${getStatusColor(enabledPercentage)}`}>
							<div className="wppo-optimization-score__value">
								{enabledFeatures.length}/{totalFeatures}
							</div>
							<div className="wppo-optimization-score__label">
								Features Enabled
							</div>
						</div>
						<div className="wppo-optimization-progress">
							<div className="wppo-optimization-progress__bar">
								<div 
									className={`wppo-optimization-progress__fill wppo-optimization-progress__fill--${getStatusColor(enabledPercentage)}`}
									style={{ width: `${enabledPercentage}%` }}
								></div>
							</div>
							<div className="wppo-optimization-progress__text">
								{enabledPercentage.toFixed(0)}% optimization coverage
							</div>
						</div>
					</div>
				</Card>

				<Card title="Feature Details" className="wppo-features-card">
					<div className="wppo-features-list">
						{Object.entries(status.features).map(([feature, enabled]) => (
							<div key={feature} className="wppo-feature-item">
								<div className="wppo-feature-item__content">
									<span className={`wppo-feature-status wppo-feature-status--${enabled ? 'enabled' : 'disabled'}`}>
										<span className={`dashicons ${enabled ? 'dashicons-yes-alt' : 'dashicons-dismiss'}`}></span>
									</span>
									<span className="wppo-feature-name">
										{featureLabels[feature] || feature}
									</span>
								</div>
								<span className={`wppo-feature-badge wppo-feature-badge--${enabled ? 'enabled' : 'disabled'}`}>
									{enabled ? 'Active' : 'Inactive'}
								</span>
							</div>
						))}
					</div>
				</Card>

				<Card title="Image Optimization" className="wppo-image-optimization-card">
					<div className="wppo-image-stats">
						<div className="wppo-image-stats__summary">
							<div className="wppo-image-stat">
								<div className="wppo-image-stat__value">
									{status.image_optimization.total_optimized}
								</div>
								<div className="wppo-image-stat__label">
									Images Optimized
								</div>
							</div>
							<div className="wppo-image-stat">
								<div className="wppo-image-stat__value">
									{status.image_optimization.total_pending}
								</div>
								<div className="wppo-image-stat__label">
									Pending Optimization
								</div>
							</div>
						</div>
						<div className="wppo-image-progress">
							<div className="wppo-image-progress__bar">
								<div 
									className="wppo-image-progress__fill"
									style={{ width: `${status.image_optimization.optimization_ratio}%` }}
								></div>
							</div>
							<div className="wppo-image-progress__text">
								{status.image_optimization.optimization_ratio.toFixed(1)}% optimized
							</div>
						</div>
					</div>
				</Card>
			</div>
		</div>
	);
};

export default OptimizationStatus;