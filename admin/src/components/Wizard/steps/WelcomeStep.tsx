/**
 * External dependencies
 */
import React from 'react';
/**
 * Internal dependencies
 */
import { useWizard } from '../WizardContext';

interface WelcomeStepProps {
	stepConfig: any;
	wizardState: any;
}

function WelcomeStep( { stepConfig }: WelcomeStepProps ) {
	const { updateData } = useWizard();

	React.useEffect( () => {
		// Mark that user has seen the welcome step
		updateData( 'welcomeViewed', true );
	}, [ updateData ] );

	return (
		<div className="wppo-welcome-step">
			<div className="wppo-step-header">
				<h2>Welcome to Performance Optimisation</h2>
				<p className="wppo-step-description">
					Boost your website's speed in just a few simple steps
				</p>
			</div>

			<div className="wppo-welcome-content">
				<div className="wppo-welcome-benefits">
					<h3>What you'll achieve:</h3>
					<div className="wppo-benefits-grid">
						<div className="wppo-benefit-item">
							<span className="dashicons dashicons-clock" />
							<span>Faster Loading</span>
						</div>
						<div className="wppo-benefit-item">
							<span className="dashicons dashicons-chart-line" />
							<span>Better SEO</span>
						</div>
						<div className="wppo-benefit-item">
							<span className="dashicons dashicons-heart" />
							<span>Happy Users</span>
						</div>
						<div className="wppo-benefit-item">
							<span className="dashicons dashicons-admin-tools" />
							<span>Optimized Server</span>
						</div>
					</div>
				</div>

				<div className="wppo-welcome-info">
					<div className="wppo-info-box">
						<span className="dashicons dashicons-info-outline" />
						<div>
							<strong>Safe & Reversible</strong>
							<p>All optimizations are safe and can be easily changed or disabled anytime.</p>
						</div>
					</div>
				</div>
			</div>
		</div>
	);
}

export default WelcomeStep;
