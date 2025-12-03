import React from 'react';
import { TextareaControl } from '@wordpress/components';

interface CacheExclusionSettingsProps {
    settings: {
        urls: string[];
        cookies: string[];
        user_agents: string[];
    };
    onChange: (key: string, value: string[]) => void;
    disabled?: boolean;
}

export const CacheExclusionSettings: React.FC<CacheExclusionSettingsProps> = ({ settings, onChange, disabled }) => {
    const handleChange = (key: string, value: string) => {
        const lines = value.split('\n').map(line => line.trim()).filter(line => line !== '');
        onChange(key, lines);
    };

    return (
        <div className="space-y-6">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <h4 className="font-semibold text-slate-800 mb-2">Exclude URLs</h4>
                    <p className="text-xs text-slate-500 mb-2">
                        Enter URL paths to exclude from caching (one per line).
                        <br />
                        Supports wildcards (*) and regex (start/end with /).
                    </p>
                    <TextareaControl
                        value={settings.urls.join('\n')}
                        onChange={(value: string) => handleChange('urls', value)}
                        disabled={disabled}
                        rows={5}
                        className="w-full"
                        help="Example: /checkout, /my-account/*, /custom-.*-page/"
                    />
                </div>

                <div>
                    <h4 className="font-semibold text-slate-800 mb-2">Exclude Cookies</h4>
                    <p className="text-xs text-slate-500 mb-2">
                        Exclude pages when these cookies are present.
                        <br />
                        Partial matches supported.
                    </p>
                    <TextareaControl
                        value={settings.cookies.join('\n')}
                        onChange={(value: string) => handleChange('cookies', value)}
                        disabled={disabled}
                        rows={5}
                        className="w-full"
                        help="Example: woocommerce_items_in_cart, wordpress_logged_in"
                    />
                </div>

                <div className="md:col-span-2">
                    <h4 className="font-semibold text-slate-800 mb-2">Exclude User Agents</h4>
                    <p className="text-xs text-slate-500 mb-2">
                        Exclude pages for specific user agents (bots, crawlers).
                    </p>
                    <TextareaControl
                        value={settings.user_agents.join('\n')}
                        onChange={(value: string) => handleChange('user_agents', value)}
                        disabled={disabled}
                        rows={3}
                        className="w-full"
                        help="Example: Googlebot, FacebookExternalHit"
                    />
                </div>
            </div>
        </div>
    );
};
