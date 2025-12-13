/**
 * Enhanced Feature Card Component
 * 
 * Unified card design with:
 * - Toggle switch for enable/disable
 * - Expandable advanced options section
 * - Smooth animations
 * - Consistent styling
 */

import React, { useState } from 'react';
import { Dashicon } from '@wordpress/components';

interface EnhancedFeatureCardProps {
    icon: string;
    title: string;
    description: string;
    enabled: boolean;
    onToggle: (enabled: boolean) => void | Promise<void>;
    color: 'blue' | 'purple' | 'emerald' | 'orange' | 'indigo' | 'red' | 'pink';
    children?: React.ReactNode; // Advanced options
    disabled?: boolean;
}

export const EnhancedFeatureCard: React.FC<EnhancedFeatureCardProps> = ({
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

    const colorClasses = {
        blue: {
            ring: 'ring-blue-500',
            shadow: 'shadow-blue-500/20',
            bg: 'bg-blue-500',
            shadowActive: 'shadow-blue-500/30',
            hoverBg: 'hover:bg-blue-50',
            hoverText: 'hover:text-blue-500',
            text: 'text-blue-600',
            bgLight: 'bg-blue-100',
        },
        purple: {
            ring: 'ring-purple-500',
            shadow: 'shadow-purple-500/20',
            bg: 'bg-purple-500',
            shadowActive: 'shadow-purple-500/30',
            hoverBg: 'hover:bg-purple-50',
            hoverText: 'hover:text-purple-500',
            text: 'text-purple-600',
            bgLight: 'bg-purple-100',
        },
        emerald: {
            ring: 'ring-emerald-500',
            shadow: 'shadow-emerald-500/20',
            bg: 'bg-emerald-500',
            shadowActive: 'shadow-emerald-500/30',
            hoverBg: 'hover:bg-emerald-50',
            hoverText: 'hover:text-emerald-500',
            text: 'text-emerald-600',
            bgLight: 'bg-emerald-100',
        },
        orange: {
            ring: 'ring-orange-500',
            shadow: 'shadow-orange-500/20',
            bg: 'bg-orange-500',
            shadowActive: 'shadow-orange-500/30',
            hoverBg: 'hover:bg-orange-50',
            hoverText: 'hover:text-orange-500',
            text: 'text-orange-600',
            bgLight: 'bg-orange-100',
        },
        indigo: {
            ring: 'ring-indigo-500',
            shadow: 'shadow-indigo-500/20',
            bg: 'bg-indigo-500',
            shadowActive: 'shadow-indigo-500/30',
            hoverBg: 'hover:bg-indigo-50',
            hoverText: 'hover:text-indigo-500',
            text: 'text-indigo-600',
            bgLight: 'bg-indigo-100',
        },
        red: {
            ring: 'ring-red-500',
            shadow: 'shadow-red-500/20',
            bg: 'bg-red-500',
            shadowActive: 'shadow-red-500/30',
            hoverBg: 'hover:bg-red-50',
            hoverText: 'hover:text-red-500',
            text: 'text-red-600',
            bgLight: 'bg-red-100',
        },
        pink: {
            ring: 'ring-pink-500',
            shadow: 'shadow-pink-500/20',
            bg: 'bg-pink-500',
            shadowActive: 'shadow-pink-500/30',
            hoverBg: 'hover:bg-pink-50',
            hoverText: 'hover:text-pink-500',
            text: 'text-pink-600',
            bgLight: 'bg-pink-100',
        },
    };

    const colors = colorClasses[color];

    return (
        <div
            className={`group relative overflow-hidden rounded-2xl transition-all duration-300 ${enabled
                ? `bg-white ring-2 ${colors.ring} ${colors.shadow} shadow-xl`
                : 'bg-white border border-slate-200 hover:border-slate-300 hover:shadow-lg'
                } transform hover:-translate-y-1`}
        >
            <div className="p-8">
                {/* Icon */}
                <div
                    className={`w-16 h-16 rounded-2xl flex items-center justify-center mb-6 transition-all duration-300 ${enabled
                        ? `${colors.bg} text-white shadow-lg ${colors.shadowActive} scale-110`
                        : `bg-slate-100 text-slate-400 ${colors.hoverBg} ${colors.hoverText}`
                        }`}
                >
                    {/* @ts-ignore */}
                    <Dashicon icon={icon} style={{ fontSize: '32px', width: '32px', height: '32px' }} />
                </div>

                {/* Title & Description */}
                <h3 className="text-xl font-bold text-slate-900 mb-3">{title}</h3>
                <p className="text-slate-600 mb-8 min-h-[3rem] leading-relaxed">{description}</p>

                {/* Toggle Switch */}
                <div className="flex items-center justify-between pt-6 border-t border-slate-100">
                    <span className={`text-sm font-semibold transition-colors flex items-center gap-2 ${enabled ? colors.text : 'text-slate-400'}`}>
                        {enabled ? 'Active' : 'Inactive'}
                    </span>
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
                        <div className={`relative w-14 h-8 rounded-full peer transition-colors duration-200 ${enabled && !disabled ? colors.bg : disabled ? 'bg-slate-100' : 'bg-slate-200'} peer-focus:outline-none ${!disabled ? `peer-focus:ring-4 peer-focus:ring-${color}-100` : ''}`}>
                            <div className={`absolute top-1 left-1 bg-white border border-gray-300 rounded-full h-6 w-6 transition-transform duration-200 ${enabled ? 'translate-x-6' : 'translate-x-0'}`}></div>
                        </div>
                    </label>
                </div>

                {/* Advanced Options */}
                {children && (
                    <div className="mt-6">
                        <button
                            onClick={() => setIsExpanded(!isExpanded)}
                            className={`w-full flex items-center justify-between px-4 py-3 text-sm font-semibold rounded-lg transition-all duration-200 ${isExpanded
                                ? `${colors.bgLight} ${colors.text}`
                                : 'bg-slate-50 text-slate-600 hover:bg-slate-100'
                                }`}
                        >
                            <span>Advanced Options</span>
                            <Dashicon
                                icon={isExpanded ? 'arrow-up-alt2' : 'arrow-down-alt2'}
                                style={{ fontSize: '20px', width: '20px', height: '20px' }}
                            />
                        </button>

                        {/* Expandable Content */}
                        <div
                            className={`overflow-hidden transition-all duration-300 ${isExpanded ? 'max-h-96 opacity-100 mt-4' : 'max-h-0 opacity-0'
                                }`}
                        >
                            <div className="p-4 bg-slate-50 rounded-lg border border-slate-200">
                                {children}
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
};
