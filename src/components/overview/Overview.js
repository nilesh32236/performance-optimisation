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
	// works on the payload, not the envelope. Reading the envelope directly made
	// every row report "Unavailable" or "Not set up".
	//
	// `success` is checked first, and it matters. A `WP_Error`-shaped body
	// (`{ code, message, data: { status: 429 } }`) carries a *truthy* `data`,
	// which the independent review showed reaching the model and rendering
	// "Object cache is off" — a false claim manufactured out of an error. A
	// failed request is not evidence that a feature is switched off.
	if ( ! response || response.success === false ) {
		return null;
	}
	return response.data ?? null;
};

/**
 * Pull the median real-user vitals the plugin already stores, if any.
 *
 * Reads the plugin's own recorded history rather than issuing a fresh
 * measurement: the Overview must stay fast, and a real-user number is more
 * honest than a lab score anyway.
 *
 * ## The real shape
 *
 * `web_vitals_trends` returns `data.trends` as an **object keyed by
 * `<url-hash>_<strategy>`**, each value an array of history rows — not the flat
 * array this originally assumed. An independent review confirmed the assumed
 * shape never matched, so the vitals feature was dead: zero of three rows ever
 * rendered. The history rows carry `performance`, `lcp`, `cls` and `tbt`; there
 * is **no `inp` field**, so INP is reported only when a source actually
 * provides it rather than being faked from a neighbouring metric.
 *
 * Exported for testing against the literal captured response.
 *
 * @param {Object} payload The decoded `web_vitals_trends` payload.
 * @return {Object|null} `{ lcp, cls, inp? }`, or null when nothing is measured.
 */
export const summariseVitals = ( payload ) => {
	// Accepts the `{ success, data }` envelope, the decoded payload, or the
	// `trends` object itself. Unwrapping here rather than only at the call site
	// means a caller that passes the raw response gets the same answer.
	const container =
		payload?.trends ?? payload?.data?.trends ?? payload?.data ?? payload;
	if ( ! container || typeof container !== 'object' ) {
		return null;
	}

	// Accepts both the keyed map the endpoint really returns and a flat array,
	// because a filter could hand back either and neither should silently
	// produce an empty page.
	const groups = Array.isArray( container )
		? [ container ]
		: Object.values( container ).filter( ( value ) =>
				Array.isArray( value )
		  );
	const rows = groups.flat();
	if ( ! rows.length ) {
		return null;
	}

	/**
	 * Median of a real, finite, non-negative measurement.
	 *
	 * Zero is excluded: `Number( null )` is 0, so counting absent readings as
	 * "0 ms" is how an unmeasured site reports perfect vitals.
	 *
	 * @param {string[]} keys Candidate field names, in order.
	 * @return {number|undefined} The median, or undefined when unmeasured.
	 */
	const medianOf = ( keys ) => {
		const values = [];
		for ( const row of rows ) {
			if ( ! row || typeof row !== 'object' ) {
				continue;
			}
			for ( const key of keys ) {
				const raw = row[ key ];
				if ( raw === null || raw === '' || typeof raw === 'boolean' ) {
					continue;
				}
				const value = Number( raw );
				if ( Number.isFinite( value ) && value > 0 ) {
					values.push( value );
					break;
				}
			}
		}
		if ( ! values.length ) {
			return undefined;
		}
		values.sort( ( a, b ) => a - b );
		return values[ Math.floor( values.length / 2 ) ];
	};

	const vitals = {
		lcp: medianOf( [ 'lcp', 'LCP', 'lcp_ms' ] ),
		cls: medianOf( [ 'cls', 'CLS', 'cls_value' ] ),
		// Only when the source genuinely measures it. There is no INP field in
		// the stored history, so this stays undefined and the row is reported as
		// unmeasured instead of borrowing a different metric.
		inp: medianOf( [ 'inp', 'INP' ] ),
	};

	return Object.values( vitals ).some( ( value ) => value !== undefined )
		? vitals
		: null;
};

/**
 * Fetch the stored real-user vitals.
 *
 * @param {AbortSignal} signal Cancellation signal.
 * @return {Promise<Object|null>} `{ lcp, cls, inp? }` or null.
 */
const fetchVitals = async ( signal ) => {
	try {
		// The typed helper, not a raw call: this action requires a url and a
		// strategy, and building the action by hand is how arguments get lost.
		const response = await fetchWebVitalsTrends( '', '', signal );
		return summariseVitals( response?.data ?? response );
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
				// Already injected server-side by Cache::get_cache_stats(); using
				// it costs no request.
				cacheStats:
					typeof wppoSettings !== 'undefined'
						? wppoSettings?.cache_size
						: undefined,
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
