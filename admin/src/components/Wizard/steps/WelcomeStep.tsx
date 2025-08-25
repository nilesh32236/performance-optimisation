import React from 'react';
import { useWizard } from '../WizardContext';

interface WelcomeStepProps {
	stepConfig: any;
	wizardState: any;
}

function WelcomeStep({ stepConfig }: WelcomeStepProps) {
	const { updateData } = useWizard();

	React.useEffect(() => {
		// Mark that user has seen the welcome step
		updateData('welcomeViewed', true);
	}, [updateData]);

	return (
		<div className="wppo-wizard-step wppo-welcome-step">
			<div className="wppo-step-content">
				<div className="wppo-welcome-icon">
					<span className="dashicons dashicons-performance" aria-hidden="true" />
				</div>
				
				<h2>Welcome to Performance Optimisation</h2>
				
				<p className="wppo-welcome-description">
					This setup wizard will help you configure optimal performance settings for your website. 
					The process takes just a few minutes and will significantly improve your site's speed.
				</p>
				
				<div className="wppo-welcome-features">
					<h3>What you'll get:</h3>
					<ul>
						<li>
							<span className="dashicons dashicons-yes-alt" aria-hidden="true" />
							Faster page loading times
						</li>
						<li>
							<span className="dashicons dashicons-yes-alt" aria-hidden="true" />
							Improved search engine rankings
						</li>
						<li>
							<span className="dashicons dashicons-yes-alt" aria-hidden="true" />
							Better user experience
						</li>
						<li>
							<span className="dashicons dashicons-yes-alt" aria-hidden="true" />
							Reduced server load
						</li>
					</ul>
				</div>
				
				<div className="wppo-welcome-note">
					<div className="wppo-note-icon">
						<span className="dashicons dashicons-info" aria-hidden="true" />
					</div>
					<div className="wppo-note-content">
						<strong>Don't worry!</strong> All settings can be changed later, and we'll only enable 
						features that are safe for your website.
					</div>
				</div>
			</div>
		</div>
	);
}

export default WelcomeStep;