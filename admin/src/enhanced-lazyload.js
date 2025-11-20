/**
 * Enhanced Lazy Loading System
 * 
 * Modern lazy loading with Intersection Observer API, WebP support,
 * and responsive image handling
 */

class EnhancedLazyLoad {
    constructor(options = {}) {
        this.options = {
            rootMargin: '50px 0px',
            threshold: 0.01,
            enableWebP: true,
            enableResponsive: true,
            fadeInDuration: 300,
            retryAttempts: 3,
            ...options
        };

        this.observer = null;
        this.images = new Set();
        this.loadedImages = new WeakSet();
        this.retryCount = new WeakMap();

        this.init();
    }

    init() {
        if (!('IntersectionObserver' in window)) {
            this.fallbackLoad();
            return;
        }

        this.observer = new IntersectionObserver(
            this.handleIntersection.bind(this),
            {
                rootMargin: this.options.rootMargin,
                threshold: this.options.threshold
            }
        );

        this.findImages();
        this.observeImages();
        this.setupEventListeners();
    }

    findImages() {
        const selectors = [
            'img[data-src]',
            'img[data-srcset]',
            '[data-bg-src]',
            'picture source[data-srcset]'
        ];

        document.querySelectorAll(selectors.join(',')).forEach(element => {
            this.images.add(element);
        });
    }

    observeImages() {
        this.images.forEach(img => {
            if (!this.loadedImages.has(img)) {
                this.observer.observe(img);
            }
        });
    }

    handleIntersection(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                this.loadImage(entry.target);
                this.observer.unobserve(entry.target);
            }
        });
    }

    async loadImage(element) {
        if (this.loadedImages.has(element)) return;

        try {
            if (element.tagName === 'IMG') {
                await this.loadImageElement(element);
            } else if (element.tagName === 'SOURCE') {
                await this.loadSourceElement(element);
            } else {
                await this.loadBackgroundImage(element);
            }

            this.loadedImages.add(element);
            this.onImageLoaded(element);
        } catch (error) {
            this.handleLoadError(element, error);
        }
    }

    async loadImageElement(img) {
        const src = this.getBestImageSrc(img);
        const srcset = img.dataset.srcset;

        return new Promise((resolve, reject) => {
            const tempImg = new Image();
            
            tempImg.onload = () => {
                img.src = src;
                if (srcset) img.srcset = srcset;
                
                // Remove data attributes
                delete img.dataset.src;
                delete img.dataset.srcset;
                
                resolve();
            };

            tempImg.onerror = reject;
            tempImg.src = src;
        });
    }

    async loadSourceElement(source) {
        const srcset = source.dataset.srcset;
        if (!srcset) return;

        source.srcset = srcset;
        delete source.dataset.srcset;
    }

    async loadBackgroundImage(element) {
        const bgSrc = this.getBestBackgroundSrc(element);
        
        return new Promise((resolve, reject) => {
            const tempImg = new Image();
            
            tempImg.onload = () => {
                element.style.backgroundImage = `url(${bgSrc})`;
                delete element.dataset.bgSrc;
                delete element.dataset.bgSrcWebp;
                resolve();
            };

            tempImg.onerror = reject;
            tempImg.src = bgSrc;
        });
    }

    getBestImageSrc(img) {
        const originalSrc = img.dataset.src;
        const webpSrc = img.dataset.srcWebp;

        if (this.options.enableWebP && webpSrc && this.supportsWebP()) {
            return webpSrc;
        }

        return originalSrc;
    }

    getBestBackgroundSrc(element) {
        const originalSrc = element.dataset.bgSrc;
        const webpSrc = element.dataset.bgSrcWebp;

        if (this.options.enableWebP && webpSrc && this.supportsWebP()) {
            return webpSrc;
        }

        return originalSrc;
    }

    supportsWebP() {
        if (this._webpSupport !== undefined) {
            return this._webpSupport;
        }

        const canvas = document.createElement('canvas');
        canvas.width = 1;
        canvas.height = 1;
        
        this._webpSupport = canvas.toDataURL('image/webp').indexOf('data:image/webp') === 0;
        return this._webpSupport;
    }

    onImageLoaded(element) {
        // Add fade-in animation
        element.style.opacity = '0';
        element.style.transition = `opacity ${this.options.fadeInDuration}ms ease-in-out`;
        
        requestAnimationFrame(() => {
            element.style.opacity = '1';
        });

        // Add loaded class
        element.classList.add('wppo-lazy-loaded');

        // Dispatch custom event
        element.dispatchEvent(new CustomEvent('wppo:imageLoaded', {
            detail: { element }
        }));

        // Update performance metrics
        this.updateMetrics();
    }

    handleLoadError(element, error) {
        const retries = this.retryCount.get(element) || 0;
        
        if (retries < this.options.retryAttempts) {
            this.retryCount.set(element, retries + 1);
            
            // Retry after delay
            setTimeout(() => {
                this.loadImage(element);
            }, Math.pow(2, retries) * 1000);
        } else {
            // Show fallback or placeholder
            element.classList.add('wppo-lazy-error');
            console.warn('Failed to load image after retries:', element, error);
        }
    }

    fallbackLoad() {
        // Fallback for browsers without Intersection Observer
        this.images.forEach(img => {
            this.loadImage(img);
        });
    }

    setupEventListeners() {
        // Handle dynamically added images
        const mutationObserver = new MutationObserver(mutations => {
            mutations.forEach(mutation => {
                mutation.addedNodes.forEach(node => {
                    if (node.nodeType === Node.ELEMENT_NODE) {
                        this.findNewImages(node);
                    }
                });
            });
        });

        mutationObserver.observe(document.body, {
            childList: true,
            subtree: true
        });

        // Handle page visibility changes
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.observeImages();
            }
        });
    }

    findNewImages(container) {
        const selectors = [
            'img[data-src]',
            'img[data-srcset]',
            '[data-bg-src]',
            'picture source[data-srcset]'
        ];

        container.querySelectorAll(selectors.join(',')).forEach(element => {
            if (!this.images.has(element)) {
                this.images.add(element);
                if (this.observer) {
                    this.observer.observe(element);
                }
            }
        });
    }

    updateMetrics() {
        // Send performance data to backend
        if (window.wppoPerformanceData) {
            window.wppoPerformanceData.lazyLoadedImages = 
                (window.wppoPerformanceData.lazyLoadedImages || 0) + 1;
        }
    }

    // Public API methods
    refresh() {
        this.findImages();
        this.observeImages();
    }

    loadAll() {
        this.images.forEach(img => {
            if (!this.loadedImages.has(img)) {
                this.loadImage(img);
            }
        });
    }

    destroy() {
        if (this.observer) {
            this.observer.disconnect();
        }
        this.images.clear();
    }
}

// Auto-initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Get settings from WordPress
    const settings = window.wppoLazyLoadSettings || {};
    
    window.wppoLazyLoad = new EnhancedLazyLoad(settings);
});

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = EnhancedLazyLoad;
}
