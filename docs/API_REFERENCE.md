# Performance Optimisation Plugin - API Reference

## Overview

The Performance Optimisation plugin provides a comprehensive REST API for managing performance settings, cache operations, and analytics data. All endpoints follow WordPress REST API conventions and require proper authentication.

## Base URL
```
https://yoursite.com/wp-json/performance-optimisation/v1/
```

## Authentication

All API endpoints require authentication. Use one of the following methods:

### WordPress Nonce (Recommended for AJAX)
```javascript
// Include nonce in request headers
headers: {
    'X-WP-Nonce': wppoAdmin.nonce
}
```

### Application Passwords
```bash
# Basic authentication with application password
curl -u "username:application_password" \
  https://yoursite.com/wp-json/performance-optimisation/v1/analytics/dashboard
```

### Cookie Authentication
```javascript
// Automatic for logged-in users making requests from admin
fetch('/wp-json/performance-optimisation/v1/analytics/dashboard', {
    credentials: 'same-origin'
})
```

## Endpoints

### Analytics

#### Get Dashboard Data
```http
GET /analytics/dashboard
```

**Response:**
```json
{
    "success": true,
    "data": {
        "overview": {
            "performance_score": 85,
            "average_load_time": 2.1,
            "cache_hit_ratio": 78,
            "total_page_views": 15420,
            "optimization_status": {
                "caching": true,
                "minification": true,
                "image_optimization": true
            }
        },
        "metrics": {
            "load_times": [2.1, 1.9, 2.3, 1.8, 2.0],
            "cache_performance": [75, 78, 82, 79, 81],
            "optimization_savings": {
                "cache": "45%",
                "minification": "23%",
                "images": "38%"
            }
        },
        "recommendations": [
            {
                "type": "cache",
                "priority": "high",
                "message": "Enable object caching for better database performance"
            }
        ]
    }
}
```

#### Get Performance Metrics
```http
GET /analytics/metrics?period=7d&metric=load_time
```

**Parameters:**
- `period` (string): Time period (`1d`, `7d`, `30d`, `90d`)
- `metric` (string): Metric type (`load_time`, `cache_ratio`, `optimization_score`)

**Response:**
```json
{
    "success": true,
    "data": {
        "metric": "load_time",
        "period": "7d",
        "data_points": [
            {"timestamp": "2025-01-05", "value": 2.1},
            {"timestamp": "2025-01-06", "value": 1.9},
            {"timestamp": "2025-01-07", "value": 2.3}
        ],
        "average": 2.1,
        "trend": "improving"
    }
}
```

#### Export Analytics Data
```http
GET /analytics/export?format=csv&period=30d
```

**Parameters:**
- `format` (string): Export format (`csv`, `json`, `xlsx`)
- `period` (string): Time period for export

### Cache Management

#### Clear All Cache
```http
POST /cache/clear
```

**Request Body:**
```json
{
    "types": ["page", "object", "browser"],
    "force": false
}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "cleared_types": ["page", "object", "browser"],
        "cache_size_before": "125.4 MB",
        "cache_size_after": "0 B",
        "time_taken": "2.3s"
    },
    "message": "Cache cleared successfully"
}
```

#### Get Cache Statistics
```http
GET /cache/stats
```

**Response:**
```json
{
    "success": true,
    "data": {
        "total_size": 131072000,
        "formatted_total_size": "125.0 MB",
        "types": {
            "page": {
                "size": 104857600,
                "formatted_size": "100.0 MB",
                "file_count": 1250,
                "enabled": true
            },
            "object": {
                "size": 20971520,
                "formatted_size": "20.0 MB",
                "file_count": 500,
                "enabled": true
            },
            "minified": {
                "size": 5242880,
                "formatted_size": "5.0 MB",
                "file_count": 45,
                "enabled": true
            }
        },
        "hit_ratio": 78.5,
        "cache_hits": 15420,
        "cache_misses": 4180,
        "last_cleared": "2025-01-07 10:30:00"
    }
}
```

#### Preload Cache
```http
POST /cache/preload
```

**Request Body:**
```json
{
    "urls": [
        "/",
        "/about/",
        "/contact/"
    ],
    "priority": "high"
}
```

### Settings Management

#### Get Current Settings
```http
GET /settings
```

