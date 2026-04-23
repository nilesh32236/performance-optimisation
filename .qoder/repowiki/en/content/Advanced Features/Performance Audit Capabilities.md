# Performance Audit Capabilities

<cite>
**Referenced Files in This Document**
- [performance-optimisation.php](file://performance-optimisation.php)
- [class-main.php](file://includes/class-main.php)
- [class-rest.php](file://includes/class-rest.php)
- [class-telemetry.php](file://includes/class-telemetry.php)
- [class-system-info.php](file://includes/class-system-info.php)
- [PerformanceAudit.js](file://src/components/PerformanceAudit.js)
- [SystemInfo.js](file://src/components/SystemInfo.js)
- [apiRequest.js](file://src/lib/apiRequest.js)
- [App.js](file://src/App.js)
- [Dashboard.js](file://src/components/Dashboard.js)
- [StatusBadge.js](file://src/components/common/StatusBadge.js)
- [MetricCard.js](file://src/components/common/MetricCard.js)
- [performance-audit.scss](file://src/css/components/_performance-audit.scss)
- [readme.txt](file://readme.txt)
</cite>

## Table of Contents
1. [Introduction](#introduction)
2. [Project Structure](#project-structure)
3. [Core Components](#core-components)
4. [Architecture Overview](#architecture-overview)
5. [Detailed Component Analysis](#detailed-component-analysis)
6. [Dependency Analysis](#dependency-analysis)
7. [Performance Considerations](#performance-considerations)
8. [Troubleshooting Guide](#troubleshooting-guide)
9. [Conclusion](#conclusion)

## Introduction
This document provides comprehensive documentation for the performance audit and diagnostic capabilities of the Performance Optimisation WordPress plugin. It explains the built-in audit tools, performance measurement techniques, diagnostic procedures, and the recommendation engine. The documentation covers system information gathering, bottleneck identification, optimization suggestions, integration with external auditing tools, automated monitoring, and continuous performance evaluation workflows.

## Project Structure
The plugin follows a modular architecture with clear separation between backend PHP classes and frontend React components. The performance audit functionality is implemented as part of the dashboard, leveraging REST API endpoints for telemetry and system diagnostics.

```mermaid
graph TB
subgraph "Plugin Entry Point"
A[performance-optimisation.php]
end
subgraph "Backend PHP Classes"
B[class-main.php]
C[class-rest.php]
D[class-telemetry.php]
E[class-system-info.php]
end
subgraph "Frontend React Components"
F[PerformanceAudit.js]
G[SystemInfo.js]
H[Dashboard.js]
I[App.js]
J[apiRequest.js]
end
subgraph "UI Components"
K[StatusBadge.js]
L[MetricCard.js]
M[performance-audit.scss]
end
A --> B
B --> C
C --> D
C --> E
I --> H
H --> F
H --> G
F --> J
G --> J
F --> K
F --> L
F --> M
```

**Diagram sources**
- [performance-optimisation.php:1-68](file://performance-optimisation.php#L1-L68)
- [class-main.php:1-1131](file://includes/class-main.php#L1-L1131)
- [class-rest.php:1-843](file://includes/class-rest.php#L1-L843)
- [PerformanceAudit.js:1-486](file://src/components/PerformanceAudit.js#L1-L486)
- [SystemInfo.js:1-208](file://src/components/SystemInfo.js#L1-L208)

**Section sources**
- [performance-optimisation.php:1-68](file://performance-optimisation.php#L1-L68)
- [class-main.php:1-1131](file://includes/class-main.php#L1-L1131)

## Core Components
The performance audit capabilities are built around three core components:

1. **Telemetry Engine**: Performs local HTTP-based page analysis with granular network timing measurements
2. **System Information Gatherer**: Collects comprehensive server, PHP, WordPress, and cache environment details
3. **Audit Dashboard**: Provides user-friendly visualization and interpretation of performance metrics

**Section sources**
- [class-telemetry.php:1-542](file://includes/class-telemetry.php#L1-L542)
- [class-system-info.php:1-298](file://includes/class-system-info.php#L1-L298)
- [PerformanceAudit.js:1-486](file://src/components/PerformanceAudit.js#L1-L486)

## Architecture Overview
The performance audit system follows a client-server architecture with REST API communication between the frontend React components and backend PHP classes.

```mermaid
sequenceDiagram
participant UI as "PerformanceAudit Component"
participant API as "REST API"
participant TELE as "Telemetry Class"
participant SYS as "SystemInfo Class"
participant CACHE as "Transient Cache"
UI->>API : POST /performance-optimisation/v1/performance_scan
API->>TELE : Telemetry : : scan(url, 'manual')
TELE->>CACHE : get_transient(key)
alt Cache Miss
TELE->>TELE : HTTP fetch (cURL or wp_remote_get)
TELE->>TELE : Parse HTML & extract resources
TELE->>TELE : Calculate sizes & metrics
TELE->>CACHE : set_transient(result, 1 hour)
end
TELE-->>API : Performance metrics array
API-->>UI : JSON response
UI->>API : GET /performance-optimisation/v1/system_info
API->>SYS : System_Info : : get_all()
SYS-->>API : System information groups
API-->>UI : JSON response
```

**Diagram sources**
- [class-rest.php:804-819](file://includes/class-rest.php#L804-L819)
- [class-telemetry.php:45-192](file://includes/class-telemetry.php#L45-L192)
- [class-system-info.php:62-71](file://includes/class-system-info.php#L62-L71)

## Detailed Component Analysis

### Telemetry Engine
The Telemetry class performs comprehensive page analysis with sophisticated HTTP fetching and parsing capabilities.

```mermaid
classDiagram
class Telemetry {
+scan(url, scan_type) array|WP_Error
-parse_resources(html) array
-calculate_sizes(resources) array
-measure_ttfb(url) float
-check_https(url) string
-check_compression(headers) string
-check_cache_control(headers) string
-check_robots_txt(url) string
-check_modern_images(images) string
-check_alt_attributes(images) string
+register_transient_key(key) void
}
class WP_HTML_Tag_Processor {
+next_tag(options) bool
+get_attribute(name) string
}
Telemetry --> WP_HTML_Tag_Processor : "uses"
```

**Diagram sources**
- [class-telemetry.php:31-541](file://includes/class-telemetry.php#L31-L541)

**Key Features:**
- **Dual HTTP Fetch Strategy**: Uses cURL for granular network timing when available, falls back to wp_remote_get()
- **Resource Parsing**: Extracts CSS, JS, and image resources with lazy-load detection
- **Size Calculation**: Computes asset sizes using local filesystem paths
- **Performance Metrics**: Measures load time, TTFB, DNS/connect/SSL timings
- **Compression Detection**: Identifies Gzip, Brotli, or Deflate compression
- **Cache Analysis**: Evaluates Cache-Control headers and modern image formats

**Section sources**
- [class-telemetry.php:45-192](file://includes/class-telemetry.php#L45-L192)
- [class-telemetry.php:213-367](file://includes/class-telemetry.php#L213-L367)

### Performance Audit Dashboard
The frontend PerformanceAudit component provides an intuitive interface for running scans and interpreting results.

```mermaid
flowchart TD
START([User Input]) --> VALIDATE["Validate URL"]
VALIDATE --> SCAN["Call runPerformanceScan()"]
SCAN --> FETCH["API Call: POST /performance_scan"]
FETCH --> SUCCESS{"Response Success?"}
SUCCESS --> |Yes| RENDER["Render Results"]
SUCCESS --> |No| ERROR["Show Error Message"]
RENDER --> STATUS["Calculate Status"]
STATUS --> DISPLAY["Display Metric Cards"]
DISPLAY --> DEVELOPER["Toggle Developer Mode"]
DEVELOPER --> ADVANCED["Show Advanced Timings"]
ADVANCED --> COMPLETE([Complete])
ERROR --> COMPLETE
```

**Diagram sources**
- [PerformanceAudit.js:203-237](file://src/components/PerformanceAudit.js#L203-L237)

**Core Functionality:**
- **Metric Thresholds**: Defines performance thresholds for load time (2.5s good, 4s poor), TTFB (200ms good, 500ms poor), and page size (500KB good, 1000KB poor)
- **Status Badge System**: Provides visual feedback with 'good', 'needs_improvement', and 'poor' status indicators
- **Developer Mode**: Enables advanced network timing details for technical analysis
- **Real-time Formatting**: Converts raw bytes to human-readable formats (KB, MB)

**Section sources**
- [PerformanceAudit.js:28-66](file://src/components/PerformanceAudit.js#L28-L66)
- [PerformanceAudit.js:143-201](file://src/components/PerformanceAudit.js#L143-L201)
- [StatusBadge.js:11-30](file://src/components/common/StatusBadge.js#L11-L30)

### System Information Gathering
The SystemInfo component collects comprehensive environment details for diagnostic purposes.

```mermaid
classDiagram
class System_Info {
+get_all() array
+get_php() array
+get_database() array
+get_wordpress() array
+get_wp_constants() array
+get_server() array
+get_cache() array
-get_active_cache_plugin() string
-get_mysql_var(variable) string?
-format_constant(constant) string
}
class Dashboard {
+render() JSX.Element
+handleLoad() Promise<void>
-setInfo(info) void
-setError(error) void
}
Dashboard --> System_Info : "fetches data"
```

**Diagram sources**
- [class-system-info.php:29-296](file://includes/class-system-info.php#L29-L296)
- [SystemInfo.js:66-90](file://src/components/SystemInfo.js#L66-L90)

**Information Collected:**
- **PHP Environment**: Version, SAPI, memory limits, execution time, extensions count
- **Database Details**: Server version, extension class, client version, max connections
- **WordPress Configuration**: Version, environment type, permalink structure, HTTPS status
- **Server Information**: Software, operating system, architecture
- **Cache Status**: Object cache availability, active cache plugins, memory usage
- **WordPress Constants**: Debug settings, cache configuration, memory limits

**Section sources**
- [class-system-info.php:62-212](file://includes/class-system-info.php#L62-L212)
- [SystemInfo.js:130-202](file://src/components/SystemInfo.js#L130-L202)

### REST API Integration
The REST API provides programmatic access to performance audit and system information capabilities.

```mermaid
sequenceDiagram
participant React as "React Component"
participant API as "WP REST API"
participant Handler as "Rest Class"
participant Telemetry as "Telemetry Class"
participant SystemInfo as "System_Info Class"
React->>API : fetch('/performance-optimisation/v1/performance_scan', {url})
API->>Handler : run_performance_scan(request)
Handler->>Telemetry : scan(url, 'manual')
Telemetry-->>Handler : metrics array
Handler-->>API : response object
API-->>React : JSON data
React->>API : fetch('/performance-optimisation/v1/system_info', {method : 'GET'})
API->>Handler : get_system_info(request)
Handler->>SystemInfo : get_all()
SystemInfo-->>Handler : system info groups
Handler-->>API : response object
API-->>React : JSON data
```

**Diagram sources**
- [class-rest.php:53-122](file://includes/class-rest.php#L53-L122)
- [class-rest.php:804-819](file://includes/class-rest.php#L804-L819)
- [apiRequest.js:41-53](file://src/lib/apiRequest.js#L41-L53)

**Section sources**
- [class-rest.php:37-122](file://includes/class-rest.php#L37-L122)
- [apiRequest.js:1-54](file://src/lib/apiRequest.js#L1-L54)

## Dependency Analysis
The performance audit system exhibits clear separation of concerns with well-defined dependencies between components.

```mermaid
graph TB
subgraph "Frontend Dependencies"
A[PerformanceAudit.js] --> B[apiRequest.js]
A --> C[StatusBadge.js]
A --> D[MetricCard.js]
E[SystemInfo.js] --> B
F[Dashboard.js] --> A
F --> E
G[App.js] --> F
end
subgraph "Backend Dependencies"
H[class-rest.php] --> I[class-telemetry.php]
H --> J[class-system-info.php]
K[class-main.php] --> H
end
subgraph "External Dependencies"
L[cURL Extension]
M[WP_HTML_Tag_Processor]
N[Action Scheduler]
end
A -.-> L
I -.-> M
H -.-> N
```

**Diagram sources**
- [PerformanceAudit.js:10-26](file://src/components/PerformanceAudit.js#L10-L26)
- [class-rest.php:19-43](file://includes/class-rest.php#L19-L43)
- [class-main.php:128-154](file://includes/class-main.php#L128-L154)

**Key Dependencies:**
- **cURL Extension**: Required for granular network timing measurements
- **WP_HTML_Tag_Processor**: Available in WordPress 6.2+, provides accurate HTML parsing
- **Action Scheduler**: Enables background processing for image optimization
- **Transient Cache**: Stores performance scan results for 1 hour

**Section sources**
- [class-telemetry.php:68-122](file://includes/class-telemetry.php#L68-L122)
- [class-rest.php:26-43](file://includes/class-rest.php#L26-L43)

## Performance Considerations
The plugin implements several performance optimizations to minimize overhead:

### Caching Strategy
- **Transient Cache**: Performance scan results cached for 1 hour to reduce repeated HTTP requests
- **Local Filesystem Access**: Asset size calculations use local filesystem instead of HTTP HEAD requests
- **Selective Loading**: System information loaded on-demand rather than on dashboard mount

### Resource Management
- **Lazy Loading**: System information component only loads when user initiates
- **Efficient Parsing**: Uses WordPress 6.2+ HTML processor when available, falls back to regex for older versions
- **Background Processing**: Image optimization uses Action Scheduler for non-blocking operations

### Memory Optimization
- **Component-Level State**: Individual components manage their state independently
- **Conditional Rendering**: Results only rendered when available
- **Resource Cleanup**: Proper cleanup of intervals and event listeners

**Section sources**
- [class-telemetry.php:46-51](file://includes/class-telemetry.php#L46-L51)
- [Dashboard.js:99-153](file://src/components/Dashboard.js#L99-L153)

## Troubleshooting Guide

### Common Issues and Solutions

**Performance Scan Failures:**
- **Symptom**: Scan returns error message
- **Causes**: Network connectivity issues, invalid URL, server timeouts
- **Solutions**: Verify URL accessibility, check firewall settings, retry with different URL

**Missing Network Timing Data:**
- **Symptom**: Developer mode shows zero values for DNS/connect/SSL
- **Causes**: cURL extension not available or disabled
- **Solutions**: Enable cURL extension, verify PHP configuration

**System Information Not Loading:**
- **Symptom**: "Load System Info" button remains disabled
- **Causes**: Permission issues, server restrictions
- **Solutions**: Verify admin privileges, check server logs for errors

**Cache Performance Issues:**
- **Symptom**: Slow dashboard loading with frequent scans
- **Causes**: Excessive cache misses
- **Solutions**: Allow cached results to expire naturally, reduce scan frequency

**Section sources**
- [class-telemetry.php:136-156](file://includes/class-telemetry.php#L136-L156)
- [PerformanceAudit.js:224-236](file://src/components/PerformanceAudit.js#L224-L236)

## Conclusion
The Performance Optimisation plugin provides a comprehensive performance audit and diagnostic system through its integrated Telemetry engine, System Information gatherer, and intuitive dashboard interface. The system offers both high-level performance insights and detailed technical analysis, enabling users to identify bottlenecks, track improvements, and implement targeted optimizations. The modular architecture ensures maintainability and extensibility, while the caching and performance optimization strategies minimize overhead on production systems.

The plugin's strength lies in its practical approach to performance measurement, focusing on real-world metrics rather than theoretical benchmarks, and providing actionable recommendations based on measurable performance indicators.