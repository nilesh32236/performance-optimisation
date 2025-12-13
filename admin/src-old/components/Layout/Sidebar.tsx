import React from 'react';
import { Dashicon } from '@wordpress/components';

interface SidebarProps {
    activeTab: string;
    onSelectTab: (tab: string) => void;
    items: Array<{
        id: string;
        label: string;
        icon: string;
    }>;
}

export const Sidebar: React.FC<SidebarProps> = ({ activeTab, onSelectTab, items }) => {
    return (
        <div className="w-64 bg-slate-900 text-white flex flex-col flex-shrink-0 min-h-[calc(100vh-32px)]">
            <div className="px-6 py-6 flex items-center gap-3 border-b border-slate-800">
                <div className="w-8 h-8 bg-primary-500 rounded-lg flex items-center justify-center shadow-lg shadow-primary-500/30">
                   <Dashicon icon="performance" className="text-white" />
                </div>
                <span className="font-bold text-lg tracking-tight text-slate-100">Performance</span>
            </div>
            
            <nav className="flex-1 py-6 px-3 space-y-1">
                {items.map((item) => (
                    <button
                        key={item.id}
                        onClick={() => onSelectTab(item.id)}
                        className={`w-full flex items-center gap-3 px-3 py-3 rounded-lg text-sm font-medium transition-all duration-200 group ${
                            activeTab === item.id 
                                ? 'bg-primary-600 text-white shadow-md shadow-primary-900/20' 
                                : 'text-slate-400 hover:bg-slate-800 hover:text-slate-100'
                        }`}
                    >
                        <Dashicon 
                            icon={item.icon as any} 
                            className={`transition-colors ${activeTab === item.id ? 'text-white' : 'text-slate-500 group-hover:text-slate-300'}`} 
                        />
                        {item.label}
                    </button>
                ))}
            </nav>

            <div className="p-4 border-t border-slate-800">
                <div className="bg-slate-800 rounded-lg p-4 border border-slate-700/50">
                    <h4 className="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Pro Status</h4>
                    <div className="flex items-center gap-2 text-sm text-emerald-400 mb-3">
                        <span className="w-2 h-2 rounded-full bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.4)]"></span>
                        Active
                    </div>
                    <button className="text-xs text-slate-400 hover:text-white transition-colors flex items-center gap-1">
                        View License <span>&rarr;</span>
                    </button>
                </div>
                <div className="mt-4 text-xs text-center text-slate-600 font-mono">
                    v2.0.0
                </div>
            </div>
        </div>
    );
};
