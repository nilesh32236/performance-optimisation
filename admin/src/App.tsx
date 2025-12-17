import React, { useState, useEffect } from 'react';

import { Layout } from './components/Layout';
import Dashboard from './pages/Dashboard';
import Caching from './pages/Caching';
import Optimization from './pages/Optimization';
import Images from './pages/Images';
import Advanced from './pages/Advanced';
import Monitor from './pages/Monitor';
import { PluginConfig } from './types';

const THEME_STORAGE_KEY = 'wppo_theme';

export const App: React.FC = () => {
    const [config, setConfig] = useState<PluginConfig | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [activeTab, setActiveTab] = useState<string>('dashboard');
    
    // Theme state with localStorage persistence
    const [theme, setTheme] = useState<'light' | 'dark'>(() => {
        const saved = localStorage.getItem(THEME_STORAGE_KEY);
        if (saved === 'dark' || saved === 'light') return saved;
        // Respect system preference
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            return 'dark';
        }
        return 'light';
    });

    // Persist theme changes
    useEffect(() => {
        localStorage.setItem(THEME_STORAGE_KEY, theme);
        // Also set on document for global dark mode support
        if (theme === 'dark') {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    }, [theme]);

    const toggleTheme = () => {
        setTheme(prev => prev === 'dark' ? 'light' : 'dark');
    };

    useEffect(() => {
        const loadConfig = async () => {
            try {
                if (window.wppoAdmin) {
                    setConfig(window.wppoAdmin);
                } else {
                    console.warn('Configuration not available in window.wppoAdmin');
                }
            } catch (err) {
                setError(err instanceof Error ? err.message : 'Failed to load configuration');
            } finally {
                setLoading(false);
            }
        };

        loadConfig();
    }, []);

    if (loading) {
        return (
            <div className={`flex flex-col items-center justify-center h-screen ${theme === 'dark' ? 'bg-gray-900' : 'bg-gray-50'}`}>
                <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
                <p className={`mt-4 text-sm font-medium ${theme === 'dark' ? 'text-gray-400' : 'text-gray-600'}`}>Loading...</p>
            </div>
        );
    }

    if (error) {
        return (
            <div className={`flex items-center justify-center h-screen p-6 ${theme === 'dark' ? 'bg-gray-900' : 'bg-gray-50'}`}>
                <div className={`max-w-md w-full rounded-lg border border-red-200 p-6 shadow-lg ${theme === 'dark' ? 'bg-gray-800' : 'bg-white'}`}>
                    <h3 className="text-lg font-semibold text-red-600 mb-2">Configuration Error</h3>
                    <p className={`mb-4 ${theme === 'dark' ? 'text-gray-300' : 'text-gray-700'}`}>{error}</p>
                    <button
                        onClick={() => window.location.reload()}
                        className="w-full bg-red-600 text-white px-4 py-2 rounded-lg hover:bg-red-700 transition-colors font-medium"
                    >
                        Reload Page
                    </button>
                </div>
            </div>
        );
    }

    const tabs = [
        { id: 'dashboard', label: 'Dashboard', icon: 'dashboard' },
        { id: 'monitor', label: 'Monitor', icon: 'performance' },
        { id: 'caching', label: 'Caching', icon: 'database' },
        { id: 'optimization', label: 'Optimization', icon: 'performance' },
        { id: 'images', label: 'Images', icon: 'format-image' },
        { id: 'advanced', label: 'Advanced', icon: 'admin-tools' },
    ];

    const renderContent = () => {
        switch (activeTab) {
            case 'dashboard':
                return <Dashboard />;
            case 'monitor':
                return <Monitor />;
            case 'caching':
                return <Caching />;
            case 'optimization':
                return <Optimization />;
            case 'images':
                return <Images />;
            case 'advanced':
                return <Advanced />;
            default:
                return <Dashboard />;
        }
    };

    return (
        <Layout
            activeTab={activeTab}
            onSelectTab={setActiveTab}
            tabs={tabs}
            theme={theme}
            onToggleTheme={toggleTheme}
        >
            {renderContent()}
        </Layout>
    );
};
