import React from 'react';
import { Dashicon } from '@wordpress/components';

interface HeaderProps {
    activeTab: string;
    onSelectTab: (tab: string) => void;
    tabs: Array<{
        id: string;
        label: string;
        icon: string;
    }>;
    actions?: React.ReactNode;
}

export const Header: React.FC<HeaderProps> = ({ activeTab, onSelectTab, tabs, actions }) => {
    return (
        <header className="bg-white border-b-2 border-gray-200 shadow-sm">
            <div className="px-8 py-6 flex items-center justify-between">
                <div className="flex items-center gap-4">
                    <div className="w-14 h-14 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center shadow-lg">
                        <Dashicon icon="performance" className="text-white" style={{ fontSize: '28px' }} />
                    </div>
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900 m-0 leading-none">Performance Optimisation</h1>
                        <p className="text-sm text-gray-600 mt-1">Boost your WordPress site speed</p>
                    </div>
                </div>
                <div className="flex items-center gap-3">
                    {actions}
                </div>
            </div>
            
            <nav className="px-8 flex gap-2 overflow-x-auto border-t border-gray-100">
                {tabs.map((tab) => (
                    <button
                        key={tab.id}
                        onClick={() => onSelectTab(tab.id)}
                        className={`flex items-center gap-2 px-6 py-4 text-base font-semibold border-b-3 transition-all whitespace-nowrap ${
                            activeTab === tab.id
                                ? 'border-blue-500 text-blue-600 bg-blue-50'
                                : 'border-transparent text-gray-600 hover:text-gray-900 hover:bg-gray-50'
                        }`}
                        style={{ borderBottomWidth: activeTab === tab.id ? '3px' : '3px' }}
                    >
                        <Dashicon icon={tab.icon as any} style={{ fontSize: '20px' }} />
                        {tab.label}
                    </button>
                ))}
            </nav>
        </header>
    );
};
