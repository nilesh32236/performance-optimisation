let scriptLoading = false;
let imgLoaded     = false;
let scriptLoadPromise;

const loadScript = (script) => {
	return new Promise((resolve, reject) => {
		if ('qtpo/javascript' === script.getAttribute('type')) {
			script.removeAttribute('type');
			// script.setAttribute('type', 'text/javascript');
		}

		const qtpoType = script.getAttribute('qtpo-type');
		if (qtpoType) {
			script.removeAttribute('qtpo-type');
			script.setAttribute('type', qtpoType);
		}

		const src = script.getAttribute('qtpo-src');

		if (src) {
			script.removeAttribute('qtpo-src');
			script.setAttribute('src', src);

			script.onload = resolve;
			script.onerror = reject;
		} else {
			if ('qtpo/javascript' === script.getAttribute('type')) {
				script.removeAttribute('type');
				// script.setAttribute('type', 'text/javascript');
			}
	
			const qtpoType = script.getAttribute('qtpo-type');
			if (qtpoType) {
				script.removeAttribute('qtpo-type');
				script.setAttribute('type', qtpoType);
			}

			try {
				if ( script.text ) {
					if ( ! script.src ) {
						const base64Script = btoa(unescape(encodeURIComponent(script.text)));
						script.setAttribute('src', `data:text/javascript;base64,${base64Script}`);
					}
				}
				resolve();
			} catch (err) {
				reject(`Error encoding inline script: ${err.message}`);
			}
		}
	});
};

async function loadScripts() {
	if (scriptLoading) return scriptLoadPromise;
	scriptLoading = true;

	const inlineScripts = Array.from(document.querySelectorAll('script[type="qtpo/javascript"], script[qtpo-src]'));

	try {
		// Sequentially process all inline scripts
		for (const script of inlineScripts) {
			await loadScript(script);
		}
	} catch (err) {
		console.error('Error loading script:', err);
	} finally {
		scriptLoading = false;
	}

	document.dispatchEvent(new Event("DOMContentLoaded"));
	window.dispatchEvent( new Event("DOMContentLoaded"));
	window.dispatchEvent( new Event("pageshow") );

	if (typeof jQuery !== 'undefined') {
		jQuery(document).triggerHandler("ready");
	}
	return;
}

// Attach event listeners to trigger the loading process
if (!scriptLoading) {
	const triggerEvents = ['mouseenter', 'mousedown', 'mouseover', 'touchstart', 'scroll'];
	const loadHandler = () => {
		triggerEvents.forEach((event) => document.removeEventListener(event, loadHandler));
		loadScripts();
	};

	triggerEvents.forEach((event) => document.addEventListener(event, loadHandler, { once: true }));
}

const loadImages = () => {
	const lazyloadImages = document.querySelectorAll('img[data-src], img[data-srcset]');
	imgLoaded = true;

	if ('IntersectionObserver' in window) {

		const observer = new IntersectionObserver((entries, observer) => {
			entries.forEach(entry => {
				if (entry.isIntersecting) {
					const img = entry.target;

					// Load the image's src and srcset
					if (img.hasAttribute('data-src')) {
						img.src = img.getAttribute('data-src');
						img.removeAttribute('data-src');
					}

					if (img.hasAttribute('data-srcset')) {
						img.srcset = img.getAttribute('data-srcset');
						img.removeAttribute('data-srcset');
					}

					observer.unobserve(img);
				}
			});
		}, {
			rootMargin: '100px'
		});

		lazyloadImages.forEach(img => {
			observer.observe(img);
		});

	} else {
		function lazyLoadFallback() {
			lazyloadImages.forEach(img => {
				if (isElementInViewport(img)) {
					if (img.hasAttribute('data-src')) {
						img.src = img.getAttribute('data-src');
						img.removeAttribute('data-src');
					}

					if (img.hasAttribute('data-srcset')) {
						img.srcset = img.getAttribute('data-srcset');
						img.removeAttribute('data-srcset');
					}
				}
			});
		}

		function isElementInViewport(el) {
			const rect = el.getBoundingClientRect();
			return (
				rect.top >= 0 &&
				rect.left >= 0 &&
				rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
				rect.right <= (window.innerWidth || document.documentElement.clientWidth)
			);
		}

		window.addEventListener('scroll', lazyLoadFallback);
		lazyLoadFallback();
	}
}

document.addEventListener('DOMContentLoaded', function () {
	if (imgLoaded) {
		setTimeout(() => {
			loadImages();
		}, 500);
	} else {
		loadImages();
	}
});