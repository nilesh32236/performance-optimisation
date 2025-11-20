/**
 * Optimization Status Component - Refactored
 */

/**
 * External dependencies
 */
import React from 'react';
import { Panel, PanelBody, Dashicon } from '@wordpress/components';

/**
 * Internal dependencies
 */
import './OptimizationStatus.scss';

interface OptimizationStatusProps {
    config: {
        settings?: {
            cache?: { page_cache?: boolean };
            optimization?: { minify_css?: boolean, minify_js?: boolean };
            images?: { lazy_loading?: boolean, convert_to_webp?: boolean };
        };
    };
}

const OptimizationStatus: React.FC<OptimizationStatusProps> = ( { config } ) => {
    const features = {
        'Page Caching': config?.settings?.cache?.page_cache || false,
        'CSS Minification': config?.settings?.optimization?.minify_css || false,
        'JavaScript Minification': config?.settings?.optimization?.minify_js || false,
        'Image Lazy Loading': config?.settings?.images?.lazy_loading || false,
        'WebP Conversion': config?.settings?.images?.convert_to_webp || false,
    };

    return (
        <Panel header="Optimization Status">
            <PanelBody>
                <ul className="wppo-optimization-status-list">
                    {Object.entries(features).map(([name, isActive]) => (
                        <li key={name} className="wppo-optimization-status-item">
                            <Dashicon icon={isActive ? 'yes-alt' : 'dismiss'} className={isActive ? 'is-active' : 'is-inactive'} />
                            <span>{name}</span>
                        </li>
                    ))}
                </ul>
            </PanelBody>
        </Panel>
    );
};

export default OptimizationStatus;