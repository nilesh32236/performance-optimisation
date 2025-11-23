# API Routes Fix Summary

## Overview
All fetch requests in the admin settings pages now use `wppoAdmin.apiUrl` as the base URL instead of hardcoded paths.

## Base URL
```
http://localhost/awm/wp-json/performance-optimisation/v1
```

## Changes Made

### 1. CachingTab.tsx ✅ FIXED
**File:** `admin/src/components/CachingTab.tsx`

**Changes:**
- Added `apiUrl` constant in all functions that make fetch calls
- Updated 4 fetch calls to use dynamic base URL

**Before:**
```typescript
fetch('/wp-json/performance-optimisation/v1/cache/stats', {
fetch('/wp-json/performance-optimisation/v1/settings', {
fetch('/wp-json/performance-optimisation/v1/cache/clear', {
```

**After:**
```typescript
const apiUrl = (window as any).wppoAdmin?.apiUrl || '/wp-json/performance-optimisation/v1';
fetch(`${apiUrl}/cache/stats`, {
fetch(`${apiUrl}/settings`, {
fetch(`${apiUrl}/cache/clear`, {
```

### 2. admin-bar.js ✅ FIXED
**File:** `admin/src/admin-bar.js`

**Changes:**
- Fixed missing slash in URL (line 90)

**Before:**
```javascript
fetch( `${ apiUrl }clear-cache`, {
```

**After:**
```javascript
fetch( `${ apiUrl }/clear-cache`, {
```

## Files Already Using Correct Pattern

### Admin Pages (using window.wppoAdmin.apiUrl)
1. ✅ `InteractiveOptimizationControls.tsx`
2. ✅ `RealTimeMonitor.tsx`
3. ✅ `pages/Dashboard/Dashboard.tsx`
4. ✅ `Analytics/PerformanceChart.tsx`

### Wizard Pages (using window.wppoWizardData.apiUrl)
5. ✅ `Wizard/steps/SiteDetectionStep.tsx`
6. ✅ `Wizard/SetupWizard.tsx`

### Admin Bar (using window.wppoAdminBar.apiUrl)
7. ✅ `admin-bar.js`

### Utility Files (context-aware)
8. ✅ `utils/security.ts` - receives full URL as parameter
9. ✅ `utils/testing.ts` - receives apiUrl as parameter
10. ✅ `utils/errorHandler.ts` - receives apiUrl as parameter

## Available API Endpoints

### Cache Management
- `GET /cache/stats` - Get cache statistics
- `POST /cache/clear` - Clear cache

### Settings
- `GET /settings` - Get all settings
- `POST /settings` - Update settings

### Analytics
- `GET /analytics/dashboard` - Dashboard data
- `GET /analytics/metrics` - Performance metrics
- `GET /analytics/real-time` - Real-time data

### Optimization
- `POST /optimization/{taskId}` - Run optimization task

### Wizard
- `POST /wizard/setup` - Complete wizard
- `GET /wizard/analysis` - Site analysis

### Other
- `GET /recommendations` - Get recommendations
- `POST /test` - Run tests
- `POST /log-error` - Log errors

## Global Variables Available

### Admin Pages
```javascript
window.wppoAdmin = {
    apiUrl: 'http://localhost/awm/wp-json/performance-optimisation/v1',
    nonce: 'xxx',
    // ... other data
}
```

### Wizard Pages
```javascript
window.wppoWizardData = {
    apiUrl: 'http://localhost/awm/wp-json/performance-optimisation/v1',
    nonce: 'xxx',
    // ... other data
}
```

### Admin Bar
```javascript
window.wppoAdminBar = {
    apiUrl: 'http://localhost/awm/wp-json/performance-optimisation/v1',
    nonce: 'xxx',
    // ... other data
}
```

## Testing

### Build Status
✅ Webpack compiled successfully
- index.js: 37.1 KiB
- wizard.js: 35.9 KiB
- admin-bar.js: 2.72 KiB
- All CSS files generated correctly

### Verification Steps
1. Check CachingTab loads cache statistics
2. Verify settings can be saved
3. Test cache clear functionality
4. Confirm all API calls use correct base URL

## Best Practices Applied

1. **Consistent Pattern:** All fetch calls now follow the same pattern
2. **Fallback URLs:** Provided fallback for cases where wppoAdmin might not be available
3. **Type Safety:** Used TypeScript type assertions where needed
4. **Error Handling:** Maintained existing error handling patterns
5. **Nonce Authentication:** All requests include X-WP-Nonce header

## Next Steps

Consider creating a centralized API utility:

```typescript
// utils/api.ts
export const apiRequest = async (endpoint: string, options = {}) => {
    const apiUrl = (window as any).wppoAdmin?.apiUrl || '/wp-json/performance-optimisation/v1';
    const nonce = (window as any).wppoAdmin?.nonce || '';
    
    return fetch(`${apiUrl}${endpoint}`, {
        ...options,
        headers: {
            'X-WP-Nonce': nonce,
            'Content-Type': 'application/json',
            ...options.headers,
        },
    });
};

// Usage
const response = await apiRequest('/cache/stats');
```

This would further standardize API calls across the application.
