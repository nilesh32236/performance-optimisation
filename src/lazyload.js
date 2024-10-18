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