import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

/**
 * Shared state hook for scoped `wppo-notice` feedback.
 *
 * Centralises the divergent notification state each API-talking component
 * used to hand-roll (e.g. `notification`/`error`/`actionMsg` plus a timeout).
 * Tracks a single `{ type, message }` object and optionally auto-dismisses it
 * after a duration, cleaning up its timer on unmount so no stale `setState` is
 * scheduled after the component is gone.
 *
 * @since 2.0.0
 * @param {Object} [options]               Hook options.
 * @param {number} [options.autoDismissMs] Default auto-dismiss delay in ms.
 *                                         `0` (default) disables auto-dismiss
 *                                         unless a `durationMs` is passed to
 *                                         `notify`.
 * @return {{
 *   notice: { type: string, message: string } | null,
 *   notify: Function,
 *   dismiss: Function
 * }} The current notice, plus `notify()` and `dismiss()` helpers.
 */
const useNotice = ( { autoDismissMs = 0 } = {} ) => {
	const [ notice, setNotice ] = useState( null );
	const timerRef = useRef( null );

	const dismiss = useCallback( () => {
		if ( timerRef.current ) {
			clearTimeout( timerRef.current );
			timerRef.current = null;
		}
		setNotice( null );
	}, [] );

	const notify = useCallback(
		( { type, message, durationMs } ) => {
			// Clear any pending auto-dismiss timer to avoid a stale timeout
			// firing after a newer notification supersedes this one.
			if ( timerRef.current ) {
				clearTimeout( timerRef.current );
				timerRef.current = null;
			}

			setNotice( { type, message } );

			const ms =
				typeof durationMs === 'number' ? durationMs : autoDismissMs;
			if ( ms > 0 ) {
				timerRef.current = setTimeout( () => {
					setNotice( null );
					timerRef.current = null;
				}, ms );
			}
		},
		[ autoDismissMs ]
	);

	// Clean up any pending timer on unmount to avoid post-unmount setState.
	useEffect( () => {
		return () => {
			if ( timerRef.current ) {
				clearTimeout( timerRef.current );
				timerRef.current = null;
			}
		};
	}, [] );

	return { notice, notify, dismiss };
};

export default useNotice;
