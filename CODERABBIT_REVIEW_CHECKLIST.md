# CodeRabbit Review Issues Checklist

This document provides a systematic approach to resolving all CodeRabbit review issues. Check off each item as you complete it.

## 🎯 Frontend/React Components Issues

### PresetStep.tsx Issues
- [ ] **Lines 118-131**: Remove duplicate ARIA attributes from card div, make it a proper label for radio input
- [ ] **Lines 146-147 & 208-217**: Add missing `id` attributes for `aria-describedby` targets
- [ ] **Lines 222-223**: Apply same radio/label fixes as above

### WizardProgressBar.tsx Issues  
- [ ] **Lines 14-15**: Add guards for `totalSteps <= 0`, clamp percentage to [0,100] range

### MetricsChart.tsx Issues
- [ ] **Lines 11-22, 31-55, 88-103**: Add i18n imports and localize all hardcoded strings
- [ ] **Tooltip formatter**: Fix unit handling - use 'ms' for FID, 's' for others

### StatsOverview.tsx Issues
- [ ] **Lines 11-14, 45-63, 66-88, 90-113**: Add i18n and localize all visible strings
- [ ] **Lines 20-28**: Fix `formatBytes` function - clamp array index for TB+ values

### Type Definition Issues
- [ ] **types/index.ts Lines 8-15**: Add proper React type imports
- [ ] **wizard.tsx Lines 51-59**: Make `wppoWizardData` optional in Window interface

### Build Configuration Issues
- [ ] **tsconfig.json Lines 12-16**: Add webpack config for TypeScript path aliases
- [ ] **build/index.css Line 2 & 4**: Fix duplicate `.wppo-button` definitions
- [ ] **build/index.css Line 4**: Fix malformed animation shorthands
- [ ] **build/wizard.css Line 1**: Fix malformed animation declarations

## 📚 Documentation Issues

### API.md Issues
- [ ] **Lines 57-107**: Update CacheUtil method signatures to match implementation
- [ ] **Lines 313-319**: Update Rate Limiting section with exact endpoint limits

## 🔧 Backend/PHP Issues

### Admin/Metabox.php Issues
- [ ] **Lines 243-264**: Add file extension validation for relative URLs
- [ ] **Lines 297-309**: Fix device prefix logic to include universal URLs

### App.tsx Issues
- [ ] **Lines 75-84, 100-107, 127-134**: Add guards for undefined nested config objects
- [ ] **Lines 143-146**: Wire Save/Reset buttons to actual handlers or disable them

### Analytics Components Issues
- [ ] **AnalyticsDashboard.tsx Lines 79-86**: Add period query parameter and credentials
- [ ] **AnalyticsDashboard.tsx Lines 114-121**: Fix export URL construction and add credentials
- [ ] **AnalyticsDashboard.tsx Lines 173-176**: Set loading state in retry handler
- [ ] **DashboardChart.tsx Lines 33-40**: Remove default prop to allow getOptimalChartType usage
- [ ] **DashboardChart.tsx Lines 61-68**: Guard trend calculation against division by zero
- [ ] **DashboardChart.tsx Lines 103-107**: Localize "Current Avg:" string
- [ ] **InteractiveChart.tsx Lines 103-139**: Fix variable shadowing in CustomTooltip
- [ ] **InteractiveChart.tsx Lines 148-242**: Fix infinite recursion in switch default, add pie case
- [ ] **OptimizationStatus.tsx Lines 31-34, 55-56, 63-71**: Fix percentage calculation and add ARIA
- [ ] **RecommendationsList.tsx Lines 10-16, 63-67, 95-103**: Add stable id field, use for React keys

### Common Components Issues
- [ ] **Button.tsx Lines 77-85**: Add explicit `type="button"` default
- [ ] **ContextualHelp.tsx Lines 171-179**: Add `showOnboarding` to useEffect dependencies
- [ ] **HelpPanel.tsx Lines 51-57, 77-83**: Add keyboard handlers and aria-controls
- [ ] **HelpPanel.tsx Lines 92-98**: Sanitize HTML content with DOMPurify
- [ ] **HelpPanel.tsx Lines 138-144, 150-156**: Add noopener,noreferrer to window.open
- [ ] **HelpTooltip.tsx Line 4, 23-27, 47, 57**: Use React.useId for unique tooltip IDs
- [ ] **HelpTooltip.tsx Lines 37-45, 56-61**: Move hover handlers to container
- [ ] **HelpTooltip.tsx Lines 39-48**: Replace div with native button element
- [ ] **OnboardingTour.tsx Lines 41-47, 154-156**: Add cleanup for highlights on unmount
- [ ] **OnboardingTour.tsx Lines 187-191**: Sanitize step.content with DOMPurify

### SCSS/Styling Issues
- [ ] **InteractiveOptimizationControls.scss Lines 31-35, 73-78, 128-133, 141-146**: Fix text contrast colors
- [ ] **InteractiveOptimizationControls.scss Lines 190-194**: Fix animation selector nesting
- [ ] **InteractiveOptimizationControls.scss Lines 237-254, 255-300**: Add prefers-reduced-motion support
- [ ] **_mixins.scss Lines 240-246**: Replace deprecated percentage() with sass:math
- [ ] **_utilities.scss Lines 88-90**: Fix .wppo-bg-white to use proper white token