**Response:**
```json
{
    "success": true,
    "data": {
        "caching": {
            "page_cache_enabled": true,
            "cache_ttl": 3600,
            "cache_exclusions": ["/checkout/", "/my-account/"],
            "object_cache_enabled": true,
            "browser_cache_enabled": true
        },
        "minification": {
            "minify_css": true,
            "minify_js": true,
            "minify_html": false,
            "combine_css": false,
            "combine_js": false
        },
        "images": {
            "lazy_loading": true,
            "convert_to_webp": true,
            "convert_to_avif": false,
            "compression_quality": 85,
            "resize_large_images": true,
            "max_image_width": 1920,
            "max_image_height": 1080
        },
        "advanced": {
            "disable_emojis": true,
            "disable_embeds": true,
            "remove_query_strings": false,
            "defer_js": true,
            "async_js": false
        }
    }
}
```

#### Update Settings
```http
PUT /settings
```

**Request Body:**
```json
{
    "caching": {
        "page_cache_enabled": true,
        "cache_ttl": 7200
    },
    "images": {
        "compression_quality": 90
    }
}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "updated_settings": {
            "caching.cache_ttl": 7200,
            "images.compression_quality": 90
        }
    },
    "message": "Settings updated successfully"
}
```

#### Reset Settings
```http
POST /settings/reset
```

**Request Body:**
```json
{
    "sections": ["caching", "minification"],
    "confirm": true
}
```

### Image Optimization

#### Optimize Single Image
```http
POST /images/optimize/{image_id}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "image_id": 123,
        "original_size": 2048576,
        "optimized_size": 1024000,
        "savings": "50.0%",
        "formats_created": ["webp", "avif"],
        "processing_time": "3.2s"
    }
}
```

#### Bulk Image Optimization
```http
POST /images/optimize/bulk
```

**Request Body:**
```json
{
    "image_ids": [123, 124, 125],
    "formats": ["webp", "avif"],
    "quality": 85
}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "job_id": "bulk_opt_20250107_103000",
        "total_images": 3,
        "estimated_time": "45s",
        "status": "queued"
    }
}
```

#### Get Optimization Status
```http
GET /images/optimize/status/{job_id}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "job_id": "bulk_opt_20250107_103000",
        "status": "processing",
        "progress": 67,
        "completed": 2,
        "total": 3,
        "current_image": "image-124.jpg",
        "estimated_remaining": "15s"
    }
}
```

### Recommendations

#### Get Performance Recommendations
```http
GET /recommendations
```

**Response:**
```json
{
    "success": true,
    "data": {
        "preset": {
            "preset": "aggressive",
            "confidence": 85,
            "reasons": [
                "High traffic volume detected",
                "Multiple optimization opportunities found"
            ]
        },
        "personalized": [
            {
                "category": "caching",
                "priority": "high",
                "title": "Enable Object Caching",
                "description": "Your site makes many database queries. Object caching could improve performance by 30-40%.",
                "impact": "high",
                "difficulty": "easy",
                "estimated_improvement": "35%"
            },
            {
                "category": "images",
                "priority": "medium",
                "title": "Convert Images to WebP",
                "description": "Converting your images to WebP format could reduce image sizes by 25-35%.",
                "impact": "medium",
                "difficulty": "easy",
                "estimated_improvement": "20%"
            }
        ],
        "summary": {
            "total_recommendations": 5,
            "high_priority": 2,
            "medium_priority": 2,
            "low_priority": 1,
            "potential_improvement": "65%"
        }
    },
    "timestamp": "2025-01-07 10:30:00"
}
```

### Wizard Setup

#### Complete Wizard Setup
```http
POST /wizard/setup
```

**Request Body:**
```json
{
    "preset": "aggressive",
    "features": {
        "preloadCache": true,
        "imageConversion": true,
        "criticalCSS": true,
        "resourceHints": true
    }
}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "preset": "aggressive",
        "applied_settings": {
            "caching": {
                "page_cache_enabled": true,
                "object_cache_enabled": true,
                "cache_ttl": 7200
            },
            "minification": {
                "minify_css": true,
                "minify_js": true,
                "combine_css": true
            },
            "images": {
                "lazy_loading": true,
                "convert_to_webp": true
            }
        }
    },
    "message": "Wizard setup completed successfully"
}
```

#### Reset Wizard
```http
POST /wizard/reset
```

**Response:**
```json
{
    "success": true,
    "message": "Setup wizard has been reset"
}
```

## Error Handling

### Error Response Format
```json
{
    "success": false,
    "error": {
        "code": "invalid_parameter",
        "message": "The 'period' parameter must be one of: 1d, 7d, 30d, 90d",
        "data": {
            "status": 400,
            "parameter": "period",
            "provided_value": "invalid"
        }
    }
}
```

### Common Error Codes

