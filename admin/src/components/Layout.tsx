import React from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { 
    faGaugeHigh, 
    faDatabase, 
    faRocket, 
    faImages, 
    faCogs,
    faBolt,
    faSun,
    faMoon
} from '@fortawesome/free-solid-svg-icons';
import { IconDefinition } from '@fortawesome/fontawesome-svg-core';

interface Tab {
    id: string;
    label: string;
    icon?: string;
}

interface LayoutProps {
    children: React.ReactNode;
    activeTab: string;
    onSelectTab: (id: string) => void;
    tabs: Tab[];
    theme: 'light' | 'dark';
    onToggleTheme: () => void;
}

const iconMap: Record<string, IconDefinition> = {
    'dashboard': faGaugeHigh,
    'database': faDatabase,
    'performance': faRocket,
    'format-image': faImages,
    'admin-tools': faCogs,
};

export const Layout: React.FC<LayoutProps> = ({ children, activeTab, onSelectTab, tabs, theme, onToggleTheme }) => {
    return (
        <div className={`min-h-screen flex ${theme === 'dark' ? 'dark bg-gray-900' : 'bg-gray-100'}`}>
            {/* Sidebar */}
            <aside className="w-64 bg-gradient-to-b from-gray-900 to-gray-800 text-white flex-shrink-0 shadow-xl relative">
                {/* Logo / Brand */}
                <div className="p-6 border-b border-gray-700/50">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-lg flex items-center justify-center shadow-lg">
                            <FontAwesomeIcon icon={faBolt} className="text-white text-lg" />
                        </div>
                        <div>
                            <h2 className="text-lg font-bold text-white leading-tight">Performance</h2>
                            <p className="text-xs text-gray-400">Optimisation</p>
                        </div>
                    </div>
                </div>

                {/* Navigation */}
                <nav className="mt-6 px-4 space-y-1">
                    {tabs.map((tab) => {
                        const icon = iconMap[tab.icon || ''] || faGaugeHigh;
                        const isActive = activeTab === tab.id;
                        
                        return (
                            <button
                                key={tab.id}
                                onClick={() => onSelectTab(tab.id)}
                                className={`
                                    w-full flex items-center px-4 py-3 text-sm font-medium rounded-lg transition-all duration-200
                                    ${isActive 
                                        ? 'bg-gradient-to-r from-blue-600 to-indigo-600 text-white shadow-lg shadow-blue-500/25' 
                                        : 'text-gray-300 hover:bg-white/10 hover:text-white'}
                                `}
                            >
                                <FontAwesomeIcon 
                                    icon={icon} 
                                    className={`mr-3 w-5 ${isActive ? 'text-white' : 'text-gray-400'}`} 
                                    fixedWidth 
                                />
                                {tab.label}
                            </button>
                        );
                    })}
                </nav>

                {/* Footer with Theme Toggle */}
                <div className="absolute bottom-0 left-0 right-0 p-4 border-t border-gray-700/50">
                    <div className="flex items-center justify-between mb-3">
                        <span className="text-xs text-gray-400">Theme</span>
                        <button
                            onClick={onToggleTheme}
                            className="p-2 rounded-lg bg-gray-700/50 hover:bg-gray-700 transition-colors"
                            title={theme === 'dark' ? 'Switch to Light Mode' : 'Switch to Dark Mode'}
                        >
                            <FontAwesomeIcon 
                                icon={theme === 'dark' ? faSun : faMoon} 
                                className={theme === 'dark' ? 'text-yellow-400' : 'text-gray-300'} 
                            />
                        </button>
                    </div>
                    <div className="text-xs text-gray-500 text-center">
                        v2.0.0 • WordPress Plugin
                    </div>
                </div>
            </aside>

            {/* Main Content */}
            <main className={`flex-1 overflow-auto ${theme === 'dark' ? 'bg-gray-900' : 'bg-gray-50'}`}>
                {children}
            </main>
        </div>
    );
};
