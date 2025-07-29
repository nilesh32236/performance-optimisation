import React from 'react';

function CriticalCss({ adminData, onUpdateSettings, specificSettings, isLoading, saveSettingsForTab }) {
  const { translations = {} } = adminData || {};
  const {
    enabled,
    css,
  } = specificSettings;

  return (
    <div>
      <h1>{translations.criticalCss || 'Critical CSS'}</h1>
      <div className="wppo-card">
        <h2>{translations.criticalCssSettings || 'Critical CSS Settings'}</h2>
        <div className="wppo-form-group">
          <label>
            <input
              type="checkbox"
              checked={enabled}
              onChange={(e) => onUpdateSettings('critical_css', 'enabled', e.target.checked)}
            />
            {translations.enableCriticalCss || 'Enable Critical CSS'}
          </label>
        </div>
        <div className="wppo-form-group">
          <label>{translations.criticalCss || 'Critical CSS'}</label>
          <textarea
            value={css}
            onChange={(e) => onUpdateSettings('critical_css', 'css', e.target.value)}
          />
        </div>
      </div>
      <button
        className="wppo-button submit-button"
        onClick={() => saveSettingsForTab('critical_css')}
        disabled={isLoading}
        style={{ marginTop: '20px' }}
      >
        {isLoading ? (translations.saving || 'Saving...') : (translations.saveSettings || 'Save Settings')}
      </button>
    </div>
  );
}

export default CriticalCss;
