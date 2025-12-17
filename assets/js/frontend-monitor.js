/**
 * Frontend Performance Monitor
 * 
 * @package PerformanceOptimisation
 * @since   2.1.0
 */

(function() {
    'use strict';

    // Check if monitoring is available
    if (typeof wppoAjax === 'undefined') {
        return;
    }

    class FrontendMonitor {
        constructor() {
            this.metrics = {};
            this.init();
        }

        init() {
            this.trackCoreWebVitals();
            this.trackNavigationTiming();
            this.trackResourceTiming();
            this.trackUserInteractions();
        }

        trackCoreWebVitals() {
            // Track LCP (Largest Contentful Paint)
            if ('PerformanceObserver' in window) {
                try {
                    const lcpObserver = new PerformanceObserver((list) => {
                        const entries = list.getEntries();
                        const lastEntry = entries[entries.length - 1];
                        this.sendMetric('lcp', lastEntry.startTime);
                    });
                    lcpObserver.observe({entryTypes: ['largest-contentful-paint']});
                } catch (e) {
                    console.warn('LCP tracking failed:', e);
                }

                // Track FID (First Input Delay)
                try {
                    const fidObserver = new PerformanceObserver((list) => {
                        const entries = list.getEntries();
                        entries.forEach((entry) => {
                            const fid = entry.processingStart - entry.startTime;
                            this.sendMetric('fid', fid);
                        });
                    });
                    fidObserver.observe({entryTypes: ['first-input']});
                } catch (e) {
                    console.warn('FID tracking failed:', e);
                }

                // Track CLS (Cumulative Layout Shift)
                try {
                    let clsValue = 0;
                    const clsObserver = new PerformanceObserver((list) => {
                        for (const entry of list.getEntries()) {
                            if (!entry.hadRecentInput) {
                                clsValue += entry.value;
                            }
                        }
                        this.sendMetric('cls', clsValue);
                    });
                    clsObserver.observe({entryTypes: ['layout-shift']});
                } catch (e) {
                    console.warn('CLS tracking failed:', e);
                }
            }

            // Fallback for browsers without PerformanceObserver
            this.trackFallbackMetrics();
        }

        trackNavigationTiming() {
            window.addEventListener('load', () => {
                setTimeout(() => {
                    const navigation = performance.getEntriesByType('navigation')[0];
                    if (navigation) {
                        // First Contentful Paint
                        const fcp = navigation.responseStart - navigation.fetchStart;
                        this.sendMetric('fcp', fcp);

                        // Time to First Byte
                        const ttfb = navigation.responseStart - navigation.requestStart;
                        this.sendMetric('ttfb', ttfb);

                        // DOM Content Loaded
                        const dcl = navigation.domContentLoadedEventEnd - navigation.domContentLoadedEventStart;
                        this.sendMetric('dom_content_loaded', dcl);

                        // Full page load
                        const loadTime = navigation.loadEventEnd - navigation.loadEventStart;
                        this.sendMetric('page_load_complete', loadTime);
                    }
                }, 0);
            });
        }

        trackResourceTiming() {
            window.addEventListener('load', () => {
                setTimeout(() => {
                    const resources = performance.getEntriesByType('resource');
                    
                    let totalSize = 0;
                    let slowResources = 0;
                    const resourceTypes = {};

                    resources.forEach(resource => {
                        const duration = resource.responseEnd - resource.startTime;
                        
                        // Count slow resources (>1s)
                        if (duration > 1000) {
                            slowResources++;
                        }

                        // Estimate size (not always available)
                        if (resource.transferSize) {
                            totalSize += resource.transferSize;
                        }

                        // Count by type
                        const type = this.getResourceType(resource.name);
                        resourceTypes[type] = (resourceTypes[type] || 0) + 1;
                    });

                    this.sendMetric('total_resources', resources.length);
                    this.sendMetric('slow_resources', slowResources);
                    this.sendMetric('estimated_page_size', totalSize);
                    this.sendMetric('resource_breakdown', resourceTypes);
                }, 1000);
            });
        }

        trackUserInteractions() {
            let interactionCount = 0;
            let scrollDepth = 0;
            let maxScrollDepth = 0;

            // Track clicks
            document.addEventListener('click', () => {
                interactionCount++;
            });

            // Track scroll depth
            window.addEventListener('scroll', this.throttle(() => {
                const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
                const windowHeight = window.innerHeight;
                const documentHeight = document.documentElement.scrollHeight;
                
                scrollDepth = Math.round((scrollTop + windowHeight) / documentHeight * 100);
                maxScrollDepth = Math.max(maxScrollDepth, scrollDepth);
            }, 250));

            // Send interaction data on page unload
            window.addEventListener('beforeunload', () => {
                this.sendMetric('user_interactions', interactionCount);
                this.sendMetric('max_scroll_depth', maxScrollDepth);
            });
        }

        trackFallbackMetrics() {
            // For browsers without PerformanceObserver
            window.addEventListener('load', () => {
                setTimeout(() => {
                    // Estimate LCP using load time
                    const loadTime = performance.now();
                    this.sendMetric('estimated_lcp', loadTime);

                    // Track paint timing if available
                    if ('getEntriesByType' in performance) {
                        const paintEntries = performance.getEntriesByType('paint');
                        paintEntries.forEach(entry => {
                            if (entry.name === 'first-contentful-paint') {
                                this.sendMetric('fcp_fallback', entry.startTime);
                            }
                        });
                    }
                }, 0);
            });
        }

        sendMetric(name, value) {
            // Don't send if value is invalid
            if (value === null || value === undefined || isNaN(value)) {
                return;
            }

            // Batch metrics to reduce requests
            this.metrics[name] = value;
            
            // Send immediately for critical metrics
            if (['lcp', 'fid', 'cls'].includes(name)) {
                this.flushMetrics();
            } else {
                // Batch other metrics
                clearTimeout(this.batchTimeout);
                this.batchTimeout = setTimeout(() => {
                    this.flushMetrics();
                }, 2000);
            }
        }

        flushMetrics() {
            if (Object.keys(this.metrics).length === 0) {
                return;
            }

            const data = new FormData();
            data.append('action', 'wppo_track_metric');
            data.append('nonce', wppoAjax.nonce);
            data.append('metrics', JSON.stringify(this.metrics));
            data.append('url', window.location.pathname);
            data.append('timestamp', Date.now());

            // Use sendBeacon if available (more reliable for page unload)
            if ('sendBeacon' in navigator) {
                navigator.sendBeacon(wppoAjax.ajaxurl, data);
            } else {
                // Fallback to fetch
                fetch(wppoAjax.ajaxurl, {
                    method: 'POST',
                    body: data,
                    keepalive: true
                }).catch(() => {
                    // Ignore errors for tracking
                });
            }

            // Clear metrics after sending
            this.metrics = {};
        }

        getResourceType(url) {
            if (url.match(/\.(css)$/i)) return 'css';
            if (url.match(/\.(js)$/i)) return 'js';
            if (url.match(/\.(jpg|jpeg|png|gif|webp|svg)$/i)) return 'image';
            if (url.match(/\.(woff|woff2|ttf|eot)$/i)) return 'font';
            if (url.match(/\.(mp4|webm|ogg|mp3|wav)$/i)) return 'media';
            return 'other';
        }

        throttle(func, limit) {
            let inThrottle;
            return function() {
                const args = arguments;
                const context = this;
                if (!inThrottle) {
                    func.apply(context, args);
                    inThrottle = true;
                    setTimeout(() => inThrottle = false, limit);
                }
            };
        }
    }

    // Initialize monitoring when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            new FrontendMonitor();
        });
    } else {
        new FrontendMonitor();
    }

    // Track page visibility changes
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            // Page became hidden, flush any pending metrics
            if (window.frontendMonitor) {
                window.frontendMonitor.flushMetrics();
            }
        }
    });

})();
