# API Integration - Images Tab

## ✅ Updated to Match Caching Tab Pattern

The Images Tab now uses the exact same API pattern as the Caching Tab for consistency.

## 🔌 API Endpoints Used

### 1. Get Image Statistics
```javascript
GET /wp-json/performance-optimisation/v1/images/stats
Headers: { 'X-WP-Nonce': wppoAdmin.nonce }

Response:
{
  success: true,
  data: {
    total_images: 348,
    optimized_images: 248,
    pending_images: 100,
    total_savings: {
      savings_bytes: 12400000,
      savings_percentage: 35.5
    }
  }
}
```

### 2. Get Settings
```javascript
GET /wp-json/performance-optimisation/v1/settings
Headers: { 'X-WP-Nonce': wppoAdmin.nonce }

Response:
{
  success: true,
  data: {
    settings: {
      image_optimization: {
        auto_convert_on_upload: true,
        webp_conversion: true,
        avif_conversion: false,
        quality: 82,
        lazy_load_enabled: true,
        exclude_first_images: 2,
        exclude_images: '',
        use_svg_placeholder: true,
        serve_next_gen: true,
        max_width: 1920
      }
    }
  }
}
```

### 3. Save Settings
```javascript
POST /wp-json/performance-optimisation/v1/settings
Headers: {
  'Content-Type': 'application/json',
  'X-WP-Nonce': wppoAdmin.nonce
}
Body: {
  settings: {
    image_optimization: {
      // ... settings object
    }
  }
}

Response:
{
  success: true,
  message: 'Settings saved successfully!'
}
```

### 4. Bulk Optimize Images
```javascript
POST /wp-json/performance-optimisation/v1/images/batch-optimize
Headers: {
  'Content-Type': 'application/json',
  'X-WP-Nonce': wppoAdmin.nonce
}
Body: {
  limit: 50,
  options: {
    quality: 82
  }
}

Response:
{
  success: true,
  message: 'Optimization started successfully!',
  data: {
    batch_id: 'uuid-here',
    total: 50,
    status: 'processing'
  }
}
```

## 🎯 Key Features Matching Caching Tab

### 1. **Global API Configuration**
```typescript
const apiUrl = (window as any).wppoAdmin?.apiUrl || '/wp-json/performance-optimisation/v1';
const nonce = (window as any).wppoAdmin?.nonce || '';
```

### 2. **Unified Data Fetching**
```typescript
const fetchImageData = async () => {
  // Fetch stats
  const statsResponse = await fetch(`${apiUrl}/images/stats`, {
    headers: { 'X-WP-Nonce': nonce }
  });
  
  // Fetch settings
  const settingsResponse = await fetch(`${apiUrl}/settings`, {
    headers: { 'X-WP-Nonce': nonce }
  });
};
```

### 3. **Consistent Settings Save**
```typescript
const handleSaveSettings = async () => {
  const response = await fetch(`${apiUrl}/settings`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': nonce
    },
    body: JSON.stringify({ 
      settings: {
        image_optimization: settings
      }
    })
  });
};
```

### 4. **Notification System**
```typescript
const showNotification = (type: 'success' | 'error', message: string) => {
  setNotification({ type, message });
  setTimeout(() => setNotification(null), 5000);
};
```

### 5. **Loading States**
```typescript
const [loading, setLoading] = useState(true);
const [saving, setSaving] = useState(false);
const [optimizing, setOptimizing] = useState(false);
```

### 6. **Refresh Data Button**
```typescript
<button onClick={fetchImageData}>
  Refresh Data
</button>
```

## 📊 Data Flow

### Initial Load
```
Component Mount
    ↓
fetchImageData()
    ↓
GET /images/stats → setStats()
    ↓
GET /settings → setSettings()
    ↓
setLoading(false)
```

### Save Settings
```
User clicks "Save Settings"
    ↓
handleSaveSettings()
    ↓
POST /settings with image_optimization
    ↓
Show success/error notification
    ↓
Auto-hide after 5 seconds
```

