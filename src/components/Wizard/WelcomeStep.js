/**
 * External dependencies
 */
import React from 'react';

function WelcomeStep({ translations, onNext }) {
	return (
		<div
			className="wppo-wizard-step wppo-welcome-step"
			role="region"
			aria-labelledby="welcome-title"
		>
			<div className="wppo-welcome-content">
				<div className="wppo-welcome-icon" aria-hidden="true">
					<span className="dashicons dashicons-performance"></span>
				</div>

				<h2 id="welcome-title">
					{translations?.welcomeTitle || 'Welcome to Performance Optimisation!'}
				</h2>

				<p className="wppo-welcome-description">
					{translations?.welcomeDescription ||
						"Let's make your site fast in just a few clicks."}
				</p>

				<div className="wppo-welcome-features" role="list" aria-label="Key benefits">
					<div className="wppo-feature-item" role="listitem">
						<span className="dashicons dashicons-yes-alt" aria-hidden="true"></span>
						<span>Safe and tested optimizations</span>
					</div>
					<div className="wppo-feature-item" role="listitem">
						<span className="dashicons dashicons-yes-alt" aria-hidden="true"></span>
						<span>No technical knowledge required</span>
					</div>
					<div className="wppo-feature-item" role="listitem">
						<span className="dashicons dashicons-yes-alt" aria-hidden="true"></span>
						<span>Instant performance improvements</span>
					</div>
				</div>

				<p className="wppo-welcome-note">
					This wizard will guide you through selecting the best performance settings for
					your website. The entire process takes less than 2 minutes and can be changed
					later if needed.
				</p>
			</div>

			<div className="wppo-wizard-navigation">
				<div className="wppo-nav-buttons">
					<button
						type="button"
						className="wppo-wizard-button wppo-wizard-button--large"
						onClick={onNext}
					>
						<span className="dashicons dashicons-arrow-right-alt2"></span>
						{translations?.letsGetStarted || "Let&apos;s Get Started"}
					</button>
				</div>
			</div>
		</div>
	);
}

export default WelcomeStep;
