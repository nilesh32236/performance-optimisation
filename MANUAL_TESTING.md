# Manual Testing Guide

This guide provides instructions for manually testing the core features of the Performance Optimisation plugin.

## 1. Cache Service Testing

### 1.1. Testing Page Cache Generation

1.  **Enable Page Caching**: Go to the plugin's settings page and ensure that "Enable Page Caching" is turned on.
2.  **Visit a Page**: Open a page on your site in a new incognito browser window. This will trigger the initial cache generation.
3.  **Verify Cache File**: Check the `wp-content/cache/wppo/` directory. You should see a new directory named after your site's domain, and inside that, a directory structure that mirrors the URL of the page you visited. Inside the final directory, you should find an `index.html` file and an `index.html.gz` file.
4.  **Verify Cache Serving**: Reload the page in the incognito window. The page should load faster. You can also check the response headers in your browser's developer tools. You should see a `X-WPPO-Cache` header with the value `HIT`.

### 1.2. Testing Cache Invalidation

1.  **Update a Post**: Edit a post or page that has already been cached.
2.  **Verify Cache Clearing**: Check the cache directory for the post you just updated. The `index.html` and `index.html.gz` files should be gone.
3.  **Verify Related Cache Clearing**: Check the cache directories for the homepage, the main blog page, and any category or tag archive pages that the post belongs to. These should also be cleared.
4.  **Visit the Page Again**: Visit the updated post in an incognito window. The cache files should be regenerated with the new content.

### 1.3. Testing Cache Preloading

1.  **Enable Cache Preloading**: Go to the plugin's settings page and ensure that "Enable Cache Preloading" is turned on.
2.  **Trigger Cron**: To manually trigger the preloading cron job, you can use a plugin like "WP Crontrol" to run the `wppo_page_cron_hook` event.
3.  **Verify Scheduled Events**: After the main cron job runs, you should see a series of `wppo_generate_static_page` events scheduled in WP Crontrol, one for each page on your site.
4.  **Verify Cache Files**: As the individual page generation events run, you should see the cache files being created in the `wp-content/cache/wppo/` directory.

## 2. Optimization Service Testing

### 2.1. Testing CSS and JS Minification

1.  **Enable Minification**: Go to the plugin's settings page and ensure that "Minify CSS files" and "Minify JavaScript files" are turned on.
2.  **Visit a Page**: Open a page on your site in a new incognito browser window.
3.  **Verify Minified Files**: Check the `wp-content/cache/wppo/min/` directory. You should see new `css` and `js` directories containing the minified files.
4.  **Verify Page Source**: View the source of the page. You should see that the original CSS and JS files have been replaced with links to the new minified files in the cache directory.

### 2.2. Testing CSS Combination

1.  **Enable Combination**: Go to the plugin's settings page and ensure that "Combine CSS files" is turned on.
2.  **Visit a Page**: Open a page on your site in a new incognito browser window.
3.  **Verify Combined File**: Check the `wp-content/cache/wppo/min/css/` directory. You should see a new CSS file that contains the combined content of all the original CSS files.
4.  **Verify Page Source**: View the source of the page. You should see that all the original CSS files have been replaced with a single link to the new combined file.

### 2.3. Testing HTML Minification

1.  **Enable HTML Minification**: Go to the plugin's settings page and ensure that "Minify HTML" is turned on.
2.  **Visit a Page**: Open a page on your site in a new incognito browser window.
3.  **Verify Page Source**: View the source of the page. You should see that all unnecessary whitespace and comments have been removed from the HTML.
