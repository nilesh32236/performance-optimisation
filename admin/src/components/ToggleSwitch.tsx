/**
 * Toggle Switch Component
 * Reusable compact toggle for inline settings
 */

import React from 'react';

interface ToggleSwitchProps {
    checked: boolean;
    onChange: (checked: boolean) => void;
    disabled?: boolean;
    color?: string;
    label?: string;
}

export const ToggleSwitch: React.FC<ToggleSwitchProps> = ({
    checked,
    onChange,
    disabled = false,
    color = 'blue',
    label,
}) => {
    const colorMap: Record<string, string> = {
        blue: 'bg-blue-600',
        purple: 'bg-purple-600',
        emerald: 'bg-emerald-600',
        indigo: 'bg-indigo-600',
        pink: 'bg-pink-600',
        red: 'bg-red-600',
    };

    const bgColor = colorMap[color] || colorMap.blue;

    return (
        <label className={`relative inline-flex items-center ${disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'}`}>
            {label && <span className="mr-3 text-sm text-gray-700 dark:text-gray-300">{label}</span>}
            <input
                type="checkbox"
                className="sr-only peer"
                checked={checked}
                onChange={(e) => !disabled && onChange(e.target.checked)}
                disabled={disabled}
            />
            <div className={`relative w-11 h-6 rounded-full transition-colors ${checked ? bgColor : 'bg-gray-200 dark:bg-gray-600'}`}>
                <div className={`absolute top-[2px] left-[2px] bg-white rounded-full h-5 w-5 transition-transform shadow ${checked ? 'translate-x-5' : 'translate-x-0'}`}></div>
            </div>
        </label>
    );
};
