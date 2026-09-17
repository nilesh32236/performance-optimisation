import { __, sprintf } from '@wordpress/i18n';
import { fetchWooCacheSelfTest } from './apiRequest';

/**
 * Timeout for the read-only WooCommerce cache self-test.
 *
 * @since NEXT
 * @type {number}
 */
export const WOO_SELF_TEST_TIMEOUT_MS = 5000;

/**
 * Whether the self-test result is unrunnable or WooCommerce is inactive.
 *
 * @since NEXT
 * @param {Object} data Self-test result payload.
 * @return {boolean} True when the proof could not run.
 */
export const isWooSelfTestUnrunnable = ( data ) => {
	if ( ! data || typeof data !== 'object' ) {
		return true;
	}
	return ! data.runnable || ! data.woo_active;
};

/**
 * Whether the FAIL-to-fix CTA should render.
 *
 * Single predicate shared by WelcomePanel and Dashboard so the two call
 * sites can never drift: runnable + active + explicit failure evidence
 * (failed route or fail-closed force_exclude recommendation).
 *
 * @since NEXT
 * @param {Object} data Self-test result payload.
 * @return {boolean} True when the safe-mode fix CTA applies.
 */
export const shouldShowWooFixCta = ( data ) => {
	if ( ! data || typeof data !== 'object' ) {
		return false;
	}
	if ( ! data.runnable || ! data.woo_active ) {
		return false;
	}
	return data.all_pass === false || Boolean( data.force_exclude );
};

/**
 * Tri-state check result: explicit true/false, inconclusive otherwise.
 *
 * @since NEXT
 * @param {*} pass Raw pass value from a check entry.
 * @return {string} 'pass', 'fail', or 'inconclusive'.
 */
export const getWooCheckState = ( pass ) => {
	if ( pass === true ) {
		return 'pass';
	}
	if ( pass === false ) {
		return 'fail';
	}
	return 'inconclusive';
};

/**
 * Notification descriptor for a self-test result.
 *
 * Explicit tri-state: unrunnable/inactive renders info, all_pass === true
 * renders success, all_pass === false renders warning, and a malformed
 * payload with all_pass missing renders inconclusive info (never a FAIL
 * warning with no evidence).
 *
 * @since NEXT
 * @param {Object} data Self-test result payload.
 * @return {Object} { type, message } descriptor for useNotice().notify().
 */
export const getWooSelfTestNotice = ( data ) => {
	if ( isWooSelfTestUnrunnable( data ) ) {
		return {
			type: 'info',
			message: data?.woo_active
				? __(
						'The self-test could not run. Default exclusion paths are shown read-only; dynamic pages fail open to uncached.',
						'performance-optimisation'
				  )
				: __(
						'WooCommerce is not active — showing default exclusion paths read-only. Dynamic pages fail open to uncached.',
						'performance-optimisation'
				  ),
		};
	}
	if ( data.all_pass === true ) {
		return {
			type: 'success',
			message: __(
				'WooCommerce self-test passed: cart, checkout and account pages bypass the cache; the guest cart survives.',
				'performance-optimisation'
			),
		};
	}
	if ( data.all_pass === false ) {
		return {
			type: 'warning',
			message: __(
				'WooCommerce self-test found a cacheable dynamic route. Re-enable safe mode in Dashboard → Page Cache.',
				'performance-optimisation'
			),
		};
	}
	return {
		type: 'info',
		message: __(
			'WooCommerce self-test result inconclusive — please re-run the test.',
			'performance-optimisation'
		),
	};
};

/**
 * Single sprintf for the safe-mode + excluded-paths summary line.
 *
 * Keeps translators' reordering intact (one format string, no hard-coded
 * bullet joins across separate __() calls).
 *
 * @since NEXT
 * @param {boolean}  safeMode      Safe-mode toggle state.
 * @param {string[]} excludedPaths Excluded path list.
 * @return {string} Translated summary string.
 */
