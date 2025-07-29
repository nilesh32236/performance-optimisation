import React from 'react';

function Database({ adminData, onUpdateSettings, specificSettings, isLoading, saveSettingsForTab }) {
  const { translations = {} } = adminData || {};
  const {
    revisions,
    spam_comments,
    transients,
  } = specificSettings;

  return (
    <div>
      <h1>{translations.database || 'Database'}</h1>
      <div className="wppo-card">
        <h2>{translations.databaseCleanup || 'Database Cleanup'}</h2>
        <div className="wppo-form-group">
          <label>
            <input
              type="checkbox"
              checked={revisions}
              onChange={(e) => onUpdateSettings('database', 'revisions', e.target.checked)}
            />
            {translations.deleteRevisions || 'Delete Post Revisions'}
          </label>
        </div>
        <div className="wppo-form-group">
          <label>
            <input
              type="checkbox"
              checked={spam_comments}
              onChange={(e) => onUpdateSettings('database', 'spam_comments', e.target.checked)}
            />
            {translations.deleteSpamComments || 'Delete Spam Comments'}
          </label>
        </div>
        <div className="wppo-form-group">
          <label>
            <input
              type="checkbox"
              checked={transients}
              onChange={(e) => onUpdateSettings('database', 'transients', e.target.checked)}
            />
            {translations.deleteTransients || 'Delete Transients'}
          </label>
        </div>
      </div>
      <button
        className="wppo-button submit-button"
        onClick={() => saveSettingsForTab('database')}
        disabled={isLoading}
        style={{ marginTop: '20px' }}
      >
        {isLoading ? (translations.saving || 'Saving...') : (translations.saveSettings || 'Save Settings')}
      </button>
    </div>
  );
}

export default Database;
