/**
 * Shared hook for scoped feedback notices.
 *
 * Centralises the divergent per-component notification state and
 * auto-dismiss timer logic into one pattern backed by the
 * NoticeBanner presentational component.
 *
 * Each component that talks to the REST API previously reinvented its
 * own feedback state (`notification`, `announcement`, `error`, `actionMsg`).
 * Use this hook instead:
 *
 * ```js
 * const { notice, notify, dismiss } = useNotice();
 * notify( { type: 'success', message: 'Saved.', durationMs: 5000 } );
 * ```
 *
 * @since 1.10.0
 * @return {{ notice: ?Object, notify: Function, dismiss: Function }}
 *   - `notice`:  `{ type, message }` or `null`.
 *   - `notify`:  `( { type, message, durationMs? } )` — shows a notice and
 *                optionally auto-dismisses it after `durationMs`.
 *   - `dismiss`: `() => void` — clears the notice and any pending timer.
 */
import { useState, useCallback, useEffect, useRef } from '@wordpress/element';

const useNotice = () => {
	const [ notice, setNotice ] = useState( null );
	const timerRef = useRef( null );

	const clearTimer = useCallback( () => {
		if ( timerRef.current ) {
			clearTimeout( timerRef.current );
			timerRef.current = null;
		}
	}, [] );

	/**
	 * Clear the current notice and any pending auto-dismiss timer.
	 */
	const dismiss = useCallback( () => {
		clearTimer();
		setNotice( null );
	}, [ clearTimer ] );

	/**
	 * Show a notice.
	 *
	 * @param {Object} opts              Notice options.
	 * @param {string} opts.type         'error' | 'success' | 'warning' | 'info'.
	 * @param {string} opts.message      Notice text.
	 * @param {number} [opts.durationMs] Optional auto-dismiss delay in milliseconds.
	 */
	const notify = useCallback(
		( { type, message, durationMs } ) => {
			clearTimer();
			setNotice( { type, message } );
			if ( durationMs ) {
				timerRef.current = setTimeout( () => {
					timerRef.current = null;
					setNotice( null );
				}, durationMs );
			}
		},
		[ clearTimer ]
	);

	useEffect( () => {
		return clearTimer;
	}, [ clearTimer ] );

	return { notice, notify, dismiss };
};

export default useNotice;
