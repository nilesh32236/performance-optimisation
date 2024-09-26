import React, { useState } from 'react';
import { handleChange, handleSubmit } from '../lib/formUtils';

const PreloadSettings = ({ options }) => {
    const [settings, setSettings] = useState({
        enablePreloadCache: options?.enablePreloadCache || false,
        excludePreloadCache: options?.excludePreloadCache || '',
        preloadLinks: options?.preloadLinks || false,
        excludePreloadLinks: options?.excludePreloadLinks || '',
        prefetchDNS: options?.prefetchDNS || false,
        excludeDNS: options?.excludeDNS || '',
        preloadFonts: options?.preloadFonts || false,
        excludeFonts: options?.excludeFonts || ''
    });

    const [isLoading, setIsLoading] = useState(false);
    const onSubmit = async (e) => {
        e.preventDefault();
        setIsLoading(true); // Start the loading state

        try {
            await handleSubmit(settings, 'preload_settings');
        } catch (error) {
            console.error('Form submission error:', error);
        } finally {
            setIsLoading(false);
        }
    }
    return (
        <form onSubmit={onSubmit} className="settings-form">
            <h2>Preload Settings</h2>

            {/* Preload Cache */}
            <div className="checkbox-option">
                <label>
                    <input
                        type="checkbox"
                        name="enablePreloadCache"
                        checked={settings.enablePreloadCache}
                        onChange={handleChange(setSettings)}
                    />
                    Enable Preloading Cache
                </label>
                <p className="option-description">
                    Preload the cache to improve page load times by caching key resources.
                </p>
                {settings.enablePreloadCache && (
                    <textarea
                        className="text-area-field"
                        placeholder="Exclude specific resources from preloading"
                        name="excludePreloadCache"
                        value={settings.excludePreloadCache}
                        onChange={handleChange(setSettings)}
                    />
                )}
            </div>

            {/* Preload Links */}
            <div className="checkbox-option">
                <label>
                    <input
                        type="checkbox"
                        name="preloadLinks"
                        checked={settings.preloadLinks}
                        onChange={handleChange(setSettings)}
                    />
                    Preload Links
                </label>
                <p className="option-description">
                    Preload links to anticipate user navigation and load content faster.
                </p>
                {settings.preloadLinks && (
                    <textarea
                        className="text-area-field"
                        placeholder="Exclude specific links from preloading"
                        name="excludePreloadLinks"
                        value={settings.excludePreloadLinks}
                        onChange={handleChange(setSettings)}
                    />
                )}
            </div>

            {/* DNS Prefetch */}
            <div className="checkbox-option">
                <label>
                    <input
                        type="checkbox"
                        name="prefetchDNS"
                        checked={settings.prefetchDNS}
                        onChange={handleChange(setSettings)}
                    />
                    Prefetch DNS
                </label>
                <p className="option-description">
                    Prefetch DNS for external domains to reduce DNS lookup times.
                </p>
                {settings.prefetchDNS && (
                    <textarea
                        className="text-area-field"
                        placeholder="Exclude specific domains from DNS prefetching"
                        name="excludeDNS"
                        value={settings.excludeDNS}
                        onChange={handleChange(setSettings)}
                    />
                )}
            </div>

            {/* Preload Fonts */}
            <div className="checkbox-option">
                <label>
                    <input
                        type="checkbox"
                        name="preloadFonts"
                        checked={settings.preloadFonts}
                        onChange={handleChange(setSettings)}
                    />
                    Preload Fonts
                </label>
                <p className="option-description">
                    Preload fonts to ensure faster loading and rendering of text.
                </p>
                {settings.preloadFonts && (
                    <textarea
                        className="text-area-field"
                        placeholder="Exclude specific fonts from preloading"
                        name="excludeFonts"
                        value={settings.excludeFonts}
                        onChange={handleChange(setSettings)}
                    />
                )}
            </div>

            <button type="submit" className="submit-button" disabled={isLoading}>
                {isLoading ? 'Saving...' : 'Save Settings'}
            </button>
        </form>
    );
};

export default PreloadSettings;
