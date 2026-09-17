/**
 * Shared settings-form hooks (audit maintainability).
 *
 * ObjectCache, DatabaseCleanup, PreloadSettings, and ImageOptimization each
 * carried a hand-rolled baseline/useUnsavedChanges scaffold with
 * hand-maintained dep arrays — adding a field but missing one dep entry
 * caused a stale baseline and silently dropped unsaved-changes protection.
 * The update_settings submit was likewise triplicated with divergent success
 * checks (res.success vs res.success !== false), so one tab could treat a
 * falsy response as success and clear dirty state without saving.
 *
 * useSettingsForm() owns state/baseline/useUnsavedChanges deriving sync keys
 * from Object.keys(defaults); useSaveSettings() owns one success/error
 * contract for update_settings submits.
 *
 * @since NEXT
 */

import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { apiCall, getErrorLogMessage } from './apiRequest';
import useUnsavedChanges, { stableStringify } from './useUnsavedChanges';

/**
 * Own the settings/baseline/dirty scaffold for a settings form.
 *
 * Baseline sync keys derive from Object.keys(defaults) so adding a field
 * needs one edit (the defaults object), not a dep-array edit per effect.
 *
 * @since NEXT
 * @param {Object}   defaults    Default settings (sync-key source of truth).
 * @param {Object}   [options]   Incoming options (e.g. wppoSettings slice).
 * @param {Function} [normalize] Optional (defaults, options) => merged state.
 * @return {Array} [settings, setSettings, baseline, setBaseline].
 */
