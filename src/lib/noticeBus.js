/**
 * A tiny publish/subscribe bus for notices.
 *
 * ## Why this exists
 *
 * Twenty components own their notice state through `useNotice`, and
 * `NoticeBanner` renders **inline inside the owning card**. A screenshot audit
 * of all eight screens found no toast, snackbar or portal anywhere in the app,
 * which means: press a button near the top of a four-thousand-pixel page and
 * the confirmation renders far above the viewport. Press one near the bottom and
 * it appears below. Either way a sighted user sees nothing.
 *
 * ## Why it is separate from the inline banner
 *
 * The inline banner already carries `role="alert"` / `aria-live`, so a
 * screen-reader user **is** told when a notice fires, wherever they are — live
 * regions are read from the DOM, not from the viewport. The gap is visual only.
 *
 * So the global region is deliberately **`aria-hidden`**: it exists so the
 * outcome is *visible* regardless of scroll, and hiding it from assistive
 * technology prevents every message being announced twice.
 *
 * @package
 */

const subscribers = new Set();

/**
 * Announce a notice to every mounted message region.
 *
 * @param {Object} notice         The notice.
 * @param {string} notice.type    'error' | 'success' | 'warning' | 'info'.
 * @param {string} notice.message The text.
 * @return {void}
 */
export const publish = ( { type, message } ) => {
	subscribers.forEach( ( fn ) => {
		try {
			fn( { type, message } );
		} catch ( error ) {
			// One broken subscriber must not stop the others, and a failure here
			// must never break the action that produced the notice.

			console.error( 'notice subscriber failed', error );
		}
	} );
};

/**
 * Listen for notices.
 *
 * @param {Function} fn Called with each published notice.
 * @return {Function}  Unsubscribe.
 */
export const subscribe = ( fn ) => {
	subscribers.add( fn );
	return () => subscribers.delete( fn );
};
