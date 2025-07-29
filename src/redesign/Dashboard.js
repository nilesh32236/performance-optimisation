import React from 'react';

function Dashboard({ adminData }) {
  const {
    translations = {},
    cacheSize = '0 B',
    minifiedAssets = { js: 0, css: 0 },
    imageInfo = { pending: { webp: [], avif: [] }, completed: { webp: [], avif: [] }, failed: { webp: [], avif: [] }, skipped: { webp: [], avif: [] } },
  } = adminData || {};

  return (
    <div>
      <h1>{translations.dashboard || 'Dashboard'}</h1>
      <div className="wppo-dashboard-cards">
        <div className="wppo-card">
          <h2>{translations.cacheStatus || 'Cache Status'}</h2>
          <p>{translations.currentCacheSize || 'Current Cache Size:'} {cacheSize}</p>
        </div>
        <div className="wppo-card">
          <h2>{translations.minifiedFiles || 'Minified Files'}</h2>
          <p>{translations.jsFilesMinified || 'JavaScript Files Minified:'} {minifiedAssets.js}</p>
          <p>{translations.cssFilesMinified || 'CSS Files Minified:'} {minifiedAssets.css}</p>
        </div>
        <div className="wppo-card">
          <h2>{translations.imageConversionStatus || 'Image Conversion Status'}</h2>
          <p>{translations.completed || 'Completed'}: {imageInfo.completed.webp.length + imageInfo.completed.avif.length}</p>
          <p>{translations.pending || 'Pending'}: {imageInfo.pending.webp.length + imageInfo.pending.avif.length}</p>
          <p>{translations.failed || 'Failed'}: {imageInfo.failed.webp.length + imageInfo.failed.avif.length}</p>
          <p>{translations.skipped || 'Skipped'}: {imageInfo.skipped.webp.length + imageInfo.skipped.avif.length}</p>
        </div>
      </div>
    </div>
  );
}

export default Dashboard;
