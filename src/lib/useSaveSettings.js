/**
 * Shared save-settings hook (audit #1515).
 *
 * Centralises the save triad previously copied across AiPanel, EdgeCachePanel,
 * LlmsPanel, ImageOptimization, DatabaseCleanup and FileOptimization:
 * setSaving + dismiss + apiCall('update_settings') + patchSettingsCache +
 * notify success/error + console.error + setSaving finally.
 *
 * @since NEXT
 */

import { useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { apiCall, getErrorLogMessage, patchSettingsCache } from './apiRequest';

/**
 * Save hook for a single settings tab.
 *
 * @since NEXT
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
				const response = await apiCall( 'update_settings', {
					tab,
					settings,
				} );
				if ( response && response.success ) {
					patchSettingsCache( tab, settings );
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
							( response && response.message ) || errorMessage,
					} );
				}
				return response;
			} catch ( saveError ) {
				console.error(
					`Save ${ tab } settings failed:`,
					getErrorLogMessage( saveError )
				);
				if ( typeof notify === 'function' ) {
					notify( { type: 'error', message: errorMessage } );
				}
				throw saveError;
			} finally {
				setSaving( false );
			}
		},
		[ tab, notify, dismiss, successMessage, errorMessage ]
	);

	return { saving, save, setSaving };
};

export default useSaveSettings;
