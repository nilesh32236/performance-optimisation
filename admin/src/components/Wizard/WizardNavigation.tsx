import React from 'react';
import { useWizard } from './WizardContext';
import Button from '../common/Button';

interface WizardNavigationProps {
	onComplete?: (data: Record<string, any>) => Promise<void>;
}

function WizardNavigation({ onComplete }: WizardNavigationProps) {
	const { 
		state, 
		nextStep, 
		previousStep, 
		validateCurrentStep, 
		setError, 
		setLoading 
	} = useWizard();
	
	const { currentStep, totalSteps, isLoading, data } = state;

	const isFirstStep = currentStep === 1;
	const isLastStep = currentStep === totalSteps;
	const canProceed = validateCurrentStep();

	const handleNext = async () => {
		if (!canProceed) {
			setError('Please complete all required fields before continuing.');
			return;
		}

		setError(null);

		if (isLastStep && onComplete) {
			try {
				setLoading(true);
				await onComplete(data);
			} catch (error) {
				setError(error instanceof Error ? error.message : 'An error occurred while completing setup.');
			} finally {
				setLoading(false);
			}
		} else {
			nextStep();
		}
	};

	const handlePrevious = () => {
		setError(null);
		previousStep();
	};

	return (
		<div className="wppo-wizard-navigation">
			<div className="wppo-nav-buttons">
				{!isFirstStep && (
					<Button
						variant="secondary"
						onClick={handlePrevious}
						disabled={isLoading}
						icon="arrow-left-alt2"
						iconPosition="left"
					>
						Back
					</Button>
				)}
				
				<Button
					variant="primary"
					onClick={handleNext}
					disabled={!canProceed || isLoading}
					loading={isLoading}
					icon={isLastStep ? 'yes-alt' : 'arrow-right-alt2'}
					iconPosition="right"
					aria-describedby={!canProceed ? 'validation-message' : undefined}
				>
					{isLastStep ? 'Complete Setup' : 'Next'}
				</Button>
			</div>
			
			{!canProceed && (
				<p id="validation-message" className="wppo-validation-message" role="alert">
					Please complete all required fields before continuing.
				</p>
			)}
		</div>
	);
}

export default WizardNavigation;