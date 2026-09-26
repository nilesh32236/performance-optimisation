/**
 * Overview — the admin's landing page.
 *
 * Answers one question: *what state is my site in, and what should I do next?*
 * It is not the old Dashboard with a new heading. The Dashboard's thirteen
 * panels are all still reachable, under "All diagnostics" in this same area.
 *
 * ## Data strategy
 *
 * Every fact here comes from an endpoint the plugin already exposes, through
 * the existing typed helpers in `apiRequest.js`. No new backend route, and no
 * duplicated query. The requests are issued together once on mount and are not
 * repeated on harmless navigation, because they are local to this component.
 *
 * Failure is per-source, not per-page: a failed object-cache call leaves that
 * one row as "Unavailable" rather than blanking the whole Overview.
 *
 * @package
 */

import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import SiteStatusCard from './SiteStatusCard';
import QuickActionsCard from './QuickActionsCard';
import { buildStatusModel } from '../../lib/overviewStatus';
import {
	apiCall,
	fetchSystemInfo,
	fetchWebVitalsTrends,
} from '../../lib/apiRequest';

/**
 * Pull the object-cache status, which has no dedicated typed helper.
 *
 * The endpoint dispatches on an `action` and answers 400 without one, so this
 * asks for `status` explicitly. It is also throttled to five calls a minute, so
 * it is issued once per mount and never polled.
 *
 * @param {AbortSignal} signal Cancellation signal.
 * @return {Promise<Object>} The object-cache state.
 */
const fetchObjectCache = async ( signal ) => {
	const response = await apiCall(
		'object_cache',
		{ action: 'status' },
		'POST',
		signal
	);
	// The REST layer answers `{ success, data, message }`; the status model
	// works on the payload, not the envelope. Verified live: reading the
	// envelope directly made every row report "Unavailable" or "Not set up".
	return response?.data ?? null;
};

/**
 * Pull the best real-user vitals the plugin already stores, if any.
 *
 * Reads the plugin's own aggregated trend rather than issuing a fresh
 * measurement: the Overview must stay fast, and a real-user number is more
 * honest than a lab score anyway.
 *
 * @param {AbortSignal} signal Cancellation signal.
 * @return {Promise<Object|null>} `{ lcp, cls, inp }` or null.
 */
const fetchVitals = async ( signal ) => {
	try {
		// The typed helper, not a raw call: this action requires a url and a
		// strategy, and building the action by hand is how arguments get lost.
		const response = await fetchWebVitalsTrends( '', '', signal );
		const trends = response?.data ?? response;
		const rows = trends?.trends || trends?.data || trends?.results || null;
		if ( ! Array.isArray( rows ) || ! rows.length ) {
			return null;
		}
		// Aggregate the recorded values rather than picking one lucky page.
		const pick = ( keys ) => {
			const values = rows
				.map( ( row ) =>
					keys
						.map( ( key ) => Number( row?.[ key ] ) )
						.find( ( n ) => Number.isFinite( n ) && n >= 0 )
				)
				.filter( ( n ) => Number.isFinite( n ) );
			if ( ! values.length ) {
				return undefined;
			}
			return values.sort( ( a, b ) => a - b )[
				Math.floor( values.length / 2 )
			];
		};
		const vitals = {
			lcp: pick( [ 'lcp', 'LCP', 'lcp_ms' ] ),
			cls: pick( [ 'cls', 'CLS', 'cls_value' ] ),
			inp: pick( [ 'inp', 'INP', 'fcp', 'ttfb' ] ),
		};
		return Object.values( vitals ).some( ( v ) => v !== undefined )
			? vitals
			: null;
	} catch ( error ) {
		// A missing vitals source is a normal state, not an error to show.
		if ( error?.name === 'AbortError' ) {
			throw error;
		}
		return null;
	}
};

/**
 * The Overview page.
 *
 * @param {Object}   props            Component props.
 * @param {Function} props.onNavigate Navigate to an area id.
 * @param {Array}    props.activities Recent activity rows, already fetched by App.
 * @return {Object} The page.
 */
export default function Overview( { onNavigate, activities = [] } ) {
	const [ payload, setPayload ] = useState( {} );
	const [ loading, setLoading ] = useState( true );
	const [ failed, setFailed ] = useState( false );

	// Guards a state update after unmount, and lets a retry start clean.
	const mounted = useRef( true );
	const controllerRef = useRef( null );

	const load = useCallback( () => {
		controllerRef.current?.abort();
		const controller = new AbortController();
		controllerRef.current = controller;
		setLoading( true );
		setFailed( false );

		// Each source settles independently: one failure must not blank the page.
		const settle = ( key ) => ( value ) => {
			if ( controller.signal.aborted || ! mounted.current ) {
				return;
			}
			setPayload( ( prev ) => ( { ...prev, [ key ]: value } ) );
		};

		Promise.allSettled( [
			fetchSystemInfo( controller.signal )
				.then( ( r ) => r?.data ?? null )
				.then( settle( 'systemInfo' ) ),
			fetchObjectCache( controller.signal ).then(
				settle( 'objectCache' )
			),
			fetchVitals( controller.signal ).then( settle( 'vitals' ) ),
		] )
			.then( ( results ) => {
				if ( controller.signal.aborted || ! mounted.current ) {
					return;
				}
				// Every source failing is a failed page; one failing is not.
				setFailed( results.every( ( r ) => r.status === 'rejected' ) );
			} )
			.finally( () => {
				if ( ! controller.signal.aborted && mounted.current ) {
					setLoading( false );
				}
			} );
	}, [] );

	useEffect( () => {
		mounted.current = true;
		load();
		return () => {
			mounted.current = false;
			controllerRef.current?.abort();
		};
	}, [ load ] );

	// The settings the plugin already injected; no extra request for them.
	const settings = useMemo( () => {
		if ( typeof wppoSettings === 'undefined' ) {
			return {};
		}
		return wppoSettings?.settings ?? {};
	}, [] );

	const model = useMemo(
		() =>
			buildStatusModel( {
				cacheSettings: settings.cache_settings,
				...payload,
			} ),
		[ payload, settings ]
	);

	return (
		<div className="wppo-overview">
			<SiteStatusCard
				rows={ model.rows }
				overall={ model.overall }
				loading={ loading && ! Object.keys( payload ).length }
				failed={ failed }
				onRetry={ load }
			/>

			<QuickActionsCard onNavigate={ onNavigate } />

			{ activities?.length ? (
				<section
					className="wppo-card wppo-overview__card"
					aria-labelledby="wppo-overview-activity"
				>
					<h2
						className="wppo-card__title"
						id="wppo-overview-activity"
					>
						{ __( 'Recent activity', 'performance-optimisation' ) }
					</h2>
					<ul className="wppo-overview__activity">
						{ activities.slice( 0, 8 ).map( ( entry, index ) => (
							<li key={ entry?.id ?? index }>
								<span className="wppo-overview__activity-text">
									{ entry?.activity ?? entry?.message ?? '' }
								</span>
								{ entry?.created_at ? (
									<time
										className="wppo-overview__activity-time"
										dateTime={ String( entry.created_at ) }
									>
										{ String( entry.created_at ) }
									</time>
								) : null }
							</li>
						) ) }
					</ul>
				</section>
			) : null }
		</div>
	);
}
