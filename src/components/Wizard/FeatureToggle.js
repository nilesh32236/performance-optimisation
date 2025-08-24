import React from 'react';

function FeatureToggle({ feature, onChange, translations }) {
    const { id, label, description, longDescription, value, recommended } = feature;

    return (
        <div className="wppo-feature-toggle" role="group" aria-labelledby={`feature-title-${id}`}>
            <div className="wppo-feature-content">
                <div className="wppo-feature-header">
                    <h3 id={`feature-title-${id}`}>{label}</h3>
                    {recommended && (
                        <span className="wppo-recommended-tag" aria-label="Recommended feature">
                            <span className="dashicons dashicons-star-filled" aria-hidden="true"></span>
                            {translations?.recommended || 'Recommended'}
                        </span>
                    )}
                </div>
                
                <p id={`feature-desc-${id}`} className="wppo-feature-description">{description}</p>
                
                {longDescription && (
                    <p id={`feature-long-desc-${id}`} className="wppo-feature-long-description">{longDescription}</p>
                )}
            </div>
            
            <div className="wppo-feature-control">
                <label className="wppo-toggle-switch" htmlFor={`toggle-${id}`}>
                    <input
                        id={`toggle-${id}`}
                        type="checkbox"
                        checked={value}
                        onChange={(e) => onChange(e.target.checked)}
                        aria-describedby={longDescription ? `feature-desc-${id} feature-long-desc-${id}` : `feature-desc-${id}`}
                        aria-label={`${value ? 'Disable' : 'Enable'} ${label}`}
                    />
                    <span className="wppo-toggle-slider" aria-hidden="true">
                        <span className="wppo-toggle-handle"></span>
                    </span>
                </label>
                
                <span className="wppo-toggle-label" aria-live="polite">
                    {value ? 'Enabled' : 'Disabled'}
                </span>
            </div>
        </div>
    );
}

export default FeatureToggle;