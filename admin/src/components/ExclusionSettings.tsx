import React from 'react';
import { TextareaControl } from '@wordpress/components';

interface ExclusionSettingsProps {
    settings: {
        exclude_css: string[];
        exclude_js: string[];
        exclude_css_files: string[];
        exclude_js_files: string[];
    };
    onChange: (key: string, value: string[]) => void;
    disabled?: boolean;
}

export const ExclusionSettings: React.FC<ExclusionSettingsProps> = ({ settings, onChange, disabled }) => {
    const handleChange = (key: string, value: string) => {
        const lines = value.split('\n').map(line => line.trim()).filter(line => line !== '');
        onChange(key, lines);
    };

    return (
        <div className="space-y-6">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <h4 className="font-semibold text-slate-800 mb-2">Exclude CSS Handles</h4>
                    <p className="text-sm text-slate-600 mb-3">
                        Enter one handle per line. Supports wildcards (*) and regex (start/end with /).
                    </p>
                    <TextareaControl
                        value={settings.exclude_css.join('\n')}
                        onChange={(value: string) => handleChange('exclude_css', value)}
                        disabled={disabled}
                        rows={5}
                        className="w-full"
                        help="Example: wp-block-library, *-style, /custom-.*-css/"
                    />
                </div>
                <div>
                    <h4 className="font-semibold text-slate-800 mb-2">Exclude JS Handles</h4>
                    <p className="text-sm text-slate-600 mb-3">
                        Enter one handle per line. Supports wildcards (*) and regex (start/end with /).
                    </p>
                    <TextareaControl
                        value={settings.exclude_js.join('\n')}
                        onChange={(value: string) => handleChange('exclude_js', value)}
                        disabled={disabled}
                        rows={5}
                        className="w-full"
                        help="Example: jquery, *-script, /custom-.*-js/"
                    />
                </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <h4 className="font-semibold text-slate-800 mb-2">Exclude CSS Files</h4>
                    <p className="text-sm text-slate-600 mb-3">
                        Enter one file path/URL pattern per line.
                    </p>
                    <TextareaControl
                        value={settings.exclude_css_files.join('\n')}
                        onChange={(value: string) => handleChange('exclude_css_files', value)}
                        disabled={disabled}
                        rows={5}
                        className="w-full"
                        help="Example: /wp-content/plugins/my-plugin/style.css, *custom.css"
                    />
                </div>
                <div>
                    <h4 className="font-semibold text-slate-800 mb-2">Exclude JS Files</h4>
                    <p className="text-sm text-slate-600 mb-3">
                        Enter one file path/URL pattern per line.
                    </p>
                    <TextareaControl
                        value={settings.exclude_js_files.join('\n')}
                        onChange={(value: string) => handleChange('exclude_js_files', value)}
                        disabled={disabled}
                        rows={5}
                        className="w-full"
                        help="Example: /wp-content/themes/my-theme/script.js, *custom.js"
                    />
                </div>
            </div>
        </div>
    );
};
