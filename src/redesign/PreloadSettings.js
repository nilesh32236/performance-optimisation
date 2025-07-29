import React from 'react';

function PreloadSettings({ adminData, onUpdateSettings, specificSettings, isLoading, saveSettingsForTab }) {
  const { translations = {} } = adminData || {};
  const {
    enablePreloadCache,
    enableCronJobs,
    preconnect,
    preconnectOrigins,
    prefetchDNS,
    dnsPrefetchOrigins,
    preloadFonts,
    preloadFontsUrls,
    preloadCSS,
    preloadCSSUrls,
  } = specificSettings;

  return (
    <div>
      <h1>{translations.preloadSettings || 'Preload & Preconnect'}</h1>
      <div className="wppo-card">
        <h2>{translations.preload || 'Preload'}</h2>
        <div className="wppo-form-group">
          <label>
            <input
              type="checkbox"
              checked={enablePreloadCache}
              onChange={(e) => onUpdateSettings('preload_settings', 'enablePreloadCache', e.target.checked)}
            />
            {translations.enablePreloadCache || 'Enable Preload Cache'}
          </label>
        </div>
        <div className="wppo-form-group">
          <label>
            <input
              type="checkbox"
              checked={enableCronJobs}
              onChange={(e) => onUpdateSettings('preload_settings', 'enableCronJobs', e.target.checked)}
            />
            {translations.enableCronJobs || 'Enable Cron Jobs'}
          </label>
        </div>
        <div className="wppo-form-group">
          <label>
            <input
              type="checkbox"
              checked={preloadFonts}
              onChange={(e) => onUpdateSettings('preload_settings', 'preloadFonts', e.target.checked)}
            />
            {translations.preloadFonts || 'Preload Fonts'}
          </label>
        </div>
        <div className="wppo-form-group">
          <label>{translations.preloadFontsUrls || 'Font URLs'}</label>
          <textarea
            value={preloadFontsUrls}
            onChange={(e) => onUpdateSettings('preload_settings', 'preloadFontsUrls', e.target.value)}
          />
        </div>
        <div className="wppo-form-group">
          <label>
            <input
              type="checkbox"
              checked={preloadCSS}
              onChange={(e) => onUpdateSettings('preload_settings', 'preloadCSS', e.target.checked)}
            />
            {translations.preloadCSS || 'Preload CSS'}
          </label>
        </div>
        <div className="wppo-form-group">
          <label>{translations.preloadCSSUrls || 'CSS URLs'}</label>
          <textarea
            value={preloadCSSUrls}
            onChange={(e) => onUpdateSettings('preload_settings', 'preloadCSSUrls', e.target.value)}
          />
        </div>
      </div>
      <div className="wppo-card">
        <h2>{translations.preconnect || 'Preconnect'}</h2>
        <div className="wppo-form-group">
          <label>
            <input
              type="checkbox"
              checked={preconnect}
              onChange={(e) => onUpdateSettings('preload_settings', 'preconnect', e.target.checked)}
            />
            {translations.preconnect || 'Preconnect'}
          </label>
        </div>
        <div className="wppo-form-group">
          <label>{translations.preconnectOrigins || 'Preconnect Origins'}</label>
          <textarea
            value={preconnectOrigins}
            onChange={(e) => onUpdateSettings('preload_settings', 'preconnectOrigins', e.target.value)}
          />
        </div>
      </div>
      <div className="wppo-card">
        <h2>{translations.prefetchDNS || 'Prefetch DNS'}</h2>
        <div className="wppo-form-group">
          <label>
            <input
              type="checkbox"
              checked={prefetchDNS}
              onChange={(e) => onUpdateSettings('preload_settings', 'prefetchDNS', e.target.checked)}
            />
            {translations.prefetchDNS || 'Prefetch DNS'}
          </label>
        </div>
        <div className="wppo-form-group">
          <label>{translations.dnsPrefetchOrigins || 'DNS Prefetch Origins'}</label>
          <textarea
            value={dnsPrefetchOrigins}
            onChange={(e) => onUpdateSettings('preload_settings', 'dnsPrefetchOrigins', e.target.value)}
          />
        </div>
      </div>
      <button
        className="wppo-button submit-button"
        onClick={() => saveSettingsForTab('preload_settings')}
        disabled={isLoading}
        style={{ marginTop: '20px' }}
      >
        {isLoading ? (translations.saving || 'Saving...') : (translations.saveSettings || 'Save Settings')}
      </button>
    </div>
  );
}

export default PreloadSettings;
