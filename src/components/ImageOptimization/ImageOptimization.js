// src/components/ImageOptimization/ImageOptimization.js

import React from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faImages, faExclamationTriangle, faCogs } from '@fortawesome/free-solid-svg-icons';
import Select from 'react-select'; // Assuming you've installed react-select

const ImageOptimization = ({
  settings, // The whole settings object from App.js
  onUpdateSettings, // Function to update settings: (tabKey, settingKey, value)
  uiData, // Contains availablePostTypes
  translations,
  // isLoading,
}) => {
  const imgOptSettings = settings.image_optimisation || {};

  const handleChange = (settingKey, value, type = 'checkbox') => {
    let processedValue = value;
    if (type === 'checkbox') {
      processedValue = !!value;
    }
    // Ensure that the settingKey corresponds to a direct key in image_optimisation
    onUpdateSettings('image_optimisation', settingKey, processedValue);
  };

  const handleMultiSelectChange = (settingKey, selectedOptions) => {
    const values = selectedOptions ? selectedOptions.map(option => option.value) : [];
    onUpdateSettings('image_optimisation', settingKey, values);
  };

  const availablePostTypesOptions = uiData.availablePostTypes?.map(pt => ({
    value: pt.value, // Assuming pt.value is the slug
    label: pt.label,
  })) || [];

  const selectedPostTypesValue = availablePostTypesOptions.filter(option =>
    (imgOptSettings.selectedPostType || []).includes(option.value)
  );

  return (
    <div className="wppo-settings-form wppo-image-optimization-settings">
      <h2 className="wppo-section-title">
        <FontAwesomeIcon icon={faImages} style={{ marginRight: '10px' }} />
        {translations.imageOptimization || 'Image Optimization'}
      </h2>
      <p className="wppo-section-description">
        {translations.imageOptimizationDesc || 'Configure lazy loading, image conversion, and preloading strategies for your site\'s images.'}
      </p>

      {/* Lazy Loading */}
      <div className="wppo-form-section">
        <h3>{translations.lazyLoadSectionTitle || 'Lazy Loading'}</h3>
        <div className="wppo-field-group wppo-checkbox-option">
          <input
            type="checkbox"
            id="lazyLoadImages"
            checked={imgOptSettings.lazyLoadImages || false}
            onChange={(e) => handleChange('lazyLoadImages', e.target.checked)}
          />
          <label htmlFor="lazyLoadImages">{translations.lazyLoadImages || 'Lazy Load Images'}</label>
        </div>
        {imgOptSettings.lazyLoadImages && (
          <div className="wppo-sub-fields">
            <label htmlFor="excludeImages" className="wppo-label">{translations.excludeImages || 'Exclude Images/Keywords from Lazy Loading:'}</label>
            <textarea
              id="excludeImages"
              className="wppo-text-area-field"
              value={imgOptSettings.excludeImages || ''}
              onChange={(e) => handleChange('excludeImages', e.target.value, 'textarea')}
              rows="3"
              placeholder={translations.excludeImagesHelpText || "e.g., /logo.png, critical-image-class, /path/to/specific-slider/"}
            />
            <p className="wppo-option-description">{translations.lazyLoadImagesDesc || 'Prevents specific images from being lazy-loaded. Useful for above-the-fold content.'}</p>

            <label htmlFor="excludeFistImages" className="wppo-label" style={{ marginTop: '15px' }}>{translations.excludeFistImages || 'Exclude First N Images from Lazy Loading:'}</label>
            <input
              type="number"
              id="excludeFistImages"
              className="wppo-input-field"
              value={imgOptSettings.excludeFistImages || 0}
              onChange={(e) => handleChange('excludeFistImages', parseInt(e.target.value, 10) || 0, 'number')}
              min="0"
              style={{ maxWidth: '100px' }}
            />
            <p className="wppo-option-description">{translations.excludeFistImagesDesc || 'Number of images from the top of the page to exclude from lazy loading.'}</p>

            <div className="wppo-field-group wppo-checkbox-option" style={{ marginTop: '15px' }}>
              <input
                type="checkbox"
                id="replacePlaceholderWithSVG"
                checked={imgOptSettings.replacePlaceholderWithSVG || false}
                onChange={(e) => handleChange('replacePlaceholderWithSVG', e.target.checked)}
              />
              <label htmlFor="replacePlaceholderWithSVG">{translations.replaceImgToSVG || 'Use SVG Placeholders for Lazy Loaded Images'}</label>
            </div>
            <p className="wppo-option-description" style={{ marginTop: '-10px' }}>{translations.replaceImgToSVGDesc || 'Replaces the low-quality image placeholder with a lightweight SVG, matching image dimensions.'}</p>
          </div>
        )}
        {/* Lazy Load Videos Option */}
        <div className="wppo-field-group wppo-checkbox-option" style={{ marginTop: '15px' }}>
          <input
            type="checkbox"
            id="lazyLoadVideos"
            checked={imgOptSettings.lazyLoadVideos || false}
            onChange={(e) => handleChange('lazyLoadVideos', e.target.checked)}
          />
          <label htmlFor="lazyLoadVideos">{translations.lazyLoadVideos || 'Lazy Load Videos (iframes & video tags)'}</label>
        </div>
        {imgOptSettings.lazyLoadVideos && (
          <div className="wppo-sub-fields">
            <label htmlFor="excludeVideos" className="wppo-label">{translations.excludeVideos || 'Exclude Videos/Keywords from Lazy Loading:'}</label>
            <textarea
              id="excludeVideos"
              className="wppo-text-area-field"
              value={imgOptSettings.excludeVideos || ''}
              onChange={(e) => handleChange('excludeVideos', e.target.value, 'textarea')}
              rows="3"
              placeholder={translations.excludeVideosHelpText || "e.g., youtube.com/embed/specific-id, vimeo-player-class"}
            />
            <p className="wppo-option-description">{translations.excludeVideosDesc || 'Prevents specific videos (iframes or video tags with matching src/class) from being lazy-loaded.'}</p>
          </div>
        )}
      </div>


      {/* Image Conversion */}
      <div className="wppo-form-section">
        <h3>
          <FontAwesomeIcon icon={faCogs} style={{ marginRight: '8px' }} />
          {translations.imageConversionSectionTitle || 'Next-Gen Image Conversion'}
        </h3>
        <div className="wppo-field-group wppo-checkbox-option">
          <input
            type="checkbox"
            id="convertImg"
            checked={imgOptSettings.convertImg || false}
            onChange={(e) => handleChange('convertImg', e.target.checked)}
          />
          <label htmlFor="convertImg">{translations.convertImg || 'Enable Next-Gen Image Conversion (WebP/AVIF)'}</label>
        </div>
        {imgOptSettings.convertImg && (
          <div className="wppo-sub-fields">
            <label htmlFor="conversionFormat" className="wppo-label">{translations.conversionFormat || 'Preferred Conversion Format:'}</label>
            <select
              id="conversionFormat"
              className="wppo-select-field"
              value={imgOptSettings.conversionFormat || 'webp'}
              onChange={(e) => handleChange('conversionFormat', e.target.value, 'select')}
            >
              <option value="webp">{translations.webpOnly || 'WebP Only'}</option>
              <option value="avif">{translations.avifOnly || 'AVIF Only (fallback to WebP/Original if not supported)'}</option>
              <option value="both">{translations.bothFormats || 'Both (Serve AVIF if supported, else WebP, else Original)'}</option>
            </select>
            <p className="wppo-option-description">{translations.conversionFormatDesc || 'AVIF generally offers better compression but has less browser support than WebP.'}</p>

            <label htmlFor="excludeConvertImages" className="wppo-label" style={{ marginTop: '15px' }}>{translations.excludeConvertImages || 'Exclude Images/Keywords from Conversion:'}</label>
            <textarea
              id="excludeConvertImages"
              className="wppo-text-area-field"
              value={imgOptSettings.excludeConvertImages || ''}
              onChange={(e) => handleChange('excludeConvertImages', e.target.value, 'textarea')}
              rows="3"
              placeholder={translations.excludeConvertImagesHelpText || "e.g., /gifs/, specific-logo.png"}
            />
            <p className="wppo-option-description">{translations.excludeConvertImagesDesc || 'Images matching these keywords or paths will not be converted.'}</p>

            <label htmlFor="imgBatchSize" className="wppo-label" style={{ marginTop: '15px' }}>{translations.imgBatchSize || 'Image Conversion Batch Size:'}</label>
            <input
              type="number"
              id="imgBatchSize"
              className="wppo-input-field"
              value={imgOptSettings.batch || 50} // Assuming 'batch' is the key in settings
              onChange={(e) => handleChange('batch', parseInt(e.target.value, 10) || 50, 'number')}
              min="1"
              max="200" // Reasonable max
              style={{ maxWidth: '100px' }}
            />
            <p className="wppo-option-description">{translations.imgBatchSizeDesc || 'Number of images processed per cron run. Higher values process faster but use more resources.'}</p>
            <p className="wppo-option-description wppo-warning-text">
              <FontAwesomeIcon icon={faExclamationTriangle} /> {translations.ensureGdImagick || 'Ensure your server has GD library with WebP/AVIF support, or Imagick extension, for conversion to work.'}
            </p>
          </div>
        )}
      </div>

      {/* Image Preloading */}
      <div className="wppo-form-section">
        <h3>{translations.imagePreloadingSectionTitle || 'Image Preloading'}</h3>
        <div className="wppo-field-group wppo-checkbox-option">
          <input
            type="checkbox"
            id="preloadFrontPageImages"
            checked={imgOptSettings.preloadFrontPageImages || false}
            onChange={(e) => handleChange('preloadFrontPageImages', e.target.checked)}
          />
          <label htmlFor="preloadFrontPageImages">{translations.preloadFrontPageImg || 'Preload Critical Images on Front Page'}</label>
        </div>
        {imgOptSettings.preloadFrontPageImages && (
          <div className="wppo-sub-fields">
            <label htmlFor="preloadFrontPageImagesUrls" className="wppo-label">{translations.preloadFrontPageImgUrl || 'Front Page Image URLs to Preload (one per line):'}</label>
            <textarea
              id="preloadFrontPageImagesUrls"
              className="wppo-text-area-field"
              value={imgOptSettings.preloadFrontPageImagesUrls || ''}
              onChange={(e) => handleChange('preloadFrontPageImagesUrls', e.target.value, 'textarea')}
              rows="3"
              placeholder={translations.preloadFrontPageImgUrlHelpText || "e.g., /wp-content/uploads/hero.jpg\nmobile:/uploads/hero-sm.jpg"}
            />
            <p className="wppo-option-description">{translations.preloadFrontPageImgDesc || 'Specify images critical for the first view of your front page. Use mobile:/desktop: prefixes for device-specific preloads.'}</p>
          </div>
        )}

        <div className="wppo-field-group wppo-checkbox-option" style={{ marginTop: '15px' }}>
          <input
            type="checkbox"
            id="preloadPostTypeImage"
            checked={imgOptSettings.preloadPostTypeImage || false}
            onChange={(e) => handleChange('preloadPostTypeImage', e.target.checked)}
          />
          <label htmlFor="preloadPostTypeImage">{translations.preloadPostTypeImg || 'Preload Featured Images for Specific Post Types'}</label>
        </div>
        {imgOptSettings.preloadPostTypeImage && (
          <div className="wppo-sub-fields">
            <label htmlFor="selectedPostType" className="wppo-label">{translations.selectPostTypes || 'Select Post Types:'}</label>
            <Select
              id="selectedPostType"
              options={availablePostTypesOptions}
              isMulti
              value={selectedPostTypesValue}
              onChange={(selected) => handleMultiSelectChange('selectedPostType', selected)}
              className="wppo-react-select-container"
              classNamePrefix="wppo-react-select"
              aria-label={translations.selectPostTypes || 'Select Post Types'}
            />
            <p className="wppo-option-description">{translations.preloadPostTypeImgDesc || 'Featured images for selected post types will be preloaded on their single views.'}</p>

            <label htmlFor="excludePostTypeImgUrl" className="wppo-label" style={{ marginTop: '15px' }}>{translations.excludePostTypeImgUrl || 'Exclude Featured Images from Preloading (Keywords/Paths):'}</label>
            <textarea
              id="excludePostTypeImgUrl"
              className="wppo-text-area-field"
              value={imgOptSettings.excludePostTypeImgUrl || ''}
              onChange={(e) => handleChange('excludePostTypeImgUrl', e.target.value, 'textarea')}
              rows="2"
              placeholder={translations.excludePostTypeImgUrlHelpText || "e.g., small-thumbnail.jpg"}
            />
            <p className="wppo-option-description">{translations.excludePostTypeImgUrlDesc || 'Featured images matching these will not be preloaded.'}</p>

            <label htmlFor="maxWidthImgSize" className="wppo-label" style={{ marginTop: '15px' }}>{translations.maxWidthImgSize || 'Max Width for Preloaded Srcset Images (px, 0 for no limit):'}</label>
            <input
              type="number"
              id="maxWidthImgSize"
              className="wppo-input-field"
              value={imgOptSettings.maxWidthImgSize || 0}
              onChange={(e) => handleChange('maxWidthImgSize', parseInt(e.target.value, 10) || 0, 'number')}
              min="0"
              style={{ maxWidth: '100px' }}
            />
            <p className="wppo-option-description">{translations.maxWidthImgSizeDesc || 'Limits preloading of srcset images to those at or below this width.'}</p>

            <label htmlFor="excludeSize" className="wppo-label" style={{ marginTop: '15px' }}>{translations.excludeSize || 'Exclude Specific Srcset Image Widths from Preloading (px, one per line):'}</label>
            <textarea
              id="excludeSize"
              className="wppo-text-area-field"
              value={imgOptSettings.excludeSize || ''}
              onChange={(e) => handleChange('excludeSize', e.target.value, 'textarea')}
              rows="2"
              placeholder={translations.excludeSizeHelpText || "e.g., 150\n300"}
            />
            <p className="wppo-option-description">{translations.excludeSizeDesc || 'Images with these exact widths in srcset will not be preloaded.'}</p>
          </div>
        )}
      </div>
    </div>
  );
};

export default ImageOptimization;