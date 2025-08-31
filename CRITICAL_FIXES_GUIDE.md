# Critical Fixes Quick Reference

## 🚨 Immediate Action Required (Security & Fatal Errors)

### 1. Fix Reserved Keyword Error (FATAL)
```php
// File: includes/Core/API/ApiRouter.php:28
// BEFORE:
const NAMESPACE = 'performance-optimisation/v1';

// AFTER:
const REST_NAMESPACE = 'performance-optimisation/v1';
// Update all references from self::NAMESPACE to self::REST_NAMESPACE
```

### 2. Fix Missing Method Closing Brace (FATAL)
```php
// File: includes/Core/API/ImageOptimizationController.php:406
// Add missing closing brace and catch block:
    } catch (\Exception $e) {
        error_log('Batch optimization error: ' . $e->getMessage());
        return new \WP_Error('optimization_failed', 'Batch optimization failed');
    }
}
```

### 3. Fix Null Pointer Exceptions
```php
// File: includes/Admin/Admin.php - Add null checks:
if ($this->cacheService !== null) {
    $cache_stats = $this->cacheService->getCacheStats();
} else {
    $cache_stats = [];
}
```

### 4. Fix CSV Injection Vulnerability
```php
// File: includes/Core/API/AnalyticsController.php:515
private function sanitize_csv_value(string $value): string {
    // Prefix dangerous characters
    if (preg_match('/^[=+\-@]/', $value)) {
        $value = "'" . $value;
    }
    // Escape quotes
    return str_replace('"', '""', $value);
}
```

## ⚡ High Priority Frontend Fixes

### 1. Fix TypeScript Path Aliases
```javascript
// Create webpack.config.js:
const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const TsconfigPathsPlugin = require('tsconfig-paths-webpack-plugin');

module.exports = {
    ...defaultConfig,
    resolve: {
        ...defaultConfig.resolve,
        plugins: [
            ...(defaultConfig.resolve.plugins || []),
            new TsconfigPathsPlugin({
                configFile: './admin/tsconfig.json'
            })
        ]
    }
};
```

### 2. Fix React Component Issues
```tsx
// PresetStep.tsx - Remove duplicate ARIA, use proper labels:
<label htmlFor={`preset-${preset.id}`} className="wppo-preset-card">
    <input
        id={`preset-${preset.id}`}
        type="radio"
        name="preset"
        value={preset.id}
        checked={selectedPreset === preset.id}
        onChange={() => setSelectedPreset(preset.id)}
    />
    {/* Remove role="radio", aria-checked, tabIndex, onKeyDown */}
</label>
```

### 3. Add Internationalization
```tsx
// Add to all components:
import { __ } from '@wordpress/i18n';

// Replace hardcoded strings:
<h2>{__('Choose Your Optimization Level', 'performance-optimisation')}</h2>
```

## 🔧 Configuration Fixes

### 1. Fix CodeRabbit Config
```yaml
# .coderabbit.yaml
language: en-US  # Not "php"
reviews:
  tools:
    phpstan:
      enabled: true
      level: "8"  # String, not number
    phpcs:
      enabled: true
    # Remove psalm from here
knowledge_base:
  learnings:
    scope: auto  # Not "enabled: true"
```

### 2. Fix GitHub Workflow
```yaml
# .github/workflows/test.yml - Fix indentation and versions:
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - name: Checkout code
        uses: actions/checkout@v4  # Not v3
        
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          extensions: dom, curl, libxml, mbstring, zip, mysqli, pdo_mysql
          # Remove "mysql" - it's obsolete
```

## 📋 Quick Fix Commands

```bash
# 1. Fix PHP syntax errors first
composer install
vendor/bin/phpcs --standard=WordPress includes/ --report=summary

# 2. Fix TypeScript issues
npm install tsconfig-paths-webpack-plugin
npm run build

# 3. Fix React component issues
npm run lint:js -- --fix

# 4. Test critical functionality
wp --allow-root plugin activate performance-optimisation
wp --allow-root plugin deactivate performance-optimisation
```

## 🎯 Priority Order

1. **FATAL ERRORS** (Prevents plugin from loading)
   - Reserved keyword fix
   - Missing closing braces
   - Null pointer exceptions

2. **SECURITY ISSUES** (Prevents exploitation)
   - CSV injection
   - Input validation
   - Permission callbacks

3. **FUNCTIONALITY ISSUES** (Prevents features from working)
   - API endpoints
   - Frontend components
   - Configuration files

4. **CODE QUALITY** (Improves maintainability)
   - Linting issues
   - Type definitions
   - Documentation

Use the main checklist for systematic resolution, but start with these critical fixes first!