| Code | Status | Description |
|------|--------|-------------|
| `invalid_parameter` | 400 | Invalid or missing parameter |
| `unauthorized` | 401 | Authentication required |
| `forbidden` | 403 | Insufficient permissions |
| `not_found` | 404 | Resource not found |
| `method_not_allowed` | 405 | HTTP method not supported |
| `rate_limit_exceeded` | 429 | Too many requests |
| `internal_error` | 500 | Server error |
| `service_unavailable` | 503 | Service temporarily unavailable |

## Rate Limiting

API requests are rate-limited to prevent abuse:

- **Authenticated users**: 1000 requests per hour
- **Unauthenticated requests**: 100 requests per hour
- **Bulk operations**: 10 requests per hour

Rate limit headers are included in responses:
```http
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 999
X-RateLimit-Reset: 1641555600
```

## Webhooks

### Cache Clear Webhook
Receive notifications when cache is cleared:

```http
POST https://your-webhook-url.com/cache-cleared
Content-Type: application/json

{
    "event": "cache_cleared",
    "timestamp": "2025-01-07T10:30:00Z",
    "data": {
        "types": ["page", "object"],
        "size_cleared": "125.4 MB",
        "triggered_by": "user"
    }
}
```

### Optimization Complete Webhook
Receive notifications when optimizations complete:

```http
POST https://your-webhook-url.com/optimization-complete
Content-Type: application/json

{
    "event": "optimization_complete",
    "timestamp": "2025-01-07T10:35:00Z",
    "data": {
        "type": "bulk_image_optimization",
        "job_id": "bulk_opt_20250107_103000",
        "images_processed": 150,
        "total_savings": "45.2 MB"
    }
}
```

## SDK Examples

### JavaScript/Node.js
```javascript
class PerformanceOptimisationAPI {
    constructor(baseUrl, nonce) {
        this.baseUrl = baseUrl;
        this.nonce = nonce;
    }

    async request(endpoint, options = {}) {
        const url = `${this.baseUrl}${endpoint}`;
        const config = {
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': this.nonce,
                ...options.headers
            },
            ...options
        };

        const response = await fetch(url, config);
        return response.json();
    }

    async getDashboard() {
        return this.request('/analytics/dashboard');
    }

    async clearCache(types = ['page', 'object']) {
        return this.request('/cache/clear', {
            method: 'POST',
            body: JSON.stringify({ types })
        });
    }

    async updateSettings(settings) {
        return this.request('/settings', {
            method: 'PUT',
            body: JSON.stringify(settings)
        });
    }
}

// Usage
const api = new PerformanceOptimisationAPI(
    '/wp-json/performance-optimisation/v1',
    wppoAdmin.nonce
);

const dashboard = await api.getDashboard();
```

### PHP
```php
class PerformanceOptimisationAPI {
    private $base_url;
    private $auth_header;

    public function __construct($base_url, $username, $app_password) {
        $this->base_url = rtrim($base_url, '/');
        $this->auth_header = 'Basic ' . base64_encode($username . ':' . $app_password);
    }

    private function request($endpoint, $method = 'GET', $data = null) {
        $url = $this->base_url . $endpoint;
        
        $args = [
            'method' => $method,
            'headers' => [
                'Authorization' => $this->auth_header,
                'Content-Type' => 'application/json'
            ]
        ];

        if ($data) {
            $args['body'] = json_encode($data);
        }

        $response = wp_remote_request($url, $args);
        return json_decode(wp_remote_retrieve_body($response), true);
    }

    public function get_dashboard() {
        return $this->request('/analytics/dashboard');
    }

    public function clear_cache($types = ['page', 'object']) {
        return $this->request('/cache/clear', 'POST', ['types' => $types]);
    }
}

// Usage
$api = new PerformanceOptimisationAPI(
    'https://yoursite.com/wp-json/performance-optimisation/v1',
    'username',
    'app_password'
);

$dashboard = $api->get_dashboard();
```

## Testing

### API Testing with cURL
```bash
# Get dashboard data
curl -H "X-WP-Nonce: your_nonce" \
  https://yoursite.com/wp-json/performance-optimisation/v1/analytics/dashboard

# Clear cache
curl -X POST \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: your_nonce" \
  -d '{"types":["page","object"]}' \
  https://yoursite.com/wp-json/performance-optimisation/v1/cache/clear

# Update settings
curl -X PUT \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: your_nonce" \
  -d '{"caching":{"cache_ttl":7200}}' \
  https://yoursite.com/wp-json/performance-optimisation/v1/settings
```

### Postman Collection
A Postman collection is available for testing all API endpoints. Import the collection file from `/docs/postman/performance-optimisation-api.json`.

---

For more information, visit the [plugin documentation](../README.md) or [contact support](mailto:support@example.com).
