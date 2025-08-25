import React from 'react';
import { WizardProvider, WizardStep } from './WizardContext';
import WizardNavigation from './WizardNavigation';
import WizardProgressBar from './WizardProgressBar';
import WizardStepRenderer from './WizardStepRenderer';
import ErrorBoundary from '../common/ErrorBoundary';

interface WizardContainerProps {
	steps: WizardStep[];
	title?: string;
	subtitle?: string;
	onComplete?: (data: Record<string, any>) => Promise<void>;
	className?: string;
}

function WizardContainer({
	steps,
	title = 'Setup Wizard',
	subtitle,
	onComplete,
	className = '',
}: WizardContainerProps) {
	return (
		<ErrorBoundary>
			<WizardProvider steps={steps}>
				<div className={`wppo-wizard-container ${className}`} role="main" aria-label="Setup Wizard">
					<a href="#wppo-wizard-content" className="wppo-skip-link">
						Skip to main content
					</a>
					
					<div className="wppo-wizard-header">
						<h1 id="wppo-wizard-title">{title}</h1>
						{subtitle && (
							<p className="wppo-wizard-subtitle">{subtitle}</p>
						)}
						<WizardProgressBar />
					</div>
					
					<div 
						id="wppo-wizard-content"
						className="wppo-wizard-content"
						role="region"
						aria-labelledby="wppo-wizard-title"
						aria-live="polite"
					>
						<WizardStepRenderer steps={steps} />
					</div>
					
					<WizardNavigation onComplete={onComplete} />
				</div>
			</WizardProvider>
		</ErrorBoundary>
	);
}

export default WizardContainer;