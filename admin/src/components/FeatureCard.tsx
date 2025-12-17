/**
 * Feature Card Component
 * Reusable card with toggle switch and expandable advanced options
 */

import React, { useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faChevronDown, faChevronUp } from '@fortawesome/free-solid-svg-icons';
import { IconDefinition } from '@fortawesome/fontawesome-svg-core';

interface FeatureCardProps {
    icon: IconDefinition;
    title: string;
    description: string;
    enabled: boolean;
    onToggle: (enabled: boolean) => void | Promise<void>;
    color: 'blue' | 'purple' | 'emerald' | 'orange' | 'indigo' | 'red' | 'pink' | 'cyan';
    children?: React.ReactNode;
    disabled?: boolean;
}

const colorClasses: Record<string, { ring: string; bg: string; text: string; bgLight: string; textDark: string; bgLightDark: string }> = {
    blue: { ring: 'ring-blue-500', bg: 'bg-blue-500', text: 'text-blue-600', bgLight: 'bg-blue-50', textDark: 'dark:text-blue-400', bgLightDark: 'dark:bg-blue-900/30' },
    purple: { ring: 'ring-purple-500', bg: 'bg-purple-500', text: 'text-purple-600', bgLight: 'bg-purple-50', textDark: 'dark:text-purple-400', bgLightDark: 'dark:bg-purple-900/30' },
    emerald: { ring: 'ring-emerald-500', bg: 'bg-emerald-500', text: 'text-emerald-600', bgLight: 'bg-emerald-50', textDark: 'dark:text-emerald-400', bgLightDark: 'dark:bg-emerald-900/30' },
    orange: { ring: 'ring-orange-500', bg: 'bg-orange-500', text: 'text-orange-600', bgLight: 'bg-orange-50', textDark: 'dark:text-orange-400', bgLightDark: 'dark:bg-orange-900/30' },
    indigo: { ring: 'ring-indigo-500', bg: 'bg-indigo-500', text: 'text-indigo-600', bgLight: 'bg-indigo-50', textDark: 'dark:text-indigo-400', bgLightDark: 'dark:bg-indigo-900/30' },
    red: { ring: 'ring-red-500', bg: 'bg-red-500', text: 'text-red-600', bgLight: 'bg-red-50', textDark: 'dark:text-red-400', bgLightDark: 'dark:bg-red-900/30' },
    pink: { ring: 'ring-pink-500', bg: 'bg-pink-500', text: 'text-pink-600', bgLight: 'bg-pink-50', textDark: 'dark:text-pink-400', bgLightDark: 'dark:bg-pink-900/30' },
    cyan: { ring: 'ring-cyan-500', bg: 'bg-cyan-500', text: 'text-cyan-600', bgLight: 'bg-cyan-50', textDark: 'dark:text-cyan-400', bgLightDark: 'dark:bg-cyan-900/30' },
};

export const FeatureCard: React.FC<FeatureCardProps> = ({
    icon,
    title,
    description,
    enabled,
    onToggle,
    color,
    children,
    disabled = false,
}) => {
    const [isExpanded, setIsExpanded] = useState(false);
    const colors = colorClasses[color];

    return (
        <div className={`relative overflow-hidden rounded-2xl transition-all duration-300 ${
            enabled
                ? `bg-white dark:bg-gray-800 ring-2 ${colors.ring} shadow-lg`
                : 'bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 hover:shadow-md'
        }`}>
            <div className="p-6">
                {/* Header */}
                <div className="flex items-start justify-between gap-4 mb-4">
                    <div className={`w-12 h-12 rounded-xl flex items-center justify-center transition-all ${
                        enabled 
                            ? `${colors.bg} text-white shadow-lg` 
                            : 'bg-gray-100 dark:bg-gray-700 text-gray-400'
                    }`}>
                        <FontAwesomeIcon icon={icon} className="text-lg" />
                    </div>
                    
                    {/* Toggle Switch */}
                    <label className={`relative inline-flex items-center ${disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'}`}>
                        <input
                            type="checkbox"
                            className="sr-only peer"
                            checked={enabled}
                            onChange={async (e) => {
                                if (!disabled) {
                                    await onToggle(e.target.checked);
                                }
                            }}
                            disabled={disabled}
                        />
                        <div className={`w-11 h-6 rounded-full transition-colors ${
                            enabled ? colors.bg : 'bg-gray-200 dark:bg-gray-600'
                        }`}>
                            <div className={`absolute top-[2px] left-[2px] bg-white rounded-full h-5 w-5 transition-transform shadow ${
                                enabled ? 'translate-x-5' : 'translate-x-0'
                            }`}></div>
                        </div>
                    </label>
                </div>

                {/* Title & Description */}
                <h3 className="text-lg font-bold text-gray-900 dark:text-white mb-2">{title}</h3>
                <p className="text-sm text-gray-500 dark:text-gray-400 leading-relaxed">{description}</p>

                {/* Status Badge */}
                <div className="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700 flex items-center justify-between">
                    <span className={`text-xs font-semibold px-2.5 py-1 rounded-full ${
                        enabled 
                            ? `${colors.bgLight} ${colors.bgLightDark} ${colors.text} ${colors.textDark}` 
                            : 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400'
                    }`}>
                        {enabled ? 'Active' : 'Inactive'}
                    </span>
                    
                    {children && (
                        <button
                            onClick={() => setIsExpanded(!isExpanded)}
                            className="text-xs font-medium text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 flex items-center gap-1"
                        >
                            Advanced
                            <FontAwesomeIcon icon={isExpanded ? faChevronUp : faChevronDown} className="text-[10px]" />
                        </button>
                    )}
                </div>

                {/* Expandable Content */}
                {children && (
                    <div className={`overflow-hidden transition-all duration-300 ${
                        isExpanded ? 'max-h-[500px] opacity-100 mt-4' : 'max-h-0 opacity-0'
                    }`}>
                        <div className="p-4 bg-gray-50 dark:bg-gray-900/50 rounded-xl border border-gray-100 dark:border-gray-700">
                            {children}
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
};
