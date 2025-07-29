import React from 'react';

function CDN({ adminData, onUpdateSettings, specificSettings, isLoading, saveSettingsForTab }) {
  const { translations = {} } = adminData || {};
  const {
    enabled,
    url,
  } = specificSettings;

  return (
    <div>
      <h1>{translations.cdn || 'CDN'}</h1>
      <div className="wppo-card">
        <h2>{translations.cdnSettings || 'CDN Settings'}</h2>
        <div className="wppo-form-group">
          <label>
            <input
              type="checkbox"
              checked={enabled}
              onChange={(e) => onUpdateSettings('cdn', 'enabled', e.target.checked)}
            />
            {translations.enableCdn || 'Enable CDN'}
          </label>
        </div>
        <div className="wppo-form-group">
          <label>{translations.cdnUrl || 'CDN URL'}</label>
          <input
            type="text"
            value={url}
            onChange={(e) => onUpdateSettings('cdn', 'url', e.target.value)}
          />
        </div>
      </div>
      <button
        className="wppo-button submit-button"
        onClick={() => saveSettingsForTab('cdn')}
        disabled={isLoading}
        style={{ marginTop: '20px' }}
      >
        {isLoading ? (translations.saving || 'Saving...') : (translations.saveSettings || 'Save Settings')}
      </button>
    </div>
  );
}

export default CDN;
