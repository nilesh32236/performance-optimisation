/**
 * External dependencies
 */
import React from 'react';

function PresetCard({ preset, isSelected, onSelect, translations }) {
	const { id, title, description, features, isRecommended, hasWarning } = preset;

	return (
		<div
			className={`wppo-preset-card ${isSelected ? 'selected' : ''} ${isRecommended ? 'recommended' : ''}`}
			onClick={onSelect}
			role="radio"
			tabIndex={0}
			aria-checked={isSelected}
			aria-labelledby={`preset-title-${id}`}
			aria-describedby={`preset-desc-${id} preset-features-${id}`}
			onKeyPress={e => {
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					onSelect();
				}
			}}
		>
			{isRecommended && (
				<div className="wppo-recommended-badge">
					<span className="dashicons dashicons-star-filled"></span>
					{translations?.recommended || 'Recommended'}
				</div>
			)}

			<div className="wppo-preset-header">
				<h3 id={`preset-title-${id}`}>{title}</h3>
				{hasWarning && (
					<span
						className="wppo-warning-icon dashicons dashicons-warning"
						title="May require testing"
						aria-label="Warning: May require testing"
					></span>
				)}
			</div>

			<p id={`preset-desc-${id}`} className="wppo-preset-description">
				{description}
			</p>

			<div id={`preset-features-${id}`} className="wppo-preset-features">
				<h4>Includes:</h4>
				<ul>
					{features.map((feature, index) => (
						<li key={index}>
							<span className="dashicons dashicons-yes-alt" aria-hidden="true"></span>
							{feature}
						</li>
					))}
				</ul>
			</div>

			<div className="wppo-preset-selector">
				<input
					type="radio"
					name="preset"
					value={id}
					checked={isSelected}
					onChange={onSelect}
					aria-label={`Select ${title} preset`}
				/>
				<span className="wppo-radio-checkmark"></span>
			</div>
		</div>
	);
}

export default PresetCard;
