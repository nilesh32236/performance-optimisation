# Manual Test Cases - Performance Optimisation Plugin

## Pre-Test Setup

### Requirements
- WordPress 5.0+ with admin access
- Test images (JPG, PNG, WebP)
- Sample CSS/JS files
- Browser developer tools enabled

### Initial Setup
1. Activate the plugin: `Plugins > Performance Optimisation > Activate`
2. Verify no PHP errors in debug.log
3. Check admin menu appears: `Performance Optimisation`

---

## 1. Plugin Activation & Deactivation Tests

### Test 1.1: Plugin Activation
**Steps:**
1. Go to `Plugins > Installed Plugins`
2. Click `Activate` on Performance Optimisation
3. Check for PHP errors

**Expected Result:**
- Plugin activates without errors
- Admin menu "Performance Optimisation" appears
- No fatal errors in error logs

### Test 1.2: Plugin Deactivation
**Steps:**
1. Go to `Plugins > Installed Plugins`
2. Click `Deactivate` on Performance Optimisation
3. Check admin menu disappears

**Expected Result:**
- Plugin deactivates cleanly
- Admin menu removed
- No errors in logs

---

## 2. Admin Interface Tests

### Test 2.1: Dashboard Access
**Steps:**
1. Go to `Performance Optimisation > Dashboard`
2. Verify page loads without errors
3. Check all sections display properly

**Expected Result:**
- Dashboard loads successfully
- Statistics widgets show data
- No JavaScript console errors

### Test 2.2: Settings Page
**Steps:**
1. Go to `Performance Optimisation > Settings`
2. Try changing various settings
3. Click `Save Settings`

**Expected Result:**
- Settings page loads
- Form submissions work
- Success message appears
- Settings persist after page reload

### Test 2.3: Cache Management
**Steps:**
1. Go to `Performance Optimisation > Cache`
2. Click `Clear All Cache`
3. Click `Clear Page Cache`
4. Click `Clear Object Cache`

**Expected Result:**
- Each clear action shows success message
- Cache directories are emptied
- No errors in browser console

---

## 3. Image Optimization Tests

### Test 3.1: Single Image Optimization
**Steps:**
1. Upload a test image (JPG/PNG > 500KB)
2. Go to `Media Library`
3. Click on the image
4. Look for optimization options/status

**Expected Result:**
- Image gets optimized automatically or manually
- File size reduces
- WebP/AVIF versions created (if supported)
- Original image preserved

### Test 3.2: Bulk Image Optimization
**Steps:**
1. Upload multiple test images
2. Go to `Performance Optimisation > Images`
3. Click `Optimize All Images`
4. Monitor progress

**Expected Result:**
- Batch processing starts
- Progress indicator shows
- All images get optimized
- Summary report displays

### Test 3.3: Image Format Conversion
**Steps:**
1. Upload JPG image
2. Check if WebP version is created
3. Test in different browsers
4. Verify fallback to original format

**Expected Result:**
- WebP created for modern browsers
- AVIF created if supported
- Proper fallback for older browsers
- Responsive images generated

---

## 4. CSS Optimization Tests

### Test 4.1: CSS Minification
**Steps:**
1. Enable CSS minification in settings
2. Visit frontend pages
3. View page source
4. Check CSS files in developer tools

**Expected Result:**
- CSS files are minified (whitespace removed)
- File sizes reduced
- No broken styles on frontend
- Combined CSS files if enabled

### Test 4.2: Critical CSS
**Steps:**
1. Enable critical CSS extraction
2. Visit homepage
3. Check page source for inline critical CSS
4. Verify non-critical CSS loads asynchronously

**Expected Result:**
- Critical CSS inlined in `<head>`
- Non-critical CSS loads after page render
- No flash of unstyled content (FOUC)
- Page loads faster

### Test 4.3: CSS Combination
**Steps:**
1. Enable CSS combination
2. Visit pages with multiple CSS files
3. Check network tab in developer tools

