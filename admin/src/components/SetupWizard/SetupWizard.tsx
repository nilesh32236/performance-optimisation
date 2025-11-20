import React, { useState, useEffect } from 'react';
import { Button, Card, CardBody, Notice, Spinner, ProgressBar } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import './SetupWizard.scss';

interface WizardStep {
    id: string;
    title: string;
    description: string;
    component: React.ComponentType<any>;
}

interface SystemInfo {
    php_version: string;
    wp_version: string;
    memory_limit: string;
    extensions: Record<string, boolean>;
    cache_writable: boolean;
}

const SetupWizard: React.FC = () => {
    const [currentStep, setCurrentStep] = useState(0);
    const [loading, setLoading] = useState(false);
    const [systemInfo, setSystemInfo] = useState<SystemInfo | null>(null);
    const [settings, setSettings] = useState({});
    const [error, setError] = useState<string | null>(null);

    const steps: WizardStep[] = [
        {
            id: 'welcome',
            title: __('Welcome', 'performance-optimisation'),
            description: __('Welcome to Performance Optimisation setup', 'performance-optimisation'),
            component: WelcomeStep,
        },
        {
            id: 'system-check',
            title: __('System Check', 'performance-optimisation'),
            description: __('Checking your system requirements', 'performance-optimisation'),
            component: SystemCheckStep,
        },
        {
            id: 'site-analysis',
            title: __('Site Analysis', 'performance-optimisation'),
            description: __('Analyzing your site for optimization opportunities', 'performance-optimisation'),
            component: SiteAnalysisStep,
        },
        {
            id: 'optimization-level',
            title: __('Optimization Level', 'performance-optimisation'),
            description: __('Choose your optimization level', 'performance-optimisation'),
            component: OptimizationLevelStep,
        },
        {
            id: 'features',
            title: __('Features', 'performance-optimisation'),
            description: __('Select features to enable', 'performance-optimisation'),
            component: FeaturesStep,
        },
        {
            id: 'complete',
            title: __('Complete', 'performance-optimisation'),
            description: __('Setup complete!', 'performance-optimisation'),
            component: CompleteStep,
        },
    ];

    useEffect(() => {
        loadSystemInfo();
    }, []);

    const loadSystemInfo = async () => {
        try {
            const info = await apiFetch({ path: '/performance-optimisation/v1/system/info' });
            setSystemInfo(info as SystemInfo);
        } catch (err) {
            setError(__('Failed to load system information', 'performance-optimisation'));
        }
    };

    const nextStep = () => {
        if (currentStep < steps.length - 1) {
            setCurrentStep(currentStep + 1);
        }
    };

    const prevStep = () => {
        if (currentStep > 0) {
            setCurrentStep(currentStep - 1);
        }
    };

    const completeSetup = async () => {
        try {
            setLoading(true);
            await apiFetch({
                path: '/performance-optimisation/v1/settings',
                method: 'POST',
                data: settings,
            });
            
            // Mark wizard as completed
            await apiFetch({
                path: '/performance-optimisation/v1/wizard/complete',
                method: 'POST',
            });
            
            nextStep();
        } catch (err) {
            setError(__('Failed to save settings', 'performance-optimisation'));
        } finally {
            setLoading(false);
        }
    };

    const progress = ((currentStep + 1) / steps.length) * 100;
    const CurrentStepComponent = steps[currentStep].component;

    return (
        <div className="wppo-setup-wizard">
            <div className="wppo-wizard-header">
                <h1>{__('Performance Optimisation Setup', 'performance-optimisation')}</h1>
                <ProgressBar value={progress} />
                <p className="wppo-step-info">
                    {__('Step', 'performance-optimisation')} {currentStep + 1} {__('of', 'performance-optimisation')} {steps.length}: {steps[currentStep].title}
                </p>
            </div>

            {error && (
                <Notice status="error" isDismissible onRemove={() => setError(null)}>
                    {error}
                </Notice>
            )}

            <Card className="wppo-wizard-content">
                <CardBody>
                    <CurrentStepComponent
                        systemInfo={systemInfo}
                        settings={settings}
                        onSettingsChange={setSettings}
                        onNext={nextStep}
                        onPrev={prevStep}
                        onComplete={completeSetup}
                        loading={loading}
                        isFirst={currentStep === 0}
                        isLast={currentStep === steps.length - 1}
                    />
                </CardBody>
            </Card>
        </div>
    );
};

const WelcomeStep: React.FC<any> = ({ onNext }) => (
    <div className="wppo-welcome-step">
        <div className="wppo-welcome-icon">🚀</div>
        <h2>{__('Welcome to Performance Optimisation', 'performance-optimisation')}</h2>
        <p>{__('This wizard will help you configure your site for optimal performance. We\'ll analyze your site and recommend the best settings for your needs.', 'performance-optimisation')}</p>
        
        <div className="wppo-features-preview">
            <h3>{__('What you\'ll get:', 'performance-optimisation')}</h3>
            <ul>
                <li>✅ {__('Advanced caching system', 'performance-optimisation')}</li>
                <li>✅ {__('Image optimization and WebP conversion', 'performance-optimisation')}</li>
                <li>✅ {__('CSS and JavaScript minification', 'performance-optimisation')}</li>
                <li>✅ {__('Database optimization', 'performance-optimisation')}</li>
                <li>✅ {__('Performance monitoring', 'performance-optimisation')}</li>
            </ul>
        </div>

        <div className="wppo-wizard-actions">
            <Button variant="primary" onClick={onNext}>
                {__('Get Started', 'performance-optimisation')}
            </Button>
        </div>
    </div>
);

