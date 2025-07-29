import React from 'react';

function FileOptimization({ adminData, onUpdateSettings, specificSettings, isLoading, saveSettingsForTab }) {
  const { translations = {} } = adminData || {};
  const {
    minifyJS,
    minifyCSS,
    combineCSS,
    excludeJS,
    excludeCSS,
    excludeCombineCSS,
    deferJS,
    excludeDeferJS,
    delayJS,
    excludeDelayJS,
  } = specificSettings;

  return (
    <div>
      <h1>{translations.fileOptimization || 'File Optimization'}</h1>
      <div className="wppo-card">
        <h2>{translations.minify || 'Minify'}</h2>
        <div className="wppo-form-group">
          <label>
            <input
              type="checkbox"
              checked={minifyJS}
              onChange={(e) => onUpdateSettings('file_optimisation', 'minifyJS', e.target.checked)}
            />
            {translations.minifyJS || 'Minify JavaScript'}
          </label>
        </div>
        <div className="wppo-form-group">
          <label>
            <input
              type="checkbox"
              checked={minifyCSS}
              onChange={(e) => onUpdateSettings('file_optimisation', 'minifyCSS', e.target.checked)}
            />
            {translations.minifyCSS || 'Minify CSS'}
          </label>
        </div>
      </div>
      <div className="wppo-card">
        <h2>{translations.combine || 'Combine'}</h2>
        <div className="wppo-form-group">
          <label>
            <input
              type="checkbox"
              checked={combineCSS}
              onChange={(e) => onUpdateSettings('file_optimisation', 'combineCSS', e.target.checked)}
            />
            {translations.combineCSS || 'Combine CSS'}
          </label>
        </div>
      </div>
      <div className="wppo-card">
        <h2>{translations.exclude || 'Exclude'}</h2>
        <div className="wppo-form-group">
          <label>{translations.excludeJS || 'Exclude JavaScript'}</label>
          <textarea
            value={excludeJS}
            onChange={(e) => onUpdateSettings('file_optimisation', 'excludeJS', e.target.value)}
          />
        </div>
        <div className="wppo-form-group">
          <label>{translations.excludeCSS || 'Exclude CSS'}</label>
          <textarea
            value={excludeCSS}
            onChange={(e) => onUpdateSettings('file_optimisation', 'excludeCSS', e.target.value)}
          />
        </div>
        <div className="wppo-form-group">
          <label>{translations.excludeCombineCSS || 'Exclude Combine CSS'}</label>
          <textarea
            value={excludeCombineCSS}
            onChange={(e) => onUpdateSettings('file_optimisation', 'excludeCombineCSS', e.target.value)}
          />
        </div>
      </div>
      <div className="wppo-card">
        <h2>{translations.defer || 'Defer'}</h2>
        <div className="wppo-form-group">
          <label>
            <input
              type="checkbox"
              checked={deferJS}
              onChange={(e) => onUpdateSettings('file_optimisation', 'deferJS', e.target.checked)}
            />
            {translations.deferJS || 'Defer JavaScript'}
          </label>
        </div>
        <div className="wppo-form-group">
          <label>{translations.excludeDeferJS || 'Exclude Defer JavaScript'}</label>
          <textarea
            value={excludeDeferJS}
            onChange={(e) => onUpdateSettings('file_optimisation', 'excludeDeferJS', e.target.value)}
          />
        </div>
      </div>
      <div className="wppo-card">
        <h2>{translations.delay || 'Delay'}</h2>
        <div className="wppo-form-group">
          <label>
            <input
              type="checkbox"
              checked={delayJS}
              onChange={(e) => onUpdateSettings('file_optimisation', 'delayJS', e.target.checked)}
            />
            {translations.delayJS || 'Delay JavaScript'}
          </label>
        </div>
        <div className="wppo-form-group">
          <label>{translations.excludeDelayJS || 'Exclude Delay JavaScript'}</label>
          <textarea
            value={excludeDelayJS}
            onChange={(e) => onUpdateSettings('file_optimisation', 'excludeDelayJS', e.target.value)}
          />
        </div>
      </div>
      <button
        className="wppo-button submit-button"
        onClick={() => saveSettingsForTab('file_optimisation')}
        disabled={isLoading}
        style={{ marginTop: '20px' }}
      >
        {isLoading ? (translations.saving || 'Saving...') : (translations.saveSettings || 'Save Settings')}
      </button>
    </div>
  );
}

export default FileOptimization;
