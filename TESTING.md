# Testing Performance Optimisation

This document provides instructions on how to manually test the Performance Optimisation plugin in a WordPress environment.

## Requirements

* A WordPress installation
* The Performance Optimisation plugin

## Installation

1. Download the latest version of the Performance Optimisation plugin from the [GitHub repository](https://github.com/example-repo/performance-optimisation).
2. In your WordPress admin panel, go to **Plugins > Add New**.
3. Click on the **Upload Plugin** button.
4. Select the downloaded zip file and click on the **Install Now** button.
5. Activate the plugin.

## Testing the Features

### File Optimization

1. Go to **Performance Optimisation > File Optimization**.
2. Enable the **Minify JavaScript** and **Minify CSS** options.
3. Click on the **Save Settings** button.
4. Open your website in a new tab and view the source code.
5. You should see that the JavaScript and CSS files are now minified.

### Image Optimization

1. Go to **Performance Optimisation > Image Optimization**.
2. Enable the **Lazy Load Images** and **Convert Images to WebP/AVIF** options.
3. Click on the **Save Settings** button.
4. Upload a new image to your website.
5. You should see that the image is lazy loaded and converted to the selected format.

### Preload Settings

1. Go to **Performance Optimisation > Preload & Preconnect**.
2. Enable the **Enable Preload Cache** and **Preload Fonts** options.
3. Add some font URLs to the **Font URLs** field.
4. Click on the **Save Settings** button.
5. Open your website in a new tab and view the source code.
6. You should see that the fonts are now preloaded.

### Database Optimization

1. Go to **Performance Optimisation > Database**.
2. Enable the **Delete Post Revisions** and **Delete Spam Comments** options.
3. Click on the **Save Settings** button.
4. You should see that the post revisions and spam comments have been deleted.

### CDN Integration

1. Go to **Performance Optimisation > CDN**.
2. Enable the **Enable CDN** option and add your CDN URL to the **CDN URL** field.
3. Click on the **Save Settings** button.
4. Open your website in a new tab and view the source code.
5. You should see that the URLs of your assets have been rewritten to use the CDN.

### Critical CSS Generation

1. Go to **Performance Optimisation > Critical CSS**.
2. Enable the **Enable Critical CSS** option and add your critical CSS to the **Critical CSS** field.
3. Click on the **Save Settings** button.
4. Open your website in a new tab and view the source code.
5. You should see that the critical CSS is now inlined in the head of the document.
