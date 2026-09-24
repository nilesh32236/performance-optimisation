/**
 * Shared save-settings hook (audit #1515).
 *
 * Centralises the save triad previously copied across AiPanel, EdgeCachePanel,
 * LlmsPanel, ImageOptimization, DatabaseCleanup and FileOptimization:
 * setSaving + dismiss + apiCall('update_settings') + patchSettingsCache +
 * notify success/error + console.error + setSaving finally.
 *
 * P3-019: saves run through the shared useAsyncWorkflow contract — the
 * AbortSignal reaches apiCall, rapid saves abort/sequence-guard so a stale
 * response can never strand the busy flag or clobber newer state, and the
 * cache commits from the *server* payload (full map) with a tab-patch
 * fallback. Unmount mid-save is fail-safe (no setState/notify after).
 *
 * @since 2.4.0
 */

import { useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	apiCall,
	getErrorLogMessage,
	patchSettingsCache,
	commitSettingsResponse,
} from './apiRequest';
import { isAbortError, useAsyncWorkflow } from './useAbortableFetch';

/**
 * Save hook for a single settings tab.
 *
 * @since 2.4.0
 * @since NEXT Saves are abort/seq/mounted-guarded via useAsyncWorkflow and commit the server payload first.
 * @param {string}   tab                      Settings tab key (e.g. 'llms_txt').
 * @param {Object}   [options]                Hook options.
 * @param {Function} [options.notify]         useNotice notify callback.
 * @param {Function} [options.dismiss]        useNotice dismiss callback.
 * @param {string}   [options.successMessage] Success notice text.
 * @param {string}   [options.errorMessage]   Error notice text.
 * @return {{ saving: boolean, save: Function }} Saving flag + save callback.
 */
export const useSaveSettings = ( tab, options = {} ) => {
	const [ saving, setSaving ] = useState( false );
	const { run, mountedRef } = useAsyncWorkflow();

	const {
		notify,
		dismiss,
		successMessage = __( 'Settings saved.', 'performance-optimisation' ),
		errorMessage = __(
			'Failed to save settings.',
			'performance-optimisation'
		),
	} = options;

	const save = useCallback(
		async ( settings ) => {
			setSaving( true );
			if ( typeof dismiss === 'function' ) {
				dismiss();
			}
			try {
				return await run( async ( { signal, isStale } ) => {
					const response = await apiCall(
						'update_settings',
						{
							tab,
							settings,
						},
						'POST',
						signal
					);
					if ( isStale() ) {
						return response;
					}
					if ( response && response.success ) {
						// Prefer the server payload: a full map is
						// already committed by the apiCall gate, so the
						// explicit commit here only covers mocked
						// transports; otherwise fall back to a tab patch
						// so the slice still lands in the shared cache.
						const committed = commitSettingsResponse(
							'update_settings',
							response.data
						);
						if ( ! committed ) {
							patchSettingsCache( tab, settings );
						}
						if ( typeof notify === 'function' ) {
							notify( {
								type: 'success',
								message: successMessage,
								durationMs: 3000,
							} );
						}
					} else if ( typeof notify === 'function' ) {
						notify( {
							type: 'error',
							message:
								( response && response.message ) ||
								errorMessage,
						} );
					}
					return response;
				} );
			} catch ( saveError ) {
				if ( isAbortError( saveError ) ) {
					return undefined;
				}
				if ( ! mountedRef.current ) {
					// Fail-safe: the save path already returned via
					// run()/isStale and no caller can handle a post-unmount
					// throw, so swallow instead of rejecting.
					return undefined;
				}
				console.error(
					`Save ${ tab } settings failed:`,
					getErrorLogMessage( saveError )
				);
				if ( typeof notify === 'function' ) {
					notify( { type: 'error', message: errorMessage } );
				}
				throw saveError;
			} finally {
				if ( mountedRef.current ) {
					setSaving( false );
				}
			}
		},
		[ tab, notify, dismiss, successMessage, errorMessage, run, mountedRef ]
	);

	return { saving, save };
};

export default useSaveSettings;
