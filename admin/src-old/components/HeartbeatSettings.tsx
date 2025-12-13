import React from 'react';

interface HeartbeatSettingsProps {
    settings: {
        enabled: boolean;
        locations: {
            dashboard: number;
            post_edit: number;
            frontend: number;
        };
    };
    onChange: (key: string, value: any) => void;
    disabled?: boolean;
}

export const HeartbeatSettings: React.FC<HeartbeatSettingsProps> = ({ settings, onChange, disabled }) => {
    const frequencies = [
        { value: 15, label: '15 Seconds (Default for Post Edit)' },
        { value: 30, label: '30 Seconds' },
        { value: 60, label: '60 Seconds (Default for Dashboard)' },
        { value: 120, label: '120 Seconds' },
        { value: 300, label: '5 Minutes' },
        { value: 0, label: 'Disable Heartbeat' },
    ];

    const updateLocation = (location: 'dashboard' | 'post_edit' | 'frontend', value: number) => {
        onChange('locations', {
            ...settings.locations,
            [location]: value
        });
    };

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <div>
                    <h4 className="text-sm font-semibold text-slate-900">Enable Heartbeat Control</h4>
                    <p className="text-xs text-slate-500">Limit or disable the WordPress Heartbeat API to reduce server load.</p>
                </div>
                <label className="relative inline-flex items-center cursor-pointer">
                    <input
                        type="checkbox"
                        className="sr-only peer"
                        checked={settings.enabled}
                        onChange={(e) => onChange('enabled', e.target.checked)}
                        disabled={disabled}
                    />
                    <div className="w-9 h-5 bg-slate-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-red-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-red-600"></div>
                </label>
            </div>

            {settings.enabled && (
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4 pt-4 border-t border-slate-100">
                    <div className="space-y-2">
                        <label className="block text-xs font-medium text-slate-700">Dashboard Frequency</label>
                        <select
                            className="w-full p-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-red-500 focus:border-red-500"
                            value={settings.locations.dashboard}
                            onChange={(e) => updateLocation('dashboard', parseInt(e.target.value))}
                            disabled={disabled}
                        >
                            {frequencies.map(f => (
                                <option key={`dash-${f.value}`} value={f.value}>{f.label}</option>
                            ))}
                        </select>
                    </div>

                    <div className="space-y-2">
                        <label className="block text-xs font-medium text-slate-700">Post Editor Frequency</label>
                        <select
                            className="w-full p-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-red-500 focus:border-red-500"
                            value={settings.locations.post_edit}
                            onChange={(e) => updateLocation('post_edit', parseInt(e.target.value))}
                            disabled={disabled}
                        >
                            {frequencies.map(f => (
                                <option key={`post-${f.value}`} value={f.value}>{f.label}</option>
                            ))}
                        </select>
                    </div>

                    <div className="space-y-2">
                        <label className="block text-xs font-medium text-slate-700">Frontend Frequency</label>
                        <select
                            className="w-full p-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-red-500 focus:border-red-500"
                            value={settings.locations.frontend}
                            onChange={(e) => updateLocation('frontend', parseInt(e.target.value))}
                            disabled={disabled}
                        >
                            {frequencies.map(f => (
                                <option key={`front-${f.value}`} value={f.value}>{f.label}</option>
                            ))}
                        </select>
                    </div>
                </div>
            )}
        </div>
    );
};
