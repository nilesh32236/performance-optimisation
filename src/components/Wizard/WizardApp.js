/**
 * External dependencies
 */
import React, { useState, useCallback } from 'react';
/**
 * Internal dependencies
 */
import StepIndicator from './StepIndicator';
import WelcomeStep from './WelcomeStep';
import PresetsStep from './PresetsStep';
import FeaturesStep from './FeaturesStep';
import SummaryStep from './SummaryStep';

const TOTAL_STEPS = 4;

function WizardApp({ wizardData }) {
	const { apiUrl, nonce, translations } = wizardData || {};

	const [currentStep, setCurrentStep] = useState(1);
	const [isLoading, setIsLoading] = useState(false);
	const [error, setError] = useState(null);
	const [retryCount, setRetryCount] = useState(0);
	const [selections, setSelections] = useState({
		preset: '',
		preloadCache: false,
		imageConversion: false,
	});

	const validateStep = useCallback(
		step => {
			switch (step) {
				case 2: // Presets step
					return selections.preset !== '';
				case 3: // Features step - no validation needed, optional features
					return true;
				case 4: // Summary step
					return selections.preset !== '';
				default:
					return true;
			}
		},
		[selections]
	);

	const handleNext = useCallback(() => {
		if (currentStep < TOTAL_STEPS) {
			if (validateStep(currentStep)) {
				setError(null);
				setCurrentStep(prev => prev + 1);
			} else {
				setError('Please complete all required fields before continuing.');
			}
		}
	}, [currentStep, validateStep]);

	const handleBack = useCallback(() => {
		if (currentStep > 1) {
			setError(null);
			setCurrentStep(prev => prev - 1);
		}
	}, [currentStep]);

	const handleSelectionChange = useCallback((key, value) => {
		setSelections(prev => ({
			...prev,
			[key]: value,
		}));
	}, []);

	const handleFinish = useCallback(async () => {
		// Final validation
		if (!validateStep(currentStep)) {
			setError('Please complete all required fields before finishing setup.');
			return;
		}

		setIsLoading(true);
		setError(null);

		try {
			// Check if API URL and nonce are available
			if (!apiUrl || !nonce) {
				throw new Error(
					'Missing required configuration. Please refresh the page and try again.'
				);
			}

			const response = await fetch(`${apiUrl}wizard-setup`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body: JSON.stringify(selections),
			});

			// Check if response is ok
			if (!response.ok) {
				if (response.status === 403) {
					throw new Error('Permission denied. Please refresh the page and try again.');
				} else if (response.status === 404) {
					throw new Error('Setup endpoint not found. Please contact support.');
				} else if (response.status >= 500) {
					throw new Error('Server error. Please try again in a few moments.');
				} else {
					throw new Error(`Request failed with status ${response.status}`);
				}
			}

			const result = await response.json();

			if (result.success) {
				// Redirect to dashboard after successful setup
				if (result.data?.redirect_url) {
					window.location.href = result.data.redirect_url;
				} else {
					// Fallback redirect
					window.location.href =
						window.location.origin +
						'/wp-admin/admin.php?page=performance-optimisation';
				}
			} else {
				throw new Error(result.data?.message || 'Setup failed. Please try again.');
			}
		} catch (error) {
			setError(error.message || 'Setup failed. Please try again.');
			setRetryCount(prev => prev + 1);
		} finally {
			setIsLoading(false);
		}
	}, [apiUrl, nonce, selections, currentStep, validateStep]);

	const handleRetry = useCallback(() => {
		setError(null);
		setRetryCount(0);
		handleFinish();
	}, [handleFinish]);

	const renderCurrentStep = () => {
		switch (currentStep) {
			case 1:
				return <WelcomeStep translations={translations} onNext={handleNext} />;
			case 2:
				return (
					<PresetsStep
						translations={translations}
						selectedPreset={selections.preset}
						onPresetChange={preset => handleSelectionChange('preset', preset)}
						onNext={handleNext}
						onBack={handleBack}
					/>
				);
			case 3:
				return (
					<FeaturesStep
						translations={translations}
						preloadCache={selections.preloadCache}
						imageConversion={selections.imageConversion}
						onFeatureChange={handleSelectionChange}
						onNext={handleNext}
						onBack={handleBack}
					/>
				);
			case 4:
				return (
					<SummaryStep
						translations={translations}
						selections={selections}
						isLoading={isLoading}
						onFinish={handleFinish}
						onBack={handleBack}
					/>
				);
			default:
				return null;
		}
	};

	return (
		<div className="wppo-wizard-container" role="main" aria-label="Setup Wizard">
			<a href="#wppo-wizard-content" className="wppo-skip-link">
				Skip to main content
			</a>

			<div className="wppo-wizard-header">
				<h1 id="wppo-wizard-title">
					{translations?.welcomeTitle || 'Performance Optimisation Setup'}
				</h1>
				<StepIndicator currentStep={currentStep} totalSteps={TOTAL_STEPS} />
			</div>

			<div
				id="wppo-wizard-content"
				className="wppo-wizard-content"
				role="region"
				aria-labelledby="wppo-wizard-title"
				aria-live="polite"
			>
				{error && (
					<div className="wppo-wizard-error" role="alert">
						<span className="dashicons dashicons-warning" aria-hidden="true"></span>
						{error}
						{retryCount > 0 && retryCount < 3 && (
							<button
								type="button"
								className="wppo-wizard-button wppo-wizard-button--secondary"
								onClick={handleRetry}
								style={{ marginLeft: '10px' }}
							>
								Try Again
							</button>
						)}
					</div>
				)}
				{renderCurrentStep()}
			</div>
		</div>
	);
}

export default WizardApp;
