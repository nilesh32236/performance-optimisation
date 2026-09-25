/**
 * Database-counts fetch hook (P3-020).
 *
 * Extracts the one unrelated `database_cleanup_counts` fetch cluster out of
 * the Dashboard shell so the shell no longer owns fetch + abort + error
 * semantics for the stats strip. Uses the P3-019 `useAsyncWorkflow`
 * primitive (abort/stale-seq/mounted) like the image-job boundary.
 *
 * @since NEXT
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { getErrorLogMessage } from './apiRequest';
import { getDbCounts } from './dbCounts';
import { useAsyncWorkflow } from './useAbortableFetch';

/**
 * Fetch database cleanup counts for the stats strip.
 *
 * @since NEXT
 * @param {Function} notify Notice sink ({type, message, durationMs}).
 * @return {{ dbCounts: Object, loadingDbCounts: boolean }} Counts + loading flag.
 */
export const useDbCounts = ( notify ) => {
	const { run } = useAsyncWorkflow();
	const [ dbCounts, setDbCounts ] = useState( {} );
	const [ loadingDbCounts, setLoadingDbCounts ] = useState( true );
	// Stable notify without re-triggering the mount fetch when the parent
	// re-renders with a fresh callback identity.
	const notifyRef = useRef( notify );
	notifyRef.current = notify;

	useEffect( () => {
		run( async ( { signal, isStale } ) => {
			try {
				const data = await getDbCounts( signal );
				if ( isStale() || signal?.aborted ) {
					return;
				}
				setDbCounts( data );
			} catch ( error ) {
				if (
					isStale() ||
					error?.name === 'AbortError' ||
					signal?.aborted
				) {
					return;
				}
				console.error(
					'Error fetching db counts:',
					getErrorLogMessage( error )
				);
				notifyRef.current?.( {
					type: 'error',
					message: __(
						'Failed to load database counts.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} );
			} finally {
				if ( ! isStale() ) {
					setLoadingDbCounts( false );
				}
			}
		} );
	}, [ run ] );

	return { dbCounts, loadingDbCounts };
};
