// src/admin-bar.js

/**
 * Performance Optimisation Admin Bar Script
 *
 * Handles interactions for the Performance Optimisation menu in the WordPress admin bar.
 */
document.addEventListener('DOMContentLoaded', () => {
	// Check if wppoAdminBar object is available (localized from PHP)
	if (typeof window.wppoAdminBar === 'undefined') {
		// console.warn('Performance Optimisation: Admin bar script data (wppoAdminBar) not found.');
		return;
	}

	const { apiUrl, nonce, pageId, pagePath, i18n } = window.wppoAdminBar;

	/**
	 * Handles the click event for clearing all cache.
	 * @param {Event} event - The click event.
	 */
	const handleClearAllCache = async (event) => {
		event.preventDefault();

		// eslint-disable-next-line no-alert
		if (!window.confirm(i18n.confirmClearAll || 'Are you sure you want to clear ALL cache?')) {
			return;
		}

		// Optionally, show some loading indicator on the admin bar item
		const originalTitle = event.target.innerHTML;
		event.target.innerHTML = i18n.clearingCache || 'Clearing...';
		event.target.style.pointerEvents = 'none'; // Disable further clicks

		try {
			const response = await fetch(`${apiUrl}/clear-cache`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body: JSON.stringify({ action: 'all' }),
			});

			const result = await response.json();

			if (result.success) {
				// alert(result.data?.message || i18n.cacheCleared || 'All cache cleared successfully.');
				showAdminBarNotice(result.data?.message || i18n.cacheCleared || 'All cache cleared successfully.', 'success');
			} else {
				// alert(result.message || i18n.cacheClearError || 'Error clearing cache.');
				showAdminBarNotice(result.message || i18n.cacheClearError || 'Error clearing cache.', 'error');
				console.error('Clear All Cache Error:', result);
			}
		} catch (error) {
			// alert(i18n.cacheClearError || 'An unexpected error occurred.');
			showAdminBarNotice(i18n.cacheClearError || 'An unexpected error occurred.', 'error');
			console.error('Clear All Cache Exception:', error);
		} finally {
			event.target.innerHTML = originalTitle; // Restore original text
			event.target.style.pointerEvents = 'auto'; // Re-enable clicks
		}
	};

	/**
	 * Handles the click event for clearing cache for the current page.
	 * @param {Event} event - The click event.
	 */
	const handleClearThisPageCache = async (event) => {
		event.preventDefault();

		if (!pagePath && !pageId) { // pagePath might be empty for homepage, pageId can be 0 for non-singular
			// alert(i18n.cannotClearNonSpecificPage || 'Cannot determine the current page to clear cache.');
			showAdminBarNotice(i18n.cannotClearNonSpecificPage || 'Cannot determine the current page to clear cache.', 'warning');
			return;
		}

		// eslint-disable-next-line no-alert
		if (!window.confirm(i18n.confirmClearPage || 'Are you sure you want to clear the cache for this page?')) {
			return;
		}

		const originalTitle = event.target.innerHTML;
		event.target.innerHTML = i18n.clearingCache || 'Clearing...';
		event.target.style.pointerEvents = 'none';

		try {
			const response = await fetch(`${apiUrl}clear-cache`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body: JSON.stringify({
					action: 'page',
					path: pagePath, // Send the URL path for the page
					// id: pageId, // Optionally send page ID if your backend uses it
				}),
			});

			const result = await response.json();

			if (result.success) {
				// alert(result.data?.message || i18n.cacheCleared || 'Cache for this page cleared successfully.');
				showAdminBarNotice(result.data?.message || i18n.cacheCleared || 'Cache for this page cleared successfully.', 'success');
			} else {
				// alert(result.message || i18n.cacheClearError || 'Error clearing cache for this page.');
				showAdminBarNotice(result.message || i18n.cacheClearError || 'Error clearing cache for this page.', 'error');
				console.error('Clear This Page Cache Error:', result);
			}
		} catch (error) {
			// alert(i18n.cacheClearError || 'An unexpected error occurred.');
			showAdminBarNotice(i18n.cacheClearError || 'An unexpected error occurred.', 'error');
			console.error('Clear This Page Cache Exception:', error);
		} finally {
			event.target.innerHTML = originalTitle;
			event.target.style.pointerEvents = 'auto';
		}
	};

	/**
	 * Shows a temporary notice in the admin bar.
	 * @param {string} message - The message to display.
	 * @param {string} type - 'success', 'error', or 'warning'.
	 */
	function showAdminBarNotice(message, type = 'success') {
		const noticeId = 'wppo-admin-bar-notice';
		let notice = document.getElementById(noticeId);

		if (!notice) {
			notice = document.createElement('div');
			notice.id = noticeId;
			notice.style.position = 'fixed';
			notice.style.top = '40px'; // Below admin bar
			notice.style.right = '20px';
			notice.style.padding = '10px 15px';
			notice.style.borderRadius = '4px';
			notice.style.zIndex = '99999'; // High z-index
			notice.style.boxShadow = '0 2px 10px rgba(0,0,0,0.2)';
			notice.style.opacity = '0';
			notice.style.transition = 'opacity 0.3s ease-in-out';
			document.body.appendChild(notice);
		}

		notice.textContent = message;
		notice.className = `wppo-admin-bar-notice--${type}`; // For styling

		if (type === 'success') {
			notice.style.backgroundColor = '#d4edda';
			notice.style.color = '#155724';
			notice.style.borderColor = '#c3e6cb';
		} else if (type === 'error') {
			notice.style.backgroundColor = '#f8d7da';
			notice.style.color = '#721c24';
			notice.style.borderColor = '#f5c6cb';
		} else { // warning or default
			notice.style.backgroundColor = '#fff3cd';
			notice.style.color = '#856404';
			notice.style.borderColor = '#ffeeba';
		}
		notice.style.border = '1px solid';


		// Fade in
		setTimeout(() => {
			notice.style.opacity = '1';
		}, 10);

		// Fade out and remove after a delay
		setTimeout(() => {
			notice.style.opacity = '0';
			setTimeout(() => {
				if (notice.parentNode) {
					notice.parentNode.removeChild(notice);
				}
			}, 300); // Match transition duration
		}, 4000); // Display for 4 seconds
	}


	// Attach event listeners to the admin bar menu items
	const clearAllCacheLink = document.querySelector('#wp-admin-bar-wppo_clear_all_cache .ab-item');
	if (clearAllCacheLink) {
		clearAllCacheLink.addEventListener('click', handleClearAllCache);
	}

	const clearThisPageCacheLink = document.querySelector('#wp-admin-bar-wppo_clear_this_page_cache .ab-item');
	if (clearThisPageCacheLink) {
		clearThisPageCacheLink.addEventListener('click', handleClearThisPageCache);
	}

});