**Expected Result:**
- Multiple CSS files combined into fewer files
- Reduced HTTP requests
- Proper order maintained
- No style conflicts

---

## 5. JavaScript Optimization Tests

### Test 5.1: JS Minification
**Steps:**
1. Enable JS minification
2. Visit frontend pages
3. Check JS files in developer tools
4. Test interactive elements

**Expected Result:**
- JS files are minified
- File sizes reduced
- All functionality works
- No JavaScript errors

### Test 5.2: JS Defer/Async
**Steps:**
1. Enable JS optimization
2. View page source
3. Check script tags for defer/async attributes
4. Test page loading speed

**Expected Result:**
- Non-critical JS has defer/async
- Page renders faster
- Interactive elements work after load
- No blocking scripts

---

## 6. Caching Tests

### Test 6.1: Page Caching
**Steps:**
1. Enable page caching
2. Visit a page twice
3. Check response headers
4. Verify cache files created

**Expected Result:**
- First visit creates cache file
- Second visit serves from cache
- Proper cache headers set
- Faster page load times

### Test 6.2: Cache Invalidation
**Steps:**
1. Enable page caching
2. Visit a page (creates cache)
3. Edit the page content
4. Visit page again

**Expected Result:**
- Cache invalidates on content change
- New content displays immediately
- New cache file created
- No stale content served

### Test 6.3: Object Caching
**Steps:**
1. Enable object caching
2. Perform database-heavy operations
3. Check query count in debug tools
4. Repeat operations

**Expected Result:**
- Database queries reduced on repeat operations
- Faster response times
- Cache hits increase
- Memory usage optimized

---

## 7. API Endpoint Tests

### Test 7.1: Cache Clear API
**Steps:**
1. Open browser developer tools
2. Go to Console tab
3. Run: 
```javascript
fetch('/wp-json/performance-optimisation/v1/cache/clear', {
    method: 'POST',
    headers: {
        'X-WP-Nonce': wpApiSettings.nonce,
        'Content-Type': 'application/json'
    },
    body: JSON.stringify({type: 'all'})
}).then(r => r.json()).then(console.log);
```

**Expected Result:**
- API returns success response
- Cache is cleared
- Proper JSON response format

### Test 7.2: Image Optimization API
**Steps:**
1. Get an image attachment ID from Media Library
2. In browser console, run:
```javascript
fetch('/wp-json/performance-optimisation/v1/images/optimize', {
    method: 'POST',
    headers: {
        'X-WP-Nonce': wpApiSettings.nonce,
        'Content-Type': 'application/json'
    },
    body: JSON.stringify({image_id: 123})
}).then(r => r.json()).then(console.log);
```

**Expected Result:**
- Image gets optimized
- API returns optimization stats
- No errors in response

---

## 8. Performance Tests

### Test 8.1: Page Speed Test
**Steps:**
1. Disable all optimizations
2. Test page speed with GTmetrix/PageSpeed Insights
3. Enable optimizations one by one
4. Retest after each optimization

**Expected Result:**
- Each optimization improves scores
- Overall page speed increases significantly
- Core Web Vitals improve
- No functionality breaks

### Test 8.2: Memory Usage Test
**Steps:**
1. Install Query Monitor plugin
2. Enable all optimizations
3. Visit various pages
4. Check memory usage in Query Monitor

**Expected Result:**
- Memory usage stays within reasonable limits
- No memory leaks detected
- Database queries optimized
- Plugin overhead minimal

---

## 9. Security Tests

### Test 9.1: Input Validation
**Steps:**
1. Try accessing admin pages without proper permissions
2. Submit forms with malicious data
3. Test file upload restrictions
4. Check nonce verification

**Expected Result:**
- Unauthorized access blocked
- Malicious input sanitized
- Only allowed file types accepted
- Nonce verification works

### Test 9.2: File System Security
**Steps:**
1. Try accessing cache files directly via URL
2. Attempt directory traversal in file paths
3. Check file permissions on created directories

