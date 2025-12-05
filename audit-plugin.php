<?php
/**
 * Comprehensive Plugin Audit
 */

class PluginAuditor
{
    private $issues      = array();
    private $suggestions = array();
    private $baseDir;

    public function __construct()
    {
        $this->baseDir = __DIR__;
    }

    public function run()
    {
        echo "=== Performance Optimisation Plugin Audit ===\n\n";

        $this->auditUIComponents();
        $this->auditBackendServices();
        $this->auditAPIEndpoints();
        $this->auditFeatureCompleteness();
        $this->auditUserExperience();
        $this->auditDocumentation();

        $this->printReport();
    }

    private function auditUIComponents()
    {
        echo "Auditing UI Components...\n";

        $components = array(
        'Dashboard'       => 'admin/src/components/Dashboard/Dashboard.tsx',
        'CachingTab'      => 'admin/src/components/CachingTab.tsx',
        'ImagesTab'       => 'admin/src/components/ImagesTab.tsx',
        'OptimizationTab' => 'admin/src/components/OptimizationTab.tsx',
        'AdvancedTab'     => 'admin/src/components/AdvancedTab.tsx',
        'PreloadTab'      => 'admin/src/components/PreloadTab.tsx',
        'SetupWizard'     => 'admin/src/components/Wizard/SetupWizard.tsx',
        'Analytics'       => 'admin/src/components/Analytics/AnalyticsDashboard.tsx',
        );

        foreach ( $components as $name => $path ) {
            $fullPath = $this->baseDir . '/' . $path;
            if (! file_exists($fullPath) ) {
                $this->issues[] = "Missing UI component: {$name}";
            } else {
                $content = file_get_contents($fullPath);

                // Check for error handling
                if (strpos($content, 'try') === false && strpos($content, 'catch') === false ) {
                    $this->suggestions[] = "{$name}: Add error handling (try-catch blocks)";
                }

                // Check for loading states
                if (strpos($content, 'loading') === false && strpos($content, 'isLoading') === false ) {
                    $this->suggestions[] = "{$name}: Consider adding loading states";
                }
            }
        }
    }

    private function auditBackendServices()
    {
        echo "Auditing Backend Services...\n";

        $services = array(
        'CacheService'                => 'includes/Services/CacheService.php',
        'ImageService'                => 'includes/Services/ImageService.php',
        'OptimizationService'         => 'includes/Services/OptimizationService.php',
        'AnalyticsService'            => 'includes/Services/AnalyticsService.php',
        'DatabaseOptimizationService' => 'includes/Services/DatabaseOptimizationService.php',
        'LazyLoadService'             => 'includes/Services/LazyLoadService.php',
        'HeartbeatService'            => 'includes/Services/HeartbeatService.php',
        'FontOptimizationService'     => 'includes/Services/FontOptimizationService.php',
        'ResourceHintsService'        => 'includes/Services/ResourceHintsService.php',
        );

        foreach ( $services as $name => $path ) {
            $fullPath = $this->baseDir . '/' . $path;
            if (! file_exists($fullPath) ) {
                $this->issues[] = "Missing service: {$name}";
            }
        }
    }

    private function auditAPIEndpoints()
    {
        echo "Auditing API Endpoints...\n";

        $controllers = array(
        'SettingsController'          => 'includes/Core/API/SettingsController.php',
        'CacheController'             => 'includes/Core/API/CacheController.php',
        'ImageOptimizationController' => 'includes/Core/API/ImageOptimizationController.php',
        'OptimizationController'      => 'includes/Core/API/OptimizationController.php',
        'AnalyticsController'         => 'includes/Core/API/AnalyticsController.php',
        'RecommendationsController'   => 'includes/Core/API/RecommendationsController.php',
        );

        foreach ( $controllers as $name => $path ) {
            $fullPath = $this->baseDir . '/' . $path;
            if (! file_exists($fullPath) ) {
                $this->issues[] = "Missing API controller: {$name}";
            } else {
                $content = file_get_contents($fullPath);

                // Check for rate limiting
                if (strpos($content, 'rate_limit') === false && strpos($content, 'RateLimiter') === false ) {
                    $this->suggestions[] = "{$name}: Consider adding rate limiting";
                }

                // Check for permission checks
                if (strpos($content, 'permission_callback') === false ) {
                    $this->issues[] = "{$name}: Missing permission callbacks";
                }
            }
        }
    }

