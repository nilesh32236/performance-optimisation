/**
 * External dependencies
 */
import React from 'react';
/**
 * Internal dependencies
 */
import WizardContainer from './WizardContainer';
import { WizardStep } from './WizardContext';
import WelcomeStep from './steps/WelcomeStep';
import SiteDetectionStep from './steps/SiteDetectionStep';
import PresetStep from './steps/PresetStep';
import FeaturesStep from './steps/FeaturesStep';
import SummaryStep from './steps/SummaryStep';

interface SetupWizardProps {
	apiUrl: string;
	nonce: string;
	translations?: Record<string, string>;
}

function SetupWizard({ apiUrl, nonce, translations }: SetupWizardProps) {
	const steps: WizardStep[] = [
		{
			id: 'welcome',
			title: 'Welcome',
			component: WelcomeStep,
			isValid: () => true, // Welcome step is always valid
		},
		{
			id: 'detection',
			title: 'Site Analysis',
			component: SiteDetectionStep,
			isValid: (state) => !!state.data.siteAnalysis, // Analysis must be completed
			isRequired: true,
		},
		{
			id: 'preset',
			title: 'Choose Preset',
			component: PresetStep,
			isValid: (state) => !!state.data.preset, // Preset must be selected
			isRequired: true,
		},
		{
			id: 'features',
			title: 'Advanced Features',
			component: FeaturesStep,
			isValid: () => true, // Features are optional
		},
		{
			id: 'summary',
			title: 'Summary',
			component: SummaryStep,
			isValid: (state) => !!state.data.preset, // Must have preset to complete
			isRequired: true,
		},
	];

	const handleComplete = async (data: Record<string, any>) => {
		try {
			const response = await fetch(`${apiUrl}/wizard/setup`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body: JSON.stringify({
					preset: data.preset,
					preloadCache: data.preloadCache || false,
					imageConversion: data.imageConversion || false,
					criticalCSS: data.criticalCSS || false,
					resourceHints: data.resourceHints || false,
				}),
			});

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
					window.location.href = `${window.location.origin}/wp-admin/admin.php?page=performance-optimisation`;
				}
			} else {
				throw new Error(result.data?.message || 'Setup failed. Please try again.');
			}
		} catch (error) {
			console.error('Setup error:', error);
			throw error; // Re-throw to be handled by the wizard framework
		}
	};

	return (
		<WizardContainer
			steps={steps}
			title={translations?.wizardTitle || 'Performance Optimisation Setup'}
			subtitle={
				translations?.wizardSubtitle || 'Configure your website for optimal performance'
			}
			onComplete={handleComplete}
			className="wppo-setup-wizard"
		/>
	);
}

export default SetupWizard;