**Expected Result:**
- Cache files not directly accessible
- Directory traversal blocked
- Proper file permissions set
- No sensitive data exposed

---

## 10. Compatibility Tests

### Test 10.1: Theme Compatibility
**Steps:**
1. Test with default WordPress theme
2. Test with popular themes (Astra, GeneratePress)
3. Check for style conflicts
4. Verify functionality works

**Expected Result:**
- Works with all tested themes
- No style conflicts
- All features functional
- No layout breaks

### Test 10.2: Plugin Compatibility
**Steps:**
1. Test with popular plugins (WooCommerce, Contact Form 7)
2. Check for JavaScript conflicts
3. Verify caching doesn't break functionality
4. Test with other performance plugins

**Expected Result:**
- No conflicts with popular plugins
- JavaScript works properly
- Dynamic content handled correctly
- Graceful handling of conflicts

---

## 11. Error Handling Tests

### Test 11.1: Invalid File Handling
**Steps:**
1. Try optimizing corrupted images
2. Process invalid CSS/JS files
3. Test with missing files
4. Check error logging

**Expected Result:**
- Graceful error handling
- Appropriate error messages
- No fatal errors
- Errors logged properly

### Test 11.2: Server Limitations
**Steps:**
1. Test with low memory limits
2. Process very large files
3. Test with limited file permissions
4. Check timeout handling

**Expected Result:**
- Graceful degradation
- Appropriate warnings shown
- No server crashes
- Fallback mechanisms work

---

## 12. Mobile & Browser Tests

### Test 12.1: Mobile Responsiveness
**Steps:**
1. Test admin interface on mobile devices
2. Check frontend optimizations on mobile
3. Verify touch interactions work
4. Test different screen sizes

**Expected Result:**
- Admin interface responsive
- Mobile optimizations work
- Touch interactions functional
- Good mobile performance

### Test 12.2: Cross-Browser Testing
**Steps:**
1. Test in Chrome, Firefox, Safari, Edge
2. Check modern format support (WebP/AVIF)
3. Verify fallbacks work
4. Test JavaScript functionality

**Expected Result:**
- Works in all major browsers
- Modern formats served when supported
- Proper fallbacks for older browsers
- Consistent functionality across browsers

---

## Test Checklist

### Critical Tests (Must Pass)
- [ ] Plugin activation/deactivation
- [ ] Admin dashboard access
- [ ] Image optimization works
- [ ] CSS/JS minification works
- [ ] Caching functions properly
- [ ] No PHP errors in logs
- [ ] Security measures active

### Performance Tests
- [ ] Page speed improves
- [ ] File sizes reduce
- [ ] Cache hit rates good
- [ ] Memory usage reasonable

### Compatibility Tests
- [ ] Works with default theme
- [ ] No plugin conflicts
- [ ] Cross-browser compatible
- [ ] Mobile responsive

### Security Tests
- [ ] Input validation works
- [ ] File access restricted
- [ ] Nonce verification active
- [ ] No XSS vulnerabilities

---

## Troubleshooting Common Issues

### Issue: Images not optimizing
**Check:**
- GD/ImageMagick extension installed
- File permissions on upload directory
- Memory limits sufficient
- Error logs for specific errors

### Issue: CSS/JS not minifying
**Check:**
- File permissions on cache directory
- Valid CSS/JS syntax
- No file conflicts
- Cache directory writable

### Issue: Cache not working
**Check:**
- Cache directory exists and writable
- No conflicting cache plugins
- Proper rewrite rules
- Server configuration

### Issue: Performance not improving
**Check:**
- All optimizations enabled
- No conflicting plugins
- Server resources adequate
- CDN configuration if applicable

---

## Reporting Issues

When reporting issues, include:
1. WordPress version
2. PHP version
3. Active theme and plugins
4. Error messages from logs
5. Steps to reproduce
6. Expected vs actual behavior

Test each section systematically and document any failures for debugging.
