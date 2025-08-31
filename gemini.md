## Performance Optimisation Plugin - Issue Fixes

### 1. Fix SQL Injection Vulnerability

```bash
gemini fix "includes/Core/Analytics/MetricsCollector.php" --issue="Potential SQL injection vulnerability in get_metrics method." --fix="Sanitize the key variable before using it in the SQL query and use wpdb->prepare for all parts of the query."
````

### 2\. Fix Cross-Site Scripting (XSS) Vulnerability

```bash
gemini fix "includes/Admin/Admin.php" --issue="Reflected XSS vulnerability in render_admin_page method." --fix="Sanitize the \$_GET['page'] parameter using esc_html before outputting it to the page."
```

### 3\. Fix Insecure File Handling

```bash
gemini fix "includes/Core/Cache/FileCache.php" --issue="Insecure file handling in write_cache_file method." --fix="Sanitize the key variable and ensure that the file path is within the expected cache directory. Use wp_filesystem for all file operations."
```

### 4\. Fix Missing CSRF Protection

```bash
gemini fix "includes/Admin/Metabox.php" --issue="Missing CSRF protection in save_preload_images_metabox_data method." --fix="Add a CSRF token to the form and verify it on the server-side using wp_verify_nonce."
```

### 5\. Fix Performance Issue: Direct Database Queries in a Loop

```bash
gemini fix "includes/Core/Analytics/PerformanceAnalyzer.php" --issue="Performance issue due to direct database queries in a loop in analyze_page_load_performance method." --fix="Optimize the code to fetch all the required data in a single database query outside the loop."
```

### 6\. Fix WordPress Coding Standards Violation: Use of `extract()`

```bash
gemini fix "includes/Core/Bootstrap/Plugin.php" --issue="Use of extract() function, which is discouraged by WordPress coding standards." --fix="Avoid using extract() and access the array variables directly."
```