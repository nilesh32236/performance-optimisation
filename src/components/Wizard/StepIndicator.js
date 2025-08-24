import React from 'react';

function StepIndicator({ currentStep, totalSteps }) {
    const steps = [
        'Welcome',
        'Presets', 
        'Features',
        'Summary'
    ];

    return (
        <div className="wppo-step-indicator" role="navigation" aria-label="Setup progress">
            <div className="wppo-step-progress" role="progressbar" aria-valuenow={currentStep} aria-valuemin={1} aria-valuemax={totalSteps}>
                <div 
                    className="wppo-step-progress-bar" 
                    style={{ width: `${(currentStep / totalSteps) * 100}%` }}
                />
                <span className="wppo-sr-only">
                    Progress: {currentStep} of {totalSteps} steps completed
                </span>
            </div>
            <div className="wppo-step-labels" role="list">
                {steps.map((step, index) => (
                    <div 
                        key={step}
                        className={`wppo-step-label ${
                            index + 1 === currentStep ? 'active' : 
                            index + 1 < currentStep ? 'completed' : ''
                        }`}
                        role="listitem"
                        aria-current={index + 1 === currentStep ? 'step' : undefined}
                    >
                        <span className="wppo-step-number" aria-hidden="true">{index + 1}</span>
                        <span className="wppo-step-text">{step}</span>
                    </div>
                ))}
            </div>
            <p className="wppo-step-counter" aria-live="polite">
                Step {currentStep} of {totalSteps}
            </p>
        </div>
    );
}

export default StepIndicator;