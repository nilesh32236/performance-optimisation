import React from 'react';

function Tools({ adminData, isLoading, setIsLoading, setCacheSize, setImageInfo }) {
  const { translations = {}, apiUrl, nonce } = adminData || {};

  const handleToolAction = async (endpoint) => {
    setIsLoading(true);
    try {
      const response = await fetch(`${apiUrl}${endpoint}`, {
        method: 'POST',
        headers: {
          'X-WP-Nonce': nonce,
        },
      });
      const result = await response.json();
      if (result.success) {
        if (result.data.cache_size) {
          setCacheSize(result.data.cache_size);
        }
        if (result.data.image_info) {
          setImageInfo(result.data.image_info);
        }
      }
    } catch (error) {
      console.error(`Error with ${endpoint}:`, error);
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div>
      <h1>{translations.tools || 'Tools'}</h1>
      <div className="wppo-card">
        <h2>{translations.cache || 'Cache'}</h2>
        <button
          className="wppo-button"
          onClick={() => handleToolAction('clear-all-cache')}
          disabled={isLoading}
        >
          {isLoading ? (translations.clearingCache || 'Clearing Cache...') : (translations.clearCacheNow || 'Clear All Cache Now')}
        </button>
      </div>
      <div className="wppo-card">
        <h2>{translations.imageOptimization || 'Image Optimization'}</h2>
        <button
          className="wppo-button"
          onClick={() => handleToolAction('optimize-images')}
          disabled={isLoading}
        >
          {isLoading ? (translations.optimizingImages || 'Optimizing Images...') : (translations.optimiseImagesNow || 'Optimize Pending Images Now')}
        </button>
        <button
          className="wppo-button"
          onClick={() => handleToolAction('delete-optimized-images')}
          disabled={isLoading}
        >
          {isLoading ? (translations.deletingImages || 'Deleting Images...') : (translations.deleteOptimizedImages || 'Delete All Converted Images')}
        </button>
      </div>
    </div>
  );
}

export default Tools;
