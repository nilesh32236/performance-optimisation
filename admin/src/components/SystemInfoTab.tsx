import React, { useState, useEffect } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faServer,
    faDatabase,
    faCopy,
    faChevronDown,
    faChevronUp,
    faSync,
    faCheckCircle,
    faExclamationTriangle,
    faInfoCircle,
    faCode,
    faGlobe,
} from '@fortawesome/free-solid-svg-icons';


interface SystemInfoSection {
    title: string;
    icon: any;
    items: { label: string; value: string; status?: 'good' | 'warning' | 'info' }[];
}

interface SystemInfoData {
    php: Record<string, string>;
    wordpress: Record<string, string>;
    database: Record<string, string>;
    server: Record<string, string>;
}

const SystemInfoTab: React.FC = () => {
    const [loading, setLoading] = useState(true);
    const [data, setData] = useState<SystemInfoData | null>(null);
    const [expandedSections, setExpandedSections] = useState<Set<string>>(new Set(['php', 'wordpress', 'database', 'server']));
    const [copied, setCopied] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const fetchData = async () => {
        setLoading(true);
        setError(null);

        try {
            const apiUrl = (window as any).wppoAdmin?.apiUrl;
            const nonce = (window as any).wppoAdmin?.nonce || '';

            if (!apiUrl) {
                throw new Error('API URL not configured');
            }

            const response = await fetch(`${apiUrl}/monitor/system-info`, {
                headers: { 'X-WP-Nonce': nonce },
            });

            const result = await response.json();
            if (result.success) {
                setData(result.data);
            }
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Failed to fetch system info');
            // Mock data for development
            setData({
                php: {
                    'PHP Version': '8.2.10',
                    'Memory Limit': '256M',
                    'Max Execution Time': '300',
                    'Post Max Size': '64M',
                    'Upload Max Size': '64M',
                    'Max Input Vars': '5000',
                    'Display Errors': 'Off',
                    'Opcache Enabled': 'Yes',
                },
                wordpress: {
                    'WordPress Version': '6.4.2',
                    'Site URL': 'https://example.com',
                    'Home URL': 'https://example.com',
                    'Multisite': 'No',
                    'WP Debug Mode': 'Yes',
                    'WP Debug Log': 'No',
                    'Active Theme': 'Starter Theme',
                    'Active Plugins': '12',
                },
                database: {
                    'Database Version': '10.5.9-MariaDB',
                    'Database Host': 'localhost',
                    'Database Name': 'wordpress',
                    'Table Prefix': 'wp_',
                    'Database Charset': 'utf8mb4',
                    'Database Collate': 'utf8mb4_unicode_ci',
                },
                server: {
                    'Server Software': 'Apache/2.4.52',
                    'Server OS': 'Linux',
                    'Architecture': 'x86_64',
                    'cURL Version': '7.81.0',
                    'OpenSSL Version': 'OpenSSL 3.0.2',
                    'Disk Free Space': '45.2 GB',
                },
            });
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchData();
    }, []);

    const toggleSection = (section: string) => {
        const newExpanded = new Set(expandedSections);
        if (newExpanded.has(section)) {
            newExpanded.delete(section);
        } else {
            newExpanded.add(section);
        }
        setExpandedSections(newExpanded);
    };

    const copyToClipboard = async () => {
        if (!data) return;

        let text = '=== System Information ===\n\n';

        const formatSection = (title: string, items: Record<string, string>) => {
            text += `--- ${title} ---\n`;
            Object.entries(items).forEach(([key, value]) => {
                text += `${key}: ${value}\n`;
            });
            text += '\n';
        };

        formatSection('PHP', data.php);
        formatSection('WordPress', data.wordpress);
        formatSection('Database', data.database);
        formatSection('Server', data.server);

        try {
            await navigator.clipboard.writeText(text);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch (err) {
            console.error('Failed to copy:', err);
        }
    };

    const getStatusIcon = (status?: string) => {
        switch (status) {
            case 'good':
                return <FontAwesomeIcon icon={faCheckCircle} className="text-green-500" />;
            case 'warning':
                return <FontAwesomeIcon icon={faExclamationTriangle} className="text-amber-500" />;
            default:
                return <FontAwesomeIcon icon={faInfoCircle} className="text-blue-400" />;
        }
    };

    const getSectionIcon = (section: string) => {
        switch (section) {
            case 'php':
                return faCode;
            case 'wordpress':
                return faGlobe;
            case 'database':
                return faDatabase;
            case 'server':
            default:
                return faServer;
        }
    };

    const getSectionTitle = (section: string) => {
        switch (section) {
            case 'php':
                return 'PHP Configuration';
            case 'wordpress':
                return 'WordPress Environment';
            case 'database':
                return 'Database Information';
            case 'server':
                return 'Server Details';
            default:
                return section;
        }
    };

    if (loading && !data) {
        return (
            <div className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-8">
                <div className="flex items-center justify-center gap-3">
                    <FontAwesomeIcon icon={faSync} className="animate-spin text-purple-600" />
                    <span className="text-gray-600 dark:text-gray-400">Loading system information...</span>
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div className="flex items-center gap-3">
                    <div className="w-10 h-10 bg-purple-100 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400 rounded-lg flex items-center justify-center">
                        <FontAwesomeIcon icon={faServer} />
                    </div>
                    <div>
                        <h3 className="text-xl font-bold text-gray-900 dark:text-white">
                            System Information
                        </h3>
                        <p className="text-sm text-gray-500 dark:text-gray-400">
                            Server, PHP, WordPress, and database details
                        </p>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    <button
                        onClick={copyToClipboard}
                        className={`flex items-center gap-2 px-4 py-2 border rounded-lg text-sm font-medium transition-colors ${
                            copied
                                ? 'border-green-500 text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-900/20'
                                : 'border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700'
                        }`}
                    >
                        <FontAwesomeIcon icon={copied ? faCheckCircle : faCopy} />
                        {copied ? 'Copied!' : 'Copy All'}
                    </button>
                    <button
                        onClick={fetchData}
                        disabled={loading}
                        className="flex items-center gap-2 px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium rounded-lg transition-colors disabled:opacity-50"
                    >
                        <FontAwesomeIcon icon={faSync} className={loading ? 'animate-spin' : ''} />
                        Refresh
                    </button>
                </div>
            </div>

            {error && (
                <div className="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-3 text-sm text-amber-700 dark:text-amber-300">
                    {error}
                </div>
            )}

            {/* Info Sections */}
            {data && (
                <div className="space-y-4">
                    {Object.entries(data).map(([sectionKey, items]) => {
                        const isExpanded = expandedSections.has(sectionKey);
                        const itemsArray = Object.entries(items as Record<string, string>);

                        return (
                            <div
                                key={sectionKey}
                                className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden"
                            >
                                <button
                                    onClick={() => toggleSection(sectionKey)}
                                    className="w-full flex items-center justify-between p-4 text-left hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                                >
                                    <div className="flex items-center gap-3">
                                        <div className="w-8 h-8 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center">
                                            <FontAwesomeIcon
                                                icon={getSectionIcon(sectionKey)}
                                                className="text-gray-600 dark:text-gray-400"
                                            />
                                        </div>
                                        <span className="font-semibold text-gray-900 dark:text-white">
                                            {getSectionTitle(sectionKey)}
                                        </span>
                                        <span className="text-xs text-gray-500 dark:text-gray-400 bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded-full">
                                            {itemsArray.length} items
                                        </span>
                                    </div>
                                    <FontAwesomeIcon
                                        icon={isExpanded ? faChevronUp : faChevronDown}
                                        className="text-gray-400"
                                    />
                                </button>

                                {isExpanded && (
                                    <div className="border-t border-gray-200 dark:border-gray-700">
                                        <div className="divide-y divide-gray-100 dark:divide-gray-700">
                                            {itemsArray.map(([label, value]) => (
                                                <div
                                                    key={label}
                                                    className="flex items-center justify-between px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors"
                                                >
                                                    <span className="text-sm text-gray-600 dark:text-gray-400">
                                                        {label}
                                                    </span>
                                                    <span className="text-sm font-medium text-gray-900 dark:text-white text-right">
                                                        {value}
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            )}

            {/* Footer Note */}
            <div className="text-center text-xs text-gray-500 dark:text-gray-400">
                This information is useful for debugging and support requests.
            </div>
        </div>
    );
};

export default SystemInfoTab;
