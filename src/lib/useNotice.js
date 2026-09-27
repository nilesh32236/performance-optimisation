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
 * // With a one-click action rendered by NoticeBanner's `action` slot:
 * notify( { type: 'success', message: 'Saved.', action: { label: 'Revert', onClick } } );
 * ```
 *
 * @since 1.10.0
 * @since NEXT `notify()` accepts an optional `action` object
 *              (`{ label, onClick, disabled?, isBusy? }`) stored on the notice
 *              and rendered by `NoticeBanner`.
 * @return {{ notice: ?Object, notify: Function, dismiss: Function }}
 *   - `notice`:  `{ type, message, action? }` or `null`.
 *   - `notify`:  `( { type, message, durationMs?, action? } )` — shows a notice and
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
	 * @param {Object} [opts.action]     Optional one-click action
	 *                                   `{ label, onClick, disabled?, isBusy? }`
	 *                                   rendered by `NoticeBanner`.
	 */
	const notify = useCallback(
		( { type, message, durationMs, action } ) => {
			clearTimer();
			setNotice( { type, message, ...( action ? { action } : {} ) } );
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
