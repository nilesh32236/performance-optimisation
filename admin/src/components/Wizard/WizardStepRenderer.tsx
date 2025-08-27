/**
 * External dependencies
 */
import React from 'react';
/**
 * Internal dependencies
 */
import { useWizard, WizardStep } from './WizardContext';
import ErrorMessage from '../common/ErrorMessage';

interface WizardStepRendererProps {
	steps: WizardStep[];
}

function WizardStepRenderer( { steps }: WizardStepRendererProps ) {
	const { state } = useWizard();
	const { currentStep, error } = state;

	const currentStepConfig = steps[ currentStep - 1 ];

	if ( ! currentStepConfig ) {
		return (
			<div className="wppo-wizard-error" role="alert">
				<span className="dashicons dashicons-warning" aria-hidden="true" />
				Invalid step configuration. Please refresh the page and try again.
			</div>
		);
	}

	const StepComponent = currentStepConfig.component;

	return (
		<div className="wppo-wizard-step-container">
			{ error && <ErrorMessage message={ error } /> }

			<div
				className="wppo-wizard-step-content"
				key={ currentStep } // Force re-render when step changes
			>
				<StepComponent stepConfig={ currentStepConfig } wizardState={ state } />
			</div>
		</div>
	);
}

export default WizardStepRenderer;