const SystemCheckStep: React.FC<any> = ({ systemInfo, onNext, onPrev }) => {
    if (!systemInfo) {
        return (
            <div className="wppo-loading">
                <Spinner />
                <p>{__('Checking system requirements...', 'performance-optimisation')}</p>
            </div>
        );
    }

    const checks = [
        {
            name: __('PHP Version', 'performance-optimisation'),
            status: parseFloat(systemInfo.php_version) >= 7.4 ? 'pass' : 'fail',
            value: systemInfo.php_version,
            requirement: '7.4+',
        },
        {
            name: __('WordPress Version', 'performance-optimisation'),
            status: parseFloat(systemInfo.wp_version) >= 6.2 ? 'pass' : 'fail',
            value: systemInfo.wp_version,
            requirement: '6.2+',
        },
        {
            name: __('Memory Limit', 'performance-optimisation'),
            status: parseInt(systemInfo.memory_limit) >= 128 ? 'pass' : 'warning',
            value: systemInfo.memory_limit,
            requirement: '128M+',
        },
        {
            name: __('Cache Directory Writable', 'performance-optimisation'),
            status: systemInfo.cache_writable ? 'pass' : 'fail',
            value: systemInfo.cache_writable ? __('Yes', 'performance-optimisation') : __('No', 'performance-optimisation'),
            requirement: __('Required', 'performance-optimisation'),
        },
    ];

    const allPassed = checks.every(check => check.status !== 'fail');

    return (
        <div className="wppo-system-check">
            <h2>{__('System Requirements Check', 'performance-optimisation')}</h2>
            
            <div className="wppo-checks-list">
                {checks.map((check, index) => (
                    <div key={index} className={`wppo-check-item wppo-check-${check.status}`}>
                        <div className="wppo-check-icon">
                            {check.status === 'pass' ? '✅' : check.status === 'warning' ? '⚠️' : '❌'}
                        </div>
                        <div className="wppo-check-details">
                            <strong>{check.name}</strong>
                            <span>{check.value} ({__('Required:', 'performance-optimisation')} {check.requirement})</span>
                        </div>
                    </div>
                ))}
            </div>

            {!allPassed && (
                <Notice status="warning">
                    {__('Some requirements are not met. The plugin may not work optimally.', 'performance-optimisation')}
                </Notice>
            )}

            <div className="wppo-extensions-check">
                <h3>{__('Optional Extensions', 'performance-optimisation')}</h3>
                <div className="wppo-extensions-grid">
                    <div className={`wppo-extension ${systemInfo.extensions.gd ? 'available' : 'missing'}`}>
                        <span>GD Extension</span>
                        <span>{systemInfo.extensions.gd ? '✅' : '❌'}</span>
                    </div>
                    <div className={`wppo-extension ${systemInfo.extensions.imagick ? 'available' : 'missing'}`}>
                        <span>ImageMagick</span>
                        <span>{systemInfo.extensions.imagick ? '✅' : '❌'}</span>
                    </div>
                    <div className={`wppo-extension ${systemInfo.extensions.redis ? 'available' : 'missing'}`}>
                        <span>Redis</span>
                        <span>{systemInfo.extensions.redis ? '✅' : '❌'}</span>
                    </div>
                </div>
            </div>

            <div className="wppo-wizard-actions">
                <Button onClick={onPrev}>{__('Previous', 'performance-optimisation')}</Button>
                <Button variant="primary" onClick={onNext}>
                    {__('Continue', 'performance-optimisation')}
                </Button>
            </div>
        </div>
    );
};

