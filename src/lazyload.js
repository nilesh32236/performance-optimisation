// src/lazyload.js

/**
 * Performance Optimisation Lazy Load and Delayed Script Execution
 *
 * Handles lazy loading for images, iframes, videos, and execution of delayed JavaScript.
 */
(function () {
	'use strict';

	const LAZY_OFFSET = window.wppoLazyLoadConfig?.offset || 200;
	const THROTTLE_DELAY = window.wppoLazyLoadConfig?.throttleDelay || 100;

	let lazyLoadThrottleTimeout;
	let firstInteractionDone = false;

	function isInViewport(el) {
		const rect = el.getBoundingClientRect();
		const inView = (
			rect.top <= (window.innerHeight || document.documentElement.clientHeight) + LAZY_OFFSET &&
			rect.bottom >= -LAZY_OFFSET &&
			rect.left <= (window.innerWidth || document.documentElement.clientWidth) + LAZY_OFFSET &&
			rect.right >= -LAZY_OFFSET
		);
		return inView;
	}

	function lazyLoadImage(element) {
		if (element.dataset.wppoLoaded === 'true') return;
		element.dataset.wppoLoaded = 'true';

		if (element.dataset.src) {
			element.src = element.dataset.src;
			element.removeAttribute('data-src');
		}
		if (element.dataset.srcset) {
			element.srcset = element.dataset.srcset;
			element.removeAttribute('data-srcset');
		}
		if (element.dataset.sizes) {
			element.sizes = element.dataset.sizes;
			element.removeAttribute('data-sizes');
		}

		if (element.tagName === 'IMG' && element.parentElement && element.parentElement.tagName === 'PICTURE') {
			const pictureSources = element.parentElement.querySelectorAll('source[data-srcset]');
			pictureSources.forEach(lazyLoadImage);
		}

		element.classList.add('wppo-lazy-loaded');
		element.classList.remove('wppo-lazy-image');
	}

	function lazyLoadIframe(iframe) {
		if (iframe.dataset.wppoLoaded === 'true') return;
		iframe.dataset.wppoLoaded = 'true';

		if (iframe.dataset.src) {
			iframe.src = iframe.dataset.src;
			iframe.removeAttribute('data-src');
		}
		iframe.classList.add('wppo-lazy-loaded');
		iframe.classList.remove('wppo-lazy-iframe');
	}

	function lazyLoadVideo(video) {
		if (video.dataset.wppoLoaded === 'true') return;
		video.dataset.wppoLoaded = 'true';

		if (video.dataset.poster) {
			video.poster = video.dataset.poster;
			video.removeAttribute('data-poster');
		}

		const sources = video.querySelectorAll('source[data-src]');
		sources.forEach(source => {
			if (source.dataset.src) {
				source.src = source.dataset.src;
				source.removeAttribute('data-src');
			}
		});

		if (video.dataset.src) {
			video.src = video.dataset.src;
			video.removeAttribute('data-src');
		}

		video.load();
		video.classList.add('wppo-lazy-loaded');
		video.classList.remove('wppo-lazy-video');
	}

	function executeDelayedScript(originalScript) {
		if (originalScript.dataset.wppoProcessed === 'true') {
			return;
		}
		originalScript.dataset.wppoProcessed = 'true';

		const newScript = document.createElement('script');
		const originalType = originalScript.getAttribute('data-wppo-type') || 'text/javascript';
		newScript.type = originalType;

		Array.from(originalScript.attributes).forEach(attr => {
			if (!['type', 'data-wppo-type', 'data-wppo-src', 'data-wppo-processed', 'data-wppo-load-on-scroll'].includes(attr.name.toLowerCase())) {
				newScript.setAttribute(attr.name, attr.value);
			}
		});

		if (originalScript.hasAttribute('async')) {
			newScript.async = true;
		}
		// Note: 'defer' is typically not copied directly for dynamically inserted scripts unless it's a module.
		// Modern browsers handle dynamically inserted 'src' scripts like async.

		if (originalScript.hasAttribute('data-wppo-src')) {
			const srcValue = originalScript.getAttribute('data-wppo-src');
			newScript.src = srcValue;
			newScript.onerror = () => console.error('WPPO: Delayed external script failed to load:', newScript.src);
		} else {
			newScript.textContent = originalScript.textContent || originalScript.innerText || '';
		}

		if (originalScript.parentNode) {
			originalScript.parentNode.insertBefore(newScript, originalScript);
			originalScript.parentNode.removeChild(originalScript);
		} else {
			console.error('WPPO: Placeholder script has no parentNode! Appending to head as fallback.', originalScript);
			document.head.appendChild(newScript);
		}
	}

	function processLazyElements() {
		let processedSomething = false;

		document.querySelectorAll('img.wppo-lazy-image[data-src]:not([data-wppo-loaded="true"]), picture source[data-srcset]:not([data-wppo-loaded="true"])').forEach(element => {
			if (isInViewport(element)) {
				lazyLoadImage(element);
				processedSomething = true;
			}
		});

		document.querySelectorAll('iframe.wppo-lazy-iframe[data-src]:not([data-wppo-loaded="true"])').forEach(iframe => {
			if (isInViewport(iframe)) {
				lazyLoadIframe(iframe);
				processedSomething = true;
			}
		});

		document.querySelectorAll('video.wppo-lazy-video:not([data-wppo-loaded="true"])').forEach(video => {
			if (isInViewport(video)) {
				lazyLoadVideo(video);
				processedSomething = true;
			}
		});

		const scriptsToProcess = document.querySelectorAll('script[type="wppo/javascript"]:not([data-wppo-processed="true"])');
		if (scriptsToProcess.length > 0) {
		}
		scriptsToProcess.forEach(script => {
			const loadOnScroll = script.dataset.wppoLoadOnScroll === 'true';
			if (firstInteractionDone || (loadOnScroll && isInViewport(script))) {
				executeDelayedScript(script);
				processedSomething = true;
			}
		});
		if (processedSomething) {
		}

		cleanupEventListeners();
	}

	function throttledProcessLazyElements() {
		if (lazyLoadThrottleTimeout) {
			clearTimeout(lazyLoadThrottleTimeout);
		}
		lazyLoadThrottleTimeout = setTimeout(() => {
			processLazyElements();
		}, THROTTLE_DELAY);
	}

	function handleFirstInteraction(event) {
		if (firstInteractionDone) return;
		firstInteractionDone = true;
		processLazyElements();
		['scroll', 'mousemove', 'mousedown', 'touchstart', 'keydown', 'wheel'].forEach(evt => {
			document.removeEventListener(evt, handleFirstInteraction, { passive: true, capture: true });
		});
	}

	function cleanupEventListeners() {
		const remainingLazyImages = document.querySelectorAll('img.wppo-lazy-image[data-src]:not([data-wppo-loaded="true"]), picture source[data-srcset]:not([data-wppo-loaded="true"])').length;
		const remainingLazyIframes = document.querySelectorAll('iframe.wppo-lazy-iframe[data-src]:not([data-wppo-loaded="true"])').length;
		const remainingLazyVideos = document.querySelectorAll('video.wppo-lazy-video:not([data-wppo-loaded="true"])').length;
		const remainingDelayedScripts = document.querySelectorAll('script[type="wppo/javascript"]:not([data-wppo-processed="true"])').length;

		if (remainingLazyImages === 0 && remainingLazyIframes === 0 && remainingLazyVideos === 0 && remainingDelayedScripts === 0) {
			document.removeEventListener('scroll', throttledProcessLazyElements, { passive: true });
			window.removeEventListener('resize', throttledProcessLazyElements, { passive: true });
			window.removeEventListener('orientationchange', throttledProcessLazyElements, { passive: true });
		} else {
		}
	}

	const userAgent = navigator.userAgent;
	const isBot = /bot|google|crawl|spider|slurp|baidu|bing|msn|duckduckbot|teoma|yandex/i.test(userAgent);

	if (isBot || (document.body && document.body.classList.contains('logged-in'))) {
		document.querySelectorAll('script[type="wppo/javascript"]:not([data-wppo-processed="true"])').forEach(script => {
			console.warn("WPPO: Executing delayed script immediately due to bot/logged-in user:", script);
			executeDelayedScript(script);
		});
		return;
	}

	document.addEventListener('scroll', throttledProcessLazyElements, { passive: true });
	window.addEventListener('resize', throttledProcessLazyElements, { passive: true });
	window.addEventListener('orientationchange', throttledProcessLazyElements, { passive: true });

	['scroll', 'mousemove', 'mousedown', 'touchstart', 'keydown', 'wheel'].forEach(event => {
		document.addEventListener(event, handleFirstInteraction, { passive: true, capture: true, once: true });
	});

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => {
			requestAnimationFrame(processLazyElements);
		});
	} else {
		requestAnimationFrame(processLazyElements);
	}
})();