export const useSettingsForm = ( defaults, options = {}, normalize ) => {
	// Per-key sync fingerprint (not object identity) so parent re-renders
	// with an identical payload do not reset the baseline. Keys derive from
	// Object.keys(defaults) — no hand-maintained dep array.
	const optionsKey = stableStringify(
		Object.keys( defaults || {} ).map( ( key ) => options?.[ key ] )
	);
	const merge = useCallback(
		( base ) => {
			if ( typeof normalize === 'function' ) {
				return normalize( base, options );
			}
			return { ...base, ...options };
		},
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ optionsKey ]
	);

	const [ settings, setSettings ] = useState( () =>
		merge( { ...defaults } )
	);
	const [ baseline, setBaseline ] = useState( () =>
		merge( { ...defaults } )
	);

	useEffect( () => {
		setBaseline( merge( { ...defaults } ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ merge ] );

	useUnsavedChanges( settings, baseline );

	return [ settings, setSettings, baseline, setBaseline ];
};

/**
 * Own the update_settings submit for a settings form.
 *
 * Single success/error contract: res.success clears dirty state and syncs
 * the baseline; anything else notifies an error. Payload tweaks stay with
 * the caller via buildPayload().
 *
 * @since NEXT
 * @param {Object}   config                  Save configuration.
 * @param {string}   config.tab              Settings tab key.
 * @param {Function} config.getSettings      () => current settings object.
 * @param {Function} config.setBaseline      Baseline setter from useSettingsForm.
 * @param {Function} config.setIsDirty       Dirty-flag setter (optional).
 * @param {Function} config.notify           useNotice notify().
 * @param {Function} config.dismiss          useNotice dismiss().
 * @param {Function} [config.buildPayload]   (settings) => settings payload.
 * @param {Function} [config.onSaved]        (settings) => void after success.
 * @param {string}   [config.successMessage] Success notice text.
 * @param {string}   [config.errorMessage]   Error notice text.
 * @return {Array} [save, isSaving].
 */
export const useSaveSettings = ( {
	tab,
	getSettings,
	setBaseline,
	setIsDirty,
	notify,
	dismiss,
	buildPayload,
	onSaved,
	successMessage,
	errorMessage,
} ) => {
	const [ isSaving, setIsSaving ] = useState( false );

	const save = useCallback(
		async ( event ) => {
			if ( event ) {
				event.preventDefault();
			}
			setIsSaving( true );
			if ( typeof dismiss === 'function' ) {
				dismiss();
			}
			try {
				const settings =
					typeof getSettings === 'function' ? getSettings() : {};
				const payload =
					typeof buildPayload === 'function'
						? buildPayload( settings )
						: settings;
				const res = await apiCall( 'update_settings', {
					tab,
					settings: payload,
				} );
				if ( res && res.success ) {
					setBaseline( payload );
					if ( typeof setIsDirty === 'function' ) {
						setIsDirty( false );
					}
					if ( typeof onSaved === 'function' ) {
						onSaved( settings );
					}
					notify( {
						type: 'success',
						message:
							successMessage ||
							__(
								'Settings saved successfully.',
								'performance-optimisation'
							),
					} );
				} else {
					notify( {
						type: 'error',
						message:
							res?.message ||
							errorMessage ||
							__(
								'Error saving settings.',
								'performance-optimisation'
							),
					} );
				}
			} catch ( err ) {
				notify( {
					type: 'error',
					message:
						errorMessage ||
						__(
							'Error saving settings.',
							'performance-optimisation'
						),
					durationMs: 5000,
				} );
				console.error(
					'Error saving settings:',
					getErrorLogMessage( err )
				);
			} finally {
				setIsSaving( false );
			}
		},
		[
			tab,
			getSettings,
			setBaseline,
			setIsDirty,
			notify,
			dismiss,
			buildPayload,
			onSaved,
			successMessage,
			errorMessage,
		]
	);

	return [ save, isSaving ];
};

/**
 * Run abortable async work with unmount + stale-run guards (audit
 * maintainability).
 *
 * ObjectCache, DatabaseCleanup, PreloadSettings, PerformanceAudit, and
 * WelcomePanel each hand-rolled the AbortController/unmount-guard pattern —
 * a missed abort/stale-run check let a slow earlier request overwrite newer
 * results or setState after unmount. Centralized here.
 *
 * @since NEXT
 * @return {Object} { run, abort }.
 *   - run(fn): runs fn(signal) with a fresh controller, aborting the
 *     previous run; AbortErrors are swallowed; results after unmount or
 *     after a newer run started are discarded (fn should still check
 *     signal.aborted before setState where cheap).
 *   - abort(): aborts the in-flight run (also runs on unmount).
 */
export const useAbortableFetch = () => {
	const controllerRef = useRef( null );
	const runIdRef = useRef( 0 );
	const isMountedRef = useRef( true );

	useEffect( () => {
		return () => {
			isMountedRef.current = false;
			if ( controllerRef.current ) {
				controllerRef.current.abort();
			}
		};
	}, [] );

	const abort = useCallback( () => {
		if ( controllerRef.current ) {
			controllerRef.current.abort();
		}
	}, [] );

	const run = useCallback( async ( fn ) => {
		if ( controllerRef.current ) {
			controllerRef.current.abort();
		}
		const controller = new AbortController();
		controllerRef.current = controller;
		const runId = ++runIdRef.current;
		try {
			const result = await fn( controller.signal );
			if (
				! isMountedRef.current ||
				runId !== runIdRef.current ||
				controller.signal.aborted
			) {
				return undefined;
			}
			return result;
		} catch ( error ) {
			if (
				controller.signal.aborted ||
				error?.name === 'AbortError' ||
				! isMountedRef.current ||
				runId !== runIdRef.current
			) {
				return undefined;
			}
			throw error;
		}
	}, [] );

	return { run, abort };
};

/**
 * Own the busy-flag + notice + try/catch lifecycle for an API action
 * (audit maintainability).
 *
 * FileOptimization's regen/save/purge handlers re-implemented busy-flag +
 * dismiss + try/apiCall + notify + catch + finally with 'An unexpected error
 * occurred' — a busy-flag reset fixed in one path but not the others caused
 * stuck spinners or double-submits. Route handlers through this hook with a
 * per-action busy setter instead.
 *
 * @since NEXT
 * @param {Object}   config           Action configuration.
 * @param {Function} config.notify    useNotice notify().
 * @param {Function} config.dismiss   useNotice dismiss().
 * @param {Function} [config.setBusy] Busy-flag setter (defaults to noop).
 * @return {Function} runAction(fn, { successMessage }) wrapping fn().
 */
export const useApiAction = ( { notify, dismiss, setBusy } ) => {
	return useCallback(
		async ( fn, { successMessage } = {} ) => {
			if ( typeof setBusy === 'function' ) {
				setBusy( true );
			}
			if ( typeof dismiss === 'function' ) {
				dismiss();
			}
			try {
				const result = await fn();
				if ( successMessage ) {
					notify( {
						type: 'success',
						message: successMessage,
						durationMs: 5000,
					} );
				}
				return result;
			} catch ( err ) {
				notify( {
					type: 'error',
					message:
						err?.message ||
						__(
							'An unexpected error occurred.',
							'performance-optimisation'
						),
				} );
				console.error(
					'API action failed:',
					getErrorLogMessage( err )
				);
				return undefined;
			} finally {
				if ( typeof setBusy === 'function' ) {
					setBusy( false );
				}
			}
		},
		[ notify, dismiss, setBusy ]
	);
};

/**
 * Own the AbortController lifecycle for a polling-style fetch (audit
 * maintainability).
 *
 * App.js fetchActivities/fetchRules/fetchCcssStatus triplicated
 * AbortController/signal.aborted/success-flag boilerplate with drift
 * (hasFetchedRules set before try vs hasFetchedCcss inside) — a retry/guard
 * fix in one left another permanently gated so errors never recovered via
 * retry. The three fetchers supply only endpoint + setters through fetchFn.
 *
 * @since NEXT
 * @param {Function} fetchFn          Async (signal) => data runner.
 * @param {Object}   second           Handler bag.
 * @param {Function} second.onSuccess Called with data on success.
 * @param {Function} second.onError   Called with error on failure.
 * @return {Object} { refresh, abort }.
 */
export const useFetchWithAbort = ( fetchFn, { onSuccess, onError } = {} ) => {
	const controllerRef = useRef( null );

	const abort = useCallback( () => {
		if ( controllerRef.current ) {
			controllerRef.current.abort();
			controllerRef.current = null;
		}
	}, [] );

	const refresh = useCallback( () => {
		if ( controllerRef.current ) {
			controllerRef.current.abort();
		}
		const controller = new AbortController();
		controllerRef.current = controller;
		( async () => {
			try {
				const data = await fetchFn( controller.signal );
				if ( controller.signal.aborted ) {
					return;
				}
				if ( typeof onSuccess === 'function' ) {
					onSuccess( data );
				}
			} catch ( error ) {
				if (
					controller.signal.aborted ||
					error?.name === 'AbortError'
				) {
					return;
				}
				if ( typeof onError === 'function' ) {
					onError( error );
				}
			}
		} )();
		return () => controller.abort();
	}, [ fetchFn, onSuccess, onError ] );

	useEffect( () => {
		return () => {
			if ( controllerRef.current ) {
				controllerRef.current.abort();
				controllerRef.current = null;
			}
		};
	}, [] );

	return { refresh, abort };
};
