import React from 'react';

function ImageOptimization({ adminData, onUpdateSettings, specificSettings, isLoading, saveSettingsForTab }) {
  const { translations = {}, uiData = {} } = adminData || {};
  const {
    lazyLoadImages,
    lazyLoadVideos,
    excludeFistImages,
    replacePlaceholderWithSVG,
    convertImg,
    conversionFormat,
    excludeConvertImages,
    preloadFrontPageImages,
    preloadFrontPageImagesUrls,
    preloadPostTypeImage,
    selectedPostType,
    excludePostTypeImgUrl,
    maxWidthImgSize,
  } = specificSettings;
  const { availablePostTypes = [] } = uiData;

  return (
    <div>
      <h1>{translations.imageOptimization || 'Image Optimization'}</h1>
      <div className="wppo-card">
        <h2>{translations.lazyLoad || 'Lazy Load'}</h2>
        <div className="wppo-form-group">
          <label>
            <input
              type="checkbox"
              checked={lazyLoadImages}
              onChange={(e) => onUpdateSettings('image_optimisation', 'lazyLoadImages', e.target.checked)}
            />
            {translations.lazyLoadImages || 'Lazy Load Images'}
          </label>
        </div>
        <div className="wppo-form-group">
          <label>
            <input
              type="checkbox"
              checked={lazyLoadVideos}
              onChange={(e) => onUpdateSettings('image_optimisation', 'lazyLoadVideos', e.target.checked)}
            />
            {translations.lazyLoadVideos || 'Lazy Load Videos'}
          </label>
        </div>
        <div className="wppo-form-group">
          <label>{translations.excludeFistImages || 'Exclude First N Images'}</label>
          <input
            type="number"
            value={excludeFistImages}
            onChange={(e) => onUpdateSettings('image_optimisation', 'excludeFistImages', e.target.value)}
          />
        </div>
        <div className="wppo-form-group">
          <label>
            <input
              type="checkbox"
              checked={replacePlaceholderWithSVG}
              onChange={(e) => onUpdateSettings('image_optimisation', 'replacePlaceholderWithSVG', e.target.checked)}
            />
            {translations.replacePlaceholderWithSVG || 'Replace Placeholder With SVG'}
          </label>
        </div>
      </div>
      <div className="wppo-card">
        <h2>{translations.imageConversion || 'Image Conversion'}</h2>
        <div className="wppo-form-group">
          <label>
            <input
              type="checkbox"
              checked={convertImg}
              onChange={(e) => onUpdateSettings('image_optimisation', 'convertImg', e.target.checked)}
            />
            {translations.convertImg || 'Convert Images to WebP/AVIF'}
          </label>
        </div>
        <div className="wppo-form-group">
          <label>{translations.conversionFormat || 'Conversion Format'}</label>
          <select
            value={conversionFormat}
            onChange={(e) => onUpdateSettings('image_optimisation', 'conversionFormat', e.target.value)}
          >
            <option value="webp">{translations.webp || 'WebP'}</option>
            <option value="avif">{translations.avif || 'AVIF'}</option>
            <option value="both">{translations.both || 'Both'}</option>
          </select>
        </div>
        <div className="wppo-form-group">
          <label>{translations.excludeConvertImages || 'Exclude Images from Conversion'}</label>
          <textarea
            value={excludeConvertImages}
            onChange={(e) => onUpdateSettings('image_optimisation', 'excludeConvertImages', e.target.value)}
          />
        </div>
      </div>
      <div className="wppo-card">
        <h2>{translations.preloadImages || 'Preload Images'}</h2>
        <div className="wppo-form-group">
          <label>
            <input
              type="checkbox"
              checked={preloadFrontPageImages}
              onChange={(e) => onUpdateSettings('image_optimisation', 'preloadFrontPageImages', e.target.checked)}
            />
            {translations.preloadFrontPageImages || 'Preload Front Page Images'}
          </label>
        </div>
        <div className="wppo-form-group">
          <label>{translations.preloadFrontPageImagesUrls || 'Front Page Image URLs'}</label>
          <textarea
            value={preloadFrontPageImagesUrls}
            onChange={(e) => onUpdateSettings('image_optimisation', 'preloadFrontPageImagesUrls', e.target.value)}
          />
        </div>
        <div className="wppo-form-group">
          <label>
            <input
              type="checkbox"
              checked={preloadPostTypeImage}
              onChange={(e) => onUpdateSettings('image_optimisation', 'preloadPostTypeImage', e.target.checked)}
            />
            {translations.preloadPostTypeImage || 'Preload Post Type Image'}
          </label>
        </div>
        <div className="wppo-form-group">
          <label>{translations.selectedPostType || 'Selected Post Type'}</label>
          <select
            multiple
            value={selectedPostType}
            onChange={(e) => onUpdateSettings('image_optimisation', 'selectedPostType', Array.from(e.target.selectedOptions, option => option.value))}
          >
            {availablePostTypes.map(postType => (
              <option key={postType.value} value={postType.value}>{postType.label}</option>
            ))}
          </select>
        </div>
        <div className="wppo-form-group">
          <label>{translations.excludePostTypeImgUrl || 'Exclude Post Type Image URLs'}</label>
          <textarea
            value={excludePostTypeImgUrl}
            onChange={(e) => onUpdateSettings('image_optimisation', 'excludePostTypeImgUrl', e.target.value)}
          />
        </div>
        <div className="wppo-form-group">
          <label>{translations.maxWidthImgSize || 'Max Width Image Size'}</label>
          <input
            type="number"
            value={maxWidthImgSize}
            onChange={(e) => onUpdateSettings('image_optimisation', 'maxWidthImgSize', e.target.value)}
          />
        </div>
      </div>
      <button
        className="wppo-button submit-button"
        onClick={() => saveSettingsForTab('image_optimisation')}
        disabled={isLoading}
        style={{ marginTop: '20px' }}
      >
        {isLoading ? (translations.saving || 'Saving...') : (translations.saveSettings || 'Save Settings')}
      </button>
    </div>
  );
}

export default ImageOptimization;