### Component Logic Issues
- [ ] **RealTimeMonitor.tsx Lines 42-66**: Add API URL guards and credentials
- [ ] **SetupWizard.tsx Lines 59-75**: Use URL API for endpoint construction, add credentials
- [ ] **SiteDetectionStep.tsx Lines 73-81, 94-101**: Fix URL concatenation and add credentials
- [ ] **SummaryStep.tsx Lines 73-247**: Localize all hardcoded strings
- [ ] **Dashboard.tsx Lines 31-51**: Add API URL guards, credentials, and error handling
- [ ] **Dashboard.tsx Lines 100-121, 126-130**: Fix numeric checks to handle 0 values properly
- [ ] **InteractiveOptimizationControls.tsx Line 12**: Fix barrel import paths
- [ ] **InteractiveOptimizationControls.tsx Lines 270-276, 283-289**: Fix Select value/onChange type consistency

## 🔒 Security & Backend Issues

### Admin/Admin.php Issues
- [ ] **Lines 232-233**: Add null check for cacheService before getCacheStats()
- [ ] **Line 291**: Add null check before clearAllCache()
- [ ] **Line 324**: Add null check before clearCache()
- [ ] **Line 346**: Add null check before get_settings()
- [ ] **Line 383**: Add null checks in getAdminData method
- [ ] **Line 494**: Guard against empty memory_limit string

### API Controllers Issues
- [ ] **AnalyticsController.php Line 515**: Sanitize CSV fields to prevent injection
- [ ] **ApiRouter.php Line 28**: Rename NAMESPACE constant (reserved keyword)
- [ ] **ApiRouter.php Lines 71-79, 149-151, 159-161**: Fix broken permission callbacks
- [ ] **ApiRouter.php Lines 120-141**: Add path validation and sanitization
- [ ] **ApiRouter.php Lines 169-241**: Fix inconsistent permission callbacks for settings
- [ ] **ApiRouter.php Lines 248-313**: Fix optimization routes permission callbacks
- [ ] **ApiRouter.php Lines 419-489**: Fix recommendations permission callbacks
- [ ] **ApiRouter.php Lines 507-557**: Remove debug logs, fix wizard permission callbacks
- [ ] **ApiRouter.php Lines 564-615**: Fix inconsistent presets permission callbacks
- [ ] **ApiRouter.php Lines 622-654**: Fix utility routes and sanitize pagination
- [ ] **BaseController.php Lines 464-467**: Validate and sanitize IP address
- [ ] **CacheController.php Lines 218-220**: Remove legacy class existence check
- [ ] **CacheController.php Lines 284-291, 335-342**: Use container services instead of new instances
- [ ] **CacheController.php Lines 377-386, 438-447**: Add exception handling for directory iteration
- [ ] **ImageOptimizationController.php Line 406**: Add missing closing brace and catch block

## ⚙️ Configuration Issues

### CodeRabbit Configuration
- [ ] **.coderabbit.yaml Line 1**: Fix language field (use locale like "en-US")
- [ ] **.coderabbit.yaml Lines 61-66**: Fix phpstan.level to string, remove psalm from tools
- [ ] **.coderabbit.yaml Lines 67-72**: Remove unsupported phpcs/phpmd keys
- [ ] **.coderabbit.yaml Lines 73-80**: Replace "ignore" with "path_filters" negative globs
- [ ] **.coderabbit.yaml Lines 85-88**: Replace "enabled" with "scope" in knowledge_base

### GitHub Workflows
- [ ] **.github/workflows/test.yml Lines 28-31, 39-47, 55-66, 74-84, 86-99, 100-104**: Fix YAML indentation
- [ ] **.github/workflows/test.yml Lines 29-31, 41, 68, 78, 89**: Update actions to v4
- [ ] **.github/workflows/test.yml Lines 36-37, 95-96**: Remove obsolete "mysql" extension
- [ ] **Security scan job**: Replace with Semgrep + SARIF upload

### Composer Configuration
- [ ] **composer.json Lines 16-30**: Add phpstan/extension-installer to allow-plugins
- [ ] **composer.json**: Ensure psalm.xml exists
- [ ] **composer.json**: Create performance test scripts if missing
- [ ] **composer.json**: Remove leftover HTML minifier references

## 📋 Systematic Resolution Process

### Phase 1: Critical Security & Functionality (Priority 1)
1. Fix all null pointer exceptions in Admin.php
2. Fix API router permission callbacks
3. Add input validation and sanitization
4. Fix CSV injection vulnerability
5. Add proper error handling

### Phase 2: Frontend Accessibility & UX (Priority 2)
1. Fix ARIA attributes and keyboard navigation
2. Add proper form labels and descriptions
3. Fix React component logic issues
4. Add internationalization support
5. Fix styling and animation issues

### Phase 3: Configuration & Build (Priority 3)
1. Fix TypeScript configuration and path aliases
2. Update GitHub workflows and dependencies
3. Fix CodeRabbit configuration
4. Update documentation
5. Clean up build artifacts

### Phase 4: Code Quality & Performance (Priority 4)
1. Fix code style and linting issues
2. Add proper type definitions
3. Optimize component rendering
4. Add proper error boundaries
5. Improve accessibility compliance

## ✅ Completion Tracking

- [ ] **Phase 1 Complete** (0/5 items)
- [ ] **Phase 2 Complete** (0/5 items)  
- [ ] **Phase 3 Complete** (0/5 items)
- [ ] **Phase 4 Complete** (0/5 items)

## 🔄 Testing Checklist

After each phase:
- [ ] Run `npm run lint` and fix any issues
- [ ] Run `composer run phpcs` and fix any issues
- [ ] Test admin functionality manually
- [ ] Test frontend components manually
- [ ] Run automated tests if available
- [ ] Commit changes with descriptive messages

## 📝 Notes

- Always test changes in a development environment first
- Create backups before making significant changes
- Document any breaking changes or new requirements
- Update this checklist as issues are resolved
- Use git branches for major refactoring work

---

**Last Updated**: 2025-08-31  
**Total Issues**: 80+ individual items across 4 priority phases
