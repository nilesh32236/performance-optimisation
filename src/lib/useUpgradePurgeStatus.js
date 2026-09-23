/**
 * Shared upgrade-purge status hook (audit #1515).
 *
 * Single home for the upgrade-purge slice previously duplicated in
 * Dashboard.js and FileOptimization.js: seed from
 * getWppoSettings('upgradePurge') (with lastPurge/safePreviewUrl aliases) +
 * read-only upgrade_purge_status GET refresh. Fail-open: a failed fetch
 * keeps the seed.
 *
 * @since NEXT
 */

import { useState, useCallback } from '@wordpress/element';
import { apiCall, getErrorLogMessage, getWppoSettings } from './apiRequest';

/**
 * Normalise a raw upgrade-purge payload (PHP snake_case or camelCase alias).
 *
 * @since NEXT
 * @param {*} raw Raw seed or endpoint payload.
 * @return {{ last_purge: ?Object, safe_preview_url: string }} Normalised slice.
 */
export const normalizeUpgradePurge = ( raw ) => {
	const source =
		raw && typeof raw === 'object' && ! Array.isArray( raw ) ? raw : {};
	return {
		last_purge: source.last_purge || source.lastPurge || null,
		safe_preview_url:
			source.safe_preview_url || source.safePreviewUrl || '',
	};
};

/**
 * Upgrade-purge status state + manual refresh.
 *
 * @since NEXT
 * @return {{ upgradePurge: Object, refreshUpgradePurgeStatus: Function }} Status slice + refresh.
 */
export const useUpgradePurgeStatus = () => {
	const [ upgradePurge, setUpgradePurge ] = useState( () =>
		normalizeUpgradePurge( getWppoSettings( 'upgradePurge', {} ) )
	);

	const refreshUpgradePurgeStatus = useCallback( async () => {
		try {
			const res = await apiCall( 'upgrade_purge_status', {}, 'GET' );
			if ( res && res.success && res.data ) {
				setUpgradePurge( normalizeUpgradePurge( res.data ) );
			}
		} catch ( statusError ) {
			// Fail-open: keep the seeded wppoSettings value.
			console.error(
				'Failed refreshing upgrade purge status:',
				getErrorLogMessage( statusError )
			);
		}
	}, [] );

	return { upgradePurge, refreshUpgradePurgeStatus, setUpgradePurge };
};

export default useUpgradePurgeStatus;