export const formatWooSummary = ( safeMode, excludedPaths ) => {
	const mode = safeMode
		? __( 'On', 'performance-optimisation' )
		: __( 'Off', 'performance-optimisation' );
	const paths = Array.isArray( excludedPaths )
		? excludedPaths.join( ', ' )
		: '';
	return sprintf(
		/* translators: 1: safe mode state (On/Off), 2: comma-separated excluded paths */
		__(
			'Safe mode: %1$s • Excluded paths: %2$s',
			'performance-optimisation'
		),
		mode,
		paths
	);
};

/**
 * Plain-language remediation for a failing self-test rule.
 *
 * Rendered next to each failing row so store owners know what to do
 * before going live. Pass-through for passing rows (returns empty).
 *
 * @since NEXT
 * @param {string} kind Check group: route|fragment|editor|preload|cart.
 * @param {*}      pass Raw pass value from a check entry.
 * @return {string} Remediation copy, or empty string when passing.
 */
export const getWooRuleRemediation = ( kind, pass ) => {
	if ( pass !== false ) {
		return '';
	}
	switch ( kind ) {
		case 'fragment':
			return __(
				'Remediation: enable WooCommerce safe mode, then save Page Cache settings — fragments must never serve cached HTML.',
				'performance-optimisation'
			);
		case 'editor':
			return __(
				'Remediation: editor and preview pages must never be cached — clear any cached admin or preview file.',
				'performance-optimisation'
			);
		case 'preload':
			return __(
				'Remediation: keep this URL pattern out of preload and warm-up so filtered pages are never queued.',
				'performance-optimisation'
			);
		case 'cart':
			return __(
				'Remediation: re-enable safe mode so cart and session cookies bypass the cache — otherwise the guest cart can go stale.',
				'performance-optimisation'
			);
		case 'route':
		default:
			return __(
				'Remediation: enable WooCommerce safe mode, then save Page Cache settings — cart, checkout and account must never serve cached HTML.',
				'performance-optimisation'
			);
	}
};

/**
 * Run the read-only WooCommerce cache self-test with an AbortSignal.
 *
 * Enforces WOO_SELF_TEST_TIMEOUT_MS here (audit #1354) so a hung request
 * cannot depend on each caller remembering a timeout: the caller signal
 * (if any) and the timeout race, whichever aborts first wins.
 *
 * @since NEXT
 * @param {AbortSignal} [signal] Optional AbortSignal for cancellation.
 * @return {Promise<Object>} Resolved self-test response.
 */
export const runWooSelfTest = ( signal ) => {
	// Audit #1354 review: each abort source forwards its own reason so
	// callers can distinguish timeout vs caller-cancel; a setTimeout
	// fallback covers runtimes without AbortSignal.timeout.
	const controller =
		typeof AbortController !== 'undefined' ? new AbortController() : null;
	if ( ! controller ) {
		return fetchWooCacheSelfTest( undefined );
	}
	const onCallerAbort = () => controller.abort( signal.reason );
	const timers = [];
	if ( signal ) {
		if ( signal.aborted ) {
			onCallerAbort();
		} else {
			signal.addEventListener( 'abort', onCallerAbort, { once: true } );
		}
	}
	let timeoutSignal = null;
	if ( typeof AbortSignal !== 'undefined' && AbortSignal.timeout ) {
		timeoutSignal = AbortSignal.timeout( WOO_SELF_TEST_TIMEOUT_MS );
		const onTimeoutAbort = () => controller.abort( timeoutSignal.reason );
		timeoutSignal.addEventListener( 'abort', onTimeoutAbort, {
			once: true,
		} );
		timers.push( () =>
			timeoutSignal.removeEventListener( 'abort', onTimeoutAbort )
		);
	} else {
		const timerId = setTimeout(
			() =>
				controller.abort(
					new Error(
						// Audit #1420: localizable abort reason.
						__( 'Woo self-test timed out', 'performance-optimisation' )
					)
				),
			WOO_SELF_TEST_TIMEOUT_MS
		);
		timers.push( () => clearTimeout( timerId ) );
	}
	if ( signal ) {
		timers.push( () =>
			signal.removeEventListener( 'abort', onCallerAbort )
		);
	}
	return fetchWooCacheSelfTest( controller.signal ).finally( () => {
		timers.forEach( ( cleanup ) => cleanup() );
	} );
};
