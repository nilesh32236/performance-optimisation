import React from 'react';
import { Header } from './Header';

interface LayoutProps {
    children: React.ReactNode;
    activeTab: string;
    onSelectTab: (tab: string) => void;
    tabs: Array<{
        id: string;
        label: string;
        icon: string;
    }>;
    headerActions?: React.ReactNode;
}

export const Layout: React.FC<LayoutProps> = ({ 
    children, 
    activeTab, 
    onSelectTab, 
    tabs,
    headerActions 
}) => {
    return (
        <div className="min-h-screen bg-gray-50 -ml-[20px] -mt-[10px]">
            <Header 
                activeTab={activeTab}
                onSelectTab={onSelectTab}
                tabs={tabs}
                actions={headerActions}
            />
            
            <main className="p-6 md:p-8">
                <div className="max-w-7xl mx-auto">
                    {children}
                </div>
            </main>
        </div>
    );
};
