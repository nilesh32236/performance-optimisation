/**
 * Admin Bar JavaScript
 * handles clear cache functionality from the WP Admin Bar.
 */

document.addEventListener('DOMContentLoaded', () => {
    const clearCacheBtn = document.getElementById('po-clear-cache');
    if (clearCacheBtn) {
        clearCacheBtn.addEventListener('click', (e) => {
            e.preventDefault();
            // TODO: Implement API call to clear cache
            console.log('Cache cleared via Admin Bar');
        });
    }
});
