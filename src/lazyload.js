let scriptLoading = false;
let scriptLoadPromise;

async function loadScripts() {
	if (scriptLoading) return scriptLoadPromise;
	scriptLoading = true;

	const inlineScripts = Array.from(document.querySelectorAll('script[type="qtpo/javascript"]'));

	const loadScript = (script) => {

		return new Promise((resolve, reject) => {
			if (script.hasAttribute('type') && 'qtpo/javascript' === script.getAttribute('type')) {
				script.removeAttribute('type');
			}

			if (script.hasAttribute('qtpo-type')) {
				const type = script.getAttribute('qtpo-type');
				script.removeAttribute('qtpo-type');
				script.setAttribute('type', type);
				const src = script.getAttribute('qtpo-src');

				if (src) {
					script.removeAttribute('qtpo-src');
					script.setAttribute('src', src);
					script.onload = resolve;
					script.onerror = reject;
				} else {
					script.src = "data:text/javascript;base64," + window.btoa(unescape(encodeURIComponent(script.text)));
					resolve();
				}
			} else {
				resolve();
			}
		});
	};
	for (let script of inlineScripts) {
		try {
			await loadScript(script);
		} catch (err) {
			console.error('Error loading script', err);
		}
	}

	document.dispatchEvent(new Event("DOMContentLoaded"));

	if (typeof jQuery !== 'undefined') {
		jQuery(document).triggerHandler("ready");
	}
	return;
}

if (!scriptLoading) {
	document.addEventListener('mouseenter', loadScripts);
	document.addEventListener('mousedown', loadScripts);
	document.addEventListener('mouseover', loadScripts);
	document.addEventListener('touchstart', loadScripts);
	document.addEventListener('scroll', loadScripts);
}

document.addEventListener('DOMContentLoaded', function () {

	const lazyloadImages = document.querySelectorAll('img[data-src], img[data-srcset]');

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
			rootMargin: '200px'
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

});
