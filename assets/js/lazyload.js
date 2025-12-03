/**
 * Lazy Loading for Images and Iframes
 * Uses Intersection Observer API with fallback
 */
(function() {
	'use strict';

	let imgLoaded = false;

	/**
	 * Load images using Intersection Observer
	 */
	const loadImages = () => {
		const lazyloadImages = document.querySelectorAll('img[data-src], img[data-srcset]');
		const lazyloadIframes = document.querySelectorAll('iframe[data-src]');

		imgLoaded = true;

		if ('IntersectionObserver' in window) {
			const observer = new IntersectionObserver((entries, observer) => {
				entries.forEach(entry => {
					if (entry.isIntersecting) {
						const el = entry.target;

						if (el.tagName === 'IMG') {
							if (el.hasAttribute('data-src')) {
								el.src = el.getAttribute('data-src');
								el.removeAttribute('data-src');
							}

							if (el.hasAttribute('data-srcset')) {
								el.srcset = el.getAttribute('data-srcset');
								el.removeAttribute('data-srcset');
							}

							el.classList.add('lazyloaded');
						} else if (el.tagName === 'IFRAME') {
							if (el.hasAttribute('data-src')) {
								el.src = el.getAttribute('data-src');
								el.removeAttribute('data-src');
							}
						}

						observer.unobserve(el);
					}
				});
			}, {
				rootMargin: '100px'
			});

			lazyloadImages.forEach(img => observer.observe(img));
			lazyloadIframes.forEach(iframe => observer.observe(iframe));

		} else {
			// Fallback for browsers without Intersection Observer
			function lazyLoadFallback() {
				[...lazyloadImages, ...lazyloadIframes].forEach(el => {
					if (isElementInViewport(el)) {
						if (el.hasAttribute('data-src')) {
							el.src = el.getAttribute('data-src');
							el.removeAttribute('data-src');
						}

						if (el.hasAttribute('data-srcset')) {
							el.srcset = el.getAttribute('data-srcset');
							el.removeAttribute('data-srcset');
						}

						if (el.tagName === 'IMG') {
							el.classList.add('lazyloaded');
						}
					}
				});
			}

			function isElementInViewport(el) {
				const rect = el.getBoundingClientRect();
				return (
					rect.top >= 0 &&
					rect.left >= 0 &&
					rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) + 100 &&
					rect.right <= (window.innerWidth || document.documentElement.clientWidth)
				);
			}

			window.addEventListener('scroll', lazyLoadFallback);
			window.addEventListener('resize', lazyLoadFallback);
			lazyLoadFallback();
		}
	};

	// Initialize on DOM ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function() {
			if (!imgLoaded) {
				loadImages();
			}
		});
	} else {
		loadImages();
	}
})();