    private function auditFeatureCompleteness()
    {
        echo "Auditing Feature Completeness...\n";

        $features = array(
        'Cache Preloading'       => array( 'includes/Services/CacheService.php', 'preload' ),
        'Critical CSS'           => array( 'includes/Services/AssetOptimizationService.php', 'critical' ),
        'CDN Integration'        => array( 'includes/Services/', 'cdn' ),
        'Database Cleanup'       => array( 'includes/Services/DatabaseOptimizationService.php', 'cleanup' ),
        'Export/Import Settings' => array( 'includes/Core/API/SettingsController.php', 'export' ),
        'Performance Reports'    => array( 'includes/Services/AnalyticsService.php', 'report' ),
        'Scheduled Optimization' => array( 'includes/Services/CronService.php', 'schedule' ),
        );

        foreach ( $features as $feature => $check ) {
            list($path, $keyword) = $check;
            $fullPath             = $this->baseDir . '/' . $path;

            if (is_dir($fullPath) ) {
                $found = false;
                foreach ( glob($fullPath . '*.php') as $file ) {
                    if (stripos(file_get_contents($file), $keyword) !== false ) {
                        $found = true;
                        break;
                    }
                }
                if (! $found ) {
                    $this->suggestions[] = "Feature enhancement: {$feature}";
                }
            } elseif (file_exists($fullPath) ) {
                if (stripos(file_get_contents($fullPath), $keyword) === false ) {
                    $this->suggestions[] = "Feature enhancement: {$feature}";
                }
            }
        }
    }

    private function auditUserExperience()
    {
        echo "Auditing User Experience...\n";

        // Check for notifications
        $imagesTab = $this->baseDir . '/admin/src/components/ImagesTab.tsx';
        if (file_exists($imagesTab) ) {
            $content = file_get_contents($imagesTab);
            if (strpos($content, 'notification') === false && strpos($content, 'toast') === false ) {
                $this->suggestions[] = 'ImagesTab: Add user notifications for actions';
            }
        }

        // Check for help/documentation
        $helpPanel = $this->baseDir . '/admin/src/components/Help/HelpPanel.tsx';
        if (! file_exists($helpPanel) ) {
            $this->suggestions[] = 'Add contextual help system';
        }

        // Check for onboarding
        $onboarding = $this->baseDir . '/admin/src/components/Help/OnboardingTour.tsx';
        if (! file_exists($onboarding) ) {
            $this->suggestions[] = 'Add onboarding tour for new users';
        }

        // Check for undo/rollback
        $settingsController = $this->baseDir . '/includes/Core/API/SettingsController.php';
        if (file_exists($settingsController) ) {
            $content = file_get_contents($settingsController);
            if (strpos($content, 'backup') === false && strpos($content, 'rollback') === false ) {
                $this->suggestions[] = 'Add settings backup/rollback functionality';
            }
        }
    }

    private function auditDocumentation()
    {
        echo "Auditing Documentation...\n";

        $docs = array(
        'README.md'               => 'Main documentation',
        'CHANGELOG.md'            => 'Version history',
        'docs/USER_GUIDE.md'      => 'User guide',
        'docs/API_REFERENCE.md'   => 'API documentation',
        'docs/DEVELOPER_GUIDE.md' => 'Developer guide',
        );

        foreach ( $docs as $file => $description ) {
            $fullPath = $this->baseDir . '/' . $file;
            if (! file_exists($fullPath) ) {
                $this->suggestions[] = "Missing documentation: {$description} ({$file})";
            }
        }
    }

    private function printReport()
    {
        echo "\n=== Audit Report ===\n\n";

        if (empty($this->issues) && empty($this->suggestions) ) {
            echo "✓ No critical issues found!\n";
            echo "✓ Plugin is well-structured and feature-complete.\n";
            return;
        }

        if (! empty($this->issues) ) {
            echo 'CRITICAL ISSUES (' . count($this->issues) . "):\n";
            foreach ( $this->issues as $issue ) {
                echo "  ✗ {$issue}\n";
            }
            echo "\n";
        }

        if (! empty($this->suggestions) ) {
            echo 'SUGGESTIONS FOR ENHANCEMENT (' . count($this->suggestions) . "):\n";
            foreach ( $this->suggestions as $suggestion ) {
                echo "  • {$suggestion}\n";
            }
        }
    }
}

$auditor = new PluginAuditor();
$auditor->run();
