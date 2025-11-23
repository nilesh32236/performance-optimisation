# API Quick Reference

## Making API Calls in Admin Pages

### Pattern to Use
```typescript
const apiUrl = (window as any).wppoAdmin?.apiUrl || '/wp-json/performance-optimisation/v1';
const nonce = (window as any).wppoAdmin?.nonce || '';

const response = await fetch(`${apiUrl}/your-endpoint`, {
    method: 'GET', // or 'POST', 'PUT', 'DELETE'
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce,
    },
    body: JSON.stringify(data), // for POST/PUT requests
});

const result = await response.json();
```

## Available Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/cache/stats` | Get cache statistics |
| POST | `/cache/clear` | Clear cache (body: `{type: 'all'}`) |
| GET | `/settings` | Get all settings |
| POST | `/settings` | Update settings |
| GET | `/analytics/dashboard` | Dashboard analytics |
| GET | `/analytics/metrics` | Performance metrics |
| GET | `/analytics/real-time` | Real-time data |
| POST | `/optimization/{taskId}` | Run optimization |
| POST | `/wizard/setup` | Complete wizard |
| GET | `/wizard/analysis` | Site analysis |
| GET | `/recommendations` | Get recommendations |

## Examples

### Get Cache Stats
```typescript
const apiUrl = window.wppoAdmin?.apiUrl;
const response = await fetch(`${apiUrl}/cache/stats`, {
    headers: { 'X-WP-Nonce': window.wppoAdmin?.nonce }
});
const data = await response.json();
console.log(data.data); // Cache statistics
```

### Clear Cache
```typescript
const apiUrl = window.wppoAdmin?.apiUrl;
const response = await fetch(`${apiUrl}/cache/clear`, {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': window.wppoAdmin?.nonce
    },
    body: JSON.stringify({ type: 'all' })
});
const result = await response.json();
```

### Save Settings
```typescript
const apiUrl = window.wppoAdmin?.apiUrl;
const response = await fetch(`${apiUrl}/settings`, {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': window.wppoAdmin?.nonce
    },
    body: JSON.stringify({
        cache_settings: {
            page_cache_enabled: true,
            cache_compression: true
        }
    })
});
const result = await response.json();
```

## Response Format

All API responses follow this format:

```json
{
    "success": true,
    "data": {
        // Response data here
    },
    "message": "Success message"
}
```

Error responses:
```json
{
    "success": false,
    "message": "Error message",
    "code": "error_code"
}
```

## Context-Specific Variables

### Admin Pages
```javascript
window.wppoAdmin.apiUrl
window.wppoAdmin.nonce
```

### Wizard Pages
```javascript
window.wppoWizardData.apiUrl
window.wppoWizardData.nonce
```

### Admin Bar
```javascript
window.wppoAdminBar.apiUrl
window.wppoAdminBar.nonce
```

## Common Patterns

### With Error Handling
```typescript
try {
    const apiUrl = window.wppoAdmin?.apiUrl;
    const response = await fetch(`${apiUrl}/cache/stats`, {
        headers: { 'X-WP-Nonce': window.wppoAdmin?.nonce }
    });
    
    if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
    }
    
    const result = await response.json();
    
    if (result.success) {
        console.log('Success:', result.data);
    } else {
        console.error('API Error:', result.message);
    }
} catch (error) {
    console.error('Request failed:', error);
}
```

### With Loading State
```typescript
const [loading, setLoading] = useState(false);

const fetchData = async () => {
    setLoading(true);
    try {
        const apiUrl = window.wppoAdmin?.apiUrl;
        const response = await fetch(`${apiUrl}/cache/stats`, {
            headers: { 'X-WP-Nonce': window.wppoAdmin?.nonce }
        });
        const result = await response.json();
        // Handle result
    } catch (error) {
        console.error(error);
    } finally {
        setLoading(false);
    }
};
```

## DO NOT Use Hardcoded URLs ❌

```typescript
// ❌ WRONG
fetch('/wp-json/performance-optimisation/v1/cache/stats', {

// ✅ CORRECT
const apiUrl = window.wppoAdmin?.apiUrl;
fetch(`${apiUrl}/cache/stats`, {
```

## Why Use Dynamic URLs?

1. **Flexibility:** Works with different WordPress installations
2. **Subdirectory Support:** Works when WordPress is in a subdirectory
3. **Multisite Compatible:** Works with WordPress multisite
4. **Testing:** Easier to test with different environments
5. **Maintainability:** Single source of truth for API URL
