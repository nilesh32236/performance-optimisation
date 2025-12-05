/**
 * External dependencies
 */
import React from 'react';
/**
 * Internal dependencies
 */
import { useWizard } from '../WizardContext';

interface SummaryStepProps {
	stepConfig: any;
	wizardState: any;
}

function SummaryStep( { stepConfig }: SummaryStepProps ) {
	const { state } = useWizard();
	const { data } = state;

	const getPresetInfo = ( presetId: string ) => {
		const presets = {
			safe: {
				title: 'Safe Mode',
				description: 'Basic optimizations for maximum compatibility',
				features: [ 'Page Caching', 'Image Lazy Loading', 'Basic HTML Compression' ],
			},
			recommended: {
				title: 'Recommended',
				description: 'Balanced performance and compatibility',
				features: [
					'Page Caching',
					'Image Lazy Loading',
					'CSS & JS Minification',
					'File Combination',
				],
			},
			advanced: {
				title: 'Advanced',
				description: 'Maximum performance optimizations',
				features: [
					'All Recommended features',
					'JavaScript Deferring',
					'Critical CSS',
					'Resource Preloading',
				],
			},
		};
		return presets[ presetId as keyof typeof presets ] || presets.safe;
	};

	const getEnabledFeatures = () => {
		const features = [];
		if ( data.preloadCache ) {
			features.push( 'Cache Preloading' );
		}
		if ( data.imageConversion ) {
			features.push( 'Modern Image Formats' );
		}
		if ( data.criticalCSS ) {
			features.push( 'Critical CSS Generation' );
		}
		if ( data.resourceHints ) {
			features.push( 'Resource Preloading' );
		}
		return features;
	};

	const presetInfo = getPresetInfo( data.preset );
	const enabledFeatures = getEnabledFeatures();

	return (
		<div className="wppo-summary-step">
			<div className="wppo-step-header">
				<h2>Setup Summary</h2>
				<p className="wppo-step-description">
					Review your configuration before completing the setup. These settings will be
					applied to your website.
				</p>
			</div>

			<div className="wppo-summary-content">
				{ /* Preset Summary */ }
				<div className="wppo-summary-section">
					<h3>
						<span className="dashicons dashicons-admin-settings" aria-hidden="true" />
						Optimization Preset
					</h3>
					<div className="wppo-summary-card">
						<div className="wppo-summary-card-header">
							<h4>{ presetInfo.title }</h4>
							<p>{ presetInfo.description }</p>
						</div>
						<div className="wppo-summary-card-content">
							<h5>Included Features:</h5>
							<ul className="wppo-feature-list">
								{ presetInfo.features.map( ( feature, index ) => (
									<li key={ index }>
										<span
											className="dashicons dashicons-yes-alt"
											aria-hidden="true"
										/>
										{ feature }
									</li>
								) ) }
							</ul>
						</div>
					</div>
				</div>

				{ /* Advanced Features Summary */ }
				{ enabledFeatures.length > 0 && (
					<div className="wppo-summary-section">
						<h3>
							<span className="dashicons dashicons-star-filled" aria-hidden="true" />
							Advanced Features
						</h3>
						<div className="wppo-summary-card">
							<div className="wppo-summary-card-content">
								<ul className="wppo-feature-list">
									{ enabledFeatures.map( ( feature, index ) => (
										<li key={ index }>
											<span
												className="dashicons dashicons-yes-alt"
												aria-hidden="true"
											/>
											{ feature }
										</li>
									) ) }
								</ul>
							</div>
						</div>
					</div>
				) }

				{ /* Expected Benefits */ }
				<div className="wppo-summary-section">
					<h3>
						<span className="dashicons dashicons-chart-line" aria-hidden="true" />
						Expected Benefits
					</h3>
					<div className="wppo-summary-card">
						<div className="wppo-benefits-grid">
							<div className="wppo-benefit-item">
								<div className="wppo-benefit-icon">
									<span
										className="dashicons dashicons-performance"
										aria-hidden="true"
									/>
								</div>
								<div className="wppo-benefit-content">
									<h4>Faster Loading</h4>
									<p>20-50% improvement in page load times</p>
								</div>
							</div>

							<div className="wppo-benefit-item">
								<div className="wppo-benefit-icon">
									<span
										className="dashicons dashicons-search"
										aria-hidden="true"
									/>
								</div>
								<div className="wppo-benefit-content">
									<h4>Better SEO</h4>
									<p>Improved search engine rankings</p>
								</div>
							</div>

							<div className="wppo-benefit-item">
								<div className="wppo-benefit-icon">
									<span
										className="dashicons dashicons-smartphone"
										aria-hidden="true"
									/>
								</div>
								<div className="wppo-benefit-content">
									<h4>Mobile Optimized</h4>
									<p>Enhanced mobile user experience</p>
								</div>
							</div>

							<div className="wppo-benefit-item">
								<div className="wppo-benefit-icon">
									<span
										className="dashicons dashicons-cloud"
										aria-hidden="true"
									/>
								</div>
								<div className="wppo-benefit-content">
									<h4>Reduced Bandwidth</h4>
									<p>Lower hosting costs and resource usage</p>
								</div>
							</div>
						</div>
					</div>
				</div>

				{ /* Next Steps */ }
				<div className="wppo-summary-section">
					<h3>
						<span className="dashicons dashicons-list-view" aria-hidden="true" />
						What Happens Next
					</h3>
					<div className="wppo-summary-card">
						<div className="wppo-next-steps">
							<div className="wppo-step-item">
								<div className="wppo-step-number">1</div>
								<div className="wppo-step-content">
									<h4>Settings Applied</h4>
									<p>
										Your optimization settings will be configured automatically
									</p>
								</div>
							</div>

							<div className="wppo-step-item">
								<div className="wppo-step-number">2</div>
								<div className="wppo-step-content">
									<h4>Cache Initialization</h4>
									<p>
										The caching system will be activated and start optimizing
										your site
									</p>
								</div>
							</div>

							<div className="wppo-step-item">
								<div className="wppo-step-number">3</div>
								<div className="wppo-step-content">
									<h4>Dashboard Access</h4>
									<p>
										You'll be redirected to the dashboard to monitor performance
									</p>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<div className="wppo-summary-note">
				<div className="wppo-note-icon">
					<span className="dashicons dashicons-info" aria-hidden="true" />
				</div>
				<div className="wppo-note-content">
					<strong>Remember:</strong> All settings can be modified later from the plugin
					dashboard. If you experience any issues, you can always reset to safe mode.
				</div>
			</div>
		</div>
	);
}

export default SummaryStep;