const OptimizationLevelStep: React.FC<any> = ({ settings, onSettingsChange, onNext, onPrev }) => {
    const [selectedLevel, setSelectedLevel] = useState('balanced');

    const levels = [
        {
            id: 'conservative',
            title: __('Conservative', 'performance-optimisation'),
            description: __('Safe optimizations with minimal risk', 'performance-optimisation'),
            icon: '🛡️',
            features: [
                __('Basic caching', 'performance-optimisation'),
                __('Image lazy loading', 'performance-optimisation'),
                __('CSS minification', 'performance-optimisation'),
            ],
        },
        {
            id: 'balanced',
            title: __('Balanced', 'performance-optimisation'),
            description: __('Good performance with reasonable compatibility', 'performance-optimisation'),
            icon: '⚖️',
            features: [
                __('Advanced caching', 'performance-optimisation'),
                __('Image optimization', 'performance-optimisation'),
                __('CSS/JS minification', 'performance-optimisation'),
                __('Database cleanup', 'performance-optimisation'),
            ],
        },
        {
            id: 'aggressive',
            title: __('Aggressive', 'performance-optimisation'),
            description: __('Maximum performance (test thoroughly)', 'performance-optimisation'),
            icon: '🚀',
            features: [
                __('All optimizations enabled', 'performance-optimisation'),
                __('File combining', 'performance-optimisation'),
                __('Critical CSS inlining', 'performance-optimisation'),
                __('Advanced database optimization', 'performance-optimisation'),
            ],
        },
    ];

    const handleLevelSelect = (level: string) => {
        setSelectedLevel(level);
        
        const levelSettings = {
            conservative: {
                caching: { page_cache_enabled: true, cache_ttl: 3600 },
                minification: { minify_css: true, minify_js: false },
                images: { lazy_loading: true, convert_to_webp: false },
            },
            balanced: {
                caching: { page_cache_enabled: true, cache_ttl: 3600, gzip_compression: true },
                minification: { minify_css: true, minify_js: true, minify_html: true },
                images: { lazy_loading: true, convert_to_webp: true, compression_quality: 85 },
                database: { cleanup_transients: true },
            },
            aggressive: {
                caching: { page_cache_enabled: true, cache_ttl: 7200, gzip_compression: true },
                minification: { minify_css: true, minify_js: true, minify_html: true, combine_css: true },
                images: { lazy_loading: true, convert_to_webp: true, convert_to_avif: true, compression_quality: 80 },
                database: { cleanup_revisions: true, cleanup_transients: true, optimize_tables: true },
                advanced: { disable_emojis: true, remove_query_strings: true },
            },
        };

        onSettingsChange({ ...settings, ...levelSettings[level] });
    };

    return (
        <div className="wppo-optimization-level">
            <h2>{__('Choose Your Optimization Level', 'performance-optimisation')}</h2>
            <p>{__('Select the optimization level that best fits your needs. You can always adjust individual settings later.', 'performance-optimisation')}</p>

            <div className="wppo-levels-grid">
                {levels.map((level) => (
                    <div
                        key={level.id}
                        className={`wppo-level-card ${selectedLevel === level.id ? 'selected' : ''}`}
                        onClick={() => handleLevelSelect(level.id)}
                    >
                        <div className="wppo-level-icon">{level.icon}</div>
                        <h3>{level.title}</h3>
                        <p>{level.description}</p>
                        <ul>
                            {level.features.map((feature, index) => (
                                <li key={index}>{feature}</li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>

            <div className="wppo-wizard-actions">
                <Button onClick={onPrev}>{__('Previous', 'performance-optimisation')}</Button>
                <Button variant="primary" onClick={onNext}>
                    {__('Continue', 'performance-optimisation')}
                </Button>
            </div>
        </div>
    );
};

const CompleteStep: React.FC<any> = () => (
    <div className="wppo-complete-step">
        <div className="wppo-success-icon">🎉</div>
        <h2>{__('Setup Complete!', 'performance-optimisation')}</h2>
        <p>{__('Your site has been optimized for performance. You can now enjoy faster loading times and better user experience.', 'performance-optimisation')}</p>
        
        <div className="wppo-next-steps">
            <h3>{__('What\'s Next?', 'performance-optimisation')}</h3>
            <ul>
                <li>{__('Monitor your site\'s performance in the dashboard', 'performance-optimisation')}</li>
                <li>{__('Fine-tune settings based on your needs', 'performance-optimisation')}</li>
                <li>{__('Check the recommendations for further improvements', 'performance-optimisation')}</li>
            </ul>
        </div>

        <div className="wppo-wizard-actions">
            <Button variant="primary" href="/wp-admin/admin.php?page=performance-optimisation">
                {__('Go to Dashboard', 'performance-optimisation')}
            </Button>
        </div>
    </div>
);

// Placeholder components for other steps
const SiteAnalysisStep: React.FC<any> = ({ onNext, onPrev }) => (
    <div className="wppo-site-analysis">
        <h2>{__('Site Analysis', 'performance-optimisation')}</h2>
        <p>{__('Analyzing your site...', 'performance-optimisation')}</p>
        <div className="wppo-wizard-actions">
            <Button onClick={onPrev}>{__('Previous', 'performance-optimisation')}</Button>
            <Button variant="primary" onClick={onNext}>{__('Continue', 'performance-optimisation')}</Button>
        </div>
    </div>
);

const FeaturesStep: React.FC<any> = ({ onNext, onPrev, onComplete, loading }) => (
    <div className="wppo-features-step">
        <h2>{__('Feature Selection', 'performance-optimisation')}</h2>
        <p>{__('Review and adjust your selected features.', 'performance-optimisation')}</p>
        <div className="wppo-wizard-actions">
            <Button onClick={onPrev}>{__('Previous', 'performance-optimisation')}</Button>
            <Button variant="primary" onClick={onComplete} disabled={loading}>
                {loading ? <Spinner /> : __('Complete Setup', 'performance-optimisation')}
            </Button>
        </div>
    </div>
);

export default SetupWizard;