### Bulk Optimize
```
User clicks "Optimize All Images"
    ↓
Confirm dialog
    ↓
handleOptimizeAll()
    ↓
POST /images/batch-optimize
    ↓
Show success notification
    ↓
Wait 2 seconds
    ↓
fetchImageData() to refresh stats
```

## 🔧 Settings Structure

Settings are stored in WordPress options:
```php
// Option name: wppo_settings
// Structure:
[
  'cache_settings' => [...],
  'image_optimization' => [
    'auto_convert_on_upload' => true,
    'webp_conversion' => true,
    'avif_conversion' => false,
    'quality' => 82,
    'lazy_load_enabled' => true,
    'exclude_first_images' => 2,
    'exclude_images' => '',
    'use_svg_placeholder' => true,
    'serve_next_gen' => true,
    'max_width' => 1920
  ]
]
```

## 🎨 UI Components Matching Caching Tab

### 1. **Loading Spinner**
```tsx
if (loading && !stats) {
  return (
    <div className="flex items-center justify-center p-12">
      <div className="animate-spin w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full"></div>
      <p>Loading image data...</p>
    </div>
  );
}
```

### 2. **Notification Banner**
```tsx
{notification && (
  <div className={`p-4 rounded-lg border-2 ${
    notification.type === 'success' 
      ? 'bg-green-50 border-green-200 text-green-800' 
      : 'bg-red-50 border-red-200 text-red-800'
  }`}>
    <p>{notification.message}</p>
  </div>
)}
```

### 3. **Toggle Switches**
```tsx
<label className="relative inline-flex items-center cursor-pointer">
  <input 
    type="checkbox" 
    className="sr-only peer" 
    checked={settings.webp_conversion}
    onChange={(e) => updateSetting('webp_conversion', e.target.checked)}
  />
  <div className="w-14 h-8 bg-gray-300 peer-checked:bg-blue-500 rounded-full..."></div>
</label>
```

### 4. **Action Buttons**
```tsx
<button 
  onClick={handleSaveSettings}
  disabled={saving}
  className="px-6 py-3 bg-blue-500 text-white rounded-lg disabled:opacity-50"
>
  {saving ? 'Saving...' : 'Save Settings'}
</button>
```

## 🧪 Testing

### Test API Endpoints
```bash
# 1. Test stats endpoint
curl http://yoursite.com/wp-json/performance-optimisation/v1/images/stats \
  -H "X-WP-Nonce: YOUR_NONCE"

# 2. Test settings endpoint
curl http://yoursite.com/wp-json/performance-optimisation/v1/settings \
  -H "X-WP-Nonce: YOUR_NONCE"

# 3. Test save settings
curl -X POST http://yoursite.com/wp-json/performance-optimisation/v1/settings \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{"settings":{"image_optimization":{"quality":85}}}'

# 4. Test bulk optimize
curl -X POST http://yoursite.com/wp-json/performance-optimisation/v1/images/batch-optimize \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{"limit":10}'
```

### Test in Browser Console
```javascript
// Get current settings
fetch('/wp-json/performance-optimisation/v1/settings', {
  headers: { 'X-WP-Nonce': wppoAdmin.nonce }
})
.then(r => r.json())
.then(d => console.log(d));

// Get image stats
fetch('/wp-json/performance-optimisation/v1/images/stats', {
  headers: { 'X-WP-Nonce': wppoAdmin.nonce }
})
.then(r => r.json())
.then(d => console.log(d));
```

## ✅ Consistency Checklist

- [x] Uses `wppoAdmin.apiUrl` for base URL
- [x] Uses `wppoAdmin.nonce` for authentication
- [x] Fetches data on component mount
- [x] Shows loading spinner during initial load
- [x] Displays success/error notifications
- [x] Auto-hides notifications after 5 seconds
- [x] Has "Refresh Data" button
- [x] Disables buttons during operations
- [x] Shows loading text on buttons
- [x] Uses same settings structure
- [x] Follows same error handling pattern
- [x] Matches UI component styling

## 🎉 Result

The Images Tab now works exactly like the Caching Tab:
- Same API pattern
- Same data fetching
- Same notification system
- Same loading states
- Same button behavior
- Same error handling

Everything is consistent and production-ready!
