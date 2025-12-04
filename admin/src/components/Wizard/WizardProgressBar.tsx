/**
 * External dependencies
 */
import React from 'react';
/**
 * Internal dependencies
 */
import { useWizard } from './WizardContext';

function WizardProgressBar() {
	const { state } = useWizard();
	const { currentStep, steps } = state;

	const getStepStatus = ( stepIndex: number ) => {
		if ( stepIndex < currentStep ) {
			return 'completed';
		}
		if ( stepIndex === currentStep ) {
			return 'active';
		}
		return 'pending';
	};

	return (
		<div className="wppo-wizard-progress" role="navigation" aria-label="Setup progress">
			<div className="wppo-wizard-steps" role="list">
				{ steps.map( ( step, index ) => {
					const status = getStepStatus( index );
					const stepNumber = index + 1;

					return (
						<div
							key={ step.id }
							className={ `wppo-wizard-step ${ status }` }
							role="listitem"
							data-step={ stepNumber }
							aria-current={ status === 'active' ? 'step' : undefined }
						>
							<span className="wppo-wizard-step-label">{ step.title }</span>
						</div>
					);
				} ) }
			</div>
		</div>
	);
}

export default WizardProgressBar;
