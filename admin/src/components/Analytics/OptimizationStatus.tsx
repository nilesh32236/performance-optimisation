/**
 * Optimization Status Component - Refactored
 */

/**
 * External dependencies
 */
import React from 'react';
import { Dashicon } from '@wordpress/components';
import { Card } from '../UI';

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
        <Card title="Active Optimizations" className="h-full">
            <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                {Object.entries(features).map(([name, isActive]) => (
                    <div 
                        key={name} 
                        className={`flex flex-col items-center justify-center p-4 rounded-lg border transition-colors ${
                            isActive 
                                ? 'bg-green-50 border-green-100 text-green-800' 
                                : 'bg-gray-50 border-gray-100 text-gray-400'
                        }`}
                    >
                        <div className={`mb-2 p-2 rounded-full ${isActive ? 'bg-green-200' : 'bg-gray-200'}`}>
                            <Dashicon icon={isActive ? 'yes' : 'no'} />
                        </div>
                        <span className="text-sm font-medium text-center">{name}</span>
                        <span className="text-xs mt-1 opacity-75">{isActive ? 'Active' : 'Inactive'}</span>
                    </div>
                ))}
            </div>
        </Card>
    );
};

export default OptimizationStatus;