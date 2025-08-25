import React from 'react';
import { useWizard } from '../WizardContext';

interface FeaturesStepProps {
	stepConfig: any;
	wizardState: any;
}

interface Feature {
	id: string;
	title: string;
	description: string;
	longDescription: string;
	recommended: boolean;
	impact: 'low' | 'medium' | 'high';
	complexity: 'low' | 'medium' | 'high';
}

function FeaturesStep({ stepConfig }: FeaturesStepProps) {
	const { state, updateData } = useWizard();
	
	const features: Feature[] = [
		{
			id: 'preloadCache',
			title: 'Cache Preloading',
			description: 'Automatically prepare cached versions of your pages for faster delivery.',
			longDescription: 'This feature runs in the background to ensure visitors always get the fastest version of your site. It intelligently preloads your most important pages.',
			recommended: true,
			impact: 'high',
			complexity: 'low',
		},
		{
			id: 'imageConversion',
			title: 'Modern Image Formats',
			description: 'Convert images to WebP and AVIF formats for better compression.',
			longDescription: 'Automatically converts uploaded images to modern formats that are 25-50% smaller while maintaining quality. Original images are kept as backup.',
			recommended: true,
			impact: 'high',
			complexity: 'medium',
		},
		{
			id: 'criticalCSS',
			title: 'Critical CSS Generation',
			description: 'Inline critical CSS for above-the-fold content.',
			longDescription: 'Automatically identifies and inlines the CSS needed for above-the-fold content, eliminating render-blocking CSS for faster page loads.',
			recommended: false,
			impact: 'medium',
			complexity: 'high',
		},
		{
			id: 'resourceHints',
			title: 'Resource Preloading',
			description: 'Preload important resources for faster navigation.',
			longDescription: 'Intelligently preloads fonts, images, and other resources that are likely to be needed, reducing perceived load times.',
			recommended: true,
			impact: 'medium',
			complexity: 'low',
		}
	];

	const handleFeatureToggle = (featureId: string, enabled: boolean) => {
		updateData(featureId, enabled);
	};

	const getImpactColor = (impact: string) => {
		switch (impact) {
			case 'high': return '#00a32a';
			case 'medium': return '#dba617';
			case 'low': return '#72aee6';
			default: return '#50575e';
		}
	};

	const getComplexityColor = (complexity: string) => {
		switch (complexity) {
			case 'low': return '#00a32a';
			case 'medium': return '#dba617';
			case 'high': return '#d63638';
			default: return '#50575e';
		}
	};

	return (
		<div className="wppo-wizard-step wppo-features-step">
			<div className="wppo-step-header">
				<h2>Advanced Features</h2>
				<p className="wppo-step-description">
					Enable these powerful features to supercharge your website's performance. 
					All features are optional and can be configured later.
				</p>
			</div>
			
			<div className="wppo-features-list">
				{features.map((feature) => {
					const isEnabled = state.data[feature.id] || false;
					
					return (
						<div 
							key={feature.id}
							className={`wppo-feature-card ${isEnabled ? 'enabled' : ''} ${feature.recommended ? 'recommended' : ''}`}
						>
							<div className="wppo-feature-header">
								<div className="wppo-feature-toggle">
									<label className="wppo-toggle-switch">
										<input
											type="checkbox"
											checked={isEnabled}
											onChange={(e) => handleFeatureToggle(feature.id, e.target.checked)}
											aria-describedby={`${feature.id}-description`}
										/>
										<span className="wppo-toggle-slider" />
									</label>
								</div>
								
								<div className="wppo-feature-info">
									<div className="wppo-feature-title-row">
										<h3 className="wppo-feature-title">{feature.title}</h3>
										{feature.recommended && (
											<span className="wppo-feature-badge wppo-feature-badge--recommended">
												Recommended
											</span>
										)}
									</div>
									<p className="wppo-feature-description">{feature.description}</p>
								</div>
							</div>
							
							<div className="wppo-feature-details">
								<p className="wppo-feature-long-description" id={`${feature.id}-description`}>
									{feature.longDescription}
								</p>
								
								<div className="wppo-feature-metrics">
									<div className="wppo-feature-metric">
										<span className="wppo-metric-label">Performance Impact</span>
										<div className="wppo-metric-indicator">
											<div 
												className="wppo-metric-dot"
												style={{ backgroundColor: getImpactColor(feature.impact) }}
											/>
											<span className="wppo-metric-text">{feature.impact}</span>
										</div>
									</div>
									
									<div className="wppo-feature-metric">
										<span className="wppo-metric-label">Setup Complexity</span>
										<div className="wppo-metric-indicator">
											<div 
												className="wppo-metric-dot"
												style={{ backgroundColor: getComplexityColor(feature.complexity) }}
											/>
											<span className="wppo-metric-text">{feature.complexity}</span>
										</div>
									</div>
								</div>
							</div>
						</div>
					);
				})}
			</div>
			
			<div className="wppo-features-note">
				<div className="wppo-note-icon">
					<span className="dashicons dashicons-info" aria-hidden="true" />
				</div>
				<div className="wppo-note-content">
					<strong>Good to know:</strong> These features work automatically in the background 
					and can be disabled at any time if you experience any issues.
				</div>
			</div>
		</div>
	);
}

export default FeaturesStep;