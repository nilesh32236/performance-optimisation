# Performance Optimisation Plugin - API Routes

Base URL: `http://localhost/awm/wp-json/performance-optimisation/v1`

All requests require `X-WP-Nonce` header for authentication.

## Cache Management

### Get Cache Statistics
```
GET /cache/stats
```
Returns cache statistics including size, hit ratio, and file counts.

### Clear Cache
```
POST /cache/clear
Body: { "type": "all" | "page" | "object" | "browser" }
```
Clears specified cache type.

## Settings

### Get Settings
```
GET /settings
```
Returns all plugin settings.

### Update Settings
```
POST /settings
Body: { "cache_settings": {...}, "optimization_settings": {...}, ... }
```
Updates plugin settings.

## Analytics

### Get Dashboard Data
```
GET /analytics/dashboard
```
Returns dashboard analytics data.

### Get Metrics
```
GET /analytics/metrics?metric={metric}&period={period}&start_date={date}&end_date={date}
```
Returns performance metrics for specified period.

### Get Real-time Data
```
GET /analytics/real-time
```
Returns real-time performance data.

## Optimization

### Run Optimization Task
```
POST /optimization/{taskId}
Body: { "action": "start" | "stop" }
```
Starts or stops an optimization task.

## Setup Wizard

### Complete Wizard Setup
```
POST /wizard/setup
Body: { "settings": {...} }
```
Completes the setup wizard with provided settings.

### Get Site Analysis
```
GET /wizard/analysis
```
Returns site analysis data for wizard.

## Recommendations

### Get Recommendations
```
GET /recommendations
```
Returns optimization recommendations.

## Testing

### Run Test
```
POST /test
Body: { "test_type": "..." }
```
Runs a specific test.

## Error Logging

### Log Error
```
POST /log-error
Body: { "error": "...", "context": {...} }
```
Logs client-side errors.

## Usage in JavaScript

All admin pages have access to `window.wppoAdmin` object:

```javascript
const apiUrl = window.wppoAdmin.apiUrl; // 'http://localhost/awm/wp-json/performance-optimisation/v1'
const nonce = window.wppoAdmin.nonce;

// Example API call
const response = await fetch(`${apiUrl}/cache/stats`, {
    headers: {
        'X-WP-Nonce': nonce
    }
});
```

## Wizard Pages

Wizard pages use `window.wppoWizardData`:

```javascript
const apiUrl = window.wppoWizardData.apiUrl;
const nonce = window.wppoWizardData.nonce;
```

## Admin Bar

Admin bar uses `window.wppoAdminBar`:

```javascript
const apiUrl = window.wppoAdminBar.apiUrl;
const nonce = window.wppoAdminBar.nonce;
```
