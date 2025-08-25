import React from 'react';
import { useWizard } from './WizardContext';

function WizardProgressBar() {
	const { state } = useWizard();
	const { currentStep, totalSteps, completedSteps, visitedSteps } = state;

	const progressPercentage = (currentStep / totalSteps) * 100;

	const getStepStatus = (stepNumber: number) => {
		if (completedSteps.has(stepNumber)) return 'completed';
		if (stepNumber === currentStep) return 'current';
		if (visitedSteps.has(stepNumber)) return 'visited';
		return 'pending';
	};

	const getStepIcon = (stepNumber: number, status: string) => {
		switch (status) {
			case 'completed':
				return <span className="dashicons dashicons-yes-alt" aria-hidden="true" />;
			case 'current':
				return <span className="wppo-step-number">{stepNumber}</span>;
			default:
				return <span className="wppo-step-number">{stepNumber}</span>;
		}
	};

	return (
		<div className="wppo-wizard-progress" role="navigation" aria-label="Setup progress">
			{/* Progress bar */}
			<div 
				className="wppo-progress-bar" 
				role="progressbar" 
				aria-valuenow={currentStep} 
				aria-valuemin={1} 
				aria-valuemax={totalSteps}
				aria-label={`Step ${currentStep} of ${totalSteps}`}
			>
				<div 
					className="wppo-progress-fill" 
					style={{ width: `${progressPercentage}%` }}
				/>
			</div>

			{/* Step indicators */}
			<div className="wppo-step-indicators" role="list">
				{Array.from({ length: totalSteps }, (_, index) => {
					const stepNumber = index + 1;
					const status = getStepStatus(stepNumber);
					
					return (
						<div 
							key={stepNumber}
							className={`wppo-step-indicator wppo-step-${status}`}
							role="listitem"
							aria-current={status === 'current' ? 'step' : undefined}
						>
							<div className="wppo-step-icon">
								{getStepIcon(stepNumber, status)}
							</div>
							<span className="wppo-step-label">
								Step {stepNumber}
							</span>
						</div>
					);
				})}
			</div>

			{/* Progress text */}
			<p className="wppo-progress-text" aria-live="polite">
				Step {currentStep} of {totalSteps}
			</p>
		</div>
	);
}

export default WizardProgressBar;