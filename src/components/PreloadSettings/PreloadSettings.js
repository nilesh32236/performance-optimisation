// src/components/PreloadSettings/PreloadSettings.js

import React from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faRocket, faLink, faFont, faPalette, faExclamationTriangle, faStopCircle } from '@fortawesome/free-solid-svg-icons';

const PreloadSettings = ({
    settings, // The whole settings object from App.js
    onUpdateSettings, // Function to update settings: (tabKey, settingKey, value)
    translations,
    // isLoading, // If needed for a save button specific to this tab
}) => {
    const preloadOptSettings = settings.preload_settings || {};

    const handleChange = (settingKey, value, type = 'checkbox') => {
        let processedValue = value;
        if (type === 'checkbox') {
            processedValue = !!value;
        }
        onUpdateSettings('preload_settings', settingKey, processedValue);
    };

    return (
        <div className="wppo-settings-form wppo-preload-settings">
            <h2 className="wppo-section-title">
                <FontAwesomeIcon icon={faRocket} style={{ marginRight: '10px' }} />
                {translations.preloadSettings || 'Preload & Preconnect'}
            </h2>
            <p className="wppo-section-description">
                {translations.preloadSettingsDesc || 'Optimize resource loading by preloading critical assets, preconnecting to essential third-party origins, and prefetching DNS.'}
            </p>

            {/* Page Preloading (Static Cache Generation) */}
            <div className="wppo-form-section">
                <h3>{translations.pagePreloadingTitle || 'Page Preloading (Static Cache)'}</h3>
                <div className="wppo-field-group wppo-checkbox-option">
                    <input
                        type="checkbox"
                        id="enablePreloadCache"
                        checked={preloadOptSettings.enablePreloadCache || false}
                        onChange={(e) => handleChange('enablePreloadCache', e.target.checked)}
                    />
                    <label htmlFor="enablePreloadCache">{translations.enablePreloadCache || 'Enable Page Preloading'}</label>
                </div>
                {preloadOptSettings.enablePreloadCache && (
                    <div className="wppo-sub-fields">
                        <label htmlFor="excludePreloadCache" className="wppo-label">{translations.excludePreloadCache || 'Exclude URLs/Paths from Preloading (one per line):'}</label>
                        <textarea
                            id="excludePreloadCache"
                            className="wppo-text-area-field"
                            value={preloadOptSettings.excludePreloadCache || ''}
                            onChange={(e) => handleChange('excludePreloadCache', e.target.value, 'textarea')}
                            rows="4"
                            placeholder={translations.excludePreloadCacheHelpText || "e.g., /do-not-cache-this-page/\n/some/path/(.*)\nhttps://example.com/specific-url"}
                        />
                        <p className="wppo-option-description">{translations.enablePreloadCacheDesc || 'Generates static HTML versions of your pages. Exclude dynamic pages or those with frequent updates. Use (.*) as a wildcard for sub-paths.'}</p>
                    </div>
                )}
            </div>

            {/* Cron Job Management */}
            <div className="wppo-form-section">
                <h3>{translations.cronJobManagementTitle || 'Automated Tasks (Cron Jobs)'}</h3>
                <div className="wppo-field-group wppo-checkbox-option">
                    <input
                        type="checkbox"
                        id="enableCronJobs"
                        checked={preloadOptSettings.enableCronJobs === undefined ? true : preloadOptSettings.enableCronJobs} // Default to true if undefined
                        onChange={(e) => handleChange('enableCronJobs', e.target.checked)}
                    />
                    <label htmlFor="enableCronJobs">{translations.enableCronJobs || 'Enable Plugin Cron Jobs'}</label>
                </div>
                <p className="wppo-option-description" style={{ marginLeft: '25px', marginTop: '-10px' }}>
                    {translations.enableCronJobsDesc || 'Required for automatic page preloading and scheduled image optimization. If disabled, these tasks must be triggered manually or will not run.'}
                </p>
                {!(preloadOptSettings.enableCronJobs === undefined ? true : preloadOptSettings.enableCronJobs) && (
                    <p className="wppo-option-description wppo-warning-text" style={{ marginLeft: '25px' }}>
                        <FontAwesomeIcon icon={faStopCircle} /> {translations.cronJobsDisabledWarning || 'Warning: Automated tasks like page preloading and background image optimization are disabled.'}
                    </p>
                )}
            </div>

            {/* Preconnect */}
            <div className="wppo-form-section">
                <h3>
                    <FontAwesomeIcon icon={faLink} style={{ marginRight: '8px' }} />
                    {translations.preconnect || 'Preconnect'}
                </h3>
                <div className="wppo-field-group wppo-checkbox-option">
                    <input
                        type="checkbox"
                        id="preconnectEnabled" // Assuming a general enable/disable for this feature group
                        checked={preloadOptSettings.preconnect || false}
                        onChange={(e) => handleChange('preconnect', e.target.checked)}
                    />
                    <label htmlFor="preconnectEnabled">{translations.enablePreconnect || 'Enable Preconnect'}</label>
                </div>
                {preloadOptSettings.preconnect && (
                    <div className="wppo-sub-fields">
                        <label htmlFor="preconnectOrigins" className="wppo-label">{translations.preconnectOrigins || 'Origins to Preconnect (one per line):'}</label>
                        <textarea
                            id="preconnectOrigins"
                            className="wppo-text-area-field"
                            value={preloadOptSettings.preconnectOrigins || ''}
                            onChange={(e) => handleChange('preconnectOrigins', e.target.value, 'textarea')}
                            rows="3"
                            placeholder={translations.preconnectOriginsHelpText || "e.g., https://fonts.gstatic.com\nhttps://www.googletagmanager.com"}
                        />
                        <p className="wppo-option-description">{translations.preconnectDesc || 'Speeds up connections to critical third-party domains by performing DNS lookup, TCP handshake, and TLS negotiation in advance.'}</p>
                    </div>
                )}
            </div>

            {/* DNS Prefetch */}
            <div className="wppo-form-section">
                <h3>
                    <FontAwesomeIcon icon={faLink} style={{ marginRight: '8px', transform: 'rotate(90deg)' }} />
                    {translations.prefetchDNS || 'DNS Prefetch'}
                </h3>
                <div className="wppo-field-group wppo-checkbox-option">
                    <input
                        type="checkbox"
                        id="prefetchDNSEnabled"
                        checked={preloadOptSettings.prefetchDNS || false}
                        onChange={(e) => handleChange('prefetchDNS', e.target.checked)}
                    />
                    <label htmlFor="prefetchDNSEnabled">{translations.enablePrefetchDNS || 'Enable DNS Prefetch'}</label>
                </div>
                {preloadOptSettings.prefetchDNS && (
                    <div className="wppo-sub-fields">
                        <label htmlFor="dnsPrefetchOrigins" className="wppo-label">{translations.dnsPrefetchOrigins || 'Domains for DNS Prefetching (one per line):'}</label>
                        <textarea
                            id="dnsPrefetchOrigins"
                            className="wppo-text-area-field"
                            value={preloadOptSettings.dnsPrefetchOrigins || ''}
                            onChange={(e) => handleChange('dnsPrefetchOrigins', e.target.value, 'textarea')}
                            rows="3"
                            placeholder={translations.dnsPrefetchOriginsHelpText || "e.g., //fonts.googleapis.com\n//cdnjs.cloudflare.com"}
                        />
                        <p className="wppo-option-description">{translations.prefetchDNSDesc || 'Resolves DNS for specified domains in advance. Use protocol-relative URLs (e.g., //example.com) or hostnames.'}</p>
                    </div>
                )}
            </div>

            {/* Preload Fonts */}
            <div className="wppo-form-section">
                <h3>
                    <FontAwesomeIcon icon={faFont} style={{ marginRight: '8px' }} />
                    {translations.preloadFonts || 'Preload Fonts'}
                </h3>
                <div className="wppo-field-group wppo-checkbox-option">
                    <input
                        type="checkbox"
                        id="preloadFontsEnabled"
                        checked={preloadOptSettings.preloadFonts || false}
                        onChange={(e) => handleChange('preloadFonts', e.target.checked)}
                    />
                    <label htmlFor="preloadFontsEnabled">{translations.enablePreloadFonts || 'Enable Font Preloading'}</label>
                </div>
                {preloadOptSettings.preloadFonts && (
                    <div className="wppo-sub-fields">
                        <label htmlFor="preloadFontsUrls" className="wppo-label">{translations.preloadFontsUrls || 'Font URLs to Preload (one per line):'}</label>
                        <textarea
                            id="preloadFontsUrls"
                            className="wppo-text-area-field"
                            value={preloadOptSettings.preloadFontsUrls || ''}
                            onChange={(e) => handleChange('preloadFontsUrls', e.target.value, 'textarea')}
                            rows="4"
                            placeholder={translations.preloadFontsUrlsHelpText || "e.g., /wp-content/themes/my-theme/fonts/font.woff2\nhttps://example.com/fonts/another-font.woff2"}
                        />
                        <p className="wppo-option-description">{translations.preloadFontsDesc || 'Specify full URLs or paths relative to your WordPress root for font files (WOFF2 recommended for modern browsers). Ensures fonts are loaded earlier, reducing FOUT/FOIT.'}</p>
                        <p className="wppo-option-description wppo-warning-text">
                            <FontAwesomeIcon icon={faExclamationTriangle} /> {translations.preloadFontsWarning || 'Warning: Preload fonts only if they are used on most pages or critical for initial rendering. Incorrect usage can negatively impact performance.'}
                        </p>
                    </div>
                )}
            </div>

            {/* Preload CSS */}
            <div className="wppo-form-section">
                <h3>
                    <FontAwesomeIcon icon={faPalette} style={{ marginRight: '8px' }} />
                    {translations.preloadCSS || 'Preload CSS Files'}
                </h3>
                <div className="wppo-field-group wppo-checkbox-option">
                    <input
                        type="checkbox"
                        id="preloadCSSEnabled"
                        checked={preloadOptSettings.preloadCSS || false}
                        onChange={(e) => handleChange('preloadCSS', e.target.checked)}
                    />
                    <label htmlFor="preloadCSSEnabled">{translations.enablePreloadCSS || 'Enable CSS File Preloading'}</label>
                </div>
                {preloadOptSettings.preloadCSS && (
                    <div className="wppo-sub-fields">
                        <label htmlFor="preloadCSSUrls" className="wppo-label">{translations.preloadCSSUrls || 'CSS File URLs to Preload (one per line):'}</label>
                        <textarea
                            id="preloadCSSUrls"
                            className="wppo-text-area-field"
                            value={preloadOptSettings.preloadCSSUrls || ''}
                            onChange={(e) => handleChange('preloadCSSUrls', e.target.value, 'textarea')}
                            rows="3"
                            placeholder={translations.preloadCSSUrlsHelpText || "e.g., /wp-content/themes/my-theme/critical-styles.css"}
                        />
                        <p className="wppo-option-description">{translations.preloadCSSDesc || 'Preload critical CSS files that are necessary for the initial rendering of the page.'}</p>
                    </div>
                )}
            </div>
        </div>
    );
};

export default PreloadSettings;